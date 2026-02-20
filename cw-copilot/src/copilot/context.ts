import * as vscode from 'vscode';

export interface FIMContext {
    prompt: string;
    suffix: string;
}

export function buildFIMContext(
    document: vscode.TextDocument,
    position: vscode.Position,
    maxPrefixLines: number,
    maxSuffixLines: number,
): FIMContext {
    const startLine = Math.max(0, position.line - maxPrefixLines);
    const endLine = Math.min(document.lineCount - 1, position.line + maxSuffixLines);

    const prefixRange = new vscode.Range(startLine, 0, position.line, position.character);
    const prompt = document.getText(prefixRange);

    const lastChar = document.lineAt(endLine).text.length;
    const suffixRange = new vscode.Range(position.line, position.character, endLine, lastChar);
    const suffix = document.getText(suffixRange);

    return { prompt, suffix };
}
