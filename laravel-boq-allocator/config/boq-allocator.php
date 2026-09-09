<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider & Model
    |--------------------------------------------------------------------------
    | Default model to use for BoQ classification if none specified in request.
    | AITOOLV3 defaults to gpt-5.6-luna through the OpenAI Responses API.
    */
    'default_model' => env('BOQ_DEFAULT_MODEL', env('OPENAI_MODEL', 'gpt-5.6-luna')),

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
    'max_records_per_call' => env('BOQ_MAX_RECORDS_PER_CALL', 100),
    'cost_cap_usd' => env('BOQ_COST_CAP_USD', 0.15),
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
