<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests\Integration;

use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;
use Rasuvaeff\Yii3TelemetryOtel\SpanFlusher;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * Exercises a real OTLP round-trip against a running OpenTelemetry Collector.
 * Skipped unless `OTEL_COLLECTOR_HOST` is set (e.g. a CI service container).
 *
 * Run: `vendor/bin/testo --suite=Integration`
 */
#[Test]
#[CoversNothing]
final class OtelCollectorIntegrationTest
{
    public function exportsASpanToTheCollector(): void
    {
        $host = getenv('OTEL_COLLECTOR_HOST');

        if ($host === false || $host === '') {
            return;
        }

        $exporter = (new OtlpExporterFactory())->create('http://' . $host . ':4318');
        $sdkProvider = (new OtelTracerProviderFactory(serviceName: 'integration-test', batch: true))
            ->create($exporter);
        $tracer = (new OtelTracerProvider($sdkProvider))->getTracer();

        $tracer->trace('integration.span', static fn(): null => null);

        Assert::true((new SpanFlusher($sdkProvider))->flush());
    }
}
