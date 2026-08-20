<?php
if (!defined('ABSPATH')) { exit; }
class PGE_Processor {
    public static function init() {
        add_action('wp_ajax_pge_process_image', array(__CLASS__, 'ajax_process_image'));
        add_action('wp_ajax_nopriv_pge_process_image', array(__CLASS__, 'ajax_process_image'));
        add_action('wp_ajax_pge_create_zip', array(__CLASS__, 'ajax_create_zip'));
        add_action('wp_ajax_nopriv_pge_create_zip', array(__CLASS__, 'ajax_create_zip'));
    }
    public static function ajax_process_image() {
        check_ajax_referer('pge_ai_nonce', 'nonce');
        if (!PGE_Security::can_use()) { wp_send_json_error(array('message' => __('Guest use is disabled.', 'pge-ai-photo-tools')), 403); }
        $rate = PGE_Security::rate_limit();
        if (is_wp_error($rate)) { wp_send_json_error(array('message' => $rate->get_error_message()), 429); }
        $file = isset($_FILES['image']) ? $_FILES['image'] : null;
        $valid = PGE_Security::validate_upload($file);
        if (is_wp_error($valid)) { wp_send_json_error(array('message' => $valid->get_error_message()), 400); }
        $source_info = @getimagesize($file['tmp_name']);
        $command = isset($_POST['command']) ? sanitize_text_field(wp_unslash($_POST['command'])) : '';
        $ops = PGE_Command_Parser::parse($command);
        $settings = PGE_Security::settings();
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = array('test_form' => false, 'mimes' => array('jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'));
        $upload = wp_handle_upload($file, $overrides);
        if (!empty($upload['error'])) { wp_send_json_error(array('message' => sanitize_text_field($upload['error'])), 400); }
        $uploads = wp_upload_dir();
        $destination_dir = trailingslashit($uploads['basedir']) . 'pge-ai-photo-tools/' . gmdate('Y/m/d');
        $result = PGE_Image_Engine::process($upload['file'], $destination_dir, $ops, $settings);
        @unlink($upload['file']);
        if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message()), 500); }
        $relative = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $result['path']), '/');
        $url = trailingslashit($uploads['baseurl']) . str_replace('%2F', '/', rawurlencode($relative));
        $url = add_query_arg('pge_v', time(), $url);
        $token = PGE_Security::sign_relative_path($relative);
        $notice = !empty($ops['fallback']) ? __('That exact command is not a local preset, so PGE applied strong auto-enhance instead.', 'pge-ai-photo-tools') : '';
        wp_send_json_success(array(
            'url' => esc_url_raw($url), 'filename' => basename($result['path']), 'token' => $token,
            'operations' => $ops, 'operation_summary' => PGE_Command_Parser::describe($ops), 'notice' => $notice,
            'format' => $result['format'], 'engine' => isset($result['engine']) ? sanitize_text_field($result['engine']) : '',
            'width' => isset($result['width']) ? absint($result['width']) : 0, 'height' => isset($result['height']) ? absint($result['height']) : 0,
            'input_width' => !empty($source_info[0]) ? absint($source_info[0]) : 0, 'input_height' => !empty($source_info[1]) ? absint($source_info[1]) : 0,
        ));
    }
    public static function ajax_create_zip() {
        check_ajax_referer('pge_ai_nonce', 'nonce');
        $settings = PGE_Security::settings();
        if (empty($settings['enable_zip'])) { wp_send_json_error(array('message' => __('ZIP downloads are disabled.', 'pge-ai-photo-tools')), 403); }
        if (!class_exists('ZipArchive')) { wp_send_json_error(array('message' => __('ZIP is not available on this server. Download files individually.', 'pge-ai-photo-tools')), 500); }
        $tokens = isset($_POST['tokens']) && is_array($_POST['tokens']) ? array_map('sanitize_text_field', wp_unslash($_POST['tokens'])) : array();
        $tokens = array_slice($tokens, 0, 50);
        if (count($tokens) < 2) { wp_send_json_error(array('message' => __('At least two processed images are required for a ZIP.', 'pge-ai-photo-tools')), 400); }
        $uploads = wp_upload_dir(); $root = trailingslashit($uploads['basedir']);
        $zip_dir = $root . 'pge-ai-photo-tools/zips/' . gmdate('Y/m/d'); wp_mkdir_p($zip_dir);
        $zip_name = 'pge-photos-' . wp_generate_password(8, false, false) . '.zip'; $zip_path = trailingslashit($zip_dir) . $zip_name;
        $zip = new ZipArchive();
        if (true !== $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) { wp_send_json_error(array('message' => __('Could not create ZIP archive.', 'pge-ai-photo-tools')), 500); }
        $added = 0;
        foreach ($tokens as $token) {
            $relative = PGE_Security::verify_relative_path_token($token);
            if (!$relative || 0 !== strpos($relative, 'pge-ai-photo-tools/')) { continue; }
            $path = $root . $relative; $real_root = realpath($root . 'pge-ai-photo-tools'); $real_path = realpath($path);
            if (!$real_root || !$real_path || 0 !== strpos($real_path, $real_root) || !is_file($real_path)) { continue; }
            $zip->addFile($real_path, basename($real_path)); $added++;
        }
        $zip->close();
        if ($added < 2) { @unlink($zip_path); wp_send_json_error(array('message' => __('Could not verify enough files for the ZIP.', 'pge-ai-photo-tools')), 400); }
        $relative_zip = ltrim(str_replace($root, '', $zip_path), '/');
        $zip_url = trailingslashit($uploads['baseurl']) . str_replace('%2F', '/', rawurlencode($relative_zip));
        wp_send_json_success(array('url' => esc_url_raw($zip_url), 'filename' => $zip_name));
    }
}
