# Laravel Code Agent Platform - Implementation Guide

## Project Status
✅ Laravel Sail is set up
✅ Project scaffolded

## Implementation Order

### Phase 1: Database Schema (Priority 1)

**Create migrations:**
```bash
sail artisan make:migration create_agents_table
sail artisan make:migration create_tasks_table
sail artisan make:migration create_executions_table
sail artisan make:migration create_tools_table
sail artisan make:migration create_agent_tools_table
sail artisan make:migration create_files_table
sail artisan make:migration create_execution_files_table
```

**Schema definitions:**

**agents table:**
- id (bigint, primary)
- user_id (bigint, foreign -> users.id)
- name (string, 255)
- description (text, nullable)
- code (longtext)
- config (json, nullable)
- status (enum: active, inactive, error)
- ai_backend (string, default: 'ollama')
- timestamps

**tasks table:**
- id (bigint, primary)
- agent_id (bigint, foreign -> agents.id)
- payload (json)
- priority (int, default: 0)
- scheduled_at (timestamp, nullable)
- timestamps

**executions table:**
- id (bigint, primary)
- task_id (bigint, foreign -> tasks.id)
- status (enum: pending, running, completed, failed)
- started_at (timestamp, nullable)
- completed_at (timestamp, nullable)
- result (json, nullable)
- logs (longtext, nullable)
- error (text, nullable)
- timestamps

**tools table:**
- id (bigint, primary)
- user_id (bigint, foreign -> users.id)
- name (string, 255)
- type (enum: api, function, command)
- config (json)
- timestamps

**agent_tools table:**
- agent_id (bigint, foreign -> agents.id)
- tool_id (bigint, foreign -> tools.id)
- primary key (agent_id, tool_id)

**files table:**
- id (bigint, primary)
- user_id (bigint, foreign -> users.id)
- path (string, 500)
- type (enum: input, output, temp)
- size (bigint)
- mime_type (string, 100)
- timestamps

**execution_files table:**
- execution_id (bigint, foreign -> executions.id)
- file_id (bigint, foreign -> files.id)
- role (enum: input, output)
- primary key (execution_id, file_id)

**Run migrations:**
```bash
sail artisan migrate
```

### Phase 2: Authentication Setup (Priority 2)

**Install Sanctum:**
```bash
sail composer require laravel/sanctum
sail artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
sail artisan migrate
```

**Update User model (app/Models/User.php):**
```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

**Create auth controllers:**
```bash
sail artisan make:controller Api/V1/Auth/RegisterController
sail artisan make:controller Api/V1/Auth/LoginController
sail artisan make:controller Api/V1/Auth/LogoutController
```

**RegisterController:**
- Validate: name, email, password
- Create user
- Generate token
- Return user + token

**LoginController:**
- Validate: email, password
- Attempt authentication
- Generate token
- Return user + token

**LogoutController:**
- Revoke current token
- Return success message

**Add routes (routes/api.php):**
```php
Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [RegisterController::class, 'register']);
    Route::post('/auth/login', [LoginController::class, 'login']);
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [LogoutController::class, 'logout']);
        Route::get('/auth/user', function (Request $request) {
            return $request->user();
        });
    });
});
```

### Phase 3: API Documentation Setup (Priority 3)

**Install Scribe:**
```bash
sail composer require knuckleswtf/scribe
sail artisan vendor:publish --tag=scribe-config
```

**Update config/scribe.php:**
```php
return [
    'type' => 'laravel',
    'theme' => 'scalar',
    'title' => 'Code Agent Platform API',
    'description' => 'API for managing AI-powered code agents',
    'base_url' => env('APP_URL', 'http://localhost'),
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/v1/*'],
            ],
            'apply' => [
                'headers' => [
                    'Authorization' => 'Bearer {token}',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ],
        ],
    ],
    'auth' => [
        'enabled' => true,
        'default' => false,
        'in' => 'bearer',
    ],
];
```

**Add Scribe annotations to auth controllers:**
```php
/**
 * Register User
 * 
 * @group Authentication
 * @unauthenticated
 * 
 * @bodyParam name string required User's name. Example: John Doe
 * @bodyParam email string required User's email. Example: john@example.com
 * @bodyParam password string required User's password. Example: password123
 * 
 * @response 201 {
 *   "user": {"id": 1, "name": "John Doe", "email": "john@example.com"},
 *   "token": "1|abc123..."
 * }
 */
```

### Phase 4: AI Backend Abstraction (Priority 4 - CRITICAL)

**Create interface:**
```bash
sail artisan make:interface Contracts/AIBackendInterface
```

**AIBackendInterface (app/Contracts/AIBackendInterface.php):**
```php
interface AIBackendInterface {
    public function execute(Agent $agent, array $context): AIResponse;
    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse;
    public function validateConfig(array $config): bool;
    public function getCapabilities(): array;
    public function listModels(): array;
}
```

**Create AIResponse DTO:**
```bash
sail artisan make:class DTOs/AIResponse
```

**AIResponse structure:**
- content (string)
- model (string)
- tokens_used (int)
- finish_reason (string)
- metadata (array)

**Create AIBackendManager:**
```bash
sail artisan make:class Services/AIBackendManager
```

**AIBackendManager (app/Services/AIBackendManager.php):**
```php
class AIBackendManager {
    protected array $drivers = [];
    
    public function driver(?string $name = null): AIBackendInterface {
        $name = $name ?? config('ai.default');
        
        if (!isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->createDriver($name);
        }
        
        return $this->drivers[$name];
    }
    
    protected function createDriver(string $name): AIBackendInterface {
        $config = config("ai.backends.{$name}");
        $driver = $config['driver'];
        
        return match($driver) {
            'ollama' => new OllamaBackend($config),
            'anthropic' => new AnthropicBackend($config),
            'openai' => new OpenAIBackend($config),
            default => throw new InvalidArgumentException("Driver [{$driver}] not supported.")
        };
    }
    
    public function extend(string $name, Closure $callback): void {
        // Allow custom driver registration
    }
}
```

**Create config file (config/ai.php):**
```php
return [
    'default' => env('AI_BACKEND', 'ollama'),
    
    'backends' => [
        'ollama' => [
            'driver' => 'ollama',
            'base_url' => env('OLLAMA_BASE_URL', 'http://host.docker.internal:11434'),
            'model' => env('OLLAMA_MODEL', 'llama3.1'),
            'timeout' => env('OLLAMA_TIMEOUT', 120),
            'options' => [
                'temperature' => 0.7,
                'num_ctx' => 4096,
            ],
        ],
        
        'claude' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
            'max_tokens' => 4096,
        ],
        
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4'),
        ],
    ],
];
```

**Register service provider:**
```bash
sail artisan make:provider AIServiceProvider
```

**AIServiceProvider:**
```php
public function register() {
    $this->app->singleton(AIBackendManager::class, function ($app) {
        return new AIBackendManager();
    });
}
```

**Add to config/app.php providers array:**
```php
App\Providers\AIServiceProvider::class,
```

### Phase 5: Ollama Backend Implementation (Priority 5 - CRITICAL)

**Create Ollama backend:**
```bash
sail artisan make:class Services/AI/OllamaBackend
```

**OllamaBackend (app/Services/AI/OllamaBackend.php):**
```php
use GuzzleHttp\Client;
use App\Contracts\AIBackendInterface;
use App\DTOs\AIResponse;

