# rasuvaeff/yii3-telemetry-otel
[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-telemetry-otel.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-telemetry-otel.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry-otel/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry-otel/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry-otel/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry-otel/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-telemetry-otel/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-telemetry-otel)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-telemetry-otel/php)](https://packagist.org/packages/rasuvaeff/yii3-telemetry-otel)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-telemetry-otel.svg)](https://github.com/rasuvaeff/yii3-telemetry-otel/blob/master/LICENSE.md)
OpenTelemetry tracing backend for [`rasuvaeff/yii3-telemetry`](https://github.com/rasuvaeff/yii3-telemetry).
Он превращает основной фасад Tracer в реальные промежутки, экспортируемые через OTLP в сборщик
 OpenTelemetry, а также промежуточное программное обеспечение корневого диапазона PSR-15 и распространение контекста W3C
.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) имеет компактную ссылку на API
 > которую можно передать в качестве контекста. @@ЛИНИЯ@@
## Требования
- PHP 8.3+ (64-битная версия)
 - `rasuvaeff/yii3-telemetry` ^1.0
 - `open-telemetry/sdk` ^1.7, `open-telemetry/exporter-otlp` ^1.4
 - HTTP-клиент PSR-18 для экспорта OTLP (например, `guzzlehttp/guzzle`)

## Установка
```bash
composer require rasuvaeff/yii3-telemetry-otel guzzlehttp/guzzle
```
Установка этого пакета привязывает заменяемый интерфейс TracerProviderInterface в ядре
 — фасад Tracer теперь создает экспортированные диапазоны. **Не** также самостоятельно привязывайте поставщика
 (это преднамеренная ошибка `yiisoft/config` `Duplate key`).

 Composer запросит доверие к плагинам `php-http/discovery` и `tbachert/spi`
 (транзитивные зависимости OpenTelemetry) — ответьте утвердительно или предварительно настройте их в
 `config.allow-plugins`. @@ЛИНИЯ@@
## Использование
### Подключите его (yiisoft/config)
В состав пакета входят `config/di.php`, `config/di-web.php` и `config/params.php`.
 Настройте конечную точку сборщика и имя службы с помощью переменных env (стандартные имена OTel
):

```bash
OTEL_SERVICE_NAME=checkout-api
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
```
Добавьте OtelMiddleware в свой стек промежуточного программного обеспечения (обычно первым), чтобы каждый запрос
 получал корневой диапазон SERVER, который продолжает любую входящую распределенную трассировку.

 Операционные переключатели в `params.php` (переопределяемые в параметрах вашего приложения):

 | Парам | По умолчанию | Значение |
 |---|---|---|
 | `включено` | `true` (учитывается `OTEL_SDK_DISABLED=true`) | `false` связывает неактивный `NullTracerProvider` — ничего не создается и не экспортируется, нет шума в журнале ошибок от недостижимого коллектора |
 | `тип_контента` | `application/x-protobuf` (с уважением `OTEL_EXPORTER_OTLP_PROTOCOL`: `http/json` → JSON) | Кодирование полезной нагрузки OTLP/HTTP; некоторые приемники (например, Buggregator) корректно обрабатывают только JSON |
 | `исключенные_пути` | `[]` | точные пути запросов пропускаются `OtelMiddleware` — очистка/проверка конечных точек (`/metrics`, `/health`): опрос Prometheus каждые несколько секунд переполняет серверную часть трассировки идентичными трассировками |
 | `capture_query` | `правда` | `url.query` в корневом диапазоне; значения чувствительных ключей (`password`, `token`, `api_key`, …) заменяются на `***` на каждом уровне вложенности |
 | `capture_request_params` | `false` — **сознательное согласие** (полезные данные запроса могут содержать персональные данные) | записывает каждый параметр JSON-body запроса/формы/верхнего уровня как `http.request.param.<name>`; конфиденциальные ключи замаскированы, значения усечены до 200 символов, тела JSON размером более 8 КиБ пропущены |
 | `партия` | `правда` | пакетный процессор (см. промывку ниже) |
 | `register_shutdown_flush` | `правда` | регистрирует перехватчик завершения работы, который очищает пакетный процессор — правильное значение по умолчанию для php-fpm и CLI; отключить в RoadRunner/Swoole, если вы выполняете очистку через SpanFlusher по таймеру |
 | `finish_request_before_flush` | `правда` | вызывает `fastcgi_finish_request()` перед сбросом, поэтому клиент никогда не ждет двустороннего обхода OTLP (без него измерено ~ 100 мс — эмиттер SAPI Yii3 не завершает запрос сам). Отключайте только в том случае, если другие функции завершения работы все еще записывают ответ | @@ЛИНИЯ@@
### Промежуточные имена и `http.route`
Именование интервала соответствует semconv HTTP OTel: `{method} {route}` с шаблоном маршрута
 (`GET /users/{id}`), обычный `{method}` в противном случае — никогда необработанный путь
 (одно имя интервала на каждый идентификатор пользователя может нарушить операцию поиска в Tempo/Jaeger; необработанный путь
 всегда находится в атрибуте `url.path`). Подключите преобразователь
 на стороне приложения (ему требуется `yiisoft/router`, который является необязательным):

```php
// config/common/di.php
use Rasuvaeff\Yii3TelemetryOtel\CurrentRouteNameResolver;
use Rasuvaeff\Yii3TelemetryOtel\RouteNameResolverInterface;

return [
    RouteNameResolverInterface::class => CurrentRouteNameResolver::class,
];
```
CurrentRouteNameResolver считывает соответствующий шаблон yiisoft/router после запуска обработчика
, поэтому промежуточное программное обеспечение трассировки может оставаться первым в стеке. Несовпадающие запросы
 (404, сканеры) сохраняют чистое имя `{method}`. Пользовательский преобразователь — это интерфейс
 с одним методом: `resolve(ServerRequestInterface): ?string`. @@ЛИНИЯ@@
### Выборка
Поставщик использует конфигурацию сэмплера SDK — стандартные переменные среды OTel:

```bash
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1   # keep 10% of new traces
```
Если этот параметр не установлен, по умолчанию используется `parentbased_always_on` (отслеживать все, учитывать входящее решение
). Вместо этого, чтобы жестко запрограммировать сэмплер, передайте его фабрике:
 `new OtelTracerProviderFactory(serviceName: '...', sampler: new AlwaysOffSampler())`.
 Отброшенная трассировка по-прежнему запускает ваш обратный вызов — `$span` просто не записывает (замороженный основной контракт
). @@ЛИНИЯ@@
### Создайте поставщика вручную
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
`$tracer` — это основной `TracerInterface` — замороженный контракт `trace()` применяет
 (возвращает значение обратного вызова; при исключении его записывает, устанавливает статус Error, завершается,
 выдает повторно; вложенные диапазоны наследуют родительский идентификатор трассировки). @@ЛИНИЯ@@
### Классы
| Класс | Цель |
 |---|---|
 | `OtelTracerProvider` | ядро `TracerProviderInterface` через OTel SDK |
 | `OtelTracer` / `OtelSpan` | адаптеры: основной фасад → OTel пролет |
 | `OtelTracerProviderFactory` | собирает SDK TracerProvider из экспортера (по умолчанию пакетный режим) |
 | `OtlpExporterFactory` | создает экспортер интервалов OTLP/HTTP |
 | `RouteNameResolverInterface` / `CurrentRouteNameResolver` | имена диапазонов шаблонов маршрутов (`{method} {route}`) — см. выше |
 | `ConsoleCommandSpanListener` | корневой диапазон для каждой консольной команды (cron) — см. Консольные команды |
 | `OtelMiddleware` | PSR-15 SERVER корневой диапазон + извлечение входящего контекста |
 | `TraceContextExtractor` / `TraceContextInjector` | Контекст W3C в/из |
 | `СпанФлашер` | `forceFlush()` для долго работающих работников | @@ЛИНИЯ@@
### Завершение промежутков и очистка экспортера
Промежуточное программное обеспечение завершает корневой диапазон «наконец» в каждом запросе (без утечки диапазона). Сброс
 пакетного экспортера является **отдельным** — `new TracerProvider(...)` регистрирует **нет**
 сброс автоматического выключения, поэтому с `register_shutdown_flush: true` (по умолчанию)
 проводка DI регистрирует один для вас:

 | Время выполнения | С значением по умолчанию `register_shutdown_flush: true` |
 |---|---|
 | **php-фпм** | Перехватчик запускается в **конце каждого запроса**, после того, как ответ был отправлен с помощью `fastcgi_finish_request` — один цикл OTLP на каждый запрос. Это единственный правильный вариант для fpm: здесь нет перехватчика отключения рабочего процесса на пользовательской территории, а диапазоны с пакетной буферизацией в противном случае **теряются**, когда fpm перезапускает работника. (`batch: false` / `SimpleSpanProcessor` — альтернатива: экспорт для каждого диапазона) |
 | **RoadRunner/Swoole/FrankenPHP** | Перехватчик срабатывает один раз при выходе рабочего процесса — безопасно, но учтите `register_shutdown_flush: false` + `SpanFlusher::flush()` по таймеру / каждые N запросов, поэтому при сбое теряется не более одного интервала |
 | **CLI/cron** | Хук срабатывает один раз при выходе из процесса — совершенно верно | @@ЛИНИЯ@@
```php
use Rasuvaeff\Yii3TelemetryOtel\SpanFlusher;

$flusher = new SpanFlusher($sdkProvider);
// php-fpm / CLI:
register_shutdown_function(static fn (): bool => $flusher->flush());
// RoadRunner: call $flusher->flush() on worker stop instead.
```
### Консольные команды
`OtelMiddleware` доступен только через Интернет. Для консольных команд (cron!) зарегистрируйте
 `ConsoleCommandSpanListener` — он заключает в скобки `ApplicationStartup`/`ApplicationShutdown`
 в АКТИВИРОВАННОМ корневом диапазоне `console <command>`, так что инструменты DB/HTTP охватывают
, становясь его дочерними элементами, вместо того, чтобы заполнять серверную часть безкорневыми трассировками `db.query`
. Ненулевой код выхода помечает интервал как ошибку. @@ЛИНИЯ@@
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
Для специальных скриптов без консоли yii, трассировка() все еще работает:

```php
$tracer->trace('cron.sync-orders', fn (SpanInterface $span) => $command->run(), traceKind: TraceKind::Internal);
```
## Безопасность
- Заголовки распределенной трассировки проверяются основным распространителем; неправильно сформированный
 `traceparent` игнорируется, ему не доверяют.
 — в URL-адресах не указываются учетные данные; конечная точка OTLP — это конфигурация.
 — промежуточное программное обеспечение записывает `http.request.method`, `url.path`, `server.address`,
 `http.response.status_code` — избегайте добавления высокомощных или чувствительных атрибутов
. @@ЛИНИЯ@@
## Примеры
Запускаемые сценарии в [`examples/`](examples/): экспорт в память, промежуточное программное обеспечение,
 и настройка провайдера OTLP. См. [`examples/README.md`](examples/README.md). Полнофункциональный `docker-compose`
 (коллектор + Tempo + Grafana) находится в
 [`examples/docker-compose/`](examples/docker-compose/). @@ЛИНИЯ@@
## Разработка
Ядро разрешается через репозиторий путей во время локальной разработки, поэтому запустите
 Docker с **корнем монорепо**, смонтированным как `/repo`:

```bash
docker run --rm -v /path/to/monorepo:/repo -w /repo/yii3-telemetry-otel \
  composer:2 composer build
```
Полный набор команд и контрольный список публикации см. в [AGENTS.md](AGENTS.md). @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
