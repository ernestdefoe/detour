<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Rules\RuleSet;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The order regular-expression rules are tried in, as dragged on the admin page. */
class OrderController implements RequestHandlerInterface
{
    use Admin;

    public function __construct(
        protected ConnectionInterface $db,
        protected RuleSet $rules,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $ids = array_values(array_unique(array_map('intval', (array) ($this->body($request)['ids'] ?? []))));

        $this->db->transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                Redirect::query()->where('id', $id)->where('type', 'regex')->update(['position' => $position]);
            }
        });

        $this->rules->flush();

        return new EmptyResponse(204);
    }
}
