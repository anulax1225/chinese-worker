import { logger } from '../util/logger';

export interface GenerateParams {
    prompt: string;
    suffix?: string;
    raw?: boolean;
    max_tokens?: number;
    temperature?: number;
    stop?: string[];
    stream?: boolean;
    think?: boolean;
}

export interface GenerateResponse {
    content: string;
    model: string;
    done: boolean;
    tokens_used?: number;
}

export interface StoredEmbeddingResponse {
    id: number;
    text: string;
    embedding: number[] | null;
    model: string;
    dimensions: number | null;
    status: string;
    error: string | null;
    created_at: string;
    updated_at: string;
}

export interface CompareResponse {
    source: { id?: number; text?: string };
    results: Array<{
        target: { id?: number; text?: string };
        similarity: number;
        projected: boolean;
    }>;
    model: string;
}

export interface EmbeddingConfigResponse {
    enabled: boolean;
    model: string | null;
    backend: string | null;
    dimensions: number | null;
    batch_size: number | null;
    caching_enabled: boolean;
}

export class CWApiClient {
    constructor(
        private baseUrl: string,
        private token: string,
    ) {}

    async generate(
        agentId: number,
        params: GenerateParams,
        signal?: AbortSignal,
    ): Promise<GenerateResponse | null> {
        const url = `${this.baseUrl}/api/v1/agents/${agentId}/generate`;
        logger.info(`POST ${url}`);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.token}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(params),
                signal,
            });

            if (!response.ok) {
                const body = await response.text().catch(() => '');
                logger.warn(`API ${response.status} ${response.statusText}: ${body}`);
                return null;
            }

            const data = await response.json() as GenerateResponse;
            logger.info(`API 200: ${data.content.length} chars, model=${data.model}, tokens=${data.tokens_used ?? '?'}`);
            return data;
        } catch (err: unknown) {
            if (err instanceof Error && err.name === 'AbortError') {
                logger.info('API request aborted (new keystroke)');
                return null;
            }

            logger.error(`API request failed: ${err instanceof Error ? err.message : String(err)}`);
            return null;
        }
    }

    async embedAsync(
        text: string,
        model?: string,
        signal?: AbortSignal,
    ): Promise<StoredEmbeddingResponse | null> {
        const url = `${this.baseUrl}/api/v1/embeddings/async`;
        logger.info(`POST ${url}`);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.token}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ text, model: model ?? undefined }),
                signal,
            });

            if (!response.ok) {
                const body = await response.text().catch(() => '');
                logger.warn(`Embed async ${response.status}: ${body}`);
                return null;
            }

            const data = await response.json() as StoredEmbeddingResponse;
            logger.info(`Embed async: id=${data.id}, status=${data.status}`);
            return data;
        } catch (err: unknown) {
            if (err instanceof Error && err.name === 'AbortError') {
                return null;
            }

            logger.error(`Embed async failed: ${err instanceof Error ? err.message : String(err)}`);
            return null;
        }
    }

    async getEmbedding(
        embeddingId: number,
        signal?: AbortSignal,
    ): Promise<StoredEmbeddingResponse | null> {
        const url = `${this.baseUrl}/api/v1/embeddings/${embeddingId}`;

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${this.token}`,
                    'Accept': 'application/json',
                },
                signal,
            });

            if (!response.ok) {
                const body = await response.text().catch(() => '');
                logger.warn(`Get embedding ${embeddingId} ${response.status}: ${body}`);
                return null;
            }

            return await response.json() as StoredEmbeddingResponse;
        } catch (err: unknown) {
            if (err instanceof Error && err.name === 'AbortError') {
                return null;
            }

            logger.error(`Get embedding failed: ${err instanceof Error ? err.message : String(err)}`);
            return null;
        }
    }

    async deleteEmbedding(
        embeddingId: number,
        signal?: AbortSignal,
    ): Promise<boolean> {
        const url = `${this.baseUrl}/api/v1/embeddings/${embeddingId}`;
        logger.info(`DELETE ${url}`);

        try {
            const response = await fetch(url, {
                method: 'DELETE',
                headers: {
                    'Authorization': `Bearer ${this.token}`,
                    'Accept': 'application/json',
                },
                signal,
            });

            if (response.status === 204) {
                return true;
            }

            const body = await response.text().catch(() => '');
            logger.warn(`Delete embedding ${embeddingId} ${response.status}: ${body}`);
            return false;
        } catch (err: unknown) {
            if (err instanceof Error && err.name === 'AbortError') {
                return false;
            }

            logger.error(`Delete embedding failed: ${err instanceof Error ? err.message : String(err)}`);
            return false;
        }
    }

    async compareEmbeddings(
        source: { id?: number; text?: string },
        targets: Array<{ id?: number; text?: string }>,
        model?: string,
        signal?: AbortSignal,
    ): Promise<CompareResponse | null> {
        const url = `${this.baseUrl}/api/v1/embeddings/compare`;
        logger.info(`POST ${url} (${targets.length} targets)`);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.token}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ source, targets, model: model ?? undefined }),
                signal,
            });

            if (!response.ok) {
                const body = await response.text().catch(() => '');
                logger.warn(`Compare ${response.status}: ${body}`);
                return null;
            }

            const data = await response.json() as CompareResponse;
            logger.info(`Compare: ${data.results.length} result(s)`);
            return data;
        } catch (err: unknown) {
            if (err instanceof Error && err.name === 'AbortError') {
                return null;
            }

            logger.error(`Compare failed: ${err instanceof Error ? err.message : String(err)}`);
            return null;
        }
    }

    async getEmbeddingConfig(
        signal?: AbortSignal,
    ): Promise<EmbeddingConfigResponse | null> {
        const url = `${this.baseUrl}/api/v1/embeddings/config`;

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${this.token}`,
                    'Accept': 'application/json',
                },
                signal,
            });

            if (!response.ok) {
                const body = await response.text().catch(() => '');
                logger.warn(`Embedding config ${response.status}: ${body}`);
                return null;
            }

            return await response.json() as EmbeddingConfigResponse;
        } catch (err: unknown) {
            if (err instanceof Error && err.name === 'AbortError') {
                return null;
            }

            logger.error(`Embedding config failed: ${err instanceof Error ? err.message : String(err)}`);
            return null;
        }
    }
}
