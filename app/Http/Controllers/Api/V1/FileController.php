<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadFileRequest;
use App\Models\File;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group File Management
 *
 * APIs for managing files used in agent executions
 */
class FileController extends Controller
{
    public function __construct(protected FileService $fileService) {}

    /**
     * List Files
     *
     * Get a paginated list of all files for the authenticated user.
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     * @queryParam type string Filter by file type. Example: input
     *
     * @response 200 {"data": [{"id": 1, "user_id": 1, "path": "files/input/document.pdf", "type": "input", "size": 1024, "mime_type": "application/pdf", "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->files();

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $files = $query->paginate($request->input('per_page', 15));

        return response()->json($files);
    }

    /**
     * Upload File
     *
     * Upload a new file.
     *
     * @bodyParam file file required The file to upload. Max 10MB.
     * @bodyParam type string required The file type. Must be one of: input, output, temp. Example: input
     *
     * @response 201 {"id": 1, "user_id": 1, "path": "files/input/document.pdf", "type": "input", "size": 1024, "mime_type": "application/pdf", "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z"}
     */
    public function store(UploadFileRequest $request): JsonResponse
    {
        $file = $this->fileService->upload(
            $request->file('file'),
            $request->input('type'),
            $request->user()->id
        );

        return response()->json($file, 201);
    }

    /**
     * Show File
     *
     * Get details of a specific file.
     *
     * @urlParam file integer required The file ID. Example: 1
     *
     * @response 200 {"id": 1, "user_id": 1, "path": "files/input/document.pdf", "type": "input", "size": 1024, "mime_type": "application/pdf", "created_at": "2026-01-26T10:00:00.000000Z", "updated_at": "2026-01-26T10:00:00.000000Z"}
     */
    public function show(File $file): JsonResponse
    {
        $this->authorize('view', $file);

        return response()->json($file);
    }

    /**
     * Download File
     *
     * Download a file.
     *
     * @urlParam file integer required The file ID. Example: 1
     *
     * @response 200 (binary file content)
     */
    public function download(File $file): StreamedResponse
    {
        $this->authorize('view', $file);

        return $this->fileService->download($file);
    }

    /**
     * Delete File
     *
     * Delete a file permanently.
     *
     * @urlParam file integer required The file ID. Example: 1
     *
     * @response 204
     */
    public function destroy(File $file): JsonResponse
    {
        $this->authorize('delete', $file);

        $this->fileService->delete($file);

        return response()->json(null, 204);
    }
}
