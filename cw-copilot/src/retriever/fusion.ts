import type { RetrievalCandidate } from './sources/types';

export interface RankedList {
    source: string;
    weight: number;
    candidates: RetrievalCandidate[];
}

export interface FusedCandidate {
    candidate: RetrievalCandidate;
    rrfScore: number;
    sources: string[];
}

/**
 * Reciprocal Rank Fusion (RRF).
 *
 * Merges multiple ranked lists into a single ranking using:
 *   score(doc) = Σ weight_r / (k + rank_r(doc))
 *
 * Works purely on rank positions — doesn't care about score scales.
 */
export function reciprocalRankFusion(
    lists: RankedList[],
    k: number = 60,
): FusedCandidate[] {
    const map = new Map<string, FusedCandidate>();

    for (const list of lists) {
        for (let i = 0; i < list.candidates.length; i++) {
            const candidate = list.candidates[i];
            const key = candidateKey(candidate);
            const rank = i + 1; // 1-based

            const existing = map.get(key);
            if (existing) {
                existing.rrfScore += list.weight / (k + rank);
                existing.sources.push(list.source);
            } else {
                map.set(key, {
                    candidate,
                    rrfScore: list.weight / (k + rank),
                    sources: [list.source],
                });
            }
        }
    }

    return [...map.values()].sort((a, b) => b.rrfScore - a.rrfScore);
}

function candidateKey(c: RetrievalCandidate): string {
    return `${c.filePath}:${c.lineStart}:${c.lineEnd}`;
}

/**
 * Deduplicate candidates with overlapping file+line ranges.
 * Keeps the candidate with the higher RRF score.
 */
export function deduplicateFused(fused: FusedCandidate[]): FusedCandidate[] {
    const result: FusedCandidate[] = [];

    for (const item of fused) {
        const overlaps = result.some(existing =>
            existing.candidate.filePath === item.candidate.filePath &&
            rangesOverlap(
                existing.candidate.lineStart, existing.candidate.lineEnd,
                item.candidate.lineStart, item.candidate.lineEnd,
            ),
        );

        if (!overlaps) {
            result.push(item);
        }
    }

    return result;
}

function rangesOverlap(
    startA: number, endA: number,
    startB: number, endB: number,
): boolean {
    return startA <= endB && startB <= endA;
}
