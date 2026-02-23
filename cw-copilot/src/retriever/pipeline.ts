import * as vscode from 'vscode';
import type { CWApiClient } from '../api/client';
import type { IndexStore } from '../indexer/store';
import type { IndexData } from '../indexer/store';
import type { LanguageConfig } from '../copilot/languages';
import type { EditTracker } from './tracker';
import type { RetrievalCandidate, DiagnosticContext } from './sources/types';
import { collectLSPCandidates } from './sources/lsp-targeted';
import { collectTabCandidates } from './sources/open-tabs';
import { collectImportCandidates } from './sources/import-follow';
import { collectEmbeddingCandidates } from './sources/embedding';
import { collectDiagnostics } from './sources/diagnostics';
import { reciprocalRankFusion, deduplicateFused } from './fusion';
import type { RankedList } from './fusion';
import { tokenize } from './jaccard';
import { buildRetrievalQuery } from './query-builder';
import { logger } from '../util/logger';

export interface PipelineConfig {
    maxLines: number;
    topK: number;
    embeddingTimeout: number;
    lspTimeout: number;
    embeddingThreshold: number;
    jaccardThreshold: number;
    weights: {
        lsp: number;
        tabs: number;
        import: number;
        embedding: number;
    };
}

const DEFAULT_CONFIG: PipelineConfig = {
    maxLines: 60,
    topK: 8,
    embeddingTimeout: 200,
    lspTimeout: 100,
    jaccardThreshold: 0.05,
    embeddingThreshold: 0.5,
    weights: {
        lsp: 1.5,
        tabs: 1.0,
        import: 1.2,
        embedding: 1.0,
    },
};

export interface PipelineResult {
    chunks: RetrievalCandidate[];
    diagnostics: DiagnosticContext | null;
}

export class RetrievalPipeline {
    private index: IndexData | null = null;

    constructor(
        private tracker: EditTracker,
        private store: IndexStore,
        private api: CWApiClient,
        private langConfigs: Map<string, LanguageConfig>,
    ) {}

    setIndex(index: IndexData): void {
        this.index = index;
    }

    async retrieve(
        document: vscode.TextDocument,
        position: vscode.Position,
        workspaceRoot: string,
        config?: Partial<PipelineConfig>,
    ): Promise<PipelineResult> {
        const cfg = { ...DEFAULT_CONFIG, ...config, weights: { ...DEFAULT_CONFIG.weights, ...config?.weights } };
        const startTime = Date.now();

        // Build query tokens for Jaccard scoring (~15 lines before cursor)
        const prefixStart = Math.max(0, position.line - 15);
        const prefixRange = new vscode.Range(prefixStart, 0, position.line, position.character);
        const prefixText = document.getText(prefixRange);
        const queryTokens = tokenize(prefixText);

        // Build embedding query (reuse the Phase 2 query builder)
        const langConfig = this.langConfigs.get(document.languageId) ?? null;

        // Run all sources in parallel
        const [lspResult, tabResult, importResult, embeddingResult, diagnostics] = await Promise.allSettled([
            collectLSPCandidates(document, position, workspaceRoot, cfg.lspTimeout),
            collectTabCandidates(document, position, workspaceRoot, this.tracker, queryTokens, cfg.jaccardThreshold),
            collectImportCandidates(document, workspaceRoot, langConfig),
            this.collectEmbeddings(document, position, workspaceRoot, cfg),
            Promise.resolve(collectDiagnostics(document, position)),
        ]);

        // Extract fulfilled results
        const lspCandidates = lspResult.status === 'fulfilled' ? lspResult.value : [];
        const tabCandidates = tabResult.status === 'fulfilled' ? tabResult.value : [];
        const importCandidates = importResult.status === 'fulfilled' ? importResult.value : [];
        const embeddingCandidates = embeddingResult.status === 'fulfilled' ? embeddingResult.value : [];
        const diagResult = diagnostics.status === 'fulfilled' ? diagnostics.value : null;

        // Log source counts
        const sources = [
            lspCandidates.length && `lsp=${lspCandidates.length}`,
            tabCandidates.length && `tabs=${tabCandidates.length}`,
            importCandidates.length && `import=${importCandidates.length}`,
            embeddingCandidates.length && `embedding=${embeddingCandidates.length}`,
            diagResult && `diagnostics=${diagResult.count}`,
        ].filter(Boolean);
        logger.info(`Pipeline sources: ${sources.join(', ') || '(none)'}`);

        // Build ranked lists (skip empty sources)
        const lists: RankedList[] = [
            { source: 'lsp', weight: cfg.weights.lsp, candidates: lspCandidates },
            { source: 'tabs', weight: cfg.weights.tabs, candidates: tabCandidates },
            { source: 'import', weight: cfg.weights.import, candidates: importCandidates },
            { source: 'embedding', weight: cfg.weights.embedding, candidates: embeddingCandidates },
        ].filter(l => l.candidates.length > 0);

        if (lists.length === 0) {
            const elapsed = Date.now() - startTime;
            logger.info(`Pipeline: 0 chunks in ${elapsed}ms (no sources produced candidates)`);
            return { chunks: [], diagnostics: diagResult };
        }

        // Fuse
        const fused = reciprocalRankFusion(lists);

        // Deduplicate overlapping ranges
        const deduped = deduplicateFused(fused);

        // Budget enforce
        let linesBudget = cfg.maxLines;
        const final: RetrievalCandidate[] = [];

        for (const item of deduped) {
            if (final.length >= cfg.topK) {
                break;
            }
            if (item.candidate.lineCount > linesBudget) {
                continue;
            }

            linesBudget -= item.candidate.lineCount;
            final.push(item.candidate);
        }

        const elapsed = Date.now() - startTime;
        logger.info(`Pipeline: ${final.length} chunk(s) in ${elapsed}ms`);
        for (const chunk of final) {
            const fusedItem = deduped.find(f =>
                f.candidate.filePath === chunk.filePath &&
                f.candidate.lineStart === chunk.lineStart,
            );
            const score = fusedItem?.rrfScore.toFixed(4) ?? '?';
            const fromSources = fusedItem?.sources.join('+') ?? chunk.source;
            logger.info(`  - ${chunk.filePath}:${chunk.lineStart}-${chunk.lineEnd} [${chunk.symbol}] rrf=${score} via ${fromSources} (${chunk.lineCount} lines)`);
        }

        return { chunks: final, diagnostics: diagResult };
    }

    private async collectEmbeddings(
        document: vscode.TextDocument,
        position: vscode.Position,
        workspaceRoot: string,
        cfg: PipelineConfig,
    ): Promise<RetrievalCandidate[]> {
        if (!this.index) {
            return [];
        }

        const query = await buildRetrievalQuery(document, position, workspaceRoot);

        return collectEmbeddingCandidates(
            query,
            this.index,
            this.store,
            this.api,
            workspaceRoot,
            cfg.embeddingThreshold,
            cfg.topK,
            cfg.embeddingTimeout,
        );
    }
}
