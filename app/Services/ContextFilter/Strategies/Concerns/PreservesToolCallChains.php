<?php

declare(strict_types=1);

namespace App\Services\ContextFilter\Strategies\Concerns;

use App\DTOs\ChatMessage;

trait PreservesToolCallChains
{
    /**
     * Enforce bidirectional tool call chain integrity.
     *
     * 1. If we keep a tool_call, we must keep its tool_result
     * 2. If we keep a tool_result, we must keep its tool_call parent
     *
     * @param  array<int, ChatMessage>  $kept
     * @param  array<int, ChatMessage>  $allMessages
     * @return array<int, ChatMessage>
     */
    protected function enforceToolCallIntegrity(array $kept, array $allMessages): array
    {
        $keptIds = $this->getMessageIds($kept);
        $additions = [];

        // Direction 1: If we kept a tool_call, ensure its tool_result is included
        foreach ($kept as $msg) {
            if (! empty($msg->toolCalls)) {
                foreach ($msg->toolCalls as $toolCall) {
                    $toolCallId = is_array($toolCall) ? ($toolCall['id'] ?? null) : $toolCall->id;
                    if ($toolCallId === null) {
                        continue;
                    }

                    $response = $this->findToolResponse($allMessages, $toolCallId);
                    if ($response !== null && ! in_array($this->getMessageId($response), $keptIds, true)) {
                        $additions[] = $response;
                        $keptIds[] = $this->getMessageId($response);
                    }
                }
            }
        }

        // Direction 2: If we kept a tool_result, ensure its tool_call parent is included
        foreach ($kept as $msg) {
            if ($msg->toolCallId !== null) {
                $caller = $this->findToolCaller($allMessages, $msg->toolCallId);
                if ($caller !== null && ! in_array($this->getMessageId($caller), $keptIds, true)) {
                    $additions[] = $caller;
                    $keptIds[] = $this->getMessageId($caller);
                }
            }
        }

        // Merge kept and additions
        $result = array_merge($kept, $additions);

        // Re-sort by original position (postcondition)
        usort($result, fn (ChatMessage $a, ChatMessage $b) => $this->getMessagePosition($a, $allMessages) <=> $this->getMessagePosition($b, $allMessages));

        return $result;
    }

    /**
     * Find a tool response message by tool call ID.
     *
     * @param  array<int, ChatMessage>  $messages
     */
    private function findToolResponse(array $messages, string $toolCallId): ?ChatMessage
    {
        foreach ($messages as $msg) {
            if ($msg->toolCallId === $toolCallId) {
                return $msg;
            }
        }

        return null;
    }

    /**
     * Find the message containing a specific tool call.
     *
     * @param  array<int, ChatMessage>  $messages
     */
    private function findToolCaller(array $messages, string $toolCallId): ?ChatMessage
    {
        foreach ($messages as $msg) {
            if (! empty($msg->toolCalls)) {
                foreach ($msg->toolCalls as $toolCall) {
                    $id = is_array($toolCall) ? ($toolCall['id'] ?? null) : $toolCall->id;
                    if ($id === $toolCallId) {
                        return $msg;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get all message IDs from an array of messages.
     *
     * @param  array<int, ChatMessage>  $messages
     * @return array<int, string>
     */
    private function getMessageIds(array $messages): array
    {
        return array_map(fn (ChatMessage $m) => $this->getMessageId($m), $messages);
    }

    /**
     * Get a unique identifier for a message.
     * Uses position as fallback if no ID is available.
     */
    private function getMessageId(ChatMessage $message): string
    {
        // Use content hash + role as identifier since ChatMessage doesn't have ID
        return md5($message->role.':'.($message->content ?? '').':'.($message->toolCallId ?? ''));
    }

    /**
     * Get the position of a message in the original array.
     *
     * @param  array<int, ChatMessage>  $allMessages
     */
    private function getMessagePosition(ChatMessage $message, array $allMessages): int
    {
        foreach ($allMessages as $index => $msg) {
            if ($this->getMessageId($msg) === $this->getMessageId($message)) {
                return $index;
            }
        }

        return PHP_INT_MAX;
    }
}
