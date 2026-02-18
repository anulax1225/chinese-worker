# Phase 3 — Documents & RAG

> **Goal**: Upload, browse, and manage documents from the TUI. Attach documents to conversations so the agent can search and reference them. See RAG working in the chat flow.

## What You Get at the End

- Document browser screen (list, view metadata, see processing status)
- Upload documents from the TUI (file picker or path input)
- Upload documents from URL
- See document processing pipeline progress (extracting → cleaning → normalizing → chunking → ready)
- Attach documents to conversations
- In chat, see when the agent uses document tools (document_search, document_read, etc.)
- Manage documents: delete, reprocess

## Prerequisites

- Phase 1 & 2 complete
- **New API client methods** must be added (see below)

## New API Client Methods

The existing `client.py` already has document CRUD, upload, stages, chunks, preview, and reprocess. What's already there:

| Method | Status |
|--------|--------|
| `list_documents()` | ✅ Exists |
| `get_document(id)` | ✅ Exists |
| `upload_document(path, title)` | ✅ Exists |
| `upload_document_from_url(url, title)` | ✅ Exists |
| `get_document_stages(id)` | ✅ Exists |
| `get_document_chunks(id)` | ✅ Exists |
| `get_document_preview(id)` | ✅ Exists |
| `reprocess_document(id)` | ✅ Exists |
| `delete_document(id)` | ✅ Exists |
| `get_supported_document_types()` | ✅ Exists |

Nothing new needed for the API client itself. The backend already exposes everything.

**However**, the conversation creation flow may need a way to attach documents. Check whether the backend supports passing document IDs when creating a conversation or if it's handled through the agent configuration. If the backend doesn't support per-conversation document attachment from the API, that's a backend enhancement to flag (but not block on — documents attached at the agent level will still work through RAG).

## Screens

### DocumentListScreen

Accessible from HomeScreen or via slash command.

```
┌──────────────────────────────────────────────────────┐
│  Documents                           U Upload  Q ✕   │
├──────────────────────────────────────────────────────┤
│  🔍 [Search documents...]                            │
│                                                      │
│  ┌──────────────────────────────────────────────────┐│
│  │ 📄 API Design Guide                             ││
│  │    PDF · 2.4 MB · 47 chunks · ready       3d ago││
│  ├──────────────────────────────────────────────────┤│
│  │ 📄 Project Requirements                         ││
│  │    Markdown · 12 KB · 8 chunks · ready    1w ago││
│  ├──────────────────────────────────────────────────┤│
│  │ 📄 Meeting Notes Q4                             ││
│  │    DOCX · 156 KB · ████░ chunking...      2m ago││
│  ├──────────────────────────────────────────────────┤│
│  │ 📄 Architecture Diagram                         ││
│  │    PNG · 3.1 MB · ✗ failed (OCR error)    5d ago││
│  └──────────────────────────────────────────────────┘│
│                                                      │
│  ↑↓ Navigate  Enter View  U Upload  D Delete  R Re  │
└──────────────────────────────────────────────────────┘
```

**Features:**
- List all documents with: title, format, size, chunk count, status, relative time
- Status shown as: progress bar (processing), ✓ ready, ✗ failed
- Filter by status (ready, processing, failed)
- Upload action → opens upload dialog
- Delete with confirmation
- Reprocess failed documents
- Enter → DocumentDetailScreen

### DocumentDetailScreen

Shows document metadata, processing stages, and a content preview.

```
┌──────────────────────────────────────────────────────┐
│  ← Back                    API Design Guide          │
├──────────────────────────────────────────────────────┤
│                                                      │
│  Status: ✓ ready                                     │
│  Format: application/pdf  Size: 2.4 MB               │
│  Chunks: 47  Created: 2025-02-14                     │
│                                                      │
│  ── Processing Pipeline ──────────────────────────── │
│  ✓ Extraction    0.8s    PlainTextExtractor           │
│  ✓ Cleaning      0.3s    7 steps applied              │
│  ✓ Normalization 0.2s    12 headings detected         │
│  ✓ Chunking      0.4s    47 chunks (avg 850 tokens)   │
│                                                      │
│  ── Preview ──────────────────────────────────────── │
│  # API Design Guide                                  │
│                                                      │
│  ## Introduction                                     │
│  This document outlines the REST API design          │
│  principles for the Chinese Worker platform...       │
│  ...                                                 │
│                                                      │
│  R Reprocess  D Delete  Esc Back                     │
└──────────────────────────────────────────────────────┘
```

### UploadDialog (Modal)

Triggered by `U` key from DocumentListScreen or `/upload` in chat.

