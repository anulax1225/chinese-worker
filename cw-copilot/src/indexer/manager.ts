import * as vscode from 'vscode';
import * as path from 'node:path';
import type { CWApiClient } from '../api/client';
import type { ProjectConfig } from './config';
import { IndexStore, type IndexData, type IndexFileEntry, type IndexNodeEntry } from './store';
import { scanProject, shouldIndexLanguage } from './scanner';
import { getTopLevelNodes, getImportNode } from './symbols';
import { enrichNode } from './enricher';
import { hashContent, hashFile } from './hasher';
import { logger } from '../util/logger';
import type { LanguageConfig } from '../copilot/languages';

export class IndexManager {
    private index: IndexData | null = null;
    private indexing = false;

    constructor(
        private api: CWApiClient,
        private store: IndexStore,
        private langConfigs: Map<string, LanguageConfig>,
    ) {}

    async indexProject(
        workspaceRoot: string,
        config: ProjectConfig,
    ): Promise<void> {
        if (this.indexing) {
            logger.warn('IndexManager: already indexing, skipping');
            return;
        }

        this.indexing = true;
        logger.info('IndexManager: starting full project index');

        try {
            const embeddingConfig = await this.api.getEmbeddingConfig();
            if (!embeddingConfig?.enabled) {
                logger.warn('IndexManager: embedding service is not enabled, skipping');
                return;
            }

            const modelName = embeddingConfig.model ?? '';

            this.index = await this.store.load(workspaceRoot);

            if (this.index.model && this.index.model !== modelName) {
                logger.info(`IndexManager: model changed from "${this.index.model}" to "${modelName}", rebuilding index`);
                await this.deleteAllEmbeddings(this.index);
                this.index.files = {};
            }

            this.index.model = modelName;

            const filePaths = await scanProject(workspaceRoot, config);
            const indexedPaths = new Set(Object.keys(this.index.files));
            const scannedPaths = new Set(filePaths);

            // Remove files that no longer exist on disk
            for (const indexedPath of indexedPaths) {
                if (!scannedPaths.has(indexedPath)) {
                    await this.removeFileFromIndex(this.index, indexedPath);
                }
            }

            // Process each scanned file
            let processed = 0;
            let skipped = 0;

            for (const filePath of filePaths) {
                const absPath = path.join(workspaceRoot, filePath);

                try {
                    const fileHash = await hashFile(absPath);
                    const existing = this.index.files[filePath];

                    if (existing && existing.file_hash === fileHash) {
                        skipped++;
                        continue;
                    }

                    await this.processFile(workspaceRoot, filePath, fileHash, modelName);
                    processed++;
                } catch (err: unknown) {
                    logger.warn(`IndexManager: failed to process ${filePath}: ${err instanceof Error ? err.message : String(err)}`);
                }
            }

            await this.store.save(workspaceRoot, this.index);
            logger.info(`IndexManager: index complete. ${processed} processed, ${skipped} unchanged, ${Object.keys(this.index.files).length} total files`);
        } finally {
            this.indexing = false;
        }
    }

    async indexFile(
        workspaceRoot: string,
        filePath: string,
    ): Promise<void> {
        if (!this.index) {
            this.index = await this.store.load(workspaceRoot);
        }

        const absPath = path.join(workspaceRoot, filePath);

        try {
            const fileHash = await hashFile(absPath);
            const existing = this.index.files[filePath];

            if (existing && existing.file_hash === fileHash) {
                return;
            }

            const embeddingConfig = await this.api.getEmbeddingConfig();
            if (!embeddingConfig?.enabled) {
                return;
            }

            await this.processFile(workspaceRoot, filePath, fileHash, embeddingConfig.model ?? '');
            await this.store.save(workspaceRoot, this.index);

            logger.info(`IndexManager: incremental index of ${filePath} complete`);
        } catch (err: unknown) {
            logger.warn(`IndexManager: incremental index failed for ${filePath}: ${err instanceof Error ? err.message : String(err)}`);
        }
    }

