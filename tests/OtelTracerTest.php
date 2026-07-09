<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider as SdkTracerProvider;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelSpan;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracer;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OtelTracer::class)]
#[Covers(OtelSpan::class)]
#[Covers(OtelTracerProvider::class)]
#[Covers(OtelTracerProviderFactory::class)]
final class OtelTracerTest
{
    private InMemoryExporter $exporter;
    private TracerInterface $tracer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(serviceName: 'test-service', batch: false))
            ->create($this->exporter);
        $this->tracer = (new OtelTracerProvider($provider))->getTracer();
    }

    public function exportsFinishedSpanAndReturnsCallbackValue(): void
    {
        $result = $this->tracer->trace(
            name: 'checkout',
            callback: static function (SpanInterface $span): string {
                $span->setAttribute('order.id', 'ORD-1');

                return 'done';
            },
            attributes: ['user.id' => 'u-7'],
            traceKind: TraceKind::Server,
        );

        Assert::same($result, 'done');

        $span = $this->onlySpan();
        Assert::same($span->getName(), 'checkout');
        Assert::same($span->getKind(), TraceKind::Server->value);
        Assert::same($span->getStatus()->getCode(), SpanStatusCode::Unset->value);
        Assert::same($span->getAttributes()->get('user.id'), 'u-7');
        Assert::same($span->getAttributes()->get('order.id'), 'ORD-1');
        Assert::same($span->getResource()->getAttributes()->get('service.name'), 'test-service');
    }

    public function recordsExceptionSetsErrorStatusAndRethrows(): void
    {
        try {
            $this->tracer->trace('op', static function (): void {
                throw new \RuntimeException('kaboom');
            });
            Assert::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'kaboom');
        }

        $span = $this->onlySpan();
        Assert::same($span->getStatus()->getCode(), SpanStatusCode::Error->value);
        Assert::same($span->getStatus()->getDescription(), 'kaboom');
        Assert::count($span->getEvents(), 1);
    }

    public function updateNameAndStatusReachTheExportedSpan(): void
    {
        $this->tracer->trace('initial', static function (SpanInterface $span): void {
            $span->updateName('renamed');
            $span->setStatus(SpanStatusCode::Ok);
        });

        $span = $this->onlySpan();
        Assert::same($span->getName(), 'renamed');
        Assert::same($span->getStatus()->getCode(), SpanStatusCode::Ok->value);
    }

    public function nestedSpanInheritsTraceIdAndParent(): void
    {
        $this->tracer->trace('parent', function (): void {
            $this->tracer->trace('child', static function (): void {});
        });

        $spans = $this->exporter->getSpans();
        Assert::count($spans, 2);

        // Children are exported before parents (finally order): [child, parent].
        [$child, $parent] = $spans;
        Assert::same($child->getTraceId(), $parent->getTraceId());
        Assert::same($child->getParentSpanId(), $parent->getSpanId());
    }

    public function scopedSpanIsCurrentInsideCallback(): void
    {
        $this->tracer->trace('op', function (SpanInterface $span): void {
            Assert::true($this->tracer->currentSpan()->isRecording());
            Assert::same(
                $this->tracer->currentSpan()->getTraceContext()->spanId,
                $span->getTraceContext()->spanId,
            );
        });

        Assert::false($this->tracer->currentSpan()->isRecording());
        Assert::false($this->tracer->getContext()->isValid());
    }

    public function unscopedSpanIsNotCurrent(): void
    {
        $this->tracer->trace('op', function (): void {
            Assert::false($this->tracer->currentSpan()->isRecording());
        }, scoped: false);

        // Still exported even though it never became current.
        Assert::count($this->exporter->getSpans(), 1);
    }

    public function droppedSpanStillRunsCallbackAsNonRecording(): void
    {
        $exporter = new InMemoryExporter(new \ArrayObject());
        $sdk = new SdkTracerProvider(new SimpleSpanProcessor($exporter), new AlwaysOffSampler());
        $tracer = (new OtelTracerProvider($sdk))->getTracer();

        $recording = null;
        $result = $tracer->trace('op', static function (SpanInterface $span) use (&$recording): int {
            $recording = $span->isRecording();

            return 99;
        });

        Assert::same($result, 99);
        Assert::false($recording);
        Assert::count($exporter->getSpans(), 0);
    }

    public function emptySpanNameFallsBackToUnnamed(): void
    {
        $this->tracer->trace('', static fn(): null => null);

        Assert::same($this->onlySpan()->getName(), 'unnamed');
    }

    public function emptyAttributeKeyOrRenameIsIgnored(): void
    {
        $this->tracer->trace('original', static function (SpanInterface $span): void {
            $span->setAttribute('', 'dropped');
            $span->setAttribute('kept', 'value');
            $span->updateName('');
        });

        $span = $this->onlySpan();
        Assert::same($span->getName(), 'original');
        Assert::false($span->getAttributes()->has(''));
        Assert::same($span->getAttributes()->get('kept'), 'value');
    }

    public function providerUsesGivenNameAsInstrumentationScope(): void
    {
        $provider = (new OtelTracerProviderFactory(batch: false))->create($this->exporter);
        $tracer = (new OtelTracerProvider($provider))->getTracer('custom.scope');

        Assert::instanceOf($tracer, OtelTracer::class);

        $tracer->trace('op', static fn(): null => null);
        Assert::same($this->onlySpan()->getInstrumentationScope()->getName(), 'custom.scope');
    }

    public function startSpanExportsADetachedSpanWhenEnded(): void
    {
        $span = $this->tracer->startSpan('manual', ['k' => 'v'], TraceKind::Client);

        Assert::true($span->isRecording());
        // Not activated: it does not become the current span.
        Assert::false($this->tracer->currentSpan()->getTraceContext()->isValid());
        // Not exported until the caller ends it.
        Assert::count($this->exporter->getSpans(), 0);

        $span->end();

        $exported = $this->onlySpan();
        Assert::same($exported->getName(), 'manual');
        Assert::same($exported->getKind(), TraceKind::Client->value);
        Assert::same($exported->getAttributes()->get('k'), 'v');
    }

    public function mapsTraceStateFromTheSpanContext(): void
    {
        $spanContext = SpanContext::create(
            '0af7651916cd43dd8448eb211c80319c',
            'b7ad6b7169203331',
            TraceFlags::SAMPLED,
            new TraceState('vendor=value'),
        );

        $context = (new OtelSpan(Span::wrap($spanContext)))->getTraceContext();

        Assert::same($context->traceState, 'vendor=value');
        Assert::same($context->traceId, '0af7651916cd43dd8448eb211c80319c');
    }

    private function onlySpan(): \OpenTelemetry\SDK\Trace\SpanDataInterface
    {
        $spans = $this->exporter->getSpans();
        Assert::count($spans, 1);

        return $spans[0];
    }
}
