<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Backend
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI backend that will be used when
    | interacting with AI models. You may change this to any of the
    | backends defined in the "backends" configuration below.
    |
    */

    'default' => env('AI_BACKEND', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | AI Backend Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure multiple AI backends. Each backend can use
    | a different driver (ollama, anthropic, openai) and has its own
    | configuration options.
    |
    */

    'backends' => [
        'ollama' => [
            'driver' => 'ollama',
            'base_url' => env('OLLAMA_BASE_URL', 'http://ollama:11434'),
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
            'timeout' => 120,
        ],

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4'),
            'max_tokens' => 4096,
            'timeout' => 120,
        ],
    ],
];
