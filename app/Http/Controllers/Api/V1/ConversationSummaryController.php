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

class ConversationSummaryController extends Controller
{
    /**
     * List all summaries for a conversation.
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
     * Create a new summary for a conversation.
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
     * Show a specific summary.
     */
    public function show(Conversation $conversation, ConversationSummary $summary): ConversationSummaryResource
    {
        $this->authorize('view', $conversation);

        // Ensure the summary belongs to the conversation
        abort_unless($summary->conversation_id === $conversation->id, 404);

        return new ConversationSummaryResource($summary);
    }
}
