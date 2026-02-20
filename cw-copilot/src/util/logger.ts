import * as vscode from 'vscode';

class Logger {
    private channel: vscode.OutputChannel;

    constructor(name: string) {
        this.channel = vscode.window.createOutputChannel(name);
    }

    info(msg: string): void {
        this.log('INFO', msg);
    }

    warn(msg: string): void {
        this.log('WARN', msg);
    }

    error(msg: string): void {
        this.log('ERROR', msg);
    }

    private log(level: string, msg: string): void {
        const now = new Date();
        const time = now.toTimeString().slice(0, 8);
        this.channel.appendLine(`[${time}] [${level}] ${msg}`);
    }

    dispose(): void {
        this.channel.dispose();
    }
}

export const logger = new Logger('CW Copilot');
