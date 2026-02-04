# Conversation Design

Professional chat interface design for AI conversations.

## Message Layout

```
┌─────────────────────────────────────────────────────────────┐
│  Conversation Header                                        │
│  [Agent: Claude] [Status: Streaming] [···]                  │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│                              ┌─────────────────────────────┐│
│                              │ User message               ││
│                              │ Right-aligned, primary bg  ││
│                              └─────────────────────────────┘│
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 🤖 Assistant message                                │   │
│  │ Left-aligned, surface bg, with avatar               │   │
│  │                                                     │   │
│  │ ▼ Thinking... (collapsible)                        │   │
│  │ ┌─────────────────────────────────────────────────┐│   │
│  │ │ Let me analyze this step by step...             ││   │
│  │ └─────────────────────────────────────────────────┘│   │
│  │                                                     │   │
│  │ Here's what I found:                               │   │
│  │ ```python                                          │   │
│  │ def hello():                                       │   │
│  │     print("Hello, world!")                         │   │
│  │ ```                                                │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 🔧 Tool: web_search                                 │   │
│  │ Query: "latest news"                               │   │
│  │ [Approve] [Reject]                                 │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────┐ [Send]│
│  │ Type a message...                                │       │
│  └─────────────────────────────────────────────────┘ ⌘↵   │
└─────────────────────────────────────────────────────────────┘
```

## Message Bubbles

### User Messages

```vue
<template>
    <div class="flex justify-end mb-4">
        <div class="max-w-[80%] md:max-w-[70%]">
            <div class="bg-primary text-primary-foreground rounded-2xl rounded-br-md px-4 py-2.5">
                <p class="text-sm whitespace-pre-wrap">{{ message.content }}</p>
            </div>
            <p class="text-xs text-muted-foreground text-right mt-1">
                {{ formatTime(message.created_at) }}
            </p>
        </div>
    </div>
</template>
```

### Assistant Messages

```vue
<template>
    <div class="flex gap-3 mb-4">
        <!-- Avatar -->
        <Avatar class="h-8 w-8 flex-shrink-0">
            <AvatarImage :src="agent.avatar" />
            <AvatarFallback class="bg-secondary text-secondary-foreground">
                <Bot class="h-4 w-4" />
            </AvatarFallback>
        </Avatar>

        <div class="max-w-[80%] md:max-w-[70%] space-y-2">
            <!-- Agent name -->
            <p class="text-xs font-medium text-muted-foreground">
                {{ agent.name }}
            </p>

            <!-- Thinking (collapsible) -->
            <details v-if="message.thinking" class="group">
                <summary class="text-xs text-muted-foreground cursor-pointer flex items-center gap-1">
                    <ChevronRight class="h-3 w-3 transition-transform group-open:rotate-90" />
                    Thinking...
                </summary>
                <div class="mt-2 pl-4 border-l-2 border-muted text-sm text-muted-foreground italic">
                    {{ message.thinking }}
                </div>
            </details>

            <!-- Content -->
            <div class="bg-muted rounded-2xl rounded-tl-md px-4 py-2.5">
                <div
                    class="text-sm prose prose-sm dark:prose-invert max-w-none"
                    v-html="renderMarkdown(message.content)"
                />
            </div>

            <!-- Tool calls indicator -->
            <div v-if="message.tool_calls?.length" class="flex flex-wrap gap-1">
                <Badge
                    v-for="tool in message.tool_calls"
                    :key="tool.id"
                    variant="outline"
                    class="text-xs"
                >
                    <Wrench class="h-3 w-3 mr-1" />
                    {{ tool.name }}
                </Badge>
            </div>

            <!-- Timestamp -->
            <p class="text-xs text-muted-foreground">
                {{ formatTime(message.created_at) }}
            </p>
        </div>
    </div>
</template>
```

### Tool Request Message

```vue
<template>
    <div class="flex gap-3 mb-4">
        <div class="h-8 w-8 flex-shrink-0 rounded-full bg-accent/10 flex items-center justify-center">
            <Wrench class="h-4 w-4 text-accent" />
        </div>

        <div class="flex-1 max-w-[90%]">
            <Card variant="flat" padding="sm" class="border-accent/50">
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-2 flex-1">
                        <div class="flex items-center gap-2">
                            <Badge variant="outline" class="bg-accent/10 text-accent border-accent/30">
                                {{ toolRequest.name }}
                            </Badge>
                            <span class="text-xs text-muted-foreground">
                                Tool Request
                            </span>
                        </div>

                        <!-- Arguments preview -->
                        <div class="bg-muted rounded-md p-2 text-xs font-mono overflow-x-auto">
                            <pre class="whitespace-pre-wrap">{{ formatArguments(toolRequest.arguments) }}</pre>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-col gap-1.5">
                        <Button size="sm" @click="approve">
                            <Check class="h-3 w-3 mr-1" />
                            Approve
                        </Button>
                        <Button size="sm" variant="outline" @click="reject">
                            <X class="h-3 w-3 mr-1" />
                            Reject
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
```

