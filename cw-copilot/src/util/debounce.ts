import * as vscode from 'vscode';

export function delay(ms: number, token: vscode.CancellationToken): Promise<void> {
    return new Promise<void>((resolve, reject) => {
        if (token.isCancellationRequested) {
            reject(new vscode.CancellationError());
            return;
        }

        const timer = setTimeout(resolve, ms);
        const listener = token.onCancellationRequested(() => {
            clearTimeout(timer);
            listener.dispose();
            reject(new vscode.CancellationError());
        });
    });
}
