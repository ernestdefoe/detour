<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Rules\Presets;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/** The migration presets, which are installed, and the Importer runs they can map through. */
class ListPresetsController implements RequestHandlerInterface
{
    use Admin;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $installed = Redirect::query()->whereNotNull('preset')
            ->groupBy('preset')
            ->selectRaw('preset, COUNT(*) AS rules, SUM(hits) AS hits, MAX(run_id) AS run_id')
            ->get()->keyBy('preset');

        $platforms = [];
        foreach (Presets::all() as $key => $rules) {
            $row = $installed->get($key);
            $platforms[] = [
                'key' => $key,
                'rules' => array_map(fn ($r) => ['lookup' => $r[0], 'pattern' => $r[1], 'example' => $r[2]], $rules),
                'installed' => $row ? (int) $row->getAttribute('rules') : 0,
                'hits' => $row ? (int) $row->getAttribute('hits') : 0,
                'runId' => $row && $row->getAttribute('run_id') !== null ? (int) $row->getAttribute('run_id') : null,
            ];
        }

        return new JsonResponse([
            'platforms' => $platforms,
            'runs' => $this->runs(),
            // The Importer's own old-address redirects answer before Detour's presets.
            'importerRedirects' => (int) $this->settings->get('ernestdefoe-importer.redirect_run', 0) > 0,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function runs(): array
    {
        try {
            if (! $this->db->getSchemaBuilder()->hasTable('importer_runs') || ! $this->db->getSchemaBuilder()->hasTable('importer_map')) {
                return [];
            }

            $counts = $this->db->table('importer_map')
                ->select('run_id', 'kind')
                ->selectRaw('COUNT(*) AS n')
                ->groupBy('run_id', 'kind')
                ->get()
                ->groupBy('run_id');

            return $this->db->table('importer_runs')->orderByDesc('id')->limit(50)
                ->get(['id', 'source', 'status', 'created_at'])
                ->map(fn ($run) => [
                    'id' => (int) $run->id,
                    'source' => (string) $run->source,
                    'status' => (string) $run->status,
                    'createdAt' => $run->created_at,
                    'mapped' => collect($counts->get($run->id, []))->mapWithKeys(fn ($c) => [$c->kind => (int) $c->n])->all(),
                ])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
