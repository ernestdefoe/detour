<?php

namespace Ernestdefoe\Detour\Http;

use Ernestdefoe\Detour\Like;
use Ernestdefoe\Detour\Settings;
use Ernestdefoe\Detour\Url;
use Flarum\Discussion\Discussion;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The page a visitor sees instead of a bare "not found": the forum's own
 * message, a search box already filled with the words from the address, and
 * discussions whose titles share those words.
 *
 * Suggestions are what a guest can see, so they are the same for everyone and
 * cached for ten minutes per set of words: a bot hammering one dead address
 * costs one query every ten minutes, and nobody is ever shown a title they
 * could not open.
 */
class NotFoundPage
{
    /** Words in old forum addresses that say nothing about the content. */
    private const STOP = ['php', 'html', 'htm', 'asp', 'aspx', 'index', 'viewtopic', 'viewforum', 'showthread', 'showpost', 'forumdisplay', 'threads', 'thread', 'forums', 'forum', 'topic', 'topics', 'post', 'posts', 'member', 'members', 'profile', 'page', 'archive', 'discussion', 'discussions', 'the', 'and', 'for', 'with', 'from', 'this', 'that', 'msg', 'tid', 'pid', 'fid', 'uid'];

    /** Uncached suggestion searches allowed per minute, across the forum. */
    public const SEARCHES_PER_MINUTE = 30;

    public function __construct(
        protected ViewFactory $view,
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url,
        protected Cache $cache,
        protected LocaleManager $locales,
    ) {
    }

    public static function wantsHtml(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));

        return ! str_contains($accept, 'json') || str_contains($accept, 'text/html');
    }

    public function render(ServerRequestInterface $request, int $status): ResponseInterface
    {
        $gone = $status === 410;
        $words = $this->words($request->getUri()->getPath());

        // Detour answers before Flarum's SetLocale runs; asking the locale
        // manager is what loads the extensions' translations.
        $translator = $this->locales->getTranslator();

        $title = trim((string) $this->settings->get(Settings::key('page_title')));
        $message = trim((string) $this->settings->get(Settings::key('page_message')));

        $html = $this->view->make('ernestdefoe-detour::page', [
            'heading' => $title !== '' && ! $gone ? $title : $translator->trans('ernestdefoe-detour.views.'.($gone ? 'gone' : 'not_found').'_title'),
            'message' => $message !== '' && ! $gone ? $message : $translator->trans('ernestdefoe-detour.views.'.($gone ? 'gone' : 'not_found').'_message', ['forum' => (string) $this->settings->get('forum_title')]),
            'query' => implode(' ', $words),
            'suggestions' => $this->suggestions($words),
            'home' => $this->url->to('forum')->base(),
        ])->render();

        return new HtmlResponse($html, $status, ['Cache-Control' => 'no-store']);
    }

    /** @return list<string> up to four meaningful words from the address */
    public function words(string $path): array
    {
        $text = Url::lower(Url::subject($path, ''));
        $words = preg_split('~[^\pL\pN]+~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! ctype_digit($w) && ! in_array($w, self::STOP, true));

        return array_slice(array_values(array_unique($words)), 0, 4);
    }

    /**
     * @param list<string> $words
     * @return list<array{title: string, url: string}>
     */
    private function suggestions(array $words): array
    {
        if ($words === [] || ! (int) $this->settings->get(Settings::key('suggestions'))) {
            return [];
        }

        $sorted = $words;
        sort($sorted);
        $key = 'ernestdefoe-detour.suggest.'.sha1(implode(' ', $sorted));

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        /*
         * A scanner asking for a new random address every time would miss the
         * cache every time, and each miss is a LIKE over discussion titles.
         * A forum-wide budget per minute keeps that bounded: past it, the page
         * is served without suggestions.
         */
        $budget = 'ernestdefoe-detour.suggest-budget.'.intdiv(time(), 60);
        $this->cache->add($budget, 0, 120);
        if ((int) $this->cache->increment($budget) > self::SEARCHES_PER_MINUTE) {
            return [];
        }

        return $this->cache->remember($key, 600, function () use ($words) {
            $query = Discussion::query()->whereVisibleTo(new Guest());

            $query->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    Like::where($q, 'title', $word, 'or');
                }
            });

            $base = $this->url->to('forum')->base();

            return $query->orderByDesc('comment_count')
                ->limit(5)
                ->get(['id', 'title'])
                ->map(fn (Discussion $d) => ['title' => (string) $d->title, 'url' => $base.'/d/'.$d->id])
                ->all();
        });
    }
}
