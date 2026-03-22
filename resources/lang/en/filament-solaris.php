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
        'transcription_success' => 'Transcription added to :fields.',
        'transcription_empty' => 'No speech detected in the recording.',
        'transcription_error' => 'Could not transcribe the audio. Please try again.',
        'transcription_rate_limited' => 'Too many transcription requests. Please wait a moment.',
        'microphone_denied' => 'Microphone access is required for dictation.',
    ],

    'preview' => [
        'modal_heading' => 'Review AI Results',
        'accept' => 'Accept',
        'discard' => 'Discard',
    ],

    'dictation' => [
        'modal_heading' => 'Record Audio',
        'submit_label' => 'Transcribe',
        'not_supported' => 'Your browser does not support audio recording.',
        'microphone_denied' => 'Microphone access was denied. Please allow microphone access in your browser settings and try again.',
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
