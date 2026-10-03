<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OtlpExporterFactory::class)]
final class OtlpExporterFactoryTest
{
    public function buildsAnOtlpSpanExporterForTheDefaultEndpoint(): void
    {
        $exporter = (new OtlpExporterFactory())->create();

        Assert::instanceOf($exporter, SpanExporterInterface::class);
    }

    public function buildsAnExporterForACustomEndpointAndContentType(): void
    {
        $exporter = (new OtlpExporterFactory())->create('http://collector:4318/', 'application/json');

        Assert::instanceOf($exporter, SpanExporterInterface::class);
    }

    public function passesTimeoutAndRetriesToTheTransport(): void
    {
        $recorder = new RecordingTransportFactory();

        (new OtlpExporterFactory($recorder))->create(
            'http://collector:4318/',
            'application/json',
            1.5,
            1,
            250,
        );

        Assert::same($recorder->endpoint, 'http://collector:4318/v1/traces');
        Assert::same($recorder->contentType, 'application/json');
        Assert::same($recorder->timeout, 1.5);
        Assert::same($recorder->maxRetries, 1);
        Assert::same($recorder->retryDelay, 250);
    }

    public function defaultsEqualTheSdkTransportDefaults(): void
    {
        $recorder = new RecordingTransportFactory();

        (new OtlpExporterFactory($recorder))->create();

        Assert::same($recorder->timeout, 10.0);
        Assert::same($recorder->maxRetries, 3);
        Assert::same($recorder->retryDelay, 100);
    }
}
