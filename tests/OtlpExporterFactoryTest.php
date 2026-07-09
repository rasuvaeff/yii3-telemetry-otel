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
}
