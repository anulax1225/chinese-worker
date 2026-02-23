import * as vscode from 'vscode';
import * as path from 'node:path';
import { CWApiClient } from '../api/client';
import type { GhostConversationResponse } from '../api/client';
import { getConfig } from '../config';
import { buildFIMContext, buildGhostContext } from './context';
import { delay } from '../util/debounce';
import { logger } from '../util/logger';
import type { LanguageConfig } from './languages';
import type { FIMTokenMap } from './fim-tokens';
import type { RetrievalPipeline, PipelineResult } from '../retriever/pipeline';
import type { RetrievalCandidate, DiagnosticContext } from '../retriever/sources/types';

const FILL_BLANK_TOOL = {
    name: 'fill_blank',
    description: 'Insert code at the cursor position (the <blank/> marker). Output ONLY the code to insert — no markdown, no explanations, no surrounding context.',
    parameters: {
        type: 'object',
        properties: {
            code: {
                type: 'string',
                description: 'The exact code to insert at the blank position',
            },
        },
        required: ['code'],
    },
} as const;

export class CWCompletionProvider implements vscode.InlineCompletionItemProvider {
    private abortController: AbortController | null = null;
    private pipeline: RetrievalPipeline | null = null;
    private workspaceRoot: string | null = null;

    constructor(
        private langConfigs: Map<string, LanguageConfig>,
        private fimTokens: FIMTokenMap,
    ) {}

    setPipeline(pipeline: RetrievalPipeline, workspaceRoot: string): void {
        this.pipeline = pipeline;
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
            return undefined;
        }

        if (token.isCancellationRequested) {
            return undefined;
        }

        // Phase 3: multi-signal retrieval pipeline
        let retrievedChunks: RetrievalCandidate[] | undefined;
        let diagnostics: DiagnosticContext | null = null;

        if (this.pipeline && this.workspaceRoot && config.retrievalEnabled) {
            const retrievalStart = Date.now();

            try {
                const result: PipelineResult = await this.pipeline.retrieve(
                    document,
                    position,
                    this.workspaceRoot,
                    {
                        maxLines: config.retrievalMaxLines,
                        topK: config.retrievalTopK,
                        embeddingTimeout: config.retrievalEmbeddingTimeout,
                        lspTimeout: config.retrievalLspTimeout,
                        embeddingThreshold: config.retrievalThreshold,
                        weights: config.retrievalWeights,
                    },
                );

                retrievedChunks = result.chunks.length > 0 ? result.chunks : undefined;
                diagnostics = result.diagnostics;

                const retrievalMs = Date.now() - retrievalStart;

                if (retrievedChunks?.length) {
                    logger.info(`Pipeline retrieved ${retrievedChunks.length} chunk(s) in ${retrievalMs}ms`);
                } else {
                    logger.info(`Pipeline returned 0 chunks in ${retrievalMs}ms`);
                }

                if (diagnostics) {
                    logger.info(`Pipeline found ${diagnostics.count} diagnostic(s) near cursor`);
                }
            } catch (err: unknown) {
                const retrievalMs = Date.now() - retrievalStart;
                logger.warn(`Pipeline failed in ${retrievalMs}ms: ${err instanceof Error ? err.message : String(err)}`);
            }
        }

        if (token.isCancellationRequested) {
            return undefined;
        }

        const projectName = this.workspaceRoot ? path.basename(this.workspaceRoot) : undefined;
        const relativeFilePath = this.workspaceRoot
            ? path.relative(this.workspaceRoot, document.uri.fsPath)
            : undefined;

        // Branch: generate endpoint vs ghost conversation
        if (config.completionMode === 'generate') {
            return this.completeFIM(document, position, config, retrievedChunks, diagnostics, projectName, relativeFilePath, token);
        }

