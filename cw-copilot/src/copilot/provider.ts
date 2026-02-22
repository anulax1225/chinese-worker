import * as vscode from 'vscode';
import * as path from 'node:path';
import { CWApiClient } from '../api/client';
import { getConfig } from '../config';
import { buildFIMContext } from './context';
import { delay } from '../util/debounce';
import { logger } from '../util/logger';
import type { LanguageConfig } from './languages';
import type { FIMTokenMap } from './fim-tokens';
import type { ContextRetriever, RetrievedChunk } from '../retriever/retriever';
import { buildRetrievalQuery } from '../retriever/query-builder';

export class CWCompletionProvider implements vscode.InlineCompletionItemProvider {
    private abortController: AbortController | null = null;
    private retriever: ContextRetriever | null = null;
    private workspaceRoot: string | null = null;

    constructor(
        private langConfigs: Map<string, LanguageConfig>,
        private fimTokens: FIMTokenMap,
    ) {}

    setRetriever(retriever: ContextRetriever, workspaceRoot: string): void {
        this.retriever = retriever;
        this.workspaceRoot = workspaceRoot;
    }

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

        // Retrieve project context (before generate, with abort-based timeout)
        let retrievedChunks: RetrievedChunk[] | undefined;

        if (this.retriever && this.workspaceRoot && config.retrievalEnabled) {
            const retrievalStart = Date.now();
            const retrievalAbort = new AbortController();
            const retrievalTimer = setTimeout(
                () => retrievalAbort.abort(),
                config.retrievalTimeoutMs,
            );

            try {
                const query = await buildRetrievalQuery(document, position, this.workspaceRoot);
                logger.info(`Retrieval query (${query.queryText.length} chars):\n${query.queryText}`);

                retrievedChunks = await this.retriever.retrieve(
                    query,
                    {
                        topK: config.retrievalTopK,
                        threshold: config.retrievalThreshold,
                        maxLines: config.retrievalMaxLines,
                    },
                    retrievalAbort.signal,
                );

                const retrievalMs = Date.now() - retrievalStart;

                if (retrievedChunks?.length) {
                    logger.info(`Retrieved ${retrievedChunks.length} chunk(s) in ${retrievalMs}ms:`);
                    for (const chunk of retrievedChunks) {
                        logger.info(`  - ${chunk.filePath} :: ${chunk.node_type} ${chunk.symbol} (similarity=${chunk.similarity.toFixed(3)}, ${chunk.lineCount} lines)`);
                    }
                } else {
                    logger.info(`Retrieval returned 0 chunks in ${retrievalMs}ms`);
                }
            } catch (err: unknown) {
                const retrievalMs = Date.now() - retrievalStart;
                if (retrievalAbort.signal.aborted) {
                    logger.info(`Retrieval timed out after ${retrievalMs}ms (limit: ${config.retrievalTimeoutMs}ms)`);
                } else {
                    logger.warn(`Retrieval failed: ${err instanceof Error ? err.message : String(err)}`);
                }
            } finally {
                clearTimeout(retrievalTimer);
            }
        }

        if (token.isCancellationRequested) {
            return undefined;
        }

        const fimFamily = config.enableFIM && config.fimTokenFamily
            ? this.fimTokens[config.fimTokenFamily]
            : undefined;

        if (config.enableFIM && config.fimTokenFamily && !fimFamily) {
            logger.warn(`Unknown FIM token family: "${config.fimTokenFamily}"`);
        }

        const langConfig = this.langConfigs.get(document.languageId);
        const commentPrefix = langConfig?.commentPrefix ?? '//';

        const projectName = this.workspaceRoot ? path.basename(this.workspaceRoot) : undefined;
        const relativeFilePath = this.workspaceRoot
            ? path.relative(this.workspaceRoot, document.uri.fsPath)
            : undefined;

        const ctx = buildFIMContext(
            document,
            position,
            config.maxPrefixLines,
            config.maxSuffixLines,
            config.enableFIM,
            fimFamily,
            retrievedChunks,
            commentPrefix,
            projectName,
            relativeFilePath,
        );

        if (ctx.prompt.trim() === '') {
            logger.info('Skipped: empty prompt');
            return undefined;
        }

        const langStop = langConfig?.stopSequences ?? ['\n\n'];
        const fimStop = fimFamily?.stop ?? [];
        const stopSequences = [...new Set([...langStop, ...fimStop])];

        const chunkInfo = retrievedChunks?.length ? `, context=${retrievedChunks.length} chunks` : '';
        logger.info(`Request: fim=${config.enableFIM}, lang=${ctx.languageId}, file=${ctx.fileName}, line=${position.line + 1}:${position.character}, prompt=${ctx.prompt.length} chars, suffix=${ctx.suffix.length} chars${chunkInfo}`);

        logger.info(`Prompt being sent to AI:\n--- PROMPT START ---\n${ctx.prompt}\n--- PROMPT END ---`);

        this.abortController = new AbortController();
        const { signal } = this.abortController;

        token.onCancellationRequested(() => this.abortController?.abort());

        try {
            const client = new CWApiClient(config.apiUrl, config.apiToken);

            const response = await client.generate(
                config.agentId,
                {
                    prompt: ctx.prompt,
                    suffix: ctx.raw ? undefined : (config.enableFIM ? ctx.suffix : undefined),
                    raw: ctx.raw || undefined,
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

            logger.info(`Completion: ${content.length} chars, model=${response.model}\n--- RESPONSE START ---\n${content.slice(0, 2000)}${content.length > 2000 ? '\n... (truncated)' : ''}\n--- RESPONSE END ---`);

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
