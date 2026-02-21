import * as vscode from 'vscode';
import * as path from 'node:path';
import { getConfig } from './config';
import { CWCompletionProvider } from './copilot/provider';
import { scaffoldLanguageConfigs, loadAllLanguageConfigs } from './copilot/languages';
import { scaffoldFIMTokens, loadFIMTokens } from './copilot/fim-tokens';
import { loadProjectConfig, scaffoldProjectConfig } from './indexer/config';
import { IndexStore } from './indexer/store';
import { IndexManager } from './indexer/manager';
import { ContextRetriever } from './retriever/retriever';
import { CWApiClient } from './api/client';
import { logger } from './util/logger';

export async function activate(context: vscode.ExtensionContext): Promise<void> {
    logger.info('CW Copilot activating...');

    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    logger.info(`Workspace root: ${workspaceRoot ?? '(none)'}`);
    logger.info(`Extension path: ${context.extensionPath}`);

    if (workspaceRoot) {
        await scaffoldLanguageConfigs(workspaceRoot, context.extensionPath);
        await scaffoldFIMTokens(workspaceRoot, context.extensionPath);
        await scaffoldProjectConfig(workspaceRoot, context.extensionPath);
    }

    const langConfigs = await loadAllLanguageConfigs(context.extensionPath, workspaceRoot);
    logger.info(`Languages available: ${[...langConfigs.keys()].join(', ') || '(none)'}`);

    const fimTokens = await loadFIMTokens(context.extensionPath, workspaceRoot);

    const provider = new CWCompletionProvider(langConfigs, fimTokens);

    const providerDisposable = vscode.languages.registerInlineCompletionItemProvider(
        { pattern: '**' },
        provider,
    );
    context.subscriptions.push(providerDisposable);

    const statusBarItem = vscode.window.createStatusBarItem(
        vscode.StatusBarAlignment.Right,
        100,
    );
    statusBarItem.command = 'cw.toggle';
    statusBarItem.tooltip = 'CW Copilot - Click to toggle';

    function updateStatusBar(): void {
        const config = getConfig();
        statusBarItem.text = config.enabled ? '$(sparkle) CW' : '$(circle-slash) CW';
    }

    updateStatusBar();
    statusBarItem.show();
    context.subscriptions.push(statusBarItem);

    const config = getConfig();
    logger.info(`Config: apiUrl=${config.apiUrl}, agentId=${config.agentId}, enabled=${config.enabled}, token=${config.apiToken ? '***' : '(empty)'}`);

    const toggleCmd = vscode.commands.registerCommand('cw.toggle', () => {
        const cfg = vscode.workspace.getConfiguration('cw');
        const current = cfg.get<boolean>('enabled', true);
        cfg.update('enabled', !current, vscode.ConfigurationTarget.Global);
        logger.info(`Toggled: enabled=${!current}`);
        vscode.window.showInformationMessage(`CW Copilot ${!current ? 'enabled' : 'disabled'}`);
    });
    context.subscriptions.push(toggleCmd);

    const configListener = vscode.workspace.onDidChangeConfiguration(e => {
        if (e.affectsConfiguration('cw.enabled')) {
            updateStatusBar();
        }
    });
    context.subscriptions.push(configListener);

    // Phase 2: Project indexing
    if (workspaceRoot && config.apiToken) {
        const projectConfig = await loadProjectConfig(workspaceRoot);

        if (projectConfig) {
            logger.info('Indexer: .cw/config.json found, initializing project indexing');

            const apiClient = new CWApiClient(config.apiUrl, config.apiToken);
            const store = new IndexStore();
            const indexManager = new IndexManager(apiClient, store, langConfigs);
            const retriever = new ContextRetriever(apiClient, store, workspaceRoot);

            provider.setRetriever(retriever, workspaceRoot);

            // Load existing index immediately so retrieval works before reindex finishes
            const existingIndex = await store.load(workspaceRoot);
            if (Object.keys(existingIndex.files).length > 0) {
                retriever.setIndex(existingIndex);
                logger.info(`Indexer: loaded existing index with ${Object.keys(existingIndex.files).length} file(s)`);
            }

            // Full index in background (non-blocking)
            indexManager.indexProject(workspaceRoot, projectConfig).then(() => {
                const index = indexManager.getIndex();
                if (index) {
                    retriever.setIndex(index);
                }
            }).catch(err => {
                logger.error(`Indexer: initial indexing failed: ${err instanceof Error ? err.message : String(err)}`);
            });

            // Incremental re-index on file save
            const saveWatcher = vscode.workspace.onDidSaveTextDocument(doc => {
                const relative = path.relative(workspaceRoot, doc.uri.fsPath);
                if (relative.startsWith('..') || path.isAbsolute(relative)) {
                    return;
                }

                indexManager.indexFile(workspaceRoot, relative).then(() => {
                    const index = indexManager.getIndex();
                    if (index) {
                        retriever.setIndex(index);
                    }
                }).catch(err => {
                    logger.warn(`Indexer: incremental index failed for ${relative}: ${err instanceof Error ? err.message : String(err)}`);
                });
            });
            context.subscriptions.push(saveWatcher);

            // Clean up on file delete
            const deleteWatcher = vscode.workspace.createFileSystemWatcher('**/*');
            deleteWatcher.onDidDelete(uri => {
                const relative = path.relative(workspaceRoot, uri.fsPath);
                if (relative.startsWith('..') || path.isAbsolute(relative)) {
                    return;
                }

                indexManager.removeFile(workspaceRoot, relative).then(() => {
                    const index = indexManager.getIndex();
                    if (index) {
                        retriever.setIndex(index);
                    }
                }).catch(() => {});
            });
            context.subscriptions.push(deleteWatcher);

            // Manual reindex command
            const reindexCmd = vscode.commands.registerCommand('cw.reindex', () => {
                vscode.window.withProgress(
                    {
                        location: vscode.ProgressLocation.Notification,
                        title: 'CW Copilot: Reindexing project...',
                        cancellable: false,
                    },
                    async () => {
                        await indexManager.indexProject(workspaceRoot, projectConfig);
                        const index = indexManager.getIndex();
                        if (index) {
                            retriever.setIndex(index);
                            const nodeCount = Object.values(index.files).reduce((sum, f) => sum + f.nodes.length, 0);
                            vscode.window.showInformationMessage(`CW Copilot: Indexed ${Object.keys(index.files).length} file(s), ${nodeCount} symbol(s)`);
                        }
                    },
                );
            });
            context.subscriptions.push(reindexCmd);
        } else {
            logger.info('Indexer: no .cw/config.json found, project indexing disabled');

            const reindexCmd = vscode.commands.registerCommand('cw.reindex', () => {
                vscode.window.showWarningMessage('CW Copilot: Create .cw/config.json to enable project indexing');
            });
            context.subscriptions.push(reindexCmd);
        }
    } else {
        const reindexCmd = vscode.commands.registerCommand('cw.reindex', () => {
            vscode.window.showWarningMessage('CW Copilot: Configure API token and open a workspace to enable indexing');
        });
        context.subscriptions.push(reindexCmd);
    }

    context.subscriptions.push({ dispose: () => logger.dispose() });

    logger.info('CW Copilot activated');
}

export function deactivate(): void {}
