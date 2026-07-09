<?php

declare(strict_types=1);

use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;

// The PSR-15 root-span middleware is web-only. Add it to the application's
// middleware stack (typically first, so it wraps the whole request).
return [
    OtelMiddleware::class => OtelMiddleware::class,
];
