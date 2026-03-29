/**
 * Inbox Intelligence Dashboard — v6
 * Clean rewrite. Reference: Linear + Vercel Analytics dark UI aesthetic.
 * Focus: professional daily morning report, all sections fed by real API data.
 */
'use strict';

const API         = '/api/inbox-intelligence.php';
const PRODUCT_API = '/api/inbox-product-check.php';

// Chart registry — destroy before recreate
const CHARTS = {};
function mkChart(id, cfg) {
  if (CHARTS[id]) { try { CHARTS[id].destroy(); } catch(e){} delete CHARTS[id]; }
  const el = document.getElementById(id);
  if (!el) return null;
  CHARTS[id] = new Chart(el.getContext('2d'), cfg);
  return CHARTS[id];
}

Chart.defaults.color       = '#64748b';
Chart.defaults.font.family = "'Noto Sans Thai', sans-serif";
Chart.defaults.font.size   = 11;

// ═══════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════

function secToText(sec) {
  sec = Math.round(parseInt(sec, 10) || 0);
  if (sec <= 0) return '—';
  if (sec < 60) return `${sec} วิ`;
  const m = Math.floor(sec / 60), s = sec % 60;
  if (m < 60) return s > 0 ? `${m} น. ${s} วิ` : `${m} น.`;
  const h = Math.floor(m / 60), rm = m % 60;
  return rm > 0 ? `${h} ชม. ${rm} น.` : `${h} ชม.`;
}

function fmt(n) { return (parseInt(n, 10) || 0).toLocaleString('th-TH'); }

function fmtBaht(n) {
  const num = parseFloat(n) || 0;
  if (num >= 1_000_000) return `฿${(num / 1_000_000).toFixed(2)}M`;
  if (num >= 1_000)     return `฿${(num / 1_000).toFixed(1)}K`;
  return `฿${Math.round(num).toLocaleString('th-TH')}`;
}

function pct(a, b) {
  const n = parseInt(a, 10) || 0, d = parseInt(b, 10) || 0;
  return d === 0 ? 0 : Math.round((n / d) * 100);
}

function deltaText(curr, prev) {
  const c = parseFloat(curr) || 0, p = parseFloat(prev) || 0;
  if (p === 0 && c === 0) return { txt: '—', cls: 'c-slate5' };
  if (p === 0) return { txt: `+${fmt(c)}`, cls: 'c-green' };
  const d = ((c - p) / p * 100).toFixed(1);
  if (c > p) return { txt: `▲ ${d}%`, cls: 'c-green' };
  if (c < p) return { txt: `▼ ${Math.abs(d)}%`, cls: 'c-red' };
  return { txt: '= 0%', cls: 'c-slate5' };
}

function speedColor(s) {
  s = parseInt(s, 10) || 0;
  if (s < 900) return 'green';
  if (s < 3000) return 'yellow';
  return 'red';
}
function speedLabel(s) {
  const c = speedColor(s);
  return c === 'green' ? 'เร็ว' : c === 'yellow' ? 'ปานกลาง' : 'ช้า';
}

function colorClass(c) {
  const map = {
    green:  { text: 'c-green',  badge: 'badge-green',  hex: '#34d399' },
    yellow: { text: 'c-amber',  badge: 'badge-yellow', hex: '#fbbf24' },
    red:    { text: 'c-red',    badge: 'badge-red',    hex: '#f87171' },
    purple: { text: 'c-purple', badge: 'badge-purple', hex: '#c084fc' },
    blue:   { text: 'c-blue',   badge: 'badge-blue',   hex: '#60a5fa' },
    indigo: { text: 'c-indigo', badge: 'badge-blue',   hex: '#818cf8' },
  };
  return map[c] || map.blue;
}

function stockBadge(qty) {
  if (qty === null || qty === undefined)
    return `<span class="badge badge-gray">ไม่มีข้อมูล</span>`;
  const q = parseInt(qty, 10);
  if (q === 0)  return `<span class="badge badge-red">หมด</span>`;
  if (q <= 3)   return `<span class="badge badge-red">เหลือ ${q}</span>`;
  if (q <= 10)  return `<span class="badge badge-yellow">เหลือ ${q}</span>`;
  if (q <= 20)  return `<span class="badge badge-yellow">${q}</span>`;
  return `<span class="badge badge-green">${q}</span>`;
}

function mentionBadge(count) {
  const n = parseInt(count, 10) || 0;
  if (n >= 50) return `<span class="badge badge-red">🔥 ${n}</span>`;
  if (n >= 20) return `<span class="badge badge-yellow">⚡ ${n}</span>`;
  if (n >= 10) return `<span class="badge badge-blue">${n}</span>`;
  return `<span class="c-slate5 fs11 mono">${n}</span>`;
}

function sk(h = 80) {
  return `<div class="sk" style="height:${h}px;"></div>`;
}
function errEl(msg) {
  return `<div style="padding:16px;text-align:center;color:#f87171;font-size:12px;opacity:0.8;">${msg}</div>`;
}
function okEl(msg) {
  return `<div style="padding:16px;text-align:center;color:#34d399;font-size:13px;display:flex;align-items:center;justify-content:center;gap:8px;">✅ <span>${msg}</span></div>`;
}

function setEl(id, html) {
  const el = document.getElementById(id);
  if (el) el.innerHTML = html;
}

// ═══════════════════════════════════════════
// SECTION 1 — EXECUTIVE DAILY REPORT
// ═══════════════════════════════════════════

