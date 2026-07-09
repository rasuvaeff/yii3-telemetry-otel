<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3Telemetry\TraceContextPropagator;

/**
 * Reads W3C Trace Context from an incoming server request (reusing the core
 * propagator, which reads PSR-7 headers case-insensitively) and returns an OTel
 * {@see ContextInterface} carrying the remote parent span — ready to activate so
 * the server root span becomes a child of the incoming trace.
 *
 * @api
 */
final readonly class TraceContextExtractor
{
    public function __construct(
        private TraceContextPropagator $propagator = new TraceContextPropagator(),
    ) {}

    public function extract(ServerRequestInterface $request): ContextInterface
    {
        $context = $this->propagator->extract($request);

        if (!$context->isValid()) {
            return Context::getCurrent();
        }

        $spanContext = SpanContext::createFromRemoteParent(
            $context->traceId,
            $context->spanId,
            $context->traceFlags,
            $context->traceState === '' ? null : new TraceState($context->traceState),
        );

        return Context::getCurrent()->withContextValue(Span::wrap($spanContext));
    }
}
