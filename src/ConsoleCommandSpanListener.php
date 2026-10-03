<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\API\Trace\SpanInterface as OtelSpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface as OtelTracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use Yiisoft\Yii\Console\Event\ApplicationShutdown;
use Yiisoft\Yii\Console\Event\ApplicationStartup;

/**
 * Opens a root span around a `yiisoft/yii-console` command run, bracketing the
 * `ApplicationStartup` / `ApplicationShutdown` events. Without it every DB/HTTP
 * span from a console command (cron jobs!) becomes its own root-less trace and
 * floods the tracing backend with anonymous `db.query` traces.
 *
 * The span is named `console <command>` and ACTIVATED, so instrumentation spans
 * opened during the command become its children. A non-zero exit code marks the
 * span as an error.
 *
 * Register both methods app-side in the console events config:
 *
 * ```php
 * ApplicationStartup::class => [[ConsoleCommandSpanListener::class, 'onStartup']],
 * ApplicationShutdown::class => [[ConsoleCommandSpanListener::class, 'onShutdown']],
 * ```
 *
 * Long-running commands (`queue:listen`, daemons) must not get a root span: it
 * would live for days with every job nested under it. List them in
 * `$excludedCommands` — exact names, or a prefix when the entry ends with `*`
 * (e.g. `outbox:*`). Per-message spans belong in the consume code.
 *
 * Remember the flush: console processes exit after one command, so keep
 * `register_shutdown_flush` enabled (the default) or the span is lost.
 *
 * @api
 */
final class ConsoleCommandSpanListener
{
    private const string ATTR_EXIT_CODE = 'process.exit.code';

    private ?OtelSpanInterface $span = null;
    private ?ScopeInterface $scope = null;

    /**
     * @param list<string> $excludedCommands exact names or `prefix*` patterns
     */
    public function __construct(
        private readonly OtelTracerProviderInterface $provider,
        private readonly string $tracerName = 'rasuvaeff/yii3-telemetry-otel',
        private readonly array $excludedCommands = ['queue:listen', 'queue:listen-all'],
    ) {}

    public function onStartup(ApplicationStartup $event): void
    {
        $command = $event->commandName ?? '';

        if ($this->isExcluded($command)) {
            return;
        }

        $span = $this->provider
            ->getTracer($this->tracerName)
            ->spanBuilder('console ' . ($command === '' ? '(default)' : $command))
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        $this->scope = $span->activate();
        $this->span = $span;
    }

    public function onShutdown(ApplicationShutdown $event): void
    {
        if (!$this->span instanceof \OpenTelemetry\API\Trace\SpanInterface) {
            return;
        }

        $this->span->setAttribute(self::ATTR_EXIT_CODE, $event->getExitCode());

        if ($event->getExitCode() !== 0) {
            $this->span->setStatus(StatusCode::STATUS_ERROR, 'exit code ' . $event->getExitCode());
        }

        $this->scope?->detach();
        $this->span->end();

        $this->span = null;
        $this->scope = null;
    }

    private function isExcluded(string $command): bool
    {
        foreach ($this->excludedCommands as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($command, substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }

            if ($command === $pattern) {
                return true;
            }
        }

        return false;
    }
}
