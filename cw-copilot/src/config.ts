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
}

export function getConfig(): CWConfig {
    const cfg = vscode.workspace.getConfiguration('cw');

    return {
        apiUrl: cfg.get<string>('apiUrl', 'http://localhost'),
        apiToken: cfg.get<string>('apiToken', ''),
        agentId: cfg.get<number>('agentId', 1),
        enabled: cfg.get<boolean>('enabled', true),
        debounceMs: cfg.get<number>('debounceMs', 300),
        maxPrefixLines: cfg.get<number>('maxPrefixLines', 100),
        maxSuffixLines: cfg.get<number>('maxSuffixLines', 30),
        maxTokens: cfg.get<number>('maxTokens', 256),
        temperature: cfg.get<number>('temperature', 0.2),
    };
}
