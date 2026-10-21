<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One row per missing address, never one per hit.
        $schema->create('detour_missing', function (Blueprint $table) {
            $table->increments('id');
            $table->char('url_hash', 40)->unique();
            $table->string('url', 1000);
            $table->unsignedInteger('hits')->default(1);
            // Up to five recent referrers, without their query strings, as JSON.
            $table->text('referrers')->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->boolean('ignored')->default(false);

            $table->index(['ignored', 'hits']);
            $table->index('last_seen_at');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('detour_missing');
    },
];
