import * as vscode from 'vscode';
import * as fs from 'node:fs/promises';
import * as path from 'node:path';
import type { RetrievalCandidate } from './types';
import { logger } from '../../util/logger';

/**
 * LSP Targeted Retrieval.
 *
 * Uses VS Code's built-in language intelligence to find code that is
 * structurally connected to the cursor position. Deterministic retrieval.
 */
export async function collectLSPCandidates(
    document: vscode.TextDocument,
    position: vscode.Position,
    workspaceRoot: string,
    timeoutMs: number = 100,
): Promise<RetrievalCandidate[]> {
    const candidates: RetrievalCandidate[] = [];
    const currentFile = document.uri.fsPath;
    const seen = new Set<string>();

    // All LSP calls share a single timeout budget
    const deadline = Date.now() + timeoutMs;

    function remainingMs(): number {
        return Math.max(0, deadline - Date.now());
    }

    // 1. Focus word — the identifier at or just before the cursor
    const wordRange = document.getWordRangeAtPosition(position);
    const focusWord = wordRange ? document.getText(wordRange) : null;

    if (focusWord && remainingMs() > 0) {
        // Go-to-definition
        const definitions = await withTimeout(
            Promise.resolve(vscode.commands.executeCommand<vscode.Location[]>(
                'vscode.executeDefinitionProvider',
                document.uri,
                position,
            )),
            remainingMs(),
        );

        if (definitions?.length) {
            for (const def of definitions) {
                if (def.uri.fsPath === currentFile) {
                    continue;
                }

                const candidate = await locationToCandidate(def, workspaceRoot, `def:${focusWord}`);
                if (candidate && !seen.has(candidateKey(candidate))) {
                    seen.add(candidateKey(candidate));
                    candidates.push(candidate);
                }
            }
        }

        // Go-to-type-definition
        if (remainingMs() > 0) {
            const typeDefs = await withTimeout(
                Promise.resolve(vscode.commands.executeCommand<vscode.Location[]>(
                    'vscode.executeTypeDefinitionProvider',
                    document.uri,
                    position,
                )),
                remainingMs(),
            );

            if (typeDefs?.length) {
                for (const td of typeDefs) {
                    if (td.uri.fsPath === currentFile) {
                        continue;
                    }

                    const candidate = await locationToCandidate(td, workspaceRoot, `type:${focusWord}`);
                    if (candidate && !seen.has(candidateKey(candidate))) {
                        seen.add(candidateKey(candidate));
                        candidates.push(candidate);
                    }
                }
            }
        }

        // References (max 2 from other files)
        if (remainingMs() > 0) {
            const refs = await withTimeout(
                Promise.resolve(vscode.commands.executeCommand<vscode.Location[]>(
                    'vscode.executeReferenceProvider',
                    document.uri,
                    position,
                )),
                remainingMs(),
            );

            if (refs?.length) {
                let refCount = 0;
                for (const ref of refs) {
                    if (refCount >= 2) {
                        break;
                    }
                    if (ref.uri.fsPath === currentFile) {
                        continue;
                    }

                    const candidate = await locationToCandidate(ref, workspaceRoot, `ref:${focusWord}`);
                    if (candidate && !seen.has(candidateKey(candidate))) {
                        seen.add(candidateKey(candidate));
                        candidates.push(candidate);
                        refCount++;
                    }
                }
            }
        }
    }

    // 2. Enclosing function: outgoing/incoming calls
    if (remainingMs() > 0) {
        const symbols = await withTimeout(
            Promise.resolve(vscode.commands.executeCommand<vscode.DocumentSymbol[]>(
                'vscode.executeDocumentSymbolProvider',
                document.uri,
            )),
            remainingMs(),
        );

        const enclosingFn = symbols ? findEnclosingFunction(symbols, position) : null;

        if (enclosingFn && remainingMs() > 0) {
            const midPoint = new vscode.Position(
                Math.floor((enclosingFn.range.start.line + enclosingFn.range.end.line) / 2),
                0,
            );

            const callItems = await withTimeout(
                Promise.resolve(vscode.commands.executeCommand<vscode.CallHierarchyItem[]>(
                    'vscode.prepareCallHierarchy',
                    document.uri,
                    midPoint,
                )),
                remainingMs(),
            );

            if (callItems?.length && remainingMs() > 0) {
                // Outgoing calls (max 3)
                const outgoing = await withTimeout(
                    Promise.resolve(vscode.commands.executeCommand<vscode.CallHierarchyOutgoingCall[]>(
                        'vscode.provideOutgoingCalls',
                        callItems[0],
                    )),
                    remainingMs(),
                );

                if (outgoing?.length) {
                    let outCount = 0;
                    for (const call of outgoing) {
                        if (outCount >= 3) {
                            break;
                        }
                        if (call.to.uri.fsPath === currentFile) {
                            continue;
                        }

                        const loc = new vscode.Location(call.to.uri, call.to.range);
                        const candidate = await locationToCandidate(loc, workspaceRoot, `calls:${call.to.name}`);
                        if (candidate && !seen.has(candidateKey(candidate))) {
                            seen.add(candidateKey(candidate));
                            candidates.push(candidate);
                            outCount++;
                        }
                    }
                }

                // Incoming calls (max 2)
                if (remainingMs() > 0) {
                    const incoming = await withTimeout(
                        Promise.resolve(vscode.commands.executeCommand<vscode.CallHierarchyIncomingCall[]>(
                            'vscode.provideIncomingCalls',
                            callItems[0],
                        )),
                        remainingMs(),
                    );

                    if (incoming?.length) {
                        let inCount = 0;
                        for (const call of incoming) {
                            if (inCount >= 2) {
                                break;
                            }
                            if (call.from.uri.fsPath === currentFile) {
                                continue;
                            }

                            const loc = new vscode.Location(call.from.uri, call.from.range);
                            const candidate = await locationToCandidate(loc, workspaceRoot, `calledBy:${call.from.name}`);
                            if (candidate && !seen.has(candidateKey(candidate))) {
                                seen.add(candidateKey(candidate));
                                candidates.push(candidate);
                                inCount++;
                            }
                        }
                    }
                }
            }
        }
    }

    logger.info(`LSP source: ${candidates.length} candidate(s) in ${timeoutMs - remainingMs()}ms`);
    return candidates;
}

