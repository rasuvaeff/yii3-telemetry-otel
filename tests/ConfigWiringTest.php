<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use OpenTelemetry\API\Trace\TracerProviderInterface as OtelApiTracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as OtelSdkTracerProviderInterface;
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\Tracer;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;
use Rasuvaeff\Yii3TelemetryOtel\ConsoleCommandSpanListener;
use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;
use Rasuvaeff\Yii3TelemetryOtel\ShutdownFlushHook;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * `config/*.php` files are outside the cs/psalm/testo gate, so this guards the
 * backend wiring contract: it owns exactly the swappable provider key and does
 * not re-bind the core facade.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function backendBindsOnlyTheSwappableProviderKey(): void
    {
        Assert::array($this->di())->hasKeys(TracerProviderInterface::class);
    }

    public function backendDoesNotRebindTheCoreFacade(): void
    {
        Assert::array($this->di())->doesNotHaveKeys(Tracer::class, TracerInterface::class);
    }

    public function diFactoryChainResolvesEndToEnd(): void
    {
        // register_shutdown_flush off: the factory chain must not leave a
        // shutdown hook behind in the test process.
        $di = $this->di(['register_shutdown_flush' => false]);

        // Invoke each factory closure so param-key mismatches or type errors in
        // the exporter → SDK provider → core provider chain surface here (config
        // is not exercised by cs/psalm/testo otherwise). No span is emitted, so
        // the OTLP exporter never connects.
        $exporter = $di[SpanExporterInterface::class](new OtlpExporterFactory());
        Assert::instanceOf($exporter, SpanExporterInterface::class);

        // The OTel API provider key is an alias to the SDK provider definition.
        Assert::same($di[OtelApiTracerProviderInterface::class], OtelSdkTracerProviderInterface::class);

        $sdkProvider = $di[OtelSdkTracerProviderInterface::class]($exporter);
        Assert::instanceOf($sdkProvider, OtelSdkTracerProviderInterface::class);

        $coreProvider = $di[TracerProviderInterface::class]($sdkProvider);
        Assert::instanceOf($coreProvider, OtelTracerProvider::class);

        Assert::instanceOf(new Tracer($coreProvider), Tracer::class);
    }

    public function disabledParamsBindTheNullProvider(): void
    {
        $di = $this->di(['enabled' => false]);

        $provider = $di[TracerProviderInterface::class]();

        Assert::instanceOf($provider, NullTracerProvider::class);

        // API-level consumers (ConsoleCommandSpanListener) go no-op too,
        // without ever building the SDK/exporter chain.
        $apiProvider = $di[OtelApiTracerProviderInterface::class]();
        Assert::instanceOf($apiProvider, \OpenTelemetry\API\Trace\NoopTracerProvider::class);
    }

    public function paramsCarryTheOperationalToggles(): void
    {
        /** @var array<string, mixed> $params */
        $params = require __DIR__ . '/../config/params.php';
        $config = $params['rasuvaeff/yii3-telemetry-otel'];

        Assert::true($config['enabled']);
        Assert::true($config['register_shutdown_flush']);
        Assert::true($config['batch']);
        Assert::same($config['max_queue_size'], null);
        Assert::same($config['max_export_batch_size'], null);
        Assert::same($config['scheduled_delay_ms'], null);
        Assert::same($config['export_timeout_ms'], null);
        Assert::same($config['auto_flush'], null);
        Assert::same($config['content_type'], 'application/x-protobuf');
        Assert::same($config['excluded_paths'], []);
        Assert::same($config['sample_ratio'], 1.0);
        Assert::true($config['capture_query']);
        Assert::same($config['max_query_bytes'], 4096);
        Assert::same($config['max_request_params'], 50);
        Assert::same($config['request_param_allowlist'], []);
        Assert::true($config['finish_request_before_flush']);
        Assert::false($config['capture_request_params']);
        Assert::same($config['excluded_commands'], ['queue:listen', 'queue:listen-all']);
        Assert::same($config['timeout'], 10.0);
        Assert::same($config['max_retries'], 3);
        Assert::same($config['retry_delay_ms'], 100);
    }

    public function timeoutComesFromTheTracesSpecificEnvInMilliseconds(): void
    {
        $timeout = $this->timeoutWithEnv(traces: '1500', generic: '5000');

        Assert::same($timeout, 1.5);
    }

    public function timeoutFallsBackToTheGenericEnv(): void
    {
        Assert::same($this->timeoutWithEnv(traces: '', generic: '2000'), 2.0);
        Assert::same($this->timeoutWithEnv(traces: null, generic: '2000'), 2.0);
        Assert::same($this->timeoutWithEnv(traces: ' ', generic: '2000'), 2.0);
    }

    public function invalidOrMissingTimeoutEnvFallsBackToTheDefault(): void
    {
        foreach (['abc', '0', '-5', '1.5', '1e3'] as $invalid) {
            Assert::same($this->timeoutWithEnv(traces: $invalid, generic: '2000'), 10.0);
            Assert::same($this->timeoutWithEnv(traces: null, generic: $invalid), 10.0);
        }

        Assert::same($this->timeoutWithEnv(traces: null, generic: null), 10.0);
    }

    public function diPassesTimeoutAndRetriesToTheExporterFactory(): void
    {
        $di = $this->di(['timeout' => 1.5, 'max_retries' => 0, 'retry_delay_ms' => 50]);

        $recorder = new RecordingTransportFactory();
        $exporter = $di[SpanExporterInterface::class](new OtlpExporterFactory($recorder));

        Assert::instanceOf($exporter, SpanExporterInterface::class);
        Assert::same($recorder->timeout, 1.5);
        Assert::same($recorder->maxRetries, 0);
        Assert::same($recorder->retryDelay, 50);
    }

    public function diPassesBatchProcessorControlsToTheProviderFactory(): void
    {
        $di = $this->di([
            'register_shutdown_flush' => false,
            'max_queue_size' => 7,
            'max_export_batch_size' => 3,
            'scheduled_delay_ms' => 250,
            'export_timeout_ms' => 900,
            'auto_flush' => false,
        ]);

        $exporter = $di[SpanExporterInterface::class](new OtlpExporterFactory());
        $provider = $di[OtelSdkTracerProviderInterface::class]($exporter);

        Assert::instanceOf($provider, OtelSdkTracerProviderInterface::class);
    }

    public function diBindsTheConsoleListenerWithExcludedCommands(): void
    {
        $di = $this->di(['excluded_commands' => ['outbox:*', 'queue:listen']]);

        /** @var array{__construct(): array{excludedCommands: mixed}} $definition */
        $definition = $di[ConsoleCommandSpanListener::class];
        Assert::same($definition['__construct()']['excludedCommands'], ['outbox:*', 'queue:listen']);
    }

    private function timeoutWithEnv(?string $traces, ?string $generic): float
    {
        $names = ['OTEL_EXPORTER_OTLP_TRACES_TIMEOUT' => $traces, 'OTEL_EXPORTER_OTLP_TIMEOUT' => $generic];
        $previous = [];
        foreach ($names as $name => $value) {
            $previous[$name] = getenv($name);
            $value === null ? putenv($name) : putenv($name . '=' . $value);
        }

        try {
            /** @var array<string, array<string, mixed>> $params */
            $params = require __DIR__ . '/../config/params.php';
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }

        return (float) $params['rasuvaeff/yii3-telemetry-otel']['timeout'];
    }

    public function webConfigBindsTheMiddlewareWithExcludedPaths(): void
    {
        $middleware = $this->webMiddleware(['excluded_paths' => ['/metrics']]);

        Assert::same($this->property($middleware, 'excludedPaths'), ['/metrics']);
        Assert::instanceOf($this->property($middleware, 'shutdownFlushHook'), ShutdownFlushHook::class);
    }

    public function webConfigPassesTheSampleRatioToTheMiddleware(): void
    {
        Assert::same($this->property($this->webMiddleware([]), 'sampleRatio'), 1.0);
        Assert::same($this->property($this->webMiddleware(['sample_ratio' => 0.01]), 'sampleRatio'), 0.01);
    }

    public function webMiddlewareGetsNoHookWhenTheFlushIsOff(): void
    {
        Assert::null($this->property($this->webMiddleware(['register_shutdown_flush' => false]), 'shutdownFlushHook'));
    }

    public function webMiddlewareGetsNoHookWhenTheSdkIsDisabled(): void
    {
        $middleware = $this->webMiddleware(['enabled' => false]);

        // Gated on `enabled` too: resolving the hook would pull the whole
        // SDK chain (exporter included), which "nothing OTel is built"
        // forbids when the SDK is off.
        Assert::null($this->property($middleware, 'shutdownFlushHook'));
    }

    public function paramsAreNamespaced(): void
    {
        /** @var array<string, mixed> $params */
        $params = require __DIR__ . '/../config/params.php';

        Assert::array($params)->hasKeys('rasuvaeff/yii3-telemetry-otel');
    }

    /**
     * @param array<string, mixed> $overrides package-param overrides
     */
    private function di(array $overrides = []): array
    {
        /** @var array<string, mixed> $params */
        $params = require __DIR__ . '/../config/params.php';
        $params['rasuvaeff/yii3-telemetry-otel'] = $overrides + $params['rasuvaeff/yii3-telemetry-otel'];

        /** @var array<string, mixed> $di */
        $di = (static fn(array $params): array => require __DIR__ . '/../config/di.php')($params);

        return $di;
    }

    /**
     * The web middleware as the application resolves it: the backend di.php
     * plus di-web.php in one container, with the core Tracer the core package
     * would bind (the core's own config is not loaded here).
     *
     * @param array<string, mixed> $overrides package-param overrides
     */
    private function webMiddleware(array $overrides = []): OtelMiddleware
    {
        /** @var array<string, mixed> $params */
        $params = require __DIR__ . '/../config/params.php';
        $params['rasuvaeff/yii3-telemetry-otel'] = $overrides + $params['rasuvaeff/yii3-telemetry-otel'];

        /** @var array<string, mixed> $di */
        $definitions = (static fn(array $params): array => require __DIR__ . '/../config/di.php')($params);
        /** @var array<string, mixed> $diWeb */
        $diWeb = (static fn(array $params): array => require __DIR__ . '/../config/di-web.php')($params);
        $definitions = [...$definitions, ...$diWeb];
        $definitions[TracerInterface::class] = static fn(TracerProviderInterface $provider): TracerInterface => new Tracer($provider);

        $container = new \Yiisoft\Di\Container(\Yiisoft\Di\ContainerConfig::create()->withDefinitions($definitions));

        return $container->get(OtelMiddleware::class);
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
