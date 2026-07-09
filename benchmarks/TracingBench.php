<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Benchmarks;

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\TraceContextExtractor;
use Testo\Bench;

final class TracingBench
{
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private static ?TracerInterface $tracer = null;

    #[Bench(
        callables: [
            'extract' => [self::class, 'extract'],
        ],
        calls: 2_000,
        iterations: 5,
    )]
    public static function traceSpan(): mixed
    {
        return self::tracer()->trace('op', static fn (): null => null);
    }

    public static function extract(): ContextInterface
    {
        $request = (new Psr17Factory())
            ->createServerRequest('GET', '/')
            ->withHeader('traceparent', self::TRACEPARENT);

        return (new TraceContextExtractor())->extract($request);
    }

    private static function tracer(): TracerInterface
    {
        if (self::$tracer === null) {
            $provider = (new OtelTracerProviderFactory(batch: false))
                ->create(new InMemoryExporter(new \ArrayObject()));
            self::$tracer = (new OtelTracerProvider($provider))->getTracer();
        }

        return self::$tracer;
    }
}