async function loadExecutiveSummary() {
  const kpiEl   = document.getElementById('exec-kpi');
  const trendEl = document.getElementById('exec-trend-chart');
  const hourlyEl= document.getElementById('exec-hourly-chart');
  const dateEl  = document.getElementById('exec-date');
  const stripEl = document.getElementById('exec-msg-strip');

  try {
    const res  = await fetch(`${API}?action=daily_report`);
    const json = await res.json();
    const d    = json.data || {};

    const oy  = d.orders_yesterday || {};
    const ot  = d.orders_today     || {};
    const cm  = d.current_month    || {};
    const pm  = d.prev_month       || {};
    const bdo = d.bdo_yesterday     || {};
    const slp = d.slips_yesterday   || {};
    const bdoLive = d.bdo_today     || {};
    const slpLive = d.slips_today   || {};
    const ovd = d.overdue          || {};
    const msgs = d.messages_today  || {};
    const trend  = d.trend_7d || [];
    const hourly = d.hourly_traffic || [];

    // Date — show YESTERDAY as main report date
    if (dateEl) {
      const today = d.date ? new Date(d.date + 'T00:00:00') : new Date();
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);
      const opts  = { weekday:'long', day:'numeric', month:'long', year:'numeric', timeZone:'Asia/Bangkok' };
      dateEl.textContent = yesterday.toLocaleDateString('th-TH', opts);
    }

    // Today snapshot (small note)
    const todaySnap = document.getElementById('today-snapshot');
    if (todaySnap) {
      const ot = d.orders_today || {};
      const todayOrders = ot.total || 0;
      const todayAmt = ot.amount || '0.00';
      todaySnap.innerHTML = `\u{1F4C5} วันนี้: ${todayOrders} ออเดอร์ \u00B7 \u{1F4B0} ${parseFloat(todayAmt).toLocaleString('th-TH')} บาท`;
    }

    // MoM
    const momAmt    = parseFloat(cm.amount || 0);
    const prevAmt   = parseFloat(pm.amount || 0);
    const momGrowth = prevAmt > 0 ? ((momAmt / prevAmt - 1) * 100).toFixed(1) : null;
    const momCol    = momGrowth === null ? 'blue' : parseFloat(momGrowth) >= 0 ? 'green' : 'red';

    // Orders delta
    const ordDiff = parseInt(oy.total || 0) - parseInt(ot.total || 0);
    const ordCol  = ordDiff >= 0 ? 'green' : 'yellow';

    const kpis = [
      {
        emoji: '🛒', label: 'ออเดอร์เมื่อวาน',
        value: fmt(oy.total || 0),
        sub: `${fmt(ot.total || 0)} ออเดอร์วันนี้`,
        badge: `${oy.total || 0} \u0E2D\u0E2D\u0E40\u0E14\u0E2D\u0E23\u0E4C`,
        badgeCol: ordDiff >= 0 ? 'green' : 'yellow',
        col: ordCol,
      },
      {
        emoji: '💰', label: 'ยอดขายเดือนนี้',
        value: fmtBaht(cm.amount || 0),
        sub: `${fmt(cm.total || 0)} ออเดอร์ · ${fmt(cm.customers || 0)} ลูกค้า`,
        badge: null, col: 'indigo',
      },
      {
        emoji: '📈', label: 'เติบโต MoM',
        value: momGrowth !== null ? `${parseFloat(momGrowth) >= 0 ? '+' : ''}${momGrowth}%` : '—',
        sub: `เดือนก่อน ${fmtBaht(pm.amount || 0)}`,
        badge: null, col: momCol,
      },
      {
        emoji: '📦', label: 'BDO เมื่อวาน',
        value: fmt(bdo.total || 0),
        sub: `วันนี้: ${fmt(bdoLive.total || 0)} รายการ`,
        badge: null, col: 'purple',
      },
      {
        emoji: '🧾', label: 'สลิปเมื่อวาน',
        value: fmt(slp.total || 0),
        sub: `วันนี้: ${fmt(slpLive.total || 0)} รายการ · รอ ${fmt(slpLive.pending || 0)}`,
        badge: parseInt(bdoLive.pending || 0) > 0 ? `วันนี้ ${bdoLive.pending}` : null,
        badgeCol: 'yellow', col: parseInt(slp.pending || 0) > 0 ? 'yellow' : 'green',
      },
      {
        emoji: '⚠️', label: 'ค้างชำระ',
        value: fmt(ovd.customers || 0),
        sub: fmtBaht(ovd.total_amount || 0),
        badge: parseInt(ovd.customers || 0) > 0 ? 'มีค้างชำระ' : null,
        badgeCol: 'red', col: parseInt(ovd.customers || 0) > 0 ? 'red' : 'green',
      },
    ];

    kpiEl.innerHTML = kpis.map(k => {
      const cc = colorClass(k.col);
      return `<div class="kpi-card">
        <div class="kpi-emoji">${k.emoji}</div>
        <div class="kpi-label">${k.label}</div>
        <div class="kpi-value ${cc.text}">${k.value}</div>
        <div class="kpi-sub">${k.sub}</div>
        ${k.badge ? `<span class="badge badge-${k.badgeCol}" style="margin-top:6px;">${k.badge}</span>` : ''}
      </div>`;
    }).join('');

    // Messages strip
    if (stripEl) {
      const unread = parseInt(msgs.unread || 0);
      stripEl.innerHTML = `
        <span style="display:inline-flex;align-items:center;gap:5px;">
          <span class="dot" style="background:#6366f1;"></span>
          <span class="c-slate4">IN</span> <span class="c-white fw6 mono">${fmt(msgs.incoming || 0)}</span>
        </span>
        <span style="display:inline-flex;align-items:center;gap:5px;">
          <span class="dot" style="background:#34d399;"></span>
          <span class="c-slate4">OUT</span> <span class="c-white fw6 mono">${fmt(msgs.outgoing || 0)}</span>
        </span>
        ${unread > 0 ? `<span style="display:inline-flex;align-items:center;gap:5px;" class="c-red">
          <span class="dot" style="background:#ef4444;"></span>ยังไม่อ่าน ${fmt(unread)}
        </span>` : ''}
        <span class="c-slate5">·</span>
        <span class="c-slate5">ผู้ส่ง ${fmt(msgs.senders || 0)} ราย</span>`;
    }

    // 7-day trend chart
    if (trendEl) {
      if (trend.length) {
        trendEl.innerHTML = `<canvas id="trendCanvas"></canvas>`;
        mkChart('trendCanvas', {
          type: 'bar',
          data: {
            labels: trend.map(t => t.day),
            datasets: [
              {
                label: 'ออเดอร์',
                data: trend.map(t => parseInt(t.orders)),
                backgroundColor: 'rgba(99,102,241,0.55)',
                borderColor: '#6366f1',
                borderWidth: 1,
                borderRadius: 5,
                yAxisID: 'y1',
              },
              {
                label: 'ยอด (฿K)',
                data: trend.map(t => Math.round(parseFloat(t.amount || 0) / 1000)),
                type: 'line',
                borderColor: '#10b981',
                backgroundColor: 'rgba(16,185,129,0.07)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#10b981',
                borderWidth: 2,
                yAxisID: 'y2',
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { labels: { boxWidth: 12, padding: 14, color: '#94a3b8' } },
              tooltip: { callbacks: { label: ctx => ctx.dataset.yAxisID === 'y2' ? ` ฿${ctx.raw.toLocaleString('th-TH')}K` : ` ${ctx.raw} ออเดอร์` } },
            },
            scales: {
              x:  { grid: { color: '#1e293b' }, ticks: { color: '#64748b', maxRotation: 0 } },
              y1: { position: 'left',  grid: { color: '#1e293b' }, ticks: { color: '#64748b' }, title: { display: true, text: 'ออเดอร์', color: '#475569' } },
              y2: { position: 'right', grid: { display: false }, ticks: { color: '#10b981', callback: v => `฿${v}K` } },
            },
          },
        });
      } else {
        trendEl.innerHTML = `<div class="c-slate6 fs11" style="text-align:center;padding:40px 0;">ไม่มีข้อมูล trend 7 วัน</div>`;
      }
    }

    // Hourly chart
    if (hourlyEl) {
      if (hourly.length) {
        hourlyEl.innerHTML = `<canvas id="hourlyCanvas"></canvas>`;
        const hLabels = Array.from({ length: 24 }, (_, i) => i);
        const inMap = {}, outMap = {};
        hourly.forEach(h => {
          inMap[parseInt(h.hour)]  = parseInt(h.incoming || 0);
          outMap[parseInt(h.hour)] = parseInt(h.outgoing || 0);
        });
        mkChart('hourlyCanvas', {
          type: 'bar',
          data: {
            labels: hLabels.map(h => `${h}`),
            datasets: [
              { label: 'IN',  data: hLabels.map(h => inMap[h]  || 0), backgroundColor: 'rgba(99,102,241,0.65)', borderColor: '#6366f1', borderWidth: 1, borderRadius: 3 },
              { label: 'OUT', data: hLabels.map(h => outMap[h] || 0), backgroundColor: 'rgba(16,185,129,0.5)',  borderColor: '#10b981', borderWidth: 1, borderRadius: 3 },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { boxWidth: 10, padding: 10, color: '#94a3b8' } } },
            scales: {
              x: { grid: { display: false }, ticks: { color: '#374151', maxTicksLimit: 13, callback: (v, i) => i % 4 === 0 ? `${i}h` : '' } },
              y: { grid: { color: '#1e293b' }, ticks: { color: '#64748b' } },
            },
          },
        });
      } else {
        hourlyEl.innerHTML = `<div class="c-slate6 fs11" style="text-align:center;padding:20px 0;">ยังไม่มีข้อมูลรายชั่วโมงวันนี้</div>`;
      }
    }

  } catch (e) {
    setEl('exec-kpi', `<div style="grid-column:span 6;">${errEl('โหลด daily report ไม่ได้: ' + e.message)}</div>`);
  }
}

