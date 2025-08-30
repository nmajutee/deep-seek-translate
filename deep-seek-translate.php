<?php
/**
 * Plugin Name: DeepSeek Translate
 * Description: Advanced WordPress translation plugin v1.2.0 with AI-powered auto-translation (OpenAI/DeepSeek), SEO optimization (hreflang, canonicals), language switcher with flags, caching, and support for 26 languages.
 * Version: 1.2.0
 * Author: Nmaju Terence
 * License: GPL2+
 * Requires at least: 5.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

class DST_DeepSeek_Translate {
    const OPTION_KEY = 'dst_settings';
    const META_PREFIX = '_dst_';
    const NONCE = 'dst_admin_nonce';

    private $settings = [];

    public function __construct() {
        $defaults = [
            'api_key'         => '',
            'api_base'        => 'https://api.openai.com/v1',
            'api_model'       => 'gpt-4o-mini',
            'default_lang'    => 'en',
            'enabled_langs'   => $this->eu_languages_codes(),
            'url_mode'        => 'subdir',
            'auto_translate'  => ['post','page'],
            'translate_title' => 1,
            'translate_textdomain_strings' => 0,
            'translate_meta' => 0,
            'debug_mode' => 0,
            'disable_api' => 0, // temporarily disable API calls for testing
            'translate_in_background' => 1, // schedule translations via WP-Cron instead of blocking requests
            'cache_ttl'       => 0,
        ];
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) $saved = [];
        $this->settings = wp_parse_args($saved, $defaults);

        // Admin
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'admin_notices']);
        
        // Cron & background
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action('dst_process_translation_queue', [$this, 'process_translation_queue']);

        // Frontend: URL language handling
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('request', [$this, 'parse_request_lang']);
        add_filter('home_url', [$this, 'filter_home_url'], 10, 4);

        // Translation filters
        add_filter('the_content', [$this, 'filter_content']);
        if (!empty($this->settings['translate_title'])) {
            add_filter('the_title', [$this, 'filter_title'], 10, 2);
        }
        if (!empty($this->settings['translate_textdomain_strings'])) {
            add_filter('gettext', [$this, 'filter_gettext'], 10, 3);
        }

        // Additional auto-translate filters
        add_filter('widget_text', [$this, 'filter_widget_text']);
        add_filter('wp_nav_menu_items', [$this, 'filter_nav_menu_items']);

        // SEO enhancements
        add_filter('wpseo_canonical', [$this, 'filter_canonical_url']);
        add_filter('wpseo_robots', [$this, 'filter_robots_meta']);
        add_filter('wp_unique_post_slug', [$this, 'filter_post_slug'], 10, 6);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'filter_sitemap_query'], 10, 2);
        add_action('wp_sitemaps_init', [$this, 'add_language_sitemaps']);
        add_action('save_post', [$this, 'translate_post_slug_on_save']);

        // Meta fields (Yoast SEO support)
        if (!empty($this->settings['translate_meta'])) {
            add_filter('wpseo_title', [$this, 'filter_wpseo_title'], 10, 2);
            add_filter('wpseo_metadesc', [$this, 'filter_wpseo_metadesc'], 10, 2);
            add_filter('wpseo_focuskw', [$this, 'filter_wpseo_focuskw'], 10, 2);
        }

        // Hreflang
        add_action('wp_head', [$this, 'output_hreflang'], 2);

        // Activation/Deactivation
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);

        // Shortcode + block-like simple switcher
        add_shortcode('deepseek_translate_switcher', [$this, 'switcher_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /* ----------------------------- Languages ------------------------------ */
    public function eu_languages() {
        // List of 24 official EU languages plus Russian and Ukrainian
        return [
            'bg' => 'Bulgarian',
            'cs' => 'Czech',
            'da' => 'Danish',
            'de' => 'German',
            'el' => 'Greek',
            'en' => 'English',
            'es' => 'Spanish',
            'et' => 'Estonian',
            'fi' => 'Finnish',
            'fr' => 'French',
            'ga' => 'Irish',
            'hr' => 'Croatian',
            'hu' => 'Hungarian',
            'it' => 'Italian',
            'lt' => 'Lithuanian',
            'lv' => 'Latvian',
            'mt' => 'Maltese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'pt' => 'Portuguese',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'sk' => 'Slovak',
            'sl' => 'Slovene',
            'sv' => 'Swedish',
            'uk' => 'Ukrainian',
        ];
    }
    public function eu_languages_codes() { return array_keys($this->eu_languages()); }

    /* ----------------------------- Activation ----------------------------- */
    public static function activate() {
        $self = new self; // to access methods
        $self->add_rewrite_rules();
        flush_rewrite_rules();
    }
    public static function deactivate() { flush_rewrite_rules(); }

    /* --------------------------- Settings (Admin) -------------------------- */
    public function register_settings_page() {
        add_options_page(
            'DeepSeek Translate',
            'DeepSeek Translate',
            'manage_options',
            'deepseek-translate',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [$this, 'sanitize_settings']);

        // Handle cache clear
        if (isset($_GET['action']) && $_GET['action'] === 'clear_cache' && wp_verify_nonce($_GET['_wpnonce'], 'clear_cache')) {
            $this->clear_translation_cache();
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Translation cache cleared successfully.</p></div>';
            });
        }
        // Ensure cron hook is registered
        if (!wp_next_scheduled('dst_process_translation_queue')) {
            wp_schedule_event(time() + 10, 'dst_minute', 'dst_process_translation_queue');
        }
    }

    public function add_cron_schedules($schedules) {
        if (!isset($schedules['dst_minute'])) {
            $schedules['dst_minute'] = ['interval' => 60, 'display' => __('Every Minute')];
        }
        return $schedules;
    }

    /**
     * Process queued translations (WP-Cron handler).
     * Runs periodically and processes a small batch to avoid long execution.
     */
    public function process_translation_queue() {
        // Safety: Ensure settings are loaded
        if (empty($this->settings) || !is_array($this->settings)) {
            return;
        }
        
        $queue = get_option('dst_translation_queue', []);
        if (empty($queue) || !is_array($queue)) return;
        
        $batch = array_splice($queue, 0, 6); // process up to 6 items per run
        foreach ($batch as $job) {
            // Safety: Check job structure
            if (!is_array($job)) continue;
            
            $cache_key = $job['cache_key'] ?? '';
            $text = $job['text'] ?? '';
            $source = $job['source'] ?? $this->settings['default_lang'];
            $target = $job['target'] ?? $this->settings['default_lang'];
            $store = $job['store'] ?? 'transient';
            $post_id = $job['post_id'] ?? null;
            $meta_key = $job['meta_key'] ?? null;

            // Skip empty jobs
            if (empty($cache_key) || empty($text)) continue;

            $translated = $this->translate_via_api($text, $source, $target);
            if ($translated && $translated !== $text) {
                if ($store === 'post_meta' && $post_id && $meta_key) {
                    update_post_meta($post_id, $meta_key, sanitize_text_field($translated));
                }
                // Always set transient cache as well
                $ttl = intval($this->settings['cache_ttl']);
                if ($ttl === 0) $ttl = YEAR_IN_SECONDS * 5;
                set_transient($cache_key, $translated, $ttl);
            }
        }
        // Save remaining queue
        update_option('dst_translation_queue', $queue);
    }

    public function admin_notices() {
        if (empty($this->settings['api_key'])) {
            echo '<div class="notice notice-error"><p><strong>DeepSeek Translate:</strong> API key is required. Please enter it in the <a href="' . esc_url(admin_url('options-general.php?page=deepseek-translate')) . '">settings</a>.</p></div>';
        }
        $last_error = get_transient('dst_api_error');
        if ($last_error) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>DeepSeek Translate:</strong> API error: ' . esc_html($last_error) . '</p></div>';
            delete_transient('dst_api_error');
        }
    }

    private function clear_translation_cache() {
        global $wpdb;
        // Delete transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dst_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dst_%'");
        // Delete post meta
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_dst_%'");
    }

    public function sanitize_settings($input) {
        $out = [];
        $out['api_key']  = isset($input['api_key']) ? trim($input['api_key']) : '';
        $out['api_base'] = isset($input['api_base']) ? esc_url_raw($input['api_base']) : 'https://api.openai.com/v1';
        $out['api_model'] = isset($input['api_model']) ? sanitize_text_field($input['api_model']) : 'gpt-4o-mini';
        $langs = $this->eu_languages_codes();
        $out['default_lang'] = in_array($input['default_lang'] ?? 'en', $langs, true) ? $input['default_lang'] : 'en';
        $enabled = array_values(array_intersect($langs, (array)($input['enabled_langs'] ?? [])));
        $out['enabled_langs'] = $enabled ?: $langs;
        $out['url_mode'] = 'subdir'; // MVP fixed
        $out['auto_translate'] = array_map('sanitize_text_field', (array)($input['auto_translate'] ?? ['post','page']));
        $out['translate_title'] = !empty($input['translate_title']) ? 1 : 0;
        $out['translate_textdomain_strings'] = !empty($input['translate_textdomain_strings']) ? 1 : 0;
        $out['translate_meta'] = !empty($input['translate_meta']) ? 1 : 0;
        $out['debug_mode'] = !empty($input['debug_mode']) ? 1 : 0;
        $out['disable_api'] = !empty($input['disable_api']) ? 1 : 0;
        $out['translate_in_background'] = !empty($input['translate_in_background']) ? 1 : 0;
        $out['cache_ttl'] = isset($input['cache_ttl']) ? max(0, intval($input['cache_ttl'])) : 0;
        return $out;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $opts = $this->settings; $langs = $this->eu_languages();
        ?>
        <div class="wrap">
            <h1>DeepSeek Translate – Settings (MVP)</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[url_mode]" value="subdir" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>DeepSeek API Key</label></th>
                        <td>
                            <input type="password" style="width: 420px;" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]" value="<?php echo esc_attr($opts['api_key']); ?>" />
                            <p class="description">Create a free API key in your DeepSeek account and paste it here.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>API Base URL</label></th>
                        <td>
                            <input type="url" style="width: 420px;" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_base]" value="<?php echo esc_attr($opts['api_base']); ?>" />
                            <p class="description">Default: https://api.openai.com/v1 (for OpenAI API)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Model</label></th>
                        <td>
                            <input type="text" style="width: 220px;" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_model]" value="<?php echo esc_attr($opts['api_model']); ?>" />
                            <p class="description">Example: gpt-4o-mini (OpenAI model)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Default Language</label></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_lang]">
                                <?php foreach ($langs as $code=>$label): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($opts['default_lang'], $code); ?>><?php echo esc_html($label . " (".$code.")"); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Enabled Languages</label></th>
                        <td>
                            <fieldset>
                                <?php foreach ($langs as $code=>$label): ?>
                                    <label style="display:inline-block;min-width:180px;margin:3px 0;">
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled_langs][]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, (array)$opts['enabled_langs'], true)); ?> />
                                        <?php echo esc_html($label . " (".$code.")"); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Auto-translate Post Types</label></th>
                        <td>
                            <?php foreach (get_post_types(['public'=>true],'objects') as $pt): ?>
                                <label style="display:inline-block;min-width:180px;margin:3px 0;">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_translate][]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, (array)$opts['auto_translate'], true)); ?> />
                                    <?php echo esc_html($pt->labels->singular_name . ' ('.$pt->name.')'); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Translate Titles</label></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[translate_title]" value="1" <?php checked(!empty($opts['translate_title'])); ?> /> Enable</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Translate Theme/Plugin Strings (experimental)</label></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[translate_textdomain_strings]" value="1" <?php checked(!empty($opts['translate_textdomain_strings'])); ?> /> Enable via <code>gettext</code> filter</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Translate Meta Fields (Yoast SEO)</label></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[translate_meta]" value="1" <?php checked(!empty($opts['translate_meta'])); ?> /> Enable translation of title, description, and focus keyword</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Debug Mode</label></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[debug_mode]" value="1" <?php checked(!empty($opts['debug_mode'])); ?> /> Enable detailed error logging</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Disable API Calls</label></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[disable_api]" value="1" <?php checked(!empty($opts['disable_api'])); ?> /> Temporarily disable API calls (for testing)</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Translate in Background</label></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[translate_in_background]" value="1" <?php checked(!empty($opts['translate_in_background'])); ?> /> Process translations via WP-Cron to avoid blocking page requests (recommended for shared hosting)</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Cache TTL (seconds)</label></th>
                        <td>
                            <input type="number" min="0" step="60" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cache_ttl]" value="<?php echo esc_attr($opts['cache_ttl']); ?>" />
                            <p class="description">0 = never expire. Translations are stored as post meta and reused.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h3>Cache Management</h3>
            <p><a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=deepseek-translate&action=clear_cache'), 'clear_cache'); ?>" class="button">Clear Translation Cache</a></p>
            <p class="description">This will delete all cached translations from transients and post meta. Use if you need to force re-translation.</p>
        </div>
        <?php
    }

    /* ---------------------------- Rewrite rules --------------------------- */
    public function add_rewrite_rules() {
        if ($this->settings['url_mode'] !== 'subdir') return;
        $langs = $this->settings['enabled_langs'];
        if (empty($langs)) return;
        $lang_regex = '(' . implode('|', array_map('preg_quote', $langs)) . ')';
        add_rewrite_tag('%dst_lang%', $lang_regex);
        add_rewrite_rule('^' . $lang_regex . '/?$', 'index.php?dst_lang=$matches[1]', 'top');
        add_rewrite_rule('^' . $lang_regex . '/(.+)$', 'index.php?dst_lang=$matches[1]&pagename=$matches[2]', 'top');
    }

    public function parse_request_lang($vars) {
        if (isset($vars['dst_lang']) && $vars['dst_lang']) {
            $this->set_current_lang($vars['dst_lang']);
        } else {
            // From URL path
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
            $maybe = strtok($path, '/');
            if ($maybe && in_array($maybe, (array)$this->settings['enabled_langs'], true)) {
                $this->set_current_lang($maybe);
                $vars['dst_lang'] = $maybe;
            } else {
                $this->set_current_lang($this->settings['default_lang']);
            }
        }
        return $vars;
    }

    private function set_current_lang($lang) {
        $langs = (array)$this->settings['enabled_langs'];
        $default = $this->settings['default_lang'];
        
        // Ensure the lang is in enabled languages, otherwise use default
        if (!in_array($lang, $langs, true)) {
            $lang = $default;
        }
        
        // Safety: If default lang is not in enabled langs, use first enabled lang
        if (!in_array($lang, $langs, true) && !empty($langs)) {
            $lang = $langs[0];
        }
        
        $GLOBALS['dst_current_lang'] = $lang;
    }

    public static function get_current_lang() {
        return $GLOBALS['dst_current_lang'] ?? null;
    }

    public function filter_home_url($url, $path, $orig_scheme, $blog_id) {
        $lang = self::get_current_lang();
        $def  = $this->settings['default_lang'];
        if (!$lang || $lang === $def) return $url;
        // Prepend /{lang}/ for non-default language
        $home = home_url('/');
        if (strpos($url, $home) === 0) {
            $rest = substr($url, strlen($home));
            $url = trailingslashit($home . $lang . '/' . ltrim($rest, '/'));
        }
        return $url;
    }

    /* ---------------------------- Translation API ---------------------------- */
    private function translate_via_api($text, $source_lang, $target_lang, $purpose = 'web_content') {
        if (!empty($this->settings['disable_api'])) {
            if (!empty($this->settings['debug_mode'])) error_log('DeepSeek Translate: API calls disabled for testing.');
            return $text; // Skip API call
        }
        $api_key  = $this->settings['api_key'];
        $api_base = rtrim($this->settings['api_base'], '/');
        $model    = $this->settings['api_model'];
        if (!$api_key || !$model) {
            $error = 'API key or model not configured.';
            set_transient('dst_api_error', $error, 300); // 5 min
            if (!empty($this->settings['debug_mode'])) {
                error_log('DeepSeek Translate API Error: ' . $error);
            }
            return $text; // fail open
        }

        // Prompt: steer model to translate only
        $system = sprintf('You are a professional website translator. Translate from %s to %s. Keep HTML tags and markdown structure intact. Do not add commentary.', strtoupper($source_lang), strtoupper($target_lang));

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => trim((string)$text)],
            ],
            'temperature' => 0.2,
        ];

        $resp = wp_remote_post($api_base . '/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 15, // Reduced timeout to 15 seconds
        ]);

        if (is_wp_error($resp)) {
            $error = 'API request failed: ' . $resp->get_error_message();
            set_transient('dst_api_error', $error, 300);
            if (!empty($this->settings['debug_mode'])) {
                error_log('DeepSeek Translate API Error: ' . $error);
            }
            return $text;
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        if ($code !== 200 || !is_array($json) || !isset($json['choices'][0]['message']['content'])) {
            $error_msg = is_array($json) && isset($json['error']['message']) ? $json['error']['message'] : 'Unknown error';
            $error = 'API response error: HTTP ' . $code . ' - ' . $error_msg;
            set_transient('dst_api_error', $error, 300);
            if (!empty($this->settings['debug_mode'])) {
                error_log('DeepSeek Translate API Error: ' . $error . ' | Response: ' . substr($body, 0, 500));
            }
            return $text;
        }
        return (string)$json['choices'][0]['message']['content'];
    }

    /**
     * Try to return a cached translation, otherwise either perform translation
     * synchronously or enqueue a background job depending on settings.
     *
     * @param string $cache_key
     * @param string $text
     * @param string $source_lang
     * @param string $target_lang
     * @param array  $store_info Optional: ['store'=>'post_meta'|'transient','post_id'=>int,'meta_key'=>string]
     * @return string
     */
    private function maybe_translate($cache_key, $text, $source_lang, $target_lang, $store_info = []) {
        if (!$text) return $text;
        if ($target_lang === $source_lang) return $text;
        
        // Safety: Ensure settings are loaded
        if (empty($this->settings) || !is_array($this->settings)) {
            return $text;
        }

        $cached = get_transient($cache_key);
        if ($cached !== false) return (string)$cached;

        // If configured to translate in background, enqueue and return original text.
        if (!empty($this->settings['translate_in_background'])) {
            try {
                $queue = get_option('dst_translation_queue', []);
                if (!is_array($queue)) $queue = [];
                
                $job = [
                    'cache_key' => $cache_key,
                    'text'      => $text,
                    'source'    => $source_lang,
                    'target'    => $target_lang,
                    'store'     => $store_info['store'] ?? 'transient',
                    'post_id'   => $store_info['post_id'] ?? null,
                    'meta_key'  => $store_info['meta_key'] ?? null,
                ];
                $queue[] = $job;
                update_option('dst_translation_queue', $queue);
                
                if (!empty($this->settings['debug_mode'])) {
                    error_log('DeepSeek Translate: Enqueued translation job for ' . substr($cache_key, 0, 40));
                }
            } catch (Exception $e) {
                // Fallback to synchronous if queueing fails
                if (!empty($this->settings['debug_mode'])) {
                    error_log('DeepSeek Translate: Queue failed, falling back to sync: ' . $e->getMessage());
                }
            }
            return $text; // return original until background worker completes
        }

        // Synchronous path
        $translated = $this->translate_via_api($text, $source_lang, $target_lang);

        $ttl = intval($this->settings['cache_ttl']);
        if ($ttl === 0) $ttl = YEAR_IN_SECONDS * 5; // treat as long-term cache in transient table
        set_transient($cache_key, $translated, $ttl);
        return $translated;
    }

    /* -------------------------- Content Translation ------------------------- */
    public function filter_content($content) {
        if (is_admin()) return $content;
        $post = get_post();
        if (!$post) return $content;
        if (!in_array($post->post_type, (array)$this->settings['auto_translate'], true)) return $content;

        $target = self::get_current_lang() ?: $this->settings['default_lang'];
        $source = $this->settings['default_lang'];
        if ($target === $source) return $content;

        $meta_key = self::META_PREFIX . $target . '_content_v2';
        $cached   = get_post_meta($post->ID, $meta_key, true);
        if ($cached) return $cached;

        // Cache key also includes post_modified to bust when updated
        $cache_key = 'dst_ct_' . md5($post->ID . '|' . $post->post_modified_gmt . '|' . $target);
        $store_info = ['store' => 'post_meta', 'post_id' => $post->ID, 'meta_key' => $meta_key];
        $translated = $this->maybe_translate($cache_key, $content, $source, $target, $store_info);

        if (empty($this->settings['translate_in_background'])) {
            if ($translated && $translated !== $content) {
                update_post_meta($post->ID, $meta_key, wp_kses_post($translated));
            }
        }
        return $translated ?: $content;
    }

    public function filter_title($title, $post_id) {
        if (is_admin()) return $title;
        $post = get_post($post_id);
        if (!$post) return $title;
        if (!in_array($post->post_type, (array)$this->settings['auto_translate'], true)) return $title;

        $target = self::get_current_lang() ?: $this->settings['default_lang'];
        $source = $this->settings['default_lang'];
        if ($target === $source) return $title;

        $meta_key = self::META_PREFIX . $target . '_title_v2';
        $cached   = get_post_meta($post->ID, $meta_key, true);
        if ($cached) return $cached;

        $cache_key = 'dst_tt_' . md5($post->ID . '|' . $post->post_modified_gmt . '|' . $target);
        $store_info = ['store' => 'post_meta', 'post_id' => $post->ID, 'meta_key' => $meta_key];
        $translated = $this->maybe_translate($cache_key, $title, $source, $target, $store_info);
        if (empty($this->settings['translate_in_background'])) {
            if ($translated && $translated !== $title) {
                update_post_meta($post->ID, $meta_key, sanitize_text_field($translated));
            }
        }
        return $translated ?: $title;
    }

    public function filter_gettext($translation, $text, $domain) {
        if (is_admin()) return $translation;
        $target = self::get_current_lang() ?: $this->settings['default_lang'];
        $source = $this->settings['default_lang'];
        if ($target === $source) return $translation;
        $cache_key = 'dst_gt_' . md5($domain . '|' . $text . '|' . $target);
        return $this->maybe_translate($cache_key, $translation ?: $text, $source, $target);
    }

    public function filter_wpseo_title($title, $presentation) {
        if (is_admin() || !$presentation || !isset($presentation->model) || !$presentation->model instanceof WP_Post) return $title;
        $post = $presentation->model;
        if (!in_array($post->post_type, (array)$this->settings['auto_translate'], true)) return $title;

        $target = self::get_current_lang() ?: $this->settings['default_lang'];
        $source = $this->settings['default_lang'];
        if ($target === $source) return $title;

        $meta_key = self::META_PREFIX . $target . '_yoast_title_v2';
        $cached = get_post_meta($post->ID, $meta_key, true);
        if ($cached) return $cached;

        $cache_key = 'dst_yt_' . md5($post->ID . '|' . $post->post_modified_gmt . '|' . $target);
        $store_info = ['store' => 'post_meta', 'post_id' => $post->ID, 'meta_key' => $meta_key];
        $translated = $this->maybe_translate($cache_key, $title, $source, $target, $store_info);
        if (empty($this->settings['translate_in_background'])) {
            if ($translated && $translated !== $title) {
                update_post_meta($post->ID, $meta_key, sanitize_text_field($translated));
            }
        }
        return $translated ?: $title;
    }

    public function filter_wpseo_metadesc($desc, $presentation) {
        if (is_admin() || !$presentation || !isset($presentation->model) || !$presentation->model instanceof WP_Post) return $desc;
        $post = $presentation->model;
        if (!in_array($post->post_type, (array)$this->settings['auto_translate'], true)) return $desc;

        $target = self::get_current_lang() ?: $this->settings['default_lang'];
        $source = $this->settings['default_lang'];
        if ($target === $source) return $desc;

        $meta_key = self::META_PREFIX . $target . '_yoast_metadesc_v2';
        $cached = get_post_meta($post->ID, $meta_key, true);
        if ($cached) return $cached;

        $cache_key = 'dst_ymd_' . md5($post->ID . '|' . $post->post_modified_gmt . '|' . $target);
        $store_info = ['store' => 'post_meta', 'post_id' => $post->ID, 'meta_key' => $meta_key];
        $translated = $this->maybe_translate($cache_key, $desc, $source, $target, $store_info);
        if (empty($this->settings['translate_in_background'])) {
            if ($translated && $translated !== $desc) {
                update_post_meta($post->ID, $meta_key, sanitize_text_field($translated));
            }
        }
        return $translated ?: $desc;
    }

    public function filter_wpseo_focuskw($kw, $post_id) {
        if (is_admin()) return $kw;
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, (array)$this->settings['auto_translate'], true)) return $kw;

        $target = self::get_current_lang() ?: $this->settings['default_lang'];
        $source = $this->settings['default_lang'];
        if ($target === $source) return $kw;

        $meta_key = self::META_PREFIX . $target . '_yoast_focuskw_v2';
        $cached = get_post_meta($post->ID, $meta_key, true);
        if ($cached) return $cached;

        $cache_key = 'dst_yfk_' . md5($post->ID . '|' . $post->post_modified_gmt . '|' . $target);
        $store_info = ['store' => 'post_meta', 'post_id' => $post->ID, 'meta_key' => $meta_key];
        $translated = $this->maybe_translate($cache_key, $kw, $source, $target, $store_info);
        if (empty($this->settings['translate_in_background'])) {
            if ($translated && $translated !== $kw) {
                update_post_meta($post->ID, $meta_key, sanitize_text_field($translated));
            }
        }
        return $translated ?: $kw;
    }

    public function filter_widget_text($text) {
        if (is_admin()) return $text;
        $target = self::get_current_lang() ?: $this->settings['default_lang'];
        $source = $this->settings['default_lang'];
        if ($target === $source) return $text;
    $cache_key = 'dst_wt_' . md5($text . '|' . $target);
    return $this->maybe_translate($cache_key, $text, $source, $target);
    }

    public function filter_nav_menu_items($items) {
        if (is_admin()) return $items;
        $target = self::get_current_lang() ?: $this->settings['default_lang'];
        $source = $this->settings['default_lang'];
        if ($target === $source) return $items;
        foreach ($items as $item) {
            if (!empty($item->title)) {
                $cache_key = 'dst_mi_' . md5($item->ID . '|' . $item->title . '|' . $target);
                $item->title = $this->maybe_translate($cache_key, $item->title, $source, $target);
            }
        }
        return $items;
    }

    // SEO: Canonical URL for translated pages
    public function filter_canonical_url($canonical) {
        $lang = self::get_current_lang();
        $def = $this->settings['default_lang'];
        if (!$lang || $lang === $def) return $canonical;
        // For translated pages, canonical to default lang
        $home = home_url('/');
        if (strpos($canonical, $home . $lang . '/') === 0) {
            $canonical = str_replace($home . $lang . '/', $home, $canonical);
        }
        return $canonical;
    }

    // SEO: Robots meta for translated pages
    public function filter_robots_meta($robots) {
        $lang = self::get_current_lang();
        $def = $this->settings['default_lang'];
        if (!$lang || $lang === $def) return $robots;
        // Add noindex for translated pages to avoid duplicate content
        if (strpos($robots, 'noindex') === false) {
            $robots .= ', noindex';
        }
        return $robots;
    }

    // SEO: Translated post slugs
    public function filter_post_slug($slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug) {
        $lang = self::get_current_lang();
        $def = $this->settings['default_lang'];
        if (!$lang || $lang === $def || !in_array($post_type, (array)$this->settings['auto_translate'])) return $slug;

        $meta_key = self::META_PREFIX . $lang . '_slug_v2';
        $cached = get_post_meta($post_ID, $meta_key, true);
        if ($cached) return $cached;

        $cache_key = 'dst_slug_' . md5($post_ID . '|' . $original_slug . '|' . $lang);
        $store_info = ['store' => 'post_meta', 'post_id' => $post_ID, 'meta_key' => $meta_key];
        $translated = $this->maybe_translate($cache_key, $original_slug, $def, $lang, $store_info);
        $translated_slug = sanitize_title($translated);
        if ($translated_slug && $translated_slug !== $original_slug) {
            if (empty($this->settings['translate_in_background'])) {
                update_post_meta($post_ID, $meta_key, $translated_slug);
                return $translated_slug;
            }
            // When queued, slug will be saved by worker later; return original to avoid collisions now
        }
        return $slug;
    }

    // SEO: Include all languages in sitemaps
    public function filter_sitemap_query($args, $post_type) {
        if (!in_array($post_type, (array)$this->settings['auto_translate'])) return $args;
        // This is a placeholder; actual sitemap inclusion needs more work
        return $args;
    }

    public function add_language_sitemaps($sitemaps) {
        // Placeholder for custom sitemap provider
        // Would need to extend WP_Sitemaps_Provider
    }

    public function translate_post_slug_on_save($post_id) {
        if (wp_is_post_revision($post_id) || !in_array(get_post_type($post_id), (array)$this->settings['auto_translate'])) return;
        $post = get_post($post_id);
        $original_slug = $post->post_name;
        $def = $this->settings['default_lang'];
        foreach ((array)$this->settings['enabled_langs'] as $lang) {
            if ($lang === $def) continue;
            $meta_key = self::META_PREFIX . $lang . '_slug_v2';
            $cache_key = 'dst_slug_' . md5($post_id . '|' . $original_slug . '|' . $lang);
            $store_info = ['store' => 'post_meta', 'post_id' => $post_id, 'meta_key' => $meta_key];
            $translated = $this->maybe_translate($cache_key, $original_slug, $def, $lang, $store_info);
            $translated_slug = sanitize_title($translated);
            if ($translated_slug && $translated_slug !== $original_slug) {
                if (empty($this->settings['translate_in_background'])) {
                    update_post_meta($post_id, $meta_key, $translated_slug);
                }
            }
        }
    }

    /* -------------------------------- SEO ---------------------------------- */
    public function output_hreflang() {
        if (!is_singular() && !is_front_page() && !is_home()) return;
        $langs = (array)$this->settings['enabled_langs'];
        $def   = $this->settings['default_lang'];
        $permalink = get_permalink();
        if (!$permalink) return; // Safety check
        $home = home_url('/');
        foreach ($langs as $lang) {
            if ($lang === $def) {
                $href = $permalink;
            } else {
                // insert /{lang}/ after home
                $href = trailingslashit($home . $lang . '/' . ltrim(str_replace($home, '', $permalink), '/'));
            }
            echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($href) . '" />' . "\n";
        }
    }

    /* --------------------------- Language Switcher -------------------------- */
    public function enqueue_assets() {
        wp_register_style('dst-switcher', plugins_url('switcher.css', __FILE__), [], '0.1.0');
        wp_register_script('dst-switcher', plugins_url('switcher.js', __FILE__), ['jquery'], '0.1.0', true);
    }

    public function switcher_shortcode($atts = []) {
        wp_enqueue_style('dst-switcher');
        wp_enqueue_script('dst-switcher');
        $langs = $this->eu_languages();
        $enabled = array_intersect_key($langs, array_flip((array)$this->settings['enabled_langs']));
        
        // Safety: Return empty if no enabled languages
        if (empty($enabled)) {
            return '<div class="dst-switcher">No languages enabled</div>';
        }
        
        $current = self::get_current_lang() ?: $this->settings['default_lang'];
        $flags = $this->get_flag_emojis();
        $out = '<div class="dst-switcher"><select onchange="if(this.value){window.location.assign(this.value)}">';
        foreach ($enabled as $code => $label) {
            $flag = $flags[$code] ?? '';
            $url = $this->lang_url_for_current($code);
            $sel = $code === $current ? ' selected' : '';
            $out .= '<option value="' . esc_url($url) . '"' . $sel . '>' . esc_html($flag . ' ' . $label) . '</option>';
        }
        $out .= '</select></div>';
        return $out;
    }

    private function get_flag_emojis() {
        return [
            'bg' => '🇧🇬',
            'cs' => '🇨🇿', // Czech
            'da' => '🇩🇰',
            'de' => '🇩🇪',
            'el' => '🇬🇷',
            'en' => '🇬🇧', // Assuming UK for English
            'es' => '🇪🇸',
            'et' => '🇪🇪',
            'fi' => '🇫🇮',
            'fr' => '🇫🇷',
            'ga' => '🇮🇪',
            'hr' => '🇭🇷',
            'hu' => '🇭🇺',
            'it' => '🇮🇹',
            'lt' => '🇱🇹',
            'lv' => '🇱🇻',
            'mt' => '🇲🇹',
            'nl' => '🇳🇱',
            'pl' => '🇵🇱',
            'pt' => '🇵🇹',
            'ro' => '🇷🇴',
            'ru' => '🇷🇺',
            'sk' => '🇸🇰',
            'sl' => '🇸🇮',
            'sv' => '🇸🇪',
            'uk' => '🇺🇦',
        ];
    }

    private function lang_url_for_current($target_lang) {
        $def  = $this->settings['default_lang'];
        $current = self::get_current_lang() ?: $def;
        $url = is_singular() ? get_permalink() : home_url($_SERVER['REQUEST_URI'] ?? '/');

        $home = home_url('/');
        // Normalize
        if ($current !== $def) {
            // strip current prefix
            $prefix = trailingslashit($home . $current);
            if (strpos($url, $prefix) === 0) {
                $url = $home . ltrim(substr($url, strlen($prefix)), '/');
            }
        }
        if ($target_lang === $def) return $url;
        return trailingslashit($home . $target_lang . '/' . ltrim(str_replace($home, '', $url), '/'));
    }
}

new DST_DeepSeek_Translate();

/* ---------------------------- Minimal assets ---------------------------- */
// These two files are optional; plugin works without them. Create if you want custom styles/behavior.
// switcher.css and switcher.js can be added in the same plugin folder.
// switcher.css example:
// .dst-switcher select{padding:6px 10px;border-radius:8px}
// switcher.js example:
// (function($){ /* reserved for enhancements */ })(jQuery);
