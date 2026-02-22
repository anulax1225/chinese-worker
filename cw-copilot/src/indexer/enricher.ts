import * as vscode from 'vscode';
import type { TopLevelNode } from './symbols';

export async function enrichNode(
    node: TopLevelNode,
    document: vscode.TextDocument,
    filePath: string,
    commentPrefix: string,
): Promise<string> {
    const parts: string[] = [];

    parts.push(`${commentPrefix} File: ${filePath}`);

    if (node.node_type === 'imports') {
        parts.push(`${commentPrefix} Imports`);
        parts.push(extractBounded(node, document, commentPrefix, 50));
    } else {
        parts.push(`${commentPrefix} ${capitalize(node.node_type)}: ${camelToWords(node.symbol)}`);

        switch (node.node_type) {
            case 'class':
            case 'interface':
            case 'struct':
                parts.push(buildSkeleton(node, document));
                break;

            case 'function':
                parts.push(extractSignature(node, document));
                break;

            default:
                parts.push(extractBounded(node, document, commentPrefix, 30));
                break;
        }
    }

    return parts.join('\n');
}

export function camelToWords(name: string): string {
    return name
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
        .replace(/[_-]+/g, ' ')
        .toLowerCase()
        .trim();
}

function capitalize(str: string): string {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function buildSkeleton(node: TopLevelNode, document: vscode.TextDocument): string {
    const declLine = document.lineAt(node.line_start).text.trimEnd();
    const parts: string[] = [declLine];

    for (const child of node.children) {
        if (child.detail) {
            parts.push(`  ${child.name}${child.detail};`);
        } else {
            parts.push(`  ${child.name};`);
        }
    }

    parts.push('}');
    return parts.join('\n');
}

function extractSignature(node: TopLevelNode, document: vscode.TextDocument): string {
    const fullText = document.getText(
        new vscode.Range(node.line_start, 0, node.line_end, 999),
    );

    const braceIndex = fullText.indexOf('{');
    if (braceIndex > 0) {
        return fullText.slice(0, braceIndex).trim();
    }

    const colonIndex = fullText.indexOf(':');
    if (colonIndex > 0 && node.node_type === 'function') {
        const beforeColon = fullText.slice(0, colonIndex + 1);
        const afterColon = fullText.slice(colonIndex + 1).split('\n')[0];
        return (beforeColon + afterColon).trim();
    }

    return fullText.split('\n')[0].trimEnd();
}

function extractBounded(
    node: TopLevelNode,
    document: vscode.TextDocument,
    commentPrefix: string,
    maxLines: number,
): string {
    const fullText = document.getText(
        new vscode.Range(node.line_start, 0, node.line_end, 999),
    );

    const lines = fullText.split('\n');
    if (lines.length <= maxLines) {
        return fullText;
    }

    const truncated = lines.slice(0, maxLines - 1).join('\n');
    return `${truncated}\n  ${commentPrefix} ... (${lines.length - maxLines + 1} more lines)`;
}
