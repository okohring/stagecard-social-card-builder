<?php
/**
 * Plugin Name: Stagecard Social Card Creator
 * Description: Adds event-specific public social card creators and, when Stagecard is active, places them under the Programs admin menu.
 * Version: 0.78
 * Author: Olivia Kohring
 * Text Domain: stagecard-social-card-builder
 */

if (!defined('ABSPATH')) { exit; }

final class Stagecard_Social_Card_Creator {
    const VERSION = '0.78';
    const GITHUB_REPO = 'okohring/stagecard-social-card-builder';
    const MENU_SLUG = 'stagecard-social-card-builder';
    const OPTION_SETTINGS = 'stagecard_social_card_builder_settings';

    public function __construct() {
        add_action('init', array($this, 'register_shortcodes'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_menu', array($this, 'admin_menu'), 99);
        add_action('admin_post_stagecard_social_card_save', array($this, 'save_card'));
        add_action('admin_post_stagecard_social_card_delete', array($this, 'delete_card'));
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_github_plugin_update'));
        add_filter('site_transient_update_plugins', array($this, 'check_github_plugin_update'));
        add_filter('plugins_api', array($this, 'github_plugin_info'), 20, 3);
        add_action('admin_init', array($this, 'maybe_clear_github_update_cache'));
    }

    public function register_shortcodes() {
        add_shortcode('stagecard_social_card_builder', array($this, 'render_shortcode'));
        add_shortcode('dhkc_social_card_builder', array($this, 'render_shortcode'));
        foreach ($this->cards() as $slug => $card) {
            add_shortcode($slug . '_social_card', array($this, 'render_shortcode'));
        }
    }

    public function register_assets() {
        $url = plugin_dir_url(__FILE__);
        wp_register_style('stagecard-social-card-builder', $url . 'assets/css/public.css', array(), self::VERSION);
        wp_register_script('stagecard-social-card-builder', $url . 'assets/js/public.js', array(), self::VERSION, true);
    }

    public function admin_assets($hook) {
        if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::MENU_SLUG) { return; }
        $this->register_assets();
        wp_enqueue_media();
        wp_enqueue_style('stagecard-social-card-builder');
        wp_enqueue_script('stagecard-social-card-builder');
        wp_enqueue_script('stagecard-social-card-builder-admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', array('jquery', 'media-editor', 'media-views'), self::VERSION, true);
        wp_add_inline_style('stagecard-social-card-builder', $this->admin_css());
    }

    private function admin_css() {
        return '
        .sccc-wrap{max-width:1180px;}
        .sccc-tabs{display:flex;gap:8px;margin:18px 0 22px;border-bottom:1px solid #dcdcde;}
        .sccc-tab{display:inline-flex;padding:10px 14px;text-decoration:none;border:1px solid transparent;border-bottom:0;border-radius:10px 10px 0 0;color:#1d2327;font-weight:700;}
        .sccc-tab.is-active{background:#fff;border-color:#dcdcde;color:#000;}
        .sccc-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:22px;margin:0 0 24px;}
        .sccc-grid{display:grid;grid-template-columns:230px minmax(0,1fr);gap:18px;align-items:start;margin:0 0 18px;}
        .sccc-grid label{font-weight:700;}
        .sccc-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
        .sccc-row input.regular-text{width:min(620px,100%);}
        .sccc-help{margin:6px 0 0;color:#646970;}
        .sccc-preview-img{display:block;max-width:220px;margin-top:12px;border:1px solid #dcdcde;border-radius:12px;background:#f6f7f7;}
        .sccc-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dcdcde;border-radius:14px;overflow:hidden;}
        .sccc-table th,.sccc-table td{padding:14px 16px;border-bottom:1px solid #dcdcde;text-align:left;vertical-align:middle;}
        .sccc-table th{background:#f6f7f7;font-weight:700;}
        .sccc-table tr:last-child td{border-bottom:0;}
        .sccc-shortcode{width:100%;max-width:360px;font-family:monospace;}
        .sccc-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
        .sccc-empty{padding:18px;border:1px dashed #b8c0cc;border-radius:14px;background:#fff;color:#50575e;}
        .sccc-preview-box{margin-top:18px;}
        @media(max-width:782px){.sccc-grid{grid-template-columns:1fr}.sccc-table{display:block;overflow-x:auto}}
        ';
    }

    public function admin_menu() {
        if ($this->stagecard_menu_exists()) {
            add_submenu_page('program-main', 'Social Card Creator', 'Social Card Creator', 'edit_posts', self::MENU_SLUG, array($this, 'admin_page'));
            return;
        }
        add_menu_page('Social Card Creator', 'Social Card Creator', 'edit_posts', self::MENU_SLUG, array($this, 'admin_page'), 'dashicons-format-image', 27);
    }

    private function stagecard_menu_exists() {
        global $menu;
        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (!empty($item[2]) && $item[2] === 'program-main') { return true; }
            }
        }
        return class_exists('Program_Agenda_Plugin');
    }

    private function settings() {
        $settings = get_option(self::OPTION_SETTINGS, array());
        if (!is_array($settings)) { $settings = array(); }
        if (empty($settings['cards']) || !is_array($settings['cards'])) { $settings['cards'] = array(); }
        foreach ($settings['cards'] as $slug => $card) {
            if (empty($card['template_url']) && $slug === 'dhkc') { unset($settings['cards'][$slug]); }
        }
        return $settings;
    }

    private function cards() {
        $settings = $this->settings();
        return $settings['cards'];
    }

    private function normalize_slug($value) {
        $value = trim((string) $value);
        $value = trim($value, '[] ');
        if (substr($value, -12) === '_social_card') { $value = substr($value, 0, -12); }
        $value = sanitize_title($value);
        $value = str_replace('-', '_', $value);
        $value = preg_replace('/_+/', '_', $value);
        return sanitize_key($value);
    }

    private function next_slug($preferred = 'stagecard') {
        $cards = $this->cards();
        $base = $this->normalize_slug($preferred) ?: 'stagecard';
        if (!isset($cards[$base])) { return $base; }
        $i = 1;
        do { $slug = $base . '_' . str_pad((string) $i, 2, '0', STR_PAD_LEFT); $i++; } while (isset($cards[$slug]));
        return $slug;
    }

    private function first_card_slug() {
        $keys = array_keys($this->cards());
        return $keys ? $keys[0] : '';
    }

    private function card_for_slug($slug) {
        $cards = $this->cards();
        $slug = $this->normalize_slug($slug);
        return ($slug && isset($cards[$slug])) ? $cards[$slug] : array();
    }

    private function shortcode_for_slug($slug) {
        return '[' . $this->normalize_slug($slug) . '_social_card]';
    }

    private function download_name($card) {
        if (!empty($card['download_file_name'])) { return sanitize_file_name($card['download_file_name']); }
        $slug = !empty($card['slug']) ? $this->normalize_slug($card['slug']) : 'stagecard';
        return $slug . '.png';
    }

    public function save_card() {
        if (!current_user_can('edit_posts')) { wp_die('You do not have permission to edit social cards.'); }
        check_admin_referer('stagecard_social_card_save');
        $original = isset($_POST['original_slug']) ? $this->normalize_slug(wp_unslash($_POST['original_slug'])) : '';
        $name = isset($_POST['card_name']) ? sanitize_text_field(wp_unslash($_POST['card_name'])) : '';
        $slug = isset($_POST['card_slug']) ? $this->normalize_slug(wp_unslash($_POST['card_slug'])) : '';
        $template = isset($_POST['template_url']) ? esc_url_raw(wp_unslash($_POST['template_url'])) : '';
        $download = isset($_POST['download_file_name']) ? sanitize_file_name(wp_unslash($_POST['download_file_name'])) : '';
        if (!$slug) { $slug = $this->next_slug($name ?: 'stagecard'); }
        if (!$name) { $name = ucwords(str_replace('_', ' ', $slug)); }
        if (!$download) { $download = $slug . '.png'; }
        $settings = $this->settings();
        $cards = $this->cards();
        if ($original && $original !== $slug && isset($cards[$original])) { unset($cards[$original]); }
        $cards[$slug] = array('name' => $name, 'slug' => $slug, 'template_url' => $template, 'download_file_name' => $download, 'updated' => time());
        $settings['cards'] = $cards;
        update_option(self::OPTION_SETTINGS, $settings, false);
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=saved&saved=1'));
        exit;
    }

    public function delete_card() {
        if (!current_user_can('edit_posts')) { wp_die('You do not have permission to delete social cards.'); }
        check_admin_referer('stagecard_social_card_delete');
        $slug = isset($_POST['card_slug']) ? $this->normalize_slug(wp_unslash($_POST['card_slug'])) : '';
        $settings = $this->settings();
        $cards = $this->cards();
        if ($slug && isset($cards[$slug])) { unset($cards[$slug]); }
        $settings['cards'] = $cards;
        update_option(self::OPTION_SETTINGS, $settings, false);
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=saved&deleted=1'));
        exit;
    }

    public function admin_page() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'create';
        $tab = $tab === 'saved' ? 'saved' : 'create';
        $edit_slug = isset($_GET['edit']) ? $this->normalize_slug(wp_unslash($_GET['edit'])) : '';
        if ($edit_slug) { $tab = 'create'; }
        $cards = $this->cards();
        $editing = $edit_slug && isset($cards[$edit_slug]) ? $cards[$edit_slug] : array();
        $form_slug = $editing ? $edit_slug : $this->next_slug('stagecard');
        $form_name = $editing ? ($editing['name'] ?? ($editing['label'] ?? '')) : '';
        $form_template = $editing ? ($editing['template_url'] ?? '') : '';
        $form_download = $editing ? $this->download_name($editing) : '';
        ?>
        <div class="wrap sccc-wrap">
            <h1>Social Card Creator</h1>
            <nav class="sccc-tabs" aria-label="Social Card Creator sections">
                <a class="sccc-tab <?php echo $tab === 'create' ? 'is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">Create Social Card</a>
                <a class="sccc-tab <?php echo $tab === 'saved' ? 'is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=saved')); ?>">Saved Social Cards</a>
            </nav>
            <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Social card saved.</p></div><?php endif; ?>
            <?php if (!empty($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>Social card deleted.</p></div><?php endif; ?>
            <?php if ($tab === 'saved') : $this->saved_cards_table($cards); else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sccc-card">
                    <?php wp_nonce_field('stagecard_social_card_save'); ?>
                    <input type="hidden" name="action" value="stagecard_social_card_save">
                    <input type="hidden" name="original_slug" value="<?php echo esc_attr($edit_slug); ?>">
                    <h2><?php echo $editing ? 'Edit Social Card' : 'Create Social Card'; ?></h2>
                    <div class="sccc-grid"><label for="sccc-template">Upload Social Card Graphic</label><div><div class="sccc-row"><input id="sccc-template" class="regular-text sccc-template-url" type="url" name="template_url" value="<?php echo esc_attr($form_template); ?>" placeholder="Choose a transparent PNG"><button type="button" class="button sccc-template-upload">Choose from Media Library</button></div><p class="sccc-help">The plugin detects the transparent section and places the user photo there.</p><img class="sccc-preview-img" src="<?php echo esc_url($form_template); ?>" alt="Social card graphic preview" <?php echo $form_template ? '' : 'style="display:none;"'; ?>></div></div>
                    <div class="sccc-grid"><label for="sccc-name">Name Social Card</label><div><input id="sccc-name" class="regular-text" type="text" name="card_name" value="<?php echo esc_attr($form_name); ?>" placeholder="Example Social Card"></div></div>
                    <div class="sccc-grid"><label for="sccc-download">Name File Download</label><div><input id="sccc-download" class="regular-text" type="text" name="download_file_name" value="<?php echo esc_attr($form_download); ?>" placeholder="example.png"></div></div>
                    <div class="sccc-grid"><label for="sccc-shortcode-name">Name Shortcode</label><div><input id="sccc-shortcode-name" class="regular-text" type="text" name="card_slug" value="<?php echo esc_attr($form_slug); ?>" placeholder="example_social_card"><p class="sccc-help">Use letters, numbers, hyphens, or underscores. The saved shortcode will be <?php echo esc_html($this->shortcode_for_slug($form_slug)); ?>.</p></div></div>
                    <p><button type="submit" class="button button-primary">Save Social Card</button></p>
                </form>
                <div class="sccc-preview-box"><h2>Preview Graphic</h2><?php echo $form_template ? $this->builder_markup($form_template, $form_download ?: ($form_slug . '.png')) : '<div class="sccc-empty">Upload a social card graphic to preview the image tools.</div>'; ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function saved_cards_table($cards) {
        if (!$cards) { echo '<div class="sccc-empty">No saved social cards yet.</div>'; return; }
        echo '<table class="sccc-table"><thead><tr><th>Name</th><th>Shortcode</th><th>Download Name</th><th>Actions</th></tr></thead><tbody>';
        foreach ($cards as $slug => $card) {
            $slug = $this->normalize_slug($slug);
            $edit_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&edit=' . rawurlencode($slug));
            echo '<tr><td>' . esc_html($card['name'] ?? ucwords(str_replace('_', ' ', $slug))) . '</td><td><input class="sccc-shortcode" readonly value="' . esc_attr($this->shortcode_for_slug($slug)) . '" onclick="this.select();"></td><td>' . esc_html($this->download_name($card)) . '</td><td><div class="sccc-actions"><a class="button" href="' . esc_url($edit_url) . '">Edit</a><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this social card?\');">';
            wp_nonce_field('stagecard_social_card_delete');
            echo '<input type="hidden" name="action" value="stagecard_social_card_delete"><input type="hidden" name="card_slug" value="' . esc_attr($slug) . '"><button type="submit" class="button button-link-delete">Delete</button></form></div></td></tr>';
        }
        echo '</tbody></table>';
    }

    public function render_shortcode($atts = array(), $content = null, $tag = '') {
        $atts = shortcode_atts(array('card' => '', 'template' => '', 'download' => ''), $atts, $tag);
        $card = array();
        if (!empty($atts['card'])) { $card = $this->card_for_slug($atts['card']); }
        if (!$card && $tag && substr($tag, -12) === '_social_card') { $card = $this->card_for_slug(substr($tag, 0, -12)); }
        if (!$card) { $first = $this->first_card_slug(); $card = $first ? $this->card_for_slug($first) : array(); }
        $template_url = !empty($atts['template']) ? esc_url_raw($atts['template']) : (!empty($card['template_url']) ? $card['template_url'] : '');
        if (!$template_url) { return '<p>Social card template missing.</p>'; }
        $download_name = !empty($atts['download']) ? sanitize_file_name($atts['download']) : $this->download_name($card);
        $this->register_assets();
        wp_enqueue_style('stagecard-social-card-builder');
        wp_enqueue_script('stagecard-social-card-builder');
        wp_localize_script('stagecard-social-card-builder', 'DHKCSocialCardBuilder', array('templateUrl' => esc_url_raw($template_url), 'downloadFileName' => $download_name));
        return $this->builder_markup($template_url, $download_name);
    }

    private function builder_markup($template_url, $download_name) {
        ob_start();
        ?>
        <div class="dhkc-card-builder" data-dhkc-card-builder data-template-url="<?php echo esc_url($template_url); ?>" data-download-file-name="<?php echo esc_attr($download_name); ?>">
            <div class="dhkc-card-builder__controls" aria-label="Photo adjustment controls"><div class="dhkc-card-builder__control-grid"><label class="dhkc-card-builder__upload"><span class="dhkc-card-builder__file-wrap"><input class="dhkc-card-builder__file" type="file" accept="image/png,image/jpeg,image/webp"><span class="dhkc-card-builder__file-button" aria-hidden="true">Upload image</span><span class="dhkc-card-builder__file-name" data-dhkc-file-name aria-live="polite">No file chosen</span></span></label><div class="dhkc-card-builder__adjust-row"><div class="dhkc-card-builder__adjust-title">Adjust Image</div><label class="dhkc-card-builder__range"><span class="dhkc-card-builder__range-label">Size</span><span class="dhkc-card-builder__size-buttons"><button type="button" class="dhkc-card-builder__zoom-button" data-dhkc-zoom="out" disabled aria-label="Make image smaller">−</button><button type="button" class="dhkc-card-builder__zoom-button" data-dhkc-zoom="in" disabled aria-label="Make image larger">+</button></span><input class="dhkc-card-builder__zoom" type="hidden" value="1" disabled></label><div class="dhkc-card-builder__move" aria-label="Move image"><span class="dhkc-card-builder__location-label">Location</span><span class="dhkc-card-builder__move-grid"><button type="button" class="dhkc-card-builder__move-button dhkc-card-builder__move-button--up" data-dhkc-move="up" disabled aria-label="Move image up">↑</button><button type="button" class="dhkc-card-builder__move-button dhkc-card-builder__move-button--left" data-dhkc-move="left" disabled aria-label="Move image left">←</button><button type="button" class="dhkc-card-builder__move-button dhkc-card-builder__move-button--right" data-dhkc-move="right" disabled aria-label="Move image right">→</button><button type="button" class="dhkc-card-builder__move-button dhkc-card-builder__move-button--down" data-dhkc-move="down" disabled aria-label="Move image down">↓</button></span></div></div></div><div class="dhkc-card-builder__actions"><button type="button" class="dhkc-card-builder__button dhkc-card-builder__button--secondary" data-dhkc-reset disabled>Reset image</button><button type="button" class="dhkc-card-builder__button" data-dhkc-download disabled>Download PNG</button></div></div>
            <div class="dhkc-card-builder__editor"><canvas class="dhkc-card-builder__canvas" width="1201" height="1201" aria-label="Social card preview"></canvas></div>
        </div>
        <?php
        return ob_get_clean();
    }
    public function check_github_plugin_update($transient) { if (empty($transient) || !is_object($transient)) { return $transient; } $plugin_file = plugin_basename(__FILE__); if (empty($transient->checked)) { $transient->checked = array(); } if (empty($transient->checked[$plugin_file])) { $transient->checked[$plugin_file] = self::VERSION; } if (empty($transient->response) || !is_array($transient->response)) { $transient->response = array(); } if (empty($transient->no_update) || !is_array($transient->no_update)) { $transient->no_update = array(); } $release = $this->github_latest_release(); if (!$release || empty($release['version']) || empty($release['download_url'])) { return $transient; } if (!version_compare($release['version'], self::VERSION, '>')) { $transient->no_update[$plugin_file] = (object) array('id'=>self::GITHUB_REPO,'slug'=>dirname($plugin_file),'plugin'=>$plugin_file,'new_version'=>self::VERSION,'url'=>$release['html_url'],'package'=>''); unset($transient->response[$plugin_file]); return $transient; } unset($transient->no_update[$plugin_file]); $transient->response[$plugin_file] = (object) array('id'=>self::GITHUB_REPO,'slug'=>dirname($plugin_file),'plugin'=>$plugin_file,'new_version'=>$release['version'],'url'=>$release['html_url'],'package'=>$release['download_url'],'tested'=>$release['tested'],'requires'=>$release['requires'],'requires_php'=>$release['requires_php']); return $transient; }
    public function github_plugin_info($result, $action, $args) { if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(plugin_basename(__FILE__))) { return $result; } $release = $this->github_latest_release(false); if (!$release) { return $result; } return (object) array('name'=>'Stagecard Social Card Creator','slug'=>dirname(plugin_basename(__FILE__)),'version'=>$release['version'],'author'=>'<a href="https://oliviakohring.com/">Olivia Kohring</a>','homepage'=>$release['html_url'],'download_link'=>$release['download_url'],'requires'=>$release['requires'],'tested'=>$release['tested'],'requires_php'=>$release['requires_php'],'last_updated'=>$release['published_at'],'sections'=>array('description'=>'Stagecard Social Card Creator lets public visitors upload, position, and export a photo inside event-specific social card graphics.','changelog'=>$release['body'] ? wp_kses_post(wpautop($release['body'])) : 'See the GitHub release notes.')); }
    private function github_latest_release($use_cache = true) { $cache_key = 'stagecard_social_card_github_release'; if ($use_cache) { $cached = get_site_transient($cache_key); if (is_array($cached)) { return $cached; } } $response = wp_remote_get('https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest', array('timeout'=>12,'headers'=>array('Accept'=>'application/vnd.github+json','User-Agent'=>'Stagecard-Social-Card-Creator/' . self::VERSION . '; ' . home_url('/')))); if (is_wp_error($response)) { return false; } $code = wp_remote_retrieve_response_code($response); if ($code < 200 || $code >= 300) { return false; } $data = json_decode(wp_remote_retrieve_body($response), true); if (!is_array($data) || empty($data['tag_name'])) { return false; } $version = ltrim((string) $data['tag_name'], 'vV'); $download_url = ''; if (!empty($data['assets']) && is_array($data['assets'])) { foreach ($data['assets'] as $asset) { $name = isset($asset['name']) ? strtolower((string) $asset['name']) : ''; if ($name && substr($name, -4) === '.zip' && !empty($asset['browser_download_url'])) { $download_url = esc_url_raw($asset['browser_download_url']); break; } } } if (!$download_url && $version) { $download_url = esc_url_raw('https://github.com/' . self::GITHUB_REPO . '/releases/download/' . rawurlencode((string) $data['tag_name']) . '/stagecard-social-card-builder-v' . str_replace('.', '-', $version) . '.zip'); } if (!$download_url) { return false; } $release = array('version'=>$version,'html_url'=>!empty($data['html_url']) ? esc_url_raw($data['html_url']) : 'https://github.com/' . self::GITHUB_REPO,'download_url'=>$download_url,'published_at'=>!empty($data['published_at']) ? sanitize_text_field($data['published_at']) : '','body'=>!empty($data['body']) ? wp_kses_post(wpautop($data['body'])) : '','requires'=>'5.8','tested'=>'6.8','requires_php'=>'7.4'); set_site_transient($cache_key, $release, 5 * MINUTE_IN_SECONDS); return $release; }
    public function maybe_clear_github_update_cache() { if (!empty($_GET['force-check']) || (!empty($_GET['action']) && $_GET['action'] === 'upgrade-plugin')) { delete_site_transient('stagecard_social_card_github_release'); } }
}
new Stagecard_Social_Card_Creator();
