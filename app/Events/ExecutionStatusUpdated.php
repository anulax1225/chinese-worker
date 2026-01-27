<?php

namespace App\Events;

use App\Models\Execution;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExecutionStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Execution $execution) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Broadcast to a private channel for the user who owns the execution
        return [
            new PrivateChannel('user.'.$this->execution->task->agent->user_id),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->execution->id,
            'task_id' => $this->execution->task_id,
            'status' => $this->execution->status,
            'started_at' => $this->execution->started_at?->toISOString(),
            'completed_at' => $this->execution->completed_at?->toISOString(),
            'result' => $this->execution->result,
            'error' => $this->execution->error,
            'updated_at' => $this->execution->updated_at->toISOString(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'execution.updated';
    }
}
