import { createHash } from 'node:crypto';
import * as fs from 'node:fs/promises';

export function hashContent(text: string): string {
    return createHash('sha256').update(text).digest('hex').slice(0, 8);
}

export async function hashFile(filePath: string): Promise<string> {
    const content = await fs.readFile(filePath, 'utf-8');
    return hashContent(content);
}
