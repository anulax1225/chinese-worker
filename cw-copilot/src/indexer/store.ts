import * as path from 'node:path';
import * as fs from 'node:fs/promises';
import { logger } from '../util/logger';

export interface IndexNodeEntry {
    symbol: string;
    node_type: string;
    embedding_id: number;
    line_start: number;
    line_end: number;
    content_hash: string;
}

export interface IndexFileEntry {
    file_hash: string;
    language: string;
    indexed_at: string;
    nodes: IndexNodeEntry[];
}

export interface IndexData {
    version: number;
    model: string;
    updated_at: string;
    files: Record<string, IndexFileEntry>;
}

const CURRENT_INDEX_VERSION = 2;

export class IndexStore {
    async load(workspaceRoot: string): Promise<IndexData> {
        const indexPath = path.join(workspaceRoot, '.cw', 'index.json');

        try {
            const raw = await fs.readFile(indexPath, 'utf-8');
            const data = JSON.parse(raw) as IndexData;

            if (data.version !== CURRENT_INDEX_VERSION) {
                logger.warn(`Index version mismatch: expected ${CURRENT_INDEX_VERSION}, got ${data.version}. Re-indexing.`);
                return this.createEmpty();
            }

            return data;
        } catch {
            return this.createEmpty();
        }
    }

    async save(workspaceRoot: string, data: IndexData): Promise<void> {
        const indexPath = path.join(workspaceRoot, '.cw', 'index.json');
        const tmpPath = indexPath + '.tmp';

        data.updated_at = new Date().toISOString();

        await fs.mkdir(path.join(workspaceRoot, '.cw'), { recursive: true });
        await fs.writeFile(tmpPath, JSON.stringify(data, null, 2), 'utf-8');
        await fs.rename(tmpPath, indexPath);
    }

    getAllEmbeddingIds(data: IndexData): Array<{ id: number; filePath: string; language: string }> {
        const results: Array<{ id: number; filePath: string; language: string }> = [];

        for (const [filePath, entry] of Object.entries(data.files)) {
            for (const node of entry.nodes) {
                if (node.embedding_id > 0) {
                    results.push({
                        id: node.embedding_id,
                        filePath,
                        language: entry.language,
                    });
                }
            }
        }

        return results;
    }

    findNodeByEmbeddingId(
        data: IndexData,
        id: number,
    ): { filePath: string; node: IndexNodeEntry } | null {
        for (const [filePath, entry] of Object.entries(data.files)) {
            for (const node of entry.nodes) {
                if (node.embedding_id === id) {
                    return { filePath, node };
                }
            }
        }

        return null;
    }

    private createEmpty(): IndexData {
        return {
            version: CURRENT_INDEX_VERSION,
            model: '',
            updated_at: new Date().toISOString(),
            files: {},
        };
    }
}