function candidateKey(c: RetrievalCandidate): string {
    return `${c.filePath}:${c.lineStart}:${c.lineEnd}`;
}

function findEnclosingFunction(
    symbols: vscode.DocumentSymbol[],
    position: vscode.Position,
): vscode.DocumentSymbol | null {
    const functionKinds = new Set([
        vscode.SymbolKind.Function,
        vscode.SymbolKind.Method,
        vscode.SymbolKind.Constructor,
    ]);

    for (const symbol of symbols) {
        if (!symbol.range.contains(position)) {
            continue;
        }

        // Check children first for tighter match
        const childMatch = findEnclosingFunction(symbol.children || [], position);
        if (childMatch) {
            return childMatch;
        }

        if (functionKinds.has(symbol.kind)) {
            return symbol;
        }
    }

    return null;
}

async function locationToCandidate(
    location: vscode.Location,
    workspaceRoot: string,
    symbol: string,
): Promise<RetrievalCandidate | null> {
    try {
        const filePath = path.relative(workspaceRoot, location.uri.fsPath);
        if (filePath.startsWith('..') || path.isAbsolute(filePath)) {
            return null;
        }

        const content = await fs.readFile(location.uri.fsPath, 'utf-8');
        const lines = content.split('\n');

        // Expand the range to capture meaningful context (up to 30 lines)
        const lineStart = location.range.start.line;
        let lineEnd = Math.min(location.range.end.line, lineStart + 29);

        // If the range is too small (single line), try to expand to the full block
        if (lineEnd - lineStart < 3) {
            lineEnd = expandToBlock(lines, lineStart, 30);
        }

        const code = lines.slice(lineStart, lineEnd + 1).join('\n');
        const lineCount = lineEnd - lineStart + 1;

        if (code.trim().length === 0) {
            return null;
        }

        return {
            filePath,
            symbol,
            code,
            lineStart,
            lineEnd,
            lineCount,
            source: 'lsp',
        };
    } catch {
        return null;
    }
}

/**
 * Expand from a starting line to include the full block (function, class, etc.)
 * by tracking brace depth or indentation.
 */
function expandToBlock(lines: string[], start: number, maxLines: number): number {
    let depth = 0;
    let foundOpen = false;
    const end = Math.min(lines.length - 1, start + maxLines - 1);

    for (let i = start; i <= end; i++) {
        const line = lines[i];
        for (const ch of line) {
            if (ch === '{' || ch === '(') {
                depth++;
                foundOpen = true;
            } else if (ch === '}' || ch === ')') {
                depth--;
                if (foundOpen && depth <= 0) {
                    return i;
                }
            }
        }
    }

    return end;
}

async function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T | null> {
    if (ms <= 0) {
        return null;
    }
    const timer = new Promise<null>(resolve => setTimeout(() => resolve(null), ms));
    return Promise.race([promise, timer]);
}
