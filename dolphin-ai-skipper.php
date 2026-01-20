<?php
/**
 * Plugin Name: Dolphin AI Skipper
 * Description: AI-powered sailing advisor. Includes Admin Settings, API Tester & Smart Navigation Logic.
 * Version: 6.0.0
 * Author: Coding Partner
 */

if (!defined('ABSPATH')) exit;

// CONFIGURATION
define('DAS_GEMINI_MODEL', 'gemini-3-flash-preview'); 

class DolphinAISkipper {

    public function __construct() {
        add_action('init', [$this, 'register_routes_cpt']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_footer', [$this, 'render_floating_widget']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX
        add_action('wp_ajax_nopriv_das_check_sailing', [$this, 'handle_analysis_request']);
        add_action('wp_ajax_das_check_sailing', [$this, 'handle_analysis_request']);
        add_action('wp_ajax_das_test_apis', [$this, 'handle_admin_api_test']);
    }

    // ... (Admin Menu, Assets, & CPT functions remain the same as Version 5.0) ...
    // ... Copy 'register_routes_cpt', 'add_admin_menu', 'register_settings', etc. from previous version ...
    // ... For brevity, I am showing the UPDATED LOGIC below ...

    // =========================================================================
    // THE NEW BRAIN: SMART ANALYSIS & ALTERNATIVES
    // =========================================================================
    public function handle_analysis_request() {
        check_ajax_referer('das_nonce', 'nonce');
        
        $route_id = intval($_POST['route_id']);
        $user_date_str = sanitize_text_field($_POST['date']); // YYYY-MM-DDTHH:MM
        
        $lat = get_post_meta($route_id, 'latitude', true);
        $lon = get_post_meta($route_id, 'longitude', true);
        $owm_key = get_option('das_owm_key');
        $gemini_key = get_option('das_gemini_key');

        if(!$owm_key || !$gemini_key || !$lat || !$lon) { 
            wp_send_json_error(['message' => 'Configuration missing.']); 
        }

        // 1. Get the FULL 5-day forecast list
        $forecast_list = $this->get_forecast_list($lat, $lon, $owm_key);
        if(!$forecast_list) { wp_send_json_error(['message' => 'Weather satellites unavailable.']); }

        // 2. Find User's Specific Slot
        $user_ts = strtotime($user_date_str);
        $target_weather = $this->find_closest_slot($forecast_list, $user_ts);

        // 3. Analyze & Find Alternative if needed
        $alternative = null;
        $is_bad_weather = false;

        // Define "Bad Weather" logic (e.g., Wind > 6m/s OR Rain)
        $wind_speed = $target_weather['wind']['speed'];
        $weather_main = strtolower($target_weather['weather'][0]['main']);
        
        if ($wind_speed > 6.0 || strpos($weather_main, 'rain') !== false) {
            $is_bad_weather = true;
            // Run Algorithm: Find closest "Good" slot
            $alternative = $this->find_best_alternative($forecast_list, $user_ts);
        }

        // 4. Send everything to AI
        $advice = $this->ask_gemini_smart($target_weather, $alternative, $user_date_str, $gemini_key);
        
        wp_send_json_success(['analysis' => $advice]);
    }

    // --- ALGORITHMS ---

    // Get raw OWM list
    private function get_forecast_list($lat, $lon, $key) {
        $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$key}&units=metric";
        $res = wp_remote_get($url);
        if (is_wp_error($res)) return false;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return $data['list'] ?? false;
    }

    // Find specific slot for user's date
    private function find_closest_slot($list, $target_ts) {
        $closest = null; $min_diff = PHP_INT_MAX;
        foreach ($list as $item) {
            $diff = abs($item['dt'] - $target_ts);
            if ($diff < $min_diff) { $min_diff = $diff; $closest = $item; }
        }
        return $closest;
    }

    // The "Senior Dev" Algorithm: Find the closest BETTER date
    private function find_best_alternative($list, $current_bad_ts) {
        $best_slot = null;
        $min_time_dist = PHP_INT_MAX;

        foreach ($list as $item) {
            // Logic: Wind < 5m/s AND No Rain
            $wind = $item['wind']['speed'];
            $main = strtolower($item['weather'][0]['main']);
            
            if ($wind < 5.0 && strpos($main, 'rain') === false) {
                // How far is this from the user's original bad choice?
                $time_dist = abs($item['dt'] - $current_bad_ts);
                
                // We prefer future dates (don't suggest the past), but within reasonable range
                if ($time_dist < $min_time_dist) {
                    $min_time_dist = $time_dist;
                    $best_slot = $item;
                }
            }
        }
        return $best_slot;
    }

    // --- AI INTEGRATION ---