    async removeFile(
        workspaceRoot: string,
        filePath: string,
    ): Promise<void> {
        if (!this.index) {
            this.index = await this.store.load(workspaceRoot);
        }

        if (this.index.files[filePath]) {
            await this.removeFileFromIndex(this.index, filePath);
            await this.store.save(workspaceRoot, this.index);
            logger.info(`IndexManager: removed ${filePath} from index`);
        }
    }

    getIndex(): IndexData | null {
        return this.index;
    }

    isIndexing(): boolean {
        return this.indexing;
    }

    private async processFile(
        workspaceRoot: string,
        filePath: string,
        fileHash: string,
        model: string,
    ): Promise<void> {
        const absPath = path.join(workspaceRoot, filePath);
        const uri = vscode.Uri.file(absPath);

        let document: vscode.TextDocument;
        try {
            document = await vscode.workspace.openTextDocument(uri);
        } catch {
            logger.warn(`IndexManager: could not open ${filePath}`);
            return;
        }

        if (!shouldIndexLanguage(document.languageId)) {
            return;
        }

        const langConfig = this.langConfigs.get(document.languageId);
        const commentPrefix = langConfig?.commentPrefix ?? '//';

        const nodes = await getTopLevelNodes(uri);
        const importNode = getImportNode(document, langConfig?.importPatterns ?? []);
        const allNodes = importNode ? [importNode, ...nodes] : nodes;

        if (allNodes.length === 0) {
            return;
        }

        const existing = this.index!.files[filePath];
        const existingNodeMap = new Map<string, IndexNodeEntry>();
        if (existing) {
            for (const node of existing.nodes) {
                existingNodeMap.set(`${node.node_type}:${node.symbol}`, node);
            }
        }

        const newNodes: IndexNodeEntry[] = [];

        for (const node of allNodes) {
            const enrichedText = await enrichNode(node, document, filePath, commentPrefix);
            const contentHash = hashContent(enrichedText);
            const key = `${node.node_type}:${node.symbol}`;

            const existingNode = existingNodeMap.get(key);

            if (existingNode && existingNode.content_hash === contentHash) {
                // Content unchanged — keep existing embedding
                newNodes.push({
                    ...existingNode,
                    line_start: node.line_start,
                    line_end: node.line_end,
                });
                existingNodeMap.delete(key);
                continue;
            }

            // Delete old embedding if it exists
            if (existingNode && existingNode.embedding_id > 0) {
                await this.api.deleteEmbedding(existingNode.embedding_id);
            }
            existingNodeMap.delete(key);

            // Create new embedding
            const embResult = await this.api.embedAsync(enrichedText, model || undefined);
            const embeddingId = embResult?.id ?? 0;

            newNodes.push({
                symbol: node.symbol,
                node_type: node.node_type,
                embedding_id: embeddingId,
                line_start: node.line_start,
                line_end: node.line_end,
                content_hash: contentHash,
            });
        }

        // Delete embeddings for nodes that no longer exist
        for (const [, oldNode] of existingNodeMap) {
            if (oldNode.embedding_id > 0) {
                await this.api.deleteEmbedding(oldNode.embedding_id);
            }
        }

        const entry: IndexFileEntry = {
            file_hash: fileHash,
            language: document.languageId,
            indexed_at: new Date().toISOString(),
            nodes: newNodes,
        };

        this.index!.files[filePath] = entry;
    }

    private async removeFileFromIndex(index: IndexData, filePath: string): Promise<void> {
        const entry = index.files[filePath];
        if (!entry) {
            return;
        }

        for (const node of entry.nodes) {
            if (node.embedding_id > 0) {
                await this.api.deleteEmbedding(node.embedding_id);
            }
        }

        delete index.files[filePath];
    }

    private async deleteAllEmbeddings(index: IndexData): Promise<void> {
        for (const entry of Object.values(index.files)) {
            for (const node of entry.nodes) {
                if (node.embedding_id > 0) {
                    await this.api.deleteEmbedding(node.embedding_id);
                }
            }
        }
    }
}
