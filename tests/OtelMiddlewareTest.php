<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\RouteNameResolverInterface;
use Rasuvaeff\Yii3TelemetryOtel\ShutdownFlushHook;
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

    public function zeroSampleRatioPassesThroughWithoutTracingOrFlushRegistration(): void
    {
        $registrations = 0;
        $provider = (new OtelTracerProviderFactory(serviceName: 'test', batch: false))->create($this->exporter);
        $middleware = new OtelMiddleware(
            $this->tracer,
            shutdownFlushHook: new ShutdownFlushHook(provider: $provider, finishRequest: false),
            shutdownRegistrar: static function () use (&$registrations): void {
                ++$registrations;
            },
            sampleRatio: 0.0,
        );

        $response = $middleware->process(
            $this->factory->createServerRequest('GET', 'https://api.example/serve'),
            $this->handler(200),
        );

        Assert::same($response->getStatusCode(), 200);
        Assert::count($this->exporter->getSpans(), 0);
        Assert::same($registrations, 0);
    }

    public function fullSampleRatioTracesEveryRequest(): void
    {
        $middleware = new OtelMiddleware($this->tracer, sampleRatio: 1.0);

        for ($i = 0; $i < 20; ++$i) {
            $middleware->process(
                $this->factory->createServerRequest('GET', 'https://api.example/serve'),
                $this->handler(200),
            );
        }

        Assert::count($this->exporter->getSpans(), 20);
    }

    public function partialSampleRatioTracesAMatchingFraction(): void
    {
        mt_srand(42);

        foreach ([[0.9, 300, 400], [0.1, 0, 100]] as [$ratio, $min, $max]) {
            $before = \count($this->exporter->getSpans());
            $middleware = new OtelMiddleware($this->tracer, sampleRatio: $ratio);

            for ($i = 0; $i < 400; ++$i) {
                $middleware->process(
                    $this->factory->createServerRequest('GET', 'https://api.example/serve'),
                    $this->handler(200),
                );
            }

            $traced = \count($this->exporter->getSpans()) - $before;
            Assert::true($traced >= $min && $traced <= $max, 'Ratio ' . $ratio . ' traced ' . $traced . ' of 400');
        }
    }

    public function incomingTraceparentBypassesTheSampleRatio(): void
    {
        $middleware = new OtelMiddleware($this->tracer, sampleRatio: 0.0);

        $middleware->process(
            $this->factory->createServerRequest('GET', 'https://api.example/serve')
                ->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'),
            $this->handler(200),
        );

        $span = $this->onlySpan();
        Assert::same($span->getTraceId(), '0af7651916cd43dd8448eb211c80319c');
        Assert::same($span->getParentSpanId(), 'b7ad6b7169203331');
    }

    public function sampleRatioIsValidatedAtConstruction(): void
    {
        foreach ([-0.1, 1.1, \NAN] as $ratio) {
            try {
                new OtelMiddleware($this->tracer, sampleRatio: $ratio);
                Assert::true(actual: false, message: 'Expected an out-of-range ratio to be refused');
            } catch (\InvalidArgumentException $exception) {
                Assert::string($exception->getMessage())->contains('between 0 and 1');
            }
        }
    }

    public function requestWithoutTraceparentLeavesTheActiveContextUntouched(): void
    {
        $before = \OpenTelemetry\Context\Context::getCurrent();

        $this->middleware()->process(
            $this->factory->createServerRequest('GET', 'https://api.example/serve'),
            $this->handler(200),
        );

        Assert::same(\OpenTelemetry\Context\Context::getCurrent(), $before);
        Assert::same($this->onlySpan()->getParentSpanId(), '0000000000000000');
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

    public function recordsRedactedUrlQueryByDefault(): void
    {
        $this->middleware()->process(
            $this->factory->createServerRequest('GET', 'https://api.example/users?page=2&api_token=hunter2'),
            $this->handler(200),
        );

        $span = $this->onlySpan();
        Assert::same($span->getAttributes()->get('url.query'), 'page=2&api_token=%2A%2A%2A');
    }

    public function capturesRequestParamsWithMaskingWhenOptedIn(): void
    {
        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            captureRequestParams: true,
        );

        $request = $this->factory->createServerRequest('POST', 'https://api.example/login?redirect=/home')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{"email":"a@b.c","password":"hunter2","profile":{"card":"4111","name":"Ann"}}'));

        $middleware->process($request, $this->handler(200));

        $attributes = $this->onlySpan()->getAttributes();
        Assert::same($attributes->get('http.request.param.email'), 'a@b.c');
        Assert::same($attributes->get('http.request.param.password'), '***');
        // Nested sensitive keys inside array values are masked before serialization.
        Assert::same($attributes->get('http.request.param.profile'), '{"card":"***","name":"Ann"}');
    }

    public function jsonBodyIsCapturedFromANullSizeStream(): void
    {
        // Real SAPI request bodies (php://input) report a null size while
        // remaining seekable and readable — size must not gate the capture.
        $inner = $this->factory->createStream('{"email":"a@b.c"}');
        $stream = new class ($inner) implements StreamInterface {
            use StreamDecoratorTrait;

            #[\Override]
            public function getSize(): ?int
            {
                return null;
            }
        };

        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            captureRequestParams: true,
        );

        $request = $this->factory->createServerRequest('POST', 'https://api.example/x')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        $middleware->process($request, $this->handler(200));

        Assert::same($this->onlySpan()->getAttributes()->get('http.request.param.email'), 'a@b.c');
    }

    public function queryParamsAreCapturedAndFormBodyPreferred(): void
    {
        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            captureRequestParams: true,
        );

        $request = $this->factory->createServerRequest('POST', 'https://api.example/search')
            ->withQueryParams(['q' => 'phones', 'page' => 3])
            ->withParsedBody(['q' => 'ignored-duplicate', 'sort' => 'price']);

        $middleware->process($request, $this->handler(200));

        $attributes = $this->onlySpan()->getAttributes();
        Assert::same($attributes->get('http.request.param.q'), 'phones'); // query wins over body
        Assert::same($attributes->get('http.request.param.page'), '3');
        Assert::same($attributes->get('http.request.param.sort'), 'price');
    }

    /**
     * Zero is a legal limit (skip the capture entirely); a negative one is
     * refused at construction.
     */
    public function captureLimitsAreValidatedAtConstruction(): void
    {
        $zero = new OtelMiddleware($this->tracer, maxQueryBytes: 0, maxRequestParams: 0);
        $zero->process(
            $this->factory->createServerRequest('GET', 'https://api.example/users?q=x'),
            $this->handler(200),
        );
        Assert::false($this->onlySpan()->getAttributes()->has('url.query'));

        foreach ([[-1, 50], [4096, -1]] as [$maxQueryBytes, $maxRequestParams]) {
            try {
                new OtelMiddleware($this->tracer, maxQueryBytes: $maxQueryBytes, maxRequestParams: $maxRequestParams);
                Assert::true(actual: false, message: 'Expected a negative capture limit to be refused');
            } catch (\InvalidArgumentException $exception) {
                Assert::string($exception->getMessage())->contains('non-negative');
            }
        }
    }

    public function captureQueryCanBeDisabled(): void
    {
        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            captureQuery: false,
        );

        $middleware->process(
            $this->factory->createServerRequest('GET', 'https://api.example/users?page=2'),
            $this->handler(200),
        );

        Assert::false($this->onlySpan()->getAttributes()->has('url.query'));
    }

    public function requestCaptureSkipsNonRecordingSpans(): void
    {
        $exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(batch: false, sampler: new AlwaysOffSampler()))->create($exporter);
        $tracer = (new OtelTracerProvider($provider))->getTracer();

        (new OtelMiddleware($tracer, new TraceContextExtractor(), captureRequestParams: true))
            ->process(
                $this->factory->createServerRequest('GET', 'https://api.example/x?token=secret'),
                $this->handler(200),
            );

        Assert::count($exporter->getSpans(), 0);
    }

    public function requestCaptureHonoursQueryAndParameterLimits(): void
    {
        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            captureQuery: true,
            captureRequestParams: true,
            maxQueryBytes: 4,
            maxRequestParams: 1,
        );

        $middleware->process(
            $this->factory->createServerRequest('GET', 'https://api.example/x?long=value')
                ->withQueryParams(['first' => '1', 'second' => '2']),
            $this->handler(200),
        );

        $attributes = $this->onlySpan()->getAttributes();
        Assert::false($attributes->has('url.query'));
        Assert::same($attributes->get('http.request.param.first'), '1');
        Assert::false($attributes->has('http.request.param.second'));
    }

    public function requestCaptureHonoursParameterAllowlist(): void
    {
        $middleware = new OtelMiddleware(
            $this->tracer,
            new TraceContextExtractor(),
            captureRequestParams: true,
            requestParamAllowlist: ['keep'],
        );

        $middleware->process(
            $this->factory->createServerRequest('GET', 'https://api.example/x')
                ->withQueryParams(['keep' => 'yes', 'drop' => 'no']),
            $this->handler(200),
        );

        $attributes = $this->onlySpan()->getAttributes();
        Assert::same($attributes->get('http.request.param.keep'), 'yes');
        Assert::false($attributes->has('http.request.param.drop'));
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

    /**
     * The #29 regression: on persistent worker runtimes shutdown functions are
     * cleared with the request that registered them, so a hook registered
     * once at provider build flushes after the first request only — later
     * spans wait in the batch queue until it fills. The middleware
     * re-registers the flush per request; the registrar spy is the runtime
     * simulation (functions registered during request N are collected, then
     * fired at that request's end), and every request's span must be
     * exported at its own end — with the batch processor on.
     */
    public function perRequestFlushExportsEveryRequestsSpansAtItsOwnEnd(): void
    {
        $exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(serviceName: 'test', batch: true))->create($exporter);
        $tracer = (new OtelTracerProvider($provider))->getTracer();

        /** @var list<ShutdownFlushHook> $pending */
        $pending = [];
        $middleware = new OtelMiddleware(
            tracer: $tracer,
            excludedPaths: [],
            shutdownFlushHook: new ShutdownFlushHook(provider: $provider, finishRequest: false),
            shutdownRegistrar: static function (ShutdownFlushHook $hook) use (&$pending): void {
                $pending[] = $hook;
            },
        );

        $requestEnd = static function () use (&$pending): void {
            foreach ($pending as $hook) {
                $hook();
            }
            $pending = [];
        };

        $middleware->process($this->factory->createServerRequest('GET', 'https://api.example/probe'), $this->handler(200));
        $requestEnd();
        Assert::count($exporter->getSpans(), 1);

        // The second request must get its own registration: on Rapira /
        // FrankenPHP the first request's functions are gone by now.
        $middleware->process($this->factory->createServerRequest('GET', 'https://api.example/probe'), $this->handler(200));
        Assert::count($pending, 1);
        $requestEnd();
        Assert::count($exporter->getSpans(), 2);
    }

    public function registersTheFlushOncePerRequestAndNeverForExcludedPaths(): void
    {
        $registrations = 0;
        $exporter = new InMemoryExporter(new \ArrayObject());
        $provider = (new OtelTracerProviderFactory(serviceName: 'test', batch: true))->create($exporter);

        $middleware = new OtelMiddleware(
            tracer: (new OtelTracerProvider($provider))->getTracer(),
            excludedPaths: ['/metrics'],
            shutdownFlushHook: new ShutdownFlushHook(provider: $provider, finishRequest: false),
            shutdownRegistrar: static function (ShutdownFlushHook $hook) use (&$registrations): void {
                $registrations++;
            },
        );

        $middleware->process($this->factory->createServerRequest('GET', 'https://api.example/metrics'), $this->handler(200));
        Assert::same($registrations, 0);

        $middleware->process($this->factory->createServerRequest('GET', 'https://api.example/users'), $this->handler(200));
        Assert::same($registrations, 1);

        $middleware->process($this->factory->createServerRequest('GET', 'https://api.example/users'), $this->handler(200));
        Assert::same($registrations, 2);

        // Without a hook nothing is registered at all: the boot-time hook
        // from config/di.php stands alone.
        $bare = new OtelMiddleware(
            tracer: $this->tracer,
            shutdownRegistrar: static function (ShutdownFlushHook $hook) use (&$registrations): void {
                $registrations++;
            },
        );
        $bare->process($this->factory->createServerRequest('GET', 'https://api.example/users'), $this->handler(200));
        Assert::same($registrations, 2);
    }
}
