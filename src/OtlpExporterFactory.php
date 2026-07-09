<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
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

    /**
     * @param 'application/json'|'application/x-ndjson'|'application/x-protobuf' $contentType
     */
    public function create(
        string $endpoint = self::DEFAULT_ENDPOINT,
        string $contentType = self::PROTOBUF,
    ): SpanExporterInterface {
        $transport = (new OtlpHttpTransportFactory())->create(
            rtrim($endpoint, '/') . self::TRACES_PATH,
            $contentType,
        );

        return new OtlpSpanExporter($transport);
    }
}
