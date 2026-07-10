<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3TelemetryOtel\CurrentRouteNameResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;

#[Test]
#[Covers(CurrentRouteNameResolver::class)]
final class CurrentRouteNameResolverTest
{
    public function resolvesTheMatchedRoutePattern(): void
    {
        $currentRoute = new CurrentRoute();
        $currentRoute->setRouteWithArguments(Route::get('/users/{id}'), ['id' => '123']);

        $resolver = new CurrentRouteNameResolver($currentRoute);
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://api.example/users/123');

        Assert::same($resolver->resolve($request), '/users/{id}');
    }

    public function resolvesNullWhenNoRouteMatched(): void
    {
        $resolver = new CurrentRouteNameResolver(new CurrentRoute());
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://api.example/nope');

        Assert::null($resolver->resolve($request));
    }
}
