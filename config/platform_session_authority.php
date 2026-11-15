<?php

return [
    // This remains opt-in until the complete company mutation/idempotency flow
    // uses PlatformSessionAuthority::withinMutation on the same connection.
    'enabled' => env('FOKUS_PLATFORM_SESSION_AUTHORITY_ENABLED', false),
];
