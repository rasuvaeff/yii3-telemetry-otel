# Examples

Runnable examples for `rasuvaeff/yii3-telemetry-otel`.

| Script | Shows | Needs server? |
|---|---|---|
| `01_in_memory.php` | Export spans to an in-memory exporter and inspect their fields | no |
| `02_middleware.php` | `OtelMiddleware` opening a SERVER span that continues an incoming trace | no |
| `03_otlp_setup.php` | Building a real OTLP provider + `SpanFlusher` (offline; no span emitted) | no |
| `04_otlp_smoke.php` | Exporting a named smoke span to an OTLP receiver | yes |
| `05_yii_di.php` | Resolving the shipped core/backend DI definitions with an in-memory exporter | no |

## Running

Install the package in an application from Packagist, then run the offline
examples:

```bash
composer require rasuvaeff/yii3-telemetry-otel nyholm/psr7
php examples/01_in_memory.php
php examples/02_middleware.php
php examples/03_otlp_setup.php
php examples/05_yii_di.php
```

For a source checkout that still uses the monorepo path repository, mount the
monorepo root instead:

```bash
docker run --rm -v /path/to/monorepo:/repo -w /repo/yii3-telemetry-otel \
  composer:2 php examples/01_in_memory.php
```

The smoke example (`04_otlp_smoke.php`) additionally needs a reachable OTLP
HTTP receiver and `OTEL_EXPORTER_OTLP_ENDPOINT`.

## Full-stack demo

`docker-compose/` runs an OpenTelemetry Collector + Tempo + Grafana. Point your
app at the collector (`OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318`), emit
spans, and view them in Grafana (`http://localhost:3000`, Tempo datasource).

```bash
cd examples/docker-compose
docker compose up
```
