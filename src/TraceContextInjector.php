<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\API\Trace\Span;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\Yii3Telemetry\TraceContextPropagator;

/**
 * Injects the currently active trace context into an outgoing client request
 * (the `traceparent` header), so a downstream service continues the same trace.
 * With no active span the request is returned untouched.
 *
 * @api
 */
final readonly class TraceContextInjector
{
    public function __construct(
        private TraceContextPropagator $propagator = new TraceContextPropagator(),
    ) {}

    public function inject(RequestInterface $request): RequestInterface
    {
        $context = (new OtelSpan(Span::getCurrent()))->getTraceContext();

        return $this->propagator->inject($context, $request);
    }
}
