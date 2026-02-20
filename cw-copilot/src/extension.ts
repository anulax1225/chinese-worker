import * as vscode from 'vscode';
import { getConfig } from './config';
import { CWCompletionProvider } from './copilot/provider';
import { scaffoldLanguageConfigs, loadAllLanguageConfigs } from './copilot/languages';
import { logger } from './util/logger';

export async function activate(context: vscode.ExtensionContext): Promise<void> {
    logger.info('CW Copilot activating...');

    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    logger.info(`Workspace root: ${workspaceRoot ?? '(none)'}`);
    logger.info(`Extension path: ${context.extensionPath}`);

    if (workspaceRoot) {
        await scaffoldLanguageConfigs(workspaceRoot, context.extensionPath);
    }

    const langConfigs = await loadAllLanguageConfigs(context.extensionPath, workspaceRoot);
    logger.info(`Languages available: ${[...langConfigs.keys()].join(', ') || '(none)'}`);

    const provider = new CWCompletionProvider(langConfigs);

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

    context.subscriptions.push({ dispose: () => logger.dispose() });

    logger.info('CW Copilot activated');
}

export function deactivate(): void {}
