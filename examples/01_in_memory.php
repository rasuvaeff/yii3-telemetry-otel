<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;

require __DIR__ . '/../vendor/autoload.php';

// An in-memory exporter needs no collector — ideal for inspecting spans locally.
$exporter = new InMemoryExporter(new ArrayObject());
$provider = (new OtelTracerProviderFactory(serviceName: 'demo', batch: false))->create($exporter);
$tracer = (new OtelTracerProvider($provider))->getTracer();

$tracer->trace(
    name: 'checkout.process',
    callback: static function (SpanInterface $span): void {
        $span->setAttribute('order.id', 'ORD-42');
    },
    attributes: ['user.id' => 'u-7'],
    traceKind: TraceKind::Internal,
);

foreach ($exporter->getSpans() as $span) {
    printf(
        "span=%s kind=%d trace=%s attributes=%s\n",
        $span->getName(),
        $span->getKind(),
        $span->getTraceId(),
        (string) json_encode($span->getAttributes()->toArray()),
    );
}
