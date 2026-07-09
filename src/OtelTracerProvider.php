<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\API\Trace\TracerProviderInterface as OtelTracerProviderInterface;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;

/**
 * Backend {@see TracerProviderInterface}: wraps an OpenTelemetry
 * `TracerProviderInterface` and hands out {@see OtelTracer} adapters. This is the
 * single binding that owns the swappable provider key in the app.
 *
 * @api
 */
final readonly class OtelTracerProvider implements TracerProviderInterface
{
    public function __construct(
        private OtelTracerProviderInterface $provider,
        private string $defaultName = 'rasuvaeff/yii3-telemetry-otel',
    ) {}

    #[\Override]
    public function getTracer(?string $name = null): TracerInterface
    {
        return new OtelTracer($this->provider->getTracer($name ?? $this->defaultName));
    }
}
