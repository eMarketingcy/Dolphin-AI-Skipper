<?php
/**
 * Plugin Name: Dolphin AI Skipper
 * Description: AI-powered sailing advisor. Includes Admin Settings & API Tester.
 * Version: 4.0.0
 * Author: Coding Partner
 */

if (!defined('ABSPATH')) exit;

class DolphinAISkipper {

    public function __construct() {
        // Init & Assets
        add_action('init', [$this, 'register_routes_cpt']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_footer', [$this, 'render_floating_widget']);

        // Admin Menu & Settings
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX Endpoints
        add_action('wp_ajax_nopriv_das_check_sailing', [$this, 'handle_analysis_request']);
        add_action('wp_ajax_das_check_sailing', [$this, 'handle_analysis_request']);
        
        // Admin API Test Endpoint
        add_action('wp_ajax_das_test_apis', [$this, 'handle_admin_api_test']);
    }

    // -------------------------------------------------------------------------
    // 1. ADMIN SETTINGS & MENU
    // -------------------------------------------------------------------------
    public function add_admin_menu() {
        // Add submenu under "Safari Routes"
        add_submenu_page(
            'edit.php?post_type=safari_route',
            'AI Skipper Settings',
            'AI Settings',
            'manage_options',
            'das-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('das_settings_group', 'das_owm_key');
        register_setting('das_settings_group', 'das_gemini_key');
    }

    public function render_settings_page() {
        ?>
        <div class="wrap das-admin-wrapper">
            <h1>🐬 AI Skipper Configuration</h1>
            <p>Enter your API keys below. The "Test Connection" button checks if they are valid in real-time.</p>
            
            <form method="post" action="options.php" class="das-admin-form">
                <?php settings_fields('das_settings_group'); ?>
                <?php do_settings_sections('das_settings_group'); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">OpenWeatherMap API Key</th>
                        <td>
                            <input type="password" id="das_owm_key" name="das_owm_key" value="<?php echo esc_attr(get_option('das_owm_key')); ?>" class="regular-text" />
                            <p class="description">Get your key from <a href="https://openweathermap.org/" target="_blank">openweathermap.org</a></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Google Gemini API Key</th>
                        <td>
                            <input type="password" id="das_gemini_key" name="das_gemini_key" value="<?php echo esc_attr(get_option('das_gemini_key')); ?>" class="regular-text" />
                            <p class="description">Get your key from <a href="https://aistudio.google.com/" target="_blank">Google AI Studio</a></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>

            <div class="das-admin-tester">
                <h3>🧪 Connection Tester</h3>
                <p>Click below to verify keys without saving.</p>
                <button id="das-test-btn" class="button button-secondary">Test API Connections</button>
                <div id="das-test-result" style="margin-top: 15px; font-weight: 500;"></div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // 2. ASSETS
    // -------------------------------------------------------------------------
    public function enqueue_frontend_assets() {
        wp_enqueue_style('das-style', plugin_dir_url(__FILE__) . 'style.css', [], '4.0');
        wp_enqueue_script('das-script', plugin_dir_url(__FILE__) . 'script.js', [], '4.0', true);
        wp_localize_script('das-script', 'das_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('das_nonce')
        ]);
    }

    public function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if ($hook !== 'safari_route_page_das-settings') return;

        // Reuse the same script file, or you could split them. We will use one for simplicity.
        wp_enqueue_script('das-admin-script', plugin_dir_url(__FILE__) . 'script.js', [], '4.0', true);
        wp_localize_script('das-admin-script', 'das_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('das_nonce')
        ]);
        
        // Simple Admin CSS injection for the tester card
        wp_add_inline_style('wp-admin', '
            .das-admin-wrapper { max-width: 800px; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 20px; }
            .das-admin-tester { background: #f0f6fc; padding: 15px; border-radius: 6px; border: 1px solid #cce5ff; margin-top: 30px; }
            .das-success { color: #00a32a; } .das-error { color: #d63638; }
        ');
    }

    public function register_routes_cpt() {
        register_post_type('safari_route', [
            'labels' => ['name' => 'Safari Routes', 'singular_name' => 'Route'],
            'public' => true,
            'supports' => ['title', 'custom-fields'],
            'menu_icon' => 'dashicons-location-alt',
            'show_in_menu' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // 3. FRONTEND LOGIC (Widget)
    // -------------------------------------------------------------------------
    public function render_floating_widget() {
        $routes = get_posts(['post_type' => 'safari_route', 'numberposts' => -1]);
        ?>
        <button id="das-widget-toggle" aria-label="Check Weather">
            <span class="das-icon">⚓</span><span class="das-label">Skipper AI</span>
        </button>
        <div id="das-interface" class="das-wrapper">
            <div class="das-glass-card">
                <div class="das-header">
                    <h2>🐬 Captain's Forecast</h2><button id="das-close-btn">&times;</button>
                </div>
                <form id="das-form">
                    <div class="das-input-group">
                        <label>Destination</label>
                        <select id="das-route" required>
                            <option value="">Select Route...</option>
                            <?php foreach($routes as $route): ?>
                                <option value="<?php echo esc_attr($route->ID); ?>"><?php echo esc_html($route->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="das-input-group">
                        <label>Date & Time</label>
                        <input type="datetime-local" id="das-date" required>
                    </div>
                    <button type="submit" class="das-btn-glow">Ask AI Captain</button>
                </form>
                <div id="das-result" class="das-result-area" style="display:none;"><div class="das-content"></div></div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // 4. API HANDLERS (Frontend & Backend Tester)
    // -------------------------------------------------------------------------
    
    // THE REAL LOGIC
    public function handle_analysis_request() {
        check_ajax_referer('das_nonce', 'nonce');
        
        $route_id = intval($_POST['route_id']);
        $user_date = sanitize_text_field($_POST['date']);
        
        $lat = get_post_meta($route_id, 'latitude', true);
        $lon = get_post_meta($route_id, 'longitude', true);
        
        // Fetch keys from DB
        $owm_key = get_option('das_owm_key');
        $gemini_key = get_option('das_gemini_key');

        if(!$owm_key || !$gemini_key) { wp_send_json_error(['message' => 'API Keys not configured in settings.']); return; }
        if(!$lat || !$lon) { wp_send_json_error(['message' => 'Route coordinates missing.']); return; }

        $weather = $this->get_weather_forecast($lat, $lon, $user_date, $owm_key);
        if(!$weather) { wp_send_json_error(['message' => 'Weather API error.']); return; }

        $advice = $this->ask_gemini($weather, $user_date, $gemini_key);
        wp_send_json_success(['analysis' => $advice]);
    }

    // THE ADMIN TEST LOGIC
    public function handle_admin_api_test() {
        check_ajax_referer('das_nonce', 'nonce');
        
        // We use the keys passed from the test form input, OR saved keys if empty
        $owm_key = sanitize_text_field($_POST['owm_key']);
        $gemini_key = sanitize_text_field($_POST['gemini_key']);

        $errors = [];
        $success = [];

        // 1. Test OpenWeatherMap (Check current weather for Cyprus)
        $owm_test = wp_remote_get("https://api.openweathermap.org/data/2.5/weather?lat=35&lon=33&appid={$owm_key}");
        if(is_wp_error($owm_test) || wp_remote_retrieve_response_code($owm_test) != 200) {
            $errors[] = "❌ OpenWeatherMap: Invalid Key or Connection Failed.";
        } else {
            $success[] = "✅ OpenWeatherMap: Connected!";
        }

        // 2. Test Gemini (Simple Hello)
        $gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.0-flash:generateContent?key={$gemini_key}";
        $gemini_body = json_encode(['contents' => [['parts' => [['text' => 'Hello']]]]]);
        $gemini_test = wp_remote_post($gemini_url, ['body' => $gemini_body, 'headers' => ['Content-Type' => 'application/json']]);
        
        if(is_wp_error($gemini_test) || wp_remote_retrieve_response_code($gemini_test) != 200) {
            $errors[] = "❌ Gemini AI: Invalid Key or Connection Failed.";
        } else {
            $success[] = "✅ Gemini AI: Connected!";
        }

        wp_send_json_success(['messages' => array_merge($success, $errors)]);
    }

    // HELPER FUNCTIONS
    private function get_weather_forecast($lat, $lon, $date, $key) {
        $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$key}&units=metric";
        $res = wp_remote_get($url);
        if (is_wp_error($res)) return false;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!isset($data['list'])) return false;

        $target = strtotime($date);
        $closest = null; $min = PHP_INT_MAX;
        foreach ($data['list'] as $f) {
            $diff = abs($f['dt'] - $target);
            if ($diff < $min) { $min = $diff; $closest = $f; }
        }
        return $closest;
    }

    private function ask_gemini($weather, $date, $key) {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.0-flash:generateContent?key={$key}";
        $desc = $weather['weather'][0]['description'];
        $temp = round($weather['main']['temp']);
        $wind = $weather['wind']['speed'];

        $prompt = "Captain for DolphinBoatSafari. Date: $date. Weather: $desc, Temp: {$temp}C, Wind: {$wind}m/s. 1.Status? 2.Recommendation. 3.Alt time if bad. HTML(<b>). Max 60 words.";
        
        $body = ['contents' => [['parts' => [['text' => $prompt]]]]];
        $res = wp_remote_post($endpoint, ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json']]);
        if (is_wp_error($res)) return "AI Error.";
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? "AI Error.";
    }
}
new DolphinAISkipper();
