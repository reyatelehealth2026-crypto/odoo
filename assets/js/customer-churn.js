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
    'Champion':    '#34d399',
    'Watchlist':   '#fbbf24',
    'At-Risk':     '#fb923c',
    'Lost':        '#f87171',
    'Churned':     '#ef4444',
    'Hibernating': '#94a3b8',
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
            backgroundColor: '#0f1829',
            borderColor: '#1e293b',
            borderWidth: 1,
            titleColor: '#e2e8f0',
            bodyColor: '#94a3b8',
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
            grid: { color: 'rgba(30,41,59,0.5)' },
            ticks: { color: '#64748b', font: { size: 11 } },
          },
          y: {
            grid: { color: 'rgba(30,41,59,0.5)' },
            ticks: { color: '#64748b', font: { size: 11 }, precision: 0 },
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
