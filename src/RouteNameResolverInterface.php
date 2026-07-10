<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3TelemetryOtel;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the low-cardinality route template (`/users/{id}`) for the root
 * span's name and `http.route` attribute. Return `null` when no route matched —
 * the span name then stays `{method}` (never the raw path, which would explode
 * span-name cardinality in the tracing backend).
 *
 * @api
 */
interface RouteNameResolverInterface
{
    public function resolve(ServerRequestInterface $request): ?string;
}
