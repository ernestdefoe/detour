<?php

use Ernestdefoe\Detour\Api;
use Ernestdefoe\Detour\Http\Detour;
use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Rules\RuleSet;
use Ernestdefoe\Detour\Settings;
use Flarum\Extend;

$settings = new Extend\Settings();

foreach (Settings::DEFAULTS as $key => $value) {
    $settings->default(Settings::key($key), $value);
}

return [
    // Admin only. The forum loads nothing of Detour's: no JS, no CSS, no payload.
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-detour', __DIR__.'/views'),

    $settings,

    // Inside the error handler, before the session: a redirect sets no cookie.
    (new Extend\Middleware('forum'))
        // @phpstan-ignore argument.type (core binds its error handler under this name, not a class)
        ->insertAfter('flarum.forum.error_handler', Detour::class),

    (new Extend\Event())
        ->listen('eloquent.saved: '.Redirect::class, fn () => resolve(RuleSet::class)->flush())
        ->listen('eloquent.deleted: '.Redirect::class, fn () => resolve(RuleSet::class)->flush()),

    (new Extend\ServiceProvider())
        ->register(Ernestdefoe\Detour\Provider::class),

    (new Extend\Routes('api'))
        ->get('/detour/redirects', 'detour.redirects', Api\ListRedirectsController::class)
        ->post('/detour/redirects', 'detour.redirects.create', Api\SaveRedirectController::class)
        ->patch('/detour/redirects/{id:\d+}', 'detour.redirects.update', Api\SaveRedirectController::class)
        ->delete('/detour/redirects/{id:\d+}', 'detour.redirects.delete', Api\DeleteRedirectController::class)
        ->post('/detour/redirects/order', 'detour.redirects.order', Api\OrderController::class)
        ->post('/detour/test', 'detour.test', Api\TestController::class)
        ->get('/detour/missing', 'detour.missing', Api\ListMissingController::class)
        ->patch('/detour/missing/{id:\d+}', 'detour.missing.update', Api\UpdateMissingController::class)
        ->delete('/detour/missing/{id:\d+}', 'detour.missing.delete', Api\DeleteMissingController::class)
        ->post('/detour/missing/clear', 'detour.missing.clear', Api\ClearMissingController::class)
        ->get('/detour/presets', 'detour.presets', Api\ListPresetsController::class)
        ->post('/detour/presets', 'detour.presets.apply', Api\ApplyPresetController::class)
        ->get('/detour/export', 'detour.export', Api\ExportController::class)
        ->post('/detour/import', 'detour.import', Api\ImportController::class),
];
