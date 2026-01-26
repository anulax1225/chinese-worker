<?php

namespace App\Services;

use App\Models\File;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileService
{
    /**
     * Upload a file and create a record.
     */
    public function upload(UploadedFile $file, string $type, int $userId): File
    {
        $path = $file->store("files/{$type}", config('filesystems.default'));

        return File::query()->create([
            'user_id' => $userId,
            'path' => $path,
            'type' => $type,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    /**
     * Download a file.
     */
    public function download(File $file): StreamedResponse
    {
        return Storage::download($file->path, basename($file->path));
    }

    /**
     * Delete a file and its record.
     */
    public function delete(File $file): bool
    {
        // Delete the physical file
        if (Storage::exists($file->path)) {
            Storage::delete($file->path);
        }

        // Delete the database record
        return $file->delete();
    }

    /**
     * Cleanup old files of a specific type.
     */
    public function cleanup(string $type, Carbon $before): int
    {
        $files = File::query()
            ->where('type', $type)
            ->where('created_at', '<', $before)
            ->get();

        $count = 0;

        foreach ($files as $file) {
            if ($this->delete($file)) {
                $count++;
            }
        }

        return $count;
    }
}