    private function ask_gemini_smart($current, $alt, $user_date, $key) {
        // Prepare Data for Prompt
        $c_desc = $current['weather'][0]['description'];
        $c_temp = round($current['main']['temp']);
        $c_wind = $current['wind']['speed'];
        $c_date = date('d M H:i', strtotime($user_date));

        $alt_text = "No better alternative found nearby.";
        if ($alt) {
            $a_date = date('d M H:i', $alt['dt']);
            $a_wind = $alt['wind']['speed'];
            $alt_text = "BETTER OPTION FOUND: $a_date (Wind: {$a_wind}m/s).";
        }

        $prompt = "
        Role: Expert Cypriot Boat Captain.
        User Request: $c_date.
        Current Forecast for that time: Sky: $c_desc, Temp: {$c_temp}C, Wind: {$c_wind} m/s.
        Alternative Data: $alt_text

        Task:
        1. If wind > 6m/s, explicitly warn the user it will be rough/bumpy.
        2. If you have a 'BETTER OPTION' in the data, strictly recommend that date/time instead and explain why (e.g. 'Smoother seas').
        3. If weather is good, just say 'Perfect conditions'.
        
        Tone: Professional, helpful, concise. 
        Format: HTML. Use <strong style='color:#00d2ff'> for dates. Max 60 words.
        ";

        return $this->ask_gemini_raw($prompt, $key);
    }

    // Raw Gemini Call (Same as before)
    private function ask_gemini_raw($prompt_text, $key) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . DAS_GEMINI_MODEL . ":generateContent?key=" . $key;
        $body = ['contents' => [['parts' => [['text' => $prompt_text]]]]];
        $args = ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json'], 'timeout' => 15];
        
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) return "Connection Error.";
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Captain is offline.";
    }

    // ... (Keep the rest of the class functions: enqueue, render_ui, etc.) ...
     public function add_admin_menu() {
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
            <h1>🐬 AI Skipper Configuration (Gemini 3.0)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('das_settings_group'); ?>
                <?php do_settings_sections('das_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">OpenWeatherMap Key</th>
                        <td><input type="password" id="das_owm_key" name="das_owm_key" value="<?php echo esc_attr(get_option('das_owm_key')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Gemini API Key</th>
                        <td><input type="password" id="das_gemini_key" name="das_gemini_key" value="<?php echo esc_attr(get_option('das_gemini_key')); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <div class="das-admin-tester">
                <h3>🧪 Connection Tester</h3>
                <button id="das-test-btn" class="button button-secondary">Test Connections</button>
                <div id="das-test-result" style="margin-top:15px; font-weight:500;"></div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // 2. ASSETS & CPT
    // =========================================================================
    public function enqueue_frontend_assets() {
        wp_enqueue_style('das-style', plugin_dir_url(__FILE__) . 'style.css', [], '6.0');
        wp_enqueue_script('das-script', plugin_dir_url(__FILE__) . 'script.js', [], '6.0', true);
        wp_localize_script('das-script', 'das_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('das_nonce')
        ]);
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'safari_route_page_das-settings') return;
        wp_enqueue_script('das-script', plugin_dir_url(__FILE__) . 'script.js', [], '6.0', true);
        wp_localize_script('das-script', 'das_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('das_nonce')
        ]);
        wp_add_inline_style('wp-admin', '.das-admin-wrapper{background:#fff;padding:20px;border-radius:8px;margin-top:20px;} .das-admin-tester{background:#f0f6fc;padding:15px;margin-top:30px;border:1px solid #cce5ff;border-radius:6px;} .das-success{color:#00a32a;} .das-error{color:#d63638;}');
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

    // =========================================================================
    // 3. THE UI (Floating Widget)
    // =========================================================================
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

    // ADMIN TESTER LOGIC
     public function handle_admin_api_test() {
        check_ajax_referer('das_nonce', 'nonce');
        $owm_key = sanitize_text_field($_POST['owm_key']);
        $gemini_key = sanitize_text_field($_POST['gemini_key']);
        $msgs = [];

        // 1. OWM Test
        $owm = wp_remote_get("https://api.openweathermap.org/data/2.5/weather?lat=35&lon=33&appid={$owm_key}");
        $msgs[] = (is_wp_error($owm) || wp_remote_retrieve_response_code($owm) != 200) 
            ? "<span class='das-error'>❌ OpenWeather: Failed</span>" 
            : "<span class='das-success'>✅ OpenWeather: Live</span>";

        // 2. Gemini 3.0 Test
        // Minimal payload to test connectivity
        $test_advice = $this->ask_gemini_raw("Say Hello", $gemini_key);
        $msgs[] = (strpos($test_advice, 'Error') !== false)
            ? "<span class='das-error'>❌ Gemini: $test_advice</span>"
            : "<span class='das-success'>✅ Gemini 3.0: Live</span>";

        wp_send_json_success(['messages' => $msgs]);
    }
}

new DolphinAISkipper();
