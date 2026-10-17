<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as OtelSdkTracerProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\Tracer;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;

require __DIR__ . '/../vendor/autoload.php';

/**
 * This is the smallest standalone equivalent of the Yii application wiring:
 * load the package definitions, replace only the exporter for an offline demo,
 * and resolve the SDK provider -> core provider -> Tracer chain.
 */
/** @var array<string, mixed> $params */
$params = require __DIR__ . '/../config/params.php';
$params['rasuvaeff/yii3-telemetry-otel'] = array_replace($params['rasuvaeff/yii3-telemetry-otel'], [
    'batch' => false,
    'register_shutdown_flush' => false,
]);

/** @var array<string, mixed> $definitions */
$definitions = (static function (array $params): array {
    return require __DIR__ . '/../config/di.php';
})($params);

/** @var array<string, mixed> $webDefinitions */
$webDefinitions = (static function (array $params): array {
    return require __DIR__ . '/../config/di-web.php';
})($params);

$exporter = new InMemoryExporter(new ArrayObject());
$sdkProvider = $definitions[OtelSdkTracerProviderInterface::class]($exporter);
/** @var OtelTracerProvider $backendProvider */
$backendProvider = $definitions[TracerProviderInterface::class]($sdkProvider);
$tracer = new Tracer($backendProvider);

/** @var array{__construct(): array<string, mixed>} $middlewareDefinition */
$middlewareDefinition = $webDefinitions[OtelMiddleware::class];
$middleware = new OtelMiddleware(
    $tracer,
    excludedPaths: $middlewareDefinition['__construct()']['excludedPaths'],
    captureQuery: $middlewareDefinition['__construct()']['captureQuery'],
    captureRequestParams: $middlewareDefinition['__construct()']['captureRequestParams'],
    maxQueryBytes: $middlewareDefinition['__construct()']['maxQueryBytes'],
    maxRequestParams: $middlewareDefinition['__construct()']['maxRequestParams'],
    requestParamAllowlist: $middlewareDefinition['__construct()']['requestParamAllowlist'],
);

$handler = new readonly class implements RequestHandlerInterface {
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
};

$request = (new Psr17Factory())->createServerRequest('GET', 'https://demo/di?order=42');
$middleware->process($request, $handler);

foreach ($exporter->getSpans() as $span) {
    printf("span=%s status=%s\n", $span->getName(), $span->getStatus()->getCode());
}

// The core Tracer facade is resolved from the backend provider binding above.
$tracer->trace('di.example', static function (SpanInterface $span): void {
    $span->setAttribute('example', true);
});

echo 'resolved=' . Tracer::class . ' via ' . OtelTracerProvider::class . PHP_EOL;
