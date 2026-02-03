export * from './auth';
export * from './models';

import type { Auth } from './auth';

export type AppPageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    name: string;
    auth: Auth;
    [key: string]: unknown;
};
