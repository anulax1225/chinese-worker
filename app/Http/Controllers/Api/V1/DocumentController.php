<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\DocumentChunkResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentStageResource;
use App\Models\Document;
use App\Models\User;
use App\Services\Document\DocumentIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Document Management
 *
 * APIs for managing documents in the ingestion pipeline.
 *
 * @authenticated
 */
class DocumentController extends Controller
{
    public function __construct(protected DocumentIngestionService $ingestionService) {}

    /**
     * List Documents
     *
     * Get a paginated list of all documents for the authenticated user.
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     * @queryParam status string Filter by document status. Example: ready
     * @queryParam search string Search documents by title. Example: report
     *
     * @apiResourceCollection App\Http\Resources\DocumentResource
     *
     * @apiResourceModel App\Models\Document paginate=15
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->documents()->withCount('chunks');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->input('search').'%');
        }

        $documents = $query->latest()->paginate($request->input('per_page', 15));

        return DocumentResource::collection($documents);
    }

    /**
     * Store Document
     *
     * Ingest a new document from file upload, URL, or pasted text.
     *
     * @bodyParam source_type string required The source type (upload, url, paste). Example: upload
     * @bodyParam title string optional Custom title for the document. Example: My Report
     * @bodyParam file file required_if:source_type=upload The file to upload.
     * @bodyParam url string required_if:source_type=url The URL to fetch. Example: https://example.com/doc.pdf
     * @bodyParam text string required_if:source_type=paste The text content.
     *
     * @apiResource App\Http\Resources\DocumentResource
     *
     * @apiResourceModel App\Models\Document
     *
     * @apiResourceAdditional status=201
     *
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"source_type": ["The source type field is required."]}}
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $sourceType = DocumentSourceType::from($request->input('source_type'));
        $title = $request->input('title');

        $document = match ($sourceType) {
            DocumentSourceType::Upload => $this->ingestFromUpload($request, $user, $title),
            DocumentSourceType::Url => $this->ingestionService->ingestFromUrl(
                $request->input('url'),
                $user,
                $title
            ),
            DocumentSourceType::Paste => $this->ingestionService->ingestFromText(
                $request->input('text'),
                $user,
                $title
            ),
        };

        return (new DocumentResource($document->loadCount('chunks')))->response()->setStatusCode(201);
    }

    /**
     * Show Document
     *
     * Get details of a specific document.
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @apiResource App\Http\Resources\DocumentResource
     *
     * @apiResourceModel App\Models\Document with=file
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Document] 1"}
     */
    public function show(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        return (new DocumentResource($document->loadCount('chunks')->load('file')))->response();
    }

    /**
     * Get Document Stages
     *
     * Get all processing stages for a document.
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @queryParam full_content boolean Return full content instead of preview. Example: false
     *
     * @apiResourceCollection App\Http\Resources\DocumentStageResource
     *
     * @apiResourceModel App\Models\DocumentStage
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Document] 1"}
     */
    public function stages(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $stages = $document->stages()->orderBy('created_at')->get();

        return DocumentStageResource::collection($stages);
    }

    /**
     * Get Document Chunks
     *
     * Get all chunks for a document.
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @queryParam page integer Page number for pagination. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 50
     *
     * @apiResourceCollection App\Http\Resources\DocumentChunkResource
     *
     * @apiResourceModel App\Models\DocumentChunk paginate=50
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Document] 1"}
     */
    public function chunks(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $chunks = $document->chunks()
            ->orderBy('chunk_index')
            ->paginate($request->input('per_page', 50));

        return DocumentChunkResource::collection($chunks);
    }

    /**
     * Get Document Preview
     *
     * Get a preview comparison of document processing stages.
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @response 200 {"document": {}, "original_preview": "...", "cleaned_preview": "...", "sample_chunks": [], "total_chunks": 10, "total_tokens": 1500}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Document] 1"}
     */
    public function preview(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $extractedStage = $document->getLatestStage(DocumentStageType::Extracted);
        $cleanedStage = $document->getLatestStage(DocumentStageType::Cleaned);
        $sampleChunks = $document->chunks()->orderBy('chunk_index')->limit(3)->get();

        return response()->json([
            'document' => new DocumentResource($document->loadCount('chunks')),
            'original_preview' => $extractedStage?->getPreview(5000),
            'cleaned_preview' => $cleanedStage?->getPreview(5000),
            'sample_chunks' => DocumentChunkResource::collection($sampleChunks),
            'total_chunks' => $document->chunks()->count(),
            'total_tokens' => $document->chunks()->sum('token_count'),
        ]);
    }

    /**
     * Reprocess Document
     *
     * Re-run the processing pipeline on an existing document.
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @apiResource App\Http\Resources\DocumentResource
     *
     * @apiResourceModel App\Models\Document
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Document] 1"}
     */
    public function reprocess(Document $document): JsonResponse
    {
        $this->authorize('reprocess', $document);

        $this->ingestionService->reprocess($document);

        return (new DocumentResource($document->fresh()->loadCount('chunks')))->response();
    }

    /**
     * Delete Document
     *
     * Delete a document and all its associated data.
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @response 204 scenario="Success"
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Document] 1"}
     */
    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $this->ingestionService->delete($document);

        return response()->json(null, 204);
    }

    /**
     * Get Supported MIME Types
     *
     * Get a list of MIME types supported for document ingestion.
     *
     * @response 200 {"supported_types": ["application/pdf", "text/plain", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"]}
     */
    public function supportedTypes(): JsonResponse
    {
        return response()->json([
            'supported_types' => $this->ingestionService->getSupportedMimeTypes(),
        ]);
    }

    /**
     * Ingest a document from an uploaded file.
     */
    protected function ingestFromUpload(StoreDocumentRequest $request, User $user, ?string $title): Document
    {
        $uploadedFile = $request->file('file');

        // Create a File record first
        $fileService = app(\App\Services\FileService::class);
        $file = $fileService->upload($uploadedFile, 'input', $user->id);

        return $this->ingestionService->ingestFromFile($file, $user, $title);
    }
}
