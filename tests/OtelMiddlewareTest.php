<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\RouteNameResolverInterface;
use Rasuvaeff\Yii3TelemetryOtel\TraceContextExtractor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OtelMiddleware::class)]
#[Covers(TraceContextExtractor::class)]
final class OtelMiddlewareTest
{
    private InMemoryExporter $exporter;
    private TracerInterface $tracer;
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(serviceName: 'test', batch: false))->create($this->exporter);
        $this->tracer = (new OtelTracerProvider($provider))->getTracer();
        $this->factory = new Psr17Factory();
    }

    public function opensServerSpanWithHttpAttributes(): void
    {
        $response = $this->middleware()->process(
            $this->factory->createServerRequest('GET', 'https://api.example/users'),
            $this->handler(200),
        );

        Assert::same($response->getStatusCode(), 200);

        $span = $this->onlySpan();
        // No route resolver wired: semconv fallback name is the bare method
        // (never the raw path — span-name cardinality).
        Assert::same($span->getName(), 'GET');
        Assert::same($span->getKind(), TraceKind::Server->value);
        Assert::same($span->getAttributes()->get('http.request.method'), 'GET');
        Assert::same($span->getAttributes()->get('url.path'), '/users');
        Assert::same($span->getAttributes()->get('server.address'), 'api.example');
        Assert::same($span->getAttributes()->get('http.response.status_code'), 200);
    }

    public function marksServerErrorsAsErrorStatus(): void
    {
        $this->middleware()->process(
            $this->factory->createServerRequest('POST', 'https://api.example/orders'),
            $this->handler(500),
        );

        $span = $this->onlySpan();
        Assert::same($span->getStatus()->getCode(), 'Error');
        Assert::same($span->getStatus()->getDescription(), 'HTTP 500');
    }

    public function leavesSuccessfulResponsesUnset(): void
    {
        $this->middleware()->process(
            $this->factory->createServerRequest('GET', 'https://api.example/ok'),
            $this->handler(204),
        );

        Assert::same($this->onlySpan()->getStatus()->getCode(), 'Unset');
    }

    public function continuesIncomingDistributedTrace(): void
    {
        $request = $this->factory->createServerRequest('GET', 'https://api.example/x')
            ->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

        $this->middleware()->process($request, $this->handler(200));

        $span = $this->onlySpan();
        Assert::same($span->getTraceId(), '0af7651916cd43dd8448eb211c80319c');
        Assert::same($span->getParentSpanId(), 'b7ad6b7169203331');
    }

    public function doesNotLeakActiveSpanAcrossRequests(): void
    {
        $middleware = $this->middleware();

        for ($i = 0; $i < 100; ++$i) {
            $middleware->process(
                $this->factory->createServerRequest('GET', 'https://api.example/loop'),
                $this->handler(200),
            );
        }

        Assert::false(Span::getCurrent()->getContext()->isValid());
        Assert::count($this->exporter->getSpans(), 100);
    }

    public function namesSpanByRouteTemplateWhenResolved(): void
    {
        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            $this->resolver('/users/{id}'),
        );

        $middleware->process(
            $this->factory->createServerRequest('GET', 'https://api.example/users/123'),
            $this->handler(200),
        );

        $span = $this->onlySpan();
        Assert::same($span->getName(), 'GET /users/{id}');
        Assert::same($span->getAttributes()->get('http.route'), '/users/{id}');
        Assert::same($span->getAttributes()->get('url.path'), '/users/123');
    }

    public function keepsMethodNameWhenNoRouteMatched(): void
    {
        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            $this->resolver(null),
        );

        $middleware->process(
            $this->factory->createServerRequest('GET', 'https://api.example/no-route'),
            $this->handler(404),
        );

        $span = $this->onlySpan();
        Assert::same($span->getName(), 'GET');
        Assert::false($span->getAttributes()->has('http.route'));
    }

    public function excludedPathIsNotTraced(): void
    {
        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            excludedPaths: ['/metrics'],
        );

        $response = $middleware->process(
            $this->factory->createServerRequest('GET', 'https://api.example/metrics'),
            $this->handler(200),
        );

        Assert::same($response->getStatusCode(), 200);
        Assert::count($this->exporter->getSpans(), 0);
    }

    private function resolver(?string $route): RouteNameResolverInterface
    {
        return new readonly class ($route) implements RouteNameResolverInterface {
            public function __construct(private ?string $route) {}

            #[\Override]
            public function resolve(ServerRequestInterface $request): ?string
            {
                return $this->route;
            }
        };
    }

    private function middleware(): OtelMiddleware
    {
        return new OtelMiddleware($this->tracer, new TraceContextExtractor());
    }

    private function handler(int $status): RequestHandlerInterface
    {
        return new readonly class ($status) implements RequestHandlerInterface {
            public function __construct(private int $status) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->status);
            }
        };
    }

    private function onlySpan(): \OpenTelemetry\SDK\Trace\SpanDataInterface
    {
        $spans = $this->exporter->getSpans();
        Assert::count($spans, 1);

        return $spans[0];
    }
}
