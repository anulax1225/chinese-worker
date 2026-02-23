import * as vscode from 'vscode';
import * as fs from 'node:fs/promises';
import * as path from 'node:path';
import type { RetrievalCandidate } from './types';
import type { EditTracker } from '../tracker';
import { jaccardSimilarity, tokenize } from '../jaccard';
import { logger } from '../../util/logger';

const MAX_CHUNKS_PER_FILE = 3;
const MAX_TOTAL_CHUNKS = 10;
const MAX_CHUNK_LINES = 30;

/**
 * Open Tabs & Recently Edited Files source.
 *
 * Gathers code chunks from files the user has open or recently edited,
 * scores them via Jaccard similarity against the code near the cursor,
 * and applies recency boosting for recently edited files.
 */
export async function collectTabCandidates(
    document: vscode.TextDocument,
    position: vscode.Position,
    workspaceRoot: string,
    tracker: EditTracker,
    queryTokens: Set<string>,
    jaccardThreshold: number = 0.05,
): Promise<RetrievalCandidate[]> {
    const currentFsPath = document.uri.fsPath;

    // Gather open tab URIs
    const openUris = new Set<string>();
    for (const group of vscode.window.tabGroups.all) {
        for (const tab of group.tabs) {
            if (tab.input instanceof vscode.TabInputText) {
                const fsPath = tab.input.uri.fsPath;
                if (fsPath !== currentFsPath) {
                    openUris.add(fsPath);
                }
            }
        }
    }

    // Gather recently edited files
    const recentEdits = tracker.getRecentlyEdited(5 * 60 * 1000); // 5 minutes
    const recentMap = new Map<string, number>(); // fsPath → lastEditedAt

    for (const tracked of recentEdits) {
        const fsPath = tracked.uri.fsPath;
        if (fsPath !== currentFsPath) {
            openUris.add(fsPath);
            recentMap.set(fsPath, tracked.lastEditedAt);
        }
    }

    // Process each file
    interface ScoredChunk {
        candidate: RetrievalCandidate;
        score: number;
    }

    const allChunks: ScoredChunk[] = [];

    for (const fsPath of openUris) {
        const relPath = path.relative(workspaceRoot, fsPath);
        if (relPath.startsWith('..') || path.isAbsolute(relPath)) {
            continue;
        }

        let content: string;
        try {
            content = await fs.readFile(fsPath, 'utf-8');
        } catch {
            continue;
        }

        const chunks = splitIntoChunks(content, relPath);
        const boost = recencyBoost(recentMap.get(fsPath));

        let fileChunkCount = 0;
        for (const chunk of chunks) {
            if (fileChunkCount >= MAX_CHUNKS_PER_FILE) {
                break;
            }

            const chunkTokens = tokenize(chunk.code);
            const jaccard = jaccardSimilarity(queryTokens, chunkTokens);
            const score = jaccard * boost;

            if (jaccard >= jaccardThreshold) {
                allChunks.push({ candidate: chunk, score });
                fileChunkCount++;
            }
        }
    }

    // Sort by score and take top N
    allChunks.sort((a, b) => b.score - a.score);
    const result = allChunks.slice(0, MAX_TOTAL_CHUNKS).map(c => c.candidate);

    logger.info(`Tabs source: ${result.length} candidate(s) from ${openUris.size} file(s)`);
    return result;
}

function recencyBoost(lastEditedMs: number | undefined): number {
    if (lastEditedMs === undefined) {
        return 1.0;
    }

    const ageSeconds = (Date.now() - lastEditedMs) / 1000;
    if (ageSeconds < 30) {
        return 1.5;
    }
    if (ageSeconds < 120) {
        return 1.3;
    }
    if (ageSeconds < 300) {
        return 1.1;
    }
    return 1.0;
}

/**
 * Split file content into chunks by blank-line-separated blocks.
 */
function splitIntoChunks(content: string, filePath: string): RetrievalCandidate[] {
    const lines = content.split('\n');
    const candidates: RetrievalCandidate[] = [];

    let chunkStart = 0;
    let chunkLines: string[] = [];
    let blankCount = 0;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const isBlank = line.trim() === '';

        if (isBlank) {
            blankCount++;
        } else {
            blankCount = 0;
        }

        chunkLines.push(line);

        // Split on double blank lines or at max chunk size
        const shouldSplit = blankCount >= 2 || chunkLines.length >= MAX_CHUNK_LINES;

        if (shouldSplit || i === lines.length - 1) {
            // Trim trailing blank lines
            while (chunkLines.length > 0 && chunkLines[chunkLines.length - 1].trim() === '') {
                chunkLines.pop();
            }

            if (chunkLines.length > 0) {
                const code = chunkLines.join('\n');
                const lineEnd = chunkStart + chunkLines.length - 1;

                // Extract a symbol label from the first meaningful line
                const symbol = extractSymbolLabel(chunkLines);

                candidates.push({
                    filePath,
                    symbol,
                    code,
                    lineStart: chunkStart,
                    lineEnd,
                    lineCount: chunkLines.length,
                    source: 'tabs',
                });
            }

            chunkStart = i + 1;
            chunkLines = [];
            blankCount = 0;
        }
    }

    return candidates;
}

function extractSymbolLabel(lines: string[]): string {
    for (const line of lines) {
        const trimmed = line.trim();
        if (trimmed === '') {
            continue;
        }

        // Try to match common declarations
        const match = trimmed.match(
            /(?:export\s+)?(?:async\s+)?(?:function|class|interface|type|enum|const|let|var|def|fn|func|pub\s+fn|struct|impl)\s+(\w+)/,
        );
        if (match) {
            return match[1];
        }

        // Return first non-empty line truncated
        return trimmed.slice(0, 40);
    }

    return '(chunk)';
}
