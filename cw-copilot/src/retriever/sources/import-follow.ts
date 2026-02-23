import * as vscode from 'vscode';
import * as fs from 'node:fs/promises';
import * as path from 'node:path';
import type { RetrievalCandidate } from './types';
import type { LanguageConfig } from '../../copilot/languages';
import { logger } from '../../util/logger';

const MAX_IMPORTED_FILES = 5;
const MAX_SKELETON_LINES = 50;

/**
 * Import Follow source.
 *
 * Parses import/require statements in the current file, resolves them
 * to workspace files, and extracts exported signatures.
 */
export async function collectImportCandidates(
    document: vscode.TextDocument,
    workspaceRoot: string,
    langConfig: LanguageConfig | null,
): Promise<RetrievalCandidate[]> {
    const candidates: RetrievalCandidate[] = [];
    const currentDir = path.dirname(document.uri.fsPath);
    const text = document.getText();

    // Extract import paths
    const importPaths = extractImportPaths(text, langConfig);

    if (importPaths.length === 0) {
        return [];
    }

    // Identify identifiers near cursor for ranking (not available here, use all)
    let resolved = 0;

    for (const importPath of importPaths) {
        if (resolved >= MAX_IMPORTED_FILES) {
            break;
        }

        // Skip external packages
        if (isExternalPackage(importPath, document.languageId)) {
            continue;
        }

        // Resolve to a file
        const resolvedPath = await resolveImportPath(importPath, currentDir, workspaceRoot);
        if (!resolvedPath) {
            continue;
        }

        const relPath = path.relative(workspaceRoot, resolvedPath);
        if (relPath.startsWith('..') || path.isAbsolute(relPath)) {
            continue;
        }

        let content: string;
        try {
            content = await fs.readFile(resolvedPath, 'utf-8');
        } catch {
            continue;
        }

        // Build a skeleton (signatures only, no bodies)
        const skeleton = buildSkeleton(content);
        if (!skeleton || skeleton.trim().length === 0) {
            continue;
        }

        const lineCount = skeleton.split('\n').length;

        candidates.push({
            filePath: relPath,
            symbol: path.basename(relPath, path.extname(relPath)),
            code: skeleton,
            lineStart: 0,
            lineEnd: lineCount - 1,
            lineCount,
            source: 'import',
        });

        resolved++;
    }

    logger.info(`Import source: ${candidates.length} candidate(s) from ${importPaths.length} import(s)`);
    return candidates;
}

