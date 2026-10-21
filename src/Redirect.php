<?php

namespace Ernestdefoe\Detour;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $type exact | prefix | regex
 * @property string $source
 * @property string|null $target
 * @property int $status
 * @property string $mode always | missing
 * @property bool $keep_query
 * @property bool $external
 * @property string|null $lookup topic | post | tag | user
 * @property int|null $run_id
 * @property string|null $preset
 * @property bool $enabled
 * @property int $position
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Redirect extends AbstractModel
{
    protected $table = 'detour_redirects';

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'keep_query' => 'boolean',
        'external' => 'boolean',
        'run_id' => 'integer',
        'enabled' => 'boolean',
        'position' => 'integer',
        'hits' => 'integer',
        'last_hit_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const TYPES = ['exact', 'prefix', 'regex'];
    public const STATUSES = [301, 302, 410];
    public const MODES = ['always', 'missing'];
    public const LOOKUPS = ['topic', 'post', 'tag', 'user'];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'target' => $this->target,
            'status' => $this->status,
            'mode' => $this->mode,
            'keepQuery' => $this->keep_query,
            'external' => $this->external,
            'lookup' => $this->lookup,
            'runId' => $this->run_id,
            'preset' => $this->preset,
            'enabled' => $this->enabled,
            'position' => $this->position,
            'hits' => $this->hits,
            'lastHitAt' => $this->last_hit_at?->toIso8601String(),
            'note' => $this->note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
