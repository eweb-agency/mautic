<?php

return [
    'name'        => 'Eweb AI Copilot',
    'description' => 'In-instance AI copilot for email subject and content, gated by an Anthropic API key.',
    'version'     => '1.0.0',
    'author'      => 'Eweb Agency',

    'routes' => [
        // "main" routes are automatically prefixed with /s by Mautic's
        // RouteLoader and sit behind the native SESSION firewall (a logged-in
        // admin). This is deliberately NOT an /api route: the copilot is called
        // from the admin UI (email editor), not from an OAuth2 machine client.
        'main' => [
            'eweb_ai_generate' => [
                'path'       => '/ai/generate',
                'controller' => 'MauticPlugin\EwebAiBundle\Controller\AiController::generateAction',
                'method'     => 'POST',
            ],
            // Assistant de segmentation. Ne mute rien : propose des critères et
            // le décompte de contacts correspondant. C'est le formulaire natif
            // du segment qui enregistre, avec sa propre protection CSRF.
            'eweb_ai_segment_suggest' => [
                'path'       => '/ai/segment/suggest',
                'controller' => 'MauticPlugin\EwebAiBundle\Controller\AiSegmentController::suggestAction',
                'method'     => 'POST',
            ],
            // Compteur en continu du formulaire de segment. Valeur MOTEUR
            // pure (aucune clé IA requise) : disponible pour tous les tenants,
            // même sans copilote. Ne mute rien ; même permission que suggest.
            'eweb_ai_segment_count' => [
                'path'       => '/ai/segment/count',
                'controller' => 'MauticPlugin\EwebAiBundle\Controller\AiSegmentController::countAction',
                'method'     => 'POST',
            ],
            // Assistant d'aide (panneau « Assistant IA » de la barre haute) :
            // questions-réponses sur l'outil. Ne mute rien, ne lit aucune
            // donnée du compte.
            // Chantier C : des suggestions d'objets aux VARIANTES A/B
            // natives. CRÉE des entités — garde celle de l'écran natif
            // abtest (email:emails:create + accès au parent).
            'eweb_ai_email_abtest' => [
                'path'       => '/ai/email/abtest',
                'controller' => 'MauticPlugin\EwebAiBundle\Controller\AiAbTestController::createAction',
                'method'     => 'POST',
            ],
            // Lot 4 : création d'un FORMULAIRE depuis l'assistant. CRÉE une
            // entité (précédent : abtest) — garde form:forms:create, et le
            // formulaire naît DÉPUBLIÉ (relecture avant publication).
            'eweb_ai_form_create' => [
                'path'       => '/ai/form/create',
                'controller' => 'MauticPlugin\EwebAiBundle\Controller\AiFormController::createAction',
                'method'     => 'POST',
            ],
            'eweb_ai_assist' => [
                'path'       => '/ai/assist',
                'controller' => 'MauticPlugin\EwebAiBundle\Controller\AiController::assistAction',
                'method'     => 'POST',
            ],
        ],
    ],
];
