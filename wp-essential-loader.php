<?php

/**
 * Plugin Name: WP Essential Loader
 * Plugin URI: https://manishpushkar.com/
 * Description: A robust autoloader for converting standard plugins into must-use plugins, ensuring critical functionalities remain active. Includes advanced features like error logging, update notifications, and detailed admin views.
 * Version: 1.0.0
 * Author: Manish
 * Author URI: https://manishpushkar.com/
 * License: MIT
 */

namespace WP_Essential_Loader;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!is_blog_installed()) {
    return;
}

class EssentialLoader
{
    private static $cache;
    private static $auto_plugins;
    private static $mu_plugins;
    private static $activated;
    private static $plugin_errors;
    private static $relative_path;
    private static $_instance;

    public function __construct()
    {
        if (isset(self::$_instance)) {
            return;
        }

        self::$_instance = $this;
        self::$relative_path = '/../' . basename(__DIR__);
        self::$plugin_errors = [];

        if (is_admin()) {
            add_filter('show_advanced_plugins', [$this, 'showInAdmin'], 10, 2);
            add_action('admin_notices', [$this, 'displayErrorNotices']);
        }

        $this->loadPlugins();
    }

    public function loadPlugins()
    {
        $this->checkCache();
        $this->validatePlugins();

        foreach (self::$cache['plugins'] as $plugin_file => $plugin_info) {
            try {
                include_once(WPMU_PLUGIN_DIR . '/' . $plugin_file);
            } catch (\Throwable $e) {
                self::$plugin_errors[] = [
                    'plugin' => $plugin_file,
                    'message' => $e->getMessage(),
                ];
                continue;
            }
        }

        $this->pluginHooks();
    }

    public function showInAdmin($show, $type)
    {
        $screen = get_current_screen();
        $current = is_multisite() ? 'plugins-network' : 'plugins';

        if ($screen->{'base'} != $current || $type != 'mustuse') {
            return $show;
        }

        $this->updateCache();

        self::$auto_plugins = array_map(function ($auto_plugin) {
            $auto_plugin['Name'] .= ' (Essential)';
            return $auto_plugin;
        }, self::$auto_plugins);

        $GLOBALS['plugins']['mustuse'] = array_merge(self::$auto_plugins, self::$mu_plugins);

        return false;
    }

    public function displayErrorNotices()
    {
        if (empty(self::$plugin_errors)) {
            return;
        }

        foreach (self::$plugin_errors as $error) {
            echo '<div class="notice notice-error"><p><strong>Error loading plugin:</strong> ' . esc_html($error['plugin']) . ' - ' . esc_html($error['message']) . '</p></div>';
        }
    }

    private function checkCache()
    {
        $cache = get_site_option('essential_loader_cache');

        if ($cache === false) {
            $this->updateCache();
            return;
        }

        self::$cache = $cache;
    }

    private function updateCache()
    {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');

        self::$auto_plugins = get_plugins(self::$relative_path);
        self::$mu_plugins = get_mu_plugins();
        $plugins = array_diff_key(self::$auto_plugins, self::$mu_plugins);
        self::$activated = array_diff_key($plugins, self::$cache['plugins'] ?? []);

        self::$cache = [
            'plugins' => $plugins,
            'count' => count($plugins),
        ];

        update_site_option('essential_loader_cache', self::$cache);
    }

    private function validatePlugins()
    {
        foreach (self::$cache['plugins'] as $plugin_file => $plugin_info) {
            if (!file_exists(WPMU_PLUGIN_DIR . '/' . $plugin_file)) {
                $this->updateCache();
                break;
            }
        }
    }

    private function pluginHooks()
    {
        if (!is_array(self::$activated)) {
            return;
        }

        foreach (self::$activated as $plugin_file => $plugin_info) {
            do_action('activate_' . $plugin_file);
        }
    }

    public static function getInstance()
    {
        if (!self::$_instance) {
            new self();
        }

        return self::$_instance;
    }
}

EssentialLoader::getInstance();