        return this.completeGhost(document, position, config, retrievedChunks, diagnostics, projectName, relativeFilePath, token);
    }

    private async completeFIM(
        document: vscode.TextDocument,
        position: vscode.Position,
        config: ReturnType<typeof getConfig>,
        retrievedChunks: RetrievalCandidate[] | undefined,
        diagnostics: DiagnosticContext | null,
        projectName: string | undefined,
        relativeFilePath: string | undefined,
        token: vscode.CancellationToken,
    ): Promise<vscode.InlineCompletionItem[] | undefined> {
        const fimFamily = config.fimTokenFamily
            ? this.fimTokens[config.fimTokenFamily]
            : undefined;

        if (config.fimTokenFamily && !fimFamily) {
            logger.warn(`Unknown FIM token family: "${config.fimTokenFamily}"`);
        }

        const langConfig = this.langConfigs.get(document.languageId);
        const commentPrefix = langConfig?.commentPrefix ?? '//';

        const ctx = buildFIMContext(
            document,
            position,
            config.maxPrefixLines,
            config.maxSuffixLines,
            true,
            fimFamily,
            retrievedChunks,
            commentPrefix,
            projectName,
            relativeFilePath,
            diagnostics,
        );

        if (ctx.prompt.trim() === '') {
            logger.info('Skipped: empty prompt');
            return undefined;
        }

        const langStop = langConfig?.stopSequences ?? ['\n\n'];
        const fimStop = fimFamily?.stop ?? [];
        const stopSequences = [...new Set([...langStop, ...fimStop])];

        const chunkInfo = retrievedChunks?.length ? `, context=${retrievedChunks.length} chunks` : '';
        const diagInfo = diagnostics ? `, diagnostics=${diagnostics.count}` : '';
        logger.info(`Request: fim=true, lang=${ctx.languageId}, file=${ctx.fileName}, line=${position.line + 1}:${position.character}, prompt=${ctx.prompt.length} chars, suffix=${ctx.suffix.length} chars${chunkInfo}${diagInfo}`);
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
                    suffix: ctx.raw ? undefined : ctx.suffix,
                    raw: ctx.raw || undefined,
                    max_tokens: config.maxTokens,
                    temperature: config.temperature,
                    stop: stopSequences,
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

    private async completeGhost(
        document: vscode.TextDocument,
        position: vscode.Position,
        config: ReturnType<typeof getConfig>,
        retrievedChunks: RetrievalCandidate[] | undefined,
        diagnostics: DiagnosticContext | null,
        projectName: string | undefined,
        relativeFilePath: string | undefined,
        token: vscode.CancellationToken,
    ): Promise<vscode.InlineCompletionItem[] | undefined> {
        const fimFamily = config.enableFIM && config.fimTokenFamily
            ? this.fimTokens[config.fimTokenFamily]
            : undefined;

        const ghostCtx = buildGhostContext(
            document,
            position,
            config.maxPrefixLines,
            config.maxSuffixLines,
            retrievedChunks,
            projectName,
            relativeFilePath,
            diagnostics,
            fimFamily,
        );

        if (ghostCtx.isEmpty) {
            logger.info('Skipped: empty ghost context');
            return undefined;
        }

        const fileName = path.basename(document.fileName);
        const chunkInfo = retrievedChunks?.length ? `, context=${retrievedChunks.length} chunks` : '';
        const diagInfo = diagnostics ? `, diagnostics=${diagnostics.count}` : '';
        logger.info(`Ghost request: lang=${document.languageId}, file=${fileName}, line=${position.line + 1}:${position.character}${chunkInfo}${diagInfo}`);
        logger.info(`Ghost user message:\n--- MSG START ---\n${ghostCtx.userMessage}\n--- MSG END ---`);

        if (Object.keys(ghostCtx.contextVariables).length > 0) {
            logger.info(`Ghost context variables: ${JSON.stringify(ghostCtx.contextVariables).slice(0, 500)}`);
        }

        this.abortController = new AbortController();
        const { signal } = this.abortController;
        token.onCancellationRequested(() => this.abortController?.abort());

        try {
            const client = new CWApiClient(config.apiUrl, config.apiToken);

            const ghostResponse = await client.ghostConversation(
                config.agentId,
                {
                    content: ghostCtx.userMessage,
                    messages: [],
                    client_tool_schemas: config.ghostUseTool ? [FILL_BLANK_TOOL] : undefined,
                    max_turns: 1,
                    context: ghostCtx.contextVariables,
                },
                signal,
            );

            if (!ghostResponse) {
                logger.warn('Ghost response: null (API error or aborted)');
                return undefined;
            }

            const completion = extractGhostCompletion(ghostResponse);

            if (!completion || completion.trim() === '') {
                logger.info(`Ghost response: empty completion (status=${ghostResponse.status})`);
                return undefined;
            }

            logger.info(`Ghost completion: ${completion.length} chars\n--- RESPONSE START ---\n${completion.slice(0, 2000)}${completion.length > 2000 ? '\n... (truncated)' : ''}\n--- RESPONSE END ---`);

            return [
                new vscode.InlineCompletionItem(
                    completion,
                    new vscode.Range(position, position),
                ),
            ];
        } catch (err: unknown) {
            if (err instanceof vscode.CancellationError) {
                return undefined;
            }
            logger.error(`Ghost completion failed: ${err instanceof Error ? err.message : String(err)}`);
            return undefined;
        }
    }
}

function extractGhostCompletion(response: GhostConversationResponse): string | null {
    // Preferred: AI called fill_blank tool
    if (response.status === 'waiting_for_tool' && response.tool_request?.name === 'fill_blank') {
        const code = response.tool_request.arguments.code;
        if (typeof code === 'string') {
            return code;
        }
        logger.warn('fill_blank tool_request.arguments.code is not a string');
        return null;
    }

    // Fallback: AI completed without calling tool — extract from last assistant message
    if (response.status === 'completed') {
        const lastAssistant = [...response.messages]
            .reverse()
            .find(m => m.role === 'assistant');

        if (lastAssistant?.content) {
            logger.info('Ghost fallback: extracting from assistant message content (tool not called)');
            let content = lastAssistant.content;
            const fenceMatch = content.match(/```[\w]*\n([\s\S]*?)```/);
            if (fenceMatch) {
                content = fenceMatch[1];
            }
            return content.trim();
        }
    }

    // Safety net: search messages for fill_blank in tool_calls
    for (const msg of response.messages) {
        if (msg.role === 'assistant' && msg.tool_calls) {
            const fillCall = msg.tool_calls.find(tc => tc.name === 'fill_blank');
            if (fillCall && typeof fillCall.arguments.code === 'string') {
                logger.info('Ghost fallback: found fill_blank in message tool_calls');
                return fillCall.arguments.code;
            }
        }
    }

    if (response.status === 'failed') {
        logger.warn(`Ghost conversation failed: ${response.error}`);
        return null;
    }

    logger.warn(`Ghost response: unexpected status=${response.status}, no completion extracted`);
    return null;
}