function extractImportPaths(text: string, langConfig: LanguageConfig | null): string[] {
    const paths: string[] = [];
    const seen = new Set<string>();

    // Use language-specific patterns if available
    const patterns = langConfig?.importPatterns ?? [];

    // Fallback: universal import patterns
    const universalPatterns = [
        /import\s+.*?from\s+['"](.+?)['"]/g,
        /import\s*\(\s*['"](.+?)['"]\s*\)/g,
        /require\s*\(\s*['"](.+?)['"]\s*\)/g,
        /from\s+(\S+)\s+import/g,
    ];

    // Try regex patterns from langConfig (line-by-line matching patterns)
    // These are line-match patterns (^import\s), not capture patterns.
    // We use universal patterns for actual path extraction.

    for (const pattern of universalPatterns) {
        const regex = new RegExp(pattern.source, pattern.flags);
        let match;
        while ((match = regex.exec(text)) !== null) {
            const importPath = match[1];
            if (importPath && !seen.has(importPath)) {
                seen.add(importPath);
                paths.push(importPath);
            }
        }
    }

    // For Python-style imports: `from foo.bar import baz` → `foo.bar`
    if (!langConfig || langConfig.id === 'python') {
        const pyFrom = /^from\s+(\S+)\s+import/gm;
        let match;
        while ((match = pyFrom.exec(text)) !== null) {
            const importPath = match[1];
            if (importPath && !seen.has(importPath)) {
                seen.add(importPath);
                paths.push(importPath);
            }
        }
    }

    return paths;
}

function isExternalPackage(importPath: string, languageId: string): boolean {
    // Relative paths are local
    if (importPath.startsWith('.') || importPath.startsWith('/')) {
        return false;
    }

    // Alias paths (e.g. @/services, ~/utils) are local
    if (importPath.startsWith('@/') || importPath.startsWith('~/')) {
        return false;
    }

    // Python dot-notation: could be local or external
    if (languageId === 'python' && importPath.includes('.')) {
        return false; // Assume local, resolve will fail gracefully for external
    }

    // Everything else is external
    return true;
}

async function resolveImportPath(
    importPath: string,
    currentDir: string,
    workspaceRoot: string,
): Promise<string | null> {
    // Handle alias paths
    let normalizedPath = importPath;
    if (importPath.startsWith('@/') || importPath.startsWith('~/')) {
        normalizedPath = importPath.slice(2);
        currentDir = workspaceRoot;

        // Check for tsconfig paths (common: @/ → src/)
        const srcPath = path.join(workspaceRoot, 'src', normalizedPath);
        const resolved = await tryResolveFile(srcPath);
        if (resolved) {
            return resolved;
        }

        // Fall through to resolve from workspace root
    }

    // Python dot notation → path
    if (normalizedPath.includes('.') && !normalizedPath.includes('/')) {
        normalizedPath = normalizedPath.replace(/\./g, '/');
    }

    // Resolve relative to current dir or workspace root
    const base = normalizedPath.startsWith('.')
        ? path.resolve(currentDir, normalizedPath)
        : path.resolve(currentDir, normalizedPath);

    return tryResolveFile(base);
}

async function tryResolveFile(basePath: string): Promise<string | null> {
    // Try exact path first
    if (await fileExists(basePath)) {
        return basePath;
    }

    // Try common extensions
    const extensions = ['.ts', '.tsx', '.js', '.jsx', '.py', '.go', '.rs', '.php'];
    for (const ext of extensions) {
        const withExt = basePath + ext;
        if (await fileExists(withExt)) {
            return withExt;
        }
    }

    // Try index files
    for (const ext of ['.ts', '.js', '.tsx', '.jsx']) {
        const indexPath = path.join(basePath, `index${ext}`);
        if (await fileExists(indexPath)) {
            return indexPath;
        }
    }

    return null;
}

async function fileExists(filePath: string): Promise<boolean> {
    try {
        const stat = await fs.stat(filePath);
        return stat.isFile();
    } catch {
        return false;
    }
}

/**
 * Build a skeleton of the file: signatures, type declarations, exported names.
 * No function bodies.
 */
function buildSkeleton(content: string): string {
    const lines = content.split('\n');
    const skeleton: string[] = [];
    let depth = 0;
    let insideBody = false;
    let lineCount = 0;

    for (const line of lines) {
        if (lineCount >= MAX_SKELETON_LINES) {
            break;
        }

        const trimmed = line.trim();

        // Count braces
        const opens = (trimmed.match(/\{/g) || []).length;
        const closes = (trimmed.match(/\}/g) || []).length;

        // At top level or entering a new block
        if (depth === 0) {
            // Skip empty lines
            if (trimmed === '') {
                continue;
            }

            // Include imports, type declarations, and signatures
            skeleton.push(line);
            lineCount++;

            // If this line opens a function/method body, track it
            if (opens > closes) {
                const isClassOrInterface = /^(?:export\s+)?(?:abstract\s+)?(?:class|interface|type|enum|struct)\s/.test(trimmed);
                if (!isClassOrInterface) {
                    insideBody = true;
                }
            }

            depth += opens - closes;
        } else if (depth === 1 && !insideBody) {
            // Inside a class/interface — include member signatures
            if (trimmed !== '' && trimmed !== '}') {
                // Include the line but skip function bodies inside classes
                const lineOpens = (trimmed.match(/\{/g) || []).length;
                if (lineOpens > 0 && !trimmed.endsWith('{')) {
                    skeleton.push(line);
                    lineCount++;
                } else if (lineOpens === 0) {
                    skeleton.push(line);
                    lineCount++;
                } else {
                    // Signature line that opens a body — include signature, skip body
                    skeleton.push(line.replace(/\{.*$/, '{ ... }'));
                    lineCount++;
                }
            }
            depth += opens - closes;

            if (depth === 0) {
                skeleton.push('}');
                lineCount++;
            }
        } else {
            // Inside a body — skip
            depth += opens - closes;

            if (depth <= 0) {
                if (!insideBody) {
                    skeleton.push('}');
                    lineCount++;
                }
                depth = 0;
                insideBody = false;
            }
        }
    }

    return skeleton.join('\n');
}
