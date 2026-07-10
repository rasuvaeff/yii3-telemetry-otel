<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\TracerProviderInterface as OtelApiTracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as OtelSdkTracerProviderInterface;
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;

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
    ),

    OtelSdkTracerProviderInterface::class => static function (SpanExporterInterface $exporter) use ($params): OtelSdkTracerProviderInterface {
        $config = $params['rasuvaeff/yii3-telemetry-otel'];

        $provider = (new OtelTracerProviderFactory(
            serviceName: (string) $config['service_name'],
            batch: (bool) $config['batch'],
        ))->create($exporter);

        // `new TracerProvider(...)` registers NO automatic shutdown flush; on
        // php-fpm batch-buffered spans die with the worker without this hook.
        if ((bool) ($config['register_shutdown_flush'] ?? true)) {
            register_shutdown_function(static function () use ($provider): void {
                $provider->shutdown();
            });
        }

        return $provider;
    },

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
