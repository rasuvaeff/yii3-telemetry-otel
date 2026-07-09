<?php

declare(strict_types=1);

use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;
use Rasuvaeff\Yii3TelemetryOtel\SpanFlusher;

require __DIR__ . '/../vendor/autoload.php';

// Build a real OTLP provider. Construction does NOT connect — spans are sent
// only when the batch exporter flushes (SpanFlusher::flush()). This script emits
// no span, so it runs offline; point it at a collector to actually export.
$endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4318';

$exporter = (new OtlpExporterFactory())->create($endpoint);
$sdkProvider = (new OtelTracerProviderFactory(serviceName: 'demo', batch: true))->create($exporter);
$tracer = (new OtelTracerProvider($sdkProvider))->getTracer();
$flusher = new SpanFlusher($sdkProvider);

echo "OTLP tracer ready — endpoint: {$endpoint}\n";
echo 'tracer: ' . $tracer::class . "\n";
echo 'flusher: ' . $flusher::class . "\n";
echo "In a long-running worker: emit spans via \$tracer->trace(...), then call\n";
echo "\$flusher->flush() on worker shutdown (never per request).\n";
