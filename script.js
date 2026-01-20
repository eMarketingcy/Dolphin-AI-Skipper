document.addEventListener('DOMContentLoaded', () => {
    
    // =========================================================
    // 1. ADMIN PANEL LOGIC (Only runs if on settings page)
    // =========================================================
    const testBtn = document.getElementById('das-test-btn');
    if (testBtn) {
        testBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const resultDiv = document.getElementById('das-test-result');
            const owmKey = document.getElementById('das_owm_key').value;
            const geminiKey = document.getElementById('das_gemini_key').value;

            if(!owmKey || !geminiKey) {
                resultDiv.innerHTML = '<span class="das-error">Please enter both keys before testing.</span>';
                return;
            }

            testBtn.textContent = 'Testing...';
            testBtn.disabled = true;
            resultDiv.innerHTML = 'Pinging Satellites...';

            const formData = new FormData();
            formData.append('action', 'das_test_apis');
            formData.append('owm_key', owmKey);
            formData.append('gemini_key', geminiKey);
            formData.append('nonce', das_vars.nonce);

            try {
                const response = await fetch(das_vars.ajax_url, { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    // Join array of messages with line breaks
                    resultDiv.innerHTML = data.data.messages.join('<br>');
                } else {
                    resultDiv.innerHTML = '<span class="das-error">Server Error.</span>';
                }
            } catch (err) {
                resultDiv.innerHTML = '<span class="das-error">AJAX Error.</span>';
            } finally {
                testBtn.textContent = 'Test API Connections';
                testBtn.disabled = false;
            }
        });
    }

    // =========================================================
    // 2. FRONTEND WIDGET LOGIC (Only runs if widget exists)
    // =========================================================
    const toggleBtn = document.getElementById('das-widget-toggle');
    if (toggleBtn) {
        const closeBtn = document.getElementById('das-close-btn');
        const wrapper = document.getElementById('das-interface');
        const form = document.getElementById('das-form');
        
        const toggleWidget = () => wrapper.classList.toggle('is-open');

        toggleBtn.addEventListener('click', toggleWidget);
        closeBtn.addEventListener('click', toggleWidget);
        document.addEventListener('click', (e) => {
            if (wrapper.classList.contains('is-open') && !wrapper.contains(e.target) && !toggleBtn.contains(e.target)) {
                wrapper.classList.remove('is-open');
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const routeId = document.getElementById('das-route').value;
            const dateVal = document.getElementById('das-date').value;
            const btn = form.querySelector('button');
            const content = document.querySelector('.das-content');
            const result = document.getElementById('das-result');

            if (!routeId || !dateVal) return;

            btn.textContent = 'Connecting...';
            btn.disabled = true;
            result.style.display = 'block';
            content.innerHTML = '<span class="das-pulse">Analyzing...</span>';

            const formData = new FormData();
            formData.append('action', 'das_check_sailing');
            formData.append('route_id', routeId);
            formData.append('date', dateVal);
            formData.append('nonce', das_vars.nonce);

            try {
                const response = await fetch(das_vars.ajax_url, { method: 'POST', body: formData });
                const data = await response.json();
                content.innerHTML = data.success ? data.data.analysis : data.data.message;
            } catch {
                content.innerHTML = 'Connection Error.';
            } finally {
                btn.textContent = 'Ask AI Captain';
                btn.disabled = false;
            }
        });
    }
});
