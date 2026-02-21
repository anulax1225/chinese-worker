import * as path from 'node:path';
import * as fs from 'node:fs/promises';
import { logger } from '../util/logger';

export interface ProjectConfig {
    scan: string[];
    ignore: string[];
}

const DEFAULTS: ProjectConfig = {
    scan: ['.'],
    ignore: ['node_modules', '.git', 'vendor', 'dist', 'out', 'build', '.cw'],
};

export async function loadProjectConfig(workspaceRoot: string): Promise<ProjectConfig | null> {
    const configPath = path.join(workspaceRoot, '.cw', 'config.json');

    try {
        const raw = await fs.readFile(configPath, 'utf-8');
        const parsed = JSON.parse(raw) as Partial<ProjectConfig>;

        return {
            scan: parsed.scan ?? DEFAULTS.scan,
            ignore: parsed.ignore ?? DEFAULTS.ignore,
        };
    } catch {
        return null;
    }
}

export async function scaffoldProjectConfig(
    workspaceRoot: string,
    extensionPath: string,
): Promise<void> {
    const targetPath = path.join(workspaceRoot, '.cw', 'config.json');
    const bundledPath = path.join(extensionPath, 'resources', 'config.json');

    try {
        await fs.access(targetPath);
    } catch {
        try {
            await fs.mkdir(path.join(workspaceRoot, '.cw'), { recursive: true });
            await fs.copyFile(bundledPath, targetPath);
            logger.info('Scaffolded config.json to .cw/');
        } catch (err: unknown) {
            logger.warn(`Could not scaffold config.json: ${err instanceof Error ? err.message : String(err)}`);
        }
    }
}
