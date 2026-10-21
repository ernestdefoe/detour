<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Redirect;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DeleteRedirectController implements RequestHandlerInterface
{
    use Admin;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        Redirect::query()->findOrFail($this->id($request))->delete();

        return new EmptyResponse(204);
    }
}
