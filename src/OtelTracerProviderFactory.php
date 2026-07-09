<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Builds an OpenTelemetry SDK {@see TracerProvider} from an injected span
 * exporter. A batch processor is used by default (spans are flushed in batches —
 * see {@see SpanFlusher}); a simple processor exports each span immediately and
 * is handy for tests.
 *
 * The sampler defaults to the SDK {@see SamplerFactory}, which honours the
 * standard `OTEL_TRACES_SAMPLER` / `OTEL_TRACES_SAMPLER_ARG` env vars
 * (`parentbased_always_on` when unset; `parentbased_traceidratio` + ratio for
 * probabilistic sampling). Pass an explicit {@see SamplerInterface} to override.
 *
 * @api
 */
final readonly class OtelTracerProviderFactory
{
    public function __construct(
        private string $serviceName = 'yii3-app',
        private bool $batch = true,
        private ?SamplerInterface $sampler = null,
    ) {}

    public function create(SpanExporterInterface $exporter): TracerProvider
    {
        return new TracerProvider(
            spanProcessors: $this->processor($exporter),
            sampler: $this->sampler ?? (new SamplerFactory())->create(),
            resource: $this->resource(),
        );
    }

    private function processor(SpanExporterInterface $exporter): SpanProcessorInterface
    {
        if ($this->batch) {
            return (new BatchSpanProcessorBuilder($exporter))->build();
        }

        return new SimpleSpanProcessor($exporter);
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                'service.name' => $this->serviceName,
            ])),
        );
    }
}
