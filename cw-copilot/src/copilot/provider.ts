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

        if (!config.enabled) {
            logger.info('Skipped: disabled');
            return undefined;
        }

        if (!config.apiToken) {
            logger.warn('Skipped: no API token configured (set cw.apiToken)');
            return undefined;
        }

        this.abortController?.abort();

        try {
            await delay(config.debounceMs, token);
        } catch {
            return undefined; // Cancelled during debounce — normal flow
        }

        if (token.isCancellationRequested) {
            return undefined;
        }

        const ctx = buildFIMContext(
            document,
            position,
            config.maxPrefixLines,
            config.maxSuffixLines,
            config.enableFIM,
        );

        if (ctx.prompt.trim() === '') {
            logger.info('Skipped: empty prompt');
            return undefined;
        }

        const langConfig = this.langConfigs.get(document.languageId);
        const stopSequences = langConfig?.stopSequences ?? ['\n\n'];

        logger.info(`Request: fim=${config.enableFIM}, lang=${ctx.languageId}, file=${ctx.fileName}, line=${position.line + 1}:${position.character}, prompt=${ctx.prompt.length} chars, suffix=${ctx.suffix.length} chars`);

        this.abortController = new AbortController();
        const { signal } = this.abortController;

        token.onCancellationRequested(() => this.abortController?.abort());

        try {
            const client = new CWApiClient(config.apiUrl, config.apiToken);

            const response = await client.generate(
                config.agentId,
                {
                    prompt: ctx.prompt,
                    suffix: config.enableFIM ? ctx.suffix : undefined,
                    system: ctx.system,
                    max_tokens: config.maxTokens,
                    temperature: config.temperature,
                    stop: stopSequences ?? [],
                    stream: false,
                    think: config.thinkingModel || undefined,
                },
                signal,
            );

            if (!response) {
                logger.warn('Response: null (API error or aborted)');
                return undefined;
            }

            if (!response.content || response.content.trim() === '') {
                logger.info('Response: empty content');
                return undefined;
            }

            const content = response.content;

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
