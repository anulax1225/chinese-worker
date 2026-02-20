import { logger } from '../util/logger';

export interface GenerateParams {
    prompt: string;
    suffix?: string;
    max_tokens?: number;
    temperature?: number;
    stop?: string[];
    stream?: boolean;
}

export interface GenerateResponse {
    content: string;
    model: string;
    done: boolean;
    tokens_used?: number;
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
                logger.warn(`API returned ${response.status}: ${response.statusText}`);
                return null;
            }

            const data = await response.json() as GenerateResponse;
            return data;
        } catch (err: unknown) {
            if (err instanceof Error && err.name === 'AbortError') {
                return null;
            }

            logger.error(`API request failed: ${err instanceof Error ? err.message : String(err)}`);
            return null;
        }
    }
}
