/**
 * Files Module
 * CRUD operations for files with upload/download support
 */

import { getClient, type HttpClient } from './client';
import type {
    File,
    FileFilters,
    FileType,
    PaginatedResponse,
} from './types';

/**
 * Files API
 */
export class FilesApi {
    constructor(private client: HttpClient = getClient()) {}

    /**
     * List all files with optional filtering and pagination
     */
    async list(filters?: FileFilters): Promise<PaginatedResponse<File>> {
        return this.client.get<PaginatedResponse<File>>('/files', filters as Record<string, unknown>);
    }

    /**
     * Get all files without pagination
     */
    async all(type?: FileType): Promise<File[]> {
        const files: File[] = [];
        let page = 1;
        let hasMore = true;

        while (hasMore) {
            const response = await this.list({ page, per_page: 100, type });
            files.push(...response.data);
            hasMore = response.meta.current_page < response.meta.last_page;
            page++;
        }

        return files;
    }

    /**
     * Upload a file
     */
    async upload(file: Blob | globalThis.File, type: FileType): Promise<File> {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', type);

        return this.client.upload<File>('/files', formData);
    }

    /**
     * Get a single file by ID
     */
    async get(id: number): Promise<File> {
        return this.client.get<File>(`/files/${id}`);
    }

    /**
     * Download a file
     */
    async download(id: number): Promise<Blob> {
        return this.client.download(`/files/${id}/download`);
    }

    /**
     * Download a file and trigger browser download
     */
    async downloadAndSave(id: number, filename?: string): Promise<void> {
        const blob = await this.download(id);
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename || `file-${id}`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * Delete a file
     */
    async delete(id: number): Promise<void> {
        return this.client.delete(`/files/${id}`);
    }
}

// ============================================================================
// Standalone Functions
// ============================================================================

const defaultFiles = new FilesApi();

/**
 * List all files with optional filtering and pagination
 */
export async function listFiles(filters?: FileFilters): Promise<PaginatedResponse<File>> {
    return defaultFiles.list(filters);
}

/**
 * Get all files without pagination
 */
export async function getAllFiles(type?: FileType): Promise<File[]> {
    return defaultFiles.all(type);
}

/**
 * Upload a file
 */
export async function uploadFile(file: Blob | globalThis.File, type: FileType): Promise<File> {
    return defaultFiles.upload(file, type);
}

/**
 * Get a single file by ID
 */
export async function getFile(id: number): Promise<File> {
    return defaultFiles.get(id);
}

/**
 * Download a file as Blob
 */
export async function downloadFile(id: number): Promise<Blob> {
    return defaultFiles.download(id);
}

/**
 * Download a file and trigger browser download
 */
export async function downloadAndSaveFile(id: number, filename?: string): Promise<void> {
    return defaultFiles.downloadAndSave(id, filename);
}

/**
 * Delete a file
 */
export async function deleteFile(id: number): Promise<void> {
    return defaultFiles.delete(id);
}