class OllamaBackend implements AIBackendInterface
{
    protected Client $client;
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'base_uri' => $config['base_url'],
            'timeout' => $config['timeout'],
        ]);
    }
    
    public function execute(Agent $agent, array $context): AIResponse
    {
        $response = $this->client->post('/api/generate', [
            'json' => [
                'model' => $this->config['model'],
                'prompt' => $this->buildPrompt($agent, $context),
                'stream' => false,
                'options' => $this->config['options'],
            ],
        ]);
        
        $data = json_decode($response->getBody(), true);
        
        return new AIResponse(
            content: $data['response'],
            model: $data['model'],
            tokens_used: $data['eval_count'] ?? 0,
            finish_reason: $data['done'] ? 'stop' : 'length',
            metadata: $data
        );
    }
    
    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse
    {
        // Implement streaming
    }
    
    public function listModels(): array
    {
        $response = $this->client->get('/api/tags');
        $data = json_decode($response->getBody(), true);
        
        return array_map(fn($model) => [
            'name' => $model['name'],
            'size' => $model['size'],
            'modified' => $model['modified_at'],
        ], $data['models'] ?? []);
    }
    
    public function validateConfig(array $config): bool
    {
        try {
            $this->client->get('/api/tags');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getCapabilities(): array
    {
        return [
            'streaming' => true,
            'function_calling' => false,
            'vision' => false,
            'max_context' => $this->config['options']['num_ctx'] ?? 4096,
        ];
    }
    
    protected function buildPrompt(Agent $agent, array $context): string
    {
        // Build prompt from agent code and context
    }
}
```

**Test Ollama connection:**
```bash
sail artisan make:command TestOllamaConnection
```

**Command to verify Ollama:**
```php
public function handle()
{
    $backend = app(AIBackendManager::class)->driver('ollama');
    
    $this->info('Testing Ollama connection...');
    
    if ($backend->validateConfig(config('ai.backends.ollama'))) {
        $this->info('✓ Connected to Ollama');
        
        $models = $backend->listModels();
        $this->info('Available models: ' . count($models));
        
        foreach ($models as $model) {
            $this->line("  - {$model['name']}");
        }
    } else {
        $this->error('✗ Failed to connect to Ollama');
    }
}
```

**Run test:**
```bash
sail artisan test:ollama
```

### Phase 6: Models and Relationships (Priority 6)

**Create models:**
```bash
sail artisan make:model Agent
sail artisan make:model Task
sail artisan make:model Execution
sail artisan make:model Tool
sail artisan make:model File
```

**Define relationships in models:**

**Agent model:**
- belongsTo User
- hasMany Tasks
- hasMany Executions (through Tasks)
- belongsToMany Tools

**Task model:**
- belongsTo Agent
- hasOne Execution

**Execution model:**
- belongsTo Task
- belongsToMany Files

**Tool model:**
- belongsTo User
- belongsToMany Agents

**File model:**
- belongsTo User
- belongsToMany Executions

### Phase 7: Core Services (Priority 7)

**Create services:**
```bash
sail artisan make:class Services/AgentService
sail artisan make:class Services/ExecutionService
sail artisan make:class Services/ToolService
sail artisan make:class Services/FileService
```

**AgentService:**
- create(array $data): Agent
- update(Agent $agent, array $data): Agent
- delete(Agent $agent): bool
- attachTools(Agent $agent, array $toolIds): void
- detachTools(Agent $agent, array $toolIds): void

**ExecutionService:**
- execute(Agent $agent, array $payload, array $fileIds = []): Execution
- getStatus(Execution $execution): string
- getLogs(Execution $execution): string
- getOutputs(Execution $execution): Collection

**ToolService:**
- create(array $data): Tool
- update(Tool $tool, array $data): Tool
- delete(Tool $tool): bool
- execute(Tool $tool, array $params): mixed

**FileService:**
- upload(UploadedFile $file, string $type): File
- download(File $file): StreamedResponse
- delete(File $file): bool
- cleanup(string $type, Carbon $before): int

### Phase 8: API Controllers (Priority 8)

**Create controllers:**
```bash
sail artisan make:controller Api/V1/AgentController --api
sail artisan make:controller Api/V1/ToolController --api
sail artisan make:controller Api/V1/FileController --api
sail artisan make:controller Api/V1/ExecutionController
sail artisan make:controller Api/V1/AIBackendController
```

**Add Scribe annotations to each controller method**

**Create API routes (routes/api.php):**
```php
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Agents
    Route::apiResource('agents', AgentController::class);
    Route::post('agents/{agent}/tools', [AgentController::class, 'attachTools']);
    Route::delete('agents/{agent}/tools/{tool}', [AgentController::class, 'detachTool']);
    
    // Tools
    Route::apiResource('tools', ToolController::class);
    
    // Files
    Route::apiResource('files', FileController::class)->except(['update']);
    
    // Execution
    Route::post('agents/{agent}/execute', [ExecutionController::class, 'execute']);
    Route::get('executions', [ExecutionController::class, 'index']);
    Route::get('executions/{execution}', [ExecutionController::class, 'show']);
    Route::get('executions/{execution}/logs', [ExecutionController::class, 'logs']);
    Route::get('executions/{execution}/outputs', [ExecutionController::class, 'outputs']);
    
    // AI Backends
    Route::get('ai-backends', [AIBackendController::class, 'index']);
    Route::get('ai-backends/{backend}/models', [AIBackendController::class, 'models']);
});
```

### Phase 9: Queue Jobs (Priority 9)

**Create jobs:**
```bash
sail artisan make:job ExecuteAgentJob
sail artisan make:job CleanupTempFilesJob
sail artisan make:job ProcessToolCallJob
```

**ExecuteAgentJob:**
- Receive Execution model
- Load agent, tools, input files
- Call AIBackendManager
- Update execution status, result, logs
- Store output files
- Handle errors and timeouts

**Schedule cleanup job (app/Console/Kernel.php):**
```php
protected function schedule(Schedule $schedule)
{
    $schedule->job(new CleanupTempFilesJob)->daily();
}
```

### Phase 10: Testing (Priority 10)

**Create tests:**
```bash
sail artisan make:test OllamaBackendTest
sail artisan make:test AgentTest
sail artisan make:test ExecutionTest
sail artisan make:test AuthTest
```

**Run tests:**
```bash
sail test
```

### Phase 11: Documentation Generation (Priority 11)

**Generate API docs:**
```bash
sail artisan scribe:generate
```

**Access at:** http://localhost/docs

### Environment Configuration

**Update .env:**
```env
AI_BACKEND=ollama
OLLAMA_BASE_URL=http://host.docker.internal:11434
OLLAMA_MODEL=llama3.1
OLLAMA_TIMEOUT=120

QUEUE_CONNECTION=redis

FILESYSTEM_DISK=local
MAX_EXECUTION_TIME=300
MAX_FILE_SIZE=10485760
```

## Next Steps After Base Implementation

1. Implement streaming responses
2. Add WebSocket support for real-time updates
3. Create admin panel
4. Add metrics and monitoring
5. Implement additional AI backends (Claude, OpenAI)
6. Add agent versioning
7. Implement scheduled executions

Start with Phase 1 (Database Schema) and proceed sequentially through Phase 11.