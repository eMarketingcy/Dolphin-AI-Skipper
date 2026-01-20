/**
 * Dolphin AI Skipper - Main Script
 * Handles: Admin API Testing, Frontend Widget Toggle, AJAX Requests, Date Validation
 * Tech: Vanilla JS (No jQuery), Fetch API
 */

document.addEventListener('DOMContentLoaded', () => {
    
    // =========================================================
    // PART 1: ADMIN PANEL LOGIC 
    // (Only executes if the user is on the Settings Page)
    // =========================================================
    const adminTestBtn = document.getElementById('das-test-btn');
    
    if (adminTestBtn) {
        // Listen for the "Test Connections" button click
        adminTestBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            
            const resultDiv = document.getElementById('das-test-result');
            const owmKeyInput = document.getElementById('das_owm_key');
            const geminiKeyInput = document.getElementById('das_gemini_key');

            // Simple validation
            if(!owmKeyInput.value || !geminiKeyInput.value) {
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
                // Send Request
                const response = await fetch(das_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                // Parse JSON
                const data = await response.json();
                
                if (data.success) {
                    // Display success/error messages line by line
                    resultDiv.innerHTML = data.data.messages.join('<br>');
                } else {
                    resultDiv.innerHTML = '<span class="das-error">Server returned an error.</span>';
                }
            } catch (err) {
                console.error('DAS Admin Error:', err);
                resultDiv.innerHTML = '<span class="das-error">Connection Failed (AJAX Error).</span>';
            } finally {
                // Reset Button
                adminTestBtn.textContent = 'Test Connections';
                adminTestBtn.disabled = false;
            }
        });
    }

    // =========================================================
    // PART 2: FRONTEND WIDGET LOGIC 
    // (Only executes if the widget is present on the page)
    // =========================================================
    const toggleBtn = document.getElementById('das-widget-toggle');
    
    if (toggleBtn) {
        // Select DOM Elements
        const wrapper = document.getElementById('das-interface');
        const form = document.getElementById('das-form');
        const closeBtn = document.getElementById('das-close-btn');
        const resultArea = document.getElementById('das-result');
        const contentArea = resultArea.querySelector('.das-content');
        const submitBtn = form.querySelector('button[type="submit"]');
        const dateInput = document.getElementById('das-date');

        // --- A. DATE PICKER RESTRICTION ---
        // Prevent users from selecting past dates
        if(dateInput) {
            const now = new Date();
            // Adjust for timezone offset to get local ISO string correctly
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            dateInput.min = now.toISOString().slice(0, 16);
        }

        // --- B. TOGGLE VISIBILITY ---
        const toggleWidget = () => {
            wrapper.classList.toggle('is-open');
        };

        // Open/Close on button click
        toggleBtn.addEventListener('click', toggleWidget);
        closeBtn.addEventListener('click', toggleWidget);

        // Close when clicking OUTSIDE the card (Standard UX)
        document.addEventListener('click', (e) => {
            // If widget is open, AND click is NOT inside widget, AND click is NOT on toggle button
            if(wrapper.classList.contains('is-open') && 
               !wrapper.contains(e.target) && 
               !toggleBtn.contains(e.target)) {
                wrapper.classList.remove('is-open');
            }
        });

        // --- C. FORM SUBMISSION (The Brain) ---
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Get Input Values
            const routeId = document.getElementById('das-route').value;
            const dateVal = document.getElementById('das-date').value;

            // Validate
            if (!routeId || !dateVal) {
                alert('Please select both a destination and a date.');
                return;
            }

            // Set Loading UI
            submitBtn.textContent = 'Calculating optimal route...';
            submitBtn.disabled = true;
            resultArea.style.display = 'block';
            contentArea.innerHTML = '<span class="das-pulse">Captain is checking wind, waves, and alternative dates...</span>';

            // Prepare Data
            const formData = new FormData();
            formData.append('action', 'das_check_sailing');
            formData.append('route_id', routeId);
            formData.append('date', dateVal);
            formData.append('nonce', das_vars.nonce);

            try {
                // Send Request to WordPress
                const response = await fetch(das_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error('Network error');

                const data = await response.json();

                if (data.success) {
                    // Success: Inject AI Response (HTML)
                    contentArea.innerHTML = data.data.analysis;
                } else {
                    // Error: Show message from PHP
                    contentArea.innerHTML = `<span class="das-error">${data.data.message}</span>`;
                }

            } catch (err) {
                console.error('DAS Frontend Error:', err);
                contentArea.innerHTML = 'Radio silence from the Captain. Please check your internet connection and try again.';
            } finally {
                // Reset Button
                submitBtn.textContent = 'Ask AI Captain';
                submitBtn.disabled = false;
            }
        });
    }
});
