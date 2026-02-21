import * as vscode from 'vscode';

export interface TopLevelNode {
    symbol: string;
    node_type: string;
    line_start: number;
    line_end: number;
    detail?: string;
    children: ChildNode[];
}

export interface ChildNode {
    name: string;
    kind: vscode.SymbolKind;
    detail?: string;
}

const INDEXABLE_KINDS = new Set([
    vscode.SymbolKind.Class,
    vscode.SymbolKind.Function,
    vscode.SymbolKind.Interface,
    vscode.SymbolKind.Enum,
    vscode.SymbolKind.Struct,
    vscode.SymbolKind.TypeParameter,
    vscode.SymbolKind.Variable,
    vscode.SymbolKind.Constant,
    vscode.SymbolKind.Module,
    vscode.SymbolKind.Namespace,
]);

const KIND_NAMES: Record<number, string> = {
    [vscode.SymbolKind.Class]: 'class',
    [vscode.SymbolKind.Function]: 'function',
    [vscode.SymbolKind.Interface]: 'interface',
    [vscode.SymbolKind.Enum]: 'enum',
    [vscode.SymbolKind.Struct]: 'struct',
    [vscode.SymbolKind.TypeParameter]: 'type',
    [vscode.SymbolKind.Variable]: 'variable',
    [vscode.SymbolKind.Constant]: 'constant',
    [vscode.SymbolKind.Module]: 'module',
    [vscode.SymbolKind.Namespace]: 'namespace',
};

export async function getTopLevelNodes(uri: vscode.Uri): Promise<TopLevelNode[]> {
    const symbols = await vscode.commands.executeCommand<vscode.DocumentSymbol[]>(
        'vscode.executeDocumentSymbolProvider',
        uri,
    );

    if (!symbols?.length) {
        return [];
    }

    return symbols
        .filter(s => INDEXABLE_KINDS.has(s.kind))
        .map(s => ({
            symbol: s.name,
            node_type: kindToString(s.kind),
            line_start: s.range.start.line,
            line_end: s.range.end.line,
            detail: s.detail || undefined,
            children: (s.children || [])
                .filter(c => isChildIndexable(c.kind))
                .map(c => ({
                    name: c.name,
                    kind: c.kind,
                    detail: c.detail || undefined,
                })),
        }));
}

function kindToString(kind: vscode.SymbolKind): string {
    return KIND_NAMES[kind] ?? 'symbol';
}

function isChildIndexable(kind: vscode.SymbolKind): boolean {
    return [
        vscode.SymbolKind.Method,
        vscode.SymbolKind.Property,
        vscode.SymbolKind.Field,
        vscode.SymbolKind.Constructor,
        vscode.SymbolKind.Function,
        vscode.SymbolKind.Variable,
        vscode.SymbolKind.Constant,
        vscode.SymbolKind.EnumMember,
    ].includes(kind);
}
