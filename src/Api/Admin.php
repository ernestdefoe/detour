<?php

namespace Ernestdefoe\Detour\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/** Every Detour endpoint is for administrators only. */
trait Admin
{
    protected function admin(ServerRequestInterface $request): void
    {
        RequestUtil::getActor($request)->assertAdmin();
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function param(ServerRequestInterface $request, string $key, mixed $default = null): mixed
    {
        return Arr::get($request->getQueryParams(), $key, $default);
    }

    protected function id(ServerRequestInterface $request): int
    {
        return (int) Arr::get($request->getAttribute('routeParameters', []), 'id');
    }
}
