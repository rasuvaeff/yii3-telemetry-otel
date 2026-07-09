<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\SpanFlusher;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SpanFlusher::class)]
final class SpanFlusherTest
{
    public function flushDrainsBatchedSpansToTheExporter(): void
    {
        $exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(batch: true))->create($exporter);
        $tracer = (new OtelTracerProvider($provider))->getTracer();

        $tracer->trace('op', static fn(): null => null);

        Assert::true((new SpanFlusher($provider))->flush());
        Assert::count($exporter->getSpans(), 1);
    }
}
