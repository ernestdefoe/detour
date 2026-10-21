<?php

namespace Ernestdefoe\Detour;

final class Settings
{
    public const PREFIX = 'ernestdefoe-detour.';

    /** @var array<string, string> */
    public const DEFAULTS = [
        'log' => '1',
        'ignore_bots' => '1',
        'ignore_paths' => "/wp-\n/wordpress\n/xmlrpc.php\n/.env\n/.git\n/phpmyadmin\n/cgi-bin\n/vendor/\n/.well-known/",
        'max_rows' => '5000',
        'retention_days' => '180',
        'friendly_page' => '1',
        'suggestions' => '1',
        'page_title' => '',
        'page_message' => '',
    ];

    public static function key(string $name): string
    {
        return self::PREFIX.$name;
    }
}
