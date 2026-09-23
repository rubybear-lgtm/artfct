<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Terms and privacy consent
    |--------------------------------------------------------------------------
    |
    | When `consent_required` is on, a signed-in user must accept the current
    | terms version before reaching the app. Bump `terms_version` whenever the
    | terms or privacy text changes materially and everyone is asked again.
    | The text in the app is a draft: have counsel review it before a public
    | launch.
    |
    */

    'consent_required' => (bool) env('LEGAL_CONSENT_REQUIRED', false),

    'terms_version' => env('LEGAL_TERMS_VERSION', '2026-09-20-draft'),

];