### System Messages

```vue
<template>
    <div class="flex justify-center my-4">
        <p class="text-xs text-muted-foreground bg-muted/50 px-3 py-1 rounded-full">
            {{ message.content }}
        </p>
    </div>
</template>
```

## Streaming Indicator

### Typing Animation

```vue
<template>
    <div v-if="isStreaming" class="flex gap-3 mb-4">
        <Avatar class="h-8 w-8 flex-shrink-0">
            <AvatarFallback class="bg-secondary">
                <Bot class="h-4 w-4" />
            </AvatarFallback>
        </Avatar>

        <div class="bg-muted rounded-2xl rounded-tl-md px-4 py-2.5">
            <div class="text-sm">
                <!-- Streaming content -->
                <span v-html="renderMarkdown(streamingContent)" />

                <!-- Cursor -->
                <span class="inline-block w-2 h-4 bg-foreground/70 animate-pulse ml-0.5" />
            </div>
        </div>
    </div>
</template>

<style>
@keyframes cursor-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}

.animate-cursor {
    animation: cursor-blink 1s ease-in-out infinite;
}
</style>
```

### Connection Status

```vue
<template>
    <div class="flex items-center gap-2 text-xs">
        <!-- Idle -->
        <div v-if="state === 'idle'" class="flex items-center gap-1.5 text-muted-foreground">
            <div class="h-2 w-2 rounded-full bg-muted-foreground/50" />
            Ready
        </div>

        <!-- Connecting -->
        <div v-else-if="state === 'connecting'" class="flex items-center gap-1.5 text-warning">
            <Loader2 class="h-3 w-3 animate-spin" />
            Connecting...
        </div>

        <!-- Connected/Streaming -->
        <div v-else-if="state === 'streaming'" class="flex items-center gap-1.5 text-success">
            <div class="h-2 w-2 rounded-full bg-success animate-pulse" />
            Streaming
        </div>

        <!-- Waiting for tool -->
        <div v-else-if="state === 'waiting_tool'" class="flex items-center gap-1.5 text-accent">
            <Pause class="h-3 w-3" />
            Waiting for approval
        </div>

        <!-- Error -->
        <div v-else-if="state === 'error'" class="flex items-center gap-1.5 text-destructive">
            <AlertCircle class="h-3 w-3" />
            Connection error
        </div>
    </div>
</template>
```

## Input Area

### Message Input Component

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Send, Paperclip } from 'lucide-vue-next';

const message = ref('');
const textareaRef = ref<HTMLTextAreaElement>();
const isExpanded = ref(false);

const emit = defineEmits<{
    send: [content: string];
}>();

const canSend = computed(() => message.value.trim().length > 0);

const handleSend = () => {
    if (!canSend.value) return;
    emit('send', message.value.trim());
    message.value = '';
    isExpanded.value = false;
};

const handleKeydown = (e: KeyboardEvent) => {
    // Cmd/Ctrl + Enter to send
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        e.preventDefault();
        handleSend();
    }
};
</script>

<template>
    <div class="sticky bottom-0 bg-background border-t border-border p-3">
        <div class="flex items-end gap-2">
            <!-- Attachment button (placeholder) -->
            <Button
                variant="ghost"
                size="icon"
                class="h-9 w-9 flex-shrink-0"
                disabled
            >
                <Paperclip class="h-4 w-4" />
            </Button>

            <!-- Input -->
            <div class="flex-1 relative">
                <Textarea
                    ref="textareaRef"
                    v-model="message"
                    placeholder="Type a message..."
                    class="min-h-[40px] max-h-[200px] resize-none pr-10"
                    :rows="isExpanded ? 4 : 1"
                    @focus="isExpanded = true"
                    @blur="isExpanded = message.length > 0"
                    @keydown="handleKeydown"
                />
            </div>

            <!-- Send button -->
            <Button
                size="icon"
                class="h-9 w-9 flex-shrink-0"
                :disabled="!canSend"
                @click="handleSend"
            >
                <Send class="h-4 w-4" />
            </Button>
        </div>

        <!-- Keyboard hint -->
        <p class="text-xs text-muted-foreground text-right mt-1">
            <kbd class="px-1.5 py-0.5 bg-muted rounded text-[10px]">⌘</kbd>
            <kbd class="px-1.5 py-0.5 bg-muted rounded text-[10px] ml-0.5">↵</kbd>
            to send
        </p>
    </div>
