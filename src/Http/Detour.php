<?php

namespace Ernestdefoe\Detour\Http;

use Carbon\Carbon;
use Ernestdefoe\Detour\Log\Recorder;
use Ernestdefoe\Detour\Rules\Hit;
use Ernestdefoe\Detour\Rules\Matcher;
use Ernestdefoe\Detour\Rules\RuleSet;
use Ernestdefoe\Detour\Settings;
use Ernestdefoe\Detour\Url;
use Flarum\Foundation\ErrorHandling\Registry;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sits just inside Flarum's error handler on the forum (never the API or the
 * admin, which are other stacks), before the session starts.
 *
 * 1. "Always" rules answer before Flarum routes the request.
 * 2. Otherwise Flarum handles it. Only if that ends in a 404 (a thrown
 *    not-found, or a 404 response) do "missing" rules get a turn, then the
 *    address is logged and the friendlier page is served.
 *
 * A normal page view costs one cache read and no queries; everything else
 * is resolved from the container only when a request needs it.
 */
class Detour implements MiddlewareInterface
{
    /** Server check: the admin page requests these to learn what the web server passes to Flarum. */
    private const PROBE = '~^/detour-probe\.(php|html|htm|asp|aspx|xml|json|cgi|jsp)$~';

    public function __construct(
        protected RuleSet $rules,
        protected Registry $errors,
        protected SettingsRepositoryInterface $settings,
        protected Container $container,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $decoded = Url::subject($path, '');

        if (($method !== 'GET' && $method !== 'HEAD') || Url::reserved($decoded)) {
            return $handler->handle($request);
        }

        if (preg_match(self::PROBE, $path)) {
            return new EmptyResponse(204, ['X-Detour-Probe' => 'ok', 'Cache-Control' => 'no-store']);
        }

        $query = $request->getUri()->getQuery();

        if ($hit = $this->find('always', $path, $query)) {
            return $this->respond($hit, $request, true);
        }

        try {
            $response = $handler->handle($request);

            if ($response->getStatusCode() !== 404) {
                return $response;
            }

            $error = null;
        } catch (Throwable $e) {
            if ($this->errors->handle($e)->getStatusCode() !== 404) {
                throw $e;
            }

            $response = null;
            $error = $e;
        }

        try {
            if ($hit = $this->find('missing', $path, $query)) {
                return $this->respond($hit, $request, false);
            }

            $recorder = $this->container->make(Recorder::class);
            if ($recorder->shouldLog($request)) {
                $recorder->record($request);
            }

            if ((int) $this->settings->get(Settings::key('friendly_page')) && NotFoundPage::wantsHtml($request)) {
                return $this->container->make(NotFoundPage::class)->render($request, 404);
            }
        } catch (Throwable $e) {
            // Never turn a 404 into a 500 because of the log or the page.
            $this->container->make(LoggerInterface::class)->error('[detour] '.$e->getMessage(), ['exception' => $e]);
        }

        if ($error !== null) {
            throw $error;
        }

        return $response;
    }

    private function find(string $phase, string $path, string $query): ?Hit
    {
        $set = $this->rules->get();

        if ($set[$phase]['exact'] === [] && $set[$phase]['prefix'] === [] && $set[$phase]['regex'] === []) {
            return null;
        }

        return $this->container->make(Matcher::class)
            ->match($set, $phase, $path, $query, rtrim($this->container->make(UrlGenerator::class)->to('forum')->base(), '/'));
    }

    /** @param bool $shared whether every visitor gets this answer ("always" rules) */
    private function respond(Hit $hit, ServerRequestInterface $request, bool $shared): ResponseInterface
    {
        try {
            $this->container->make(ConnectionInterface::class)->table('detour_redirects')->where('id', $hit->rule['id'])->increment('hits', 1, ['last_hit_at' => Carbon::now()]);
        } catch (Throwable $e) {
            $this->container->make(LoggerInterface::class)->warning('[detour] could not count a hit: '.$e->getMessage());
        }

        if ($hit->target === null) {
            return NotFoundPage::wantsHtml($request)
                ? $this->container->make(NotFoundPage::class)->render($request, 410)
                : new EmptyResponse(410);
        }

        /*
         * A day's cache, not forever. Browsers keep a bare 301 indefinitely,
         * so a mistaken rule would follow visitors around after it was fixed;
         * search engines still read 301 as a permanent move.
         *
         * An "only if missing" redirect is never cached: whether Flarum had a
         * page depends on who is asking (a private discussion is missing to a
         * guest), so a proxy must not hand one visitor's answer to another, and
         * a guest who then logs in must reach the page.
         */
        $headers = ['Cache-Control' => $hit->status === 301 && $shared ? 'public, max-age=86400' : 'private, no-store'];

        return new RedirectResponse($hit->target, $hit->status, $headers);
    }
}
