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
    | Context Filter Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how conversation context is filtered when
    | approaching model context limits. The default strategy and options
    | are used when an agent doesn't specify its own configuration.
    |
    */

    'context_filter' => [
        'default_strategy' => 'token_budget',
        'default_options' => [
            'budget_percentage' => 0.8,
            'reserve_tokens' => 0,
        ],
        'default_threshold' => 0.8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Estimation Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how tokens are estimated for messages.
    | Different content types have different tokenization ratios.
    |
    */

    'token_estimation' => [
        'default_chars_per_token' => 4.0,
        'json_chars_per_token' => 2.5,
        'code_chars_per_token' => 3.0,
        'safety_margin' => 0.9,
        'cache_on_message' => true,
    ],

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
