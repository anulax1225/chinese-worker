import * as vscode from 'vscode';

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
    enableFIM: boolean;
    fimTokenFamily: string;
    thinkingModel: boolean;
    retrievalEnabled: boolean;
    retrievalTopK: number;
    retrievalThreshold: number;
    retrievalMaxLines: number;
    retrievalTimeoutMs: number;
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
        enableFIM: cfg.get<boolean>('enableFIM', false),
        fimTokenFamily: cfg.get<string>('fimTokenFamily', ''),
        thinkingModel: cfg.get<boolean>('thinkingModel', false),
        retrievalEnabled: cfg.get<boolean>('retrieval.enabled', true),
        retrievalTopK: cfg.get<number>('retrieval.topK', 3),
        retrievalThreshold: cfg.get<number>('retrieval.threshold', 0.5),
        retrievalMaxLines: cfg.get<number>('retrieval.maxLines', 50),
        retrievalTimeoutMs: cfg.get<number>('retrieval.timeoutMs', 2000),
    };
}
