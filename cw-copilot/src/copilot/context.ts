import * as vscode from 'vscode';
import * as path from 'node:path';
import type { FIMTokenFamily } from './fim-tokens';

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
        const prompt = `${fimTokenFamily.prefix}${prefix}${fimTokenFamily.suffix}${suffix}${fimTokenFamily.middle}`;
        return {
            prompt,
            suffix: '',
            raw: true,
            fileName,
            languageId,
        };
    }

    const instruction = `Continue the code exactly where it left off in "${fileName}" (${languageId}). Output ONLY the code continuation. No explanations, no markdown, no repeating existing code, no code block syntax.\n\n`;
    return {
        prompt: instruction + prefix,
        suffix,
        raw: false,
        fileName,
        languageId,
    };
}