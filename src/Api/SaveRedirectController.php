<?php

namespace Ernestdefoe\Detour\Api;

use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Rules\Validator;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Creates a rule (POST) or changes one (PATCH /{id}). */
class SaveRedirectController implements RequestHandlerInterface
{
    use Admin;

    public function __construct(
        protected Validator $validator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $existing = $request->getMethod() === 'PATCH' ? Redirect::query()->findOrFail($this->id($request)) : null;

        $input = array_intersect_key($this->body($request), array_flip(['type', 'source', 'target', 'status', 'mode', 'keepQuery', 'external', 'lookup', 'runId', 'enabled', 'note']));

        $columns = $this->validator->validate($input, $existing);

        $redirect = $existing ?? new Redirect();

        if (! $existing) {
            $redirect->position = $columns['type'] === 'regex' ? ((int) Redirect::query()->where('type', 'regex')->max('position')) + 1 : 0;
        } elseif ($existing->type !== $columns['type']) {
            $redirect->position = $columns['type'] === 'regex' ? ((int) Redirect::query()->where('type', 'regex')->max('position')) + 1 : 0;
        }

        // Kept by the preset only while its rule is unchanged.
        if ($existing && $existing->preset && ($columns['source'] !== $existing->source || $columns['lookup'] !== $existing->lookup)) {
            $redirect->preset = null;
        }

        $redirect->forceFill($columns)->save();

        return new JsonResponse(['data' => $redirect->toArray()], $existing ? 200 : 201);
    }
}
