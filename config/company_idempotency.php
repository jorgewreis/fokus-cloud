<?php

return [
    // An enabled setting alone does not expose the unfinished API contract.
    'enabled' => env('FOKUS_COMPANY_IDEMPOTENCY_ENABLED', false),
    // Experimental lifecycle is separate and remains disabled by default.
    'session_authority_lifecycle' => env('FOKUS_SESSION_AUTHORITY_LIFECYCLE', false),
    // No default lifetime: a reviewed, explicit policy is required before enabling.
    'session_authority_max_age_seconds' => env('FOKUS_SESSION_AUTHORITY_MAX_AGE_SECONDS'),
    'keyring_json' => env('FOKUS_COMPANY_IDEMPOTENCY_KEYRING'),
    'active_kid' => env('FOKUS_COMPANY_IDEMPOTENCY_ACTIVE_KID'),
    // Must be explicitly supplied, even for an empty list; never infer no revocations.
    'revoked_kids_json' => env('FOKUS_COMPANY_IDEMPOTENCY_REVOKED_KIDS'),
    'max_outstanding' => env('FOKUS_COMPANY_IDEMPOTENCY_MAX_OUTSTANDING'),
    'max_issues_per_window' => env('FOKUS_COMPANY_IDEMPOTENCY_MAX_ISSUES'),
    'issuance_window_seconds' => env('FOKUS_COMPANY_IDEMPOTENCY_WINDOW_SECONDS'),
    'token_lifetime_seconds' => env('FOKUS_COMPANY_IDEMPOTENCY_TOKEN_SECONDS'),
];
