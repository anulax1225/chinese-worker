import * as vscode from 'vscode';

export type CompletionMode = 'generate' | 'ghost';

export interface CWConfig {
    apiUrl: string;
    apiToken: string;
    agentId: number;
    enabled: boolean;
    debounceMs: number;
    maxPrefixLines: number;
    maxSuffixLines: number;
    maxTokens: number;
    temperature: number;
    completionMode: CompletionMode;
    enableFIM: boolean;
    fimTokenFamily: string;
    thinkingModel: boolean;
    ghostUseTool: boolean;
    retrievalEnabled: boolean;
    retrievalTopK: number;
    retrievalThreshold: number;
    retrievalMaxLines: number;
    retrievalTimeoutMs: number;
    retrievalEmbeddingEnabled: boolean;
    retrievalEmbeddingTimeout: number;
    retrievalLspTimeout: number;
    retrievalWeights: {
        lsp: number;
        tabs: number;
        import: number;
        embedding: number;
    };
}

export function getConfig(): CWConfig {
    const cfg = vscode.workspace.getConfiguration('cw');

    return {
        apiUrl: cfg.get<string>('apiUrl', 'http://localhost'),
        apiToken: cfg.get<string>('apiToken', ''),
        agentId: cfg.get<number>('agentId', 2),
        enabled: cfg.get<boolean>('enabled', true),
        debounceMs: cfg.get<number>('debounceMs', 300),
        maxPrefixLines: cfg.get<number>('maxPrefixLines', 100),
        maxSuffixLines: cfg.get<number>('maxSuffixLines', 30),
        maxTokens: cfg.get<number>('maxTokens', 256),
        temperature: cfg.get<number>('temperature', 0.2),
        completionMode: cfg.get<string>('completionMode', 'generate') as CompletionMode,
        enableFIM: cfg.get<boolean>('enableFIM', false),
        fimTokenFamily: cfg.get<string>('fimTokenFamily', ''),
        thinkingModel: cfg.get<boolean>('thinkingModel', false),
        ghostUseTool: cfg.get<boolean>('ghostUseTool', true),
        retrievalEnabled: cfg.get<boolean>('retrieval.enabled', true),
        retrievalTopK: cfg.get<number>('retrieval.topK', 8),
        retrievalThreshold: cfg.get<number>('retrieval.threshold', 0.5),
        retrievalMaxLines: cfg.get<number>('retrieval.maxLines', 60),
        retrievalTimeoutMs: cfg.get<number>('retrieval.timeoutMs', 5000),
        retrievalEmbeddingEnabled: cfg.get<boolean>('retrieval.embeddingEnabled', true),
        retrievalEmbeddingTimeout: cfg.get<number>('retrieval.embeddingTimeout', 200),
        retrievalLspTimeout: cfg.get<number>('retrieval.lspTimeout', 100),
        retrievalWeights: {
            lsp: cfg.get<number>('retrieval.weights.lsp', 1.5),
            tabs: cfg.get<number>('retrieval.weights.tabs', 1.0),
            import: cfg.get<number>('retrieval.weights.import', 1.2),
            embedding: cfg.get<number>('retrieval.weights.embedding', 1.0),
        },
    };
}
