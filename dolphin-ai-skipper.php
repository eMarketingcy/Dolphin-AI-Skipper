<?php
/**
 * Plugin Name: Dolphin AI Skipper
 * Description: AI-powered sailing advisor using OpenWeatherMap & Google Gemini 3.0.
 * Version: 2.0.0
 * Author: Coding Partner
 */

if (!defined('ABSPATH')) exit;

// -------------------------------------------------------------------------
// 1. CONFIGURATION (Replace with your actual keys)
// -------------------------------------------------------------------------
define('DAS_OWM_KEY', 'YOUR_OPENWEATHERMAP_API_KEY');
define('DAS_GEMINI_KEY', 'YOUR_GOOGLE_GEMINI_API_KEY');
// Using the Flash model for speed/cost efficiency
define('DAS_GEMINI_MODEL', 'gemini-3.0-flash'); 

class DolphinAISkipper {

    public function __construct() {
        add_action('init', [$this, 'register_routes_cpt']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('ai_skipper_ui', [$this, 'render_ui']);
        
        // AJAX Endpoints
        add_action('wp_ajax_nopriv_das_check_sailing', [$this, 'handle_analysis_request']);
        add_action('wp_ajax_das_check_sailing', [$this, 'handle_analysis_request']);
    }

    // -------------------------------------------------------------------------
    // 2. BACKEND: REGISTER "ROUTES"
    // -------------------------------------------------------------------------
    public function register_routes_cpt() {
        register_post_type('safari_route', [
            'labels' => ['name' => 'Safari Routes', 'singular_name' => 'Route'],
            'public' => true,
            'supports' => ['title', 'custom-fields'], // Important: Enable Custom Fields for Lat/Lon
            'menu_icon' => 'dashicons-location-alt',
            'taxonomies' => ['category'], 
        ]);
    }

    // -------------------------------------------------------------------------
    // 3. FRONTEND: ASSETS & UI
    // -------------------------------------------------------------------------
    public function enqueue_assets() {
        wp_enqueue_style('das-style', plugin_dir_url(__FILE__) . 'style.css');
        wp_enqueue_script('das-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], '1.0', true);
        wp_localize_script('das-script', 'das_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('das_nonce')
        ]);
    }

    public function render_ui() {
        $routes = get_posts(['post_type' => 'safari_route', 'numberposts' => -1]);
        
        ob_start();
        ?>
        <div id="das-interface" class="das-wrapper">
            <div class="das-glass-card">
                <h2>🐬 AI Skipper Forecast</h2>
                <p class="das-subtitle">Plan your perfect safari with AI precision.</p>
                
                <form id="das-form">
                    <div class="das-input-group">
                        <label>Select Route</label>
                        <select id="das-route" required>
                            <option value="">Choose a destination...</option>
                            <?php foreach($routes as $route): ?>
                                <option value="<?php echo esc_attr($route->ID); ?>">
                                    <?php echo esc_html($route->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="das-input-group">
                        <label>Date & Time</label>
                        <input type="datetime-local" id="das-date" required>
                    </div>

                    <button type="submit" class="das-btn-glow">Analyze Conditions</button>
                </form>

                <div id="das-result" class="das-result-area" style="display:none;">
                    <div class="das-loader"></div>
                    <div class="das-content"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // 4. THE BRAIN: AJAX LOGIC
    // -------------------------------------------------------------------------
    public function handle_analysis_request() {
        check_ajax_referer('das_nonce', 'nonce');

        $route_id = intval($_POST['route_id']);
        $user_date = sanitize_text_field($_POST['date']); // Format: YYYY-MM-DDTHH:MM

        // 1. Get Location Data (Lat/Lon) from Post Meta
        $lat = get_post_meta($route_id, 'latitude', true);
        $lon = get_post_meta($route_id, 'longitude', true);

        // Fallback if admin forgot to add meta
        if(!$lat || !$lon) {
            wp_send_json_error(['message' => 'Route coordinates missing. Please contact admin.']);
        }

        // 2. Fetch Weather (OpenWeatherMap)
        $weather_data = $this->get_weather_forecast($lat, $lon, $user_date);
        
        if(!$weather_data) {
            wp_send_json_error(['message' => 'Could not retrieve weather data.']);
        }

        // 3. Ask Gemini AI
        $ai_advice = $this->ask_gemini($weather_data, $user_date);

        wp_send_json_success(['analysis' => $ai_advice]);
    }

    // HELPER: OpenWeatherMap 5 Day Forecast
    private function get_weather_forecast($lat, $lon, $target_date_str) {
        $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid=" . DAS_OWM_KEY . "&units=metric";
        
        $response = wp_remote_get($url);

        if (is_wp_error($response)) return false;

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['list'])) return false;

        // Find the forecast closest to user's selected time
        $target_ts = strtotime($target_date_str);
        $closest_forecast = null;
        $min_diff = PHP_INT_MAX;

        foreach ($data['list'] as $forecast) {
            $diff = abs($forecast['dt'] - $target_ts);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest_forecast = $forecast;
            }
        }

        return $closest_forecast;
    }

    // HELPER: Google Gemini API
    private function ask_gemini($weather, $date) {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/" . DAS_GEMINI_MODEL . ":generateContent?key=" . DAS_GEMINI_KEY;

        // Prepare human-readable weather string
        $weather_desc = $weather['weather'][0]['description'];
        $temp = $weather['main']['temp'];
        $wind = $weather['wind']['speed']; // m/s
        $gust = isset($weather['wind']['gust']) ? $weather['wind']['gust'] : 0;
        $waves = "Calculated from wind"; // OWM doesn't always give wave height in basic tier

        $prompt_text = "
            You are an experienced Boat Captain for DolphinBoatSafari.com in Cyprus.
            Context: A customer wants to book a trip on $date.
            
            Weather Data:
            - Sky: $weather_desc
            - Temp: {$temp}°C
            - Wind Speed: {$wind} m/s
            - Wind Gusts: {$gust} m/s

            Task:
            1. Analyze the 'Sailing Comfort' (Smooth, Bumpy, or Rough).
            2. Give a recommendation.
            3. If wind is > 6 m/s, suggest a specific time of day (Morning/Sunset) that is usually calmer in Cyprus.
            
            Format:
            Return valid HTML. Use <h3> for the status (e.g. 'Status: Smooth Sailing'). Use <b> for emphasis. Keep it under 80 words. Friendly tone.
        ";

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt_text]
                    ]
                ]
            ]
        ];

        $args = [
            'body'        => json_encode($body),
            'headers'     => ['Content-Type' => 'application/json'],
            'timeout'     => 15
        ];

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            return "<b>Captain's Radio is down!</b> (AI Error)";
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        // Extract text from Gemini response structure
        if (isset($response_body['candidates'][0]['content']['parts'][0]['text'])) {
            return $response_body['candidates'][0]['content']['parts'][0]['text'];
        } else {
            return "Unable to interpret weather charts right now.";
        }
    }
}

new DolphinAISkipper();
