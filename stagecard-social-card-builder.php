<?php
/**
 * Plugin Name: Stagecard Social Card Builder
 * Description: Adds a public social card image builder and, when Stagecard is active, places it under the Programs admin menu.
 * Version: 0.4.0
 * Author: Olivia Kohring
 * Text Domain: stagecard-social-card-builder
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Stagecard_Social_Card_Builder {
    const VERSION = '0.4.0';
    const GITHUB_REPO = 'okohring/stagecard-social-card-builder';
    const SHORTCODE = 'dhkc_social_card_builder';
    const ALIAS_SHORTCODE = 'stagecard_social_card_builder';
    const MENU_SLUG = 'stagecard-social-card-builder';
    const OPTION_SETTINGS = 'stagecard_social_card_builder_settings';

    public function __construct() {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_shortcode(self::ALIAS_SHORTCODE, array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_menu', array($this, 'admin_menu'), 99);
        add_action('admin_post_stagecard_social_card_save_settings', array($this, 'save_settings'));
        add_action('wp_ajax_stagecard_social_card_default_template', array($this, 'serve_default_template'));
        add_action('wp_ajax_nopriv_stagecard_social_card_default_template', array($this, 'serve_default_template'));
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_github_plugin_update'));
        add_filter('site_transient_update_plugins', array($this, 'check_github_plugin_update'));
        add_filter('plugins_api', array($this, 'github_plugin_info'), 20, 3);
        add_action('admin_init', array($this, 'maybe_clear_github_update_cache'));
    }

    public function register_assets() {
        $base_url = plugin_dir_url(__FILE__);

        wp_register_style(
            'stagecard-social-card-builder',
            $base_url . 'assets/css/public.css',
            array(),
            self::VERSION
        );

        wp_register_script(
            'stagecard-social-card-builder',
            $base_url . 'assets/js/public.js',
            array(),
            self::VERSION,
            true
        );

        wp_localize_script(
            'stagecard-social-card-builder',
            'DHKCSocialCardBuilder',
            array(
                'templateUrl' => $this->template_url(),
                'downloadFileName' => $this->download_file_name(),
            )
        );
    }

    public function admin_assets($hook) {
        if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::MENU_SLUG) {
            return;
        }

        $this->register_assets();
        wp_enqueue_media();
        wp_enqueue_style('stagecard-social-card-builder');
        wp_enqueue_script('stagecard-social-card-builder');
        wp_enqueue_script(
            'stagecard-social-card-builder-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            self::VERSION,
            true
        );

        wp_add_inline_style('stagecard-social-card-builder', '
            .stagecard-social-card-admin{max-width:1080px;}
            .stagecard-social-card-admin-intro{max-width:760px;}
            .stagecard-social-card-settings{display:grid;gap:18px;margin:20px 0 26px;padding:22px;border:1px solid #dcdcde;border-radius:14px;background:#fff;}
            .stagecard-social-card-settings h2{margin:0;}
            .stagecard-social-card-settings-grid{display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:start;}
            .stagecard-social-card-settings-grid label{font-weight:700;}
            .stagecard-social-card-template-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
            .stagecard-social-card-template-row input{width:min(620px,100%);}
            .stagecard-social-card-template-preview{max-width:220px;border:1px solid #dcdcde;border-radius:12px;background:#f6f7f7;display:block;}
            .stagecard-social-card-shortcode{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0 18px;}
            .stagecard-social-card-shortcode input{width:min(420px,100%);font-family:monospace;}
            .stagecard-social-card-admin .dhkc-card-builder{margin:20px 0 0;}
            @media(max-width:782px){.stagecard-social-card-settings-grid{grid-template-columns:1fr;}}
        ');
    }

    public function admin_menu() {
        if ($this->stagecard_menu_exists()) {
            add_submenu_page(
                'program-main',
                'Social Card Builder',
                'Social Card Builder',
                'edit_posts',
                self::MENU_SLUG,
                array($this, 'render_admin_page')
            );
            return;
        }

        add_menu_page(
            'Social Card Builder',
            'Social Card Builder',
            'edit_posts',
            self::MENU_SLUG,
            array($this, 'render_admin_page'),
            'dashicons-format-image',
            27
        );
    }

    private function stagecard_menu_exists() {
        global $menu;
        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (!empty($item[2]) && $item[2] === 'program-main') {
                    return true;
                }
            }
        }

        return class_exists('Program_Agenda_Plugin');
    }

    private function default_template_url() {
        return add_query_arg('action', 'stagecard_social_card_default_template', admin_url('admin-ajax.php'));
    }

    public function serve_default_template() {
        $path = plugin_dir_path(__FILE__) . 'assets/img/attendee-template.b64';
        if (!file_exists($path)) {
            status_header(404);
            exit;
        }

        $encoded = preg_replace('/\s+/', '', (string) file_get_contents($path));
        $image = base64_decode($encoded);
        if (!$image) {
            status_header(500);
            exit;
        }

        nocache_headers();
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($image));
        echo $image;
        exit;
    }

    private function settings() {
        $settings = get_option(self::OPTION_SETTINGS, array());
        return is_array($settings) ? $settings : array();
    }

    private function template_url() {
        $settings = $this->settings();
        $url = isset($settings['template_url']) ? esc_url_raw($settings['template_url']) : '';
        return $url ? $url : $this->default_template_url();
    }

    private function download_file_name() {
        $settings = $this->settings();
        $file_name = isset($settings['download_file_name']) ? sanitize_file_name($settings['download_file_name']) : '';
        return $file_name ? $file_name : 'stagecard-social-card.png';
    }

    public function save_settings() {
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to edit these settings.');
        }

        check_admin_referer('stagecard_social_card_save_settings');

        $template_url = isset($_POST['template_url']) ? esc_url_raw(wp_unslash($_POST['template_url'])) : '';
        $download_file_name = isset($_POST['download_file_name']) ? sanitize_file_name(wp_unslash($_POST['download_file_name'])) : '';

        update_option(
            self::OPTION_SETTINGS,
            array(
                'template_url' => $template_url,
                'download_file_name' => $download_file_name,
            ),
            false
        );

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&saved=1'));
        exit;
    }

    public function render_admin_page() {
        $template_url = $this->template_url();
        $download_file_name = $this->download_file_name();
        ?>
        <div class="wrap stagecard-social-card-admin">
            <h1>Social Card Builder</h1>
            <?php if (!empty($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Social card settings saved.</p></div>
            <?php endif; ?>
            <p class="stagecard-social-card-admin-intro">Created by Olivia Kohring. Use this page to manage the card template and test the public builder. Add one of the shortcodes below to any public page where attendees should create and download their image.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="stagecard-social-card-settings">
                <?php wp_nonce_field('stagecard_social_card_save_settings'); ?>
                <input type="hidden" name="action" value="stagecard_social_card_save_settings">
                <h2>Card Settings</h2>

                <div class="stagecard-social-card-settings-grid">
                    <label for="stagecard-social-card-template-url">Card template</label>
                    <div>
                        <div class="stagecard-social-card-template-row">
                            <input id="stagecard-social-card-template-url" class="regular-text stagecard-social-card-template-url" type="url" name="template_url" value="<?php echo esc_attr($template_url); ?>" placeholder="<?php echo esc_attr($this->default_template_url()); ?>">
                            <button type="button" class="button stagecard-social-card-template-upload">Choose from Media Library</button>
                            <button type="button" class="button stagecard-social-card-template-reset" data-default-url="<?php echo esc_url($this->default_template_url()); ?>">Use default</button>
                        </div>
                        <p class="description">Use a square PNG with a transparent circle where the attendee photo should appear. The original card size is 1201 × 1201.</p>
                        <img class="stagecard-social-card-template-preview" src="<?php echo esc_url($template_url); ?>" alt="Current card template preview">
                    </div>
                </div>

                <div class="stagecard-social-card-settings-grid">
                    <label for="stagecard-social-card-download-file-name">Download filename</label>
                    <div>
                        <input id="stagecard-social-card-download-file-name" class="regular-text" type="text" name="download_file_name" value="<?php echo esc_attr($download_file_name); ?>">
                        <p class="description">Example: digital-health-day-2026-attendee.png</p>
                    </div>
                </div>

                <p><button type="submit" class="button button-primary">Save Social Card Settings</button></p>
            </form>

            <h2>Shortcodes</h2>
            <div class="stagecard-social-card-shortcode">
                <input readonly value="[dhkc_social_card_builder]" onclick="this.select();">
                <input readonly value="[stagecard_social_card_builder]" onclick="this.select();">
            </div>

            <h2>Preview</h2>
            <?php echo $this->render_shortcode(); ?>
        </div>
        <?php
    }

    public function render_shortcode($atts = array()) {
        wp_enqueue_style('stagecard-social-card-builder');
        wp_enqueue_script('stagecard-social-card-builder');

        $id = 'stagecard-social-card-builder-' . wp_generate_uuid4();

        ob_start();
        ?>
        <div class="dhkc-card-builder" id="<?php echo esc_attr($id); ?>" data-dhkc-card-builder>
            <div class="dhkc-card-builder__controls" aria-label="Photo adjustment controls">
                <div class="dhkc-card-builder__control-grid">
                    <label class="dhkc-card-builder__upload">
                        <span>Upload your photo</span>
                        <input class="dhkc-card-builder__file" type="file" accept="image/png,image/jpeg,image/webp" />
                    </label>

                    <label class="dhkc-card-builder__range">
                        <span>Photo size</span>
                        <input class="dhkc-card-builder__zoom" type="range" min="0.25" max="4" step="0.01" value="1" disabled />
                    </label>
                </div>

                <div class="dhkc-card-builder__actions">
                    <button type="button" class="dhkc-card-builder__button dhkc-card-builder__button--secondary" data-dhkc-reset disabled>Reset photo</button>
                    <button type="button" class="dhkc-card-builder__button" data-dhkc-download disabled>Download PNG</button>
                </div>
            </div>

            <div class="dhkc-card-builder__editor" aria-label="Social card preview editor">
                <canvas class="dhkc-card-builder__canvas" width="1201" height="1201"></canvas>
            </div>

            <p class="dhkc-card-builder__note">Drag the photo inside the circle, resize it above, then download the finished PNG.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    public function maybe_clear_github_update_cache() {
        if (!is_admin() || !current_user_can('update_plugins')) { return; }
        if (isset($_GET['force-check']) || isset($_GET['stagecard_social_card_clear_update_cache'])) {
            delete_site_transient('stagecard_social_card_github_release');
            delete_site_transient('update_plugins');
            if (function_exists('wp_clean_plugins_cache')) { wp_clean_plugins_cache(true); }
        }
    }

    public function check_github_plugin_update($transient) {
        if (empty($transient) || !is_object($transient)) { return $transient; }

        $plugin_file = plugin_basename(__FILE__);
        if (empty($transient->checked)) { $transient->checked = array(); }
        if (empty($transient->checked[$plugin_file])) { $transient->checked[$plugin_file] = self::VERSION; }
        if (empty($transient->response) || !is_array($transient->response)) { $transient->response = array(); }
        if (empty($transient->no_update) || !is_array($transient->no_update)) { $transient->no_update = array(); }

        $release = $this->github_latest_release();
        if (!$release || empty($release['version']) || empty($release['download_url'])) { return $transient; }

        if (!version_compare($release['version'], self::VERSION, '>')) {
            $transient->no_update[$plugin_file] = (object) array(
                'id' => self::GITHUB_REPO,
                'slug' => dirname($plugin_file),
                'plugin' => $plugin_file,
                'new_version' => self::VERSION,
                'url' => $release['html_url'],
                'package' => '',
            );
            unset($transient->response[$plugin_file]);
            return $transient;
        }

        unset($transient->no_update[$plugin_file]);
        $transient->response[$plugin_file] = (object) array(
            'id' => self::GITHUB_REPO,
            'slug' => dirname($plugin_file),
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['download_url'],
            'tested' => $release['tested'],
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
        );

        return $transient;
    }

    public function github_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(plugin_basename(__FILE__))) {
            return $result;
        }

        $release = $this->github_latest_release(false);
        if (!$release) { return $result; }

        return (object) array(
            'name' => 'Stagecard Social Card Builder',
            'slug' => dirname(plugin_basename(__FILE__)),
            'version' => $release['version'],
            'author' => '<a href="https://oliviakohring.com/">Olivia Kohring</a>',
            'homepage' => $release['html_url'],
            'download_link' => $release['download_url'],
            'requires' => $release['requires'],
            'tested' => $release['tested'],
            'requires_php' => $release['requires_php'],
            'last_updated' => $release['published_at'],
            'sections' => array(
                'description' => 'Stagecard Social Card Builder lets public visitors upload, position, and export a photo inside a branded social graphic. Created by Olivia Kohring.',
                'changelog' => $release['body'] ? wp_kses_post(wpautop($release['body'])) : 'See the GitHub release notes for this version.',
            ),
        );
    }

    private function github_latest_release($use_cache = true) {
        $cache_key = 'stagecard_social_card_github_release';
        if ($use_cache) {
            $cached = get_site_transient($cache_key);
            if (is_array($cached)) { return $cached; }
        }

        $response = wp_remote_get('https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest', array(
            'timeout' => 12,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Stagecard-Social-Card-Builder/' . self::VERSION . '; ' . home_url('/'),
            ),
        ));

        if (is_wp_error($response)) { return false; }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) { return false; }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) { return false; }

        $version = ltrim((string) $data['tag_name'], 'vV');
        $download_url = '';
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                $name = isset($asset['name']) ? strtolower((string) $asset['name']) : '';
                if ($name && substr($name, -4) === '.zip' && !empty($asset['browser_download_url'])) {
                    $download_url = esc_url_raw($asset['browser_download_url']);
                    break;
                }
            }
        }

        if (!$download_url && $version) {
            $download_url = esc_url_raw('https://github.com/' . self::GITHUB_REPO . '/releases/download/' . rawurlencode((string) $data['tag_name']) . '/stagecard-social-card-builder-v' . str_replace('.', '-', $version) . '.zip');
        }

        if (!$download_url) { return false; }

        $release = array(
            'version' => $version,
            'html_url' => !empty($data['html_url']) ? esc_url_raw($data['html_url']) : 'https://github.com/' . self::GITHUB_REPO,
            'download_url' => $download_url,
            'published_at' => !empty($data['published_at']) ? sanitize_text_field($data['published_at']) : '',
            'body' => !empty($data['body']) ? wp_kses_post($data['body']) : '',
            'requires' => '5.8',
            'tested' => '6.8',
            'requires_php' => '7.4',
        );

        set_site_transient($cache_key, $release, 5 * MINUTE_IN_SECONDS);
        return $release;
    }

}

new Stagecard_Social_Card_Builder();
