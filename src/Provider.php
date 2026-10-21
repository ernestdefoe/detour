<?php

namespace Ernestdefoe\Detour;

use Ernestdefoe\Detour\Rules\RuleSet;
use Flarum\Foundation\AbstractServiceProvider;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        // One per request, so the compiled rules are read from the cache once.
        $this->container->singleton(RuleSet::class);
    }
}
