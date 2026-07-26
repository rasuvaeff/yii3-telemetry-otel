# rasuvaeff/yii3-telemetry-otel

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-telemetry-otel.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-telemetry-otel.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry-otel/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry-otel/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry-otel/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry-otel/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-telemetry-otel/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-telemetry-otel)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-telemetry-otel/php)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-telemetry-otel.svg)](https://github.com/rasuvaeff/yii3-telemetry-otel/blob/master/LICENSE.md)
[English version](README.md)

Backend трассировки OpenTelemetry для [`rasuvaeff/yii3-telemetry`](https://github.com/rasuvaeff/yii3-telemetry).
Превращает фасад `Tracer` из ядра в настоящие span'ы, экспортируемые по OTLP в
OpenTelemetry Collector, и добавляет PSR-15 middleware корневого span'а и
распространение контекста по W3C.

> Используете AI-ассистента для программирования? В [llms.txt](llms.txt) лежит
> компактный справочник по API, который можно передать как контекст.

## Требования

- PHP 8.3+ (64-битный)
- `rasuvaeff/yii3-telemetry` ^1.0
- `open-telemetry/sdk` ^1.7, `open-telemetry/exporter-otlp` ^1.4
- PSR-18 HTTP-клиент для экспорта по OTLP (например, `guzzlehttp/guzzle`)

## Установка

```bash
composer require rasuvaeff/yii3-telemetry-otel guzzlehttp/guzzle
```

Установка этого пакета биндит сменный `TracerProviderInterface` в ядре — фасад
`Tracer` начинает выдавать экспортируемые span'ы. Не биндите провайдер ещё и
самостоятельно: это осознанная ошибка `Duplicate key` из `yiisoft/config`.

Composer спросит разрешение на плагины `php-http/discovery` и `tbachert/spi`
(транзитивные зависимости OpenTelemetry) — ответьте «да» либо пропишите их
заранее в `config.allow-plugins`.

## Использование

### Подключение (yiisoft/config)

Пакет поставляет `config/di.php`, `config/di-web.php` и `config/params.php`.
Адрес коллектора и имя сервиса задаются переменными окружения (стандартные
имена OTel):

```bash
OTEL_SERVICE_NAME=checkout-api
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
```

Добавьте `OtelMiddleware` в стек middleware (обычно первым), чтобы каждый запрос
получал корневой SERVER-span, продолжающий входящую распределённую трассировку.

Операционные переключатели в `params.php` (переопределяются в params приложения):

| Параметр | По умолчанию | Смысл |
|---|---|---|
| `enabled` | `true` (учитывает `OTEL_SDK_DISABLED=true`) | `false` биндит no-op `NullTracerProvider` — ничего не строится и не экспортируется, в error-log нет шума от недоступного коллектора |
| `content_type` | `application/x-protobuf` (учитывает `OTEL_EXPORTER_OTLP_PROTOCOL`: `http/json` → JSON) | кодировка payload'а OTLP/HTTP для настоящего OTLP-приёмника: OTel Collector, Tempo или Jaeger |
| `excluded_paths` | `[]` | точные пути запросов, которые `OtelMiddleware` пропускает — scrape/probe-эндпоинты (`/metrics`, `/health`): Prometheus, опрашивающий их каждые несколько секунд, заваливает backend трассировки одинаковыми трассами |
| `capture_query` | `true` | `url.query` на корневом span'е; значения ключей, похожих на чувствительные (`password`, `token`, `api_key`, …), заменяются на `***` на любом уровне вложенности |
| `capture_request_params` | `false` — **включать осознанно** (в payload запроса могут быть персональные данные) | пишет каждый query/form/top-level JSON-параметр как `http.request.param.<name>`; чувствительные ключи маскируются, значения обрезаются до 200 символов, JSON-тела больше 8 KiB пропускаются |
| `batch` | `true` | batch-процессор span'ов (о сбросе — ниже) |
| `register_shutdown_flush` | `true` | регистрирует shutdown-хук, сбрасывающий batch-процессор — правильное значение по умолчанию для php-fpm и CLI; отключайте на RoadRunner/Swoole, если сбрасываете через `SpanFlusher` по таймеру |
| `finish_request_before_flush` | `true` | вызывает `fastcgi_finish_request()` перед сбросом, чтобы клиент не ждал OTLP round-trip (без него замерено ~100 мс — SAPI-эмиттер Yii3 сам запрос не завершает). Отключайте, только если другие shutdown-функции ещё пишут в ответ |

### Имена span'ов и `http.route`

Именование следует HTTP semconv от OTel: `{method} {route}` с шаблоном маршрута
(`GET /users/{id}`), иначе просто `{method}` — но никогда не сырой путь (по
span'у на каждый user id уничтожили бы поиск по операциям в Tempo/Jaeger; сырой
путь всегда есть в атрибуте `url.path`). Резолвер, знающий о роутере,
подключается на стороне приложения — ему нужен `yiisoft/router`, который является
необязательной зависимостью:

```php
// config/common/di.php
use Rasuvaeff\Yii3TelemetryOtel\CurrentRouteNameResolver;
use Rasuvaeff\Yii3TelemetryOtel\RouteNameResolverInterface;

return [
    RouteNameResolverInterface::class => CurrentRouteNameResolver::class,
];
```

`CurrentRouteNameResolver` читает совпавший паттерн `yiisoft/router` уже после
работы обработчика, поэтому middleware трассировки может оставаться первым в
стеке. Несовпавшие запросы (404, сканеры) сохраняют голое имя `{method}`.
Собственный резолвер — интерфейс из одного метода:
`resolve(ServerRequestInterface): ?string`.

### Сэмплирование

Провайдер использует конфигурацию сэмплера из SDK — стандартные переменные
окружения OTel:

```bash
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1   # оставить 10% новых трасс
```

Если они не заданы, действует `parentbased_always_on` (трассировать всё, уважая
входящее решение). Чтобы зашить сэмплер жёстко, передайте его в фабрику:
`new OtelTracerProviderFactory(serviceName: '...', sampler: new AlwaysOffSampler())`.
Отброшенная трасса всё равно выполняет ваш callback — `$span` просто ничего не
записывает (зафиксированный контракт ядра).

### Ручная сборка провайдера

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

`$tracer` — это `TracerInterface` из ядра, действует зафиксированный контракт
`trace()`: возвращает значение callback'а; при исключении записывает его, ставит
статус Error, закрывает span и пробрасывает исключение дальше; вложенные span'ы
наследуют trace id родителя.

### Классы

| Класс | Назначение |
|---|---|
| `OtelTracerProvider` | `TracerProviderInterface` ядра поверх OTel SDK |
| `OtelTracer` / `OtelSpan` | адаптеры: фасад ядра → span OTel |
| `OtelTracerProviderFactory` | строит `TracerProvider` из SDK по экспортёру (по умолчанию batch) |
| `OtlpExporterFactory` | строит экспортёр span'ов OTLP/HTTP |
| `RouteNameResolverInterface` / `CurrentRouteNameResolver` | имена span'ов по шаблону маршрута (`{method} {route}`) — см. выше |
| `ConsoleCommandSpanListener` | корневой span на консольную команду (cron) — см. «Консольные команды» |
| `OtelMiddleware` | PSR-15 корневой SERVER-span + извлечение входящего контекста |
| `TraceContextExtractor` / `TraceContextInjector` | контекст W3C на вход и на выход |
| `SpanFlusher` | `forceFlush()` для долгоживущих воркеров |

### Закрытие span'ов и сброс экспортёра

Middleware закрывает корневой span в `finally` на каждом запросе — утечки span'ов
нет. Сброс batch-экспортёра — **отдельная** история: `new TracerProvider(...)` не
регистрирует автоматический сброс на shutdown, поэтому при
`register_shutdown_flush: true` (значение по умолчанию) DI-обвязка регистрирует
его за вас:

| Среда выполнения | При умолчании `register_shutdown_flush: true` |
|---|---|
| **php-fpm** | Хук срабатывает в **конце каждого запроса**, уже после отправки ответа через `fastcgi_finish_request` — один OTLP round-trip на запрос. На fpm это единственный корректный вариант: пользовательского хука на завершение воркера нет, а накопленные в batch span'ы **теряются**, когда fpm пересоздаёт воркер. (Альтернатива — `batch: false` / `SimpleSpanProcessor`: экспорт на каждый span) |
| **RoadRunner / Swoole / FrankenPHP** | Хук срабатывает один раз при выходе воркера — это безопасно, но стоит рассмотреть `register_shutdown_flush: false` + `SpanFlusher::flush()` по таймеру или каждые N запросов: тогда падение теряет максимум один интервал |
| **CLI / cron** | Хук срабатывает один раз при выходе процесса — ровно то, что нужно |

```php
use Rasuvaeff\Yii3TelemetryOtel\SpanFlusher;

$flusher = new SpanFlusher($sdkProvider);
// php-fpm / CLI:
register_shutdown_function(static fn (): bool => $flusher->flush());
// RoadRunner: вместо этого вызывайте $flusher->flush() при остановке воркера.
```

### Консольные команды

`OtelMiddleware` работает только для web. Для консольных команд (и особенно для
cron) зарегистрируйте `ConsoleCommandSpanListener` — он оборачивает
`ApplicationStartup`/`ApplicationShutdown` в активированный корневой span
`console <command>`, чтобы span'ы инструментирования БД и HTTP становились его
детьми, а не заваливали backend трассами `db.query` без корня. Ненулевой код
возврата помечает span как ошибочный.

```php
// config/console/events.php (нужен yiisoft/yii-console — необязательная зависимость)
use Rasuvaeff\Yii3TelemetryOtel\ConsoleCommandSpanListener;
use Yiisoft\Yii\Console\Event\ApplicationShutdown;
use Yiisoft\Yii\Console\Event\ApplicationStartup;

return [
    ApplicationStartup::class => [[ConsoleCommandSpanListener::class, 'onStartup']],
    ApplicationShutdown::class => [[ConsoleCommandSpanListener::class, 'onShutdown']],
];
```

Для разовых скриптов без yii-console по-прежнему работает `trace()`:

```php
$tracer->trace('cron.sync-orders', fn (SpanInterface $span) => $command->run(), traceKind: TraceKind::Internal);
```

## Безопасность

- Заголовки распределённой трассировки проверяются пропагатором ядра;
  некорректный `traceparent` игнорируется, а не принимается на веру.
- Учётные данные не помещаются в URL; адрес OTLP — это конфигурация.
- Middleware пишет `http.request.method`, `url.path`, `server.address`,
  `http.response.status_code` — избегайте добавления атрибутов с высокой
  кардинальностью или чувствительным содержимым.

## Примеры

Исполняемые скрипты — в [`examples/`](examples/): экспорт в память, middleware и
настройка OTLP-провайдера. См. [`examples/README.md`](examples/README.md).
Полный стек через `docker-compose` (collector + Tempo + Grafana) лежит в
[`examples/docker-compose/`](examples/docker-compose/).

### Дымовая проверка OTLP

Запустите этот стек, отправьте span с уникальным именем и убедитесь, что Tempo
его проиндексировал:

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318 php examples/04_otlp_smoke.php
curl -fsSG http://localhost:3200/api/search \
  --data-urlencode 'q={ name = "yii3-telemetry-otel.smoke" }'
```

Ответ Tempo обязан содержать трассу (либо найдите тот же span в Grafana на
`http://localhost:3000`). Одного HTTP 2xx от экспортёра недостаточно: обычный
HTTP-эндпоинт, дампящий запросы, примет payload, не декодировав его как OTLP.

### Анализаторы зависимостей

Этот листовой пакет выбирается корневым приложением через config-plugin и вполне
законно может не иметь ни одной ссылки на класс в автозагружаемых исходниках.
Прямую зависимость нужно сохранить: backend или мост выбирает приложение, а не
пакет-ядро. Исключение для Composer Dependency Analyser ограничивайте этим
пакетом:

```php
use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())->ignoreErrorsOnPackage(
    'rasuvaeff/yii3-telemetry-otel',
    [ErrorType::UNUSED_DEPENDENCY],
);
```

`composer-require-checker` ищет используемые, но не объявленные символы, а не
неиспользуемые пакеты, поэтому этой конфигурационной зависимости подавление для
require-checker не требуется.

## Разработка

Во время локальной разработки ядро подключается через path-репозиторий, поэтому
запускайте Docker с примонтированным **корнем монорепо** как `/repo`:

```bash
docker run --rm -v /path/to/monorepo:/repo -w /repo/yii3-telemetry-otel \
  composer:2 composer build
```

Полный набор команд и чеклист публикации — в [AGENTS.md](AGENTS.md).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
