# Examples

Runnable examples for `rasuvaeff/yii3-telemetry-otel`.

| Script | Shows | Needs server? |
|---|---|---|
| `01_in_memory.php` | Export spans to an in-memory exporter and inspect their fields | no |
| `02_middleware.php` | `OtelMiddleware` opening a SERVER span that continues an incoming trace | no |
| `03_otlp_setup.php` | Building a real OTLP provider + `SpanFlusher` (offline; no span emitted) | no |
| `04_otlp_smoke.php` | Exporting a named smoke span to an OTLP receiver | yes |

## Running

From the package directory (the core is resolved via a path repo, so mount the
monorepo root):

```bash
docker run --rm -v /path/to/monorepo:/repo -w /repo/yii3-telemetry-otel \
  composer:2 php examples/01_in_memory.php
```

Or, in an app that installed the package from Packagist, just `php examples/01_in_memory.php`.

## Full-stack demo

`docker-compose/` runs an OpenTelemetry Collector + Tempo + Grafana. Point your
app at the collector (`OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318`), emit
spans, and view them in Grafana (`http://localhost:3000`, Tempo datasource).

```bash
cd examples/docker-compose
docker compose up
```
