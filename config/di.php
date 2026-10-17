<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\TracerProviderInterface as OtelApiTracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as OtelSdkTracerProviderInterface;
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;
use Rasuvaeff\Yii3TelemetryOtel\ConsoleCommandSpanListener;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;
use Rasuvaeff\Yii3TelemetryOtel\ShutdownFlushHook;

/** @var array $params */

// The backend owns exactly ONE swappable key: the core TracerProviderInterface.
// It must NOT bind Tracer / core TracerInterface — the core binds those; binding
// them here would collide with the core (`yiisoft/config` "Duplicate key").
//
// With `enabled` off (OTEL_SDK_DISABLED=true) the provider key resolves to the
// no-op NullTracerProvider WITHOUT dependencies, so the exporter / SDK provider
// factories below are never invoked — nothing OTel is built.
return [
    SpanExporterInterface::class => static fn (OtlpExporterFactory $factory): SpanExporterInterface => $factory->create(
        (string) $params['rasuvaeff/yii3-telemetry-otel']['endpoint'],
        (string) ($params['rasuvaeff/yii3-telemetry-otel']['content_type'] ?? 'application/x-protobuf'),
        (float) ($params['rasuvaeff/yii3-telemetry-otel']['timeout'] ?? OtlpExporterFactory::DEFAULT_TIMEOUT),
        (int) ($params['rasuvaeff/yii3-telemetry-otel']['max_retries'] ?? OtlpExporterFactory::DEFAULT_MAX_RETRIES),
        (int) ($params['rasuvaeff/yii3-telemetry-otel']['retry_delay_ms'] ?? OtlpExporterFactory::DEFAULT_RETRY_DELAY_MS),
    ),

    // Console listener (registered app-side in the console events config).
    // Excluded commands (long-running workers) get no root span.
    ConsoleCommandSpanListener::class => [
        'class' => ConsoleCommandSpanListener::class,
        '__construct()' => [
            'excludedCommands' => array_values(array_map(
                strval(...),
                (array) ($params['rasuvaeff/yii3-telemetry-otel']['excluded_commands'] ?? ['queue:listen', 'queue:listen-all']),
            )),
        ],
    ],

    OtelSdkTracerProviderInterface::class => static function (SpanExporterInterface $exporter) use ($params): OtelSdkTracerProviderInterface {
        $config = $params['rasuvaeff/yii3-telemetry-otel'];

        $provider = (new OtelTracerProviderFactory(
            serviceName: (string) $config['service_name'],
            batch: (bool) $config['batch'],
            maxQueueSize: isset($config['max_queue_size']) ? (int) $config['max_queue_size'] : null,
            scheduledDelayMillis: isset($config['scheduled_delay_ms']) ? (int) $config['scheduled_delay_ms'] : null,
            exportTimeoutMillis: isset($config['export_timeout_ms']) ? (int) $config['export_timeout_ms'] : null,
            maxExportBatchSize: isset($config['max_export_batch_size']) ? (int) $config['max_export_batch_size'] : null,
            autoFlush: isset($config['auto_flush']) ? (bool) $config['auto_flush'] : null,
        ))->create($exporter);

        // `new TracerProvider(...)` registers NO automatic shutdown flush; on
        // php-fpm batch-buffered spans die with the worker without this hook.
        // fastcgi_finish_request() first: without it the client WAITS for the
        // OTLP export round-trip (measured ~+100ms per request) — Yii3's SAPI
        // emitter does not finish the request itself.
        //
        // forceFlush(), NOT shutdown(): shutdown functions run after EVERY
        // request on per-request-shutdown runtimes (Rapira's HTTP worker,
        // FrankenPHP worker mode), and shutdown() closes the span processor
        // ($this->closed = true) — every span of every later request is
        // silently dropped. forceFlush() drains the buffer and keeps the
        // provider usable; on php-fpm the process recycles either way, so
        // forceFlush() is the safe universal choice.
        //
        // This boot-time registration covers the console commands and apps
        // that run no OtelMiddleware: on CLI shutdown functions run at process
        // exit — exactly right. On persistent web workers it fires after the
        // first request only (the runtime clears the functions with the
        // request that registered them, #29) — OtelMiddleware re-registers
        // the flush per request, which is the web half of the guarantee.
        if ((bool) ($config['register_shutdown_flush'] ?? true)) {
            $finishRequest = (bool) ($config['finish_request_before_flush'] ?? true);

            register_shutdown_function(new ShutdownFlushHook(
                provider: $provider,
                finishRequest: $finishRequest,
            ));
        }

        return $provider;
    },

    // The per-request flush OtelMiddleware registers on every request (#29).
    // Resolving it is lazy: with `enabled` off nothing requests it, so no
    // exporter, no SDK — the di-web wiring gates on both params.
    ShutdownFlushHook::class => static fn (OtelSdkTracerProviderInterface $provider): ShutdownFlushHook => new ShutdownFlushHook(
        provider: $provider,
        finishRequest: (bool) ($params['rasuvaeff/yii3-telemetry-otel']['finish_request_before_flush'] ?? true),
    ),

    // The SDK provider also satisfies the OTel API provider consumed by
    // OtelTracerProvider and ConsoleCommandSpanListener. With `enabled` off it
    // resolves to the OTel no-op provider, so API-level consumers (the console
    // listener) go silent too without building the SDK.
    OtelApiTracerProviderInterface::class => (bool) ($params['rasuvaeff/yii3-telemetry-otel']['enabled'] ?? true)
        ? OtelSdkTracerProviderInterface::class
        : static fn (): OtelApiTracerProviderInterface => new \OpenTelemetry\API\Trace\NoopTracerProvider(),

    TracerProviderInterface::class => (bool) ($params['rasuvaeff/yii3-telemetry-otel']['enabled'] ?? true)
        ? static fn (OtelApiTracerProviderInterface $provider): TracerProviderInterface => new OtelTracerProvider($provider)
        : static fn (): TracerProviderInterface => new NullTracerProvider(),
];
