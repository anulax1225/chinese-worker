import * as vscode from 'vscode';
import * as path from 'node:path';
import type { FIMTokenFamily } from './fim-tokens';
import type { RetrievedChunk } from '../retriever/retriever';

export interface FIMContext {
    prompt: string;
    suffix: string;
    raw: boolean;
    fileName: string;
    languageId: string;
}

export function buildFIMContext(
    document: vscode.TextDocument,
    position: vscode.Position,
    maxPrefixLines: number,
    maxSuffixLines: number,
    enableFIM: boolean,
    fimTokenFamily?: FIMTokenFamily,
    retrievedChunks?: RetrievedChunk[],
    commentPrefix?: string,
    projectName?: string,
    relativeFilePath?: string,
): FIMContext {
    const startLine = Math.max(0, position.line - maxPrefixLines);
    const endLine = Math.min(document.lineCount - 1, position.line + maxSuffixLines);

    const prefixRange = new vscode.Range(startLine, 0, position.line, position.character);
    const prefix = document.getText(prefixRange);

    const lastChar = document.lineAt(endLine).text.length;
    const suffixRange = new vscode.Range(position.line, position.character, endLine, lastChar);
    const suffix = document.getText(suffixRange);

    const fileName = path.basename(document.fileName);
    const languageId = document.languageId;

    if (enableFIM && fimTokenFamily) {
        const useTokenFormat = !!fimTokenFamily.fileSep && retrievedChunks?.length;

        if (useTokenFormat) {
            const prompt = formatTokenFIM(
                fimTokenFamily,
                retrievedChunks,
                prefix,
                suffix,
                projectName,
                relativeFilePath ?? fileName,
            );
            return { prompt, suffix: '', raw: true, fileName, languageId };
        }

        // No fileSep support or no chunks — plain FIM with comment-based context
        const contextBlock = formatRetrievedContext(retrievedChunks, commentPrefix ?? '//');
        const prompt = `${fimTokenFamily.prefix}${contextBlock}${prefix}${fimTokenFamily.suffix}${suffix}${fimTokenFamily.middle}`;
        return { prompt, suffix: '', raw: true, fileName, languageId };
    }

    const contextBlock = formatRetrievedContext(retrievedChunks, commentPrefix ?? '//');
    const fullPrefix = contextBlock + prefix;

    const instruction = `Continue the code exactly where it left off in "${fileName}" (${languageId}). Output ONLY the code continuation. No explanations, no markdown, no repeating existing code, no code block syntax.\n\n`;
    return {
        prompt: instruction + fullPrefix,
        suffix,
        raw: false,
        fileName,
        languageId,
    };
}

/**
 * Build FIM prompt using native repo/file tokens.
 *
 * Structure:
 *   <repo_name>project\n
 *   <file_sep>path/to/retrieved.ts\n code...\n
 *   <file_sep>current/file.ts\n
 *   <fim_prefix>prefix<fim_suffix>suffix<fim_middle>
 */
function formatTokenFIM(
    tokens: FIMTokenFamily,
    chunks: RetrievedChunk[],
    prefix: string,
    suffix: string,
    projectName?: string,
    currentFilePath?: string,
): string {
    const parts: string[] = [];

    if (tokens.repoName && projectName) {
        parts.push(`${tokens.repoName}${projectName}`);
    }

    for (const chunk of chunks) {
        parts.push(`${tokens.fileSep}${chunk.filePath}`);
        parts.push(chunk.code);
    }

    if (currentFilePath) {
        parts.push(`${tokens.fileSep}${currentFilePath}`);
    }

    parts.push(`${tokens.prefix}${prefix}${tokens.suffix}${suffix}${tokens.middle}`);

    return parts.join('\n');
}

function formatRetrievedContext(
    chunks: RetrievedChunk[] | undefined,
    commentPrefix: string,
): string {
    if (!chunks?.length) {
        return '';
    }

    const parts: string[] = [];

    parts.push(`${commentPrefix} ─── Related code from project ───`);
    parts.push('');

    for (const chunk of chunks) {
        parts.push(`${commentPrefix} ${chunk.filePath} (${chunk.node_type} ${chunk.symbol})`);
        parts.push(chunk.code);
        parts.push('');
    }

    parts.push(`${commentPrefix} ─── Current file ───`);
    parts.push('');

    return parts.join('\n');
}
