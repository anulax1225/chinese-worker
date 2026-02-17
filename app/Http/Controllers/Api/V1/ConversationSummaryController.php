<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SummaryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSummaryRequest;
use App\Http\Resources\ConversationSummaryResource;
use App\Jobs\CreateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Conversation Summaries
 *
 * APIs for managing conversation summaries.
 *
 * Summaries allow you to compress long conversation histories into concise
 * summaries that preserve key context. Summaries are created asynchronously
 * via a background job.
 *
 * @authenticated
 */
class ConversationSummaryController extends Controller
{
    /**
     * List Summaries
     *
     * Get all summaries for a conversation, ordered by creation date (newest first).
     *
     * @urlParam conversation integer required The conversation ID. Example: 1
     *
     * @apiResourceCollection App\Http\Resources\ConversationSummaryResource
     *
     * @apiResourceModel App\Models\ConversationSummary
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 1"}
     */
    public function index(Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);

        $summaries = $conversation->summaries()
            ->orderByDesc('created_at')
            ->get();

        return ConversationSummaryResource::collection($summaries);
    }

    /**
     * Create Summary
     *
     * Create a new summary for a conversation. The summary is processed
     * asynchronously via a background job. The response returns immediately
     * with a pending status (HTTP 202 Accepted).
     *
     * Poll the show endpoint to check when processing is complete.
     *
     * @urlParam conversation integer required The conversation ID. Example: 1
     *
     * @bodyParam from_position integer The starting message position (0-indexed). Defaults to first message. Example: 0
     * @bodyParam to_position integer The ending message position. Must be greater than from_position. Defaults to last message. Example: 50
     *
     * @apiResource 202 App\Http\Resources\ConversationSummaryResource
     *
     * @apiResourceModel App\Models\ConversationSummary
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [App\\Models\\Conversation] 1"}
     * @response 422 scenario="Validation Error" {"message": "The given data was invalid.", "errors": {"to_position": ["The to position must be greater than from position."]}}
     */
    public function store(StoreSummaryRequest $request, Conversation $conversation): JsonResponse
    {
        $fromPosition = $request->validated('from_position');
        $toPosition = $request->validated('to_position');

        // Create a pending summary record
        $summary = ConversationSummary::create([
            'conversation_id' => $conversation->id,
            'status' => SummaryStatus::Pending,
            'from_position' => $fromPosition ?? 0,
            'to_position' => $toPosition ?? 0,
        ]);

        // Dispatch the job to process the summary
        CreateConversationSummaryJob::dispatch($summary, $fromPosition, $toPosition);

        return (new ConversationSummaryResource($summary))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Show Summary
     *
     * Get details of a specific summary. Use this to poll for completion status
     * after creating a summary.
     *
     * Status values:
     * - `pending`: Summary is queued for processing
     * - `processing`: Summary is currently being generated
     * - `completed`: Summary is ready (content field will be populated)
     * - `failed`: Summary generation failed (error_message field will explain why)
     *
     * @urlParam conversation integer required The conversation ID. Example: 1
     * @urlParam summary string required The summary ID (ULID). Example: 01H5K3MPXJ9RWVZ8NCQY7D6WGT
     *
     * @apiResource App\Http\Resources\ConversationSummaryResource
     *
     * @apiResourceModel App\Models\ConversationSummary
     *
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "Not Found"}
     */
    public function show(Conversation $conversation, ConversationSummary $summary): ConversationSummaryResource
    {
        $this->authorize('view', $conversation);

        // Ensure the summary belongs to the conversation
        abort_unless($summary->conversation_id === $conversation->id, 404);

        return new ConversationSummaryResource($summary);
    }
}
