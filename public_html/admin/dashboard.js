(function () {
  'use strict';

  var state = {
    data: null,
    charts: {},
    pollTimer: null,
    autoRefresh: true,
    pollInterval: 15000,
    isRefreshing: false,
    lastUpdate: null,
    userRole: null,
    dateRange: 'month',
    dateFrom: '',
    dateTo: '',
    forceFresh: true,
  };

  var CACHE_KEY = 'dash_cache_data';
  var LANG = {};
  try {
    var ls = document.getElementById('dashLangData');
    if (ls) LANG = JSON.parse(ls.textContent);
  } catch (e) {}

  function saveCache() {
    if (!state.data) return;
    try {
      var payload = { data: state.data, timestamp: Date.now(), dateRange: state.dateRange };
      localStorage.setItem(CACHE_KEY, JSON.stringify(payload));
    } catch (e) {}
  }

  function loadCache() {
    try {
      var raw = localStorage.getItem(CACHE_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) { return null; }
  }

  function $(id) { return document.getElementById(id); }

  function _(key, fallback) {
    var v = LANG[key];
    if (v && v !== key) return v;
    return fallback || key;
  }

  function el(tag, attrs, children) {
    var e = tag === 'svg' ? document.createElementNS('http://www.w3.org/2000/svg', tag) : document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'className') e.className = attrs[k];
      else if (k === 'style' && typeof attrs[k] === 'object')
        Object.keys(attrs[k]).forEach(function (sk) { e.style[sk] = attrs[k][sk]; });
      else if (k.startsWith('on')) e.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
      else e.setAttribute(k, attrs[k]);
    });
    if (children) {
      if (typeof children === 'string') e.innerHTML = children;
      else if (Array.isArray(children)) children.forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    }
    return e;
  }

  function fmtNum(n) { return Number(n).toLocaleString('en-IN'); }
  function fmtCurrency(n) { return (Number(n) < 0 ? '-\u20b9' : '\u20b9') + fmtNum(Math.abs(Number(n)).toFixed(0)); }
  function fmtTime(ts) {
    if (!ts) return '';
    var s = Math.floor((Date.now() - ts * 1000) / 1000);
    if (s < 5) return _('dash.just_now', 'just now');
    if (s < 60) return s + 's ago';
    var m = Math.floor(s / 60);
    if (m < 60) return m + 'm ago';
    var h = Math.floor(m / 60);
    if (h < 24) return h + 'h ago';
    return Math.floor(h / 24) + 'd ago';
  }
  function fmtDate(d) {
    var dt = new Date(d);
    return dt.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function prefersReducedMotion() { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

  function pctChange(val, prev) {
    if (prev > 0) return Math.round((val - prev) / prev * 100);
    if (val > 0) return 100;
    return 0;
  }

  function changeHtml(pct, suffix) {
    suffix = suffix || '';
    if (pct > 0) return '<span class="d-hero-change up"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:10px;height:10px;"><polyline points="18 15 12 9 6 15"/></svg>' + pct + '%' + suffix + '</span>';
    if (pct < 0) return '<span class="d-hero-change down"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:10px;height:10px;"><polyline points="6 9 12 15 18 9"/></svg>' + Math.abs(pct) + '%' + suffix + '</span>';
    return '<span class="d-hero-change neutral">\u2014</span>';
  }

  function destroyChart(key) {
    if (state.charts[key]) { state.charts[key].destroy(); delete state.charts[key]; }
  }

  function createChart(key, canvasId, config) {
    destroyChart(key);
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    try { state.charts[key] = new Chart(canvas, config); } catch(e) { console.error('Chart error ' + key, e); }
  }

  function defaultChartColors(count) {
    return ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#f97316','#ec4899','#6366f1','#14b8a6'];
  }

  function chartAnimation() {
    return prefersReducedMotion() ? false : { duration: 600 };
  }

  // ── Animated Counter ──
  function animateCount(elId, target, isCurrency, decimals) {
    var el = $(elId);
    if (!el) return;
    if (prefersReducedMotion()) {
      el.textContent = isCurrency ? fmtCurrency(target) : fmtNum(Math.round(target));
      return;
    }
    var start = performance.now();
    var duration = 800;
    function step(now) {
      var elapsed = now - start;
      var progress = Math.min(elapsed / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      var val = Math.round(target * eased);
      el.textContent = isCurrency ? (target < 0 ? '-\u20b9' : '\u20b9') + fmtNum(Math.abs(val)) : fmtNum(val);
      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  // ── Animate Progress Bar ──
  function animateBar(barId, target) {
    var bar = $(barId);
    if (!bar || isNaN(target)) return;
    setTimeout(function () { bar.style.width = target + '%'; }, 300);
  }

  // ── GST Credit/Payable Color Toggle ──
  function updateGstCreditColor(val) {
    var card = document.querySelector('[data-section="fin6"]');
    if (!card) return;
    var bar = card.querySelector('.fin-card-bar');
    var icon = card.querySelector('.fin-card-icon');
    if (!bar || !icon) return;
    if (val < 0) {
      bar.classList.remove('amber');
      bar.classList.add('green');
      icon.classList.remove('amber');
      icon.classList.add('green');
    } else {
      bar.classList.remove('green');
      bar.classList.add('amber');
      icon.classList.remove('green');
      icon.classList.add('amber');
    }
  }

  // ── Date Filter Helpers ──
  function buildFilterUrl() {
    var url = DASH_AJAX_URL + '?_t=' + Date.now();
    url += '&range=' + encodeURIComponent(state.dateRange);
    if (state.dateRange === 'custom' && state.dateFrom && state.dateTo) {
      url += '&from=' + encodeURIComponent(state.dateFrom);
      url += '&to=' + encodeURIComponent(state.dateTo);
    }
    if (state.forceFresh) {
      url += '&fresh=1';
      state.forceFresh = false;
    }
    return url;
  }

  function setDateFilter(range, from, to) {
    state.dateRange = range;
    state.dateFrom = from || '';
    state.dateTo = to || '';
    state.forceFresh = true;
    var pills = document.querySelectorAll('.d-fin-pill');
    pills.forEach(function (p) { p.classList.remove('active'); });
    var activePill = document.querySelector('.d-fin-pill[data-range="' + range + '"]');
    if (activePill) activePill.classList.add('active');
    var cd = document.getElementById('finCustomDates');
    if (cd) cd.style.display = (range === 'custom' ? 'flex' : 'none');
    fetchData();
  }

  // ── Data Fetching ──
  function fetchData() {
    if (state.isRefreshing) return;
    state.isRefreshing = true;

    fetch(buildFilterUrl(), { cache: 'no-store' })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        state.isRefreshing = false;
        state.data = data;
        showContent();
        if (data.success) { renderAll(data); saveCache(); }
        else showError(data.error || 'Failed to load dashboard data');
      })
      .catch(function (err) {
        state.isRefreshing = false;
        showError(_('dash.load_error', 'Could not load dashboard data. Check your connection.'));
        console.error('Dash fetch error', err);
      });
  }

  function showContent() {
    var skeleton = $('dashSkeleton');
    var content = $('dashContent');
    if (skeleton) skeleton.style.display = 'none';
    if (content) content.style.display = 'grid';
  }

  function showError(msg) {
    showContent();
    var content = $('dashContent');
    if (content) content.innerHTML = '<div class="dash-empty-state"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg><h3>Error</h3><p>' + msg + '</p><button onclick="location.reload()" class="dash-retry-btn">Retry</button></div>';
  }

  function startPolling() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    state.pollTimer = setInterval(function () { if (state.autoRefresh) fetchData(); }, state.pollInterval);
  }

  function toggleAutoRefresh() {
    state.autoRefresh = !state.autoRefresh;
    var dot = $('dashAutoDot');
    var btn = $('dashAutoRefreshBtn');
    if (dot) {
      dot.className = 'd-auto-dot' + (state.autoRefresh ? ' on' : ' off');
    }
    if (btn) {
      btn.style.opacity = state.autoRefresh ? '1' : '0.6';
    }
    if (state.autoRefresh) {
      fetchData();
    } else {
      saveCache();
    }
  }

  // ════════════════════════════════════════
  // RENDER ENGINE
  // ════════════════════════════════════════

  function renderAll(d) {
    updateTopbar(d);
    renderFinanceSummary(d);
    renderCylinderDonut(d);
    renderLifecycleChart(d);
    renderPartnerExchange(d);
    renderTimelineContainer(d);
    renderRevenueTrend(d);
    renderOperationsGrid(d);
    renderTopProducts(d);
    renderInventoryLevels(d);
    renderExpenseDonut(d);
    renderCashFlowChart(d);
    renderAlertChips(d);
    renderAiInsights(d);
    renderQuickActions();
  }

  // ═══ Top Bar ═══
  function updateTopbar(d) {
    var lu = $('dashLastUpdated');
    if (lu) lu.textContent = _('dash.updated', 'Updated') + ' ' + fmtTime(d.timestamp);
  }

  // ═══ P&L Financial Summary (8 Cards) ═══
  function renderFinanceSummary(d) {
    var fs = d.finance_summary || {};
    var p = fs.period || {};
    var meta = d.meta || {};

    var periodEl = $('finPeriodLabel');
    if (periodEl) periodEl.textContent = _('fin.period', 'Period') + ': ' + (p.label || meta.period_label || '');

    // ── Row 1: P&L Chain ──

    // 1. Total Revenue
    animateCount('finRevenue', fs.total_revenue ? fs.total_revenue.value : 0, true);
    var el = $('finRevenueChange');
    if (el) el.innerHTML = changeHtml(fs.total_revenue ? fs.total_revenue.change : 0, '%');

    // 2. COGS (Refill Costs)
    animateCount('finCOGS', fs.cogs ? fs.cogs.value : 0, true);
    el = $('finCOGSChange');
    if (el) el.innerHTML = changeHtml(fs.cogs ? fs.cogs.change : 0, '%');

    // 3. Gross Profit
    animateCount('finGrossProfit', fs.gross_profit ? fs.gross_profit.value : 0, true);
    el = $('finGrossProfitChange');
    if (el) el.innerHTML = changeHtml(fs.gross_profit ? fs.gross_profit.change : 0, '%');
    el = $('finMarginLabel');
    if (el) el.textContent = _('fin.gross_margin', 'Margin') + ': ' + (fs.gross_margin ? fs.gross_margin.value : 0) + '%';

    // 4. Operating Expenses
    animateCount('finOpEx', fs.operating_expenses ? fs.operating_expenses.value : 0, true);
    el = $('finOpExChange');
    if (el) el.innerHTML = changeHtml(fs.operating_expenses ? fs.operating_expenses.change : 0, '%');

    // ── Row 2: Bottom Line ──

    // 5. Net Profit
    animateCount('finNetProfit', fs.net_profit ? fs.net_profit.value : 0, true);
    el = $('finNetProfitChange');
    if (el) el.innerHTML = changeHtml(fs.net_profit ? fs.net_profit.change : 0, '%');

    // 6. GST Net Payable
    var gstVal = fs.gst_net ? fs.gst_net.value : 0;
    animateCount('finGstNet', gstVal, true);
    el = $('finGstNetChange');
    if (el) el.innerHTML = changeHtml(fs.gst_net ? fs.gst_net.change : 0, '%');
    updateGstCreditColor(gstVal);

    // 7. Profit After GST
    animateCount('finProfitAfterGst', fs.profit_after_gst ? fs.profit_after_gst.value : 0, true);
    el = $('finProfitAfterGstChange');
    if (el) el.innerHTML = changeHtml(fs.profit_after_gst ? fs.profit_after_gst.change : 0, '%');

    // 8. Cash Balance
    animateCount('finCashBalance', fs.cash_balance ? fs.cash_balance.value : 0, true);

    showContent();
  }

  // ═══ Cylinder Donut ═══
  function renderCylinderDonut(d) {
    var c = d.cylinders || {};
    var total = c.total || 0;

    var totalEl = $('cylDonutTotal');
    if (totalEl) totalEl.textContent = total;

    var legend = $('cylDonutLegend');
    if (legend) {
      var items = [
        { label: _('dash.filled', 'Filled'), value: c.filled, color: '#10b981' },
        { label: _('dash.empty', 'Empty'), value: c.empty, color: '#f59e0b' },
        { label: _('dash.with_customer', 'With Customer'), value: c.with_customer, color: '#8b5cf6' },
        { label: _('dash.lent', 'Lent'), value: c.lent_partner_count || c.lent_out, color: '#3b82f6' },
        { label: _('dash.borrowed', 'Borrowed'), value: c.borrowed_partner_count || c.borrowed, color: '#059669' },
      ];
      legend.innerHTML = '';
      items.forEach(function (item) {
        legend.innerHTML += '<div class="dist-item"><span class="dist-dot" style="background:' + item.color + ';"></span>' + item.label + '<strong class="dist-value">' + fmtNum(item.value || 0) + '</strong></div>';
      });
    }

    var canvas = $('cylinderChart');
    if (!canvas) return;
    var donutData = [
      { label: _('dash.filled', 'Filled'), value: c.filled || 0, color: '#10b981' },
      { label: _('dash.empty', 'Empty'), value: c.empty || 0, color: '#f59e0b' },
      { label: _('dash.with_customer', 'With Customer'), value: c.with_customer || 0, color: '#8b5cf6' },
      { label: _('dash.in_transit', 'In Transit'), value: (c.in_transit || 0) + (c.lent_out || 0), color: '#3b82f6' },
      { label: _('dash.maintenance', 'Maintenance'), value: c.under_maintenance || 0, color: '#ef4444' },
    ];
    var donutTotal = donutData.reduce(function (s, i) { return s + i.value; }, 0);
    if (donutTotal === 0) return;

    createChart('cylDonut', 'cylinderChart', {
      type: 'doughnut',
      data: {
        labels: donutData.map(function (i) { return i.label; }),
        datasets: [{
          data: donutData.map(function (i) { return i.value; }),
          backgroundColor: donutData.map(function (i) { return i.color; }),
          borderWidth: 0,
          hoverOffset: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        cutout: '78%',
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,0.9)',
            padding: 10,
            cornerRadius: 8,
            bodyFont: { size: 11 },
            callbacks: {
              label: function (ctx) {
                var pct = donutTotal > 0 ? Math.round(ctx.parsed / donutTotal * 100) : 0;
                return ctx.label + ': ' + fmtNum(ctx.parsed) + ' (' + pct + '%)';
              },
            },
          },
        },
        animation: prefersReducedMotion() ? false : { animateRotate: true, duration: 1200, easing: 'easeOutQuart' },
      },
    });
  }

  // ═══ Lifecycle Bar Chart ═══
  function renderLifecycleChart(d) {
    var canvas = $('lifecycleChart');
    if (!canvas) return;
    var byType = (d.cylinders && d.cylinders.by_type) || [];
    if (byType.length === 0) { destroyChart('lifecycle'); return; }

    var labels = byType.map(function (t) { return t.name || ''; });
    var filled = byType.map(function (t) { return parseInt(t.filled) || 0; });
    var withCust = byType.map(function (t) { return parseInt(t.with_customer) || 0; });
    var empty = byType.map(function (t) { return parseInt(t.empty) || 0; });
    var maint = byType.map(function (t) { return parseInt(t.maintenance) || 0; });
    var transit = byType.map(function (t) { return parseInt(t.in_transit) || 0; });

    // Update total hint
    var total = filled.reduce(function(s, v) { return s + v; }, 0)
      + withCust.reduce(function(s, v) { return s + v; }, 0)
      + empty.reduce(function(s, v) { return s + v; }, 0)
      + maint.reduce(function(s, v) { return s + v; }, 0)
      + transit.reduce(function(s, v) { return s + v; }, 0);
    var hint = $('lifecycleLegendHint');
    if (hint) hint.textContent = _('dash.total', 'Total') + ': ' + fmtNum(total) + ' ' + _('dash.cylinders', 'cylinders');

    createChart('lifecycle', 'lifecycleChart', {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          { label: _('dash.filled', 'Filled'), data: filled, backgroundColor: '#10b981', borderRadius: 3 },
          { label: _('dash.with_customer', 'With Customer'), data: withCust, backgroundColor: '#8b5cf6', borderRadius: 3 },
          { label: _('dash.empty', 'Empty'), data: empty, backgroundColor: '#f59e0b', borderRadius: 3 },
          { label: _('dash.maintenance', 'Maintenance'), data: maint, backgroundColor: '#ef4444', borderRadius: 3 },
          { label: _('dash.in_transit', 'In Transit'), data: transit, backgroundColor: '#3b82f6', borderRadius: 3 },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        scales: {
          x: { stacked: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 9 }, color: '#94a3b8' } },
          y: { stacked: true, grid: { display: false }, ticks: { font: { size: 9 }, color: '#64748b' } },
        },
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              boxWidth: 10,
              padding: 8,
              font: { size: 10, weight: '500' },
              usePointStyle: true,
              pointStyle: 'circle',
              color: '#64748b',
            },
          },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,0.9)', padding: 10, cornerRadius: 8,
            callbacks: {
              label: function (ctx) {
                return ctx.dataset.label + ': ' + fmtNum(ctx.parsed.x);
              },
            },
          },
        },
        animation: prefersReducedMotion() ? false : { duration: 800, easing: 'easeOutQuart' },
      },
    });
  }

  // ═══ Partner Exchange ═══
  function renderPartnerExchange(d) {
    var grid = $('partnerExchangeGrid');
    if (!grid) return;
    var c = d.cylinders || {};
    var overduePartners = c.overdue_partner_cylinders || 0;
    var rentPending = c.partner_rent_pending || 0;

    var html = '';
    html += '<div class="partner-metric"><span class="partner-metric-label"><span class="tag-own">OWN</span></span><span class="partner-metric-value">' + fmtNum(c.own_count || 0) + '</span></div>';
    html += '<div class="partner-metric"><span class="partner-metric-label"><span class="tag-br">BR</span></span><span class="partner-metric-value">' + fmtNum(c.borrowed_partner_count || 0) + '</span></div>';
    html += '<div class="partner-metric"><span class="partner-metric-label">Lent</span><span class="partner-metric-value">' + fmtNum(c.lent_partner_count || 0) + '</span></div>';
    html += '<div class="partner-metric"><span class="partner-metric-label"><span class="tag-con">CON</span></span><span class="partner-metric-value">' + fmtNum(c.consumer_owned_count || 0) + '</span></div>';
    html += '<div class="partner-metric"><span class="partner-metric-label"><span class="tag-ven">VEN</span></span><span class="partner-metric-value">' + fmtNum(c.vendor_borrowed_count || 0) + '</span></div>';
    if (rentPending > 0) {
      html += '<div class="partner-metric"><span class="partner-metric-label">Rent</span><span class="partner-metric-value warn">' + fmtNum(rentPending) + '</span></div>';
    }
    html += '<div class="partner-metric overdue"><span class="partner-metric-label">Overdue ' + (overduePartners > 0 ? '<span class="pulse-dot"></span>' : '') + '</span><span class="partner-metric-value danger">' + fmtNum(overduePartners) + '</span></div>';
    grid.innerHTML = html;
  }

  // ═══ Timeline ═══
  function renderTimelineContainer(d) {
    var container = $('dashTimelineContainer');
    if (!container) return;
    var items = d.activity || [];
    container.innerHTML = '';
    if (items.length === 0) { container.innerHTML = '<div style="text-align:center;color:var(--admin-muted);padding:2rem 0;font-size:0.85rem;">' + _('dash.no_recent', 'No recent activity') + '</div>'; return; }
    items.slice(0, 10).forEach(function (m) {
      var dotColor = '#64748b';
      var src = m.source || '';
      if (src === 'order') dotColor = '#3b82f6';
      else if (src === 'payment') dotColor = '#10b981';
      else if (src === 'cylinder') dotColor = '#8b5cf6';
      else if (src === 'customer') dotColor = '#f59e0b';
      else if (src === 'expense') dotColor = '#ef4444';

      container.appendChild(el('div', { className: 'dash-tl-item' }, [
        el('span', { className: 'dash-tl-dot', style: { color: dotColor, background: dotColor } }),
        el('div', { className: 'dash-tl-content' }, [
          el('div', { className: 'dash-tl-title' }, m.description || ''),
          el('div', { className: 'dash-tl-meta' }, (m.user_name || _('dash.system', 'System')) + ' \u00b7 ' + (m.status || '')),
          el('div', { className: 'dash-tl-date' }, m.ts ? fmtDate(m.ts) : ''),
        ]),
      ]));
    });
  }

  // ═══ Revenue Trend ═══
  function renderRevenueTrend(d) {
    var r = d.revenue || {};
    var days = r.trend_days || [];
    var values = r.trend_values || [];
    var orders = r.trend_orders || [];

    var meta = $('revenueMetaLine');
    if (meta && values.length > 0) {
      var total = values.reduce(function (a, b) { return a + b; }, 0);
      var activeDays = values.filter(function (v) { return v > 0; }).length;
      var avg = activeDays > 0 ? total / activeDays : 0;
      meta.textContent = '\u20b9' + fmtNum(total.toFixed(0)) + ' total \u00b7 avg \u20b9' + fmtNum((avg || 0).toFixed(0)) + '/day';
    }

    var canvas = $('revenueChart');
    if (!canvas || days.length === 0) return;
    var ctx = canvas.getContext('2d');
    var gradient = ctx.createLinearGradient(0, 0, 0, canvas.parentNode.offsetHeight || 200);
    gradient.addColorStop(0, 'rgba(37,99,235,0.18)');
    gradient.addColorStop(0.5, 'rgba(37,99,235,0.06)');
    gradient.addColorStop(1, 'rgba(37,99,235,0.01)');

    createChart('revenue', 'revenueChart', {
      type: 'line',
      data: {
        labels: days,
        datasets: [
          { label: _('dash.revenue', 'Revenue'), data: values, borderColor: '#2563eb', backgroundColor: gradient, fill: true, tension: 0.35, pointRadius: 0, pointHitRadius: 10, pointHoverRadius: 5, pointHoverBackgroundColor: '#2563eb', borderWidth: 2.5, yAxisID: 'y' },
          { label: _('dash.orders', 'Orders'), data: orders, borderColor: '#10b981', backgroundColor: 'transparent', borderDash: [4, 3], fill: false, tension: 0.3, pointRadius: 0, pointHitRadius: 8, borderWidth: 1.5, yAxisID: 'y1' },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,0.92)', padding: 10, cornerRadius: 8,
            titleFont: { size: 11, weight: '600' }, bodyFont: { size: 11 },
            callbacks: {
              label: function (ctx) {
                if (ctx.datasetIndex === 0) return _('dash.revenue', 'Revenue') + ': \u20b9' + fmtNum(Number(ctx.parsed.y).toFixed(0));
                return _('dash.orders', 'Orders') + ': ' + ctx.parsed.y;
              },
            },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 9 }, color: '#94a3b8', maxTicksLimit: 10, maxRotation: 0 } },
          y: { position: 'left', grid: { color: 'rgba(0,0,0,0.03)' }, ticks: { font: { size: 9 }, color: '#94a3b8', callback: function (v) { return '\u20b9' + fmtNum(v); } } },
          y1: { position: 'right', grid: { display: false }, ticks: { font: { size: 9 }, color: '#93c5fd' }, beginAtZero: true },
        },
        animation: chartAnimation(),
      },
    });
  }

  // ═══ Operations Grid ═══
  function renderOperationsGrid(d) {
    var container = $('dashOpsGrid');
    if (!container) return;
    container.innerHTML = '';

    var o = d.orders || {};
    var cu = d.customers || {};
    var v = d.vendors || {};

    var groups = [
      { title: _('dash.orders_title', 'Orders'), rows: [
        { label: _('dash.today', 'Today'),              value: fmtNum(o.today || 0), cls: '' },
        { label: _('dash.pending', 'Pending'),           value: fmtNum(o.pending || 0), cls: o.pending > 0 ? 'red' : '' },
        { label: _('dash.completed', 'Completed'),       value: fmtNum(o.completed || 0), cls: 'green' },
        { label: _('dash.refills', 'Refills'),           value: fmtNum(o.refills_today || 0), cls: o.refills_today > 0 ? 'green' : '' },
        { label: _('dash.exchanges', 'Exchanges'),       value: fmtNum(o.exchanges_today || 0), cls: o.exchanges_today > 0 ? 'green' : '' },
        { label: _('dash.rentals', 'Rentals'),           value: fmtNum(o.rentals_today || 0), cls: o.rentals_today > 0 ? 'green' : '' },
        { label: _('dash.deliveries', 'Deliveries'),     value: fmtNum(o.deliveries_today || 0), cls: o.deliveries_today > 0 ? 'green' : '' },
        { label: _('dash.returns', 'Returns'),           value: fmtNum(o.returns_today || 0), cls: o.returns_today > 0 ? 'green' : '' },
      ]},
      { title: _('dash.customers_title', 'Customers'), rows: [
        { label: _('dash.total', 'Total'), value: fmtNum(cu.total || 0), cls: '' },
        { label: _('dash.active', 'Active'), value: fmtNum(cu.active || 0), cls: 'green' },
        { label: _('dash.new_month', 'New This Month'), value: '+' + fmtNum(cu.new_this_month || 0), cls: cu.new_this_month > 0 ? 'green' : '' },
        { label: _('dash.inactive', 'Inactive (90d)'), value: fmtNum(cu.inactive_count || 0), cls: cu.inactive_count > 0 ? 'amber' : '' },
      ]},
      { title: _('dash.vendors_title', 'Vendors'), rows: [
        { label: _('dash.total', 'Total'), value: fmtNum(v.total || 0), cls: '' },
        { label: _('dash.active', 'Active'), value: fmtNum(v.active || 0), cls: 'green' },
        { label: _('dash.pending_lots', 'Pending Lots'), value: fmtNum(v.pending_purchases || 0), cls: v.pending_purchases > 0 ? 'amber' : '' },
        { label: _('dash.outstanding', 'Outstanding'), value: fmtCurrency(v.outstanding || 0), cls: v.outstanding > 0 ? 'red' : '' },
      ]},
    ];

    groups.forEach(function (g) {
      var group = el('div', { className: 'd-ops-group' }, [el('div', { className: 'd-ops-title' }, g.title)]);
      g.rows.forEach(function (r) {
        group.appendChild(el('div', { className: 'd-ops-row' }, [
          el('span', { className: 'd-ops-lbl' }, r.label),
          el('span', { className: 'd-ops-val ' + r.cls }, r.value),
        ]));
      });
      container.appendChild(group);
    });
  }

  // ═══ Top Products ═══
  function renderTopProducts(d) {
    var container = $('dashProductRanking');
    if (!container) return;
    var products = (d.revenue && d.revenue.by_product) || [];
    container.innerHTML = '';
    if (products.length === 0) {
      container.innerHTML = '<div class="d-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg><span>' + _('dash.no_products', 'No product data for this month') + '</span></div>';
      return;
    }
    var maxVal = Math.max.apply(null, products.map(function (p) { return parseFloat(p.revenue) || 0; }));
    var colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#f97316', '#ec4899'];
    var list = el('div', { className: 'd-prod-list' });
    products.slice(0, 8).forEach(function (p, i) {
      var pct = maxVal > 0 ? (parseFloat(p.revenue) / maxVal * 100) : 0;
      list.appendChild(el('div', { className: 'd-prod-row' }, [
        el('span', { className: 'd-prod-rank ' + (i === 0 ? 'gold' : i === 1 ? 'silver' : i === 2 ? 'bronze' : '') }, String(i + 1)),
        el('span', { className: 'd-prod-name' }, p.name || ''),
        el('div', { className: 'd-prod-bar' }, [el('div', { className: 'd-prod-fill', style: { width: pct + '%', background: colors[i % colors.length] } })]),
        el('span', { className: 'd-prod-val' }, fmtCurrency(p.revenue || 0)),
      ]));
    });
    container.appendChild(list);
  }

  // ═══ Inventory Levels ═══
  function renderInventoryLevels(d) {
    var container = $('dashInvContainer');
    if (!container) return;
    var inv = d.inventory || {};
    var items = inv.items || [];

    var html = '';
    if (items.length > 0) {
      html += '<div class="d-inv-grid">';
      items.forEach(function (item) {
        var total = (parseInt(item.filled_stock) || 0) + (parseInt(item.empty_stock) || 0);
        var pct = total > 0 ? Math.round((parseInt(item.filled_stock) || 0) / total * 100) : 0;
        var barColor = pct > 60 ? '#10b981' : pct > 30 ? '#f59e0b' : '#ef4444';
        html += '<div class="d-inv-item"><div class="d-inv-gas">' + (item.gas_name || '') + '</div><div class="d-inv-bar"><div class="d-inv-fill" style="width:' + pct + '%;background:' + barColor + '"></div></div><div class="d-inv-meta"><span>F: ' + (item.filled_stock || 0) + '</span><span>E: ' + (item.empty_stock || 0) + '</span></div></div>';
      });
      html += '</div>';
    } else {
      html = '<div class="d-empty" style="padding:16px 0;"><span>' + _('dash.no_inventory', 'No inventory data') + '</span></div>';
    }

    var lowList = inv.low_stock || [];
    var lowProds = inv.low_products || [];
    if (lowList.length > 0 || lowProds.length > 0) {
      html += '<div style="margin-top:10px;"><div style="font-size:11px;font-weight:700;color:#dc2626;margin-bottom:4px;">\u26a0 ' + _('dash.low_stock_alerts', 'Low Stock Alerts') + '</div>';
      lowList.concat(lowProds).forEach(function (item) {
        var name = item.gas_name || item.name || 'Unknown';
        var stock = item.filled_stock !== undefined ? item.filled_stock : (item.stock_quantity || 0);
        html += '<div class="d-low-row"><span>' + name + '</span><span>' + _('dash.stock', 'Stock') + ': ' + stock + '</span></div>';
      });
      html += '</div>';
    }

    if (d.inventory) {
      html += '<div class="d-stats-2col" style="margin-top:10px;">';
      html += '<div class="d-stat-mini"><div class="d-stat-mini-val">' + fmtNum(inv.incoming_today || 0) + '</div><div class="d-stat-mini-lbl">' + _('dash.incoming', 'Incoming Today') + '</div></div>';
      html += '<div class="d-stat-mini"><div class="d-stat-mini-val">' + fmtNum(inv.outgoing_today || 0) + '</div><div class="d-stat-mini-lbl">' + _('dash.outgoing', 'Outgoing Today') + '</div></div>';
      html += '</div>';
    }

    container.innerHTML = html;
  }

  // ═══ Expense Donut ═══
  function renderExpenseDonut(d) {
    var canvas = $('expenseDonutChart');
    if (!canvas) return;
    var categories = (d.financial && d.financial.expense_by_category) || [];
    if (categories.length === 0) { destroyChart('expDonut'); return; }

    var colors = defaultChartColors(8);
    var labels = categories.map(function (c) { return c.name || 'Other'; });
    var values = categories.map(function (c) { return parseFloat(c.total) || 0; });
    var total = values.reduce(function (s, v) { return s + v; }, 0);

    createChart('expDonut', 'expenseDonutChart', {
      type: 'doughnut',
      data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0, hoverOffset: 6 }] },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '58%',
        plugins: {
          legend: {
            position: 'right',
            labels: {
              boxWidth: 10, padding: 6, font: { size: 9, weight: '500' }, usePointStyle: true, pointStyle: 'circle',
              generateLabels: function (chart) {
                return chart.data.labels.map(function (l, i) {
                  var pct = total > 0 ? Math.round(values[i] / total * 100) : 0;
                  return { text: l + ': ' + pct + '%', fillStyle: chart.data.datasets[0].backgroundColor[i], strokeStyle: 'transparent', pointStyle: 'circle', index: i };
                });
              },
            },
          },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,0.92)', padding: 8, cornerRadius: 6, bodyFont: { size: 11 },
            callbacks: { label: function (ctx) { var pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0; return ctx.label + ': ' + fmtCurrency(ctx.parsed) + ' (' + pct + '%)'; } },
          },
        },
        animation: prefersReducedMotion() ? false : { animateRotate: true, duration: 600 },
      },
    });
  }

  // ═══ Cash Flow Chart ═══
  function renderCashFlowChart(d) {
    var canvas = $('cashFlowChart');
    if (!canvas) return;
    var flow = (d.financial && d.financial.cash_flow) || [];
    if (flow.length === 0) { destroyChart('cashFlow'); return; }

    var labels = flow.map(function (f) { return f.month || ''; });
    var inflow = flow.map(function (f) { return parseFloat(f.inflow) || 0; });
    var outflow = flow.map(function (f) { return parseFloat(f.outflow) || 0; });

    createChart('cashFlow', 'cashFlowChart', {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          { label: _('dash.inflow', 'Inflow'), data: inflow, backgroundColor: 'rgba(16,185,129,0.8)', borderColor: '#10b981', borderWidth: 1, borderRadius: 3 },
          { label: _('dash.outflow', 'Outflow'), data: outflow, backgroundColor: 'rgba(239,68,68,0.7)', borderColor: '#ef4444', borderWidth: 1, borderRadius: 3 },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 9 }, color: '#94a3b8' } },
          y: { grid: { color: 'rgba(0,0,0,0.03)' }, ticks: { font: { size: 9 }, color: '#94a3b8', callback: function (v) { return '\u20b9' + fmtNum(v); } }, beginAtZero: true },
        },
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 12, padding: 10, font: { size: 10, weight: '500' }, usePointStyle: true, pointStyle: 'rectRounded' } },
          tooltip: { backgroundColor: 'rgba(15,23,42,0.92)', padding: 8, cornerRadius: 6, bodyFont: { size: 11 }, callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + fmtCurrency(ctx.parsed.y); } } },
        },
        animation: chartAnimation(),
      },
    });
  }

  // ═══ Alert Chips ═══
  function renderAlertChips(d) {
    var container = $('dashAlertBar');
    if (!container) return;
    var alerts = d.alerts || [];
    var active = alerts.filter(function (a) { return a.count > 0; });
    container.innerHTML = '';
    if (active.length === 0) {
      container.innerHTML = '<span style="font-size:13px;color:#10b981;font-weight:600;">\u2705 ' + _('dash.all_clear', 'All clear') + ' \u2014 ' + _('dash.no_alerts', 'no active alerts') + '</span>';
      return;
    }
    active.forEach(function (a) {
      container.appendChild(el('a', { className: 'd-alert-chip ' + a.type, href: a.link, target: '_top' }, [
        el('span', { style: { fontWeight: 800 } }, String(a.count)),
        document.createTextNode(' ' + a.label),
      ]));
    });
  }

  // ═══ AI Insights ═══
  function renderAiInsights(d) {
    var container = $('aiInsightsContent');
    if (!container) return;
    var ai = d.ai || {};
    if (!ai.configured || !ai.snapshot) {
      container.innerHTML = '<div style="text-align:center;padding:1.5rem;color:var(--admin-muted);font-size:0.85rem;">' + _('dash.ai_no_config', 'AI Assistant not configured. Go to Settings > AI Config.') + '</div>';
      return;
    }
    var snap = ai.snapshot;
    var lowStock = snap.low_stock || [];

    var html = '';
    html += '<div style="padding:0.75rem 1rem;background:#f8fafc;border-radius:12px;"><div class="kpi-label" style="margin-bottom:0.3rem;font-size:0.6rem;">' + _('dash.monthly_revenue', 'Monthly Revenue') + '</div><div style="font-size:1.35rem;font-weight:800;">\u20b9' + fmtNum(Math.round((snap.sales && snap.sales.total_revenue) || 0)) + '</div><div class="kpi-label" style="margin-top:2px;font-size:0.68rem;">' + ((snap.sales && snap.sales.order_count) || 0) + ' orders</div></div>';
    html += '<div style="padding:0.75rem 1rem;background:#f8fafc;border-radius:12px;"><div class="kpi-label" style="margin-bottom:0.3rem;font-size:0.6rem;">' + _('dash.total_cylinders', 'Total Cylinders') + '</div><div style="font-size:1.35rem;font-weight:800;">' + ((snap.cylinders && snap.cylinders.total_cylinders) || 0) + '</div><div class="kpi-label" style="margin-top:2px;font-size:0.68rem;">' + ((snap.cylinders && snap.cylinders.filled) || 0) + ' filled \u00b7 ' + ((snap.cylinders && snap.cylinders.with_customer) || 0) + ' with customer</div></div>';
    html += '<div style="padding:0.75rem 1rem;background:#f8fafc;border-radius:12px;"><div class="kpi-label" style="margin-bottom:0.3rem;font-size:0.6rem;">' + _('dash.active_customers', 'Active Customers') + '</div><div style="font-size:1.35rem;font-weight:800;">' + ((snap.customers && snap.customers.active_customers) || 0) + ' / ' + ((snap.customers && snap.customers.total_customers) || 0) + '</div><div class="kpi-label" style="margin-top:2px;font-size:0.68rem;">active / total</div></div>';
    if (lowStock.length > 0) {
      html += '<div style="padding:0.75rem 1rem;background:#fef2f2;border-radius:12px;border-left:3px solid #dc2626;"><div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#dc2626;margin-bottom:0.3rem;">Low Stock</div><div style="font-size:1.35rem;font-weight:800;color:#dc2626;">' + lowStock.length + '</div><div class="kpi-label" style="margin-top:2px;font-size:0.68rem;">';
      lowStock.slice(0, 2).forEach(function (ls) { html += '<span>' + (ls.gas_name || '') + ' </span>'; });
      if (lowStock.length > 2) html += '+' + (lowStock.length - 2) + ' more';
      html += '</div></div>';
    }
    container.innerHTML = html;
  }

  // ═══ Quick Actions ═══
  function renderQuickActions() {
    var container = $('dashQuickActions');
    if (!container) return;
    container.innerHTML = '';

    var role = state.userRole;

    var allActions = [
      { href: 'customer-profile.php', icon: 'M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8.5 7a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM20 8v6M23 11h-6', color: '#10b981', label: _('dash.new_customer', 'New Customer'), roles: ['super_admin', 'warehouse_supervisor', 'billing_clerk'] },
      { href: 'order-create.php', icon: 'M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0', color: '#2563eb', label: _('dash.create_order', 'Create Order'), roles: ['super_admin', 'billing_clerk'] },
      { href: 'refill-orders.php', icon: 'M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z', color: '#f59e0b', label: _('dash.refill', 'Refill'), roles: ['super_admin', 'warehouse_supervisor', 'billing_clerk'] },
      { href: 'rent-cylinders.php', icon: 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z', color: '#8b5cf6', label: _('dash.rent', 'Rent'), roles: ['super_admin', 'warehouse_supervisor', 'billing_clerk'] },
      { href: 'cylinder-exchange.php', icon: 'M17 1l4 4-4 4M7 23l-4-4 4-4M3 5h14a4 4 0 0 1 4 4v4M21 15H7a4 4 0 0 1-4-4V7', color: '#06b6d4', label: _('dash.exchange', 'Exchange'), roles: ['super_admin', 'warehouse_supervisor', 'billing_clerk'] },
      { href: 'deposit-receipt.php', icon: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z', color: '#10b981', label: _('dash.receive_payment', 'Receive Payment'), roles: ['super_admin', 'billing_clerk'] },
      { href: 'expense-create.php', icon: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM16 2v6h6M16 13H8M16 17H8M10 9H8', color: '#f97316', label: _('dash.add_expense', 'Add Expense'), roles: ['super_admin', 'billing_clerk'] },
      { href: 'send-cylinder.php', icon: 'M22 2l-7 7M17 2v5h5M12 12l-7 7M7 12h5v5', color: '#3b82f6', label: _('dash.send_vendor', 'Send Vendor'), roles: ['super_admin', 'warehouse_supervisor'] },
      { href: 'receive-cylinder.php', icon: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3', color: '#8b5cf6', label: _('dash.receive_vendor', 'Receive Vendor'), roles: ['super_admin', 'warehouse_supervisor'] },
      { href: 'reports.php', icon: 'M18 20V10M12 20V4M6 20v-6', color: '#64748b', label: _('dash.reports', 'Reports'), roles: ['super_admin', 'warehouse_supervisor', 'billing_clerk', 'viewer'] },
    ];

    var visible = role ? allActions.filter(function (a) { return a.roles.indexOf(role) >= 0; }) : allActions;
    visible.forEach(function (a) {
      container.appendChild(el('a', { className: 'd-action-btn', href: a.href, style: { '--accent': a.color } }, [
        el('div', { className: 'd-ico-tray' }, [
          el('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: a.color, 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, a.icon),
        ]),
        el('span', null, a.label),
      ]));
    });
  }

  // ════════════════════════════════════════
  // INIT
  // ════════════════════════════════════════

  function init() {
    var refreshBtn = $('dashRefreshBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', function () { state.forceFresh = true; fetchData(); });

    var autoBtn = $('dashAutoRefreshBtn');
    if (autoBtn) autoBtn.addEventListener('click', toggleAutoRefresh);
    var autoDot = $('dashAutoDot');
    if (autoDot) autoDot.className = 'd-auto-dot on';

    var userRoleEl = document.getElementById('dashUserRole');
    if (userRoleEl) state.userRole = userRoleEl.textContent;

    // ── Date filter pills ──
    var filterTabs = $('finFilterTabs');
    if (filterTabs) {
      filterTabs.addEventListener('click', function (e) {
        var pill = e.target.closest('.d-fin-pill');
        if (!pill) return;
        var range = pill.getAttribute('data-range');
        if (!range) return;
        if (range === 'custom') {
          var cd = $('finCustomDates');
          if (cd) cd.style.display = 'flex';
          var activePill = document.querySelector('.d-fin-pill.active');
          if (activePill) activePill.classList.remove('active');
          pill.classList.add('active');
          return;
        }
        setDateFilter(range);
      });
    }

    // ── Custom date apply ──
    var applyBtn = $('finApplyCustom');
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        var from = $('finDateFrom');
        var to = $('finDateTo');
        if (from && to && from.value && to.value) {
          setDateFilter('custom', from.value, to.value);
        }
      });
    }

    function start() {
      var cached = loadCache();
      if (cached && cached.data && cached.data.success) {
        state.data = cached.data;
        showContent();
        renderAll(cached.data);
      }
      fetchData();
      startPolling();
      setInterval(function () {
        var lu = $('dashLastUpdated');
        if (lu && state.data) lu.textContent = _('dash.updated', 'Updated') + ' ' + fmtTime(state.data.timestamp);
      }, 10000);
    }

    if (typeof Chart !== 'undefined') { start(); }
    else {
      var ck = setInterval(function () {
        if (typeof Chart !== 'undefined') { clearInterval(ck); start(); }
      }, 100);
    }
  }

  if (document.readyState === 'complete') { init(); }
  else { document.addEventListener('DOMContentLoaded', init); }

})();
