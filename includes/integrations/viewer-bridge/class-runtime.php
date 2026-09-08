<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

if (!defined('ABSPATH')) {
    exit;
}

/** Single in-process entry point for the shared live catalog integration. */
final class Runtime
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        foreach (array('redis', 'store', 'revision', 'canonical-taxonomy', 'live-state', 'patris-commerce', 'events', 'actions', 'rest') as $module) {
            require_once __DIR__ . '/class-' . $module . '.php';
        }
        self::$registered = true;

        add_action('init', array(Actions::class, 'register_product_trash_status'), 5);
        Rest::register();
        add_action('rest_api_init', array(Patris_Commerce::class, 'prime_ingest_runtime'), -1000);
        add_action('plugins_loaded', array(self::class, 'register_events'), 30);
    }

    public static function register_events(): void
    {
        Store::register_maintenance();
        Events::register();
    }
}
