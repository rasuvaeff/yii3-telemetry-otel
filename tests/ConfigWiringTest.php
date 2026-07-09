<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use OpenTelemetry\API\Trace\TracerProviderInterface as OtelApiTracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as OtelSdkTracerProviderInterface;
use Rasuvaeff\Yii3Telemetry\Tracer;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtelMiddleware;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;
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
        $di = $this->di();

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

    public function webConfigBindsTheMiddleware(): void
    {
        /** @var array<string, mixed> $di */
        $di = require dirname(__DIR__) . '/config/di-web.php';

        Assert::array($di)->hasKeys(OtelMiddleware::class);
    }

    public function paramsAreNamespaced(): void
    {
        /** @var array<string, mixed> $params */
        $params = require dirname(__DIR__) . '/config/params.php';

        Assert::array($params)->hasKeys('rasuvaeff/yii3-telemetry-otel');
    }

    /**
     * @return array<string, mixed>
     */
    private function di(): array
    {
        /** @var array<string, mixed> $params */
        $params = require dirname(__DIR__) . '/config/params.php';

        /** @var array<string, mixed> $di */
        $di = (static fn(array $params): array => require dirname(__DIR__) . '/config/di.php')($params);

        return $di;
    }
}
