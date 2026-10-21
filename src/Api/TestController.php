<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Rules\Matcher;
use Ernestdefoe\Detour\Rules\RuleSet;
use Ernestdefoe\Detour\Url;
use Flarum\Http\UrlGenerator;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** "What would happen to this address?" for the admin page's test box. */
class TestController implements RequestHandlerInterface
{
    use Admin;

    public function __construct(
        protected RuleSet $rules,
        protected Matcher $matcher,
        protected UrlGenerator $url,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $input = trim((string) ($this->body($request)['url'] ?? ''));
        $subject = Url::source($input);

        if ($subject === null) {
            return new JsonResponse(['valid' => false]);
        }

        [$path, $query] = array_pad(explode('?', $subject, 2), 2, '');
        $path = Url::encode($path);
        $query = str_replace(['%', '+'], ['%25', '%2B'], $query);

        if (Url::reserved(rawurldecode($path))) {
            return new JsonResponse(['valid' => true, 'reserved' => true]);
        }

        $set = $this->rules->get();
        $base = rtrim($this->url->to('forum')->base(), '/');
        $out = ['valid' => true, 'reserved' => false];

        foreach (['always', 'missing'] as $phase) {
            $hit = $this->matcher->match($set, $phase, $path, $query, $base);
            $out[$phase] = $hit ? [
                'ruleId' => $hit->rule['id'],
                'status' => $hit->status,
                'target' => $hit->target,
                'chain' => $hit->chain,
            ] : null;
        }

        return new JsonResponse($out);
    }
}
