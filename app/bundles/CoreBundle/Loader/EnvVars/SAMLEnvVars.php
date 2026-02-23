<?php

namespace Mautic\CoreBundle\Loader\EnvVars;

use Symfony\Component\HttpFoundation\ParameterBag;

class SAMLEnvVars implements EnvVarsInterface
{
    public static function load(ParameterBag $config, ParameterBag $defaultConfig, ParameterBag $envVars): void
    {
        // Try to get from local.php first, fallback to env var
        $entityId = $config->get('saml_idp_entity_id') ?: getenv('MAUTIC_SAML_IDP_ENTITY_ID');

        if ($entityId) {
            $samlEntityId = $entityId;
        } elseif ($siteUrl = $config->get('site_url')) {
            $parts        = parse_url($siteUrl);
            $scheme       = !empty($parts['scheme']) ? $parts['scheme'] : 'http';
            $samlEntityId = $scheme.'://'.$parts['host'];
        } else {
            $samlEntityId = 'mautic';
        }

        $envVars->set('MAUTIC_SAML_ENTITY_ID', $samlEntityId);

        // Read saml_idp_metadata from local.php OR environment variable
        $samlMetadata = $config->get('saml_idp_metadata') ?: getenv('MAUTIC_SAML_IDP_METADATA');
        $samlEnabled  = (bool) $samlMetadata;
        $envVars->set('MAUTIC_SAML_ENABLED', $samlEnabled);

        $envVars->set('MAUTIC_SAML_LOGIN_PATH', '/s/saml/login');
        $envVars->set('MAUTIC_SAML_LOGIN_CHECK_PATH', '/s/saml/login_check');

        // Read from local.php OR environment variables
        $envVars->set('MAUTIC_SAML_REQUIRED_GROUP_ID', (string) ($config->get('saml_required_group_id') ?: getenv('MAUTIC_SAML_REQUIRED_GROUP_ID')));
        $envVars->set('MAUTIC_SAML_GROUP_ATTRIBUTE', $config->get('saml_group_attribute') ?: getenv('MAUTIC_SAML_GROUP_ATTRIBUTE') ?: 'Group');
        $envVars->set('MAUTIC_SAML_ROLE_ATTRIBUTE', $config->get('saml_role_attribute') ?: getenv('MAUTIC_SAML_ROLE_ATTRIBUTE') ?: 'Role');

        // Logout redirect: explicit env var, fallback to /s/login
        $envVars->set('MAUTIC_LOGOUT_REDIRECT_URL', getenv('MAUTIC_LOGOUT_REDIRECT_URL') ?: '/s/login');
        $envVars->set('MAUTIC_AUTH_FAILURE_REDIRECT_URL', getenv('MAUTIC_AUTH_FAILURE_REDIRECT_URL') ?: '/s/login');
    }
}
