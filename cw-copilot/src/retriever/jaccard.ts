/**
 * Jaccard similarity scorer for code chunks.
 *
 * Extracts identifier tokens from code, then computes Jaccard similarity
 * (|intersection| / |union|) between two token sets. Fast, local, no model needed.
 */

export function tokenize(code: string): Set<string> {
    const tokens = new Set<string>();
    const identifiers = code.match(/[a-zA-Z_]\w{2,}/g);

    if (!identifiers) {
        return tokens;
    }

    for (const id of identifiers) {
        tokens.add(id.toLowerCase());

        // Split camelCase and snake_case into parts
        const parts = id.split(/(?=[A-Z])|_/).filter(p => p.length >= 2);
        for (const part of parts) {
            tokens.add(part.toLowerCase());
        }
    }

    return tokens;
}

export function jaccardSimilarity(queryTokens: Set<string>, chunkTokens: Set<string>): number {
    if (queryTokens.size === 0 || chunkTokens.size === 0) {
        return 0;
    }

    let intersection = 0;
    for (const token of queryTokens) {
        if (chunkTokens.has(token)) {
            intersection++;
        }
    }

    const union = queryTokens.size + chunkTokens.size - intersection;
    return union === 0 ? 0 : intersection / union;
}
