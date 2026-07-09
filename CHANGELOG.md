# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — Unreleased

- OpenTelemetry traces backend for `rasuvaeff/yii3-telemetry`.
- `OtelTracerProvider` / `OtelTracer` / `OtelSpan` adapt the core facade onto the
  OpenTelemetry SDK (types map field-for-field, no lookup table).
- `OtelTracerProviderFactory` (batch/simple processor + service resource) and
  `OtlpExporterFactory` (OTLP/HTTP exporter).
- `OtelMiddleware` (PSR-15): SERVER root span, incoming W3C context extraction,
  HTTP attributes, 5xx → Error, span end + scope detach in `finally`.
- `TraceContextExtractor` / `TraceContextInjector` bridge core W3C context and
  the OTel context.
- `SpanFlusher` wraps `TracerProvider::forceFlush()` for long-running workers.
- `OtelTracer::startSpan()` — a manual (non-activated) OTel span for split
  begin/end instrumentation, backing the core `TracerInterface::startSpan()`.
- `yiisoft/config` wiring: binds only the core `TracerProviderInterface`.
