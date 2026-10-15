<?php

return ['paths' => ['api/*'], 'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'], 'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:4200')], 'allowed_origins_patterns' => [], 'allowed_headers' => ['Content-Type', 'Authorization', 'Accept'], 'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => false];
