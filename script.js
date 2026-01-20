document.addEventListener('DOMContentLoaded', () => {
    
    const form = document.getElementById('das-form');
    const resultArea = document.getElementById('das-result');
    const contentArea = resultArea.querySelector('.das-content');
    const submitBtn = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const routeId = document.getElementById('das-route').value;
        const dateVal = document.getElementById('das-date').value;

        if (!routeId || !dateVal) {
            alert('Please select both a route and a date.');
            return;
        }

        // 1. UI Loading State
        submitBtn.textContent = 'Asking the Captain...';
        submitBtn.disabled = true;
        resultArea.style.display = 'block';
        contentArea.innerHTML = '<span class="das-pulse">Checking satellite weather data...</span>';

        // 2. Prepare Data (FormData is the modern way to handle POSTs)
        const formData = new FormData();
        formData.append('action', 'das_check_sailing');
        formData.append('route_id', routeId);
        formData.append('date', dateVal);
        formData.append('nonce', das_vars.nonce);

        try {
            // 3. The Fetch API (Modern replacement for $.ajax)
            const response = await fetch(das_vars.ajax_url, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) throw new Error('Network response was not ok');

            const data = await response.json();

            if (data.success) {
                // Success: Inject the HTML from the PHP response
                contentArea.innerHTML = data.data.analysis;
            } else {
                // Logic Error (e.g., missing coordinates)
                contentArea.innerHTML = `<span style="color:#ff6b6b">Error: ${data.data.message}</span>`;
            }

        } catch (error) {
            console.error('DAS Plugin Error:', error);
            contentArea.innerHTML = 'Communication error with the main server.';
        } finally {
            // 4. Reset UI
            submitBtn.textContent = 'Analyze Conditions';
            submitBtn.disabled = false;
        }
    });
});
