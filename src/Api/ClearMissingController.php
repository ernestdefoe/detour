<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Log\Missing;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Empties the 404 log (ignored addresses stay ignored unless asked). */
class ClearMissingController implements RequestHandlerInterface
{
    use Admin;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        Missing::query()
            ->when(! ($this->body($request)['includeIgnored'] ?? false), fn ($q) => $q->where('ignored', false))
            ->delete();

        return new EmptyResponse(204);
    }
}
