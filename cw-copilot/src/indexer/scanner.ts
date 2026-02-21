import * as path from 'node:path';
import * as fs from 'node:fs/promises';
import { logger } from '../util/logger';
import type { ProjectConfig } from './config';

const SKIP_LANGUAGE_IDS = new Set([
    'plaintext', 'log', 'json', 'jsonc', 'xml', 'yaml', 'yml',
    'markdown', 'csv', 'tsv', 'ini', 'toml', 'dotenv', 'properties',
    'html', 'svg', 'binary', 'image', 'pdf',
]);

export async function scanProject(
    workspaceRoot: string,
    config: ProjectConfig,
): Promise<string[]> {
    const results: string[] = [];

    for (const scanDir of config.scan) {
        const absDir = path.resolve(workspaceRoot, scanDir);

        try {
            await walkDirectory(absDir, workspaceRoot, config.ignore, results);
        } catch (err: unknown) {
            logger.warn(`Scanner: could not walk ${scanDir}: ${err instanceof Error ? err.message : String(err)}`);
        }
    }

    results.sort();
    logger.info(`Scanner: found ${results.length} file(s)`);
    return results;
}

export function shouldIndexLanguage(languageId: string): boolean {
    return !SKIP_LANGUAGE_IDS.has(languageId);
}

async function walkDirectory(
    dirPath: string,
    workspaceRoot: string,
    ignorePatterns: string[],
    results: string[],
): Promise<void> {
    let entries: import('node:fs').Dirent[];

    try {
        entries = await fs.readdir(dirPath, { withFileTypes: true });
    } catch {
        return;
    }

    for (const entry of entries) {
        const fullPath = path.join(dirPath, entry.name);
        const relativePath = path.relative(workspaceRoot, fullPath);

        if (isIgnored(relativePath, entry.name, ignorePatterns)) {
            continue;
        }

        if (entry.isDirectory()) {
            await walkDirectory(fullPath, workspaceRoot, ignorePatterns, results);
        } else if (entry.isFile()) {
            results.push(relativePath);
        }
    }
}

function isIgnored(relativePath: string, name: string, patterns: string[]): boolean {
    for (const pattern of patterns) {
        if (pattern.startsWith('*.')) {
            const ext = pattern.slice(1);
            if (name.endsWith(ext)) {
                return true;
            }
        } else if (relativePath.includes(pattern) || name === pattern) {
            return true;
        }
    }

    return false;
}
