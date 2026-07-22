<?php

return [
    'name'        => 'Eweb SaaS Plugin',
    'description' => 'Plugin to help transofrming Mautic to a Saas.',
    'version'     => '1.0.0',
    'author'      => 'Eweb Agency',

    'routes' => [
        // API routes — automatically prefixed with /api by Mautic's RouteLoader
        // and protected by Mautic's native OAuth2 firewall (client_credentials).
        // No custom auth: saas-core authenticates with a Bearer access token
        // obtained from the instance's /oauth/v2/token endpoint.
        'api' => [
            'eweb_saas_api_health' => [
                'path'       => '/saas/v1/health',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::healthAction',
                'method'     => 'GET',
            ],
            'eweb_saas_api_stats' => [
                'path'       => '/saas/v1/stats',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::statsAction',
                'method'     => 'GET',
            ],
            'eweb_saas_api_campaigns' => [
                'path'       => '/saas/v1/campaigns',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::campaignsAction',
                'method'     => 'GET',
            ],
            'eweb_saas_api_email_series' => [
                'path'       => '/saas/v1/email-series',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::emailSeriesAction',
                'method'     => 'GET',
            ],
            'eweb_saas_api_scheduled_emails' => [
                'path'       => '/saas/v1/scheduled-emails',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::scheduledEmailsAction',
                'method'     => 'GET',
            ],
            'eweb_saas_api_segments' => [
                'path'       => '/saas/v1/segments',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::segmentsAction',
                'method'     => 'GET',
            ],
            'eweb_saas_api_forms' => [
                'path'       => '/saas/v1/forms',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::formsAction',
                'method'     => 'GET',
            ],
            'eweb_saas_api_contacts' => [
                'path'       => '/saas/v1/contacts',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::contactsAction',
                'method'     => 'GET',
            ],
            'eweb_saas_api_refresh' => [
                'path'       => '/saas/v1/refresh',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::refreshAction',
                'method'     => 'POST',
            ],
        ],
    ],
];
