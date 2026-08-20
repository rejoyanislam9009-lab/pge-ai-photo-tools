<?php
if (!defined('ABSPATH')) {
    exit;
}

class PGE_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
    }

    public static function menu() {
        add_options_page(
            __('PGE Photo Tools', 'pge-ai-photo-tools'),
            __('PGE Photo Tools', 'pge-ai-photo-tools'),
            'manage_options',
            'pge-ai-photo-tools',
            array(__CLASS__, 'page')
        );
    }

    public static function register() {
        register_setting('pge_ai_settings_group', 'pge_ai_settings', array(__CLASS__, 'sanitize'));
    }

    public static function sanitize($input) {
        return array(
            'max_files'      => min(30, max(1, absint(isset($input['max_files']) ? $input['max_files'] : 10))),
            'max_file_mb'    => min(50, max(1, absint(isset($input['max_file_mb']) ? $input['max_file_mb'] : 10))),
            'max_dimension'  => min(12000, max(1000, absint(isset($input['max_dimension']) ? $input['max_dimension'] : 6000))),
            'output_quality' => min(100, max(40, absint(isset($input['output_quality']) ? $input['output_quality'] : 90))),
            'cleanup_days'   => min(30, max(1, absint(isset($input['cleanup_days']) ? $input['cleanup_days'] : 2))),
            'guest_access'   => empty($input['guest_access']) ? 0 : 1,
            'enable_zip'     => empty($input['enable_zip']) ? 0 : 1,
        );
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = PGE_Security::settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('PGE Smart Photo Tools', 'pge-ai-photo-tools'); ?></h1>
            <p><strong><?php esc_html_e('Shortcode:', 'pge-ai-photo-tools'); ?></strong> <code>[pge_ai]</code> &nbsp; <code>[pge_ai mode="photo"]</code> &nbsp; <code>[pge_ai mode="passport"]</code></p>
            <p><?php esc_html_e('This build uses no paid API. Processing runs with Imagick when available, otherwise GD.', 'pge-ai-photo-tools'); ?></p>

            <table class="widefat striped" style="max-width:780px;margin:16px 0">
                <tbody>
                    <tr><td>Imagick</td><td><?php echo class_exists('Imagick') ? '<strong>Available</strong>' : 'Not available (GD fallback will be used)'; ?></td></tr>
                    <tr><td>GD</td><td><?php echo function_exists('imagecreatefromjpeg') ? '<strong>Available</strong>' : 'Not available'; ?></td></tr>
                    <tr><td>ZipArchive</td><td><?php echo class_exists('ZipArchive') ? '<strong>Available</strong>' : 'Not available (individual downloads only)'; ?></td></tr>
                </tbody>
            </table>

            <form method="post" action="options.php">
                <?php settings_fields('pge_ai_settings_group'); ?>
                <table class="form-table">
                    <tr><th scope="row"><label for="pge-max-files"><?php esc_html_e('Max files per batch', 'pge-ai-photo-tools'); ?></label></th><td><input id="pge-max-files" type="number" min="1" max="30" name="pge_ai_settings[max_files]" value="<?php echo esc_attr($s['max_files']); ?>"></td></tr>
                    <tr><th scope="row"><label for="pge-max-mb"><?php esc_html_e('Max MB per image', 'pge-ai-photo-tools'); ?></label></th><td><input id="pge-max-mb" type="number" min="1" max="50" name="pge_ai_settings[max_file_mb]" value="<?php echo esc_attr($s['max_file_mb']); ?>"></td></tr>
                    <tr><th scope="row"><label for="pge-max-dim"><?php esc_html_e('Max source dimension (px)', 'pge-ai-photo-tools'); ?></label></th><td><input id="pge-max-dim" type="number" min="1000" max="12000" step="100" name="pge_ai_settings[max_dimension]" value="<?php echo esc_attr($s['max_dimension']); ?>"></td></tr>
                    <tr><th scope="row"><label for="pge-quality"><?php esc_html_e('Default output quality', 'pge-ai-photo-tools'); ?></label></th><td><input id="pge-quality" type="number" min="40" max="100" name="pge_ai_settings[output_quality]" value="<?php echo esc_attr($s['output_quality']); ?>"></td></tr>
                    <tr><th scope="row"><label for="pge-cleanup"><?php esc_html_e('Auto-delete outputs after days', 'pge-ai-photo-tools'); ?></label></th><td><input id="pge-cleanup" type="number" min="1" max="30" name="pge_ai_settings[cleanup_days]" value="<?php echo esc_attr($s['cleanup_days']); ?>"></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Guest access', 'pge-ai-photo-tools'); ?></th><td><label><input type="checkbox" name="pge_ai_settings[guest_access]" value="1" <?php checked(!empty($s['guest_access'])); ?>> <?php esc_html_e('Allow visitors who are not logged in', 'pge-ai-photo-tools'); ?></label></td></tr>
                    <tr><th scope="row"><?php esc_html_e('ZIP download', 'pge-ai-photo-tools'); ?></th><td><label><input type="checkbox" name="pge_ai_settings[enable_zip]" value="1" <?php checked(!empty($s['enable_zip'])); ?>> <?php esc_html_e('Enable batch ZIP when ZipArchive is installed', 'pge-ai-photo-tools'); ?></label></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
