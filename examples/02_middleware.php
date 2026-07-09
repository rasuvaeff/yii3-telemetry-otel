<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;

require __DIR__ . '/../vendor/autoload.php';

$exporter = new InMemoryExporter(new ArrayObject());
$provider = (new OtelTracerProviderFactory(serviceName: 'demo', batch: false))->create($exporter);
$tracer = (new OtelTracerProvider($provider))->getTracer();

$middleware = new OtelMiddleware($tracer);

$handler = new readonly class implements RequestHandlerInterface {
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
};

$factory = new Psr17Factory();
$request = $factory->createServerRequest('GET', 'https://demo/users')
    // An incoming traceparent makes the server span continue a distributed trace.
    ->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

$middleware->process($request, $handler);

foreach ($exporter->getSpans() as $span) {
    printf(
        "span=%s trace=%s parent=%s status=%s\n",
        $span->getName(),
        $span->getTraceId(),
        $span->getParentSpanId(),
        $span->getStatus()->getCode(),
    );
}
