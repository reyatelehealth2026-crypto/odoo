/**
 * customer-churn.js — Page-scoped JS for Customer Churn Tracker dashboard
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.5 (Phase 3)
 * Loaded by: customer-churn.php <script src="assets/js/customer-churn.js">
 * Calls:     api/churn-dashboard-data.php?action={kpi|cohort|health|watchlist}
 *
 * Security: ALL user-derived data written via textContent (never innerHTML).
 * Polling:  KPI refresh every 60 s, gated by document.hidden.
 * Chart:    Chart.js cohort bar chart, dark theme (#080d18 body / #0f1829 cards).
 */

'use strict';

(function () {
  // ── Constants ──────────────────────────────────────────────────────────────
  const API_BASE      = 'api/churn-dashboard-data.php';
  const POLL_INTERVAL = 60_000; // ms — KPI live-refresh cadence

  /** Segment colour palette matching inbox-intelligence.html */
  const SEGMENT_COLORS = {
    'Champion':    '#16a34a',
    'Watchlist':   '#d97706',
    'At-Risk':     '#ea580c',
    'Lost':        '#dc2626',
    'Churned':     '#b91c1c',
    'Hibernating': '#6b7280',
  };

  /**
   * Maps segment name to the DOM element id holding its count.
   * The id suffix mirrors the PHP-rendered kpi-* ids in customer-churn.php.
   */
  const SEGMENT_ID_MAP = {
    'Champion':    'kpi-champion',
    'Watchlist':   'kpi-watchlist',
    'At-Risk':     'kpi-at_risk',
    'Lost':        'kpi-lost',
    'Churned':     'kpi-churned',
    'Hibernating': 'kpi-hibernating',
  };

  // ── State ──────────────────────────────────────────────────────────────────
  /** @type {import('chart.js').Chart|null} */
  let cohortChart  = null;
  let pollTimer    = null;
  let isRefreshing = false;

  // ── Fetch helper ───────────────────────────────────────────────────────────

  /**
   * Fetch JSON from the churn API.
   *
   * @param {string} action
   * @param {Record<string, string|number>} [params]
   * @returns {Promise<{success: boolean, data: unknown, error: string|null}>}
   */
  async function apiFetch(action, params = {}) {
    const url = new URL(API_BASE, window.location.href);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
      url.searchParams.set(k, String(v));
    }

    const resp = await fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!resp.ok) {
      throw new Error('HTTP ' + resp.status + ' from action=' + action);
    }

    return resp.json();
  }

  // ── KPI update helpers ─────────────────────────────────────────────────────

  /**
   * Update one KPI card counter using textContent (XSS-safe).
   *
   * @param {string} segment
   * @param {number} count
   */
  function updateKpiCard(segment, count) {
    const id = SEGMENT_ID_MAP[segment];
    if (!id) return;
    const el = document.getElementById(id);
    if (!el) return;
    // textContent only — never innerHTML for any server-derived value
    el.textContent = String(count);
    el.setAttribute('aria-label', segment + ' ' + String(count) + ' ราย');
  }

  /**
   * Refresh all 6 KPI cards from the API.
   * Also updates #last-updated timestamp via textContent.
   */
  async function refreshKpi() {
    if (isRefreshing) return;
    isRefreshing = true;
    setRefreshSpinner(true);

    try {
      const resp = await apiFetch('kpi');
      if (!resp.success || !resp.data) return;

      const { segments, last_computed_at } = /** @type {any} */ (resp.data);

      for (const [seg, count] of Object.entries(segments)) {
        updateKpiCard(seg, Number(count));
      }

      const lastEl = document.getElementById('last-updated');
      if (lastEl && last_computed_at) {
        lastEl.textContent = 'คำนวณล่าสุด: ' + String(last_computed_at);
      }
    } catch (_err) {
      // Silent on network failure — page still shows PHP-rendered server data.
    } finally {
      isRefreshing = false;
      setRefreshSpinner(false);
    }
  }

  function setRefreshSpinner(on) {
    const icon = document.getElementById('refresh-icon');
    if (!icon) return;
    if (on) {
      icon.classList.add('spinning');
    } else {
      icon.classList.remove('spinning');
    }
  }

  // ── Cohort retention chart ─────────────────────────────────────────────────

  /**
   * Build or update the Chart.js bar chart.
   * Dark-theme palette: body #080d18, cards #0f1829, grid #1e293b.
   *
   * @param {string[]} labels
   * @param {number[]} counts
   */
  function renderCohortChart(labels, counts) {
    const canvas = /** @type {HTMLCanvasElement|null} */ (document.getElementById('cohort-chart'));
    if (!canvas) return;

    const bgColors = labels.map(function (l) {
      return SEGMENT_COLORS[l] || '#94a3b8';
    });

    if (cohortChart) {
      cohortChart.data.labels                        = labels;
      cohortChart.data.datasets[0].data              = counts;
      cohortChart.data.datasets[0].backgroundColor   = bgColors;
      cohortChart.update('active');
      return;
    }

    cohortChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'จำนวนลูกค้า',
          data: counts,
          backgroundColor: bgColors,
          borderColor: bgColors.map(function (c) { return c + '99'; }),
          borderWidth: 1,
          borderRadius: 6,
          borderSkipped: false,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#ffffff',
            borderColor: '#e5e7eb',
            borderWidth: 1,
            titleColor: '#111827',
            bodyColor: '#374151',
            padding: 10,
            callbacks: {
              label: function (ctx) {
                return ' ' + String(ctx.parsed.y) + ' ราย';
              },
            },
          },
        },
        scales: {
          x: {
            grid: { color: 'rgba(229,231,235,0.5)' },
            ticks: { color: '#6b7280', font: { size: 11 } },
          },
          y: {
            grid: { color: 'rgba(229,231,235,0.5)' },
            ticks: { color: '#6b7280', font: { size: 11 }, precision: 0 },
            beginAtZero: true,
          },
        },
      },
    });
  }

  /** Fetch cohort distribution and render chart. Falls back to __churnInitial. */
  async function loadCohort() {
    const loadingEl = document.getElementById('cohort-loading');
    if (loadingEl) loadingEl.style.display = 'block';

    try {
      const resp = await apiFetch('cohort');
      if (resp.success && resp.data) {
        const d = /** @type {any} */ (resp.data);
        renderCohortChart(d.labels, d.counts);
      }
    } catch (_err) {
      // Fallback: render from PHP-bootstrapped initial state.
      const initial = window['__churnInitial'];
      if (initial && initial.kpi) {
        renderCohortChart(Object.keys(initial.kpi), Object.values(initial.kpi));
      }
    } finally {
      if (loadingEl) loadingEl.style.display = 'none';
    }
  }

  // ── System health strip ────────────────────────────────────────────────────

  /** Refresh health mini-strip elements via textContent. */
  async function loadHealth() {
    try {
      const resp = await apiFetch('health');
      if (!resp.success || !resp.data) return;

      const d = /** @type {any} */ (resp.data);

      const elEligible = document.getElementById('health-eligible');
      if (elEligible) {
        elEligible.textContent = String(d.total_eligible ?? 0);
      }

      const elComputed = document.getElementById('health-computed');
      if (elComputed) {
        elComputed.textContent = d.last_computed_at ? String(d.last_computed_at) : '—';
      }

      const elGemini = document.getElementById('health-gemini');
      if (elGemini) {
        const calls = Number(d.gemini_calls_today ?? 0);
        const cap   = Number(d.gemini_daily_cap ?? 200);
        elGemini.textContent  = String(calls) + ' / ' + String(cap);
        elGemini.style.color  = (calls >= cap) ? '#f87171' : '#34d399';
      }
    } catch (_err) {
      // Silently ignore — health strip shows PHP-rendered defaults.
    }
  }

  // ── Watchlist client-side filter ───────────────────────────────────────────

  /**
   * Filter visible watchlist rows by segment without re-fetching.
   * Exposed on window.churnDashboard for inline onclick and tests.
   *
   * @param {HTMLElement} btn    — the clicked filter button
   * @param {string}      filter — segment name or 'all'
   */
  function filterTable(btn, filter) {
    // Update ARIA selected state on all filter buttons.
    document.querySelectorAll('.seg-filter-btn').forEach(function (b) {
      b.setAttribute('aria-selected', 'false');
    });
    btn.setAttribute('aria-selected', 'true');

    const tbody = document.getElementById('watchlist-tbody');
    if (!tbody) return;

    let visible = 0;
    tbody.querySelectorAll('tr[data-segment]').forEach(function (row) {
      const seg  = row.getAttribute('data-segment') || '';
      const show = (filter === 'all' || seg === filter);
      /** @type {HTMLElement} */ (row).style.display = show ? '' : 'none';
      if (show) visible++;
    });

    const countEl = document.getElementById('watchlist-count');
    if (countEl) countEl.textContent = String(visible);
  }

  // ── Polling lifecycle ──────────────────────────────────────────────────────

  function startPolling() {
    if (pollTimer !== null) return; // guard double-start
    pollTimer = setInterval(function () {
      if (!document.hidden) {
        refreshKpi();
      }
    }, POLL_INTERVAL);
  }

  function stopPolling() {
    if (pollTimer !== null) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  // ── Event bindings ─────────────────────────────────────────────────────────

  function bindRefreshButton() {
    const btn = document.getElementById('refresh-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
      refreshKpi();
      loadHealth();
      loadCohort();
    });
  }

  function bindFilterButtons() {
    // Event delegation on document — works even if table is re-rendered later.
    document.addEventListener('click', function (e) {
      const btn = /** @type {HTMLElement} */ (e.target).closest('.seg-filter-btn');
      if (!btn) return;
      const filter = btn.getAttribute('data-filter') || 'all';
      filterTable(/** @type {HTMLElement} */ (btn), filter);
    });
  }

  function bindVisibilityChange() {
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        stopPolling();
      } else {
        // Immediately refresh on tab refocus, then restart polling.
        refreshKpi();
        startPolling();
      }
    });
  }

  // ── Public surface (used by inline onclick in PHP + PHPUnit tests) ─────────

  window['churnDashboard'] = {
    refreshKpi:  refreshKpi,
    loadCohort:  loadCohort,
    loadHealth:  loadHealth,
    filterTable: filterTable,
  };

  // ── Init ───────────────────────────────────────────────────────────────────

  function init() {
    bindRefreshButton();
    bindFilterButtons();
    bindVisibilityChange();
    wireAiBriefButtons();

    // Render chart immediately from PHP-bootstrapped data (avoids blank flash).
    const initial = window['__churnInitial'];
    if (initial && initial.kpi) {
      renderCohortChart(Object.keys(initial.kpi), Object.values(initial.kpi));
    }

    // Fetch fresh data from API asynchronously.
    loadCohort();
    loadHealth();

    // Begin 60-second KPI polling.
    startPolling();
  }

  // ── AI analyst-brief modal (admin-only, no customer push) ─────────────────
  /**
   * Wire .act-link-ai buttons to open modal + fetch internal analyst brief.
   * Endpoint: api/churn-talking-points.php (output is now an internal note,
   * NOT a customer-facing script — see TalkingPointsService.php).
   * @returns {void}
   */
  function wireAiBriefButtons() {
    const buttons = document.querySelectorAll('.act-link-ai');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        const partnerId = btn.getAttribute('data-partner-id');
        const storeName = btn.getAttribute('data-store-name') || '';
        if (!partnerId) return;
        openAiBriefModal(partnerId, storeName, btn);
      });
    });

    document.querySelectorAll('[data-close-ai-modal]').forEach(function (el) {
      el.addEventListener('click', closeAiBriefModal);
    });
    const overlay = document.getElementById('ai-brief-modal');
    if (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeAiBriefModal();
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeAiBriefModal();
    });
  }

  /**
   * @param {string} partnerId
   * @param {string} storeName
   * @param {HTMLButtonElement} sourceBtn
   * @returns {Promise<void>}
   */
  async function openAiBriefModal(partnerId, storeName, sourceBtn) {
    const overlay  = document.getElementById('ai-brief-modal');
    const subEl    = document.getElementById('ai-modal-sub');
    const bodyEl   = document.getElementById('ai-modal-body');
    const metaEl   = document.getElementById('ai-modal-meta');
    if (!overlay || !bodyEl) return;

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    if (subEl) subEl.textContent = storeName + ' (Partner #' + partnerId + ')';
    if (metaEl) metaEl.style.display = 'none';
    renderAiLoading(bodyEl);

    if (sourceBtn) sourceBtn.disabled = true;
    try {
      const r = await fetch('api/churn-talking-points.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ partner_id: Number(partnerId) }),
      });
      const json = await r.json();
      if (!json || !json.success || !json.data) {
        renderAiError(bodyEl, (json && json.error) || 'ไม่สามารถโหลดบันทึกวิเคราะห์ได้');
        return;
      }
      // API envelope: { success, data: <payload-fields-flat>, cached, tokens_used, error }
      // Pass the full envelope so renderAiBrief can read both payload + meta.
      renderAiBrief(json, metaEl);
    } catch (err) {
      renderAiError(bodyEl, 'Network error — ลองใหม่อีกครั้ง');
    } finally {
      if (sourceBtn) sourceBtn.disabled = false;
    }
  }

  function closeAiBriefModal() {
    const overlay = document.getElementById('ai-brief-modal');
    if (!overlay) return;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
  }

  /**
   * Build a DOM element via createElement + textContent (XSS-safe by construction).
   * @param {string} tag
   * @param {{class?: string, text?: string, attrs?: Object<string,string>}} [opts]
   * @returns {HTMLElement}
   */
  function el(tag, opts) {
    const node = document.createElement(tag);
    if (opts) {
      if (opts.class) node.className = opts.class;
      if (opts.text != null) node.textContent = String(opts.text);
      if (opts.attrs) {
        Object.keys(opts.attrs).forEach(function (k) {
          node.setAttribute(k, String(opts.attrs[k]));
        });
      }
    }
    return node;
  }

  /** Replace all children of a node with the given new children. */
  function clearAndAppend(parent, children) {
    while (parent.firstChild) parent.removeChild(parent.firstChild);
    children.forEach(function (c) { if (c) parent.appendChild(c); });
  }

  /** Build a labelled section block. */
  function buildAiSection(label, contentNode) {
    const section = el('div', { class: 'ai-section' });
    section.appendChild(el('div', { class: 'ai-section-label', text: label }));
    if (contentNode) section.appendChild(contentNode);
    return section;
  }

  /** Build a plain-text content block. */
  function buildAiText(text) {
    return el('div', { class: 'ai-section-text', text: text || '—' });
  }

  /** Build a string list. Empty → italic dash. */
  function buildAiList(arr) {
    if (!Array.isArray(arr) || arr.length === 0) {
      const empty = el('div', { class: 'ai-section-text', text: '—' });
      empty.style.color = '#9ca3af';
      empty.style.fontStyle = 'italic';
      return empty;
    }
    const ul = el('ul', { class: 'ai-list' });
    arr.forEach(function (s) {
      ul.appendChild(el('li', { text: s }));
    });
    return ul;
  }

  /** Build the health-signals block with severity pills. */
  function buildAiSignals(arr) {
    const wrap = document.createDocumentFragment();
    (Array.isArray(arr) ? arr : []).forEach(function (sig) {
      const sev = String(sig.severity || 'medium').toLowerCase();
      const row = el('div', { class: 'ai-signal' });
      row.appendChild(el('span', { class: 'ai-signal-sev ' + sev, text: sev.toUpperCase() }));
      const body = el('div', { class: 'ai-signal-body' });
      body.appendChild(el('div', { class: 'ai-signal-label',  text: sig.label  || '' }));
      body.appendChild(el('div', { class: 'ai-signal-detail', text: sig.detail || '' }));
      row.appendChild(body);
      wrap.appendChild(row);
    });
    return wrap;
  }

  /** Build the recommended-actions block with priority pills. */
  function buildAiActions(arr) {
    const wrap = document.createDocumentFragment();
    (Array.isArray(arr) ? arr : []).forEach(function (act) {
      const prio = String(act.priority || 'P2').toUpperCase();
      const row = el('div', { class: 'ai-action' });
      row.appendChild(el('span', { class: 'ai-action-prio ' + prio, text: prio }));
      const body = el('div', { class: 'ai-action-body', text: act.action || '' });
      body.appendChild(el('div', { class: 'ai-action-owner', text: 'ผู้รับผิดชอบ: ' + (act.owner || '—') }));
      row.appendChild(body);
      wrap.appendChild(row);
    });
    return wrap;
  }

  /** Build the internal note + copy button block. */
  function buildAiNote(noteText) {
    const note = el('div', { class: 'ai-note', text: noteText || '—' });
    const copyWrap = el('div');
    copyWrap.style.marginTop  = '10px';
    copyWrap.style.textAlign  = 'right';
    const copyBtn = el('button', { class: 'ai-copy-btn', text: '📋 คัดลอกบันทึก', attrs: { type: 'button' } });
    copyBtn.addEventListener('click', function () {
      if (!navigator.clipboard || !navigator.clipboard.writeText) return;
      navigator.clipboard.writeText(noteText || '').then(function () {
        const old = copyBtn.textContent;
        copyBtn.textContent = '✓ คัดลอกแล้ว';
        setTimeout(function () { copyBtn.textContent = old; }, 1500);
      });
    });
    copyWrap.appendChild(copyBtn);
    note.appendChild(copyWrap);
    return note;
  }

  /**
   * Render the analyst brief into the modal body. Uses DOM construction +
   * textContent throughout — no innerHTML assignment, XSS-safe by design.
   *
   * Envelope shape from api/churn-talking-points.php:
   *   { success, data: <payload-fields-flat>, cached, tokens_used, error }
   *
   * @param {{success:boolean, data:Object, cached:boolean, tokens_used:number}} envelope
   * @param {HTMLElement|null} metaEl
   * @returns {void}
   */
  function renderAiBrief(envelope, metaEl) {
    const bodyEl = document.getElementById('ai-modal-body');
    if (!bodyEl) return;
    const p = (envelope && envelope.data) || {};
    const cached     = Boolean(envelope && envelope.cached);
    const tokensUsed = Number((envelope && envelope.tokens_used) || 0);

    clearAndAppend(bodyEl, [
      buildAiSection('📋 สรุปสถานการณ์',                    buildAiText(p.executive_summary)),
      buildAiSection('🩺 สัญญาณสุขภาพลูกค้า (RFM)',         buildAiSignals(p.health_signals)),
      buildAiSection('📊 พฤติกรรมการซื้อ',                  buildAiText(p.behavior_pattern)),
      buildAiSection('⚠️ สาเหตุ/ความเสี่ยงที่อาจหายไป',     buildAiList(p.risk_factors)),
      buildAiSection('💡 โอกาสที่เห็นจากข้อมูล',           buildAiList(p.opportunities)),
      buildAiSection('✅ การดำเนินการที่แนะนำ',             buildAiActions(p.recommended_actions)),
      buildAiSection('⚙️ ข้อจำกัดของข้อมูล',                buildAiList(p.data_quality_caveats)),
      buildAiSection('📝 บันทึกถึง Sales',                  buildAiNote(p.internal_note_for_sales)),
    ]);

    if (metaEl) {
      const cacheBadge  = document.getElementById('ai-meta-cache');
      const tokensBadge = document.getElementById('ai-meta-tokens');
      if (cacheBadge) {
        if (cached) {
          cacheBadge.textContent = 'จาก cache (TTL 24h)';
          cacheBadge.className   = 'badge badge-blue';
        } else {
          cacheBadge.textContent = 'สร้างใหม่ · gemini-flash-latest';
          cacheBadge.className   = 'badge badge-green';
        }
      }
      if (tokensBadge) {
        tokensBadge.textContent = tokensUsed > 0
          ? ('tokens ใช้ ' + tokensUsed)
          : (cached ? 'จาก cache' : 'fallback template');
        tokensBadge.className   = tokensUsed > 0
          ? 'badge badge-gray'
          : (cached ? 'badge badge-blue' : 'badge badge-amber');
      }
      metaEl.style.display = 'flex';
    }
  }

  /** Show inline loading spinner inside the modal body. */
  function renderAiLoading(bodyEl) {
    const wrap = el('div', { class: 'ai-loading' });
    const svgNS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('width', '16'); svg.setAttribute('height', '16');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '2');
    svg.setAttribute('class', 'spin-icon'); svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS(svgNS, 'path');
    path.setAttribute('d', 'M21 12a9 9 0 1 1-6.22-8.56');
    svg.appendChild(path);
    wrap.appendChild(svg);
    wrap.appendChild(el('span', { text: 'กำลังให้ AI วิเคราะห์ข้อมูลลูกค้า… (โดยปกติใช้เวลา 3-8 วินาที)' }));
    clearAndAppend(bodyEl, [wrap]);
  }

  /**
   * @param {HTMLElement} bodyEl
   * @param {string} message
   * @returns {void}
   */
  function renderAiError(bodyEl, message) {
    const errBox = el('div', { class: 'ai-error', text: '⚠️ ' + (message || 'เกิดข้อผิดพลาด') });
    clearAndAppend(bodyEl, [errBox]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
