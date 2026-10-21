<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Csv;
use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Rules\Presets;
use Ernestdefoe\Detour\Rules\RuleSet;
use Ernestdefoe\Detour\Rules\Validator;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds redirects from CSV: Detour's own export (with a header row), or just
 * `old address,new address[,status]` per line. Every row goes through the
 * same checks as the form; bad rows are reported by line and skipped.
 * `dryRun` checks without saving.
 */
class ImportController implements RequestHandlerInterface
{
    use Admin;

    public const MAX_BYTES = 2_000_000;
    public const MAX_ROWS = 10_000;
    private const MAX_ERRORS = 50;

    public function __construct(
        protected Validator $validator,
        protected ConnectionInterface $db,
        protected RuleSet $rules,
        protected TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $body = $this->body($request);
        $csv = (string) ($body['csv'] ?? '');
        $dryRun = (bool) ($body['dryRun'] ?? false);

        if (trim($csv) === '' || strlen($csv) > self::MAX_BYTES) {
            throw new ValidationException(['csv' => $this->translator->trans('ernestdefoe-detour.api.csv_size')]);
        }

        $rows = Csv::read($csv, self::MAX_ROWS + 1);
        $header = null;

        if ($rows && in_array('source', array_map('strtolower', $rows[0]['cells']), true)) {
            $header = array_map('strtolower', array_shift($rows)['cells']);
        }

        $this->validator->against();
        $created = 0;
        $errors = [];
        $failed = 0;

        $position = (int) Redirect::query()->where('type', 'regex')->max('position');

        $this->db->transaction(function () use ($rows, $header, $dryRun, &$created, &$errors, &$failed, &$position) {
            foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
                $input = $this->input($row['cells'], $header);

                try {
                    $columns = $this->validator->validate($input);
                } catch (ValidationException $e) {
                    $failed++;
                    if (count($errors) < self::MAX_ERRORS) {
                        $errors[] = ['line' => $row['line'], 'message' => implode(' ', $e->getAttributes())];
                    }
                    continue;
                }

                $redirect = new Redirect();
                $redirect->forceFill($columns);
                $redirect->preset = $columns['lookup'] !== null && Presets::for((string) ($input['preset'] ?? '')) !== [] ? $input['preset'] : null;
                $redirect->position = $columns['type'] === 'regex' ? ++$position : 0;

                if (! $dryRun) {
                    Redirect::withoutEvents(fn () => $redirect->save());
                } else {
                    $redirect->id = -1 - $created;
                }

                $this->validator->remember($redirect);
                $created++;
            }
        });

        if (! $dryRun) {
            $this->rules->flush();
        }

        return new JsonResponse([
            'created' => $created,
            'failed' => $failed,
            'errors' => $errors,
            'truncated' => count($rows) > self::MAX_ROWS,
            'dryRun' => $dryRun,
        ]);
    }

    /**
     * @param list<string> $cells
     * @param list<string>|null $header
     * @return array<string, mixed>
     */
    private function input(array $cells, ?array $header): array
    {
        if ($header === null) {
            return [
                'type' => 'exact',
                'source' => $cells[0] ?? '',
                'target' => $cells[1] ?? '',
                'status' => isset($cells[2]) && $cells[2] !== '' ? (int) $cells[2] : 301,
            ];
        }

        $row = [];
        foreach ($header as $i => $name) {
            $row[$name] = $cells[$i] ?? '';
        }

        $flag = fn (string $key, bool $default) => isset($row[$key]) && $row[$key] !== '' ? in_array(strtolower($row[$key]), ['1', 'true', 'yes'], true) : $default;

        return [
            'type' => ($row['type'] ?? '') !== '' ? $row['type'] : 'exact',
            'source' => $row['source'] ?? '',
            'target' => $row['target'] ?? '',
            'status' => ($row['status'] ?? '') !== '' ? (int) $row['status'] : 301,
            'mode' => ($row['mode'] ?? '') !== '' ? $row['mode'] : 'always',
            'keepQuery' => $flag('keep_query', false),
            'external' => $flag('external', false),
            'lookup' => ($row['lookup'] ?? '') !== '' ? $row['lookup'] : null,
            // An Importer run id means something only on the forum it came
            // from; the checks refuse one that does not exist here.
            'runId' => ($row['run_id'] ?? '') !== '' ? (int) $row['run_id'] : null,
            'preset' => $row['preset'] ?? '',
            'enabled' => $flag('enabled', true),
            'note' => $row['note'] ?? '',
        ];
    }
}
