<?php

namespace Ernestdefoe\Detour\Rules;

/**
 * What old forum addresses looked like, per platform, as lookup rules.
 *
 * Each pattern is matched against the decoded address, path and query, and
 * its first capture is the old id (or slug); a Discourse topic's optional
 * second capture is the post number. Rules run only when Flarum has no page
 * at the address ("missing"), so they can never take one of Flarum's own.
 *
 * 🚨 Order matters within a platform: the more specific pattern comes first
 * (a phpBB `viewtopic.php?t=1&p=5` is post 5, not topic 1; Discourse's
 * `/t/123/4` must be read as topic 123 before the slugged pattern reads `123`
 * as a slug). A lookup that finds nothing falls through to the next rule.
 *
 * Kept in step with ernestdefoe/importer's Redirects\Patterns, whose source
 * names these keys match, so a run's id map lines up with its platform.
 */
final class Presets
{
    /** @return array<string, list<array{0: string, 1: string, 2: string}>> platform => [lookup, regex, example] */
    public static function all(): array
    {
        return [
            'phpbb' => [
                ['post', '(?:^|/)viewtopic\.php\?(?:.*&)?p=(\d+)', '/viewtopic.php?p=5678'],
                ['topic', '(?:^|/)viewtopic\.php\?(?:.*&)?t=(\d+)', '/viewtopic.php?f=2&t=1234'],
                ['tag', '(?:^|/)viewforum\.php\?(?:.*&)?f=(\d+)', '/viewforum.php?f=2'],
                ['user', '(?:^|/)memberlist\.php\?(?:.*&)?u=(\d+)', '/memberlist.php?mode=viewprofile&u=5'],
            ],
            'vbulletin' => [
                ['post', '(?:^|/)show(?:thread|post)\.php\?(?:.*&)?p=(\d+)', '/showthread.php?p=5678'],
                ['topic', '(?:^|/)showthread\.php\?(?:.*&)?t=(\d+)', '/showthread.php?t=1234'],
                ['topic', '(?:^|/)showthread\.php[?/](\d+)', '/showthread.php?1234-my-topic'],
                ['topic', '(?:^|/)threads/(\d+)', '/threads/1234-my-topic'],
                ['topic', '(?:^|/)archive/index\.php/t-(\d+)', '/archive/index.php/t-1234.html'],
                ['tag', '(?:^|/)forumdisplay\.php\?(?:.*&)?f=(\d+)', '/forumdisplay.php?f=4'],
                ['tag', '(?:^|/)forumdisplay\.php[?/](\d+)', '/forumdisplay.php?4-general'],
                ['tag', '(?:^|/)forums/(\d+)', '/forums/4-general'],
                ['tag', '(?:^|/)archive/index\.php/f-(\d+)', '/archive/index.php/f-4.html'],
                ['user', '(?:^|/)member\.php\?(?:.*&)?u=(\d+)', '/member.php?u=9'],
                ['user', '(?:^|/)member\.php[?/](\d+)', '/member.php?9-alice'],
                ['user', '(?:^|/)members/(\d+)', '/members/9-alice'],
            ],
            'vbulletin5' => [
                ['user', '(?:^|/)member/(\d+)', '/member/9-alice'],
                ['topic', '(?:^|/)node/(\d+)', '/node/1234'],
                // Never Flarum's own /d/77-slug: a private discussion is "missing" to a guest.
                ['topic', '^(?!/(?:d|t|u|p)/)(?:.*/)?(\d+)-[^/?]+(?:/page\d+)?/?$', '/forum/general/1234-my-topic'],
            ],
            'xenforo' => [
                ['post', '(?:^|[/?])posts/(\d+)', '/posts/5678/'],
                ['post', '(?:^|[/?])goto/post\?(?:.*&)?id=(\d+)', '/goto/post?id=5678'],
                ['post', '(?:^|[/?])threads/[^/?]*/post-(\d+)', '/threads/my-topic.1234/post-5678'],
                ['topic', '(?:^|[/?])threads/(?:[^/?]*\.)?(\d+)', '/threads/my-topic.1234/'],
                ['tag', '(?:^|[/?])forums/(?:[^/?]*\.)?(\d+)', '/forums/general.4/'],
                ['user', '(?:^|[/?])members/(?:[^/?]*\.)?(\d+)', '/members/alice.9/'],
            ],
            'mybb' => [
                ['post', '(?:^|/)showthread\.php\?(?:.*&)?pid=(\d+)', '/showthread.php?tid=1234&pid=5678'],
                ['post', '(?:^|/)post-(\d+)', '/post-5678.html'],
                ['post', '(?:^|/)thread-\d+-post-(\d+)', '/thread-1234-post-5678.html'],
                ['topic', '(?:^|/)showthread\.php\?(?:.*&)?tid=(\d+)', '/showthread.php?tid=1234'],
                ['topic', '(?:^|/)thread-(\d+)', '/thread-1234.html'],
                ['tag', '(?:^|/)forumdisplay\.php\?(?:.*&)?fid=(\d+)', '/forumdisplay.php?fid=4'],
                ['tag', '(?:^|/)forum-(\d+)', '/forum-4.html'],
                ['user', '(?:^|/)member\.php\?(?:.*&)?uid=(\d+)', '/member.php?action=profile&uid=9'],
                ['user', '(?:^|/)user-(\d+)', '/user-9.html'],
            ],
            'smf' => [
                ['post', '[?&;/]topic[=,]\d+\.msg(\d+)', '/index.php?topic=1234.msg5678'],
                ['post', '[?&;]msg=(\d+)', '/index.php?msg=5678'],
                ['topic', '[?&;/]topic[=,](\d+)', '/index.php?topic=1234.0'],
                ['tag', '[?&;/]board[=,](\d+)', '/index.php?board=4.0'],
                ['user', '[?&;]action=profile[;&](?:.*[;&])?u=(\d+)', '/index.php?action=profile;u=9'],
            ],
            'invision' => [
                ['post', '(?:^|/)topic/\d+[^?]*\?(?:.*&)?comment=(\d+)', '/topic/1234-my-topic/?do=findComment&comment=5678'],
                ['post', '[?&]showtopic=\d+&(?:.*&)?p=(\d+)', '/index.php?showtopic=1234&view=findpost&p=5678'],
                ['topic', '(?:^|/)topic/(\d+)', '/topic/1234-my-topic/'],
                ['topic', '[?&]showtopic=(\d+)', '/index.php?showtopic=1234'],
                ['tag', '(?:^|/)forum/(\d+)', '/forum/4-general/'],
                ['tag', '[?&]showforum=(\d+)', '/index.php?showforum=4'],
                ['user', '(?:^|/)profile/(\d+)', '/profile/9-alice/'],
                ['user', '[?&]showuser=(\d+)', '/index.php?showuser=9'],
            ],
            'discourse' => [
                ['topic', '(?:^|/)t/(\d+)(?:/(\d+))?/?(?:\?|$)', '/t/1234/5'],
                ['topic', '(?:^|/)t/[^/?]+/(\d+)(?:/(\d+))?/?(?:\?|$)', '/t/my-topic/1234'],
                ['tag', '(?:^|/)c/(?:[^/?]+/)*?(\d+)(?:/l/[^/?]+)?/?(?:\?|$)', '/c/general/4/l/latest'],
                ['tag', '(?:^|/)c/([^/?]+)(?:/l/[^/?]+)?/?(?:\?|$)', '/c/general'],
                ['user', '(?:^|/)u/([^/?]+)', '/u/alice'],
            ],
            'vanilla' => [
                ['post', '(?:^|/)discussion/comment/(\d+)', '/discussion/comment/5678'],
                ['topic', '(?:^|/)discussion/(\d+)', '/discussion/1234/my-topic'],
                ['tag', '(?:^|/)categories/([^/?]+)', '/categories/general'],
                ['user', '(?:^|/)profile/(?:(?:comments|discussions|activity|notifications)/)?(?:\d+/)?([^/?]+)', '/profile/comments/9/alice'],
            ],
            'nodebb' => [
                ['topic', '(?:^|/)topic/(\d+)', '/topic/1234/my-topic'],
                ['tag', '(?:^|/)category/(\d+)', '/category/4/general'],
                ['user', '(?:^|/)user/([^/?]+)', '/user/alice'],
            ],
            'webwiz' => [
                ['topic', '(?:^|/)forum_posts\.asp\?(?:.*&)?TID=(\d+)', '/forum_posts.asp?TID=1234'],
                ['tag', '(?:^|/)forum_topics\.asp\?(?:.*&)?FID=(\d+)', '/forum_topics.asp?FID=4'],
                ['user', '(?:^|/)member_profile\.asp\?(?:.*&)?PF=(\d+)', '/member_profile.asp?PF=9'],
            ],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function for(string $platform): array
    {
        return self::all()[$platform] ?? [];
    }
}
