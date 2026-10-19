<?php

return [
    // Opt in only after deployment schema, scheduler and cache-lock review.
    'sharing_retention_enabled' => env('FAMILYGUARD_SHARING_RETENTION_ENABLED', false),
];
