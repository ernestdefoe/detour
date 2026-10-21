<?php

namespace Ernestdefoe\Detour\Rules;

use Flarum\Extension\ExtensionManager;
use Flarum\Http\SlugManager;
use Flarum\Tags\Tag;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Where an id (or slug) from the old forum lives now.
 *
 * With an Importer run, a numeric id goes through that run's `importer_map`;
 * without one, the id is taken to be the same here (as most converters keep
 * them). Only reached by an address that matched a migration rule, so a
 * normal page view never runs any of it.
 *
 * Nothing private is given away: a discussion is sent to `/d/{id}` and Flarum
 * decides whether the visitor may see it; a post's discussion is only looked up
 * for a post and discussion that are not private; tags and members only when a
 * guest could see them.
 */
class Lookup
{
    public function __construct(
        protected ConnectionInterface $db,
        protected SlugManager $slugs,
        protected ExtensionManager $extensions,
    ) {
    }

    /** The forum path for an old id, or null when there is nothing to send it to. */
    public function find(string $kind, string $old, string $number, ?int $runId): ?string
    {
        if ($old === '' || strlen($old) > 191) {
            return null;
        }

        try {
            return match ($kind) {
                'topic' => $this->topic($old, $number, $runId),
                'post' => $this->post($old, $runId),
                'tag' => $this->tag($old, $runId),
                'user' => $this->user($old, $runId),
                default => null,
            };
        } catch (Throwable) {
            // The Importer's tables are gone, or Tags is off: no redirect.
            return null;
        }
    }

    private function topic(string $old, string $number, ?int $runId): ?string
    {
        $id = $this->id('topic', $old, $runId);

        if ($id === null) {
            return null;
        }

        return '/d/'.$id.(ctype_digit($number) && (int) $number > 1 ? '/'.(int) $number : '');
    }

    private function post(string $old, ?int $runId): ?string
    {
        $id = $this->id('post', $old, $runId);

        if ($id === null) {
            return null;
        }

        $row = $this->db->table('posts')
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->where('posts.id', $id)
            ->where('posts.is_private', false)
            ->whereNull('posts.hidden_at')
            ->where('discussions.is_private', false)
            ->whereNull('discussions.hidden_at')
            ->first(['posts.discussion_id', 'posts.number']);

        return $row ? '/d/'.(int) $row->discussion_id.'/'.(int) $row->number : null;
    }

    private function tag(string $old, ?int $runId): ?string
    {
        // Installed but turned off, Tags has no visibility rules to apply.
        if (! class_exists(Tag::class) || ! $this->extensions->isEnabled('flarum-tags')) {
            return null;
        }

        $query = Tag::query()->whereVisibleTo(new Guest());

        if (ctype_digit($old)) {
            $id = $this->id('tag', $old, $runId);
            if ($id === null) {
                return null;
            }
            $query->where('id', $id);
        } else {
            $query->whereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap('slug').') = ?', [mb_strtolower($old, 'UTF-8')]);
        }

        $slug = $query->value('slug');

        return is_string($slug) && $slug !== '' ? '/t/'.rawurlencode($slug) : null;
    }

    private function user(string $old, ?int $runId): ?string
    {
        $query = User::query()->whereVisibleTo(new Guest());

        if (ctype_digit($old)) {
            $id = $this->id('user', $old, $runId);
            if ($id === null) {
                return null;
            }
            $query->where('id', $id);
        } else {
            // Usernames are unique whatever their case; PostgreSQL and SQLite compare case.
            $query->whereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap('username').') = ?', [mb_strtolower($old, 'UTF-8')]);
        }

        $user = $query->first();

        return $user ? '/u/'.rawurlencode($this->slugs->forResource(User::class)->toSlug($user)) : null;
    }

    /** The id here for an old numeric id. */
    private function id(string $kind, string $old, ?int $runId): ?int
    {
        if (! ctype_digit($old)) {
            return null;
        }

        if ($runId === null) {
            return (int) $old > 0 ? (int) $old : null;
        }

        $target = $this->db->table('importer_map')
            ->where('run_id', $runId)
            ->where('kind', $kind)
            ->where('source_id', $old)
            ->value('target_id');

        return $target !== null && (int) $target > 0 ? (int) $target : null;
    }
}
