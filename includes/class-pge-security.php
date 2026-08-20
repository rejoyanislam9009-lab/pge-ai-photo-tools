<?php
if (!defined('ABSPATH')) {
    exit;
}

class PGE_Security {
    public static function settings() {
        return wp_parse_args(get_option('pge_ai_settings', array()), array(
            'max_files'      => 10,
            'max_file_mb'    => 10,
            'max_dimension'  => 6000,
            'output_quality' => 90,
            'cleanup_days'   => 2,
            'guest_access'   => 1,
            'enable_zip'     => 1,
        ));
    }

    public static function can_use() {
        $settings = self::settings();
        return is_user_logged_in() || !empty($settings['guest_access']);
    }

    public static function validate_upload($file) {
        $settings = self::settings();

        if (empty($file) || !isset($file['tmp_name'], $file['name'], $file['size'], $file['error'])) {
            return new WP_Error('missing_file', __('No image was uploaded.', 'pge-ai-photo-tools'));
        }

        if (UPLOAD_ERR_OK !== (int) $file['error']) {
            return new WP_Error('upload_error', __('The image upload failed.', 'pge-ai-photo-tools'));
        }

        $max_bytes = max(1, absint($settings['max_file_mb'])) * MB_IN_BYTES;
        if ((int) $file['size'] > $max_bytes) {
            return new WP_Error('file_too_large', sprintf(__('Image exceeds the %d MB upload limit.', 'pge-ai-photo-tools'), absint($settings['max_file_mb'])));
        }

        $allowed = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
        );

        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed);
        if (empty($check['type']) || !in_array($check['type'], array_values($allowed), true)) {
            return new WP_Error('invalid_type', __('Only JPG, PNG, and WebP images are allowed.', 'pge-ai-photo-tools'));
        }

        return true;
    }

    public static function rate_limit() {
        $key = 'pge_ai_rate_' . md5(self::client_key());
        $count = (int) get_transient($key);
        $limit = (int) apply_filters('pge_ai_requests_per_10_minutes', 60);

        if ($count >= $limit) {
            return new WP_Error('rate_limited', __('Too many requests. Please try again later.', 'pge-ai-photo-tools'));
        }

        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function client_key() {
        if (is_user_logged_in()) {
            return 'user:' . get_current_user_id();
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return 'ip:' . $ip;
    }

    public static function sign_relative_path($relative_path) {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        $payload = rtrim(strtr(base64_encode($relative_path), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, wp_salt('auth'));
        return $payload . '.' . $signature;
    }

    public static function verify_relative_path_token($token) {
        $parts = explode('.', (string) $token, 2);
        if (2 !== count($parts)) {
            return false;
        }

        list($payload, $signature) = $parts;
        $expected = hash_hmac('sha256', $payload, wp_salt('auth'));
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $padded = strtr($payload, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $relative = base64_decode($padded, true);
        if (false === $relative) {
            return false;
        }

        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (false !== strpos($relative, '..')) {
            return false;
        }
        return $relative;
    }
}
