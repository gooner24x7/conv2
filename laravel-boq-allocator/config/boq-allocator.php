<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider & Model
    |--------------------------------------------------------------------------
    | Default model to use for BoQ classification if none specified in request.
    | Options:
    |   - Google: 'gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-3.5-flash', 'gemini-3.1-pro'
    |   - OpenAI: 'openai-luna', 'openai-terra', 'openai-sol', 'gpt-4o', 'gpt-4o-mini'
    |   - Anthropic: 'claude-3-7-sonnet', 'claude-3-5-sonnet'
    */
    'default_model' => env('BOQ_DEFAULT_MODEL', 'gemini-3.6-flash'),

    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    | AI service provider credentials.
    */
    'api_keys' => [
        'gemini' => env('GEMINI_API_KEY', ''),
        'openai' => env('OPENAI_API_KEY', ''),
        'anthropic' => env('ANTHROPIC_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing Settings
    |--------------------------------------------------------------------------
    */
    'batch_size' => env('BOQ_BATCH_SIZE', 18),
    'max_context_items_per_bill' => env('BOQ_MAX_CONTEXT_ITEMS', 20),

    /*
    |--------------------------------------------------------------------------
    | Default Works Package Template
    |--------------------------------------------------------------------------
    | Default template to use if none is selected.
    */
    'default_template' => env('BOQ_DEFAULT_TEMPLATE', 'WD template.csv'),
    
    /*
    |--------------------------------------------------------------------------
    | Templates Storage Path
    |--------------------------------------------------------------------------
    */
    'templates_path' => storage_path('app/templates'),
];
