<?php

return [

    'notifications' => [
        'rate_limited' => 'Te veel AI-verzoeken. Wacht even.',
        'overloaded' => 'De AI-dienst is momenteel overbelast. Probeer het opnieuw.',
        'error' => 'Er ging iets mis met het AI-verzoek. Probeer het opnieuw.',
        'timeout' => 'Het AI-verzoek is verlopen. Probeer het opnieuw.',
        'success' => 'AI heeft :fields ingevuld.',
        'partial_failure' => 'Kon :fields niet invullen.|Kon :fields niet invullen.',
        'empty_source_fields' => 'Vul eerst :fields in.|Vul eerst :fields in.',
        'overwrite_warning' => ':fields bevat al een waarde en zal worden overschreven.|:fields bevatten al waarden en zullen worden overschreven.',
    ],

    'user_input' => [
        'additional_instructions' => 'Bijkomende instructies',
    ],

    'presets' => [
        'generate' => [
            'prompt' => 'Wat wilt u genereren?',
            'placeholder' => 'Beschrijf wat u wilt...',
        ],
        'translate' => [
            'label' => 'Vertalen naar',
        ],
    ],

    'languages' => [
        'en' => 'Engels',
        'nl' => 'Nederlands',
        'fr' => 'Frans',
        'de' => 'Duits',
        'es' => 'Spaans',
        'it' => 'Italiaans',
        'pt' => 'Portugees',
        'ja' => 'Japans',
        'zh' => 'Chinees',
        'ko' => 'Koreaans',
        'ar' => 'Arabisch',
        'ru' => 'Russisch',
        'pl' => 'Pools',
        'sv' => 'Zweeds',
        'da' => 'Deens',
        'fi' => 'Fins',
        'no' => 'Noors',
    ],

];
