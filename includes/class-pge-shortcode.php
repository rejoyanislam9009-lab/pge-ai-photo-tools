<?php
if (!defined('ABSPATH')) {
    exit;
}

class PGE_Shortcode {
    public static function init() {
        add_shortcode('pge_ai', array(__CLASS__, 'render'));
        add_shortcode('pge_photo_tools', array(__CLASS__, 'render'));
    }

    public static function render($atts = array()) {
        if (!PGE_Security::can_use()) {
            return '<div class="pge-ai-notice">' . esc_html__('This photo tool is available to logged-in users only.', 'pge-ai-photo-tools') . '</div>';
        }

        $atts = shortcode_atts(array('mode' => 'all'), $atts, 'pge_ai');
        $settings = PGE_Security::settings();
        $command_id = wp_unique_id("pge-ai-command-");

        wp_enqueue_style('pge-ai-photo-tools', PGE_AI_URL . 'public/css/pge-ai.css', array(), PGE_AI_VERSION);
        wp_enqueue_script('pge-ai-photo-tools', PGE_AI_URL . 'public/js/pge-ai.js', array(), PGE_AI_VERSION, true);
        wp_localize_script('pge-ai-photo-tools', 'PGE_AI', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('pge_ai_nonce'),
            'maxFiles' => max(1, absint($settings['max_files'])),
            'maxMB'    => max(1, absint($settings['max_file_mb'])),
            'mode'     => sanitize_key($atts['mode']),
            'i18n'     => array(
                'processing' => __('Processing', 'pge-ai-photo-tools'),
                'done'       => __('Done', 'pge-ai-photo-tools'),
                'failed'     => __('Failed', 'pge-ai-photo-tools'),
                'noFiles'    => __('Choose at least one image.', 'pge-ai-photo-tools'),
                'tooMany'    => sprintf(__('You can process up to %d images at once.', 'pge-ai-photo-tools'), absint($settings['max_files'])),
            ),
        ));

        ob_start();
        ?>
        <div class="pge-ai-app" data-mode="<?php echo esc_attr($atts['mode']); ?>">
            <div class="pge-ai-header">
                <div>
                    <h2><?php esc_html_e('PGE Smart Photo Tools', 'pge-ai-photo-tools'); ?></h2>
                    <p><?php esc_html_e('API-free photo processing on your own WordPress server.', 'pge-ai-photo-tools'); ?></p>
                </div>
                <span class="pge-ai-badge"><?php esc_html_e('No API key', 'pge-ai-photo-tools'); ?></span>
            </div>

            <div class="pge-ai-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e('Choose images', 'pge-ai-photo-tools'); ?>">
                <input class="pge-ai-files" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden>
                <strong><?php esc_html_e('Drop photos here or click to choose', 'pge-ai-photo-tools'); ?></strong>
                <span><?php echo esc_html(sprintf(__('JPG, PNG, WebP | up to %d files | %d MB each', 'pge-ai-photo-tools'), absint($settings['max_files']), absint($settings['max_file_mb']))); ?></span>
            </div>

            <div class="pge-ai-file-list" aria-live="polite"></div>

            <label class="pge-ai-label" for="<?php echo esc_attr($command_id); ?>"><?php esc_html_e('Command', 'pge-ai-photo-tools'); ?></label>
            <textarea id="<?php echo esc_attr($command_id); ?>" class="pge-ai-command" rows="3" placeholder="<?php esc_attr_e('Example: clear photo hd, passport 35x45, CV photo, studio, brighter, rotate 90, resize 600x600 webp', 'pge-ai-photo-tools'); ?>"></textarea>

