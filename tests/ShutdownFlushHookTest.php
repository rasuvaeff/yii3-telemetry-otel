<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProvider as SdkTracerProvider;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\ShutdownFlushHook;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

require __DIR__ . '/Support/FinishRequestStub.php';

/**
 * Uses `batch: true` (the production default) — the bug #26 reproduces with
 * the batch processor closed by a `shutdown()` hook and is invisible with
 * `SimpleSpanProcessor` semantics alone.
 */
#[Test]
#[Covers(ShutdownFlushHook::class)]
final class ShutdownFlushHookTest
{
    public static int $fastcgiCalls = 0;

    private InMemoryExporter $exporter;
    private SdkTracerProvider $sdkProvider;
    private TracerInterface $tracer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->exporter = new InMemoryExporter(new \ArrayObject());
        $this->sdkProvider = (new OtelTracerProviderFactory(serviceName: 'test-service', batch: true))
            ->create($this->exporter);
        $this->tracer = (new OtelTracerProvider($this->sdkProvider))->getTracer();
    }

    /**
     * The #26 regression: shutdown functions run after EVERY request on
     * per-request-shutdown runtimes (Rapira HTTP worker, FrankenPHP worker
     * mode). Calling the registered hook twice with spans in between must
     * export the second batch too — a hook that called shutdown() left the
     * span processor closed and dropped it silently.
     */
    public function secondHookCallStillExportsTheNextBatch(): void
    {
        $hook = new ShutdownFlushHook($this->sdkProvider, finishRequest: false);

        $this->tracer->trace('first', static fn(): null => null);

        // Buffered by the batch processor until the hook flushes it.
        Assert::count($this->exporter->getSpans(), 0);

        $fastcgiCalls = self::$fastcgiCalls;
        Assert::true($hook());
        Assert::count($this->exporter->getSpans(), 1);
        Assert::same($this->exporter->getSpans()[0]->getName(), 'first');

        // finishRequest: false — the stub engine function must not run.
        Assert::same(self::$fastcgiCalls, $fastcgiCalls);

        $this->tracer->trace('second', static fn(): null => null);
        Assert::true($hook());

        $spans = $this->exporter->getSpans();
        Assert::count($spans, 2);
        Assert::same($spans[1]->getName(), 'second');

        // forceFlush() keeps the provider usable after the hook calls.
        $this->tracer->trace('third', static fn(): null => null);
        Assert::true($hook());
        Assert::count($this->exporter->getSpans(), 3);
    }

    public function hookFinishRequestsBeforeFlushingByDefault(): void
    {
        $hook = new ShutdownFlushHook($this->sdkProvider);

        $this->tracer->trace('op', static fn(): null => null);

        $fastcgiCalls = self::$fastcgiCalls;
        Assert::true($hook());
        Assert::same(self::$fastcgiCalls, $fastcgiCalls + 1);

        // The flush still happened.
        Assert::count($this->exporter->getSpans(), 1);
    }

    /**
     * A hook that closed the provider would make forceFlush() return false on
     * the second batch — assert the returned flag explicitly after a second
     * invocation.
     */
    public function hookReturnsTrueOnEveryFlush(): void
    {
        $hook = new ShutdownFlushHook($this->sdkProvider, finishRequest: false);

        $this->tracer->trace('a', static fn(): null => null);
        Assert::true($hook());

        $this->tracer->trace('b', static fn(): null => null);
        Assert::true($hook());
    }
}
