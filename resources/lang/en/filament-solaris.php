<?php

return [

    'notifications' => [
        'rate_limited' => 'Too many AI requests. Please wait a moment.',
        'overloaded' => 'The AI service is currently overloaded. Please try again.',
        'error' => 'Something went wrong with the AI request. Please try again.',
        'timeout' => 'The AI request timed out. Please try again.',
        'success' => 'AI filled :fields.',
        'partial_failure' => 'Could not fill :fields.|Could not fill :fields.',
        'empty_source_fields' => 'Please fill in :fields first.|Please fill in :fields first.',
        'overwrite_warning' => ':fields already has a value and will be overwritten.|:fields already have values and will be overwritten.',
    ],

    'user_input' => [
        'additional_instructions' => 'Additional instructions',
    ],

    'presets' => [
        'generate' => [
            'prompt' => 'What would you like to generate?',
            'placeholder' => 'Describe what you want...',
        ],
        'translate' => [
            'label' => 'Translate to',
        ],
    ],

    'languages' => [
        'en' => 'English',
        'nl' => 'Dutch',
        'fr' => 'French',
        'de' => 'German',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
        'ru' => 'Russian',
        'pl' => 'Polish',
        'sv' => 'Swedish',
        'da' => 'Danish',
        'fi' => 'Finnish',
        'no' => 'Norwegian',
    ],

];
