import * as vscode from 'vscode';

export interface TrackedFile {
    uri: vscode.Uri;
    lastEditedAt: number;
    editCount: number;
}

const MAX_AGE_MS = 10 * 60 * 1000; // 10 minutes

export class EditTracker implements vscode.Disposable {
    private files: Map<string, TrackedFile> = new Map();
    private disposables: vscode.Disposable[] = [];

    activate(): void {
        this.disposables.push(
            vscode.workspace.onDidChangeTextDocument(e => {
                // Skip output channels, git diffs, etc.
                if (e.document.uri.scheme !== 'file') {
                    return;
                }

                const key = e.document.uri.toString();
                const existing = this.files.get(key);

                this.files.set(key, {
                    uri: e.document.uri,
                    lastEditedAt: Date.now(),
                    editCount: (existing?.editCount ?? 0) + 1,
                });
            }),
        );
    }

    getRecentlyEdited(maxAge: number): TrackedFile[] {
        const cutoff = Date.now() - maxAge;

        // Prune old entries
        for (const [key, file] of this.files) {
            if (file.lastEditedAt < Date.now() - MAX_AGE_MS) {
                this.files.delete(key);
            }
        }

        return [...this.files.values()]
            .filter(f => f.lastEditedAt >= cutoff)
            .sort((a, b) => b.lastEditedAt - a.lastEditedAt);
    }

    dispose(): void {
        this.disposables.forEach(d => d.dispose());
    }
}