// ═══════════════════════════════════════════
// SECTION 2 — TRAFFIC (7 days)
// ═══════════════════════════════════════════

async function loadTrafficSection() {
  const statsEl  = document.getElementById('traffic-stats');
  const chartEl  = document.getElementById('traffic-volume-chart');
  if (!statsEl) return;

  try {
    const res  = await fetch(`${API}?action=traffic_comparison&days=7`);
    const json = await res.json();

    const todayIn    = parseInt(json.today_in    || 0);
    const todayOut   = parseInt(json.today_out   || 0);
    const ydayIn     = parseInt(json.yday_in     || 0);
    const ydayOut    = parseInt(json.yday_out    || 0);
    const avgIn      = Math.round(parseFloat(json.avg_in  || 0));
    const avgOut     = Math.round(parseFloat(json.avg_out || 0));
    const todayUsers = parseInt(json.today_users || 0);
    const ydayUsers  = parseInt(json.yday_users  || 0);
    const newToday   = parseInt(json.new_today   || 0);
    const newYday    = parseInt(json.new_yday    || 0);

    const rows = [
      { icon: '📨', label: 'ข้อความเข้า',   today: todayIn,    yday: ydayIn,    avg: avgIn,  col: '#818cf8' },
      { icon: '📤', label: 'ข้อความออก',    today: todayOut,   yday: ydayOut,   avg: avgOut, col: '#34d399' },
      { icon: '👥', label: 'ผู้ใช้ที่คุย',  today: todayUsers, yday: ydayUsers, avg: null,   col: '#c084fc' },
      { icon: '🆕', label: 'ลูกค้าใหม่',    today: newToday,   yday: newYday,   avg: null,   col: '#fbbf24' },
    ];

    statsEl.innerHTML = rows.map(r => {
      const d = deltaText(r.today, r.yday);
      return `<div class="stat-row">
        <div class="stat-row-icon">${r.icon}</div>
        <div style="flex:1;">
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <span class="c-slate5 fs11">${r.label}</span>
            <span class="fw7 mono" style="font-size:17px;color:${r.col};">${fmt(r.today)}</span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:3px;">
            <span class="${d.cls} fs11">${d.txt}</span>
            <span class="c-slate6 fs11">เมื่อวาน ${fmt(r.yday)}</span>
            ${r.avg !== null ? `<span class="c-slate7 fs11">avg7d ${fmt(r.avg)}</span>` : ''}
          </div>
        </div>
      </div>`;
    }).join('');

    // Volume chart
    const daily7 = (json.data || []).slice().reverse();
    if (chartEl && daily7.length) {
      chartEl.innerHTML = `<canvas id="volumeCanvas"></canvas>`;
      mkChart('volumeCanvas', {
        type: 'line',
        data: {
          labels: daily7.map(d => (d.date || '').slice(5)),
          datasets: [
            {
              label: 'IN',
              data: daily7.map(d => d.msg_in),
              borderColor: '#6366f1',
              backgroundColor: 'rgba(99,102,241,0.08)',
              fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: '#6366f1', borderWidth: 2,
            },
            {
              label: 'OUT',
              data: daily7.map(d => d.msg_out),
              borderColor: '#10b981',
              backgroundColor: 'rgba(16,185,129,0.08)',
              fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: '#10b981', borderWidth: 2,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { labels: { boxWidth: 10, padding: 12, color: '#94a3b8' } } },
          scales: {
            x: { grid: { color: '#1e293b' }, ticks: { color: '#64748b', maxRotation: 0 } },
            y: { grid: { color: '#1e293b' }, ticks: { color: '#64748b' } },
          },
        },
      });
    } else if (chartEl) {
      chartEl.innerHTML = `<div class="c-slate6 fs11" style="text-align:center;padding:40px 0;">ไม่มีข้อมูล</div>`;
    }

  } catch (e) {
    setEl('traffic-stats', errEl('โหลด traffic ไม่ได้: ' + e.message));
  }
}

// ═══════════════════════════════════════════
// SECTION 3 — SLA
// ═══════════════════════════════════════════

async function loadSLASection() {
  const el = document.getElementById('sla-content');
  if (!el) return;
  el.innerHTML = sk(80);

  const threshold = parseInt(document.getElementById('sla-threshold-val')?.value || 30);

  try {
    const res  = await fetch(`${API}?action=sla_breach&sla_threshold=${threshold}`);
    const json = await res.json();
    const breaches     = json.breaches || [];
    const totalWaiting = parseInt(json.total_waiting || 0);
    const breachCount  = parseInt(json.breach_count  || 0);

    const wcc = colorClass(totalWaiting > 0 ? 'yellow' : 'green');
    const bcc = colorClass(breachCount  > 0 ? 'red'    : 'green');

    el.innerHTML = `
      <div class="sla-pair">
        <div class="sla-box">
          <div class="sla-num ${wcc.text}">${fmt(totalWaiting)}</div>
          <div class="sla-label">รอตอบอยู่</div>
        </div>
        <div class="sla-box">
          <div class="sla-num ${bcc.text}">${fmt(breachCount)}</div>
          <div class="sla-label">เกิน ${threshold} น.</div>
        </div>
      </div>
      ${breaches.length === 0
        ? okEl('ไม่มี SLA breach ขณะนี้ 🎉')
        : `<div style="overflow-y:auto;max-height:240px;">
            <table class="dt">
              <thead><tr>
                <th>ลูกค้า</th>
                <th style="text-align:right;">รอนาน</th>
                <th>ข้อความล่าสุด</th>
              </tr></thead>
              <tbody>
                ${breaches.map(b => {
                  const w  = parseInt(b.waiting_minutes || b.wait_minutes || b.wait_min || 0);
                  const cc = colorClass(w >= 60 ? 'red' : 'yellow');
                  const wt = w < 60 ? `${w} น.` : `${Math.floor(w/60)} ชม. ${w%60} น.`;
                  return `<tr>
                    <td class="c-slate3">${b.customer_name || b.name || `#${b.conversation_id || b.id || '?'}`}</td>
                    <td class="mono ${cc.text}" style="text-align:right;white-space:nowrap;">${wt}</td>
                    <td class="c-slate6" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${b.last_message || '—'}</td>
                  </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>`}`;
  } catch (e) {
    el.innerHTML = errEl('โหลด SLA ไม่ได้: ' + e.message);
  }
}

function onSLAThresholdChange() { loadSLASection(); }

// ═══════════════════════════════════════════
// SECTION 4 — ADMIN
// ═══════════════════════════════════════════

async function loadAdminSection() {
  const perfEl = document.getElementById('admin-perf-table');
  const workEl = document.getElementById('admin-workload-bars');
  if (!perfEl) return;
  perfEl.innerHTML = sk(120);
  if (workEl) workEl.innerHTML = sk(100);

  try {
    const [pr, wr] = await Promise.all([
      fetch(`${API}?action=response_by_admin&days=7`),
      fetch(`${API}?action=admin_workload&days=7`),
    ]);
    const pj = await pr.json();
    const wj = await wr.json();
    const perf = (pj.data || []).sort((a, b) => parseInt(a.avg_sec) - parseInt(b.avg_sec));
    const work = (wj.data || []).filter(d =>
      !String(d.sent_by).startsWith('system:') &&
      !String(d.sent_by).startsWith('admin_')
    );

    if (!perf.length) {
      perfEl.innerHTML = `<div class="c-slate6 fs11" style="text-align:center;padding:24px 0;">ไม่มีข้อมูลการตอบ 7 วัน</div>`;
    } else {
      perfEl.innerHTML = `
        <div style="overflow-x:auto;">
          <table class="dt">
            <thead><tr>
              <th>แอดมิน</th>
              <th style="text-align:right;">สนทนา</th>
              <th style="text-align:right;">เฉลี่ย</th>
              <th style="text-align:right;">&lt;5น.</th>
              <th style="text-align:right;">&gt;30น.</th>
              <th style="text-align:right;">ระดับ</th>
            </tr></thead>
            <tbody>
              ${perf.map(d => {
                const sc  = speedColor(d.avg_sec);
                const cc  = colorClass(sc);
                const p5  = pct(d.under_5min, d.conversations);
                const p30 = pct(d.over_30min, d.conversations);
                const icon = parseInt(d.admin_id) === 0 ? '🤖' : '👤';
                return `<tr>
                  <td>
                    <span style="display:inline-flex;align-items:center;gap:6px;">
                      <span style="width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;background:rgba(99,102,241,0.12);flex-shrink:0;">${icon}</span>
                      <span class="c-slate3">${d.admin_name || `Admin #${d.admin_id}`}</span>
                    </span>
                  </td>
                  <td class="mono c-slate4" style="text-align:right;">${fmt(d.conversations)}</td>
                  <td class="mono ${cc.text}" style="text-align:right;">${secToText(d.avg_sec)}</td>
                  <td class="c-slate5" style="text-align:right;">${p5}%</td>
                  <td class="c-slate5" style="text-align:right;">${p30}%</td>
                  <td style="text-align:right;"><span class="badge badge-${sc === 'green' ? 'green' : sc === 'yellow' ? 'yellow' : 'red'}">${speedLabel(d.avg_sec)}</span></td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
        <div class="c-slate7 fs10" style="margin-top:10px;">🟢 เร็ว &lt;5น. · 🟡 ปานกลาง 5-10น. · 🔴 ช้า &gt;10น. · (7 วัน)</div>`;
    }

    if (workEl) {
      if (!work.length) {
        workEl.innerHTML = `<div class="c-slate6 fs11" style="text-align:center;padding:24px 0;">ไม่มีข้อมูล workload</div>`;
      } else {
        const maxTotal = Math.max(...work.map(d => parseInt(d.total_sent || 0)), 1);
        const COLS = ['#6366f1','#10b981','#f59e0b','#ef4444','#a78bfa','#14b8a6','#ec4899','#60a5fa','#f97316','#84cc16'];
        workEl.innerHTML = `
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;">
            ${work.slice(0, 12).map((d, i) => {
              const total = parseInt(d.total_sent || 0);
              const today = parseInt(d.today_sent || 0);
              const w     = Math.round(total / maxTotal * 100);
              const color = COLS[i % COLS.length];
              const name  = d.admin_name || d.sent_by || `Admin #${i+1}`;
              return `<div>
                <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;margin-bottom:3px;gap:6px;">
                  <span class="c-slate4" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${name}</span>
                  <span style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
                    ${today > 0 ? `<span style="color:${color};font-size:10px;">+${today}</span>` : ''}
                    <span class="mono fw6" style="color:${color};">${fmt(total)}</span>
                  </span>
                </div>
                <div class="wl-track">
                  <div class="wl-fill" style="width:${w}%;background:linear-gradient(90deg,${color}cc,${color}44);"></div>
                </div>
              </div>`;
            }).join('')}
          </div>
          <div class="c-slate7 fs10" style="margin-top:10px;">ข้อความที่ส่งโดยแอดมิน 7 วัน (ไม่รวม system/bot)</div>`;
      }
    }

  } catch (e) {
    setEl('admin-perf-table', errEl('โหลดข้อมูลแอดมินไม่ได้: ' + e.message));
  }
}

// ═══════════════════════════════════════════
// SECTION 5 — PRODUCTS
// ═══════════════════════════════════════════

async function loadProductSection() {
  const bannerEl = document.getElementById('low-stock-banner');
  const tableEl  = document.getElementById('trending-table');
  if (!bannerEl) return;
  bannerEl.innerHTML = sk(48);
  if (tableEl) tableEl.innerHTML = sk(200);

  try {
    const res  = await fetch(`${PRODUCT_API}?action=overview&days=7`);
    const json = await res.json();
    const products = json.products || [];
    const lowList  = json.low_stock_alerts || [];

    // Banner
    if (lowList.length > 0) {
      bannerEl.innerHTML = `<div class="alert alert-red">
        <div class="alert-title">
          ⚠️ สินค้าที่ถูกถามแต่ stock ต่ำ
          <span class="badge badge-red">${lowList.length} รายการ</span>
        </div>
        <div style="overflow-x:auto;margin-top:8px;">
          <table class="dt">
            <thead><tr>
              <th style="width:72px;">รหัส</th>
              <th>ชื่อสินค้า</th>
              <th style="text-align:right;">Stock</th>
              <th style="text-align:right;">จำนวนถาม</th>
              <th>ราคา</th>
            </tr></thead>
            <tbody>
              ${lowList.slice(0, 15).map(p => {
                const critical = parseInt(p.live_qty || 0) === 0;
                return `<tr ${critical ? 'class="row-critical"' : ''}>
                  <td class="mono c-slate5 fs10">${p.product_code}</td>
                  <td style="max-width:200px;">
                    <div class="c-slate3 fs12" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${critical ? '🔴 ' : '🟡 '}${p.name}</div>
                    ${p.generic_name ? `<div class="c-slate6 fs10">${p.generic_name}</div>` : ''}
                  </td>
                  <td style="text-align:right;">${stockBadge(p.live_qty)}</td>
                  <td style="text-align:right;">${mentionBadge(p.mention_count)}</td>
                  <td class="c-slate5 fs11">${p.online_price ? `฿${p.online_price}` : '—'}</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
    } else {
      bannerEl.innerHTML = `<div class="alert alert-green">
        ✅ ไม่มีสินค้าที่ถูกถามแล้วใกล้หมด stock
        <span class="c-slate5 fs11" style="margin-left:12px;">สินค้าที่มีการถาม ${fmt(products.length)} รายการ</span>
      </div>`;
    }

    // Trending table
    if (tableEl && products.length) {
      tableEl.innerHTML = `
        <div style="overflow-y:auto;max-height:420px;">
          <table class="dt">
            <thead style="position:sticky;top:0;background:#0f1829;z-index:2;">
              <tr>
                <th style="width:28px;text-align:right;">#</th>
                <th>สินค้า</th>
                <th style="text-align:right;">Stock</th>
                <th style="text-align:right;">ถาม</th>
                <th>ราคา</th>
                <th>ลูกค้าที่ถาม</th>
              </tr>
            </thead>
            <tbody>
              ${products.slice(0, 30).map((p, i) => {
                const mentioners = (p.mentioners || []).slice(0, 3).join(', ');
                const more       = (p.mentioners || []).length - 3;
                const catShort   = (p.category || '').replace(/^[A-Z]{2,3}-\d{2}-/, '').slice(0, 12);
                return `<tr>
                  <td class="c-slate6 fs10 mono" style="text-align:right;">${i + 1}</td>
                  <td>
                    <div class="c-slate2" style="font-size:12px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${p.name}">${p.name}</div>
                    ${p.generic_name ? `<div class="c-slate6 fs10">${p.generic_name.slice(0, 48)}</div>` : ''}
                    ${catShort ? `<div class="c-slate7 fs10">${catShort}</div>` : ''}
                  </td>
                  <td style="text-align:right;">${stockBadge(p.live_qty)}</td>
                  <td style="text-align:right;">${mentionBadge(p.mention_count)}</td>
                  <td class="c-slate5 fs11">${p.online_price ? `฿${p.online_price}` : '—'}</td>
                  <td class="c-slate6 fs10" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    ${mentioners}${more > 0 ? ` +${more}` : ''}
                  </td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>`;
    } else if (tableEl) {
      tableEl.innerHTML = `<div class="c-slate6 fs11" style="text-align:center;padding:24px 0;">ไม่มีข้อมูลสินค้า</div>`;
    }

  } catch (e) {
    if (bannerEl) bannerEl.innerHTML = errEl('โหลด product intelligence ไม่ได้: ' + e.message);
  }
}

// ═══════════════════════════════════════════
// SECTION 6 — SENTIMENT
// ═══════════════════════════════════════════

const SENT_COLORS = {
  'รอตอบนาน':   { bg: 'rgba(239,68,68,0.6)',   border: '#ef4444' },
  'เชิงบวก':    { bg: 'rgba(16,185,129,0.6)',  border: '#10b981' },
  'ร้องเรียน':  { bg: 'rgba(245,158,11,0.6)',  border: '#f59e0b' },
  'ต้องติดตาม': { bg: 'rgba(99,102,241,0.6)',  border: '#818cf8' },
  'ไม่พอใจ':    { bg: 'rgba(168,85,247,0.6)',  border: '#a855f7' },
  'เร่งด่วน':   { bg: 'rgba(234,179,8,0.6)',   border: '#eab308' },
};
const SENT_FB = [
  { bg: 'rgba(99,102,241,0.6)',  border: '#6366f1' },
  { bg: 'rgba(20,184,166,0.6)',  border: '#14b8a6' },
  { bg: 'rgba(236,72,153,0.6)',  border: '#ec4899' },
  { bg: 'rgba(248,113,113,0.5)', border: '#f87171' },
];

async function loadSentimentSection() {
  const wrapEl = document.getElementById('sentiment-canvas-wrap');
  const tagsEl = document.getElementById('sentiment-tags');
  if (!wrapEl) return;
  wrapEl.innerHTML = sk(160);

  try {
    const res  = await fetch(`${API}?action=sentiment_summary&days=7`);
    const json = await res.json();
    const data = json.data || [];

    if (!data.length) {
      wrapEl.innerHTML = `<div class="c-slate6 fs11" style="text-align:center;padding:40px 0;">ไม่มีข้อมูล sentiment 7 วัน</div>`;
      if (tagsEl) tagsEl.innerHTML = '';
      return;
    }

    const labels   = data.map(d => d.tag_name || d.tag);
    const counts   = data.map(d => parseInt(d.count || 0));
    const colors   = labels.map((t, i) => SENT_COLORS[t] || SENT_FB[i % SENT_FB.length]);
    const bgs      = colors.map(c => c.bg);
    const borders  = colors.map(c => c.border);
    const total    = counts.reduce((a, b) => a + b, 0);

    wrapEl.innerHTML = `<canvas id="sentCanvas"></canvas>`;
    mkChart('sentCanvas', {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'จำนวน',
          data: counts,
          backgroundColor: bgs,
          borderColor: borders,
          borderWidth: 1,
          borderRadius: 5,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => ` ${ctx.raw.toLocaleString('th-TH')} ครั้ง (${pct(ctx.raw, total)}%)` } },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#64748b' } },
          y: { grid: { color: '#1e293b' }, ticks: { color: '#64748b', stepSize: 1 } },
        },
      },
    });

    if (tagsEl) {
      tagsEl.innerHTML = data.map((d, i) => {
        const p = pct(d.count, total);
        return `<span class="stag" style="background:${bgs[i].replace('0.6','0.12')};color:${borders[i]};border:1px solid ${borders[i]}55;">
          ${d.tag_name || d.tag}: <strong>${fmt(d.count)}</strong> <span style="opacity:0.6;">(${p}%)</span>
        </span>`;
      }).join('');
    }

  } catch (e) {
    wrapEl.innerHTML = errEl('โหลด sentiment ไม่ได้: ' + e.message);
  }
}

// ═══════════════════════════════════════════
// SECTION 7 — CUSTOMER JOURNEY
// ═══════════════════════════════════════════

async function loadJourneySection() {
  const el = document.getElementById('journey-content');
  if (!el) return;
  el.innerHTML = sk(100);

  try {
    const res  = await fetch(`${API}?action=customer_journey&days=7`);
    const json = await res.json();
    const data = json.data || {};
    const conv = data.conversion || {};

    const inboxUsers = parseInt(conv.inbox_users     || 0);
    const hasOrders  = parseInt(conv.has_orders      || 0);
    const repeatCust = parseInt(conv.repeat_customers || 0);
    const crPct      = inboxUsers > 0 ? Math.round(hasOrders / inboxUsers * 100) : 0;

    let html = `
      <div class="conv-row">
        <div class="conv-cell">
          <div class="conv-num c-blue">${fmt(inboxUsers)}</div>
          <div class="conv-label">ผู้ใช้ใน Inbox</div>
        </div>
        <div class="conv-arrow">→</div>
        <div class="conv-cell">
          <div class="conv-num c-green">${fmt(hasOrders)}</div>
          <div class="conv-label">มีออเดอร์</div>
        </div>
        <div class="conv-arrow">→</div>
        <div class="conv-cell">
          <div class="conv-num c-purple">${fmt(repeatCust)}</div>
          <div class="conv-label">ลูกค้าซ้ำ</div>
        </div>
      </div>
      <div class="conv-rate">
        <span class="c-slate4 fs11">Inbox → Order Conversion</span>
        <span class="fw7 mono" style="font-size:22px;color:${crPct >= 20 ? '#34d399' : crPct >= 5 ? '#fbbf24' : '#f87171'};">${crPct}%</span>
      </div>`;

    const stages = data.consultation_stages || [];
    if (stages.length > 0) {
      const FUNNEL = ['#6366f1','#8b5cf6','#a855f7','#ec4899','#f43f5e'];
      const maxC   = Math.max(...stages.map(s => parseInt(s.count || s.users || 0)), 1);
      html += `<div style="margin-top:14px;">
        <div class="c-slate5 fs10 fw7" style="letter-spacing:0.1em;text-transform:uppercase;margin-bottom:10px;">Consultation Funnel</div>
        ${stages.map((s, i) => {
          const count = parseInt(s.count || s.users || 0);
          const w     = Math.round(count / maxC * 100);
          const color = FUNNEL[i % FUNNEL.length];
          const name  = s.stage_name || s.stage || s.name || `Stage ${i+1}`;
          const prev  = i > 0 ? parseInt(stages[i-1].count || stages[i-1].users || 0) : null;
          const drop  = prev && prev > 0 ? Math.round((1 - count / prev) * 100) : null;
          return `<div style="margin-bottom:8px;">
            <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;margin-bottom:2px;">
              <span class="c-slate4">${name}${drop !== null ? ` <span class="c-slate7">↓${drop}%</span>` : ''}</span>
              <span class="mono fw6" style="color:${color};">${fmt(count)}</span>
            </div>
            <div class="wl-track">
              <div class="wl-fill" style="width:${w}%;background:linear-gradient(90deg,${color}cc,${color}44);"></div>
            </div>
          </div>`;
        }).join('')}
      </div>`;
    }

    el.innerHTML = html;

  } catch (e) {
    el.innerHTML = errEl('โหลด customer journey ไม่ได้: ' + e.message);
  }
}

// ═══════════════════════════════════════════════════════════════
// FLAGGED MESSAGES — problematic incoming + inappropriate outgoing
// ═══════════════════════════════════════════════════════════════


function esc(s) {
  if (!s) return '';
  const d = document.createElement('div');
  d.textContent = String(s);
  return d.innerHTML;
}
const CATEGORY_LABELS = {
  complaint:       '🔴 ร้องเรียน',
  return_exchange: '🔄 ขอคืน/เปลี่ยน',
  missing_item:    '📦 ของขาด/หาย',
  slow_response:   '⏱️ รอนาน/ช้า',
  error_complaint: '❌ ผิดพลาด',
  other:           '⚠️ อื่นๆ',
};

async function loadFlaggedMessages() {
  try {
    const r = await fetch(API + '?action=flagged_messages&days=7');
    const d = await r.json();
    const data = d.data;

    const fEl = document.getElementById('flagged-list');
    const fCount = document.getElementById('flagged-count');
    if (fEl) {
      if (data.problematic_incoming.length === 0) {
        fEl.innerHTML = '<div style="color:#475569;font-size:12px;">✅ ไม่มีข้อความที่อาจเป็นปัญหาใน 7 วัน</div>';
      } else {
        fEl.innerHTML = data.problematic_incoming.map(m => {
          const cat = CATEGORY_LABELS[m.category] || '⚠️ อื่นๆ';
          const time = new Date(m.created_at).toLocaleString('th-TH', {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
          return '<div style="background:#1e293b;border-radius:6px;padding:8px 10px;margin-bottom:6px;border-left:3px solid #fbbf24;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;">' +
            '<span style="font-size:11px;color:#94a3b8;">' + esc(m.display_name) + '</span>' +
            '<span style="font-size:10px;color:#64748b;">' + time + '</span></div>' +
            '<div style="font-size:12px;color:#e2e8f0;margin:4px 0;">' + esc(m.content) + '</div>' +
            '<span style="font-size:10px;">' + cat + '</span></div>';
        }).join('');
      }
      if (fCount) fCount.textContent = '(' + data.problematic_incoming.length + ' ข้อ)';
    }

    const uEl = document.getElementById('unprofessional-list');
    const uCount = document.getElementById('unprof-count');
    if (uEl) {
      if (data.inappropriate_outgoing.length === 0) {
        uEl.innerHTML = '<div style="color:#475569;font-size:12px;">✅ ไม่พบข้อความที่ไม่เหมาะสม</div>';
      } else {
        uEl.innerHTML = data.inappropriate_outgoing.map(m => {
          const time = new Date(m.created_at).toLocaleString('th-TH', {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
          const catLabel = m.category === 'typo_garbage' ? '⌨️ กดพิมพ์ผิด' : '💬 ไม่เหมาะสม';
          return '<div style="background:#1e293b;border-radius:6px;padding:8px 10px;margin-bottom:6px;border-left:3px solid #f87171;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;">' +
            '<span style="font-size:11px;color:#f87171;">' + esc(m.admin_name) + '</span>' +
            '<span style="font-size:10px;color:#64748b;">' + time + '</span></div>' +
            '<div style="font-size:12px;color:#e2e8f0;margin:4px 0;font-family:monospace;">' + esc(m.content) + '</div>' +
            '<span style="font-size:10px;">' + catLabel + '</span></div>';
        }).join('');
      }
      if (uCount) uCount.textContent = '(' + data.inappropriate_outgoing.length + ' ข้อ)';
    }
  } catch(e) {
    console.error('loadFlaggedMessages:', e);
  }
}

// ═══════════════════════════════════════════
// HEADER STATS
// ═══════════════════════════════════════════

async function loadHeaderStats() {
  const el = document.getElementById('header-stats');
  if (!el) return;

  try {
    const res  = await fetch(`${API}?action=response_by_admin&days=7`);
    const json = await res.json();
    const data = json.data || [];

    let totalConv = 0, weightedAvg = 0;
    data.forEach(d => {
      const c = parseInt(d.conversations || 0);
      totalConv   += c;
      weightedAvg += parseInt(d.avg_sec || 0) * c;
    });
    const avgSec = totalConv > 0 ? Math.round(weightedAvg / totalConv) : 0;
    const cc = colorClass(speedColor(avgSec));

    el.style.display = 'flex';
    el.innerHTML = `
      <span class="c-slate5">สนทนา 7 วัน:</span>
      <span class="c-white fw6 mono" style="margin-left:4px;">${fmt(totalConv)}</span>
      <span class="c-slate7" style="margin:0 6px;">·</span>
      <span class="c-slate5">เวลาตอบ:</span>
      <span class="${cc.text} fw6 mono" style="margin-left:4px;">${secToText(avgSec)}</span>
      <span class="c-slate7" style="margin:0 6px;">·</span>
      <span class="c-slate5">แอดมิน:</span>
      <span class="c-white fw6 mono" style="margin-left:4px;">${data.length} คน</span>`;
  } catch (e) { /* silent */ }
}

// ═══════════════════════════════════════════
// TIMESTAMP
// ═══════════════════════════════════════════

function updateTimestamp() {
  const el = document.getElementById('last-updated');
  if (!el) return;
  const now = new Date();
  el.textContent = `อัปเดต ${now.toLocaleString('th-TH', { day:'numeric', month:'short', hour:'2-digit', minute:'2-digit', timeZone:'Asia/Bangkok' })} น.`;
}

// ═══════════════════════════════════════════
// REFRESH ALL
// ═══════════════════════════════════════════

let _busy = false;

async function refreshAll() {
  if (_busy) return;
  _busy = true;

  const icon = document.getElementById('refresh-icon');
  const btn  = document.getElementById('refresh-btn');
  if (icon) icon.classList.add('spinning');
  if (btn)  btn.disabled = true;

  await Promise.allSettled([
    loadHeaderStats(),
    loadExecutiveSummary(),
    loadTrafficSection(),
    loadSLASection(),
    loadAdminSection(),
    loadProductSection(),
    loadSentimentSection(),
    loadJourneySection(),
    loadFlaggedMessages(),
  ]);

  updateTimestamp();
  if (icon) icon.classList.remove('spinning');
  if (btn)  btn.disabled = false;
  _busy = false;
}

// ═══════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
  refreshAll();
  setInterval(refreshAll, 5 * 60 * 1000);
});
