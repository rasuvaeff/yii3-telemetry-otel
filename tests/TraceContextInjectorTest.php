<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\TraceContextInjector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(TraceContextInjector::class)]
final class TraceContextInjectorTest
{
    private TracerInterface $tracer;
    private Psr17Factory $factory;
    private TraceContextInjector $injector;

    #[BeforeTest]
    public function setUp(): void
    {
        $provider = (new OtelTracerProviderFactory(batch: false))
            ->create(new InMemoryExporter(new \ArrayObject()));
        $this->tracer = (new OtelTracerProvider($provider))->getTracer();
        $this->factory = new Psr17Factory();
        $this->injector = new TraceContextInjector();
    }

    public function injectsTheActiveSpanContext(): void
    {
        $header = null;
        $activeSpanId = null;

        $this->tracer->trace('op', function () use (&$header, &$activeSpanId): void {
            $activeSpanId = $this->tracer->getContext()->spanId;
            $request = $this->injector->inject($this->factory->createRequest('GET', 'https://downstream/api'));
            $header = $request->getHeaderLine('traceparent');
        });

        Assert::string($header)->contains('00-');
        Assert::string($header)->contains((string) $activeSpanId);
    }

    public function leavesRequestUntouchedWithoutActiveSpan(): void
    {
        $request = $this->factory->createRequest('GET', 'https://downstream/api');

        $result = $this->injector->inject($request);

        Assert::false($result->hasHeader('traceparent'));
    }
}
