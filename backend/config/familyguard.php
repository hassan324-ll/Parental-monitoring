<?php

return [
    // Internal development gate only: no environment switch or public activation route.
    'device_activation_enabled' => false,
    // Opt in only after deployment schema, scheduler and cache-lock review.
    'sharing_retention_enabled' => env('FAMILYGUARD_SHARING_RETENTION_ENABLED', false),
];
