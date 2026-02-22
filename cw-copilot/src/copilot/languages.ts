import * as vscode from 'vscode';
import * as path from 'node:path';
import * as fs from 'node:fs/promises';
import { logger } from '../util/logger';

export interface LanguageConfig {
    id: string;
    extensions: string[];
    stopSequences: string[];
    commentPrefix: string;
    topLevelNodes: string[];
    nodeStopPatterns: Record<string, string[]>;
    importPatterns?: string[];
}

export async function scaffoldLanguageConfigs(
    workspaceRoot: string,
    extensionPath: string,
): Promise<void> {
    const targetDir = path.join(workspaceRoot, '.cw', 'languages');
    const bundledDir = path.join(extensionPath, 'resources', 'languages');

    try {
        await fs.mkdir(targetDir, { recursive: true });

        const bundledFiles = await fs.readdir(bundledDir);

        for (const file of bundledFiles) {
            if (!file.endsWith('.json')) {
                continue;
            }

            const targetFile = path.join(targetDir, file);

            try {
                await fs.access(targetFile);
            } catch {
                await fs.copyFile(path.join(bundledDir, file), targetFile);
                logger.info(`Scaffolded ${file} to .cw/languages/`);
            }
        }
    } catch (err: unknown) {
        logger.warn(`Could not scaffold language configs: ${err instanceof Error ? err.message : String(err)}`);
    }
}

export async function loadLanguageConfig(
    languageId: string,
    extensionPath: string,
    workspaceRoot?: string,
): Promise<LanguageConfig | null> {
    const filename = `${languageId}.json`;

    if (workspaceRoot) {
        const workspacePath = path.join(workspaceRoot, '.cw', 'languages', filename);
        const config = await tryLoadConfig(workspacePath);
        if (config) {
            return config;
        }
    }

    const bundledPath = path.join(extensionPath, 'resources', 'languages', filename);
    return tryLoadConfig(bundledPath);
}

export async function loadAllLanguageConfigs(
    extensionPath: string,
    workspaceRoot?: string,
): Promise<Map<string, LanguageConfig>> {
    const configs = new Map<string, LanguageConfig>();
    const bundledDir = path.join(extensionPath, 'resources', 'languages');

    try {
        const bundledFiles = await fs.readdir(bundledDir);

        for (const file of bundledFiles) {
            if (!file.endsWith('.json')) {
                continue;
            }

            const languageId = file.replace('.json', '');
            const config = await loadLanguageConfig(languageId, extensionPath, workspaceRoot);

            if (config) {
                configs.set(config.id, config);
            }
        }
    } catch (err: unknown) {
        logger.error(`Failed to load language configs: ${err instanceof Error ? err.message : String(err)}`);
    }

    if (workspaceRoot) {
        const workspaceDir = path.join(workspaceRoot, '.cw', 'languages');
        try {
            const workspaceFiles = await fs.readdir(workspaceDir);

            for (const file of workspaceFiles) {
                if (!file.endsWith('.json')) {
                    continue;
                }

                const languageId = file.replace('.json', '');
                if (configs.has(languageId)) {
                    continue;
                }

                const config = await tryLoadConfig(path.join(workspaceDir, file));
                if (config) {
                    configs.set(config.id, config);
                }
            }
        } catch {
            // Workspace dir may not exist, that's fine
        }
    }

    logger.info(`Loaded ${configs.size} language config(s)`);
    return configs;
}

async function tryLoadConfig(filePath: string): Promise<LanguageConfig | null> {
    try {
        const raw = await fs.readFile(filePath, 'utf-8');
        return JSON.parse(raw) as LanguageConfig;
    } catch {
        return null;
    }
}
