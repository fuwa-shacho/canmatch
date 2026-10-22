<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'prompt' => env('GEMINI_PROMPT', 'vending_read_v1'),
        'timeout' => env('GEMINI_TIMEOUT', 60),
        'thinking_level' => env('GEMINI_THINKING_LEVEL'),
        'retry_wait' => env('GEMINI_RETRY_WAIT', 10),
    ],

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'embedding_model' => env('CF_EMBEDDING_MODEL', '@cf/baai/bge-m3'),
        'vectorize_index' => env('CF_VECTORIZE_INDEX', 'canmatch-products'),
        'timeout' => env('CF_TIMEOUT', 30),
        'generation_model' => env('CF_GENERATION_MODEL'),
        'max_tokens' => env('CF_GENERATION_MAX_TOKENS', 400),
        'answer_prompt' => env('CF_ANSWER_PROMPT', 'rag_answer_v1'),
        'disable_thinking' => env('CF_GENERATION_DISABLE_THINKING', true),
        'json_mode' => env('CF_GENERATION_JSON_MODE', true),
    ],

];
