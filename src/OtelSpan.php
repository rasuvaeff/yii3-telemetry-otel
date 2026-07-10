<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface as OtelSpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\TraceContext;

/**
 * Adapts an OpenTelemetry span to the core {@see SpanInterface}. The core status
 * codes and W3C context map onto OTel field-for-field (see backing values), so
 * no lookup table is needed.
 *
 * @api
 */
final readonly class OtelSpan implements SpanInterface
{
    public function __construct(
        private OtelSpanInterface $span,
    ) {}

    #[\Override]
    public function setAttribute(string $key, bool|int|float|string|array|null $value): void
    {
        // An empty attribute key is invalid in OpenTelemetry; drop it silently.
        if ($key !== '') {
            $this->span->setAttribute($key, $value);
        }
    }

    #[\Override]
    public function updateName(string $name): void
    {
        if ($name !== '') {
            $this->span->updateName($name);
        }
    }

    #[\Override]
    public function setStatus(SpanStatusCode $code, ?string $description = null): void
    {
        $this->span->setStatus($code->value, $description);
    }

    #[\Override]
    public function addEvent(string $name, array $attributes = []): void
    {
        // An empty event name is invalid in OpenTelemetry; drop it silently.
        if ($name !== '') {
            $this->span->addEvent($name, $attributes);
        }
    }

    #[\Override]
    public function recordException(\Throwable $exception): void
    {
        $this->span->recordException($exception);
    }

    #[\Override]
    public function end(): void
    {
        $this->span->end();
    }

    #[\Override]
    public function isRecording(): bool
    {
        return $this->span->isRecording();
    }

    #[\Override]
    public function getTraceContext(): TraceContext
    {
        return $this->mapContext($this->span->getContext());
    }

    private function mapContext(SpanContextInterface $context): TraceContext
    {
        if (!$context->isValid()) {
            return TraceContext::invalid();
        }

        return new TraceContext(
            traceId: $context->getTraceId(),
            spanId: $context->getSpanId(),
            traceFlags: $context->getTraceFlags(),
            traceState: $context->getTraceState()?->toString() ?? '',
        );
    }
}
