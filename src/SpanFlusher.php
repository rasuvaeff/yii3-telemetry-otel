<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * Forces the batch exporter to flush buffered spans. Call it on worker shutdown
 * or on a timer in long-running runtimes (RoadRunner, Swoole, FrankenPHP) —
 * NEVER per request, or the batch processor degrades into synchronous export.
 *
 * @api
 */
final readonly class SpanFlusher
{
    public function __construct(
        private TracerProviderInterface $provider,
    ) {}

    /**
     * @return bool whether the flush succeeded
     */
    public function flush(): bool
    {
        return $this->provider->forceFlush();
    }
}
