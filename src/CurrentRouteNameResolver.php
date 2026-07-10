<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Router-aware {@see RouteNameResolverInterface} over `yiisoft/router`: resolves
 * to the matched route pattern (`/users/{id}`), which is low-cardinality by
 * construction; `null` for unmatched requests (404s, scanners).
 *
 * `CurrentRoute` is populated during routing, and {@see OtelMiddleware} reads
 * the resolver after the handler ran — so placing the tracing middleware before
 * the router middleware works as expected.
 *
 * Wire it app-side (`yiisoft/router` is optional):
 * `RouteNameResolverInterface::class => CurrentRouteNameResolver::class`.
 *
 * @api
 */
final readonly class CurrentRouteNameResolver implements RouteNameResolverInterface
{
    public function __construct(
        private CurrentRoute $currentRoute,
    ) {}

    #[\Override]
    public function resolve(ServerRequestInterface $request): ?string
    {
        return $this->currentRoute->getPattern();
    }
}
