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
        'transcription_success' => 'Transcriptie toegevoegd aan :fields.',
        'transcription_empty' => 'Geen spraak gedetecteerd in de opname.',
        'transcription_error' => 'Kon de audio niet transcriberen. Probeer het opnieuw.',
        'transcription_rate_limited' => 'Te veel transcriptieverzoeken. Wacht even.',
        'microphone_denied' => 'Microfoontoegang is vereist voor dictatie.',
    ],

    'preview' => [
        'modal_heading' => 'AI-resultaten bekijken',
        'accept' => 'Accepteren',
        'discard' => 'Verwerpen',
    ],

    'conversation' => [
        'heading' => 'Conversatie',
        'refine_placeholder' => 'Verfijn de resultaten...',
        'send' => 'Versturen',
        'initial_message' => 'Hier zijn de AI-gegenereerde resultaten. U kunt ze verfijnen door een bericht te sturen.',
        'refined_message' => 'Ik heb de resultaten bijgewerkt op basis van uw feedback.',
        'ai_label' => 'AI',
        'attach_files' => 'Bestanden toevoegen',
    ],

    'dictation' => [
        'modal_heading' => 'Audio opnemen',
        'submit_label' => 'Transcriberen',
        'not_supported' => 'Uw browser ondersteunt geen audio-opname.',
        'microphone_denied' => 'Microfoontoegang is geweigerd. Sta microfoontoegang toe in uw browserinstellingen en probeer het opnieuw.',
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
