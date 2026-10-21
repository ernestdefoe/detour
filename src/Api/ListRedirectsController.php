<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Like;
use Ernestdefoe\Detour\Redirect;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListRedirectsController implements RequestHandlerInterface
{
    use Admin;

    public const PER_PAGE = 50;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $query = Redirect::query();

        $q = trim((string) $this->param($request, 'q', ''));
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                foreach (['source', 'target', 'note'] as $column) {
                    Like::where($w, $column, $q, 'or');
                }
            });
        }

        $type = (string) $this->param($request, 'type', '');
        if (in_array($type, Redirect::TYPES, true)) {
            $query->where('type', $type);
        }

        $total = $query->count();

        match ((string) $this->param($request, 'sort', '')) {
            'hits' => $query->orderByDesc('hits')->orderByDesc('id'),
            'recent' => $query->orderByDesc('id'),
            // Evaluation order: what the forum checks first is listed first.
            default => $query->orderByRaw('CASE WHEN type = ? THEN 0 WHEN type = ? THEN 1 ELSE 2 END', ['exact', 'prefix'])->orderBy('position')->orderBy('id'),
        };

        $offset = max(0, (int) $this->param($request, 'offset', 0));

        return new JsonResponse([
            'data' => $query->skip($offset)->take(self::PER_PAGE)->get()->map->toArray()->all(),
            'total' => $total,
            'perPage' => self::PER_PAGE,
        ]);
    }
}
