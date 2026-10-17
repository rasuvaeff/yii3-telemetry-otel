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

    public function autoFlushFalseDefersExportUntilManualFlush(): void
    {
        $exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(
            batch: true,
            maxQueueSize: 4,
            maxExportBatchSize: 2,
            autoFlush: false,
        ))->create($exporter);
        $tracer = (new OtelTracerProvider($provider))->getTracer();

        $tracer->trace('op', static fn(): null => null);

        Assert::count($exporter->getSpans(), 0);
        Assert::true((new SpanFlusher($provider))->flush());
        Assert::count($exporter->getSpans(), 1);
    }

    public function autoFlushExportsEndedSpansSynchronously(): void
    {
        $exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(
            batch: true,
            maxQueueSize: 4,
            maxExportBatchSize: 2,
            autoFlush: true,
        ))->create($exporter);
        $tracer = (new OtelTracerProvider($provider))->getTracer();

        $tracer->trace('first', static fn(): null => null);
        $tracer->trace('second', static fn(): null => null);
        Assert::count($exporter->getSpans(), 2);
    }

    public function queueOverflowDropsSpansUntilTheQueueIsFlushed(): void
    {
        $exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(
            batch: true,
            maxQueueSize: 1,
            maxExportBatchSize: 1,
            autoFlush: false,
        ))->create($exporter);
        $tracer = (new OtelTracerProvider($provider))->getTracer();

        $tracer->trace('first', static fn(): null => null);
        $tracer->trace('dropped', static fn(): null => null);

        Assert::true((new SpanFlusher($provider))->flush());
        Assert::count($exporter->getSpans(), 1);
    }
}
