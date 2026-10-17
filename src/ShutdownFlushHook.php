<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * The shutdown hook the DI wiring registers via `register_shutdown_function()`
 * when `register_shutdown_flush` is on (see `config/di.php`).
 *
 * It calls {@see TracerProviderInterface::forceFlush()} — NOT `shutdown()`.
 * Shutdown functions run **after every request** on per-request-shutdown
 * runtimes (Rapira's HTTP worker, FrankenPHP worker mode — on php-fpm the
 * process recycles either way), so a `shutdown()` hook would close the span
 * processor after the first request and silently drop every span of every
 * later request. `forceFlush()` drains the batch buffer and keeps the provider
 * usable.
 *
 * On php-fpm the response is finished first (`fastcgi_finish_request()`, when
 * the engine function exists), so the client never waits for the OTLP
 * round-trip.
 *
 * @api
 */
final readonly class ShutdownFlushHook
{
    public function __construct(
        private TracerProviderInterface $provider,
        private bool $finishRequest = true,
    ) {}

    /**
     * @return bool whether the flush succeeded
     */
    public function __invoke(): bool
    {
        if ($this->finishRequest && \function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        return $this->provider->forceFlush();
    }
}
