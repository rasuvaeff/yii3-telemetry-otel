# AGENTS.md — yii3-telemetry-otel

Guidance for AI agents working on this package. Read before changing code.

## What this is

The OpenTelemetry **traces backend** for `rasuvaeff/yii3-telemetry`. It supplies
the real span export: it adapts the core `Tracer`/`Span` facade onto the
OpenTelemetry SDK, ships spans to an OTLP Collector, and provides a PSR-15 root
span middleware and W3C context propagation.

Namespace: `Rasuvaeff\Yii3TelemetryOtel`.

Public API: `OtelTracerProvider` (implements core `TracerProviderInterface`),
`OtelTracer`, `OtelSpan` (adapters), `OtelTracerProviderFactory`,
`OtlpExporterFactory`, `OtelMiddleware` (PSR-15), `TraceContextExtractor`,
`TraceContextInjector`, `SpanFlusher`, `RouteNameResolverInterface` /
`CurrentRouteNameResolver` (semconv `{method} {route}` span names),
`ConsoleCommandSpanListener` (root span per yii-console command; yiisoft/yii-console
is require-dev + require-checker whitelist; with `enabled` off the OTel API
provider key resolves to the OTel NoopTracerProvider so the listener is no-op).

The core's value types mirror OpenTelemetry 1:1, so the adapters map field-for-
field with no lookup table: `TraceKind->value == SpanKind::KIND_*`,
`SpanStatusCode->value == StatusCode::STATUS_*`, `TraceContext` = W3C fields.

## DI wiring — the backend side of core+backend

`config/di.php` binds exactly ONE swappable key: the core
`Rasuvaeff\Yii3Telemetry\TracerProviderInterface => OtelTracerProvider`. It must
**never** bind `Tracer` or the core `TracerInterface` — the core binds those.
Re-binding them here is a `yiisoft/config` `Duplicate key` error when core +
backend are installed together (by design). `ConfigWiringTest` guards this.

The middleware lives in `config/di-web.php` (web-only).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause
   (OTel's `non-empty-string` params are handled with real guards, not
   suppressions; see `OtelSpan`/`OtelTracer`).
3. **`trace()` contract is frozen (inherited from the core).** On success the
   span keeps status Unset — do NOT set Ok. On a thrown exception:
   `recordException` + status Error + `end()` in `finally` + re-throw. `end()`
   and context detach always run in `finally` (the long-running anti-leak
   guarantee). Flushing is separate (`SpanFlusher`); on long-running runtimes
   never per request — but on **php-fpm a per-request shutdown flush (or
   `batch: false`) is the only correct option**: there is no user-land
   worker-shutdown hook and `new TracerProvider()` registers no automatic
   flush, so batch-buffered spans die with the worker. Keep the README's
   per-runtime table in sync with this.
4. **Preserve the public contract.** Update README + tests with any API change.

## Local build & the path-repo / publish trap

This package `require`s `rasuvaeff/yii3-telemetry: ^1.0`, which is **not on
Packagist yet**. Local builds resolve it through a `repositories` **path** entry
(`/repo/yii3-telemetry`), so every Docker command must mount the **monorepo
root** as `/repo` (NOT the package dir as `/app`):

```bash
docker run --rm -v /home/rasuvaeff/projects/rasuvaeff:/repo \
  -w /repo/yii3-telemetry-otel composer:2 composer build
```

`make build` / `make mutation` (which mount `/app`) FAIL here, because the
installed `vendor/rasuvaeff/yii3-telemetry` is a symlink to `/repo/yii3-telemetry`.
Use the monorepo-root mount above instead.

**Before publishing:** the core `yii3-telemetry` must be released to Packagist
first, and the `repositories` block removed from `composer.json` so the standalone
GitHub CI resolves the core from Packagist. Publishing (or tagging) with the path
repo still present yields a red first CI run on an already-protected `master`.

## Commands (monorepo-root mount)

```bash
REPO=/home/rasuvaeff/projects/rasuvaeff
docker run --rm -v "$REPO":/repo -w /repo/yii3-telemetry-otel composer:2 composer build
docker run --rm -v "$REPO":/repo -w /repo/yii3-telemetry-otel composer:2 composer cs:fix
docker run --rm -v "$REPO":/repo -w /repo/yii3-telemetry-otel composer:2 composer rector
# mutation (needs pcov, monorepo mount):
docker run --rm -v "$REPO":/repo -w /repo/yii3-telemetry-otel composer:2 \
  sh -lc 'apk add --no-cache $PHPIZE_DEPS >/dev/null && pecl install pcov >/dev/null && docker-php-ext-enable pcov && composer mutation'
```

`composer.lock` is gitignored (library).

## Invariants & gotchas

- **Adapters are tested through an `InMemoryExporter`** asserting exported span
  fields (name, kind, status, attributes, exception event) — not with mocks. A
  mock cannot kill "delegating call removed" mutants. Adapter tests use
  `#[Covers(...)]` (never `#[CoversNothing]`, which yields zero mutants).
- Test the dropped-span branch with an `AlwaysOffSampler` (the default
  `AlwaysOn` never exercises `isRecording() === false`).
- `OtelMiddleware` extracts the incoming context and `activate()`s it so the
  server span continues a distributed trace; it detaches the scope in `finally`.
  Anti-leak assertion: after N requests `Span::getCurrent()->getContext()->isValid()`
  is `false`.
- Attribute keys use stable OTel semantic-convention **string literals** (no
  `open-telemetry/sem-conv` dependency — that class is deprecated).
- **Sampling**: `OtelTracerProviderFactory` defaults to the SDK `SamplerFactory`
  (honours `OTEL_TRACES_SAMPLER` / `OTEL_TRACES_SAMPLER_ARG`, default
  `parentbased_always_on`); an explicit `sampler:` argument overrides it. Do not
  hardcode a sampler in `config/di.php` — env is the configuration surface.
- Integration tests (`tests/Integration`, an OTLP Collector round-trip) are
  env-gated on `OTEL_COLLECTOR_HOST` and skipped otherwise.
- **Span names are semconv, low-cardinality**: `{method} {route}` when a
  `RouteNameResolverInterface` is wired (app-side; `yiisoft/router` is
  require-dev + `composer-require-checker.json` whitelist), bare `{method}`
  otherwise. NEVER name a span with the raw path.
- **Params toggles**: `enabled` (honours `OTEL_SDK_DISABLED`; off → di.php binds
  core `NullTracerProvider` with a dependency-free closure — nothing OTel is
  built) and `register_shutdown_flush` (default true; di.php registers
  `$provider->shutdown()` via `register_shutdown_function`). `ConfigWiringTest`
  covers both branches; keep it in sync.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- **CI workflows are SHA-pinned.** Every `uses:` references a 40-char commit SHA
  with a `# vN` comment; `permissions: { contents: read }`;
  `persist-credentials: false` on every checkout. Verify with
  `zizmor --persona=auditor .github/`.
- `examples/` is part of the public contract: keep scripts runnable.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build` (monorepo mount); paste the output. For releases also
  run mutation and `release-check`.
