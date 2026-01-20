document.addEventListener('DOMContentLoaded', () => {
    
    // Selectors
    const toggleBtn = document.getElementById('das-widget-toggle');
    const closeBtn = document.getElementById('das-close-btn');
    const wrapper = document.getElementById('das-interface');
    
    const form = document.getElementById('das-form');
    const resultArea = document.getElementById('das-result');
    const contentArea = resultArea.querySelector('.das-content');
    const submitBtn = form.querySelector('button[type="submit"]');

    // 1. Toggle Logic (Open/Close)
    function toggleWidget() {
        wrapper.classList.toggle('is-open');
    }

    toggleBtn.addEventListener('click', toggleWidget);
    closeBtn.addEventListener('click', toggleWidget);

    // Close if clicking outside the card
    document.addEventListener('click', (e) => {
        if (wrapper.classList.contains('is-open') && 
            !wrapper.contains(e.target) && 
            !toggleBtn.contains(e.target)) {
            wrapper.classList.remove('is-open');
        }
    });

    // 2. Form Logic (Same as before, updated for widget)
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const routeId = document.getElementById('das-route').value;
        const dateVal = document.getElementById('das-date').value;

        if (!routeId || !dateVal) {
            alert('Please select route & date.');
            return;
        }

        submitBtn.innerHTML = 'Connecting to Satellite...';
        submitBtn.disabled = true;
        resultArea.style.display = 'block';
        contentArea.innerHTML = '<span class="das-pulse">Analyzing wind & wave patterns...</span>';

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
                contentArea.innerHTML = data.data.analysis;
            } else {
                contentArea.innerHTML = `<span style="color:#ff6b6b">${data.data.message}</span>`;
            }

        } catch (error) {
            contentArea.innerHTML = 'Captain is currently offline. Please try again.';
        } finally {
            submitBtn.textContent = 'Ask AI Captain';
            submitBtn.disabled = false;
        }
    });
});
