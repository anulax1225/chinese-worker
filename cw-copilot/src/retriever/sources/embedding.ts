import * as fs from 'node:fs/promises';
import * as path from 'node:path';
import type { CWApiClient } from '../../api/client';
import type { IndexData } from '../../indexer/store';
import { IndexStore } from '../../indexer/store';
import type { QueryContext } from '../query-builder';
import type { RetrievalCandidate } from './types';
import { logger } from '../../util/logger';

/**
 * Embedding Semantic Retrieval source.
 *
 * Extracted from Phase 2's retriever.ts. Uses the embeddings compare API
 * to find semantically similar code across the project.
 */
export async function collectEmbeddingCandidates(
    query: QueryContext,
    index: IndexData | null,
    store: IndexStore,
    api: CWApiClient,
    workspaceRoot: string,
    threshold: number,
    topK: number,
    timeoutMs: number,
    queryInstruction?: string,
): Promise<RetrievalCandidate[]> {
    if (!index) {
        return [];
    }

    // Collect all embedding IDs, excluding current file
    let targets = store
        .getAllEmbeddingIds(index)
        .filter(e => e.filePath !== query.currentFilePath);

    if (targets.length === 0) {
        return [];
    }

    // Cap at 50 targets (compare endpoint limit)
    if (targets.length > 50) {
        targets = targets.slice(0, 50);
    }

    // Wrap in timeout
    const abortController = new AbortController();
    const timer = setTimeout(() => abortController.abort(), timeoutMs);

    try {
        const sourceText = queryInstruction
            ? `${queryInstruction}\n\n${query.queryText}`
            : query.queryText;

        const compareResult = await api.compareEmbeddings(
            { text: sourceText },
            targets.map(t => ({ id: t.id })),
            undefined,
            abortController.signal,
        );

        if (!compareResult) {
            return [];
        }

        // Log results
        const sorted = [...compareResult.results].sort((a, b) => b.similarity - a.similarity);
        logger.info(`Embedding source: ${sorted.length} compare results`);
        for (const r of sorted.slice(0, 10)) {
            const targetId = r.target.id;
            const found = targetId !== undefined ? store.findNodeByEmbeddingId(index, targetId) : null;
            const label = found ? `${found.filePath} :: ${found.node.node_type} ${found.node.symbol}` : `id=${targetId}`;
            const above = r.similarity >= threshold ? '+' : '-';
            logger.info(`  ${above} ${r.similarity.toFixed(4)} — ${label}`);
        }

        // Filter and build candidates
        const filtered = compareResult.results
            .filter(r => r.similarity >= threshold)
            .sort((a, b) => b.similarity - a.similarity)
            .slice(0, topK);

        const candidates: RetrievalCandidate[] = [];

        for (const match of filtered) {
            const targetId = match.target.id;
            if (targetId === undefined) {
                continue;
            }

            const found = store.findNodeByEmbeddingId(index, targetId);
            if (!found) {
                continue;
            }

            const code = await readLinesFromFile(
                workspaceRoot,
                found.filePath,
                found.node.line_start,
                found.node.line_end,
            );

            if (!code) {
                continue;
            }

            candidates.push({
                filePath: found.filePath,
                symbol: `${found.node.node_type}:${found.node.symbol}`,
                code,
                lineStart: found.node.line_start,
                lineEnd: found.node.line_end,
                lineCount: code.split('\n').length,
                source: 'embedding',
            });
        }

        logger.info(`Embedding source: ${candidates.length} candidate(s)`);
        return candidates;
    } catch (err: unknown) {
        if (abortController.signal.aborted) {
            logger.info(`Embedding source: timed out after ${timeoutMs}ms`);
        } else {
            logger.warn(`Embedding source: failed: ${err instanceof Error ? err.message : String(err)}`);
        }
        return [];
    } finally {
        clearTimeout(timer);
    }
}

async function readLinesFromFile(
    workspaceRoot: string,
    filePath: string,
    lineStart: number,
    lineEnd: number,
): Promise<string | null> {
    try {
        const absPath = path.join(workspaceRoot, filePath);
        const content = await fs.readFile(absPath, 'utf-8');
        const lines = content.split('\n');
        return lines.slice(lineStart, lineEnd + 1).join('\n');
    } catch {
        return null;
    }
}
