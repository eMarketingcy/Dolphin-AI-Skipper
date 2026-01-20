document.addEventListener('DOMContentLoaded', () => {
    
    // --- ADMIN TEST LOGIC ---
    const testBtn = document.getElementById('das-test-btn');
    if (testBtn) {
        testBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const resDiv = document.getElementById('das-test-result');
            const owm = document.getElementById('das_owm_key').value;
            const gem = document.getElementById('das_gemini_key').value;
            
            if(!owm || !gem) { resDiv.innerHTML = '<span class="das-error">Enter keys first.</span>'; return; }
            
            testBtn.innerText = 'Testing...';
            resDiv.innerHTML = 'Connecting...';
            
            const fd = new FormData();
            fd.append('action', 'das_test_apis');
            fd.append('owm_key', owm);
            fd.append('gemini_key', gem);
            fd.append('nonce', das_vars.nonce);
            
            try {
                const r = await fetch(das_vars.ajax_url, {method:'POST', body:fd});
                const d = await r.json();
                resDiv.innerHTML = d.success ? d.data.messages.join('<br>') : 'Server Error';
            } catch(err) {
                resDiv.innerHTML = '<span class="das-error">AJAX Error</span>';
            }
            testBtn.innerText = 'Test Connections';
        });
    }

    // --- FRONTEND WIDGET LOGIC ---
    const toggle = document.getElementById('das-widget-toggle');
    if (toggle) {
        const wrap = document.getElementById('das-interface');
        const form = document.getElementById('das-form');
        const close = document.getElementById('das-close-btn');
        
        const toggleFn = () => wrap.classList.toggle('is-open');
        toggle.addEventListener('click', toggleFn);
        close.addEventListener('click', toggleFn);
        
        document.addEventListener('click', (e) => {
            if(wrap.classList.contains('is-open') && !wrap.contains(e.target) && !toggle.contains(e.target)) {
                wrap.classList.remove('is-open');
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button');
            const out = document.querySelector('.das-content');
            const resArea = document.getElementById('das-result');
            
            const rId = document.getElementById('das-route').value;
            const dt = document.getElementById('das-date').value;
            if(!rId || !dt) return;

            btn.disabled = true; btn.innerText = 'Analyzing...';
            resArea.style.display = 'block';
            out.innerHTML = '<span class="das-pulse">Captain is checking the charts...</span>';

            const fd = new FormData();
            fd.append('action', 'das_check_sailing');
            fd.append('route_id', rId);
            fd.append('date', dt);
            fd.append('nonce', das_vars.nonce);

            try {
                const r = await fetch(das_vars.ajax_url, {method:'POST', body:fd});
                const d = await r.json();
                out.innerHTML = d.success ? d.data.analysis : d.data.message;
            } catch(err) {
                out.innerHTML = 'Connection failed.';
            }
            btn.disabled = false; btn.innerText = 'Ask AI Captain';
        });
    }
});
