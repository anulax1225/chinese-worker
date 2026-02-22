import * as fs from 'node:fs/promises';
import * as path from 'node:path';
import type { CWApiClient } from '../api/client';
import { IndexStore, type IndexData } from '../indexer/store';
import type { QueryContext } from './query-builder';
import { logger } from '../util/logger';

export interface RetrievedChunk {
    filePath: string;
    symbol: string;
    node_type: string;
    similarity: number;
    code: string;
    lineCount: number;
}

export interface RetrievalOptions {
    topK: number;
    threshold: number;
    maxLines: number;
}

const DEFAULT_OPTIONS: RetrievalOptions = {
    topK: 3,
    threshold: 0.5,
    maxLines: 50,
};

export class ContextRetriever {
    private index: IndexData | null = null;

    constructor(
        private api: CWApiClient,
        private store: IndexStore,
        private workspaceRoot: string,
    ) {}

    setIndex(index: IndexData): void {
        this.index = index;
    }

    async retrieve(
        query: QueryContext,
        opts?: Partial<RetrievalOptions>,
        signal?: AbortSignal,
    ): Promise<RetrievedChunk[]> {
        const options = { ...DEFAULT_OPTIONS, ...opts };

        if (!this.index) {
            return [];
        }

        const startTime = Date.now();

        // Collect all embedding IDs, excluding current file
        let targets = this.store
            .getAllEmbeddingIds(this.index)
            .filter(e => e.filePath !== query.currentFilePath);

        if (targets.length === 0) {
            return [];
        }

        // Cap at 50 targets (compare endpoint limit)
        if (targets.length > 50) {
            targets = targets.slice(0, 50);
        }

        const compareResult = await this.api.compareEmbeddings(
            { text: query.queryText },
            targets.map(t => ({ id: t.id })),
            undefined,
            signal,
        );

        if (!compareResult) {
            return [];
        }

        // Log all compare results sorted by best similarity
        const sorted = [...compareResult.results].sort((a, b) => b.similarity - a.similarity);
        logger.info(`Embeddings compare response (${sorted.length} results, sorted by score):`);
        for (const r of sorted) {
            const targetId = r.target.id;
            const found = targetId !== undefined ? this.store.findNodeByEmbeddingId(this.index, targetId) : null;
            const label = found ? `${found.filePath} :: ${found.node.node_type} ${found.node.symbol}` : `id=${targetId}`;
            const above = r.similarity >= options.threshold ? '✓' : '✗';
            logger.info(`  ${above} ${r.similarity.toFixed(4)} — ${label}`);
        }

        // Filter by threshold and take topK
        const filtered = compareResult.results
            .filter(r => r.similarity >= options.threshold)
            .slice(0, options.topK);

        // Build result chunks with budget trimming
        let linesBudget = options.maxLines;
        const results: RetrievedChunk[] = [];

        for (const match of filtered) {
            if (results.length >= options.topK) {
                break;
            }

            const targetId = match.target.id;
            if (targetId === undefined) {
                continue;
            }

            const found = this.store.findNodeByEmbeddingId(this.index, targetId);
            if (!found) {
                continue;
            }

            const code = await this.readLinesFromFile(
                found.filePath,
                found.node.line_start,
                found.node.line_end,
            );

            if (!code) {
                continue;
            }

            const lineCount = code.split('\n').length;

            if (lineCount > linesBudget) {
                continue;
            }

            linesBudget -= lineCount;

            results.push({
                filePath: found.filePath,
                symbol: found.node.symbol,
                node_type: found.node.node_type,
                similarity: match.similarity,
                code,
                lineCount,
            });
        }

        const elapsed = Date.now() - startTime;
        logger.info(`Retriever: ${results.length} chunk(s) in ${elapsed}ms (${targets.length} candidates)`);

        return results;
    }

    private async readLinesFromFile(
        filePath: string,
        lineStart: number,
        lineEnd: number,
    ): Promise<string | null> {
        try {
            const absPath = path.join(this.workspaceRoot, filePath);
            const content = await fs.readFile(absPath, 'utf-8');
            const lines = content.split('\n');
            return lines.slice(lineStart, lineEnd + 1).join('\n');
        } catch {
            return null;
        }
    }
}
