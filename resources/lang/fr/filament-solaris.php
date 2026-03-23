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
        'transcription_success' => 'Transcription ajoutée à :fields.',
        'transcription_empty' => "Aucune parole détectée dans l'enregistrement.",
        'transcription_error' => "Impossible de transcrire l'audio. Veuillez réessayer.",
        'transcription_rate_limited' => 'Trop de demandes de transcription. Veuillez patienter un instant.',
        'microphone_denied' => 'L\'accès au microphone est requis pour la dictée.',
    ],

    'preview' => [
        'modal_heading' => 'Vérifier les résultats IA',
        'accept' => 'Accepter',
        'discard' => 'Rejeter',
    ],

    'conversation' => [
        'heading' => 'Conversation',
        'refine_placeholder' => 'Affiner les résultats...',
        'send' => 'Envoyer',
        'initial_message' => 'Voici les résultats générés par l\'IA. Vous pouvez les affiner en envoyant un message.',
        'refined_message' => 'J\'ai mis à jour les résultats en fonction de vos commentaires.',
    ],

    'dictation' => [
        'modal_heading' => 'Enregistrer l\'audio',
        'submit_label' => 'Transcrire',
        'not_supported' => 'Votre navigateur ne prend pas en charge l\'enregistrement audio.',
        'microphone_denied' => 'L\'accès au microphone a été refusé. Veuillez autoriser l\'accès au microphone dans les paramètres de votre navigateur et réessayer.',
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
