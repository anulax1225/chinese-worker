import * as path from 'node:path';
import * as fs from 'node:fs/promises';
import { logger } from '../util/logger';

export interface FIMTokenFamily {
    prefix: string;
    suffix: string;
    middle: string;
    modelPattern: string;
}

export type FIMTokenMap = Record<string, FIMTokenFamily>;

export async function scaffoldFIMTokens(
    workspaceRoot: string,
    extensionPath: string,
): Promise<void> {
    const targetFile = path.join(workspaceRoot, '.cw', 'fim-tokens.json');
    const bundledFile = path.join(extensionPath, 'resources', 'fim-tokens.json');

    try {
        await fs.mkdir(path.join(workspaceRoot, '.cw'), { recursive: true });
        await fs.access(targetFile);
    } catch {
        try {
            await fs.copyFile(bundledFile, targetFile);
            logger.info('Scaffolded fim-tokens.json to .cw/');
        } catch (err: unknown) {
            logger.warn(`Could not scaffold fim-tokens.json: ${err instanceof Error ? err.message : String(err)}`);
        }
    }
}

export async function loadFIMTokens(
    extensionPath: string,
    workspaceRoot?: string,
): Promise<FIMTokenMap> {
    if (workspaceRoot) {
        const workspaceFile = path.join(workspaceRoot, '.cw', 'fim-tokens.json');
        const tokens = await tryLoadTokens(workspaceFile);
        if (tokens) {
            logger.info(`Loaded FIM tokens from .cw/fim-tokens.json (${Object.keys(tokens).length} families)`);
            return tokens;
        }
    }

    const bundledFile = path.join(extensionPath, 'resources', 'fim-tokens.json');
    const tokens = await tryLoadTokens(bundledFile);
    if (tokens) {
        logger.info(`Loaded FIM tokens from bundled defaults (${Object.keys(tokens).length} families)`);
        return tokens;
    }

    logger.warn('No FIM token config found');
    return {};
}

async function tryLoadTokens(filePath: string): Promise<FIMTokenMap | null> {
    try {
        const raw = await fs.readFile(filePath, 'utf-8');
        return JSON.parse(raw) as FIMTokenMap;
    } catch {
        return null;
    }
}