</template>
```

## Code Blocks

### Syntax Highlighting with Shiki

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { codeToHtml } from 'shiki';
import { Copy, Check } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    code: string;
    language: string;
}>();

const copied = ref(false);

const highlightedCode = computed(async () => {
    return await codeToHtml(props.code, {
        lang: props.language,
        theme: 'github-dark',
    });
});

const copyCode = async () => {
    await navigator.clipboard.writeText(props.code);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
};
</script>

<template>
    <div class="relative group rounded-lg overflow-hidden bg-[#0d1117] my-3">
        <!-- Language badge -->
        <div class="flex items-center justify-between px-3 py-1.5 bg-[#161b22] border-b border-[#30363d]">
            <span class="text-xs text-[#8b949e] font-mono">{{ language }}</span>

            <!-- Copy button -->
            <Button
                variant="ghost"
                size="icon"
                class="h-6 w-6 opacity-0 group-hover:opacity-100 transition-opacity"
                @click="copyCode"
            >
                <Check v-if="copied" class="h-3 w-3 text-success" />
                <Copy v-else class="h-3 w-3 text-[#8b949e]" />
            </Button>
        </div>

        <!-- Code content -->
        <div
            class="p-3 text-sm overflow-x-auto"
            v-html="highlightedCode"
        />
    </div>
</template>
```

### Inline Code

```css
/* In your prose styles */
.prose code:not(pre code) {
    @apply bg-muted px-1.5 py-0.5 rounded text-sm font-mono;
}

.prose code:not(pre code)::before,
.prose code:not(pre code)::after {
    content: none;
}
```

## Conversation Header

```vue
<template>
    <div class="flex items-center justify-between p-3 border-b border-border bg-background/95 backdrop-blur-sm sticky top-14 z-10">
        <div class="flex items-center gap-3">
            <!-- Back button (mobile) -->
            <Button variant="ghost" size="icon" class="md:hidden" @click="goBack">
                <ChevronLeft class="h-4 w-4" />
            </Button>

            <!-- Agent info -->
            <div class="flex items-center gap-2">
                <Avatar class="h-8 w-8">
                    <AvatarImage :src="agent.avatar" />
                    <AvatarFallback>
                        <Bot class="h-4 w-4" />
                    </AvatarFallback>
                </Avatar>
                <div>
                    <p class="text-sm font-medium">{{ agent.name }}</p>
                    <ConnectionStatus :state="connectionState" />
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-1">
            <Button variant="ghost" size="icon">
                <MoreVertical class="h-4 w-4" />
            </Button>
        </div>
    </div>
</template>
```

## Scroll Behavior

```typescript
// Auto-scroll to bottom on new messages
const messagesContainer = ref<HTMLElement>();

const scrollToBottom = (behavior: 'smooth' | 'auto' = 'smooth') => {
    if (messagesContainer.value) {
        messagesContainer.value.scrollTo({
            top: messagesContainer.value.scrollHeight,
            behavior,
        });
    }
};

// Watch for new messages
watch(
    () => messages.value.length,
    () => scrollToBottom(),
    { flush: 'post' }
);

// Scroll to bottom when streaming content updates
watch(
    streamingContent,
    () => {
        // Only auto-scroll if user is near the bottom
        const container = messagesContainer.value;
        if (container) {
            const isNearBottom =
                container.scrollHeight - container.scrollTop - container.clientHeight < 100;
            if (isNearBottom) {
                scrollToBottom('auto');
            }
        }
    }
);
```

## Empty State

```vue
<template>
    <div v-if="!messages.length" class="flex-1 flex items-center justify-center p-8">
        <div class="text-center max-w-md">
            <div class="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
                <MessageSquare class="h-8 w-8 text-primary" />
            </div>
            <h3 class="text-lg font-medium mb-2">Start a conversation</h3>
            <p class="text-sm text-muted-foreground">
                Send a message to {{ agent.name }} to begin.
                The AI will respond and can use tools to help you.
            </p>
        </div>
    </div>
</template>
```
