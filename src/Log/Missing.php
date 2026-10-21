<?php

namespace Ernestdefoe\Detour\Log;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * One address that was not found, however many times it was asked for.
 *
 * @property int $id
 * @property string $url_hash
 * @property string $url
 * @property int $hits
 * @property string|null $referrers JSON list, newest first
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property bool $ignored
 */
class Missing extends AbstractModel
{
    protected $table = 'detour_missing';

    protected $casts = [
        'id' => 'integer',
        'hits' => 'integer',
        'ignored' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public static function hash(string $url): string
    {
        return sha1($url);
    }

    /** @return list<string> */
    public function referrerList(): array
    {
        $list = json_decode((string) $this->referrers, true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
