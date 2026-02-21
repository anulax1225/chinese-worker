import * as vscode from 'vscode';
import * as path from 'node:path';

export interface FIMContext {
    prompt: string;
    suffix: string;
    system: string;
    fileName: string;
    languageId: string;
}

export function buildFIMContext(
    document: vscode.TextDocument,
    position: vscode.Position,
    maxPrefixLines: number,
    maxSuffixLines: number,
    enableFIM: boolean,
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

    if (enableFIM) {
        return {
            prompt: prefix,
            suffix,
            system: `You are a code completion engine. Complete the code at the cursor position in the file "${fileName}" (${languageId}). Output ONLY the code that goes between the prefix and suffix. No explanations, NO MARKDOWN.`,
            fileName,
            languageId,
        };
    }

    const prompt = `// File: ${fileName} (${languageId})\n${prefix}`;

    return {
        prompt,
        suffix,
        system: `You are a code completion engine. Continue the code exactly where it left off. Output ONLY the code continuation. No explanations, no markdown, no repeating existing code.`,
        fileName,
        languageId,
    };
}
