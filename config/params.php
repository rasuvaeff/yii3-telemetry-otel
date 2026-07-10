<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-telemetry-otel' => [
        // Standard OTel kill switch: OTEL_SDK_DISABLED=true binds the no-op
        // NullTracerProvider — nothing is built, nothing is exported, no
        // error_log noise from an unreachable collector.
        'enabled' => !\in_array(strtolower((string) getenv('OTEL_SDK_DISABLED')), ['true', '1', 'on'], true),
        'service_name' => getenv('OTEL_SERVICE_NAME') ?: 'yii3-app',
        'endpoint' => getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4318',
        // OTLP/HTTP payload encoding, mapped from the standard
        // OTEL_EXPORTER_OTLP_PROTOCOL (http/protobuf | http/json). Some
        // receivers (e.g. Buggregator) only speak JSON cleanly.
        'content_type' => (getenv('OTEL_EXPORTER_OTLP_PROTOCOL') ?: 'http/protobuf') === 'http/json'
            ? 'application/json'
            : 'application/x-protobuf',
        'batch' => true,
        // Exact request paths OtelMiddleware skips — scrape/probe endpoints
        // (Prometheus polls /metrics every few seconds; tracing that is noise).
        'excluded_paths' => [],
        // url.query attribute on the root span (sensitive values masked).
        'capture_query' => true,
        // Opt-in: query/form/JSON-body params as http.request.param.* attributes
        // (sensitive keys masked, values truncated). Off by default — request
        // payloads may carry personal data; enable consciously.
        'capture_request_params' => false,
        // Registers a shutdown flush for the batch processor. Correct default
        // everywhere: on php-fpm it runs at request end (after
        // fastcgi_finish_request — batch-buffered spans would otherwise be LOST
        // with the worker); on long-running workers it runs once at process
        // exit. Set to false on RoadRunner/Swoole when flushing via SpanFlusher
        // on a timer / worker-stop hook instead.
        'register_shutdown_flush' => true,
    ],
];
