<?php

declare(strict_types=1);

use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;

/** @var array $params */

// The PSR-15 root-span middleware is web-only. Add it to the application's
// middleware stack (typically first, so it wraps the whole request).
// Excluded paths (scrape/probe endpoints) come from the package params.
return [
    OtelMiddleware::class => [
        'class' => OtelMiddleware::class,
        '__construct()' => [
            'excludedPaths' => (array) ($params['rasuvaeff/yii3-telemetry-otel']['excluded_paths'] ?? []),
            'captureQuery' => (bool) ($params['rasuvaeff/yii3-telemetry-otel']['capture_query'] ?? true),
            'captureRequestParams' => (bool) ($params['rasuvaeff/yii3-telemetry-otel']['capture_request_params'] ?? false),
        ],
    ],
];
