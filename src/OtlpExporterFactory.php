<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Builds an OTLP/HTTP span exporter that ships spans to an OpenTelemetry
 * Collector. The transport discovers a PSR-18 client via `php-http/discovery`, so
 * the application must have one installed (e.g. `guzzlehttp/guzzle`).
 *
 * @api
 */
final readonly class OtlpExporterFactory
{
    private const string DEFAULT_ENDPOINT = 'http://localhost:4318';
    private const string TRACES_PATH = '/v1/traces';
    private const string PROTOBUF = 'application/x-protobuf';

    /** SDK transport defaults, kept so a bare `create()` behaves as before. */
    public const float DEFAULT_TIMEOUT = 10.0;
    public const int DEFAULT_MAX_RETRIES = 3;
    public const int DEFAULT_RETRY_DELAY_MS = 100;

    public function __construct(
        private ?TransportFactoryInterface $transportFactory = null,
    ) {}

    /**
     * @param 'application/json'|'application/x-ndjson'|'application/x-protobuf' $contentType
     * @param float $timeout per-request timeout, seconds
     * @param int $maxRetries retries after the first failed attempt (0 = none)
     * @param int $retryDelayMs initial retry back-off, milliseconds
     */
    public function create(
        string $endpoint = self::DEFAULT_ENDPOINT,
        string $contentType = self::PROTOBUF,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $retryDelayMs = self::DEFAULT_RETRY_DELAY_MS,
    ): SpanExporterInterface {
        $transport = ($this->transportFactory ?? new OtlpHttpTransportFactory())->create(
            rtrim($endpoint, '/') . self::TRACES_PATH,
            $contentType,
            timeout: $timeout,
            retryDelay: $retryDelayMs,
            maxRetries: $maxRetries,
        );

        return new OtlpSpanExporter($transport);
    }
}
