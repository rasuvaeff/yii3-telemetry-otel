<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Benchmarks;

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\API\Trace\TracerInterface as OtelTracerInterface;
use OpenTelemetry\SDK\Trace\Behavior\SpanExporterTrait;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Rasuvaeff\Yii3Telemetry\NullTracer;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\TraceContextExtractor;
use Testo\Bench;

final class TracingBench
{
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private static ?TracerInterface $tracer = null;
    private static ?OtelTracerInterface $otelTracer = null;
    private static ?TracerInterface $nullTracer = null;

    #[Bench(
        callables: [
            'sdk' => [self::class, 'sdkTraceSpan'],
            'noop' => [self::class, 'noopTraceSpan'],
        ],
        calls: 2_000,
        iterations: 5,
        tolerance: \INF,
    )]
    public static function traceSpan(): mixed
    {
        return self::tracer()->trace('op', static fn (): null => null);
    }

    public static function sdkTraceSpan(): mixed
    {
        $span = self::otelTracer()->spanBuilder('op')->startSpan();
        $span->end();

        return null;
    }

    public static function noopTraceSpan(): mixed
    {
        return self::nullTracer()->trace('op', static fn (): null => null);
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
                ->create(new NonRetainingExporter());
            self::$tracer = (new OtelTracerProvider($provider))->getTracer();
            self::$otelTracer = $provider->getTracer('benchmark');
            self::$nullTracer = NullTracer::instance();
        }

        return self::$tracer;
    }

    private static function otelTracer(): OtelTracerInterface
    {
        self::tracer();

        return self::$otelTracer;
    }

    private static function nullTracer(): TracerInterface
    {
        self::tracer();

        return self::$nullTracer;
    }
}

/** Exporter that exercises span processing without retaining benchmark data. */
final class NonRetainingExporter implements SpanExporterInterface
{
    use SpanExporterTrait;

    #[\Override]
    protected function doExport(iterable $spans): bool
    {
        foreach ($spans as $_span) {
        }

        return true;
    }
}
