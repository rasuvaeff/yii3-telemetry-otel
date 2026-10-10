<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
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
        private ?int $maxQueueSize = null,
        private ?int $scheduledDelayMillis = null,
        private ?int $exportTimeoutMillis = null,
        private ?int $maxExportBatchSize = null,
        private ?bool $autoFlush = null,
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
            return new BatchSpanProcessor(
                $exporter,
                \OpenTelemetry\API\Common\Time\Clock::getDefault(),
                $this->configuredInt(
                    $this->maxQueueSize,
                    Variables::OTEL_BSP_MAX_QUEUE_SIZE,
                    BatchSpanProcessor::DEFAULT_MAX_QUEUE_SIZE,
                ),
                $this->configuredInt(
                    $this->scheduledDelayMillis,
                    Variables::OTEL_BSP_SCHEDULE_DELAY,
                    BatchSpanProcessor::DEFAULT_SCHEDULE_DELAY,
                ),
                $this->configuredInt(
                    $this->exportTimeoutMillis,
                    Variables::OTEL_BSP_EXPORT_TIMEOUT,
                    BatchSpanProcessor::DEFAULT_EXPORT_TIMEOUT,
                ),
                $this->configuredInt(
                    $this->maxExportBatchSize,
                    Variables::OTEL_BSP_MAX_EXPORT_BATCH_SIZE,
                    BatchSpanProcessor::DEFAULT_MAX_EXPORT_BATCH_SIZE,
                ),
                $this->autoFlush ?? true,
            );
        }

        return new SimpleSpanProcessor($exporter);
    }

    private function configuredInt(?int $value, string $variable, int $default): int
    {
        return $value ?? Configuration::getInt($variable, $default);
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
