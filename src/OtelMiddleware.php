<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\TracerInterface;

/**
 * PSR-15 middleware that opens a SERVER root span for each request. It extracts
 * the incoming W3C context (so the span continues a distributed trace), records
 * HTTP attributes, marks 5xx responses as errors, and always ends the span and
 * detaches the context — the anti-leak guarantee for long-running workers.
 *
 * Span naming follows the OTel HTTP semconv: `{method} {http.route}` when a
 * {@see RouteNameResolverInterface} is wired and a route matched, plain
 * `{method}` otherwise — never the raw URL path, which would create one span
 * name per user id and wreck operation search in the tracing backend. The raw
 * path is always available in the `url.path` attribute. Wire the router-aware
 * resolver app-side: `RouteNameResolverInterface => CurrentRouteNameResolver`.
 *
 * `$excludedPaths` skips tracing for exact request paths — typically the
 * scrape/probe endpoints (`/metrics`, `/health`): Prometheus polling every few
 * seconds would otherwise flood the tracing backend with identical traces.
 * Excluded requests pass straight through.
 *
 * Flushing the exporter is a separate concern (see {@see SpanFlusher}); it must
 * not happen per request.
 *
 * @api
 */
final readonly class OtelMiddleware implements MiddlewareInterface
{
    private const int SERVER_ERROR_THRESHOLD = 500;

    // OTel HTTP semantic-convention attribute names (stable string keys).
    private const string ATTR_REQUEST_METHOD = 'http.request.method';
    private const string ATTR_RESPONSE_STATUS = 'http.response.status_code';
    private const string ATTR_HTTP_ROUTE = 'http.route';
    private const string ATTR_URL_PATH = 'url.path';
    private const string ATTR_SERVER_ADDRESS = 'server.address';

    /**
     * @param list<string> $excludedPaths exact request paths to skip (e.g. '/metrics')
     */
    public function __construct(
        private TracerInterface $tracer,
        private TraceContextExtractor $extractor = new TraceContextExtractor(),
        private ?RouteNameResolverInterface $routeResolver = null,
        private array $excludedPaths = [],
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (\in_array($request->getUri()->getPath(), $this->excludedPaths, true)) {
            return $handler->handle($request);
        }

        $scope = $this->extractor->extract($request)->activate();

        try {
            return $this->tracer->trace(
                name: $request->getMethod(),
                callback: function (SpanInterface $span) use ($request, $handler): ResponseInterface {
                    $response = $handler->handle($request);

                    // The route is known only after routing ran inside the handler.
                    $route = $this->routeResolver?->resolve($request);

                    if ($route !== null && $route !== '') {
                        $span->updateName($request->getMethod() . ' ' . $route);
                        $span->setAttribute(self::ATTR_HTTP_ROUTE, $route);
                    }

                    $status = $response->getStatusCode();
                    $span->setAttribute(self::ATTR_RESPONSE_STATUS, $status);

                    if ($status >= self::SERVER_ERROR_THRESHOLD) {
                        $span->setStatus(SpanStatusCode::Error, 'HTTP ' . $status);
                    }

                    return $response;
                },
                attributes: [
                    self::ATTR_REQUEST_METHOD => $request->getMethod(),
                    self::ATTR_URL_PATH => $request->getUri()->getPath(),
                    self::ATTR_SERVER_ADDRESS => $request->getUri()->getHost(),
                ],
                traceKind: TraceKind::Server,
            );
        } finally {
            $scope->detach();
        }
    }
}
