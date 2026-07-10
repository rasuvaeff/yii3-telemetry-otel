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
 * Request data on the span:
 * - `$captureQuery` (default on) records `url.query` with the values of
 *   sensitive-looking keys (`password`, `token`, …) replaced by `***`;
 * - `$captureRequestParams` (default OFF — opt in consciously) records each
 *   query / form / top-level JSON-body parameter as
 *   `http.request.param.<name>`, sensitive keys masked, values truncated.
 *   Bodies larger than 8 KiB or non-seekable streams are skipped.
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
    private const string ATTR_URL_QUERY = 'url.query';
    private const string ATTR_SERVER_ADDRESS = 'server.address';
    private const string PARAM_ATTR_PREFIX = 'http.request.param.';

    private const array SENSITIVE_KEY_NEEDLES = ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'credential', 'card'];
    private const string MASK = '***';
    private const int MAX_PARAM_VALUE_LENGTH = 200;
    private const int MAX_JSON_BODY_BYTES = 8192;

    /**
     * @param list<string> $excludedPaths exact request paths to skip (e.g. '/metrics')
     * @param bool $captureQuery record `url.query` (sensitive values masked)
     * @param bool $captureRequestParams record query/form/JSON-body parameters as
     *        `http.request.param.<name>` attributes (masked + truncated)
     */
    public function __construct(
        private TracerInterface $tracer,
        private TraceContextExtractor $extractor = new TraceContextExtractor(),
        private ?RouteNameResolverInterface $routeResolver = null,
        private array $excludedPaths = [],
        private bool $captureQuery = true,
        private bool $captureRequestParams = false,
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
                    ...$this->requestDataAttributes($request),
                ],
                traceKind: TraceKind::Server,
            );
        } finally {
            $scope->detach();
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestDataAttributes(ServerRequestInterface $request): array
    {
        $attributes = [];
        $query = $request->getUri()->getQuery();

        if ($this->captureQuery && $query !== '') {
            $attributes[self::ATTR_URL_QUERY] = $this->redactedQuery($query);
        }

        if ($this->captureRequestParams) {
            /** @var mixed $value */
            foreach ($this->requestParams($request) as $name => $value) {
                $attributes[self::PARAM_ATTR_PREFIX . $name] = $this->isSensitiveKey($name)
                    ? self::MASK
                    : $this->stringifyParam($value);
            }
        }

        return $attributes;
    }

    /**
     * Query params + form params + top-level JSON-body params, in that
     * precedence order (later sources do not overwrite earlier names).
     *
     * @return array<string, mixed>
     */
    private function requestParams(ServerRequestInterface $request): array
    {
        $params = self::stringKeyed($request->getQueryParams());
        $parsed = $request->getParsedBody();

        if (\is_array($parsed)) {
            return $params + self::stringKeyed($parsed);
        }

        if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json')) {
            $params += $this->jsonBodyParams($request);
        }

        return $params;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        return array_combine(array_map(strval(...), array_keys($data)), array_values($data));
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBodyParams(ServerRequestInterface $request): array
    {
        $body = $request->getBody();

        if (!$body->isSeekable()) {
            return [];
        }

        // Read with a limit instead of trusting getSize(): real SAPI request
        // streams (php://input) report a null/0 size while still being readable.
        $body->rewind();
        $contents = $body->read(self::MAX_JSON_BODY_BYTES + 1);
        $body->rewind();

        if ($contents === '' || \strlen($contents) > self::MAX_JSON_BODY_BYTES) {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (!\is_array($decoded)) {
            return [];
        }

        return self::stringKeyed($decoded);
    }

    private function redactedQuery(string $query): string
    {
        parse_str($query, $pairs);

        return http_build_query($this->maskSensitive($pairs));
    }

    /**
     * Recursively replaces the values of sensitive-looking keys at every level.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function maskSensitive(array $data): array
    {
        /** @var mixed $value */
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;

                continue;
            }

            if (\is_array($value)) {
                $data[$key] = $this->maskSensitive($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $name): bool
    {
        $lower = strtolower($name);

        foreach (self::SENSITIVE_KEY_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function stringifyParam(mixed $value): string
    {
        if (\is_string($value)) {
            $string = $value;
        } elseif (\is_bool($value)) {
            $string = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $string = 'null';
        } elseif (\is_scalar($value)) {
            $string = (string) $value;
        } else {
            $encoded = json_encode(\is_array($value) ? $this->maskSensitive($value) : $value, JSON_UNESCAPED_UNICODE);
            $string = $encoded === false ? '(unserializable)' : $encoded;
        }

        // Byte-based truncation — may split a multibyte character at the edge,
        // acceptable for debug attributes (no mb_* runtime dependency).
        return \strlen($string) > self::MAX_PARAM_VALUE_LENGTH
            ? substr($string, 0, self::MAX_PARAM_VALUE_LENGTH) . '…'
            : $string;
    }
}
