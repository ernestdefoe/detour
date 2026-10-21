<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Like;
use Ernestdefoe\Detour\Log\Missing;
use Ernestdefoe\Detour\Rules\Matcher;
use Ernestdefoe\Detour\Rules\RuleSet;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListMissingController implements RequestHandlerInterface
{
    use Admin;

    public const PER_PAGE = 50;

    public function __construct(
        protected RuleSet $rules,
        protected Matcher $matcher,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $query = Missing::query()->where('ignored', (bool) (int) $this->param($request, 'ignored', 0));

        $q = trim((string) $this->param($request, 'q', ''));
        if ($q !== '') {
            Like::where($query, 'url', $q);
        }

        $total = $query->count();

        $this->param($request, 'sort') === 'recent'
            ? $query->orderByDesc('last_seen_at')
            : $query->orderByDesc('hits')->orderByDesc('last_seen_at');

        $offset = max(0, (int) $this->param($request, 'offset', 0));
        $set = $this->rules->get();

        $rows = $query->skip($offset)->take(self::PER_PAGE)->get()->map(function (Missing $row) use ($set) {
            [$path, $q] = array_pad(explode('?', $row->url, 2), 2, '');

            return [
                'id' => $row->id,
                'url' => $row->url,
                'hits' => $row->hits,
                'referrers' => $row->referrerList(),
                'firstSeenAt' => $row->first_seen_at->toIso8601String(),
                'lastSeenAt' => $row->last_seen_at->toIso8601String(),
                'ignored' => $row->ignored,
                // A rule that now covers it (lookups are not resolved here).
                'ruleId' => $this->matcher->covering($set, $path, $q),
            ];
        });

        return new JsonResponse([
            'data' => $rows->all(),
            'total' => $total,
            'perPage' => self::PER_PAGE,
        ]);
    }
}
