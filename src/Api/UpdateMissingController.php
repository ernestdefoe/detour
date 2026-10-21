<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Log\Missing;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Ignore (or stop ignoring) a logged address. */
class UpdateMissingController implements RequestHandlerInterface
{
    use Admin;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $row = Missing::query()->findOrFail($this->id($request));
        $row->ignored = (bool) ($this->body($request)['ignored'] ?? true);
        $row->save();

        return new EmptyResponse(204);
    }
}
