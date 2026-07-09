<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface as OtelTracerInterface;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\TraceContext;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\TracerInterface;

/**
 * Adapts an OpenTelemetry tracer to the core {@see TracerInterface}, honouring
 * the frozen `trace()` contract: on success the span keeps its status (Unset);
 * on a thrown exception it records the exception, sets status Error, ends, and
 * re-throws. The active OTel context supplies the parent for nested spans.
 *
 * @api
 */
final readonly class OtelTracer implements TracerInterface
{
    public function __construct(
        private OtelTracerInterface $tracer,
    ) {}

    /**
     * @template T
     *
     * @param callable(SpanInterface): T $callback
     * @param array<string, bool|int|float|string|array|null> $attributes
     *
     * @return T
     */
    #[\Override]
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        bool $scoped = true,
        TraceKind $traceKind = TraceKind::Internal,
    ): mixed {
        $builder = $this->tracer->spanBuilder($name === '' ? 'unnamed' : $name)->setSpanKind($traceKind->value);

        foreach ($attributes as $key => $value) {
            $builder->setAttribute($key, $value);
        }

        $span = $builder->startSpan();
        $scope = $scoped ? $span->activate() : null;

        try {
            return $callback(new OtelSpan($span));
        } catch (\Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $scope?->detach();
            $span->end();
        }
    }

    /**
     * @param array<string, bool|int|float|string|array|null> $attributes
     */
    #[\Override]
    public function startSpan(
        string $name,
        array $attributes = [],
        TraceKind $traceKind = TraceKind::Internal,
    ): SpanInterface {
        $builder = $this->tracer->spanBuilder($name === '' ? 'unnamed' : $name)->setSpanKind($traceKind->value);

        foreach ($attributes as $key => $value) {
            $builder->setAttribute($key, $value);
        }

        // Not activated: the caller ends it; the parent is the active OTel context.
        return new OtelSpan($builder->startSpan());
    }

    #[\Override]
    public function currentSpan(): SpanInterface
    {
        return new OtelSpan(Span::getCurrent());
    }

    #[\Override]
    public function getContext(): TraceContext
    {
        return (new OtelSpan(Span::getCurrent()))->getTraceContext();
    }
}
