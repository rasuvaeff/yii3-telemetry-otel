<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\TracerProviderInterface as OtelApiTracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as OtelSdkTracerProviderInterface;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;

/** @var array $params */

// The backend owns exactly ONE swappable key: the core TracerProviderInterface.
// It must NOT bind Tracer / core TracerInterface — the core binds those; binding
// them here would collide with the core (`yiisoft/config` "Duplicate key").
return [
    SpanExporterInterface::class => static fn (OtlpExporterFactory $factory): SpanExporterInterface => $factory->create(
        (string) $params['rasuvaeff/yii3-telemetry-otel']['endpoint'],
    ),

    OtelSdkTracerProviderInterface::class => static function (SpanExporterInterface $exporter) use ($params): OtelSdkTracerProviderInterface {
        $config = $params['rasuvaeff/yii3-telemetry-otel'];

        return (new OtelTracerProviderFactory(
            serviceName: (string) $config['service_name'],
            batch: (bool) $config['batch'],
        ))->create($exporter);
    },

    // The SDK provider also satisfies the OTel API provider consumed by OtelTracerProvider.
    OtelApiTracerProviderInterface::class => OtelSdkTracerProviderInterface::class,

    TracerProviderInterface::class => static fn (OtelApiTracerProviderInterface $provider): TracerProviderInterface => new OtelTracerProvider($provider),
];
