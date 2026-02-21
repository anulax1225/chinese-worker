import * as vscode from 'vscode';
import * as path from 'node:path';

export interface QueryContext {
    queryText: string;
    currentFilePath: string;
}

export async function buildRetrievalQuery(
    document: vscode.TextDocument,
    position: vscode.Position,
    workspaceRoot: string,
): Promise<QueryContext> {
    const parts: string[] = [];
    const relPath = path.relative(workspaceRoot, document.uri.fsPath);

    // 1. File context
    parts.push(`// File: ${relPath}`);

    // 2-4. LSP calls with individual 20ms timeouts, run in parallel
    const [symbols, sigHelp, hovers] = await Promise.all([
        withTimeout(
            vscode.commands.executeCommand<vscode.DocumentSymbol[]>(
                'vscode.executeDocumentSymbolProvider',
                document.uri,
            ),
            20,
        ),
        withTimeout(
            vscode.commands.executeCommand<vscode.SignatureHelp>(
                'vscode.executeSignatureHelpProvider',
                document.uri,
                position,
            ),
            20,
        ),
        withTimeout(
            vscode.commands.executeCommand<vscode.Hover[]>(
                'vscode.executeHoverProvider',
                document.uri,
                position,
            ),
            20,
        ),
    ]);

    // 2. Enclosing scope
    if (symbols) {
        const scope = findEnclosingSymbol(symbols, position);
        if (scope) {
            parts.push(`// In: ${scope.path}`);
            parts.push(`// Scope: ${camelToWords(scope.name)}`);
        }
    }

    // 3. Signature help
    if (sigHelp?.signatures?.length) {
        const activeSig = sigHelp.signatures[sigHelp.activeSignature ?? 0];
        if (activeSig) {
            parts.push(`// Calling: ${activeSig.label}`);
        }
    }

    // 4. Hover info
    if (hovers?.length) {
        const hoverText = hovers[0].contents
            .map(c => typeof c === 'string' ? c : c.value)
            .join(' ')
            .replace(/```\w*\n?/g, '')
            .trim()
            .slice(0, 200);

        if (hoverText) {
            parts.push(`// Type: ${hoverText}`);
        }
    }

    // 5. Nearby identifiers
    const nearbyStart = Math.max(0, position.line - 5);
    const nearbyEnd = Math.min(document.lineCount - 1, position.line + 5);
    const nearbyRange = new vscode.Range(nearbyStart, 0, nearbyEnd, 999);
    const nearbyText = document.getText(nearbyRange);
    const identifiers = extractIdentifiers(nearbyText);

    if (identifiers.length > 0) {
        const readable = identifiers.slice(0, 10).map(camelToWords).join(', ');
        parts.push(`// Uses: ${readable}`);
    }

    // 6. Code prefix (last ~15 lines before cursor)
    const prefixStart = Math.max(0, position.line - 15);
    const prefixRange = new vscode.Range(prefixStart, 0, position.line, position.character);
    const prefix = document.getText(prefixRange);
    parts.push(prefix);

    return {
        queryText: parts.join('\n'),
        currentFilePath: relPath,
    };
}

interface EnclosingScope {
    name: string;
    path: string;
}

function findEnclosingSymbol(
    symbols: vscode.DocumentSymbol[],
    position: vscode.Position,
): EnclosingScope | null {
    for (const symbol of symbols) {
        if (!symbol.range.contains(position)) {
            continue;
        }

        const childMatch = findEnclosingSymbol(symbol.children || [], position);
        if (childMatch) {
            return {
                name: childMatch.name,
                path: `${symbol.name} > ${childMatch.path}`,
            };
        }

        return {
            name: symbol.name,
            path: symbol.name,
        };
    }

    return null;
}

export function camelToWords(name: string): string {
    return name
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
        .replace(/[_-]+/g, ' ')
        .toLowerCase()
        .trim();
}

function extractIdentifiers(code: string): string[] {
    const matches = code.match(/[a-zA-Z_]\w{2,}/g);
    if (!matches) {
        return [];
    }

    const KEYWORDS = new Set([
        'const', 'let', 'var', 'function', 'class', 'return', 'import', 'export',
        'from', 'async', 'await', 'new', 'this', 'true', 'false', 'null', 'undefined',
        'void', 'string', 'number', 'boolean', 'interface', 'type', 'enum',
        'if', 'else', 'for', 'while', 'switch', 'case', 'break', 'continue',
        'try', 'catch', 'finally', 'throw', 'extends', 'implements',
        'def', 'self', 'None', 'True', 'False', 'elif', 'except', 'pass',
        'public', 'private', 'protected', 'static', 'readonly',
    ]);

    const seen = new Set<string>();
    const result: string[] = [];

    for (const match of matches) {
        if (!KEYWORDS.has(match) && !seen.has(match)) {
            seen.add(match);
            result.push(match);
        }
    }

    return result;
}

async function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T | null> {
    const timer = new Promise<null>(resolve => setTimeout(() => resolve(null), ms));
    return Promise.race([promise, timer]);
}
