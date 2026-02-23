import * as vscode from 'vscode';
import type { DiagnosticContext } from './types';

const MAX_DIAGNOSTICS = 3;

/**
 * Diagnostics Injector.
 *
 * Collects errors and warnings near the cursor for direct injection
 * into the prompt context. NOT a ranking signal — bypasses RRF.
 */
export function collectDiagnostics(
    document: vscode.TextDocument,
    position: vscode.Position,
    maxRange: number = 10,
): DiagnosticContext | null {
    const allDiagnostics = vscode.languages.getDiagnostics(document.uri);

    if (allDiagnostics.length === 0) {
        return null;
    }

    // Filter to errors/warnings within ±maxRange lines of cursor
    const nearby = allDiagnostics
        .filter(d => {
            if (d.severity !== vscode.DiagnosticSeverity.Error &&
                d.severity !== vscode.DiagnosticSeverity.Warning) {
                return false;
            }

            const diagLine = d.range.start.line;
            return Math.abs(diagLine - position.line) <= maxRange;
        })
        .sort((a, b) => {
            // Closest to cursor first
            const distA = Math.abs(a.range.start.line - position.line);
            const distB = Math.abs(b.range.start.line - position.line);
            return distA - distB;
        })
        .slice(0, MAX_DIAGNOSTICS);

    if (nearby.length === 0) {
        return null;
    }

    const lines = nearby.map(d => {
        const severity = d.severity === vscode.DiagnosticSeverity.Error ? 'Error' : 'Warning';
        const line = d.range.start.line + 1;
        const source = d.source ? ` (${d.source})` : '';
        const code = d.code
            ? typeof d.code === 'object' ? ` [${d.code.value}]` : ` [${d.code}]`
            : '';
        return `// ${severity} L${line}: ${d.message}${code}${source}`;
    });

    return {
        text: lines.join('\n'),
        count: nearby.length,
    };
}
