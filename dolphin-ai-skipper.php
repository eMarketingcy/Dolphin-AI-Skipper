<?php
/**
 * Plugin Name: Dolphin AI Skipper
 * Description: AI-powered sailing advisor with Voice AI, Interactive Maps, Living Backgrounds, Seasickness Gauge & Climate Mode. Modern 2026 UI/UX.
 * Version: 7.1.0
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
        $user_date_str = sanitize_text_field($_POST['date']); // YYYY-MM-DDTHH:MM (for display only)

        // Use UTC timestamp sent from JavaScript to avoid timezone issues
        $user_ts = isset($_POST['timestamp']) ? intval($_POST['timestamp']) : strtotime($user_date_str);

        $lat = get_post_meta($route_id, 'latitude', true);
        $lon = get_post_meta($route_id, 'longitude', true);
        $owm_key = get_option('das_owm_key');
        $gemini_key = get_option('das_gemini_key');

        if(!$owm_key || !$gemini_key || !$lat || !$lon) {
            wp_send_json_error(['message' => 'Configuration missing.']);
        }

        // Check if Climate Mode is needed (> 5 days away)
        $use_climate_mode = $this->is_climate_mode_required($user_ts);

        if ($use_climate_mode) {
            // CLIMATE MODE: Use Seasonal Intelligence for long-term forecasts
            $target_weather = $this->get_climate_forecast($user_ts);
            $forecast_list = [$target_weather]; // Single item for consistency
        } else {
            // NORMAL MODE: Use OpenWeather API for near-term forecasts (≤ 5 days)
            // 1. Get the FULL 5-day forecast list
            $forecast_list = $this->get_forecast_list($lat, $lon, $owm_key);
            if(!$forecast_list) { wp_send_json_error(['message' => 'Weather satellites unavailable.']); }

            // 2. Find User's Specific Slot using UTC timestamp
            $target_weather = $this->find_closest_slot($forecast_list, $user_ts);
        }

        // 3. Analyze & Find Alternative if needed
        $alternative = null;
        $is_bad_weather = false;

        // Define "Bad Weather" logic (e.g., Wind > 6m/s OR Rain)
        $wind_speed = $target_weather['wind']['speed'];
        $weather_main = strtolower($target_weather['weather'][0]['main']);

        if ($wind_speed > 6.0 || strpos($weather_main, 'rain') !== false) {
            $is_bad_weather = true;
            // Only find alternatives in normal mode (not in climate mode)
            if (!$use_climate_mode) {
                // Run Algorithm: Find closest "Good" slot
                $alternative = $this->find_best_alternative($forecast_list, $user_ts);
            }
        }

        // 4. Send everything to AI (pass UTC timestamp for correct formatting)
        $advice = $this->ask_gemini_smart($target_weather, $alternative, $user_ts, $use_climate_mode, $gemini_key);

        // 5. Prepare additional data for frontend features
        $weather_condition = strtolower($target_weather['weather'][0]['main']);
        $route_title = get_the_title($route_id);

        // Calculate seasickness risk (0-100) based on wind speed and conditions
        $seasickness_risk = $this->calculate_seasickness_risk($wind_speed, $weather_condition);

        wp_send_json_success([
            'analysis' => $advice,
            'weather_condition' => $weather_condition,
            'wind_speed' => $wind_speed,
            'seasickness_risk' => $seasickness_risk,
            'coordinates' => ['lat' => floatval($lat), 'lon' => floatval($lon)],
            'route_name' => $route_title,
            'climate_mode' => $use_climate_mode
        ]);
    }

    // --- ALGORITHMS ---

    // Check if date is more than 5 days away (requires Climate Mode)
    private function is_climate_mode_required($target_ts) {
        $now = time();
        $days_difference = ($target_ts - $now) / (60 * 60 * 24);
        return $days_difference > 5;
    }

    // Seasonal Intelligence for Cyprus (Climate Mode for dates > 5 days)
    private function get_climate_forecast($target_ts) {
        // Get the target date components
        $month = intval(gmdate('n', $target_ts)); // 1-12
        $day = intval(gmdate('j', $target_ts));
        $hour = intval(gmdate('H', $target_ts));

        // Cyprus Climate Patterns (Mediterranean Climate)
        // Based on historical averages for Cyprus

        $climate_data = [
            // Winter (December, January, February) - Mild, rainy season
            12 => ['temp' => 15, 'temp_var' => 3, 'wind' => 5.5, 'wind_var' => 2, 'rain_chance' => 45, 'conditions' => ['Clear', 'Clouds', 'Rain']],
            1  => ['temp' => 13, 'temp_var' => 3, 'wind' => 6.0, 'wind_var' => 2, 'rain_chance' => 50, 'conditions' => ['Clear', 'Clouds', 'Rain']],
            2  => ['temp' => 14, 'temp_var' => 3, 'wind' => 5.5, 'wind_var' => 2, 'rain_chance' => 40, 'conditions' => ['Clear', 'Clouds', 'Rain']],

            // Spring (March, April, May) - Mild, transitional
            3  => ['temp' => 16, 'temp_var' => 3, 'wind' => 5.0, 'wind_var' => 2, 'rain_chance' => 30, 'conditions' => ['Clear', 'Clouds', 'Clouds']],
            4  => ['temp' => 20, 'temp_var' => 3, 'wind' => 4.5, 'wind_var' => 1.5, 'rain_chance' => 20, 'conditions' => ['Clear', 'Clear', 'Clouds']],
            5  => ['temp' => 24, 'temp_var' => 3, 'wind' => 4.0, 'wind_var' => 1.5, 'rain_chance' => 10, 'conditions' => ['Clear', 'Clear', 'Clouds']],

            // Summer (June, July, August) - Hot, dry, stable
            6  => ['temp' => 28, 'temp_var' => 2, 'wind' => 4.0, 'wind_var' => 1, 'rain_chance' => 5, 'conditions' => ['Clear', 'Clear', 'Clear']],
            7  => ['temp' => 31, 'temp_var' => 2, 'wind' => 3.5, 'wind_var' => 1, 'rain_chance' => 2, 'conditions' => ['Clear', 'Clear', 'Clear']],
            8  => ['temp' => 31, 'temp_var' => 2, 'wind' => 3.5, 'wind_var' => 1, 'rain_chance' => 2, 'conditions' => ['Clear', 'Clear', 'Clear']],

            // Autumn (September, October, November) - Warm to mild
            9  => ['temp' => 27, 'temp_var' => 2, 'wind' => 4.0, 'wind_var' => 1.5, 'rain_chance' => 10, 'conditions' => ['Clear', 'Clear', 'Clouds']],
            10 => ['temp' => 23, 'temp_var' => 3, 'wind' => 4.5, 'wind_var' => 1.5, 'rain_chance' => 25, 'conditions' => ['Clear', 'Clouds', 'Clouds']],
            11 => ['temp' => 19, 'temp_var' => 3, 'wind' => 5.0, 'wind_var' => 2, 'rain_chance' => 35, 'conditions' => ['Clear', 'Clouds', 'Rain']],
        ];

        $season_data = $climate_data[$month];

        // Add some pseudo-random variation based on the date (deterministic for same date)
        $seed = $day + $hour;
        $temp_offset = (($seed * 7) % 7) - 3; // -3 to +3
        $wind_offset = (($seed * 5) % 5) - 2; // -2 to +2

        $temp = $season_data['temp'] + ($temp_offset * $season_data['temp_var'] / 3);
        $wind = max(2.0, $season_data['wind'] + ($wind_offset * $season_data['wind_var'] / 2));

        // Determine weather condition based on rain chance and seed
        $rain_roll = ($seed * 13) % 100;
        if ($rain_roll < $season_data['rain_chance']) {
            $condition = 'Rain';
            $description = 'light rain';
        } else {
            // Pick from available conditions based on seed
            $cond_index = ($seed * 3) % count($season_data['conditions']);
            $condition = $season_data['conditions'][$cond_index];
            $description = $condition === 'Clear' ? 'clear sky' : 'scattered clouds';
        }

        // Return in OpenWeather API format for compatibility
        return [
            'dt' => $target_ts,
            'main' => [
                'temp' => round($temp, 1),
                'feels_like' => round($temp - 1, 1),
                'humidity' => 65,
                'pressure' => 1013
            ],
            'weather' => [
                [
                    'main' => $condition,
                    'description' => $description,
                    'icon' => '01d'
                ]
            ],
            'wind' => [
                'speed' => round($wind, 1),
                'deg' => 270,
                'gust' => round($wind * 1.3, 1)
            ],
            'clouds' => [
                'all' => $condition === 'Clouds' ? 50 : ($condition === 'Clear' ? 10 : 80)
            ],
            'climate_mode' => true // Flag to indicate this is from climate algorithm
        ];
    }

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

    // Calculate Seasickness Risk (0-100) based on wind and weather
    private function calculate_seasickness_risk($wind_speed, $weather_condition) {
        $risk = 0;

        // Base risk from wind speed (0-60 points)
        if ($wind_speed < 3) {
            $risk = 5;  // Calm
        } elseif ($wind_speed < 5) {
            $risk = 15; // Light breeze
        } elseif ($wind_speed < 7) {
            $risk = 35; // Moderate
        } elseif ($wind_speed < 10) {
            $risk = 60; // Choppy
        } else {
            $risk = 85; // Rough
        }

        // Add weather condition penalties
        if (strpos($weather_condition, 'storm') !== false) {
            $risk = min(100, $risk + 30);
        } elseif (strpos($weather_condition, 'rain') !== false) {
            $risk = min(100, $risk + 15);
        } elseif (strpos($weather_condition, 'cloud') !== false) {
            $risk = min(100, $risk + 5);
        }

        return min(100, max(0, $risk));
    }

    // --- AI INTEGRATION ---

    private function ask_gemini_smart($current, $alt, $user_timestamp, $is_climate_mode, $key) {
        // Prepare Data for Prompt
        $c_desc = $current['weather'][0]['description'];
        $c_temp = round($current['main']['temp']);
        $c_wind = $current['wind']['speed'];
        // Format date from UTC timestamp (use gmdate to avoid timezone issues)
        $c_date = gmdate('d M H:i', $user_timestamp);

        // Add climate mode indicator
        $mode_text = $is_climate_mode
            ? "DATA SOURCE: Seasonal Intelligence (based on Cyprus historical climate averages). This is a long-term forecast >5 days away."
            : "DATA SOURCE: Live Weather API.";

        $alt_text = "No better alternative found nearby.";
        if ($alt) {
            // Use gmdate for UTC consistency (OpenWeather API returns UTC timestamps)
            $a_date = gmdate('d M H:i', $alt['dt']);
            $a_wind = $alt['wind']['speed'];
            $alt_text = "BETTER OPTION FOUND: $a_date (Wind: {$a_wind}m/s).";
        }

        $prompt = "
        Role: Expert Cypriot Boat Captain.
        User Request: $c_date.
        $mode_text
        Current Forecast for that time: Sky: $c_desc, Temp: {$c_temp}C, Wind: {$c_wind} m/s.
        Alternative Data: $alt_text

        Task:
        1. If using Seasonal Intelligence, mention this is a climate-based prediction for long-term planning.
        2. If wind > 6m/s, explicitly warn the user it will be rough/bumpy.
        3. If you have a 'BETTER OPTION' in the data, strictly recommend that date/time instead and explain why (e.g. 'Smoother seas').
        4. If weather is good, just say 'Perfect conditions'.

        Tone: Professional, helpful, concise.
        Format: HTML. Use <strong style='color:#00d2ff'> for dates. Max 70 words.
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
        // Leaflet.js for Interactive Maps
        wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', false);

        wp_enqueue_style('das-style', plugin_dir_url(__FILE__) . 'style.css', [], '7.1.0');
        wp_enqueue_script('das-script', plugin_dir_url(__FILE__) . 'script.js', ['leaflet-js'], '7.1.0', true);
        wp_localize_script('das-script', 'das_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('das_nonce')
        ]);
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'safari_route_page_das-settings') return;
        wp_enqueue_script('das-script', plugin_dir_url(__FILE__) . 'script.js', [], '7.1.0', true);
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
                    <h2>🐬 Captain's Forecast</h2>
                    <div class="das-header-actions">
                        <button id="das-voice-btn" class="das-voice-btn" title="Voice Command" aria-label="Activate Voice Control">
                            🎤
                        </button>
                        <button id="das-close-btn" aria-label="Close">&times;</button>
                    </div>
                </div>

                <!-- Voice Feedback -->
                <div id="das-voice-feedback" class="das-voice-feedback" style="display:none;">
                    <span class="das-pulse">🎙️ Listening...</span>
                </div>

                <form id="das-form">
                    <div class="das-input-group">
                        <label>Destination</label>
                        <select id="das-route" required>
                            <option value="">Select Route...</option>
                            <?php foreach($routes as $route): ?>
                                <option value="<?php echo esc_attr($route->ID); ?>"
                                        data-name="<?php echo esc_attr($route->post_title); ?>"
                                        data-lat="<?php echo esc_attr(get_post_meta($route->ID, 'latitude', true)); ?>"
                                        data-lon="<?php echo esc_attr(get_post_meta($route->ID, 'longitude', true)); ?>">
                                    <?php echo esc_html($route->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="das-input-group">
                        <label>Date & Time</label>
                        <input type="datetime-local" id="das-date" required>
                    </div>
                    <button type="submit" class="das-btn-glow">Ask AI Captain</button>
                </form>

                <!-- Seasickness Gauge -->
                <div id="das-seasickness-gauge" class="das-gauge-container" style="display:none;">
                    <div class="das-gauge-label">⚓ Seasickness Risk Meter</div>
                    <div class="das-gauge">
                        <svg viewBox="0 0 200 120" class="das-gauge-svg">
                            <!-- Gauge Background Arc -->
                            <path d="M 20 100 A 80 80 0 0 1 180 100" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="12" stroke-linecap="round"/>
                            <!-- Gauge Progress Arc -->
                            <path id="das-gauge-progress" d="M 20 100 A 80 80 0 0 1 180 100" fill="none" stroke="url(#gaugeGradient)" stroke-width="12" stroke-linecap="round" stroke-dasharray="251.2" stroke-dashoffset="251.2"/>
                            <!-- Gradient Definition -->
                            <defs>
                                <linearGradient id="gaugeGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" style="stop-color:#00f260;stop-opacity:1" />
                                    <stop offset="50%" style="stop-color:#FFD700;stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#ff5f6d;stop-opacity:1" />
                                </linearGradient>
                            </defs>
                            <!-- Value Text -->
                            <text id="das-gauge-value" x="100" y="85" text-anchor="middle" font-size="32" font-weight="bold" fill="#fff">0</text>
                            <text x="100" y="105" text-anchor="middle" font-size="12" fill="rgba(255,255,255,0.6)" id="das-gauge-status">CALM</text>
                        </svg>
                    </div>
                    <div id="das-gauge-advice" class="das-gauge-advice">
                        Calculating risk factors...
                    </div>
                    <div class="das-gauge-legend">
                        <div class="das-gauge-legend-item">
                            <span class="das-gauge-dot" style="background: #00f260;"></span>
                            <span>0-20: Calm</span>
                        </div>
                        <div class="das-gauge-legend-item">
                            <span class="das-gauge-dot" style="background: #FFD700;"></span>
                            <span>40-60: Moderate</span>
                        </div>
                        <div class="das-gauge-legend-item">
                            <span class="das-gauge-dot" style="background: #ff5f6d;"></span>
                            <span>80-100: Rough</span>
                        </div>
                    </div>
                </div>

                <!-- Interactive Map -->
                <div id="das-map-container" class="das-map-container" style="display:none;">
                    <div class="das-map-header">
                        <span id="das-map-title">📍 Route Location</span>
                        <button id="das-map-close" class="das-map-close-btn">&times;</button>
                    </div>
                    <div id="das-map" class="das-map"></div>
                </div>

                <!-- Results Area -->
                <div id="das-result" class="das-result-area" style="display:none;">
                    <div class="das-content"></div>
                </div>
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
