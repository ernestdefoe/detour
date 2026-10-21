<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('detour_redirects', function (Blueprint $table) {
            $table->increments('id');
            // exact | prefix | regex
            $table->string('type', 10);
            $table->string('source', 1000);
            // Null for a 410, and for a lookup rule, whose target is found per request.
            $table->string('target', 1000)->nullable();
            $table->unsignedSmallInteger('status')->default(301);
            // always: before Flarum routes the request. missing: only when Flarum has no page there.
            $table->string('mode', 10)->default('always');
            $table->boolean('keep_query')->default(false);
            $table->boolean('external')->default(false);
            // topic | post | tag | user: the first capture is an id (or slug) from the old forum.
            $table->string('lookup', 10)->nullable();
            // An Importer run whose id map the lookup goes through.
            $table->unsignedInteger('run_id')->nullable();
            // Which migration preset made it, so a preset can be re-applied without duplicates.
            $table->string('preset', 20)->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('position')->default(0);
            $table->unsignedInteger('hits')->default(0);
            $table->dateTime('last_hit_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['type', 'position']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('detour_redirects');
    },
];
