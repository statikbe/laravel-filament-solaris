<?php

return [

    'notifications' => [
        'rate_limited' => 'Trop de demandes IA. Veuillez patienter un instant.',
        'overloaded' => 'Le service IA est actuellement surchargé. Veuillez réessayer.',
        'error' => "Une erreur s'est produite avec la demande IA. Veuillez réessayer.",
        'timeout' => 'La demande IA a expiré. Veuillez réessayer.',
        'success' => "L'IA a rempli :fields.",
        'partial_failure' => 'Impossible de remplir :fields.|Impossible de remplir :fields.',
        'empty_source_fields' => "Veuillez d'abord remplir :fields.|Veuillez d'abord remplir :fields.",
        'overwrite_warning' => ':fields contient déjà une valeur et sera écrasé.|:fields contiennent déjà des valeurs et seront écrasés.',
    ],

    'user_input' => [
        'additional_instructions' => 'Instructions supplémentaires',
    ],

    'presets' => [
        'generate' => [
            'prompt' => 'Que souhaitez-vous générer ?',
            'placeholder' => 'Décrivez ce que vous voulez...',
        ],
        'translate' => [
            'label' => 'Traduire en',
        ],
    ],

    'languages' => [
        'en' => 'Anglais',
        'nl' => 'Néerlandais',
        'fr' => 'Français',
        'de' => 'Allemand',
        'es' => 'Espagnol',
        'it' => 'Italien',
        'pt' => 'Portugais',
        'ja' => 'Japonais',
        'zh' => 'Chinois',
        'ko' => 'Coréen',
        'ar' => 'Arabe',
        'ru' => 'Russe',
        'pl' => 'Polonais',
        'sv' => 'Suédois',
        'da' => 'Danois',
        'fi' => 'Finnois',
        'no' => 'Norvégien',
    ],

];
