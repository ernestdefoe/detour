<?php

namespace Ernestdefoe\Detour\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Detour\Redirect;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;

abstract class DetourTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected const BASE = 'http://localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-detour');

        $now = Carbon::now()->subHour()->toDateTimeString();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Welcome to the garden club', 'created_at' => $now, 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 2, 'last_post_number' => 2],
                ['id' => 2, 'title' => 'Secret garden plans', 'created_at' => $now, 'user_id' => 2, 'first_post_id' => 3, 'comment_count' => 1, 'is_private' => true, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hello</p></t>', 'number' => 1],
                ['id' => 2, 'discussion_id' => 1, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Reply</p></t>', 'number' => 2],
                ['id' => 3, 'discussion_id' => 2, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Private</p></t>', 'number' => 1, 'is_private' => true],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Roses', 'slug' => 'roses', 'position' => 0, 'is_restricted' => false],
                ['id' => 2, 'name' => 'Staff room', 'slug' => 'staff-room', 'position' => 1, 'is_restricted' => true],
            ],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function rule(array $attributes): Redirect
    {
        $this->app();
        $redirect = new Redirect();
        $redirect->forceFill($attributes + ['type' => 'exact', 'status' => 301, 'mode' => 'always', 'enabled' => true, 'position' => 0, 'hits' => 0]);
        $redirect->save();

        return $redirect;
    }

    /** @param array<string, string> $headers */
    protected function get(string $url, array $headers = []): ResponseInterface
    {
        $request = $this->request('GET', $url);
        $headers += ['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15'];
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->send($request);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     */
    protected function api(string $method, string $path, ?array $json = null, int $as = 1, array $query = []): ResponseInterface
    {
        $options = ['authenticatedAs' => $as];
        if ($json !== null) {
            $options['json'] = $json;
        }

        $request = $this->request($method, '/api/detour'.$path, $options);

        if ($query) {
            $request = $request->withQueryParams($query);
        }

        return $this->send($request);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function location(ResponseInterface $response): string
    {
        return $response->getHeaderLine('Location');
    }
}
