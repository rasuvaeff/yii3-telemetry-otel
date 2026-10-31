<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;
use Rasuvaeff\Yii3TelemetryOtel\ShutdownFlushHook;

/** @var array $params */

// The PSR-15 root-span middleware is web-only. Add it to the application's
// middleware stack (typically first, so it wraps the whole request).
// Excluded paths (scrape/probe endpoints) come from the package params.

$config = $params['rasuvaeff/yii3-telemetry-otel'];

return [
    OtelMiddleware::class => static function (ContainerInterface $container, TracerInterface $tracer) use ($config): OtelMiddleware {
        // The per-request shutdown flush (#29): persistent worker runtimes
        // (Rapira's HTTP worker, FrankenPHP worker mode) clear shutdown
        // functions with the request that registered them, so the middleware
        // re-registers the hook on every request. Resolving the hook pulls
        // the SDK provider chain — skipped entirely when the SDK is disabled
        // or the flush is off, keeping "nothing OTel is built" true.
        $flushEnabled = (bool) ($config['register_shutdown_flush'] ?? true)
            && (bool) ($config['enabled'] ?? true);

        return new OtelMiddleware(
            tracer: $tracer,
            excludedPaths: (array) ($config['excluded_paths'] ?? []),
            sampleRatio: (float) ($config['sample_ratio'] ?? 1.0),
            captureQuery: (bool) ($config['capture_query'] ?? true),
            captureRequestParams: (bool) ($config['capture_request_params'] ?? false),
            maxQueryBytes: (int) ($config['max_query_bytes'] ?? 4096),
            maxRequestParams: (int) ($config['max_request_params'] ?? 50),
            requestParamAllowlist: (array) ($config['request_param_allowlist'] ?? []),
            shutdownFlushHook: $flushEnabled ? $container->get(ShutdownFlushHook::class) : null,
        );
    },
];
