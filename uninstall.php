<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('pge_ai_settings');
$timestamp = wp_next_scheduled('pge_ai_cleanup_outputs');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'pge_ai_cleanup_outputs');
}
