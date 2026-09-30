<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Provider (SP) Configuration
    |--------------------------------------------------------------------------
    |
    | Settings that describe your application to MPass. The entityId uniquely
    | identifies your SP; it is used by MPass to look up your registration.
    |
    */

    'sp' => [
        'entityId' => env('APP_URL'),

        'assertionConsumerService' => [
            'url'     => env('APP_URL') . '/auth/mpass/acs',
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],

        'singleLogoutService' => [
            'url'     => env('APP_URL') . '/auth/mpass/sls',
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],

        'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified',

        /*
        | Paths are relative to storage/app/. You may also pass the raw PEM
        | string directly instead of a file path — both are accepted.
        */
        'x509cert'   => env('MPASS_SP_CERT', 'certs/sp.crt'),
        'privateKey' => env('MPASS_SP_KEY',  'certs/sp.key'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity Provider (MPass IdP) Configuration
    |--------------------------------------------------------------------------
    |
    | MPass environments:
    |   Testing    SSO: https://mpass.staging.egov.md/login/saml
    |              SLO: https://mpass.staging.egov.md/logout/saml
    |   Production SSO: https://mpass.gov.md/login/saml
    |              SLO: https://mpass.gov.md/logout/saml
    |
    */

    'idp' => [
        'entityId' => env('MPASS_IDP_ENTITY_ID', 'https://mpass.staging.egov.md'),

        'singleSignOnService' => [
            'url'     => env('MPASS_SSO_URL', 'https://mpass.staging.egov.md/login/saml'),
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],

        'singleLogoutService' => [
            'url'     => env('MPASS_SLO_URL', 'https://mpass.staging.egov.md/logout/saml'),
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],

        // Public certificate provided by MPass (PEM string without headers).
        'x509cert' => env('MPASS_IDP_CERT', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Controls which messages are signed and which assertions must be signed
    | or encrypted. The defaults match MPass integration requirements.
    |
    */

    'security' => [
        'authnRequestsSigned'     => true,
        'logoutRequestSigned'     => true,
        'logoutResponseSigned'    => true,
        'wantAssertionsSigned'    => false,
        'wantAssertionsEncrypted' => false,
        'wantNameIdEncrypted'     => false,
        'signMetadata'            => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Routes
    |--------------------------------------------------------------------------
    |
    | The prefix and middleware applied to the package's built-in routes.
    | Set 'enabled' to false if you prefer to define your own routes.
    |
    */

    'routes' => [
        'enabled'    => true,
        'prefix'     => 'auth/mpass',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect Targets
    |--------------------------------------------------------------------------
    |
    | Where to send the user after each authentication outcome. These are
    | used only by the default controller; override them in your own if needed.
    |
    */

    'redirects' => [
        'after_login'  => '/',
        'after_logout' => '/',
        'on_failure'   => '/login',
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Keys
    |--------------------------------------------------------------------------
    |
    | Keys under which MPass session data (NameID and its Format/qualifiers,
    | SessionIndex) are stored
    | in the Laravel session. You may change them to avoid conflicts.
    |
    */

    'session' => [
        'name_id'       => 'mpass_name_id',
        'session_index' => 'mpass_session_index',
        'name_id_format' => 'mpass_name_id_format',
        'name_id_name_qualifier' => 'mpass_name_id_name_qualifier',
        'name_id_sp_name_qualifier' => 'mpass_name_id_sp_name_qualifier',
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the underlying onelogin/php-saml library will output
    | extra diagnostic information. Disable in production.
    |
    */

    'debug' => env('APP_DEBUG', false),

];
