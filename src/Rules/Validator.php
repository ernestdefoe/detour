<?php

namespace Ernestdefoe\Detour\Rules;

use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Url;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Checks a rule before it is saved (from the form, a CSV row or a preset) and
 * returns the columns to store. Refuses what would be unsafe or broken: an
 * address on another host unless asked for, a capture in a host, a regex that
 * catches the home page, a rule that sends an address back to itself.
 */
class Validator
{
    /** @var array<string, mixed>|null compiled set the checks run against */
    private ?array $set = null;

    /** @var array<int, bool> Importer runs already checked */
    private array $runs = [];

    public function __construct(
        protected Matcher $matcher,
        protected UrlGenerator $url,
        protected ConnectionInterface $db,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * Run checks against the saved rules, minus one being edited.
     */
    public function against(?int $exceptId = null): static
    {
        $set = Compiler::empty();

        $rows = Redirect::query()->where('enabled', true)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'type', 'source', 'target', 'status', 'mode', 'keep_query', 'external', 'lookup', 'run_id']);

        foreach ($rows as $row) {
            $set = Compiler::add($set, $row->getAttributes());
        }

        $this->set = $set;

        return $this;
    }

    /** Adds a rule that was just saved, so later rows of an import see it. */
    public function remember(Redirect $redirect): void
    {
        if ($this->set !== null && $redirect->enabled) {
            $this->set = Compiler::add($this->set, $redirect->getAttributes());
        }
    }

    /**
     * @param array<string, mixed> $input camelCase attributes
     * @return array<string, mixed> columns
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?Redirect $existing = null): array
    {
        $set = $this->set ?? $this->against($existing?->id)->set;
        assert(is_array($set));

        $get = fn (string $key, mixed $default = null) => array_key_exists($key, $input) ? $input[$key] : ($existing ? $existing->getAttribute($this->column($key)) : $default);

        $type = (string) $get('type', 'exact');
        $status = (int) $get('status', 301);
        $mode = (string) $get('mode', 'always');
        $lookup = $get('lookup');
        $lookup = $lookup === '' ? null : $lookup;
        $external = (bool) $get('external', false);
        $keepQuery = (bool) $get('keepQuery', false);
        $enabled = (bool) $get('enabled', true);
        $note = trim((string) $get('note', ''));
        $runId = $get('runId');
        $runId = $runId === null || $runId === '' ? null : (int) $runId;

        if (! in_array($type, Redirect::TYPES, true)) {
            $this->fail('type', 'type_invalid');
        }
        if (! in_array($status, Redirect::STATUSES, true)) {
            $this->fail('status', 'status_invalid');
        }
        if (! in_array($mode, Redirect::MODES, true)) {
            $this->fail('mode', 'mode_invalid');
        }
        if (! mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > 255) {
            $this->fail('note', 'note_long');
        }

        $source = trim((string) $get('source', ''));
        if ($source === '' || strlen($source) > 1000) {
            $this->fail('source', 'source_required');
        }

        if ($type === 'regex') {
            $pattern = Compiler::pattern($source);
            if ($pattern === null) {
                $this->fail('source', 'regex_invalid');
            }
            if (@preg_match($pattern, '/') === 1) {
                $this->fail('source', 'regex_home');
            }
        } else {
            // Kept as typed (only a host or this forum's folder is cut off), so
            // saving a rule again never decodes it a second time.
            $raw = Url::raw($source, $this->url->to('forum')->base());
            if ($raw === null) {
                $this->fail('source', 'source_path');
            }
            [$path] = Url::split($raw);
            if ($type === 'prefix') {
                $raw = $path;
            }
            $decoded = Url::trimSlash(Url::subject($path, ''));
            if ($decoded === '/') {
                $this->fail('source', 'source_home');
            }
            if (Url::reserved($decoded)) {
                $this->fail('source', 'source_reserved');
            }
            $source = $raw;

            // Duplicates of the same address in the same phase.
            $match = Compiler::rule(['id' => 0, 'type' => $type, 'source' => $source, 'status' => $status])['match'] ?? null;
            $taken = $type === 'exact'
                ? isset($set[$mode]['exact'][$match])
                : in_array($match, array_column($set[$mode]['prefix'], 'match'), true);
            if ($taken) {
                $this->fail('source', 'duplicate');
            }
        }

        $target = trim((string) $get('target', ''));

        if ($lookup !== null) {
            if ($type !== 'regex' || ! in_array($lookup, Redirect::LOOKUPS, true)) {
                $this->fail('lookup', 'lookup_invalid');
            }
            if ($runId !== null && ! $this->runExists($runId)) {
                $this->fail('runId', 'run_unknown');
            }
            $target = null;
            $external = false;
        } elseif ($status === 410) {
            $target = null;
            $external = false;
        } else {
            $runId = null;
            $target = $this->target($type, $target, $external);
        }

        $columns = [
            'type' => $type,
            'source' => $source,
            'target' => $target,
            'status' => $status,
            'mode' => $mode,
            'keep_query' => $keepQuery && $status !== 410 && $lookup === null,
            'external' => $external,
            'lookup' => $lookup,
            'run_id' => $lookup !== null ? $runId : null,
            'enabled' => $enabled,
            'note' => $note === '' ? null : $note,
        ];

        if ($enabled && $target !== null && str_starts_with($target, '/')) {
            $this->loops($columns, $set);
        }

        return $columns;
    }

    private function target(string $type, string $target, bool $external): string
    {
        if (Url::hasControl($target)) {
            $this->fail('target', 'target_invalid');
        }

        // Spaces and non-ASCII characters are written as %XX, as a Location header needs.
        $target = (string) preg_replace_callback('~[^\x21-\x7E]~', fn ($c) => rawurlencode($c[0]), $target);

        if ($target === '' || strlen($target) > 1000) {
            $this->fail('target', 'target_required');
        }

        if (str_starts_with($target, '/')) {
            // Captures stand for any text: check with one in place.
            $sample = $type === 'regex' ? Matcher::substitute($target, array_fill(0, 10, 'x')) : $target;
            if ($type !== 'regex' && preg_match('~\$\{?\d~', $target)) {
                $this->fail('target', 'target_captures');
            }
            if (! Url::safeInternal($sample) || preg_match('~^/\$\{?\d~', $target)) {
                $this->fail('target', 'target_invalid');
            }

            return $target;
        }

        if (preg_match('~^https?://~i', $target)) {
            if (! $external) {
                $this->fail('target', 'external_off');
            }
            if (Url::externalTemplate($target) === null) {
                $this->fail('target', 'external_host');
            }

            return $target;
        }

        $this->fail('target', 'target_invalid');
    }

    /**
     * A rule whose target leads back to its own source (directly or through
     * other rules) is refused: nobody could ever reach either page.
     *
     * @param array<string, mixed> $columns
     * @param array<string, mixed> $set
     */
    private function loops(array $columns, array $set): void
    {
        $base = rtrim($this->url->to('forum')->base(), '/');
        $candidate = Compiler::rule(['id' => 0] + $columns);

        if ($candidate === null) {
            return;
        }

        if ($columns['type'] === 'regex') {
            // Only a target without captures can be checked: does it match its own rule?
            if (! preg_match('~\$\{?\d~', (string) $columns['target'])) {
                [$p, $q] = Url::split((string) $columns['target']);
                if (@preg_match($candidate['match'], Url::subject($p, $q)) === 1) {
                    $this->fail('target', 'loop');
                }
            }

            return;
        }

        // Follow the chain through rules of both kinds: two "only if missing"
        // rules pointing at each other loop just as surely when both pages are gone.
        $with = Compiler::add($set, ['id' => 0] + $columns);
        [$path, $query] = Url::split((string) $columns['source']);

        if ($this->matcher->match($with, $columns['mode'], $path, $query, $base, ['always', 'missing']) === null) {
            $this->fail('target', 'loop');
        }
    }

    private function runExists(int $runId): bool
    {
        if (isset($this->runs[$runId])) {
            return $this->runs[$runId];
        }

        try {
            return $this->runs[$runId] = $this->db->table('importer_runs')->where('id', $runId)->exists();
        } catch (\Throwable) {
            return $this->runs[$runId] = false;
        }
    }

    private function column(string $key): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', $key));
    }

    private function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-detour.api.'.$key)]);
    }
}
