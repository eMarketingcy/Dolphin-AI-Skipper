<?php
/**
 * Plugin Name: Dolphin AI Skipper
 * Description: AI-powered sailing advisor using OpenWeatherMap & Google Gemini 3.0.
 * Version: 3.0.0
 * Author: Coding Partner
 */

if (!defined('ABSPATH')) exit;

// CONFIGURATION
define('DAS_OWM_KEY', 'YOUR_OPENWEATHERMAP_API_KEY');
define('DAS_GEMINI_KEY', 'YOUR_GOOGLE_GEMINI_API_KEY');
define('DAS_GEMINI_MODEL', 'gemini-3.0-flash'); 

class DolphinAISkipper {

    public function __construct() {
        add_action('init', [$this, 'register_routes_cpt']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Inject the UI into the footer of every page
        add_action('wp_footer', [$this, 'render_floating_widget']);

        // AJAX Endpoints
        add_action('wp_ajax_nopriv_das_check_sailing', [$this, 'handle_analysis_request']);
        add_action('wp_ajax_das_check_sailing', [$this, 'handle_analysis_request']);
    }

    public function register_routes_cpt() {
        register_post_type('safari_route', [
            'labels' => ['name' => 'Safari Routes', 'singular_name' => 'Route'],
            'public' => true,
            'supports' => ['title', 'custom-fields'],
            'menu_icon' => 'dashicons-location-alt',
        ]);
    }

    public function enqueue_assets() {
        wp_enqueue_style('das-style', plugin_dir_url(__FILE__) . 'style.css', [], '3.0');
        // Loading our script with NO jQuery dependency
        wp_enqueue_script('das-script', plugin_dir_url(__FILE__) . 'script.js', [], '3.0', true);
        
        wp_localize_script('das-script', 'das_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('das_nonce')
        ]);
    }

    // This renders the invisible popup + the toggle button
    public function render_floating_widget() {
        $routes = get_posts(['post_type' => 'safari_route', 'numberposts' => -1]);
        ?>
        
        <button id="das-widget-toggle" aria-label="Check Weather">
            <span class="das-icon">⚓</span>
            <span class="das-label">Skipper AI</span>
        </button>

        <div id="das-interface" class="das-wrapper">
            <div class="das-glass-card">
                <div class="das-header">
                    <h2>🐬 Captain's Forecast</h2>
                    <button id="das-close-btn">&times;</button>
                </div>
                
                <form id="das-form">
                    <div class="das-input-group">
                        <label>Where are we going?</label>
                        <select id="das-route" required>
                            <option value="">Select Route...</option>
                            <?php foreach($routes as $route): ?>
                                <option value="<?php echo esc_attr($route->ID); ?>">
                                    <?php echo esc_html($route->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="das-input-group">
                        <label>When?</label>
                        <input type="datetime-local" id="das-date" required>
                    </div>

                    <button type="submit" class="das-btn-glow">Ask AI Captain</button>
                </form>

                <div id="das-result" class="das-result-area" style="display:none;">
                    <div class="das-content"></div>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_analysis_request() {
        check_ajax_referer('das_nonce', 'nonce');
        $route_id = intval($_POST['route_id']);
        $user_date = sanitize_text_field($_POST['date']); 

        $lat = get_post_meta($route_id, 'latitude', true);
        $lon = get_post_meta($route_id, 'longitude', true);

        if(!$lat || !$lon) { wp_send_json_error(['message' => 'Coordinates missing.']); return; }

        $weather_data = $this->get_weather_forecast($lat, $lon, $user_date);
        if(!$weather_data) { wp_send_json_error(['message' => 'Weather data unavailable.']); return; }

        $ai_advice = $this->ask_gemini($weather_data, $user_date);
        wp_send_json_success(['analysis' => $ai_advice]);
    }

    private function get_weather_forecast($lat, $lon, $target_date_str) {
        $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid=" . DAS_OWM_KEY . "&units=metric";
        $response = wp_remote_get($url);
        if (is_wp_error($response)) return false;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['list'])) return false;

        $target_ts = strtotime($target_date_str);
        $closest = null;
        $min_diff = PHP_INT_MAX;

        foreach ($data['list'] as $forecast) {
            $diff = abs($forecast['dt'] - $target_ts);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest = $forecast;
            }
        }
        return $closest;
    }

    private function ask_gemini($weather, $date) {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/" . DAS_GEMINI_MODEL . ":generateContent?key=" . DAS_GEMINI_KEY;
        
        $desc = $weather['weather'][0]['description'];
        $temp = round($weather['main']['temp']);
        $wind = $weather['wind']['speed'];
        
        $prompt = "Act as a friendly Boat Captain for DolphinBoatSafari. User date: $date. Weather: $desc, Temp: {$temp}C, Wind: {$wind}m/s. 
        1. Is it smooth or bumpy? 
        2. Give a 1-sentence recommendation. 
        3. If bad, suggest a time. 
        Use HTML (<b>). Max 60 words.";

        $body = ['contents' => [['parts' => [['text' => $prompt]]]]];
        $args = ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json'], 'timeout' => 15];
        
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) return "Radio silence... try again.";
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? "No signal.";
    }
}

new DolphinAISkipper();
