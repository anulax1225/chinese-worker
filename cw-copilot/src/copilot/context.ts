import * as vscode from 'vscode';
import * as path from 'node:path';
import type { FIMTokenFamily } from './fim-tokens';
import type { RetrievalCandidate, DiagnosticContext } from '../retriever/sources/types';

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
    retrievedChunks?: RetrievalCandidate[],
    commentPrefix?: string,
    projectName?: string,
    relativeFilePath?: string,
    diagnostics?: DiagnosticContext | null,
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
    const cp = commentPrefix ?? '//';

    if (enableFIM && fimTokenFamily) {
        const useTokenFormat = !!fimTokenFamily.fileSep && retrievedChunks?.length;

        if (useTokenFormat) {
            const prompt = formatTokenFIM(
                fimTokenFamily,
                retrievedChunks,
                prefix,
                suffix,
                cp,
                diagnostics,
                projectName,
                relativeFilePath ?? fileName,
            );
            return { prompt, suffix: '', raw: true, fileName, languageId };
        }

        // No fileSep support or no chunks — plain FIM with comment-based context
        const contextBlock = formatRetrievedContext(retrievedChunks, cp, diagnostics);
        const prompt = `${fimTokenFamily.prefix}${contextBlock}${prefix}${fimTokenFamily.suffix}${suffix}${fimTokenFamily.middle}`;
        return { prompt, suffix: '', raw: true, fileName, languageId };
    }

    const contextBlock = formatRetrievedContext(retrievedChunks, cp, diagnostics);
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
    chunks: RetrievalCandidate[],
    prefix: string,
    suffix: string,
    commentPrefix: string,
    diagnostics: DiagnosticContext | null | undefined,
    projectName?: string,
    currentFilePath?: string,
): string {
    const parts: string[] = [];

    if (tokens.repoName && projectName) {
        parts.push(`${tokens.repoName}${projectName}`);
    }

    const groups = groupChunksByFile(chunks);

    for (const [filePath, fileChunks] of groups) {
        parts.push(`${tokens.fileSep}${filePath}`);
        for (const chunk of fileChunks) {
            parts.push(`${commentPrefix} (via ${sourceLabel(chunk.source)})`);
            parts.push(chunk.code);
        }
    }

    if (currentFilePath) {
        parts.push(`${tokens.fileSep}${currentFilePath}`);
    }

    // Add diagnostics before FIM markers
    if (diagnostics) {
        parts.push(`${commentPrefix} ── Diagnostics near cursor ──`);
        parts.push(diagnostics.text);
    }

    parts.push(`${tokens.prefix}${prefix}${tokens.suffix}${suffix}${tokens.middle}`);

    return parts.join('\n');
}

export interface GhostContext {
    userMessage: string;
    contextVariables: Record<string, string>;
    isEmpty: boolean;
}

export function buildGhostContext(
    document: vscode.TextDocument,
    position: vscode.Position,
    maxPrefixLines: number,
    maxSuffixLines: number,
    retrievedChunks?: RetrievalCandidate[],
    projectName?: string,
    relativeFilePath?: string,
    diagnostics?: DiagnosticContext | null,
): GhostContext {
    const startLine = Math.max(0, position.line - maxPrefixLines);
    const endLine = Math.min(document.lineCount - 1, position.line + maxSuffixLines);

    const prefixRange = new vscode.Range(startLine, 0, position.line, position.character);
    const prefix = document.getText(prefixRange);

    const lastChar = document.lineAt(endLine).text.length;
    const suffixRange = new vscode.Range(position.line, position.character, endLine, lastChar);
    const suffix = document.getText(suffixRange);

    const userMessage = `<prefix>\n${prefix}\n<blank/>\n<suffix>\n${suffix}\n</suffix>`;

    const contextVariables: Record<string, string> = {};

    if (relativeFilePath) {
        contextVariables.file_path = relativeFilePath;
    }
    if (document.languageId) {
        contextVariables.language = document.languageId;
    }
    if (projectName) {
        contextVariables.project_name = projectName;
    }
    if (retrievedChunks?.length) {
        contextVariables.retrieved_context = formatRetrievedContextForGhost(retrievedChunks);
    }
    if (diagnostics) {
        contextVariables.diagnostics = diagnostics.text;
    }

    return {
        userMessage,
        contextVariables,
        isEmpty: prefix.trim() === '' && suffix.trim() === '',
    };
}

function groupChunksByFile(chunks: RetrievalCandidate[]): Map<string, RetrievalCandidate[]> {
    const groups = new Map<string, RetrievalCandidate[]>();

    for (const chunk of chunks) {
        const existing = groups.get(chunk.filePath);
        if (existing) {
            existing.push(chunk);
        } else {
            groups.set(chunk.filePath, [chunk]);
        }
    }

    return groups;
}

function sourceLabel(source: string): string {
    const labels: Record<string, string> = {
        lsp: 'definition',
        tabs: 'recent edit',
        import: 'imported',
        embedding: 'similar',
    };
    return labels[source] ?? source;
}

function formatChunkHeader(chunk: RetrievalCandidate, commentPrefix: string): string {
    return `${commentPrefix} From: ${chunk.filePath} (via ${sourceLabel(chunk.source)})`;
}

function formatRetrievedContextForGhost(chunks: RetrievalCandidate[]): string {
    const parts: string[] = [];
    const groups = groupChunksByFile(chunks);

    for (const [filePath, fileChunks] of groups) {
        for (const chunk of fileChunks) {
            parts.push(`--- ${filePath} (via ${sourceLabel(chunk.source)}) ---`);
            parts.push(chunk.code);
            parts.push('');
        }
    }

    return parts.join('\n');
}

function formatRetrievedContext(
    chunks: RetrievalCandidate[] | undefined,
    commentPrefix: string,
    diagnostics?: DiagnosticContext | null,
): string {
    const parts: string[] = [];

    if (chunks?.length) {
        parts.push(`${commentPrefix} ── Related code from workspace ──`);
        parts.push('');

        for (const chunk of chunks) {
            parts.push(formatChunkHeader(chunk, commentPrefix));
            parts.push(chunk.code);
            parts.push('');
        }
    }

    if (diagnostics) {
        parts.push(`${commentPrefix} ── Diagnostics near cursor ──`);
        parts.push(diagnostics.text);
        parts.push('');
    }

    if (parts.length > 0) {
        parts.push(`${commentPrefix} ── Current file ──`);
        parts.push('');
    }

    return parts.join('\n');
}
