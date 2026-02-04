export * from './auth';
export * from './models';

import type { Auth } from './auth';

export interface Flash {
    success: string | null;
    error: string | null;
    token: string | null;
}

export interface BreadcrumbItem {
    label: string;
    href?: string;
}

export interface RecentConversation {
    id: number;
    title: string;
    agent_name: string | null;
    status: string;
}

export interface SharedAgent {
    id: number;
    name: string;
    description: string | null;
}

export type AppPageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    name: string;
    auth: Auth;
    flash: Flash;
    breadcrumbs?: BreadcrumbItem[];
    sidebarConversations: RecentConversation[];
    agents: SharedAgent[];
    [key: string]: unknown;
};
