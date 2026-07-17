(function() {
    const chatMessages = document.getElementById('chatMessages');
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const voiceBtn = document.getElementById('voiceBtn');
    const voiceStatus = document.getElementById('voiceStatus');
    const suggestions = document.querySelectorAll('.suggestion-chip');
    const feedbackModal = document.getElementById('feedbackModal');
    const langToggle = document.getElementById('langToggle');
    const azureTtsToggle = document.getElementById('azureTtsToggle');
    let feedbackConversationId = null;
    let isProcessing = false;
    let recognition = null;
    let voiceOutputEnabled = true;
    let azureTtsEnabled = false;
    let lastInputWasVoice = false;
    let currentUtterance = null;
    let cachedVoices = [];
    let currentAudio = null;
    var i18n = window.AI_ASSISTANT_I18N || {};
    loadVoices();

    function loadVoices() {
        cachedVoices = window.speechSynthesis.getVoices();
        if (cachedVoices.length === 0) {
            window.speechSynthesis.onvoiceschanged = function() {
                cachedVoices = window.speechSynthesis.getVoices();
            };
        }
    }

    function detectLanguage(text) {
        const devanagari = text.match(/[\u0900-\u097F]/g);
        if (!devanagari) return 'en-IN';
        return (devanagari.length / text.length) > 0.5 ? 'hi-IN' : 'en-IN';
    }

    function findBestVoice(lang) {
        if (cachedVoices.length === 0) return null;
        var prefList = [
            { l: 'en-IN', n: 'Google' },
            { l: 'en-IN', n: 'Microsoft' },
            { l: 'en-IN', n: '' },
            { l: 'hi-IN', n: 'Google' },
            { l: 'hi-IN', n: 'Microsoft' },
            { l: 'hi-IN', n: '' },
            { l: 'en-US', n: 'Google' },
            { l: 'en-US', n: '' },
        ];
        for (var i = 0; i < prefList.length; i++) {
            var p = prefList[i];
            for (var j = 0; j < cachedVoices.length; j++) {
                var v = cachedVoices[j];
                if (v.lang.startsWith(p.l) && (!p.n || v.name.indexOf(p.n) !== -1)) {
                    return v;
                }
            }
        }
        for (var k = 0; k < cachedVoices.length; k++) {
            if (cachedVoices[k].lang.startsWith(lang)) return cachedVoices[k];
        }
        return null;
    }

    function speakText(text, isQuestion) {
        if (!voiceOutputEnabled || !text) return;
        stopSpeaking();
        if (azureTtsEnabled) {
            speakAzureTTS(text);
            return;
        }
        if (!window.speechSynthesis) return;
        var utterance = new SpeechSynthesisUtterance(text);
        var lang = detectLanguage(text);
        utterance.lang = lang;
        var voice = findBestVoice(lang);
        if (voice) utterance.voice = voice;
        utterance.rate = 0.9;
        utterance.pitch = isQuestion ? 1.05 : 0.95;
        utterance.volume = 1.0;
        currentUtterance = utterance;
        var lastMsg = chatMessages.querySelector('.chat-message.assistant:last-child');
        if (lastMsg) {
            var sb = lastMsg.querySelector('.speak-btn');
            if (sb) sb.classList.add('speaking');
        }
        utterance.onend = function() {
            currentUtterance = null;
            document.querySelectorAll('.speak-btn.speaking').forEach(function(b) { b.classList.remove('speaking'); });
        };
        utterance.onerror = function() {
            currentUtterance = null;
            document.querySelectorAll('.speak-btn.speaking').forEach(function(b) { b.classList.remove('speaking'); });
        };
        window.speechSynthesis.speak(utterance);
    }

    function speakAzureTTS(text) {
        var lastMsg = chatMessages.querySelector('.chat-message.assistant:last-child');
        if (lastMsg) {
            var sb = lastMsg.querySelector('.speak-btn');
            if (sb) sb.classList.add('speaking');
        }
        fetch('ai/tts/azure-tts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.audio_base64) {
                var audio = new Audio('data:audio/mpeg;base64,' + data.audio_base64);
                currentAudio = audio;
                audio.onended = function() {
                    currentAudio = null;
                    document.querySelectorAll('.speak-btn.speaking').forEach(function(b) { b.classList.remove('speaking'); });
                };
                audio.onerror = function() {
                    currentAudio = null;
                    document.querySelectorAll('.speak-btn.speaking').forEach(function(b) { b.classList.remove('speaking'); });
                };
                audio.play().catch(function() {
                    document.querySelectorAll('.speak-btn.speaking').forEach(function(b) { b.classList.remove('speaking'); });
                });
            } else {
                document.querySelectorAll('.speak-btn.speaking').forEach(function(b) { b.classList.remove('speaking'); });
            }
        })
        .catch(function() {
            document.querySelectorAll('.speak-btn.speaking').forEach(function(b) { b.classList.remove('speaking'); });
        });
    }

    function stopSpeaking() {
        if (window.speechSynthesis) window.speechSynthesis.cancel();
        if (currentAudio) { currentAudio.pause(); currentAudio = null; }
        currentUtterance = null;
        document.querySelectorAll('.speak-btn.speaking').forEach(function(b) { b.classList.remove('speaking'); });
    }

    function getSessionId() {
        let sid = sessionStorage.getItem('ai_session_id');
        if (!sid) {
            sid = 'ai_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
            sessionStorage.setItem('ai_session_id', sid);
        }
        return sid;
    }

    function addMessage(text, role, meta) {
        const div = document.createElement('div');
        div.className = 'chat-message ' + role;

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';
        bubble.textContent = text;
        div.appendChild(bubble);

        if (meta) {
            const metaDiv = document.createElement('div');
            metaDiv.className = 'message-meta';
            if (meta.agent) {
                const tag = document.createElement('span');
                tag.style.background = '#e2e8f0';
                tag.style.padding = '2px 8px';
                tag.style.borderRadius = '12px';
                tag.style.fontSize = '11px';
                tag.textContent = meta.agent;
                metaDiv.appendChild(tag);
            }
            if (meta.confidence) {
                const conf = document.createElement('span');
                conf.textContent = Math.round(meta.confidence * 100) + '% ' + (i18n.confidence || 'confident');
                metaDiv.appendChild(conf);
            }
            if (role === 'assistant' && meta.conversation_id) {
                const fbBtn = document.createElement('span');
                fbBtn.className = 'feedback-btn';
                fbBtn.textContent = i18n.rate_title || 'Rate';
                fbBtn.onclick = function() { openFeedback(meta.conversation_id); };
                metaDiv.appendChild(fbBtn);
            }
            div.appendChild(metaDiv);
        }

        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function addTypingIndicator() {
        const div = document.createElement('div');
        div.className = 'typing-indicator';
        div.id = 'typingIndicator';
        div.innerHTML = '<span></span><span></span><span></span>';
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function removeTypingIndicator() {
        const el = document.getElementById('typingIndicator');
        if (el) el.remove();
    }

    function el(tag, cls, children) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (typeof children === 'string') { e.textContent = children; }
        else if (children) { children.forEach(c => { if (c) e.appendChild(c); }); }
        return e;
    }

    function renderInventoryUI(d, aiText) {
        const c = el('div');
        if (d.stock_summary && d.stock_summary.length) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Stock by Gas Type')]));
            const row = el('div', 'tpl-row');
            d.stock_summary.forEach((s, i) => {
                const card = el('div', 'tpl-card accent-blue');
                card.appendChild(el('div', 'tpl-label', [document.createTextNode(s.gas_name)]));
                card.appendChild(el('div', 'tpl-value', [document.createTextNode(s.total)]));
                row.appendChild(card);
            });
            c.appendChild(row);
        }
        if (d.cylinder_status && d.cylinder_status.length) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Cylinder Status')]));
            const row = el('div', 'tpl-row');
            const statusMap = { filled: 'filled', empty: 'empty', under_maintenance: 'maintenance', with_customer: 'with-customer' };
            const accentMap = { filled: 'accent-green', empty: 'accent-blue', under_maintenance: 'accent-amber', with_customer: 'accent-purple' };
            d.cylinder_status.forEach(s => {
                const badgeCls = 'tpl-badge ' + (statusMap[s.status] || '');
                const card = el('div', 'tpl-card ' + (accentMap[s.status] || 'accent-blue'));
                card.appendChild(el('div', 'tpl-value', [document.createTextNode(s.count)]));
                card.appendChild(el('div', 'tpl-sub', [el('span', badgeCls, [document.createTextNode(s.status.replace(/_/g, ' '))])]));
                row.appendChild(card);
            });
            c.appendChild(row);
        }
        if (aiText) { c.appendChild(el('div', 'tpl-ai-text', [document.createTextNode(aiText)])); }
        return c;
    }

    function renderSalesUI(d, aiText) {
        const c = el('div');
        if (d.today) {
            const row = el('div', 'tpl-row');
            const t = d.today;
            const rev = el('div', 'tpl-card accent-green');
            rev.appendChild(el('div', 'tpl-label', [document.createTextNode('Revenue Today')]));
            rev.appendChild(el('div', 'tpl-value', [document.createTextNode('\u20B9' + Number(t.total_sales || 0).toLocaleString('en-IN'))]));
            row.appendChild(rev);
            const ord = el('div', 'tpl-card accent-blue');
            ord.appendChild(el('div', 'tpl-label', [document.createTextNode('Orders Today')]));
            ord.appendChild(el('div', 'tpl-value', [document.createTextNode(t.order_count)]));
            row.appendChild(ord);
            c.appendChild(row);
        }
        if (d.weekly && d.weekly.length) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Last 7 Days')]));
            const wrap = el('div', 'tpl-table-wrap');
            const table = el('table', 'tpl-table');
            const thead = el('thead');
            const hrow = el('tr');
            hrow.appendChild(el('th', null, [document.createTextNode('Day')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Revenue')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Orders')]));
            thead.appendChild(hrow);
            table.appendChild(thead);
            const tbody = el('tbody');
            d.weekly.forEach(w => {
                const r = el('tr');
                r.appendChild(el('td', null, [document.createTextNode(w.day?.slice(5) || w.day || '')]));
                r.appendChild(el('td', null, [document.createTextNode('\u20B9' + Number(w.daily_total || 0).toLocaleString('en-IN'))]));
                r.appendChild(el('td', null, [document.createTextNode(w.order_count)]));
                tbody.appendChild(r);
            });
            table.appendChild(tbody);
            wrap.appendChild(table);
            c.appendChild(wrap);
        }
        if (d.top_customers && d.top_customers.length) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Top Customers')]));
            const wrap = el('div', 'tpl-table-wrap');
            const table = el('table', 'tpl-table');
            const thead = el('thead');
            const hrow = el('tr');
            hrow.appendChild(el('th', null, [document.createTextNode('Name')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Mobile')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Total Spent')]));
            thead.appendChild(hrow);
            table.appendChild(thead);
            const tbody = el('tbody');
            d.top_customers.forEach(cust => {
                const r = el('tr');
                r.appendChild(el('td', null, [document.createTextNode(cust.name || '')]));
                r.appendChild(el('td', null, [document.createTextNode(cust.mobile || '')]));
                r.appendChild(el('td', null, [document.createTextNode('\u20B9' + Number(cust.total_spent || 0).toLocaleString('en-IN'))]));
                tbody.appendChild(r);
            });
            table.appendChild(tbody);
            wrap.appendChild(table);
            c.appendChild(wrap);
        }
        if (aiText) { c.appendChild(el('div', 'tpl-ai-text', [document.createTextNode(aiText)])); }
        return c;
    }

    function renderCustomerUI(d, aiText) {
        const c = el('div');
        if (d.customers && d.customers.length === 1 && d.customer_detail) {
            const cd = d.customer_detail;
            const card = el('div', 'tpl-profile-card');
            const header = el('div', 'tpl-profile-header');
            const initial = (cd.name || '?').charAt(0).toUpperCase();
            const avatar = el('div', 'tpl-profile-avatar', [document.createTextNode(initial)]);
            header.appendChild(avatar);
            const info = el('div', 'tpl-profile-info');
            info.appendChild(el('div', 'tpl-profile-name', [document.createTextNode(cd.name || '')]));
            info.appendChild(el('div', 'tpl-profile-sub', [document.createTextNode(cd.customer_type || 'Customer')]));
            header.appendChild(info);
            card.appendChild(header);
            const fields = [
                ['Mobile', cd.mobile],
                ['Email', cd.email],
                ['Address', cd.address],
                ['City', cd.city],
                ['State', cd.state],
            ];
            fields.forEach(f => {
                if (f[1]) {
                    const row = el('div', 'tpl-customer-field');
                    row.appendChild(el('span', 'tpl-field-label', [document.createTextNode(f[0])]));
                    row.appendChild(el('span', 'tpl-field-value', [document.createTextNode(f[1])]));
                    card.appendChild(row);
                }
            });
            const badgeRow = el('div', 'tpl-profile-badges');
            badgeRow.appendChild(el('span', 'tpl-badge ' + (cd.status === 'active' ? 'active' : 'inactive'), [document.createTextNode(cd.status || '')]));
            if (cd.deposit_balance !== undefined) {
                const dep = el('span');
                dep.style.cssText = 'font-size:12px;font-weight:600;color:#0f172a;background:#f1f5f9;padding:3px 10px;border-radius:8px;';
                dep.textContent = '\u20B9' + Number(cd.deposit_balance || 0).toLocaleString('en-IN') + ' deposit';
                badgeRow.appendChild(dep);
            }
            if (d.cylinder_count !== undefined) {
                const cnt = el('span');
                cnt.style.cssText = 'font-size:12px;font-weight:600;color:#0f172a;background:#f1f5f9;padding:3px 10px;border-radius:8px;';
                cnt.textContent = d.cylinder_count + ' cylinders';
                badgeRow.appendChild(cnt);
            }
            card.appendChild(badgeRow);
            c.appendChild(card);
        } else if (d.customers && d.customers.length > 1) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Matching Customers')]));
            const wrap = el('div', 'tpl-table-wrap');
            const table = el('table', 'tpl-table');
            const thead = el('thead');
            const hrow = el('tr');
            hrow.appendChild(el('th', null, [document.createTextNode('Name')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Mobile')]));
            hrow.appendChild(el('th', null, [document.createTextNode('City')]));
            thead.appendChild(hrow);
            table.appendChild(thead);
            const tbody = el('tbody');
            d.customers.forEach(cust => {
                const r = el('tr');
                r.appendChild(el('td', null, [document.createTextNode(cust.name || '')]));
                r.appendChild(el('td', null, [document.createTextNode(cust.mobile || '')]));
                r.appendChild(el('td', null, [document.createTextNode(cust.city || '')]));
                tbody.appendChild(r);
            });
            table.appendChild(tbody);
            wrap.appendChild(table);
            c.appendChild(wrap);
        }
        if (aiText) { c.appendChild(el('div', 'tpl-ai-text', [document.createTextNode(aiText)])); }
        return c;
    }

    function renderAnalyticsUI(d, aiText) {
        const c = el('div');
        const snap = d.snapshot || {};
        if (snap.sales || snap.cylinders || snap.customers) {
            const row = el('div', 'tpl-row');
            if (snap.sales) {
                const rev = el('div', 'tpl-card accent-green');
                rev.appendChild(el('div', 'tpl-label', [document.createTextNode('Revenue Today')]));
                rev.appendChild(el('div', 'tpl-value', [document.createTextNode('\u20B9' + Number(snap.sales.total_revenue || 0).toLocaleString('en-IN'))]));
                rev.appendChild(el('div', 'tpl-sub', [document.createTextNode(snap.sales.order_count + ' orders, ' + snap.sales.unique_customers + ' customers')]));
                row.appendChild(rev);
            }
            if (snap.cylinders) {
                const cyl = el('div', 'tpl-card accent-purple');
                cyl.appendChild(el('div', 'tpl-label', [document.createTextNode('Cylinders')]));
                cyl.appendChild(el('div', 'tpl-value', [document.createTextNode(snap.cylinders.total_cylinders)]));
                cyl.appendChild(el('div', 'tpl-sub', [document.createTextNode(snap.cylinders.filled + ' filled, ' + snap.cylinders.empty + ' empty')]));
                row.appendChild(cyl);
            }
            if (snap.customers) {
                const cust = el('div', 'tpl-card accent-cyan');
                cust.appendChild(el('div', 'tpl-label', [document.createTextNode('Customers')]));
                cust.appendChild(el('div', 'tpl-value', [document.createTextNode(snap.customers.total_customers)]));
                cust.appendChild(el('div', 'tpl-sub', [document.createTextNode(snap.customers.active_customers + ' active')]));
                row.appendChild(cust);
            }
            c.appendChild(row);
        }
        if (d.wow) {
            const w = d.wow;
            const row = el('div', 'tpl-row');
            const wow = el('div', 'tpl-card accent-blue');
            wow.appendChild(el('div', 'tpl-label', [document.createTextNode('Week over Week')]));
            wow.appendChild(el('div', 'tpl-value', [document.createTextNode('\u20B9' + Number(w.current_week_revenue || 0).toLocaleString('en-IN'))]));
            wow.appendChild(el('div', 'tpl-sub', [el('span', 'tpl-delta ' + (w.direction === 'up' ? 'up' : w.direction === 'down' ? 'down' : 'flat'), [document.createTextNode((w.revenue_growth_pct > 0 ? '+' : '') + (w.revenue_growth_pct || 0) + '%')])]));
            row.appendChild(wow);
            if (d.mom) {
                const m = d.mom;
                const mom = el('div', 'tpl-card accent-amber');
                mom.appendChild(el('div', 'tpl-label', [document.createTextNode('Month over Month')]));
                mom.appendChild(el('div', 'tpl-value', [document.createTextNode('\u20B9' + Number(m.current_month_revenue || 0).toLocaleString('en-IN'))]));
                mom.appendChild(el('div', 'tpl-sub', [el('span', 'tpl-delta ' + (m.direction === 'up' ? 'up' : m.direction === 'down' ? 'down' : 'flat'), [document.createTextNode((m.revenue_growth_pct > 0 ? '+' : '') + (m.revenue_growth_pct || 0) + '%')])]));
                row.appendChild(mom);
            }
            c.appendChild(row);
        }
        if (snap.low_stock && snap.low_stock.length) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Low Stock Alerts')]));
            const row = el('div', 'tpl-row');
            snap.low_stock.forEach(ls => {
                const card = el('div', 'tpl-card accent-red');
                card.appendChild(el('div', 'tpl-value', [document.createTextNode(ls.gas_name || '')]));
                card.appendChild(el('div', 'tpl-sub', [document.createTextNode(ls.total_stock + ' left (min ' + ls.min_alert_threshold + ')')]));
                row.appendChild(card);
            });
            c.appendChild(row);
        }
        if (d.top_customers && d.top_customers.length) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Top Customers (90 days)')]));
            const wrap = el('div', 'tpl-table-wrap');
            const table = el('table', 'tpl-table');
            const thead = el('thead');
            const hrow = el('tr');
            hrow.appendChild(el('th', null, [document.createTextNode('Name')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Spent')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Orders')]));
            thead.appendChild(hrow);
            table.appendChild(thead);
            const tbody = el('tbody');
            d.top_customers.forEach(cust => {
                const r = el('tr');
                r.appendChild(el('td', null, [document.createTextNode(cust.name || '')]));
                r.appendChild(el('td', null, [document.createTextNode('\u20B9' + Number(cust.total_spent || 0).toLocaleString('en-IN'))]));
                r.appendChild(el('td', null, [document.createTextNode(cust.order_count)]));
                tbody.appendChild(r);
            });
            table.appendChild(tbody);
            wrap.appendChild(table);
            c.appendChild(wrap);
        }
        if (d.forecast && d.forecast.forecast && d.forecast.forecast.length) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Revenue Forecast (7 days)')]));
            const row = el('div', 'tpl-row');
            const f = el('div', 'tpl-card accent-purple');
            f.appendChild(el('div', 'tpl-label', [document.createTextNode('Predicted')]));
            f.appendChild(el('div', 'tpl-value', [document.createTextNode('\u20B9' + Number(d.forecast.total_predicted_revenue || 0).toLocaleString('en-IN'))]));
            f.appendChild(el('div', 'tpl-sub', [document.createTextNode('Confidence: ' + (d.forecast.confidence_note || 'moderate'))]));
            row.appendChild(f);
            c.appendChild(row);
        }
        if (d.depletion && d.depletion.length) {
            c.appendChild(el('div', 'tpl-section-title', [document.createTextNode('Stock Depletion Watch')]));
            const wrap = el('div', 'tpl-table-wrap');
            const table = el('table', 'tpl-table');
            const thead = el('thead');
            const hrow = el('tr');
            hrow.appendChild(el('th', null, [document.createTextNode('Gas')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Stock')]));
            hrow.appendChild(el('th', null, [document.createTextNode('Days Left')]));
            thead.appendChild(hrow);
            table.appendChild(thead);
            const tbody = el('tbody');
            d.depletion.forEach(dp => {
                const r = el('tr');
                r.appendChild(el('td', null, [document.createTextNode(dp.gas_name || '')]));
                r.appendChild(el('td', null, [document.createTextNode(dp.current_stock)]));
                r.appendChild(el('td', null, [document.createTextNode(dp.days_until_empty !== null ? dp.days_until_empty + ' days' : 'N/A')]));
                tbody.appendChild(r);
            });
            table.appendChild(tbody);
            wrap.appendChild(table);
            c.appendChild(wrap);
        }
        if (aiText) { c.appendChild(el('div', 'tpl-ai-text', [document.createTextNode(aiText)])); }
        return c;
    }

    let chartInstances = {};

    function renderChartBlock(block) {
        const container = el('div', 'tpl-section');
        container.style.cssText = 'margin-bottom:12px;';
        const title = el('div', 'tpl-section-title', [document.createTextNode(block.title || 'Chart')]);
        container.appendChild(title);
        const canvasWrap = el('div');
        canvasWrap.style.cssText = 'position:relative;height:250px;background:#fff;border-radius:12px;border:1px solid #eef2f6;padding:12px;';
        const canvas = document.createElement('canvas');
        const chartId = 'chart_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6);
        canvas.id = chartId;
        canvasWrap.appendChild(canvas);
        container.appendChild(canvasWrap);

        setTimeout(function() {
            const ctx = canvas.getContext('2d');
            if (!ctx) return;
            if (chartInstances[chartId]) { chartInstances[chartId].destroy(); }
            const colors = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16','#14b8a6','#f97316'];
            const datasets = (block.datasets || []).map(function(ds, i) {
                const color = colors[i % colors.length];
                return {
                    label: ds.label || '',
                    data: ds.data || [],
                    backgroundColor: block.chart_type === 'pie' || block.chart_type === 'doughnut'
                        ? colors.slice(0, (ds.data || []).length).map(function(_, j) { return colors[j % colors.length]; })
                        : color + '33',
                    borderColor: color,
                    borderWidth: 2,
                    tension: 0.3,
                    fill: block.chart_type !== 'line' ? false : true,
                };
            });
            try {
                chartInstances[chartId] = new Chart(ctx, {
                    type: block.chart_type || 'bar',
                    data: { labels: block.labels || [], datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: datasets.length > 1, position: 'bottom', labels: { boxWidth: 12, padding: 12, font: { size: 11 } } },
                        },
                        scales: (block.chart_type === 'pie' || block.chart_type === 'doughnut') ? {} : {
                            y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 } } },
                            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                        },
                    },
                });
            } catch(e) { console.error('Chart render error:', e); }
        }, 100);
        return container;
    }

    function renderTableBlock(block) {
        const container = el('div', 'tpl-section');
        if (block.title) {
            container.appendChild(el('div', 'tpl-section-title', [document.createTextNode(block.title)]));
        }
        const headers = block.headers || [];
        const rows = block.rows || [];
        if (rows.length === 0) {
            container.appendChild(el('div', null, [document.createTextNode('No data')]));
            return container;
        }
        const wrap = el('div', 'tpl-table-wrap');
        const table = el('table', 'tpl-table');
        const thead = el('thead');
        const hrow = el('tr');
        headers.forEach(function(h) {
            hrow.appendChild(el('th', null, [document.createTextNode(h)]));
        });
        thead.appendChild(hrow);
        table.appendChild(thead);
        const tbody = el('tbody');
        rows.forEach(function(row) {
            const r = el('tr');
            headers.forEach(function(h) {
                const key = h.toLowerCase().replace(/[^a-z0-9]/g, '_');
                let val = row[key] !== undefined ? row[key] : (row[h] !== undefined ? row[h] : '');
                if (val === null || val === undefined) val = '';
                r.appendChild(el('td', null, [document.createTextNode(String(val))]));
            });
            tbody.appendChild(r);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        container.appendChild(wrap);
        return container;
    }

    function renderProfileBlock(block) {
        const container = el('div', 'tpl-section');
        const card = el('div', 'tpl-profile-card');
        const fields = block.fields || {};
        const fieldKeys = Object.keys(fields);
        if (fieldKeys.length > 0) {
            const firstVal = fields[fieldKeys[0]];
            const header = el('div', 'tpl-profile-header');
            const initial = String(firstVal || '?').charAt(0).toUpperCase();
            header.appendChild(el('div', 'tpl-profile-avatar', [document.createTextNode(initial)]));
            const info = el('div', 'tpl-profile-info');
            info.appendChild(el('div', 'tpl-profile-name', [document.createTextNode(firstVal || '')]));
            if (block.title) {
                info.appendChild(el('div', 'tpl-profile-sub', [document.createTextNode(block.title)]));
            }
            header.appendChild(info);
            card.appendChild(header);

            fieldKeys.forEach(function(k, i) {
                if (i === 0) return;
                const val = fields[k];
                if (val) {
                    const row = el('div', 'tpl-customer-field');
                    row.appendChild(el('span', 'tpl-field-label', [document.createTextNode(k)]));
                    row.appendChild(el('span', 'tpl-field-value', [document.createTextNode(String(val))]));
                    card.appendChild(row);
                }
            });
        }
        if (block.badges && block.badges.length) {
            const badgeRow = el('div', 'tpl-profile-badges');
            block.badges.forEach(function(b) {
                badgeRow.appendChild(el('span', 'tpl-badge active', [document.createTextNode(b)]));
            });
            card.appendChild(badgeRow);
        }
        container.appendChild(card);
        return container;
    }

    function renderComparisonBlock(block) {
        const container = el('div', 'tpl-section');
        if (block.title) {
            container.appendChild(el('div', 'tpl-section-title', [document.createTextNode(block.title)]));
        }
        const headers = block.headers || ['Metric', 'Current', 'Previous', 'Change'];
        const rows = block.rows || [];
        const wrap = el('div', 'tpl-table-wrap');
        const table = el('table', 'tpl-table');
        const thead = el('thead');
        const hrow = el('tr');
        headers.forEach(function(h) { hrow.appendChild(el('th', null, [document.createTextNode(h)])); });
        thead.appendChild(hrow);
        table.appendChild(thead);
        const tbody = el('tbody');
        rows.forEach(function(row) {
            const r = el('tr');
            headers.forEach(function(h) {
                const key = h.toLowerCase().replace(/[^a-z0-9]/g, '_');
                let val = row[key] !== undefined ? row[key] : '';
                if (val === null || val === undefined) val = '';
                const td = el('td', null, [document.createTextNode(String(val))]);
                if (key === 'change' || h === 'Change') {
                    const s = String(val);
                    if (s.startsWith('+')) { td.style.color = '#166534'; td.style.fontWeight = '700'; }
                    else if (s.startsWith('-')) { td.style.color = '#dc2626'; td.style.fontWeight = '700'; }
                }
                r.appendChild(td);
            });
            tbody.appendChild(r);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        container.appendChild(wrap);
        return container;
    }

    function renderTimelineBlock(block) {
        const container = el('div', 'tpl-section');
        if (block.title) {
            container.appendChild(el('div', 'tpl-section-title', [document.createTextNode(block.title)]));
        }
        const events = block.events || [];
        events.forEach(function(ev, i) {
            const item = el('div');
            item.style.cssText = 'display:flex;gap:12px;padding:10px 0;border-left:2px solid #e2e8f0;margin-left:12px;padding-left:20px;position:relative;';
            const dot = el('div');
            dot.style.cssText = 'position:absolute;left:-7px;top:14px;width:12px;height:12px;border-radius:50%;background:' + (i === 0 ? '#6366f1' : '#cbd5e1') + ';border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.1);';
            item.appendChild(dot);
            const content = el('div');
            content.style.cssText = 'flex:1;';
            const dateEl = el('div');
            dateEl.style.cssText = 'font-size:11px;color:#94a3b8;font-weight:600;margin-bottom:2px;';
            dateEl.textContent = ev.date || '';
            content.appendChild(dateEl);
            const eventEl = el('div');
            eventEl.style.cssText = 'font-size:13px;font-weight:600;color:#0f172a;';
            eventEl.textContent = ev.event || '';
            content.appendChild(eventEl);
            if (ev.details) {
                const det = el('div');
                det.style.cssText = 'font-size:12px;color:#64748b;margin-top:2px;';
                det.textContent = ev.details;
                content.appendChild(det);
            }
            item.appendChild(content);
            container.appendChild(item);
        });
        return container;
    }

    function renderStatsBlock(block) {
        const container = el('div', 'tpl-section');
        const row = el('div', 'tpl-row');
        const items = block.items || [];
        items.forEach(function(item) {
            const card = el('div', 'tpl-card ' + (item.accent ? 'accent-' + item.accent : 'accent-blue'));
            card.appendChild(el('div', 'tpl-label', [document.createTextNode(item.label || '')]));
            card.appendChild(el('div', 'tpl-value', [document.createTextNode(String(item.value || ''))]));
            if (item.sub) {
                card.appendChild(el('div', 'tpl-sub', [document.createTextNode(item.sub)]));
            }
            row.appendChild(card);
        });
        container.appendChild(row);
        return container;
    }

    function renderInsightBlock(block) {
        const container = el('div', 'tpl-section');
        const styleMap = {
            info: { bg: '#eff6ff', color: '#1e40af', border: '#bfdbfe' },
            warning: { bg: '#fffbeb', color: '#92400e', border: '#fde68a' },
            success: { bg: '#f0fdf4', color: '#166534', border: '#bbf7d0' },
            danger: { bg: '#fef2f2', color: '#dc2626', border: '#fecaca' },
        };
        const style = styleMap[block.style] || styleMap.info;
        const alert = el('div');
        alert.style.cssText = 'padding:12px 16px;border-radius:12px;font-size:13px;font-weight:500;background:' + style.bg + ';color:' + style.color + ';border:1px solid ' + style.border + ';';
        alert.textContent = block.text || '';
        container.appendChild(alert);
        return container;
    }

    function renderResponseBlocks(blocks) {
        if (!blocks || !blocks.length) return null;
        const container = el('div');
        blocks.forEach(function(block) {
            var element = null;
            switch(block.type) {
                case 'chart': element = renderChartBlock(block); break;
                case 'table': element = renderTableBlock(block); break;
                case 'profile': element = renderProfileBlock(block); break;
                case 'comparison': element = renderComparisonBlock(block); break;
                case 'timeline': element = renderTimelineBlock(block); break;
                case 'stats': element = renderStatsBlock(block); break;
                case 'insight': element = renderInsightBlock(block); break;
            }
            if (element) container.appendChild(element);
        });
        return container;
    }

    function simpleMarkdown(text) {
        if (!text) return text;
        var s = String(text);
        s = s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
        s = s.replace(/`(.+?)`/g, '<code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px;">$1</code>');
        s = s.replace(/\n/g, '<br>');
        return s;
    }

    function updateFollowUpSuggestions(intent, data) {
        var suggestions = [];
        switch(intent) {
            case 'cylinder_tracking':
                suggestions = ['Show all filled cylinders', 'Which cylinders are with customers?', 'Show overdue hydrotest cylinders', 'Cylinder status breakdown'];
                break;
            case 'cylinder_inventory':
            case 'stock_inquiry':
                suggestions = ['Which gas types are low in stock?', 'Show inventory by gas type', 'How many cylinders are filled?', 'Show ownership breakdown'];
                break;
            case 'invoice_lookup':
                suggestions = ['Recent invoices', 'Show unpaid invoices', 'Search invoice by number', 'Invoice summary this month'];
                break;
            case 'customer_lookup':
                suggestions = ['Find another customer', 'Show top customers', 'New customers this month', 'Customer deposit summary'];
                break;
            case 'sales_analytics':
                suggestions = ['Compare week over week', 'Best selling gas types', 'Payment method breakdown', 'Monthly revenue trend'];
                break;
            case 'borrow_lent':
                suggestions = ['Partner exchange balance', 'Recent partner transactions', 'Which partner has most cylinders?', 'Show all partners'];
                break;
            case 'analytics':
                suggestions = ['Revenue forecast', 'Stock depletion watch', 'Compare month over month', 'Year over year comparison'];
                break;
            default:
                suggestions = ['Show Oxygen inventory', 'How were sales this week?', 'Find customer by mobile', 'Cylinder status summary'];
        }
        var chips = document.querySelectorAll('.suggestion-chip');
        chips.forEach(function(chip, i) {
            if (i < suggestions.length) {
                chip.textContent = suggestions[i];
                chip.dataset.text = suggestions[i];
                chip.dataset.index = i + 1;
                chip.style.display = '';
            }
        });
    }

    function downloadCSV(blocks) {
        if (!blocks || !blocks.length) return;
        var csvContent = '';
        blocks.forEach(function(block) {
            if (block.type === 'table') {
                var headers = block.headers || [];
                csvContent += '\n' + (block.title || 'Data') + '\n';
                csvContent += headers.join(',') + '\n';
                (block.rows || []).forEach(function(row) {
                    var vals = headers.map(function(h) {
                        var key = h.toLowerCase().replace(/[^a-z0-9]/g, '_');
                        var v = row[key] !== undefined ? row[key] : (row[h] || '');
                        return '"' + String(v).replace(/"/g, '""') + '"';
                    });
                    csvContent += vals.join(',') + '\n';
                });
            }
            if (block.type === 'comparison') {
                var headers = block.headers || [];
                csvContent += '\n' + (block.title || 'Comparison') + '\n';
                csvContent += headers.join(',') + '\n';
                (block.rows || []).forEach(function(row) {
                    var vals = headers.map(function(h) {
                        var key = h.toLowerCase().replace(/[^a-z0-9]/g, '_');
                        var v = row[key] !== undefined ? row[key] : '';
                        return '"' + String(v).replace(/"/g, '""') + '"';
                    });
                    csvContent += vals.join(',') + '\n';
                });
            }
        });
        if (!csvContent) return;
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'ai_report_' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    const intentIcons = {
        cylinder_tracking: '\u{1F4CD}',
        cylinder_inventory: '\u{1F4E6}',
        invoice_lookup: '\u{1F9FE}',
        inventory: '\u{1F4E6}',
        sales: '\u{1F4B5}',
        customer: '\u{1F465}',
        analytics: '\u{1F4CA}',
        general: '\u{1F4AC}',
        stock_inquiry: '\u{1F50D}',
        customer_lookup: '\u{1F50D}',
        sales_analytics: '\u{1F4C8}',
    };

    const confidenceLabels = {
        verified: '\u2705 Verified',
        inferred: '\u{1F50D} Inferred',
        reconstructed: '\u{1F527} Reconstructed',
        insufficient_data: '\u26A0\uFE0F Insufficient Data',
    };

    function accentForIntent(intent) {
        var map = { cylinder_tracking:'purple', invoice_lookup:'amber', cylinder_inventory:'blue', stock_inquiry:'green', customer_lookup:'cyan', sales_analytics:'pink', inventory:'blue', sales:'green', customer:'cyan', analytics:'purple', general:'blue' };
        return 'accent-' + (map[intent] || 'blue');
    }

    function addStructuredMessage(text, intent, data, meta, visualBlocks, options) {
        const div = el('div', 'chat-message assistant');
        const bubble = el('div', 'message-bubble');
        bubble.style.padding = '14px 18px';

        if (intent && intent !== 'general') {
            bubble.classList.add('intent-' + intent);
        }

        const headerRow = el('div');
        headerRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;';
        const iconSpan = el('span');
        iconSpan.style.cssText = 'font-size:16px;line-height:1;';
        iconSpan.textContent = intentIcons[intent] || '\u{1F4AC}';
        headerRow.appendChild(iconSpan);
        const label = document.createElement('span');
        label.style.cssText = 'font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#94a3b8;background:#f1f5f9;padding:3px 10px;border-radius:6px;';
        label.textContent = (intent || 'general').replace(/_/g, ' ');
        headerRow.appendChild(label);
        bubble.appendChild(headerRow);

        const textDiv = el('div');
        textDiv.style.cssText = 'font-size:14px;line-height:1.7;color:#0f172a;';
        textDiv.innerHTML = simpleMarkdown(text);
        bubble.appendChild(textDiv);

        var blocksContainer = null;

        if (visualBlocks && visualBlocks.length > 0) {
            blocksContainer = el('div');
            blocksContainer.style.cssText = 'margin-top:12px;';
            var renderedBlocks = renderResponseBlocks(visualBlocks);
            if (renderedBlocks) {
                blocksContainer.appendChild(renderedBlocks);
                bubble.appendChild(blocksContainer);
            }
        }

        if (options && options.length > 0) {
            var optContainer = el('div');
            optContainer.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;';
            options.forEach(function(opt, idx) {
                var chip = document.createElement('span');
                chip.className = 'option-chip';
                chip.textContent = (idx + 1) + '. ' + opt;
                chip.dataset.index = idx;
                chip.onclick = function() { sendMessage(opt); };
                optContainer.appendChild(chip);
            });
            bubble.appendChild(optContainer);
        }

        if (data && !blocksContainer) {
            var dataSection = el('div');
            dataSection.style.cssText = 'margin-top:12px;';
            var rendered = null;
            if (data.stock_summary || data.cylinder_status) {
                rendered = renderInventoryUI(data, null);
            } else if (data.today || data.weekly || data.top_customers) {
                rendered = renderSalesUI(data, null);
            } else if (data.customers) {
                rendered = renderCustomerUI(data, null);
            } else if (data.snapshot || data.wow || data.depletion) {
                rendered = renderAnalyticsUI(data, null);
            }
            if (rendered) {
                rendered.querySelectorAll('.tpl-card, .tpl-section, .tpl-table-wrap, .tpl-profile-card').forEach(function(e) { e.classList.add('tpl-section'); });
                dataSection.appendChild(rendered);
                bubble.appendChild(dataSection);
            }
        }

        div.appendChild(bubble);
        if (meta) {
            const metaDiv = el('div', 'message-meta');
            const leftGroup = document.createElement('span');
            leftGroup.style.cssText = 'display:flex;gap:6px;align-items:center;flex-wrap:wrap;';
            if (meta.agent) {
                const tag = document.createElement('span');
                tag.style.cssText = 'background:#f1f5f9;padding:3px 10px;border-radius:8px;font-size:10px;font-weight:600;color:#64748b;';
                tag.textContent = intentIcons[meta.agent] + ' ' + meta.agent.replace(/_/g, ' ');
                leftGroup.appendChild(tag);
            }
            var cfLevel = meta.confidence_level || 'insufficient_data';
            if (confidenceLabels[cfLevel]) {
                const badge = document.createElement('span');
                badge.style.cssText = 'padding:3px 10px;border-radius:8px;font-size:10px;font-weight:600;';
                if (cfLevel === 'verified') { badge.style.background = '#dcfce7'; badge.style.color = '#166534'; }
                else if (cfLevel === 'inferred') { badge.style.background = '#dbeafe'; badge.style.color = '#1e40af'; }
                else if (cfLevel === 'reconstructed') { badge.style.background = '#fef3c7'; badge.style.color = '#92400e'; }
                else { badge.style.background = '#f1f5f9'; badge.style.color = '#64748b'; }
                badge.textContent = confidenceLabels[cfLevel] || '\u26A0\uFE0F Unknown';
                leftGroup.appendChild(badge);
            }
            metaDiv.appendChild(leftGroup);
            if (meta.conversation_id) {
                const toolbar = document.createElement('span');
                toolbar.className = 'msg-toolbar';
                if (visualBlocks && visualBlocks.length) {
                    const csvBtn = document.createElement('button');
                    csvBtn.className = 'msg-action-btn';
                    csvBtn.innerHTML = '\u{1F4E5}';
                    csvBtn.title = 'Download CSV';
                    csvBtn.onclick = function(e) { e.stopPropagation(); downloadCSV(visualBlocks); };
                    toolbar.appendChild(csvBtn);
                }
                const spBtn = document.createElement('button');
                spBtn.className = 'msg-action-btn';
                spBtn.innerHTML = '\u{1F50A}';
                spBtn.title = 'Read aloud';
                spBtn.onclick = function(e) {
                    e.stopPropagation();
                    if (currentUtterance) { stopSpeaking(); }
                    else { speakText(text, false); }
                };
                toolbar.appendChild(spBtn);
                const fbBtn = document.createElement('button');
                fbBtn.className = 'msg-action-btn';
                fbBtn.innerHTML = '\u2B50';
                fbBtn.title = i18n.rate_title || 'Rate';
                fbBtn.onclick = function(e) { e.stopPropagation(); openFeedback(meta.conversation_id); };
                toolbar.appendChild(fbBtn);
                metaDiv.appendChild(toolbar);
            }
            div.appendChild(metaDiv);
        }
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function sendMessage(text) {
        // Voice command: detect numbered option selection
        var optMatch = text.toLowerCase().trim().match(/^(option|choose|select|pick|number|no\.?|#)\s*([1-9])$/i);
        if (!optMatch) optMatch = text.toLowerCase().trim().match(/^([1-4])$/);
        if (optMatch) {
            var optIndex = parseInt(optMatch[1] || optMatch[2]) - 1;
            var chips = document.querySelectorAll('.suggestion-chip');
            if (chips[optIndex] && chips[optIndex].style.display !== 'none') {
                text = chips[optIndex].dataset.text;
            }
        }
        if (isProcessing || !text.trim()) return;
        isProcessing = true;
        sendBtn.disabled = true;
        chatInput.parentElement.classList.remove('waiting');

        addMessage(text, 'user');
        chatInput.value = '';
        addTypingIndicator();
        var chipContainer = document.getElementById('suggestionChips');
        if (chipContainer) chipContainer.classList.add('processing');

        if (text.length < 1000 && typeof EventSource !== 'undefined') {
            sendMessageStream(text);
        } else {
            sendMessagePost(text);
        }
    }

    function sendMessagePost(text) {
        var lang = langToggle ? langToggle.value : 'hinglish';
        fetch('ai-chat-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: text, session_id: getSessionId(), language: lang })
        })
        .then(r => r.json())
        .then(data => {
            removeTypingIndicator();
            if (data.success) {
                const meta = {
                    agent: data.agent,
                    confidence: data.confidence,
                    conversation_id: data.conversation_id,
                    confidence_level: data.confidence_level
                };
                addStructuredMessage(data.message, data.intent, data.data, meta, data.visual_blocks, data.options);
                if (lastInputWasVoice) {
                    speakText(data.message, data.is_question);
                    lastInputWasVoice = false;
                }
                if (data.is_question) {
                    chatInput.parentElement.classList.add('waiting');
                    chatInput.focus();
                }
                updateFollowUpSuggestions(data.intent, data);
            } else {
                console.error('AI API error:', data.error);
                addMessage(data.error || (i18n.error || 'Something went wrong'), 'assistant');
            }
        })
        .catch(err => {
            removeTypingIndicator();
            addMessage(err.message || (i18n.error || 'Something went wrong'), 'assistant');
        })
        .finally(() => {
            isProcessing = false;
            sendBtn.disabled = false;
            var chipContainer = document.getElementById('suggestionChips');
            if (chipContainer) chipContainer.classList.remove('processing');
        });
    }

    function sendMessageStream(text) {
        var lang = langToggle ? langToggle.value : 'hinglish';
        var url = 'ai-stream.php?message=' + encodeURIComponent(text) + '&session_id=' + encodeURIComponent(getSessionId()) + '&language=' + encodeURIComponent(lang);
        var assistantMsg = null;
        var assistantBubble = null;
        var fullText = '';
        var visualBlocks = [];
        var options = [];
        var meta = {};
        var hadError = false;

        var es = new EventSource(url);

        es.addEventListener('token', function(e) {
            if (!assistantMsg) {
                removeTypingIndicator();
                var div = document.createElement('div');
                div.className = 'chat-message assistant';
                var bubble = document.createElement('div');
                bubble.className = 'message-bubble';
                div.appendChild(bubble);
                chatMessages.appendChild(div);
                assistantMsg = div;
                assistantBubble = bubble;
            }
            var token = e.data;
            fullText += token;
            assistantBubble.textContent = fullText;
            chatMessages.scrollTop = chatMessages.scrollHeight;
        });

        es.addEventListener('complete', function(e) {
            es.close();
            removeTypingIndicator();
            if (assistantMsg && assistantMsg.parentElement) {
                assistantMsg.parentElement.removeChild(assistantMsg);
            }
            try {
                var data = JSON.parse(e.data);
                meta = {
                    agent: data.agent,
                    confidence: data.confidence,
                    conversation_id: data.conversation_id,
                    confidence_level: data.confidence_level
                };
                visualBlocks = data.visual_blocks || [];
                options = data.options || [];

                addStructuredMessage(data.message, data.intent, data.data, meta, visualBlocks, options);

                if (lastInputWasVoice) {
                    speakText(data.message, data.is_question);
                    lastInputWasVoice = false;
                }
                if (data.is_question) {
                    chatInput.parentElement.classList.add('waiting');
                    chatInput.focus();
                }
                updateFollowUpSuggestions(data.intent, data);
            } catch (err) {
                if (!hadError) {
                    addStructuredMessage(fullText || '...', 'general', null, {}, [], []);
                    hadError = true;
                }
            }
            isProcessing = false;
            sendBtn.disabled = false;
            var chipContainer = document.getElementById('suggestionChips');
            if (chipContainer) chipContainer.classList.remove('processing');
        });

        es.addEventListener('error', function(e) {
            es.close();
            if (hadError) return;
            hadError = true;
            removeTypingIndicator();
            if (!assistantBubble) {
                addMessage(i18n.error || 'Something went wrong', 'assistant');
            }
            isProcessing = false;
            sendBtn.disabled = false;
            var chipContainer = document.getElementById('suggestionChips');
            if (chipContainer) chipContainer.classList.remove('processing');
        });
    }

    function openFeedback(conversationId) {
        feedbackConversationId = conversationId;
        document.querySelectorAll('.star').forEach(s => s.classList.remove('active'));
        document.getElementById('feedbackText').value = '';
        feedbackModal.style.display = 'flex';
    }

    if (chatInput) {
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') sendMessage(this.value);
        });

        sendBtn.addEventListener('click', function() {
            sendMessage(chatInput.value);
        });

        suggestions.forEach(chip => {
            chip.addEventListener('click', function() {
                sendMessage(this.dataset.text);
            });
        });
    }

    // Voice - Continuous conversation mode
    let voiceTimeout = null;
    let voiceActive = false;
    const VOICE_SILENCE_TIMEOUT = 30000; // 30s silence = auto-stop
    
    if (voiceBtn && (window.SpeechRecognition || window.webkitSpeechRecognition)) {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        recognition = new SpeechRecognition();
        recognition.lang = 'hi-IN';
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.maxAlternatives = 3;
        
        function stopVoice(keepInput) {
            voiceActive = false;
            voiceBtn.classList.remove('listening');
            voiceBtn.classList.remove('waveform');
            voiceStatus.style.display = 'none';
            var vi = document.getElementById('voiceInterim');
            if (vi) { vi.classList.remove('active'); vi.textContent = ''; }
            if (voiceTimeout) { clearTimeout(voiceTimeout); voiceTimeout = null; }
            try { recognition.stop(); } catch(e) {}
            if (!keepInput && chatInput && !chatInput.value.trim()) {
                chatInput.placeholder = (i18n.voice_not_supported || 'Voice not supported');
                setTimeout(function() {
                    if (chatInput) chatInput.placeholder = '';
                }, 2000);
            }
        }
        
        function resetVoiceTimeout() {
            if (voiceTimeout) clearTimeout(voiceTimeout);
            voiceTimeout = setTimeout(function() {
                // Auto-stop after silence
                if (voiceActive) {
                    var finalText = chatInput ? chatInput.value.trim() : '';
                    if (finalText) {
                        stopVoice(true);
                        sendMessage(finalText);
                    } else {
                        stopVoice(false);
                    }
                }
            }, VOICE_SILENCE_TIMEOUT);
        }
        
        recognition.onresult = function(event) {
            resetVoiceTimeout();
            var finalTranscript = '';
            var interimTranscript = '';
            
            for (var i = event.resultIndex; i < event.results.length; i++) {
                var result = event.results[i];
                if (result.isFinal) {
                    finalTranscript += result[0].transcript;
                } else {
                    interimTranscript += result[0].transcript;
                }
            }
            
            // Show interim text in input
            if (chatInput) {
                chatInput.value = (finalTranscript + interimTranscript).trim();
            }
            // Show interim indicator
            var voiceInterim = document.getElementById('voiceInterim');
            if (voiceInterim) {
                if (interimTranscript) {
                    voiceInterim.textContent = '🎤 ' + interimTranscript;
                    voiceInterim.classList.add('active');
                } else {
                    voiceInterim.classList.remove('active');
                }
            }
            
            // Check for stop commands in final transcript
            var lower = finalTranscript.toLowerCase().trim();
            var stopWords = ['stop', 'bye', 'thank you', 'that\'s all', 'bas', 'hat', 'band', 'rok', 'ruko', 'enough', 'that is all'];
            var shouldStop = false;
            for (var si = 0; si < stopWords.length; si++) {
                if (lower === stopWords[si] || lower.indexOf(stopWords[si] + ' ') === 0 || lower.indexOf(' ' + stopWords[si]) > -1) {
                    shouldStop = true;
                    break;
                }
            }
            
            if (shouldStop) {
                stopVoice(false);
                if (chatInput) chatInput.value = '';
                return;
            }
            
            // Auto-send if final transcript has meaningful length (not just noise)
            if (finalTranscript.trim().length >= 2) {
                lastInputWasVoice = true;
                // Keep mic active - don't auto-send, let user press Send or say option
                // But if it's a short command, auto-send
                if (finalTranscript.trim().length < 30 && !interimTranscript) {
                    stopVoice(true);
                    sendMessage(finalTranscript.trim());
                }
            }
        };
        
        recognition.onerror = function(event) {
            // Only stop on fatal errors
            if (event.error !== 'no-speech' && event.error !== 'aborted') {
                stopVoice(false);
            }
        };
        
        recognition.onend = function() {
            // Restart if still active (continuous mode was interrupted)
            if (voiceActive) {
                try {
                    recognition.start();
                } catch(e) {
                    voiceActive = false;
                    voiceBtn.classList.remove('listening');
                    voiceBtn.classList.remove('waveform');
                    voiceStatus.style.display = 'none';
                }
            }
        };
        
        voiceBtn.addEventListener('click', function() {
            if (voiceActive) {
                // Double-click = stop, or if has text = send
                if (chatInput && chatInput.value.trim()) {
                    var text = chatInput.value.trim();
                    stopVoice(true);
                    lastInputWasVoice = true;
                    sendMessage(text);
                } else {
                    stopVoice(false);
                }
                return;
            }
            
            // Start continuous voice
            try {
                voiceActive = true;
                voiceBtn.classList.add('listening');
                voiceBtn.classList.add('waveform');
                voiceStatus.style.display = 'block';
                if (chatInput) chatInput.value = '';
                recognition.start();
                resetVoiceTimeout();
            } catch(e) {
                voiceActive = false;
                voiceBtn.classList.remove('listening');
                voiceBtn.classList.remove('waveform');
                voiceStatus.style.display = 'none';
            }
        });
        
        // Keyboard shortcut: Space to toggle mic when input is focused
        if (chatInput) {
            chatInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && voiceActive) {
                    stopVoice(false);
                }
            });
        }
    } else if (voiceBtn) {
        voiceBtn.style.opacity = '0.4';
        voiceBtn.title = i18n.voice_not_supported || 'Voice not supported';
    }

    document.querySelectorAll('.star').forEach(star => {
        star.addEventListener('click', function() {
            const val = parseInt(this.dataset.value);
            document.querySelectorAll('.star').forEach(s => {
                s.classList.toggle('active', parseInt(s.dataset.value) <= val);
            });
        });
    });

    document.getElementById('submitFeedback')?.addEventListener('click', function() {
        const rating = document.querySelectorAll('.star.active').length;
        const text = document.getElementById('feedbackText').value;
        if (rating === 0) return;

        fetch('ai/feedback-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: feedbackConversationId,
                rating: rating,
                feedback_text: text
            })
        }).catch(() => {});

        feedbackModal.style.display = 'none';
        alert(i18n.feedback_thanks || 'Thank you for your feedback!');
    });

    document.getElementById('cancelFeedback')?.addEventListener('click', function() {
        feedbackModal.style.display = 'none';
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') stopSpeaking();
    });

    const voiceOutputToggle = document.getElementById('voiceOutputToggle');
    if (voiceOutputToggle) {
        voiceOutputToggle.addEventListener('click', function() {
            voiceOutputEnabled = !voiceOutputEnabled;
            this.classList.toggle('active');
            if (!voiceOutputEnabled) stopSpeaking();
        });
    }

    if (azureTtsToggle) {
        azureTtsToggle.addEventListener('click', function() {
            azureTtsEnabled = !azureTtsEnabled;
            this.classList.toggle('active');
            if (azureTtsEnabled) {
                voiceOutputEnabled = true;
                if (voiceOutputToggle) voiceOutputToggle.classList.add('active');
            }
        });
    }

    // Quick Actions
    document.querySelectorAll('.quick-action-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            var action = this.dataset.action;
            var messages = {
                inventory: 'Show current inventory stock levels',
                sales: 'How were sales today?',
                customers: 'Show me customer summary',
                cylinders: 'Show cylinder status breakdown',
                dashboard: 'Give me a business overview snapshot'
            };
            sendMessage(messages[action] || action);
        });
    });
})();