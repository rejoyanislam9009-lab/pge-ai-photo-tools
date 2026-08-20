<?php
/**
 * Plugin Name: PGE AI Photo Tools
 * Description: API-free smart photo tools for WordPress: command-based enhancement, passport crops, local styles, format conversion, batch processing, ZIP downloads, and a printable CV builder.
 * Version: 1.1.0
 * Author: PGE
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: pge-ai-photo-tools
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PGE_AI_VERSION', '1.1.0');
define('PGE_AI_FILE', __FILE__);
define('PGE_AI_DIR', plugin_dir_path(__FILE__));
define('PGE_AI_URL', plugin_dir_url(__FILE__));

require_once PGE_AI_DIR . 'includes/class-pge-command-parser.php';
require_once PGE_AI_DIR . 'includes/class-pge-image-engine.php';
require_once PGE_AI_DIR . 'includes/class-pge-security.php';
require_once PGE_AI_DIR . 'includes/class-pge-processor.php';
require_once PGE_AI_DIR . 'includes/class-pge-shortcode.php';
require_once PGE_AI_DIR . 'admin/class-pge-admin.php';

final class PGE_AI_Photo_Tools {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'boot'));
        add_action('pge_ai_cleanup_outputs', array($this, 'cleanup_outputs'));
    }

    public function boot() {
        PGE_Shortcode::init();
        PGE_Processor::init();

        if (is_admin()) {
            PGE_Admin::init();
        }
    }

    public static function activate() {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(esc_html__('PGE AI Photo Tools requires PHP 7.4 or newer.', 'pge-ai-photo-tools'));
        }

        $defaults = array(
            'max_files'       => 10,
            'max_file_mb'     => 10,
            'max_dimension'   => 6000,
            'output_quality'  => 90,
            'cleanup_days'    => 2,
            'guest_access'    => 1,
            'enable_zip'      => 1,
        );

        $current = get_option('pge_ai_settings', array());
        update_option('pge_ai_settings', wp_parse_args($current, $defaults));

        if (!wp_next_scheduled('pge_ai_cleanup_outputs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'pge_ai_cleanup_outputs');
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled('pge_ai_cleanup_outputs');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'pge_ai_cleanup_outputs');
        }
    }

    public function cleanup_outputs() {
        $settings = get_option('pge_ai_settings', array());
        $days = max(1, absint(isset($settings['cleanup_days']) ? $settings['cleanup_days'] : 2));
        $uploads = wp_upload_dir();
        $root = trailingslashit($uploads['basedir']) . 'pge-ai-photo-tools';

        if (!is_dir($root)) {
            return;
        }

        $cutoff = time() - ($days * DAY_IN_SECONDS);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && $file->getMTime() < $cutoff) {
                @unlink($path);
            } elseif ($file->isDir()) {
                @rmdir($path);
            }
        }
    }
}

register_activation_hook(__FILE__, array('PGE_AI_Photo_Tools', 'activate'));
register_deactivation_hook(__FILE__, array('PGE_AI_Photo_Tools', 'deactivate'));

PGE_AI_Photo_Tools::instance();
