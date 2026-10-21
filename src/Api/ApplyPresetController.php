<?php

namespace Ernestdefoe\Detour\Api;

use Carbon\Carbon;
use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Rules\Presets;
use Ernestdefoe\Detour\Rules\RuleSet;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Installs (or re-installs, or removes) one platform's migration rules.
 * Re-applying replaces that platform's rules, so it never duplicates them.
 */
class ApplyPresetController implements RequestHandlerInterface
{
    use Admin;

    public function __construct(
        protected ConnectionInterface $db,
        protected RuleSet $rules,
        protected TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $body = $this->body($request);
        $platform = (string) ($body['platform'] ?? '');
        $patterns = Presets::for($platform);

        if ($patterns === []) {
            throw new ValidationException(['platform' => $this->translator->trans('ernestdefoe-detour.api.preset_unknown')]);
        }

        $remove = (bool) ($body['remove'] ?? false);
        $runId = isset($body['runId']) && $body['runId'] !== '' ? (int) $body['runId'] : null;

        if ($runId !== null && ! $this->runExists($runId)) {
            throw new ValidationException(['runId' => $this->translator->trans('ernestdefoe-detour.api.run_unknown')]);
        }

        $created = 0;

        $this->db->transaction(function () use ($platform, $patterns, $remove, $runId, &$created) {
            // A reinstall keeps what the admin changed on a rule it replaces.
            $kept = Redirect::query()->where('preset', $platform)->get(['source', 'enabled', 'status'])->keyBy('source');
            Redirect::query()->where('preset', $platform)->delete();

            if ($remove) {
                return;
            }

            $position = (int) Redirect::query()->where('type', 'regex')->max('position');
            $now = Carbon::now();
            $rows = [];

            foreach ($patterns as [$lookup, $pattern, $example]) {
                $old = $kept->get($pattern);
                $rows[] = [
                    'type' => 'regex',
                    'source' => $pattern,
                    'target' => null,
                    'status' => $old ? $old->status : 301,
                    'mode' => 'missing',
                    'keep_query' => false,
                    'external' => false,
                    'lookup' => $lookup,
                    'run_id' => $runId,
                    'preset' => $platform,
                    'enabled' => $old ? $old->enabled : true,
                    'position' => ++$position,
                    'hits' => 0,
                    'note' => $example,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // One insert for the whole preset.
            Redirect::query()->insert($rows);
            $created = count($rows);
        });

        $this->rules->flush();

        return new JsonResponse(['created' => $created]);
    }

    private function runExists(int $runId): bool
    {
        try {
            return $this->db->table('importer_runs')->where('id', $runId)->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
