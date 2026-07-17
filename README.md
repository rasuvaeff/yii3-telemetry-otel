# rasuvaeff/yii3-telemetry-otel

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-telemetry-otel.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-telemetry-otel.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry-otel/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry-otel/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry-otel/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry-otel/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-telemetry-otel/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-telemetry-otel)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-telemetry-otel/php)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-telemetry-otel.svg)](https://github.com/rasuvaeff/yii3-telemetry-otel/blob/master/LICENSE.md)
[Русская версия](README.ru.md)

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

Operational toggles in `params.php` (overridable in your app params):

| Param | Default | Meaning |
|---|---|---|
| `enabled` | `true` (honours `OTEL_SDK_DISABLED=true`) | `false` binds the no-op `NullTracerProvider` — nothing is built or exported, no error-log noise from an unreachable collector |
| `content_type` | `application/x-protobuf` (honours `OTEL_EXPORTER_OTLP_PROTOCOL`: `http/json` → JSON) | OTLP/HTTP payload encoding; some receivers (e.g. Buggregator) only speak JSON cleanly |
| `excluded_paths` | `[]` | exact request paths `OtelMiddleware` skips — scrape/probe endpoints (`/metrics`, `/health`): Prometheus polling every few seconds floods the tracing backend with identical traces |
| `capture_query` | `true` | `url.query` on the root span; values of sensitive-looking keys (`password`, `token`, `api_key`, …) are replaced by `***` at every nesting level |
| `capture_request_params` | `false` — **opt in consciously** (request payloads may carry personal data) | records each query / form / top-level JSON-body parameter as `http.request.param.<name>`; sensitive keys masked, values truncated to 200 chars, JSON bodies over 8 KiB skipped |
| `batch` | `true` | batch span processor (see flushing below) |
| `register_shutdown_flush` | `true` | registers a shutdown hook that flushes the batch processor — the correct default on php-fpm and CLI; disable on RoadRunner/Swoole if you flush via `SpanFlusher` on a timer |
| `finish_request_before_flush` | `true` | calls `fastcgi_finish_request()` before the flush, so the client never waits for the OTLP round-trip (~100 ms measured without it — Yii3's SAPI emitter does not finish the request itself). Disable only if other shutdown functions still write to the response |

### Span names & `http.route`

Span naming follows the OTel HTTP semconv: `{method} {route}` with a route
template (`GET /users/{id}`), plain `{method}` otherwise — never the raw path
(one span name per user id would wreck operation search in Tempo/Jaeger; the
raw path is always in the `url.path` attribute). Wire the router-aware resolver
app-side (it needs `yiisoft/router`, which is optional):

```php
// config/common/di.php
use Rasuvaeff\Yii3TelemetryOtel\CurrentRouteNameResolver;
use Rasuvaeff\Yii3TelemetryOtel\RouteNameResolverInterface;

return [
    RouteNameResolverInterface::class => CurrentRouteNameResolver::class,
];
```

`CurrentRouteNameResolver` reads the matched `yiisoft/router` pattern after the
handler ran, so the tracing middleware can stay first in the stack. Unmatched
requests (404s, scanners) keep the bare `{method}` name. A custom resolver is a
one-method interface: `resolve(ServerRequestInterface): ?string`.

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
| `RouteNameResolverInterface` / `CurrentRouteNameResolver` | route-template span names (`{method} {route}`) — see above |
| `ConsoleCommandSpanListener` | root span per console command (cron) — see Console commands |
| `OtelMiddleware` | PSR-15 SERVER root span + incoming-context extraction |
| `TraceContextExtractor` / `TraceContextInjector` | W3C context in / out |
| `SpanFlusher` | `forceFlush()` for long-running workers |

### Ending spans vs flushing the exporter

Middleware ends the root span in `finally` every request (no span leak). Flushing
the batch exporter is **separate** — `new TracerProvider(...)` registers **no**
automatic shutdown flush, so with `register_shutdown_flush: true` (the default)
the DI wiring registers one for you:

| Runtime | With the default `register_shutdown_flush: true` |
|---|---|
| **php-fpm** | The hook runs at the **end of every request**, after the response was sent with `fastcgi_finish_request` — one OTLP round-trip per request. That is the only correct option on fpm: there is no user-land worker-shutdown hook, and batch-buffered spans are otherwise **lost** when fpm recycles the worker. (`batch: false` / `SimpleSpanProcessor` is the alternative: export per span) |
| **RoadRunner / Swoole / FrankenPHP** | The hook fires once at worker exit — safe, but consider `register_shutdown_flush: false` + `SpanFlusher::flush()` on a timer / every N requests, so a crash loses at most one interval |
| **CLI / cron** | The hook fires once at process exit — exactly right |

```php
use Rasuvaeff\Yii3TelemetryOtel\SpanFlusher;

$flusher = new SpanFlusher($sdkProvider);
// php-fpm / CLI:
register_shutdown_function(static fn (): bool => $flusher->flush());
// RoadRunner: call $flusher->flush() on worker stop instead.
```

### Console commands

`OtelMiddleware` is web-only. For console commands (cron!) register
`ConsoleCommandSpanListener` — it brackets `ApplicationStartup`/`ApplicationShutdown`
in an ACTIVATED root span `console <command>`, so DB/HTTP instrumentation spans
become its children instead of flooding the backend with root-less `db.query`
traces. A non-zero exit code marks the span as an error.

```php
// config/console/events.php (needs yiisoft/yii-console — optional dep)
use Rasuvaeff\Yii3TelemetryOtel\ConsoleCommandSpanListener;
use Yiisoft\Yii\Console\Event\ApplicationShutdown;
use Yiisoft\Yii\Console\Event\ApplicationStartup;

return [
    ApplicationStartup::class => [[ConsoleCommandSpanListener::class, 'onStartup']],
    ApplicationShutdown::class => [[ConsoleCommandSpanListener::class, 'onShutdown']],
];
```

For ad-hoc scripts without yii-console, `trace()` still works:

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
