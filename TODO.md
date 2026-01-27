Frontend Implementation Plan for Chinese Worker AI Agent Platform
Overview
Build a complete Vue 3 frontend using Inertia.js v2 that fully utilizes the Chinese Worker API. The frontend will use shadcn-vue (stone theme) with Tailwind CSS v4 and Lucide icons.

Current State Analysis
Existing Setup
Frontend Framework: Vue 3.5.13 + Inertia.js v2.3.7 + TypeScript
Styling: Tailwind CSS v4.1.1 (inline config)
Build Tool: Vite 7.0.4
Current Pages: Only Welcome.vue exists
Components: None exist yet
Layouts: No layout system implemented
UI Library: None installed (shadcn-vue needs to be added)
Icons: No icon library installed (Lucide will be added)
API Coverage
The backend provides 30 endpoints across 7 functional areas:

Authentication (4 endpoints)
Agent Management (7 endpoints)
Tool Management (5 endpoints)
File Management (5 endpoints)
Execution Management (6 endpoints)
AI Backend Management (2 endpoints)
Real-time Features (WebSocket broadcasting + SSE streaming)
Implementation Strategy
Phase 1: Foundation Setup
Goal: Install dependencies and configure base infrastructure

1.1 Install shadcn-vue
Run: npx shadcn-vue@latest init
Configure with:
Style: Default
Base color: Stone
CSS variables: Yes
TypeScript: Yes
Components directory: resources/js/components
Utils directory: resources/js/lib
Import alias: @/*
1.2 Install Additional Dependencies

npm install lucide-vue-next
npm install @vueuse/core (already installed)
npm install date-fns (for date formatting)
npm install zod (for client-side validation)
1.3 Install Core shadcn-vue Components

npx shadcn-vue@latest add button
npx shadcn-vue@latest add input
npx shadcn-vue@latest add card
npx shadcn-vue@latest add dialog
npx shadcn-vue@latest add dropdown-menu
npx shadcn-vue@latest add select
npx shadcn-vue@latest add textarea
npx shadcn-vue@latest add table
npx shadcn-vue@latest add badge
npx shadcn-vue@latest add alert
npx shadcn-vue@latest add toast
npx shadcn-vue@latest add tabs
npx shadcn-vue@latest add separator
npx shadcn-vue@latest add avatar
npx shadcn-vue@latest add skeleton
npx shadcn-vue@latest add progress
npx shadcn-vue@latest add switch
npx shadcn-vue@latest add label
npx shadcn-vue@latest add form
Phase 2: Type Definitions
Goal: Define TypeScript types for all API responses and models

2.1 Create Type Files
resources/js/types/models.ts - Agent, Tool, File, Task, Execution types
resources/js/types/api.ts - API request/response types
resources/js/types/forms.ts - Form validation schemas
2.2 Example Type Definitions

// models.ts
export interface Agent {
  id: number;
  user_id: number;
  name: string;
  description: string | null;
  code: string;
  config: Record<string, any>;
  status: 'active' | 'inactive' | 'error';
  ai_backend: 'ollama' | 'anthropic' | 'openai';
  created_at: string;
  updated_at: string;
  tools?: Tool[];
}

export interface Execution {
  id: number;
  task_id: number;
  status: 'pending' | 'running' | 'completed' | 'failed';
  started_at: string | null;
  completed_at: string | null;
  result: ExecutionResult | null;
  logs: string | null;
  error: string | null;
  created_at: string;
  updated_at: string;
  task?: Task;
  files?: File[];
}
Phase 3: Layouts & Navigation
Goal: Create reusable layout components with navigation

3.1 Create Layout Components
resources/js/layouts/AuthenticatedLayout.vue

Contains: Sidebar navigation, header with user menu, main content area
Navigation items: Dashboard, Agents, Tools, Files, Executions, AI Backends
User dropdown: Profile, Settings, Logout
resources/js/layouts/GuestLayout.vue

Simple centered layout for login/register pages
Branding/logo area
3.2 Navigation Structure

Sidebar Navigation:
├── Dashboard (Home icon)
├── Agents (Bot icon)
├── Tools (Wrench icon)
├── Files (FolderOpen icon)
├── Executions (PlayCircle icon)
└── AI Backends (Settings icon)

User Menu (top-right):
├── Profile
├── Settings
└── Logout
Phase 4: Authentication Pages
Goal: Implement login and registration

4.1 Create Authentication Pages
resources/js/pages/Auth/Login.vue

Email + Password form
Remember me checkbox
"Don't have an account?" link to register
Uses GuestLayout
resources/js/pages/Auth/Register.vue

Name + Email + Password form
"Already have an account?" link to login
Uses GuestLayout
4.2 Create Authentication Service
resources/js/composables/useAuth.ts
login(email, password)
register(name, email, password)
logout()
Uses Wayfinder-generated routes from @/actions/
Phase 5: Dashboard Page
Goal: Create overview dashboard with key metrics

5.1 Dashboard Components
resources/js/pages/Dashboard.vue
Stats cards: Total Agents, Total Executions, Success Rate, Active Tools
Recent Executions table (5 most recent)
Quick Actions: Create Agent, Execute Agent, Upload File
Real-time execution status updates via WebSocket
Phase 6: Agent Management
Goal: Full CRUD for agents with tool attachment

6.1 Agent Pages
resources/js/pages/Agents/Index.vue

Table with columns: Name, Description, Status, AI Backend, Actions
Search/filter by status
Pagination
"Create Agent" button
resources/js/pages/Agents/Create.vue

Form: Name, Description, Code (textarea), Config (JSON), Status, AI Backend
Tool selection (multi-select dropdown)
Form validation
resources/js/pages/Agents/Edit.vue

Same as Create but pre-filled
Tool attachment/detachment interface
resources/js/pages/Agents/Show.vue

Agent details
Attached tools list
"Execute Agent" button
Recent executions for this agent
Edit/Delete actions
6.2 Agent Components
resources/js/components/agents/AgentCard.vue - Card display for agents
resources/js/components/agents/AgentForm.vue - Reusable form component
resources/js/components/agents/ExecuteAgentDialog.vue - Execute agent modal
resources/js/components/agents/AgentStatusBadge.vue - Status indicator
Phase 7: Tool Management
Goal: CRUD operations for tools

7.1 Tool Pages
resources/js/pages/Tools/Index.vue

Table: Name, Type, Actions
Filter by type (api, function, command)
resources/js/pages/Tools/Create.vue

Form: Name, Type (select), Config (dynamic based on type)
Type-specific config editors:
API: URL, Method, Headers
Function: Code textarea
Command: Command template
resources/js/pages/Tools/Edit.vue

Same as Create but pre-filled
resources/js/pages/Tools/Show.vue

Tool details
Agents using this tool
Edit/Delete actions
7.2 Tool Components
resources/js/components/tools/ToolCard.vue
resources/js/components/tools/ToolForm.vue
resources/js/components/tools/ToolTypeBadge.vue
resources/js/components/tools/ConfigEditor.vue - Dynamic config editor
Phase 8: File Management
Goal: Upload, list, download, delete files

8.1 File Pages
resources/js/pages/Files/Index.vue

Table: Name, Type, Size, MIME Type, Uploaded, Actions
Filter by type (input, output, temp)
Upload button (opens dialog)
Download/Delete actions
resources/js/components/files/FileUploadDialog.vue

Drag & drop file upload
File type selection
Progress indicator
8.2 File Components
resources/js/components/files/FileCard.vue
resources/js/components/files/FileTypeBadge.vue
resources/js/components/files/FilePreview.vue - Preview for text/JSON files
Phase 9: Execution Management
Goal: View executions with real-time updates and streaming

9.1 Execution Pages
resources/js/pages/Executions/Index.vue

Table: ID, Agent, Status, Started, Completed, Actions
Filter by status (pending, running, completed, failed)
Real-time status updates via WebSocket
Click to view details
resources/js/pages/Executions/Show.vue

Execution details
Live status updates
Logs viewer (scrollable, auto-scroll to bottom)
Output files list with download
Execution metadata (tokens used, model, duration)
Retry button (if failed)
9.2 Streaming Execution
resources/js/components/executions/StreamingExecutor.vue
Connect to SSE endpoint /agents/{agent}/stream
Display chunks in real-time
Show progress/completion status
Save completed output
9.3 Execution Components
resources/js/components/executions/ExecutionCard.vue
resources/js/components/executions/ExecutionStatusBadge.vue
resources/js/components/executions/LogsViewer.vue - Syntax highlighted logs
resources/js/components/executions/OutputsList.vue
Phase 10: Real-time Features
Goal: Implement WebSocket and SSE for live updates

10.1 WebSocket Integration (Laravel Reverb)
resources/js/composables/useBroadcasting.ts
Configure Laravel Echo with Reverb
Subscribe to user.{userId} private channel
Listen for execution.updated events
Update execution state reactively
10.2 Server-Sent Events Integration
resources/js/composables/useStreaming.ts
Connect to /agents/{agent}/stream
Handle chunk events
Handle completion events
Reconnection logic
10.3 Setup Laravel Echo

npm install laravel-echo pusher-js

// resources/js/echo.ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
Phase 11: AI Backend Management
Goal: View and select AI backends

11.1 AI Backend Pages
resources/js/pages/AIBackends/Index.vue
List available backends (Ollama, Anthropic, OpenAI)
Show capabilities for each
View available models per backend
Set default backend
11.2 Components
resources/js/components/ai-backends/BackendCard.vue
resources/js/components/ai-backends/ModelList.vue
Phase 12: Shared Components & Utilities
Goal: Create reusable components and utilities

12.1 Shared Components
resources/js/components/shared/PageHeader.vue - Page title + actions
resources/js/components/shared/EmptyState.vue - Empty state placeholder
resources/js/components/shared/LoadingSpinner.vue
resources/js/components/shared/ErrorAlert.vue
resources/js/components/shared/ConfirmDialog.vue - Confirmation dialogs
resources/js/components/shared/DataTable.vue - Reusable table component
12.2 Composables
resources/js/composables/useApi.ts - API wrapper with error handling
resources/js/composables/usePagination.ts - Pagination helper
resources/js/composables/useToast.ts - Toast notifications
resources/js/composables/useConfirm.ts - Confirmation dialogs
12.3 Utilities
resources/js/lib/api.ts - Axios/fetch wrapper
resources/js/lib/formatters.ts - Date/size formatters
resources/js/lib/validators.ts - Zod schemas
Phase 13: Laravel Integration
Goal: Create Inertia controller responses

13.1 Create Inertia Controllers
app/Http/Controllers/DashboardController.php
app/Http/Controllers/AgentController.php (Inertia version)
app/Http/Controllers/ToolController.php (Inertia version)
app/Http/Controllers/FileController.php (Inertia version)
app/Http/Controllers/ExecutionController.php (Inertia version)
app/Http/Controllers/Auth/LoginController.php (Inertia version)
app/Http/Controllers/Auth/RegisterController.php (Inertia version)
13.2 Web Routes
routes/web.php - Add all web routes returning Inertia responses

Route::get('/', fn() => redirect('/dashboard'));

Route::middleware('guest')->group(function() {
    Route::get('/login', [LoginController::class, 'show']);
    Route::get('/register', [RegisterController::class, 'show']);
});

Route::middleware('auth')->group(function() {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::resource('agents', AgentController::class);
    Route::resource('tools', ToolController::class);
    Route::resource('files', FileController::class);
    Route::resource('executions', ExecutionController::class);
    Route::get('/ai-backends', [AIBackendController::class, 'index']);
});
Critical Files to Modify/Create
New Files to Create (Frontend)
resources/js/layouts/AuthenticatedLayout.vue
resources/js/layouts/GuestLayout.vue
resources/js/pages/Auth/Login.vue
resources/js/pages/Auth/Register.vue
resources/js/pages/Dashboard.vue
resources/js/pages/Agents/{Index,Create,Edit,Show}.vue
resources/js/pages/Tools/{Index,Create,Edit,Show}.vue
resources/js/pages/Files/Index.vue
resources/js/pages/Executions/{Index,Show}.vue
resources/js/pages/AIBackends/Index.vue
resources/js/types/models.ts
resources/js/types/api.ts
resources/js/composables/useAuth.ts
resources/js/composables/useBroadcasting.ts
resources/js/composables/useStreaming.ts
resources/js/composables/useApi.ts
resources/js/echo.ts
Multiple component files (40+)
New Files to Create (Backend)
app/Http/Controllers/DashboardController.php
app/Http/Controllers/AgentController.php (web version)
app/Http/Controllers/ToolController.php (web version)
app/Http/Controllers/FileController.php (web version)
app/Http/Controllers/ExecutionController.php (web version)
app/Http/Controllers/Auth/LoginController.php (web version)
app/Http/Controllers/Auth/RegisterController.php (web version)
Files to Modify
routes/web.php - Add all web routes
resources/js/app.ts - Add Echo setup
package.json - Add new dependencies
.env.example - Document Reverb settings
Verification Plan
Step 1: Component Installation
Run npx shadcn-vue@latest init and verify components are in resources/js/components/ui/
Check that stone theme is applied in components.json
Step 2: Build & Compile
Run npm run build - should compile without errors
Run npm run dev - Vite dev server should start
Run ./vendor/bin/sail up -d - Laravel should be accessible
Step 3: Authentication Flow
Visit /login - Should show login page with shadcn-vue UI
Register new account - Should create user and redirect to dashboard
Login with credentials - Should authenticate and show dashboard
Check that auth state persists across page navigations
Step 4: CRUD Operations
Agents:

Create agent with name, code, backend selection
List agents in table
Edit agent and see changes
Delete agent with confirmation
Attach/detach tools
Tools:

Create tool with type-specific config
View tool details
Edit and delete tools
Files:

Upload file via drag-and-drop
List files with type filter
Download file
Delete file
Step 5: Execution Flow
Execute agent from agent detail page
See execution appear in executions list with "pending" status
Verify WebSocket updates change status to "running" then "completed"
View execution details page
Check logs are displayed
Download output files
Step 6: Streaming Execution
Start streaming execution
Verify chunks appear in real-time
Check final result is saved
Confirm UI updates to show completion
Step 7: Real-time Features
Open two browser windows (logged in as same user)
Execute agent in window 1
Verify window 2 receives WebSocket update and UI updates automatically
Check Reverb server is running (./vendor/bin/sail artisan reverb:start)
Step 8: UI/UX Polish
Test on mobile viewport (responsive design)
Verify all icons are from Lucide
Check stone theme is applied consistently
Validate loading states appear during API calls
Verify error messages are user-friendly
Test keyboard navigation and accessibility
Technical Considerations
State Management
Use Inertia's reactive page props for server-driven state
Use Vue ref/reactive for client-side UI state
Use @vueuse/core for common composable patterns
Error Handling
Catch API errors and display user-friendly messages
Use toast notifications for success/error feedback
Implement retry logic for failed operations
Performance
Lazy-load heavy components
Paginate long lists
Debounce search inputs
Use skeleton loaders during data fetching
Accessibility
Use semantic HTML
Add ARIA labels where needed
Ensure keyboard navigation works
Test with screen readers
Security
CSRF token handling (automatic via Inertia)
XSS prevention (automatic via Vue)
Input sanitization on server
Rate limiting on sensitive operations
Implementation Order
Phase 1: Foundation Setup (shadcn-vue + dependencies)
Phase 2: Type Definitions
Phase 3: Layouts & Navigation
Phase 4: Authentication Pages
Phase 13: Laravel Integration (web routes + controllers)
Phase 5: Dashboard
Phase 6: Agent Management
Phase 7: Tool Management
Phase 8: File Management
Phase 9: Execution Management
Phase 10: Real-time Features
Phase 11: AI Backend Management
Phase 12: Shared Components & Polish
Success Criteria
✅ All API endpoints are utilized by the frontend
✅ Authentication flow works (login, register, logout)
✅ All CRUD operations functional (Agents, Tools, Files)
✅ Real-time execution updates via WebSocket
✅ SSE streaming for live agent responses
✅ Responsive design works on mobile
✅ Stone-themed shadcn-vue components throughout
✅ Lucide icons used consistently
✅ No TypeScript errors
✅ All existing tests still pass
✅ User-friendly error handling and loading states

User approved the plan
Excellent! The plan has been approved. Let me create a comprehensive TODO list and start implementing the frontend.

Update Todos

Phase 1: Install shadcn-vue with stone theme

Phase 1: Install additional dependencies (lucide, date-fns, zod, echo)

Phase 1: Install core shadcn-vue components

Phase 2: Create TypeScript type definitions

Phase 3: Create layout components

Phase 4: Create authentication pages

Phase 13: Create Laravel web controllers and routes

Phase 5: Create dashboard page

Phase 6: Create agent management pages and components

Phase 7: Create tool management pages and components

Phase 8: Create file management pages and components

Phase 9: Create execution management pages and components

Phase 10: Implement real-time features (WebSocket + SSE)

Phase 11: Create AI backend management pages

Phase 12: Create shared components and utilities