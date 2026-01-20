/**
 * Dolphin AI Skipper - Main Script v7.0.0
 * Features: Voice AI, Interactive Maps, Living Backgrounds, Seasickness Gauge
 * Tech: Vanilla JS, Web Speech API, Leaflet.js
 */

document.addEventListener('DOMContentLoaded', () => {

    // =========================================================
    // PART 1: ADMIN PANEL LOGIC
    // =========================================================
    const adminTestBtn = document.getElementById('das-test-btn');

    if (adminTestBtn) {
        adminTestBtn.addEventListener('click', async (e) => {
            e.preventDefault();

            const resultDiv = document.getElementById('das-test-result');
            const owmKeyInput = document.getElementById('das_owm_key');
            const geminiKeyInput = document.getElementById('das_gemini_key');

            // Simple validation
            if (!owmKeyInput.value || !geminiKeyInput.value) {
                resultDiv.innerHTML = '<span class="das-error">Please enter both API keys before testing.</span>';
                return;
            }

            // UI Loading State
            adminTestBtn.textContent = 'Testing Satellites...';
            adminTestBtn.disabled = true;
            resultDiv.innerHTML = 'Pinging external servers...';

            // Prepare Data for PHP
            const formData = new FormData();
            formData.append('action', 'das_test_apis');
            formData.append('owm_key', owmKeyInput.value);
            formData.append('gemini_key', geminiKeyInput.value);
            formData.append('nonce', das_vars.nonce);

            try {
                const response = await fetch(das_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    resultDiv.innerHTML = data.data.messages.join('<br>');
                } else {
                    resultDiv.innerHTML = '<span class="das-error">Server returned an error.</span>';
                }
            } catch (err) {
                console.error('DAS Admin Error:', err);
                resultDiv.innerHTML = '<span class="das-error">Connection Failed (AJAX Error).</span>';
            } finally {
                adminTestBtn.textContent = 'Test Connections';
                adminTestBtn.disabled = false;
            }
        });
    }

    // =========================================================
    // PART 2: FRONTEND WIDGET LOGIC
    // =========================================================
    const toggleBtn = document.getElementById('das-widget-toggle');

    if (toggleBtn) {
        // DOM Elements
        const wrapper = document.getElementById('das-interface');
        const glassCard = wrapper.querySelector('.das-glass-card');
        const form = document.getElementById('das-form');
        const closeBtn = document.getElementById('das-close-btn');
        const voiceBtn = document.getElementById('das-voice-btn');
        const voiceFeedback = document.getElementById('das-voice-feedback');
        const resultArea = document.getElementById('das-result');
        const contentArea = resultArea.querySelector('.das-content');
        const submitBtn = form.querySelector('button[type="submit"]');
        const dateInput = document.getElementById('das-date');
        const routeSelect = document.getElementById('das-route');
        const gaugeContainer = document.getElementById('das-seasickness-gauge');
        const mapContainer = document.getElementById('das-map-container');

        // State
        let currentMap = null;

        // =============================
        // A. DATE PICKER RESTRICTION
        // =============================
        if (dateInput) {
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            dateInput.min = now.toISOString().slice(0, 16);
        }

        // =============================
        // B. TOGGLE VISIBILITY
        // =============================
        const toggleWidget = () => {
            wrapper.classList.toggle('is-open');
        };

        toggleBtn.addEventListener('click', toggleWidget);
        closeBtn.addEventListener('click', toggleWidget);

        // Close when clicking OUTSIDE the card
        document.addEventListener('click', (e) => {
            if (wrapper.classList.contains('is-open') &&
                !wrapper.contains(e.target) &&
                !toggleBtn.contains(e.target)) {
                wrapper.classList.remove('is-open');
            }
        });

        // =============================
        // C. VOICE AI CONTROL
        // =============================
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            const recognition = new SpeechRecognition();

            recognition.lang = 'en-US';
            recognition.continuous = false;
            recognition.interimResults = false;

            voiceBtn.addEventListener('click', () => {
                recognition.start();
                voiceBtn.classList.add('listening');
                voiceFeedback.style.display = 'block';
                voiceFeedback.innerHTML = '<span class="das-pulse">🎙️ Listening... Say "Check [Route Name]"</span>';
            });

            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript.toLowerCase();
                console.log('Voice Input:', transcript);

                // Parse voice command: "check [route name]"
                const checkMatch = transcript.match(/check\s+(.+)/);
                if (checkMatch) {
                    const routeName = checkMatch[1].trim();
                    selectRouteByVoice(routeName);
                } else {
                    voiceFeedback.innerHTML = '<span class="das-error">❌ Command not recognized. Try "Check Blue Lagoon"</span>';
                    setTimeout(() => {
                        voiceFeedback.style.display = 'none';
                    }, 3000);
                }

                voiceBtn.classList.remove('listening');
            };

            recognition.onerror = (event) => {
                console.error('Speech Recognition Error:', event.error);
                voiceFeedback.innerHTML = '<span class="das-error">❌ Voice recognition failed. Please try again.</span>';
                voiceBtn.classList.remove('listening');
                setTimeout(() => {
                    voiceFeedback.style.display = 'none';
                }, 3000);
            };

            recognition.onend = () => {
                voiceBtn.classList.remove('listening');
            };

            // Helper function to select route by voice
            function selectRouteByVoice(spokenName) {
                const options = Array.from(routeSelect.options);
                const matchedOption = options.find(opt => {
                    const optionText = opt.getAttribute('data-name') || opt.textContent;
                    return optionText.toLowerCase().includes(spokenName);
                });

                if (matchedOption) {
                    routeSelect.value = matchedOption.value;
                    voiceFeedback.innerHTML = `<span class="das-success">✅ Route selected: ${matchedOption.textContent}</span>`;

                    // Auto-set date to "now" if not set
                    if (!dateInput.value) {
                        const now = new Date();
                        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                        dateInput.value = now.toISOString().slice(0, 16);
                    }

                    // Auto-submit after 1 second
                    setTimeout(() => {
                        voiceFeedback.style.display = 'none';
                        form.dispatchEvent(new Event('submit'));
                    }, 1000);
                } else {
                    voiceFeedback.innerHTML = `<span class="das-error">❌ Route "${spokenName}" not found. Try again.</span>';
                    setTimeout(() => {
                        voiceFeedback.style.display = 'none';
                    }, 3000);
                }
            }
        } else {
            // Voice API not supported
            voiceBtn.style.display = 'none';
            console.warn('Speech Recognition API not supported in this browser');
        }

        // =============================
        // D. FORM SUBMISSION (The Brain)
        // =============================
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const routeId = routeSelect.value;
            const dateVal = dateInput.value;

            if (!routeId || !dateVal) {
                alert('Please select both a destination and a date.');
                return;
            }

            // Get route data
            const selectedOption = routeSelect.options[routeSelect.selectedIndex];
            const routeName = selectedOption.getAttribute('data-name') || selectedOption.textContent;
            const lat = parseFloat(selectedOption.getAttribute('data-lat'));
            const lon = parseFloat(selectedOption.getAttribute('data-lon'));

            // Set Loading UI
            submitBtn.textContent = 'Analyzing conditions...';
            submitBtn.disabled = true;
            resultArea.style.display = 'block';
            contentArea.innerHTML = '<span class="das-pulse">⚓ Captain is checking wind, waves, and alternative dates...</span>';

            // Hide previous results
            gaugeContainer.style.display = 'none';
            mapContainer.style.display = 'none';

            // Prepare Data
            const formData = new FormData();
            formData.append('action', 'das_check_sailing');
            formData.append('route_id', routeId);
            formData.append('date', dateVal);
            formData.append('nonce', das_vars.nonce);

            try {
                const response = await fetch(das_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error('Network error');

                const data = await response.json();

                if (data.success) {
                    const result = data.data;

                    // 1. Display AI Analysis
                    contentArea.innerHTML = result.analysis;

                    // 2. Apply Living Background
                    applyLivingBackground(result.weather_condition);

                    // 3. Show Seasickness Gauge
                    showSeasicknessGauge(result.seasickness_risk);

                    // 4. Show Interactive Map
                    showInteractiveMap(lat, lon, routeName);

                } else {
                    contentArea.innerHTML = `<span class="das-error">${data.data.message}</span>`;
                }

            } catch (err) {
                console.error('DAS Frontend Error:', err);
                contentArea.innerHTML = 'Radio silence from the Captain. Please check your internet connection and try again.';
            } finally {
                submitBtn.textContent = 'Ask AI Captain';
                submitBtn.disabled = false;
            }
        });

        // =============================
        // E. LIVING BACKGROUNDS
        // =============================
        function applyLivingBackground(weatherCondition) {
            // Remove all weather classes
            const weatherClasses = ['weather-sunny', 'weather-clear', 'weather-clouds', 'weather-rain', 'weather-drizzle', 'weather-thunderstorm', 'weather-storm', 'weather-sunset'];
            weatherClasses.forEach(cls => glassCard.classList.remove(cls));

            // Determine time of day for sunset detection
            const hour = new Date().getHours();
            const isSunset = (hour >= 17 && hour <= 19) || (hour >= 5 && hour <= 7);

            // Apply appropriate background class
            if (isSunset && (weatherCondition === 'clear' || weatherCondition === 'sunny')) {
                glassCard.classList.add('weather-sunset');
            } else {
                glassCard.classList.add(`weather-${weatherCondition}`);
            }

            console.log('Applied Living Background:', weatherCondition);
        }

        // =============================
        // F. SEASICKNESS GAUGE
        // =============================
        function showSeasicknessGauge(riskLevel) {
            gaugeContainer.style.display = 'block';

            const gaugeProgress = document.getElementById('das-gauge-progress');
            const gaugeValue = document.getElementById('das-gauge-value');
            const gaugeStatus = document.getElementById('das-gauge-status');

            // Arc length is approximately 251.2 units (half circle)
            const maxDash = 251.2;
            const offset = maxDash - (riskLevel / 100) * maxDash;

            // Animate gauge
            setTimeout(() => {
                gaugeProgress.style.strokeDashoffset = offset;
                gaugeValue.textContent = Math.round(riskLevel);

                // Update status text
                if (riskLevel < 20) {
                    gaugeStatus.textContent = 'CALM SEAS';
                } else if (riskLevel < 40) {
                    gaugeStatus.textContent = 'SMOOTH';
                } else if (riskLevel < 60) {
                    gaugeStatus.textContent = 'MODERATE';
                } else if (riskLevel < 80) {
                    gaugeStatus.textContent = 'CHOPPY';
                } else {
                    gaugeStatus.textContent = 'ROUGH';
                }
            }, 100);

            console.log('Seasickness Risk:', riskLevel);
        }

        // =============================
        // G. INTERACTIVE MAP (Leaflet.js)
        // =============================
        function showInteractiveMap(lat, lon, routeName) {
            mapContainer.style.display = 'block';
            document.getElementById('das-map-title').textContent = `📍 ${routeName}`;

            // Initialize map (destroy previous if exists)
            if (currentMap) {
                currentMap.remove();
            }

            // Create map centered on coordinates
            setTimeout(() => {
                currentMap = L.map('das-map', {
                    center: [lat, lon],
                    zoom: 13,
                    zoomControl: true,
                    scrollWheelZoom: false
                });

                // Add tile layer (OpenStreetMap)
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                    maxZoom: 19
                }).addTo(currentMap);

                // Add marker with custom popup
                const marker = L.marker([lat, lon]).addTo(currentMap);
                marker.bindPopup(`
                    <div style="text-align:center; padding:8px;">
                        <strong style="color:#00d2ff; font-size:1.1rem;">⚓ ${routeName}</strong><br>
                        <span style="font-size:0.85rem; opacity:0.8;">Lat: ${lat.toFixed(4)}, Lon: ${lon.toFixed(4)}</span>
                    </div>
                `).openPopup();

                // Add circle to show general area
                L.circle([lat, lon], {
                    color: '#00d2ff',
                    fillColor: '#00d2ff',
                    fillOpacity: 0.1,
                    radius: 500
                }).addTo(currentMap);

                console.log('Map initialized:', routeName, lat, lon);
            }, 200); // Small delay to ensure container is visible
        }

        // Close map button
        document.getElementById('das-map-close')?.addEventListener('click', () => {
            mapContainer.style.display = 'none';
            if (currentMap) {
                currentMap.remove();
                currentMap = null;
            }
        });
    }
});
