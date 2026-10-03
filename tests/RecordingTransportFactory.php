<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

final class RecordingTransportFactory implements TransportFactoryInterface
{
    public string $endpoint = '';
    public string $contentType = '';
    public float $timeout = -1.0;
    public int $retryDelay = -1;
    public int $maxRetries = -1;

    #[\Override]
    public function create(
        string $endpoint,
        string $contentType,
        array $headers = [],
        $compression = null,
        float $timeout = 10.,
        int $retryDelay = 100,
        int $maxRetries = 3,
        ?string $cacert = null,
        ?string $cert = null,
        ?string $key = null,
    ): TransportInterface {
        $this->endpoint = $endpoint;
        $this->contentType = $contentType;
        $this->timeout = $timeout;
        $this->retryDelay = $retryDelay;
        $this->maxRetries = $maxRetries;

        return new readonly class ($contentType) implements TransportInterface {
            public function __construct(private string $contentType) {}

            #[\Override]
            public function contentType(): string
            {
                return $this->contentType;
            }

            #[\Override]
            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                return new CompletedFuture(null);
            }

            #[\Override]
            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            #[\Override]
            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
    }
}
