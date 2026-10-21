<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Csv;
use Ernestdefoe\Detour\Log\Missing;
use Ernestdefoe\Detour\Redirect;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The redirects (or, with ?log=1, the 404 log) as CSV. */
class ExportController implements RequestHandlerInterface
{
    use Admin;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        if ($this->param($request, 'log')) {
            $csv = Csv::write(['url', 'hits', 'first_seen', 'last_seen', 'referrers', 'ignored'], Missing::query()->orderByDesc('hits')->cursor()->map(fn (Missing $m) => [
                $m->url, $m->hits, $m->first_seen_at->toIso8601String(), $m->last_seen_at->toIso8601String(), implode(' ', $m->referrerList()), $m->ignored,
            ]));
            $name = 'detour-404-log.csv';
        } else {
            $csv = Csv::write(Csv::COLUMNS, Redirect::query()->orderBy('id')->cursor()->map(fn (Redirect $r) => [
                $r->type, $r->source, $r->target, $r->status, $r->mode, $r->keep_query, $r->external, $r->lookup, $r->run_id, $r->preset, $r->enabled, $r->note,
            ]));
            $name = 'detour-redirects.csv';
        }

        return new TextResponse($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
