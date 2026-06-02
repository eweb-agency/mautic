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
            'eweb_saas_api_refresh' => [
                'path'       => '/saas/v1/refresh',
                'controller' => 'MauticPlugin\EwebSaasBundle\Controller\Api\SaasStatsController::refreshAction',
                'method'     => 'POST',
            ],
        ],
    ],
];
