<?php

declare(strict_types=1);

// OTEL_EXPORTER_OTLP_TRACES_TIMEOUT, then OTEL_EXPORTER_OTLP_TIMEOUT: integer
// milliseconds per the OTel spec, converted to seconds. The first non-empty
// value wins; non-numeric or non-positive → the SDK default (10 s).
$otlpTimeout = static function (): float {
    foreach (['OTEL_EXPORTER_OTLP_TRACES_TIMEOUT', 'OTEL_EXPORTER_OTLP_TIMEOUT'] as $name) {
        $value = trim((string) getenv($name));
        if ($value === '') {
            continue;
        }

        return ctype_digit($value) && (int) $value > 0 ? (int) $value / 1000 : 10.0;
    }

    return 10.0;
};

return [
    'rasuvaeff/yii3-telemetry-otel' => [
        // Standard OTel kill switch: OTEL_SDK_DISABLED=true binds the no-op
        // NullTracerProvider — nothing is built, nothing is exported, no
        // error_log noise from an unreachable collector.
        'enabled' => !\in_array(strtolower((string) getenv('OTEL_SDK_DISABLED')), ['true', '1', 'on'], true),
        'service_name' => getenv('OTEL_SERVICE_NAME') ?: 'yii3-app',
        'endpoint' => getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4318',
        // OTLP/HTTP payload encoding, mapped from the standard
        // OTEL_EXPORTER_OTLP_PROTOCOL (http/protobuf | http/json). Use an
        // actual OTLP receiver such as the OTel Collector, Tempo, or Jaeger.
        'content_type' => (getenv('OTEL_EXPORTER_OTLP_PROTOCOL') ?: 'http/protobuf') === 'http/json'
            ? 'application/json'
            : 'application/x-protobuf',
        // Per-request OTLP export timeout, seconds (float). From
        // OTEL_EXPORTER_OTLP_TRACES_TIMEOUT / OTEL_EXPORTER_OTLP_TIMEOUT (ms).
        // Export is synchronous: for web use a local collector agent and 1–2 s.
        'timeout' => $otlpTimeout(),
        // Retries after the first failed export attempt (package-specific, no
        // OTel env var). 0 disables retries; the SDK default is 3.
        'max_retries' => 3,
        // Initial retry back-off, milliseconds (doubles per attempt).
        'retry_delay_ms' => 100,
        'batch' => true,
        // Batch processor controls. Null keeps the OpenTelemetry SDK defaults
        // and allows OTEL_BSP_* environment variables to apply.
        'max_queue_size' => null,
        'max_export_batch_size' => null,
        'scheduled_delay_ms' => null,
        'export_timeout_ms' => null,
        // true preserves the SDK default and may export synchronously from
        // span end; false defers export to SpanFlusher/shutdown.
        'auto_flush' => null,
        // Exact request paths OtelMiddleware skips — scrape/probe endpoints
        // (Prometheus polls /metrics every few seconds; tracing that is noise).
        'excluded_paths' => [],
        // Console commands ConsoleCommandSpanListener does NOT wrap in a root
        // span (long-running workers: one span for days is useless). Exact
        // names, or a prefix when the entry ends with `*` (e.g. 'outbox:*').
        'excluded_commands' => ['queue:listen', 'queue:listen-all'],
        // url.query attribute on the root span (sensitive values masked).
        'capture_query' => true,
        // Hard limits prevent an attacker-controlled query from becoming a
        // large span attribute. Set to 0 to skip query capture entirely.
        'max_query_bytes' => 4096,
        // Opt-in: query/form/JSON-body params as http.request.param.* attributes
        // (sensitive keys masked, values truncated). Off by default — request
        // payloads may carry personal data; enable consciously.
        'capture_request_params' => false,
        'max_request_params' => 50,
        'request_param_allowlist' => [],
        // Registers a shutdown flush for the batch processor. Correct default
        // everywhere: on php-fpm it runs at request end (after
        // fastcgi_finish_request — batch-buffered spans would otherwise be LOST
        // with the worker); on long-running workers it runs once at process
        // exit. Set to false on RoadRunner/Swoole when flushing via SpanFlusher
        // on a timer / worker-stop hook instead.
        'register_shutdown_flush' => true,
        // Send the response to the client BEFORE the shutdown flush
        // (fastcgi_finish_request) — otherwise the client waits for the OTLP
        // export round-trip. Disable only if other shutdown functions of your
        // app still write to the response body.
        'finish_request_before_flush' => true,
    ],
];
