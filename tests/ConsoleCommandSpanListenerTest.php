<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\ConsoleCommandSpanListener;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Yii\Console\Event\ApplicationShutdown;
use Yiisoft\Yii\Console\Event\ApplicationStartup;

#[Test]
#[Covers(ConsoleCommandSpanListener::class)]
final class ConsoleCommandSpanListenerTest
{
    private InMemoryExporter $exporter;
    private ConsoleCommandSpanListener $listener;
    private TracerInterface $tracer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->exporter = new InMemoryExporter(new \ArrayObject());
        $sdkProvider = (new OtelTracerProviderFactory(serviceName: 'test', batch: false))->create($this->exporter);
        $this->listener = new ConsoleCommandSpanListener($sdkProvider);
        $this->tracer = (new OtelTracerProvider($sdkProvider))->getTracer();
    }

    public function bracketsTheCommandInARootSpanWithChildren(): void
    {
        $this->listener->onStartup(new ApplicationStartup('audit-log:transfer'));

        // Instrumentation running inside the command (a DB profiler span).
        $span = $this->tracer->startSpan('db.query');
        $span->end();

        $this->listener->onShutdown(new ApplicationShutdown(0));

        $spans = $this->exporter->getSpans();
        Assert::count($spans, 2);

        [$child, $root] = $spans;
        Assert::same($root->getName(), 'console audit-log:transfer');
        Assert::same($root->getParentSpanId(), '0000000000000000');
        Assert::same($root->getAttributes()->get('process.exit.code'), 0);
        Assert::same($root->getStatus()->getCode(), 'Unset');

        // The db span joined the command's trace instead of starting its own.
        Assert::same($child->getName(), 'db.query');
        Assert::same($child->getTraceId(), $root->getTraceId());
        Assert::same($child->getParentSpanId(), $root->getSpanId());
    }

    public function nonZeroExitCodeMarksTheSpanAsError(): void
    {
        $this->listener->onStartup(new ApplicationStartup('failing:command'));
        $this->listener->onShutdown(new ApplicationShutdown(1));

        $root = $this->exporter->getSpans()[0];
        Assert::same($root->getStatus()->getCode(), 'Error');
        Assert::same($root->getStatus()->getDescription(), 'exit code 1');
        Assert::same($root->getAttributes()->get('process.exit.code'), 1);
    }

    public function missingCommandNameFallsBackToDefault(): void
    {
        $this->listener->onStartup(new ApplicationStartup(null));
        $this->listener->onShutdown(new ApplicationShutdown(0));

        Assert::same($this->exporter->getSpans()[0]->getName(), 'console (default)');
    }

    public function shutdownWithoutStartupIsSafe(): void
    {
        $this->listener->onShutdown(new ApplicationShutdown(0));

        Assert::count($this->exporter->getSpans(), 0);
    }

    public function contextDoesNotLeakAfterShutdown(): void
    {
        $this->listener->onStartup(new ApplicationStartup('cmd'));
        $this->listener->onShutdown(new ApplicationShutdown(0));

        Assert::false($this->tracer->getContext()->isValid());
    }
}
