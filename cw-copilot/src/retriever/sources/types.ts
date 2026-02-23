export interface RetrievalCandidate {
    filePath: string;
    symbol: string;
    code: string;
    lineStart: number;
    lineEnd: number;
    lineCount: number;
    source: 'lsp' | 'tabs' | 'import' | 'embedding';
}

export interface DiagnosticContext {
    text: string;
    count: number;
}