```
┌──────────────────────────────────────┐
│  Upload Document                     │
│                                      │
│  Source: ○ File  ○ URL  ○ Paste      │
│                                      │
│  Path: [~/documents/api-guide.pdf ]  │
│  Title: [API Design Guide (opt.)   ] │
│                                      │
│  Supported: PDF, DOCX, MD, TXT, ... │
│                                      │
│  [ Upload ]  [ Cancel ]             │
│                                      │
└──────────────────────────────────────┘
```

- Three modes: file path, URL, text paste
- File path: type path (with tab completion if possible) or paste
- URL: paste URL, backend fetches and processes
- Title is optional (defaults to filename)
- Show upload progress
- On success, navigate to DocumentDetailScreen for the new document

## Chat Integration

### Document Tool Indicators

When the agent uses document tools during a conversation, show them inline like server-side tools:

```
  Assistant:
  Let me search the API guide for that...
  
  📎 document_search: "authentication flow" in "API Design Guide"
  ⤷ Found 3 relevant chunks (similarity: 0.87, 0.82, 0.79)
  
  Based on the documentation, the auth flow works like this...
```

These events come through the existing SSE `tool_executing` and `tool_completed` events. The ChatScreen already handles these (from Phase 1) — just improve the formatting for document-specific tools.

### Slash Commands

| Command | Action |
|---------|--------|
| `/documents` or `/docs` | Open DocumentListScreen |
| `/upload <path>` | Quick upload from chat |
| `/upload-url <url>` | Upload from URL |

## Widgets

### `DocumentItem` (extends `Static`)

```python
class DocumentItem(Static):
    """Single document in the list."""
    
    STATUS_ICONS = {
        "ready": "[green]✓[/green]",
        "failed": "[red]✗[/red]",
        "pending": "[yellow]⏳[/yellow]",
    }
    PROCESSING_STAGES = ["extracting", "cleaning", "normalizing", "chunking"]
```

### `ProcessingPipeline` (extends `Static`)

Visual display of the 4-phase document processing pipeline.

```python
class ProcessingPipeline(Static):
    """Shows document processing stages with status."""
    
    def render_stage(self, stage: dict) -> str:
        icon = "✓" if stage["completed"] else "⏳" if stage["active"] else "○"
        time = f"{stage['duration']:.1f}s" if stage.get("duration") else ""
        return f"{icon} {stage['name']}  {time}  {stage.get('details', '')}"
```

### `UploadModal` (extends `ModalScreen`)

Modal for document upload with source selection.

## Polling for Processing Status

Documents processing is async on the backend. After upload, poll the document status:

```python
@work(thread=True)
async def poll_document_status(self, doc_id: int):
    """Poll until document processing completes."""
    while True:
        doc = await run_in_executor(self.client.get_document, doc_id)
        status = doc["data"]["status"]
        self.post_message(DocumentStatusUpdate(doc_id, status))
        
        if status in ("ready", "failed"):
            break
        await asyncio.sleep(2)
```

## Styling

### `documents.tcss`

```css
.document-item {
    padding: 1 2;
    border-bottom: solid $border;
}

.document-item.ready { border-left: thick $success; }
.document-item.failed { border-left: thick $error; }
.document-item.processing { border-left: thick $warning; }

.pipeline-stage {
    padding: 0 2;
    height: 1;
}

.pipeline-stage.completed { color: $success; }
.pipeline-stage.active { color: $warning; }
.pipeline-stage.pending { color: $text-muted; }
```

## Implementation Order

### Step 1: DocumentListScreen (Day 1-2)
- Fetch and display documents
- Status indicators and metadata
- Delete and reprocess actions

### Step 2: DocumentDetailScreen (Day 2-3)
- Show metadata, stages, preview
- Processing pipeline visualization
- Reprocess and delete actions

### Step 3: Upload flow (Day 3-4)
- Upload modal (file path and URL modes)
- Upload progress
- Post-upload polling for processing status
- Navigate to detail on completion

### Step 4: Chat integration (Day 4-5)
- Improved document tool display in chat
- Slash commands for document access
- Wire up from HomeScreen navigation

## Backend Considerations

**No backend changes required** for this phase. All document management endpoints already exist. 

**Possible enhancement** (non-blocking): If document-to-conversation attachment isn't in the API, conversations can still use documents through the agent's RAG configuration. Flag for a future backend update if per-conversation document attachment is desired.

## Acceptance Criteria

- [X] DocumentListScreen shows all documents with status
- [X] Can filter documents by status
- [X] Can upload a document via file path
- [ ] Can upload a document via URL
- [ ] Upload shows progress, polls for completion
- [ ] DocumentDetailScreen shows metadata + processing stages + preview
- [ ] Can reprocess a failed document
- [ ] Can delete a document
- [ ] Document tool usage shown clearly in chat
- [ ] Slash commands work (/docs, /upload)