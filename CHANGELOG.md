# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 — 2026-10-03

### Added

- `OtlpExporterFactory::create()` takes trailing `float $timeout`, `int $maxRetries`
  and `int $retryDelayMs` (defaults equal the SDK transport defaults: 10 s, 3, 100 ms),
  and the factory accepts an optional `TransportFactoryInterface` in its constructor
  (a test seam). Closes #17.
- Params `timeout` (from `OTEL_EXPORTER_OTLP_TRACES_TIMEOUT`, then
  `OTEL_EXPORTER_OTLP_TIMEOUT`, milliseconds per the OTel spec; invalid → default),
  `max_retries` and `retry_delay_ms`, wired through `config/di.php`.
- README: "Collector down / latency" section (synchronous export, local collector
  agent, short timeout, FPM worker occupancy).
- `ConsoleCommandSpanListener` takes `array $excludedCommands` and is bound in
  `config/di.php` from the new `excluded_commands` param. Closes #18.

### Changed

- **Behaviour change:** `ConsoleCommandSpanListener` no longer opens a root span for
  `queue:listen` and `queue:listen-all` (default `excluded_commands`). A root span
  living for days with every job nested under it was misleading; per-message spans
  should come from queue consume code. Set `excluded_commands` to `[]` to restore
  the old behaviour. Entries match exactly or, ending with `*`, as a prefix.

## 1.0.2 — 2026-07-26

- Rewrite `README.ru.md`. It had been machine-translated: package, class and
  method names were translated as prose, so the document described an API that
  does not exist, and 12 `@@ЛИНИЯ@@` translator artefacts were left in the text.
  The new version mirrors `README.md` section for section, keeping identifiers,
  parameter names and code blocks in English.
- `AGENTS.md`: the "When you finish" checklist now requires `README.ru.md`
  alongside `README.md`, matching the monorepo rule — the omission is how the
  Russian version drifted in the first place.

## 1.0.1 — 2026-07-25

- Correct OTLP receiver guidance: remove the unsupported Buggregator claim and
  add a runnable OTLP smoke script with Tempo/Grafana verification steps.

## 1.0.0 — 2026-07-10

- Semconv span naming: `{method} {http.route}` via `RouteNameResolverInterface`
  (+ `CurrentRouteNameResolver` for `yiisoft/router`); bare `{method}` fallback —
  never the raw path. `http.route` attribute on matched routes.
- `addEvent()` / `startNanos` adapter support (core contract).
- Params toggles: `enabled` (honours `OTEL_SDK_DISABLED`) binds
  `NullTracerProvider` when off; `register_shutdown_flush` (default `true`)
  registers a shutdown flush so php-fpm batch spans are never silently lost;
  `content_type` (honours `OTEL_EXPORTER_OTLP_PROTOCOL`, e.g. `http/json` for
  JSON-only receivers); `excluded_paths` — `OtelMiddleware` skips scrape/probe
  endpoints so Prometheus polling doesn't flood the tracing backend.
- `ConsoleCommandSpanListener` — root span `console <command>` bracketing
  `yiisoft/yii-console` Startup/Shutdown events (activated: instrumentation
  spans become children; cron jobs stop flooding the backend with root-less
  `db.query` traces; non-zero exit code → Error).
- `finish_request_before_flush` (default `true`): the shutdown hook calls
  `fastcgi_finish_request()` before flushing, so the client never waits for
  the OTLP export round-trip (measured ~+100 ms per request without it).
- Request data on the root span: `url.query` (default on, sensitive values
  masked recursively) and opt-in `capture_request_params` —
  `http.request.param.<name>` attributes from query/form/JSON-body parameters
  (sensitive keys `***`, values truncated, bodies over 8 KiB skipped).
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
- `OtelTracerProviderFactory` accepts an explicit sampler; by default the SDK
  `SamplerFactory` applies (`OTEL_TRACES_SAMPLER` / `OTEL_TRACES_SAMPLER_ARG`,
  falling back to `parentbased_always_on`).
- Per-runtime flush recipes documented (php-fpm has no worker-shutdown hook —
  register a per-request shutdown flush or use `batch: false`).
