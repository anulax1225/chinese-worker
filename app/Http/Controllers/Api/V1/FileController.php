<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadFileRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group File Management
 *
 * APIs for managing files used in agent executions.
 *
 * @authenticated
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
     * @apiResourceCollection App\Http\Resources\FileResource
     *
     * @apiResourceModel App\Models\File paginate=15
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->files();

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $files = $query->paginate($request->input('per_page', 15));

        return FileResource::collection($files);
    }

    /**
     * Upload File
     *
     * Upload a new file.
     *
     * @bodyParam file file required The file to upload. Max 10MB.
     * @bodyParam type string required The file type. Must be one of: input, output, temp. Example: input
     *
     * @apiResource App\Http\Resources\FileResource
     *
     * @apiResourceModel App\Models\File
     *
     * @apiResourceAdditional status=201
     *
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"file": ["The file field is required."]}}
     */
    public function store(UploadFileRequest $request): JsonResponse
    {
        $file = $this->fileService->upload(
            $request->file('file'),
            $request->input('type'),
            $request->user()->id
        );

        return (new FileResource($file))->response()->setStatusCode(201);
    }

    /**
     * Show File
     *
     * Get details of a specific file.
     *
     * @urlParam file integer required The file ID. Example: 1
     *
     * @apiResource App\Http\Resources\FileResource
     *
     * @apiResourceModel App\Models\File
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\File] 1"}
     */
    public function show(File $file): JsonResponse
    {
        $this->authorize('view', $file);

        return (new FileResource($file))->response();
    }

    /**
     * Download File
     *
     * Download a file.
     *
     * @urlParam file integer required The file ID. Example: 1
     *
     * @response 200 scenario="Success" file Binary file content
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\File] 1"}
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
     * @response 204 scenario="Success"
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\File] 1"}
     */
    public function destroy(File $file): JsonResponse
    {
        $this->authorize('delete', $file);

        $this->fileService->delete($file);

        return response()->json(null, 204);
    }
}
