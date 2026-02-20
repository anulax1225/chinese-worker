import * as vscode from 'vscode';
import { CWApiClient } from '../api/client';
import { getConfig } from '../config';
import { buildFIMContext } from './context';
import { delay } from '../util/debounce';
import { logger } from '../util/logger';
import type { LanguageConfig } from './languages';

export class CWCompletionProvider implements vscode.InlineCompletionItemProvider {
    private abortController: AbortController | null = null;

    constructor(
        private langConfigs: Map<string, LanguageConfig>,
    ) {}

    async provideInlineCompletionItems(
        document: vscode.TextDocument,
        position: vscode.Position,
        _context: vscode.InlineCompletionContext,
        token: vscode.CancellationToken,
    ): Promise<vscode.InlineCompletionItem[] | undefined> {
        const config = getConfig();

        if (!config.enabled || !config.apiToken) {
            return undefined;
        }

        this.abortController?.abort();

        try {
            await delay(config.debounceMs, token);
        } catch {
            return undefined;
        }

        if (token.isCancellationRequested) {
            return undefined;
        }

        const { prompt, suffix } = buildFIMContext(
            document,
            position,
            config.maxPrefixLines,
            config.maxSuffixLines,
        );

        if (prompt.trim() === '') {
            return undefined;
        }

        const langConfig = this.langConfigs.get(document.languageId);
        const stopSequences = langConfig?.stopSequences ?? ['\n\n'];

        this.abortController = new AbortController();
        const { signal } = this.abortController;

        token.onCancellationRequested(() => this.abortController?.abort());

        try {
            const client = new CWApiClient(config.apiUrl, config.apiToken);

            const response = await client.generate(
                config.agentId,
                {
                    prompt,
                    suffix,
                    max_tokens: config.maxTokens,
                    temperature: config.temperature,
                    stop: stopSequences,
                    stream: false,
                },
                signal,
            );

            if (!response || !response.content) {
                return undefined;
            }

            const content = response.content;
            if (content.trim() === '') {
                return undefined;
            }

            logger.info(`Completion: ${content.length} chars, model=${response.model}`);

            return [
                new vscode.InlineCompletionItem(
                    content,
                    new vscode.Range(position, position),
                ),
            ];
        } catch (err: unknown) {
            if (err instanceof vscode.CancellationError) {
                return undefined;
            }

            logger.error(`Completion failed: ${err instanceof Error ? err.message : String(err)}`);
            return undefined;
        }
    }
}
