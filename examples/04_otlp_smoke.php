<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;
use Rasuvaeff\Yii3TelemetryOtel\SpanFlusher;

require __DIR__ . '/../vendor/autoload.php';

$endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4318';
$exporter = (new OtlpExporterFactory())->create($endpoint);
$sdkProvider = (new OtelTracerProviderFactory(serviceName: 'yii3-telemetry-otel-smoke', batch: true))->create($exporter);
$tracer = (new OtelTracerProvider($sdkProvider))->getTracer();

$tracer->trace(
    'yii3-telemetry-otel.smoke',
    static function (SpanInterface $span): void {
        $span->setAttribute('smoke.id', bin2hex(random_bytes(8)));
    },
);

if (!(new SpanFlusher($sdkProvider))->flush()) {
    fwrite(STDERR, "OTLP flush failed\n");

    exit(1);
}

echo "Span exported; verify it in Tempo or Grafana\n";
