<?php

use Flarum\Testing\integration\Setup\SetupScript;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();

/*
 * The Importer's id-map tables, which Detour's presets read when they exist.
 * Made here once, not inside a test: on MySQL a CREATE TABLE commits the
 * test's transaction, and every row it had written would leak into the next.
 */
$tmp = $setup->tmpDir();
$config = (require "$tmp/config.php")['database'];
if ($config['driver'] === 'sqlite' && ! str_starts_with($config['database'], '/')) {
    $config['database'] = "$tmp/".$config['database'];
}

$capsule = new Manager();
$capsule->addConnection($config);
$schema = $capsule->getConnection()->getSchemaBuilder();

if (! $schema->hasTable('importer_runs')) {
    $schema->create('importer_runs', function (Blueprint $table) {
        $table->increments('id');
        $table->string('source', 40);
        $table->mediumText('state')->nullable();
        $table->string('status', 20)->default('running');
        $table->timestamp('created_at')->nullable();
    });
}

if (! $schema->hasTable('importer_map')) {
    $schema->create('importer_map', function (Blueprint $table) {
        $table->increments('id');
        $table->integer('run_id')->unsigned();
        $table->string('kind', 20);
        $table->string('source_id', 64);
        $table->unsignedInteger('target_id');
    });
}

echo "Importer tables ready\n";
