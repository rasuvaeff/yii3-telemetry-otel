<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use Fiber;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\TraceContextInjector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TraceContextInjector::class)]
final class AsyncContextTest
{
    public function interleavedFibersKeepTheirOwnClientParentAndHeaders(): void
    {
        $exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(batch: false))->create($exporter);
        $tracer = (new OtelTracerProvider($provider))->getTracer();
        $injector = new TraceContextInjector();
        $factory = new Psr17Factory();
        $headers = [];
        $rootContext = Context::getCurrent();

        $makeRequest = static function (string $name) use ($tracer, $injector, $factory, &$headers, $rootContext): Fiber {
            return new Fiber(static function () use ($name, $tracer, $injector, $factory, &$headers, $rootContext): void {
                $scope = $rootContext->activate();

                try {
                    $tracer->trace('root.' . $name, static function () use ($name, $tracer, $injector, $factory, &$headers): void {
                        $tracer->trace('http.client.' . $name, static function () use ($name, $tracer, $injector, $factory, &$headers): void {
                            $request = $injector->inject($factory->createRequest('GET', 'https://downstream/' . $name));
                            $headers[$name] = [
                                $request->getHeaderLine('traceparent'),
                                $tracer->getContext()->spanId,
                            ];
                            Fiber::suspend();
                            Assert::same($tracer->getContext()->spanId, $headers[$name][1]);
                        });
                    });
                } finally {
                    $scope->detach();
                }
            });
        };

        $fiberA = $makeRequest('a');
        $fiberB = $makeRequest('b');
        $fiberA->start();
        $fiberB->start();

        Assert::string($headers['a'][0])->contains((string) $headers['a'][1]);
        Assert::string($headers['b'][0])->contains((string) $headers['b'][1]);
        Assert::notSame($headers['a'][1], $headers['b'][1]);

        $fiberA->resume();
        $fiberB->resume();

        Assert::false($tracer->getContext()->isValid());
        Assert::count($exporter->getSpans(), 4);
    }

    public function exceptionInFiberRestoresContextAfterThePromiseSettles(): void
    {
        $provider = (new OtelTracerProviderFactory(batch: false))->create(
            new InMemoryExporter(new \ArrayObject()),
        );
        $tracer = (new OtelTracerProvider($provider))->getTracer();
        $rootContext = Context::getCurrent();
        $fiber = new Fiber(static function () use ($tracer, $rootContext): void {
            $scope = $rootContext->activate();

            try {
                $tracer->trace('failing-request', static function (): never {
                    Fiber::suspend();

                    throw new \RuntimeException('downstream failed');
                });
            } finally {
                $scope->detach();
            }
        });

        $fiber->start();

        try {
            $fiber->resume();
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'downstream failed');
        }

        Assert::false($fiber->isSuspended());
        Assert::false($tracer->getContext()->isValid());
    }
}