            <div class="pge-ai-presets" aria-label="<?php esc_attr_e('Quick commands', 'pge-ai-photo-tools'); ?>">
                <button type="button" data-command="clear photo hd 2x"><?php esc_html_e('Clear / HD 2x', 'pge-ai-photo-tools'); ?></button>
                <button type="button" data-command="passport 35x45 white background"><?php esc_html_e('Passport 35x45', 'pge-ai-photo-tools'); ?></button>
                <button type="button" data-command="passport 2x2 white background"><?php esc_html_e('Passport 2x2', 'pge-ai-photo-tools'); ?></button>
                <button type="button" data-command="cv photo white background studio"><?php esc_html_e('CV Headshot', 'pge-ai-photo-tools'); ?></button>
                <button type="button" data-command="studio clear photo hd 2x"><?php esc_html_e('Studio', 'pge-ai-photo-tools'); ?></button>
                <button type="button" data-command="black and white clear photo"><?php esc_html_e('B&W', 'pge-ai-photo-tools'); ?></button>
                <button type="button" data-command="vintage"><?php esc_html_e('Vintage', 'pge-ai-photo-tools'); ?></button>
                <button type="button" data-command="brighter more contrast"><?php esc_html_e('Bright + Contrast', 'pge-ai-photo-tools'); ?></button>
                <button type="button" data-command="webp quality 88"><?php esc_html_e('Convert WebP', 'pge-ai-photo-tools'); ?></button>
            </div>

            <div class="pge-ai-actions">
                <button type="button" class="pge-ai-run"><?php esc_html_e('Process Photos', 'pge-ai-photo-tools'); ?></button>
                <button type="button" class="pge-ai-reset"><?php esc_html_e('Reset', 'pge-ai-photo-tools'); ?></button>
                <button type="button" class="pge-ai-zip" hidden><?php esc_html_e('Download ZIP', 'pge-ai-photo-tools'); ?></button>
            </div>

            <div class="pge-ai-status" aria-live="polite"></div>
            <div class="pge-ai-results"></div>

            <?php if ('photo' !== $atts['mode'] && 'passport' !== $atts['mode']) : ?>
            <details class="pge-cv-builder">
                <summary><?php esc_html_e('Printable CV Builder (no API)', 'pge-ai-photo-tools'); ?></summary>
                <div class="pge-cv-grid">
                    <label><?php esc_html_e('Photo', 'pge-ai-photo-tools'); ?><input type="file" class="pge-cv-photo" accept="image/jpeg,image/png,image/webp"></label>
                    <label><?php esc_html_e('Full name', 'pge-ai-photo-tools'); ?><input type="text" class="pge-cv-name"></label>
                    <label><?php esc_html_e('Professional title', 'pge-ai-photo-tools'); ?><input type="text" class="pge-cv-title"></label>
                    <label><?php esc_html_e('Email', 'pge-ai-photo-tools'); ?><input type="email" class="pge-cv-email"></label>
                    <label><?php esc_html_e('Phone', 'pge-ai-photo-tools'); ?><input type="text" class="pge-cv-phone"></label>
                    <label class="pge-cv-wide"><?php esc_html_e('Profile summary', 'pge-ai-photo-tools'); ?><textarea class="pge-cv-summary" rows="4"></textarea></label>
                    <label class="pge-cv-wide"><?php esc_html_e('Skills (one per line)', 'pge-ai-photo-tools'); ?><textarea class="pge-cv-skills" rows="4"></textarea></label>
                    <label class="pge-cv-wide"><?php esc_html_e('Experience', 'pge-ai-photo-tools'); ?><textarea class="pge-cv-experience" rows="6"></textarea></label>
                    <label class="pge-cv-wide"><?php esc_html_e('Education', 'pge-ai-photo-tools'); ?><textarea class="pge-cv-education" rows="5"></textarea></label>
                </div>
                <button type="button" class="pge-cv-generate"><?php esc_html_e('Generate / Print CV', 'pge-ai-photo-tools'); ?></button>
                <p class="pge-cv-note"><?php esc_html_e('The CV opens in a print-ready window. Use your browser Print > Save as PDF.', 'pge-ai-photo-tools'); ?></p>
            </details>
            <?php endif; ?>

            <p class="pge-ai-footnote"><?php esc_html_e('Results now show Before vs Processed. Local enhancement can sharpen, upscale, resize and apply filters, but it cannot recreate missing facial detail or change clothes like a generative AI model.', 'pge-ai-photo-tools'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
}
