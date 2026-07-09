# rasuvaeff/yii3-telemetry-otel

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-telemetry-otel.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-telemetry-otel.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry-otel/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry-otel/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry-otel/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry-otel/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-telemetry-otel/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-telemetry-otel)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-telemetry-otel/php)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-telemetry-otel.svg)](https://github.com/rasuvaeff/yii3-telemetry-otel/blob/master/LICENSE.md)

OpenTelemetry tracing backend for [`rasuvaeff/yii3-telemetry`](https://github.com/rasuvaeff/yii3-telemetry).
It turns the core `Tracer` facade into real spans exported over OTLP to an
OpenTelemetry Collector, plus a PSR-15 root-span middleware and W3C context
propagation.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference
> you can pass as context.

## Requirements

- PHP 8.3+ (64-bit)
- `rasuvaeff/yii3-telemetry` ^1.0
- `open-telemetry/sdk` ^1.7, `open-telemetry/exporter-otlp` ^1.4
- A PSR-18 HTTP client for OTLP export (e.g. `guzzlehttp/guzzle`)

## Installation

```bash
composer require rasuvaeff/yii3-telemetry-otel guzzlehttp/guzzle
```

Installing this package binds the swappable `TracerProviderInterface` in the core
— the `Tracer` facade now produces exported spans. Do **not** also bind the
provider yourself (that is a deliberate `yiisoft/config` `Duplicate key` error).

Composer will ask to trust the `php-http/discovery` and `tbachert/spi` plugins
(transitive OpenTelemetry dependencies) — answer yes, or preconfigure them in
`config.allow-plugins`.

## Usage

### Wire it (yiisoft/config)

The package ships `config/di.php`, `config/di-web.php`, and `config/params.php`.
Configure the collector endpoint and service name via env vars (standard OTel
names):

```bash
OTEL_SERVICE_NAME=checkout-api
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
```

Add `OtelMiddleware` to your middleware stack (typically first) so every request
gets a SERVER root span that continues any incoming distributed trace.

### Sampling

The provider uses the SDK sampler configuration — the standard OTel env vars:

```bash
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1   # keep 10% of new traces
```

Unset, it defaults to `parentbased_always_on` (trace everything, honour the
incoming decision). To hardcode a sampler instead, pass it to the factory:
`new OtelTracerProviderFactory(serviceName: '...', sampler: new AlwaysOffSampler())`.
A dropped trace still runs your callback — `$span` is simply non-recording (the
frozen core contract).

### Build a provider manually

```php
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProvider;
use Rasuvaeff\Yii3TelemetryOtel\OtelTracerProviderFactory;
use Rasuvaeff\Yii3TelemetryOtel\OtlpExporterFactory;

$exporter = (new OtlpExporterFactory())->create('http://collector:4318');
$sdkProvider = (new OtelTracerProviderFactory(serviceName: 'checkout-api'))->create($exporter);

$tracer = (new OtelTracerProvider($sdkProvider))->getTracer();
$tracer->trace('checkout.process', static function ($span): void {
    $span->setAttribute('order.id', 'ORD-1');
});
```

The `$tracer` is a core `TracerInterface` — the frozen `trace()` contract applies
(returns the callback value; on exception records it, sets status Error, ends,
re-throws; nested spans inherit the parent trace id).

### Classes

| Class | Purpose |
|---|---|
| `OtelTracerProvider` | core `TracerProviderInterface` over the OTel SDK |
| `OtelTracer` / `OtelSpan` | adapters: core facade → OTel span |
| `OtelTracerProviderFactory` | builds an SDK `TracerProvider` from an exporter (batch by default) |
| `OtlpExporterFactory` | builds the OTLP/HTTP span exporter |
| `OtelMiddleware` | PSR-15 SERVER root span + incoming-context extraction |
| `TraceContextExtractor` / `TraceContextInjector` | W3C context in / out |
| `SpanFlusher` | `forceFlush()` for long-running workers |

### Ending spans vs flushing the exporter

Middleware ends the root span in `finally` every request (no span leak). Flushing
the batch exporter is **separate**, and the right hook depends on the runtime —
`new TracerProvider(...)` registers **no** automatic shutdown flush:

| Runtime | Recipe |
|---|---|
| **php-fpm** | There is no user-land "worker shutdown" hook — `register_shutdown_function` runs at the **end of every request**. Either accept per-request flushing (`register_shutdown_function([$flusher, 'flush'])` — one OTLP round-trip per request, after the response was sent with `fastcgi_finish_request`), or set `batch: false` (`SimpleSpanProcessor`, export per span). Do NOT skip both: spans buffered in a batch are **lost** when fpm recycles the worker |
| **RoadRunner** | `SpanFlusher::flush()` on worker stop / every N requests / a timer — never per request |
| **Swoole / FrankenPHP** | Periodic tick or worker-shutdown callback |
| **CLI / cron** | `register_shutdown_function([$flusher, 'flush'])` once at bootstrap |

```php
use Rasuvaeff\Yii3TelemetryOtel\SpanFlusher;

$flusher = new SpanFlusher($sdkProvider);
// php-fpm / CLI:
register_shutdown_function(static fn (): bool => $flusher->flush());
// RoadRunner: call $flusher->flush() on worker stop instead.
```

### Console commands

`OtelMiddleware` is web-only. In console commands open the root span manually and
make sure a flush is registered (see above):

```php
$tracer->trace('cron.sync-orders', fn (SpanInterface $span) => $command->run(), traceKind: TraceKind::Internal);
```

## Security

- Distributed-trace headers are validated by the core propagator; malformed
  `traceparent` is ignored, not trusted.
- No credentials are placed in URLs; the OTLP endpoint is configuration.
- The middleware records `http.request.method`, `url.path`, `server.address`,
  `http.response.status_code` — avoid adding high-cardinality or sensitive
  attributes.

## Examples

Runnable scripts in [`examples/`](examples/): in-memory export, the middleware,
and OTLP provider setup. See [`examples/README.md`](examples/README.md). A
full-stack `docker-compose` (collector + Tempo + Grafana) lives in
[`examples/docker-compose/`](examples/docker-compose/).

## Development

The core is resolved via a path repository during local development, so run
Docker with the **monorepo root** mounted as `/repo`:

```bash
docker run --rm -v /path/to/monorepo:/repo -w /repo/yii3-telemetry-otel \
  composer:2 composer build
```

See [AGENTS.md](AGENTS.md) for the full command set and the publish checklist.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
