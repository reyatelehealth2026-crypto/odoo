/**
 * Inbox Intelligence Dashboard — v10
 * iOS Frosted Glass Theme — All-in-One Layout (No Modals)
 * API logic unchanged.
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

// iOS Chart defaults
Chart.defaults.color       = '#8e8e93';
Chart.defaults.font.family = "-apple-system, 'SF Pro Display', 'Noto Sans Thai', sans-serif";
Chart.defaults.font.size   = 10;

// ═══════════════════════════════════════════
// UTILITIES — iOS Style
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
  if (p === 0 && c === 0) return { txt: '—', cls: 'ios-secondary' };
  if (p === 0) return { txt: `+${fmt(c)}`, cls: 'ios-success' };
  const d = ((c - p) / p * 100).toFixed(1);
  if (c > p) return { txt: `▲ ${d}%`, cls: 'ios-success' };
  if (c < p) return { txt: `▼ ${Math.abs(d)}%`, cls: 'ios-danger' };
  return { txt: '= 0%', cls: 'ios-secondary' };
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

// iOS Color Classes
function iosColorClass(c) {
  const map = {
    green:  { text: 'ios-success', badge: 'ios-badge-green',  hex: '#34c759' },
    yellow: { text: 'ios-warning', badge: 'ios-badge-yellow', hex: '#ff9500' },
    red:    { text: 'ios-danger',  badge: 'ios-badge-red',    hex: '#ff3b30' },
    purple: { text: 'ios-purple',  badge: 'ios-badge-purple', hex: '#af52de' },
    blue:   { text: 'ios-blue',    badge: 'ios-badge-blue',   hex: '#007aff' },
    indigo: { text: 'ios-indigo',  badge: 'ios-badge-blue',   hex: '#5856d6' },
  };
  return map[c] || map.blue;
}

function stockBadge(qty) {
  if (qty === null || qty === undefined)
    return `<span class="ios-badge ios-badge-gray">ไม่มีข้อมูล</span>`;
  const q = parseInt(qty, 10);
  if (q === 0)  return `<span class="ios-badge ios-badge-red">หมด</span>`;
  if (q <= 3)   return `<span class="ios-badge ios-badge-red">เหลือ ${q}</span>`;
  if (q <= 10)  return `<span class="ios-badge ios-badge-yellow">เหลือ ${q}</span>`;
  if (q <= 20)  return `<span class="ios-badge ios-badge-yellow">${q}</span>`;
  return `<span class="ios-badge ios-badge-green">${q}</span>`;
}

function mentionBadge(count) {
  const n = parseInt(count, 10) || 0;
  if (n >= 50) return `<span class="ios-badge ios-badge-red">🔥 ${n}</span>`;
  if (n >= 20) return `<span class="ios-badge ios-badge-yellow">⚡ ${n}</span>`;
  if (n >= 10) return `<span class="ios-badge ios-badge-blue">${n}</span>`;
  return `<span class="ios-secondary ios-fs10 ios-mono">${n}</span>`;
}

function sk(h = 60) {
  return `<div class="ios-skeleton" style="height:${h}px;"></div>`;
}
function errEl(msg) {
  return `<div class="ios-error-msg">${msg}</div>`;
}
function okEl(msg) {
  return `<div class="ios-success-msg">✅ <span>${msg}</span></div>`;
}

function setEl(id, html) {
  const el = document.getElementById(id);
  if (el) el.innerHTML = html;
}

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
      const todayOrders = ot.total || 0;
      const todayAmt = ot.amount || '0.00';
      todaySnap.innerHTML = `📅 วันนี้: ${todayOrders} ออเดอร์ · 💰 ${parseFloat(todayAmt).toLocaleString('th-TH')} บาท`;
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
        badge: `${oy.total || 0} ออเดอร์`,
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
      const cc = iosColorClass(k.col);
      return `<div class="ios-kpi-card">
        <div class="ios-kpi-emoji">${k.emoji}</div>
        <div class="ios-kpi-label">${k.label}</div>
        <div class="ios-kpi-value ${cc.text}">${k.value}</div>
        <div class="ios-kpi-sub">${k.sub}</div>
        ${k.badge ? `<span class="ios-badge ios-badge-${k.badgeCol}" style="margin-top:4px;">${k.badge}</span>` : ''}
      </div>`;
    }).join('');

    // Messages strip
    if (stripEl) {
      const unread = parseInt(msgs.unread || 0);
      stripEl.innerHTML = `
        <span class="ios-strip-item">
          <span class="ios-dot" style="background:#007aff;"></span>
          <span class="ios-secondary">IN</span> <span class="ios-primary ios-fw6 ios-mono">${fmt(msgs.incoming || 0)}</span>
        </span>
        <span class="ios-strip-item">
          <span class="ios-dot" style="background:#34c759;"></span>
          <span class="ios-secondary">OUT</span> <span class="ios-primary ios-fw6 ios-mono">${fmt(msgs.outgoing || 0)}</span>
        </span>
        ${unread > 0 ? `<span class="ios-strip-item ios-danger">
          <span class="ios-dot" style="background:#ff3b30;"></span>ยังไม่อ่าน ${fmt(unread)}
        </span>` : ''}
        <span class="ios-secondary">·</span>
        <span class="ios-secondary">ผู้ส่ง ${fmt(msgs.senders || 0)} ราย</span>`;
    }

    // 7-day trend chart — iOS style
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
                backgroundColor: 'rgba(0,122,255,0.72)',
                borderColor: '#007aff',
                borderWidth: 1,
                borderRadius: 5,
                yAxisID: 'y1',
              },
              {
                label: 'ยอด (฿K)',
                data: trend.map(t => Math.round(parseFloat(t.amount || 0) / 1000)),
                type: 'line',
                borderColor: '#34c759',
                backgroundColor: 'rgba(52,199,89,0.12)',
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointBackgroundColor: '#34c759',
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
              legend: { labels: { boxWidth: 10, padding: 10, color: '#8e8e93', font: { weight: '500', size: 10 } } },
              tooltip: { 
                backgroundColor: 'rgba(255,255,255,0.95)',
                titleColor: '#1c1c1e',
                bodyColor: '#3c3c43',
                borderColor: 'rgba(60,60,67,0.12)',
                borderWidth: 1,
                cornerRadius: 8,
                padding: 10,
                callbacks: { label: ctx => ctx.dataset.yAxisID === 'y2' ? ` ฿${ctx.raw.toLocaleString('th-TH')}K` : ` ${ctx.raw} ออเดอร์` } 
              },
            },
            scales: {
              x:  { grid: { color: 'rgba(60,60,67,0.12)', drawBorder: false }, ticks: { color: '#8e8e93', maxRotation: 0, font: { weight: '500', size: 10 } } },
              y1: { position: 'left',  grid: { color: 'rgba(60,60,67,0.12)', drawBorder: false }, ticks: { color: '#8e8e93', font: { size: 10 } }, title: { display: true, text: 'ออเดอร์', color: '#8e8e93', font: { size: 10 } } },
              y2: { position: 'right', grid: { display: false }, ticks: { color: '#34c759', font: { size: 10 }, callback: v => `฿${v}K` } },
            },
          },
        });
      } else {
        trendEl.innerHTML = `<div class="ios-empty-state">ไม่มีข้อมูล trend 7 วัน</div>`;
      }
    }

    // Hourly chart — iOS style
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
              { label: 'IN',  data: hLabels.map(h => inMap[h]  || 0), backgroundColor: 'rgba(0,122,255,0.72)', borderColor: '#007aff', borderWidth: 1, borderRadius: 3 },
              { label: 'OUT', data: hLabels.map(h => outMap[h] || 0), backgroundColor: 'rgba(52,199,89,0.6)',  borderColor: '#34c759', borderWidth: 1, borderRadius: 3 },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
              legend: { labels: { boxWidth: 8, padding: 8, color: '#8e8e93', font: { weight: '500', size: 10 } } },
              tooltip: { 
                backgroundColor: 'rgba(255,255,255,0.95)',
                titleColor: '#1c1c1e',
                bodyColor: '#3c3c43',
                borderColor: 'rgba(60,60,67,0.12)',
                borderWidth: 1,
                cornerRadius: 8,
                padding: 10,
              }
            },
            scales: {
              x: { grid: { display: false }, ticks: { color: '#8e8e93', maxTicksLimit: 13, callback: (v, i) => i % 4 === 0 ? `${i}h` : '', font: { weight: '500', size: 9 } } },
              y: { grid: { color: 'rgba(60,60,67,0.12)', drawBorder: false }, ticks: { color: '#8e8e93', font: { size: 10 } } },
            },
          },
        });
      } else {
        hourlyEl.innerHTML = `<div class="ios-empty-state">ยังไม่มีข้อมูลรายชั่วโมงวันนี้</div>`;
      }
    }

  } catch (e) {
    setEl('exec-kpi', `<div style="grid-column:span 6;">${errEl('โหลด daily report ไม่ได้: ' + e.message)}</div>`);
  }
}

// ═══════════════════════════════════════════
// SECTION 2 — TRAFFIC
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
      { icon: '📨', label: 'ข้อความเข้า',   today: todayIn,    yday: ydayIn,    avg: avgIn,  col: '#007aff' },
      { icon: '📤', label: 'ข้อความออก',    today: todayOut,   yday: ydayOut,   avg: avgOut, col: '#34c759' },
      { icon: '👥', label: 'ผู้ใช้ที่คุย',  today: todayUsers, yday: ydayUsers, avg: null,   col: '#af52de' },
      { icon: '🆕', label: 'ลูกค้าใหม่',    today: newToday,   yday: newYday,   avg: null,   col: '#ff9500' },
    ];

    statsEl.innerHTML = rows.map(r => {
      const d = deltaText(r.today, r.yday);
      return `<div class="ios-stat-row">
        <div class="ios-stat-row-icon">${r.icon}</div>
        <div style="flex:1;">
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <span class="ios-secondary ios-fs10">${r.label}</span>
            <span class="ios-fw7 ios-mono" style="font-size:15px;color:${r.col};">${fmt(r.today)}</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-top:2px;">
            <span class="${d.cls} ios-fs10">${d.txt}</span>
            <span class="ios-tertiary ios-fs10">เมื่อวาน ${fmt(r.yday)}</span>
            ${r.avg !== null ? `<span class="ios-quaternary ios-fs10">avg7d ${fmt(r.avg)}</span>` : ''}
          </div>
        </div>
      </div>`;
    }).join('');

    // Volume chart — iOS style
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
              borderColor: '#007aff',
              backgroundColor: 'rgba(0,122,255,0.1)',
              fill: true, tension: 0.4, pointRadius: 3, pointBackgroundColor: '#007aff', borderWidth: 2,
            },
            {
              label: 'OUT',
              data: daily7.map(d => d.msg_out),
              borderColor: '#34c759',
              backgroundColor: 'rgba(52,199,89,0.1)',
              fill: true, tension: 0.4, pointRadius: 3, pointBackgroundColor: '#34c759', borderWidth: 2,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: { 
            legend: { labels: { boxWidth: 8, padding: 10, color: '#8e8e93', font: { weight: '500', size: 10 } } },
            tooltip: { 
              backgroundColor: 'rgba(255,255,255,0.95)',
              titleColor: '#1c1c1e',
              bodyColor: '#3c3c43',
              borderColor: 'rgba(60,60,67,0.12)',
              borderWidth: 1,
              cornerRadius: 8,
              padding: 10,
            }
          },
          scales: {
            x: { grid: { color: 'rgba(60,60,67,0.12)', drawBorder: false }, ticks: { color: '#8e8e93', maxRotation: 0, font: { weight: '500', size: 10 } } },
            y: { grid: { color: 'rgba(60,60,67,0.12)', drawBorder: false }, ticks: { color: '#8e8e93', font: { size: 10 } } },
          },
        },
      });
    } else if (chartEl) {
      chartEl.innerHTML = `<div class="ios-empty-state">ไม่มีข้อมูล</div>`;
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
  el.innerHTML = sk(60);

  const threshold = parseInt(document.getElementById('sla-threshold-val')?.value || 30);

  try {
    const res  = await fetch(`${API}?action=sla_breach&sla_threshold=${threshold}`);
    const json = await res.json();
    const breaches     = json.breaches || [];
    const totalWaiting = parseInt(json.total_waiting || 0);
    const breachCount  = parseInt(json.breach_count  || 0);

    const wcc = iosColorClass(totalWaiting > 0 ? 'yellow' : 'green');
    const bcc = iosColorClass(breachCount  > 0 ? 'red'    : 'green');

    el.innerHTML = `
      <div class="ios-sla-pair">
        <div class="ios-sla-box">
          <div class="ios-sla-num ${wcc.text}">${fmt(totalWaiting)}</div>
          <div class="ios-sla-label">รอตอบอยู่</div>
        </div>
        <div class="ios-sla-box">
          <div class="ios-sla-num ${bcc.text}">${fmt(breachCount)}</div>
          <div class="ios-sla-label">เกิน ${threshold} น.</div>
        </div>
      </div>
      ${breaches.length === 0
        ? okEl('ไม่มี SLA breach ขณะนี้ 🎉')
        : `<div class="ios-table-wrap">
            <table class="ios-table">
              <thead><tr>
                <th>ลูกค้า</th>
                <th style="text-align:right;">รอนาน</th>
                <th>ข้อความล่าสุด</th>
              </tr></thead>
              <tbody>
                ${breaches.map(b => {
                  const w  = parseInt(b.waiting_minutes || b.wait_minutes || b.wait_min || 0);
                  const cc = iosColorClass(w >= 60 ? 'red' : 'yellow');
                  const wt = w < 60 ? `${w} น.` : `${Math.floor(w/60)} ชม. ${w%60} น.`;
                  return `<tr>
                    <td class="ios-primary">${b.customer_name || b.name || `#${b.conversation_id || b.id || '?'}`}</td>
                    <td class="ios-mono ${cc.text}" style="text-align:right;white-space:nowrap;">${wt}</td>
                    <td class="ios-tertiary" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${b.last_message || '—'}</td>
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
// SECTION 4 — ADMIN PERFORMANCE
// ═══════════════════════════════════════════

async function loadAdminSection() {
  const perfEl = document.getElementById('admin-perf-table');
  if (!perfEl) return;
  perfEl.innerHTML = sk(100);

  try {
    const pr = await fetch(`${API}?action=response_by_admin&days=7`);
    const pj = await pr.json();
    const perf = (pj.data || []).sort((a, b) => parseInt(a.avg_sec) - parseInt(b.avg_sec));

    if (!perf.length) {
      perfEl.innerHTML = `<div class="ios-empty-state">ไม่มีข้อมูลการตอบ 7 วัน</div>`;
    } else {
      perfEl.innerHTML = `
        <div class="ios-table-wrap">
          <table class="ios-table">
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
                const cc  = iosColorClass(sc);
                const p5  = pct(d.under_5min, d.conversations);
                const p30 = pct(d.over_30min, d.conversations);
                const icon = parseInt(d.admin_id) === 0 ? '🤖' : '👤';
                return `<tr>
                  <td>
                    <span style="display:inline-flex;align-items:center;gap:5px;">
                      <span class="ios-avatar">${icon}</span>
                      <span class="ios-primary">${d.admin_name || `Admin #${d.admin_id}`}</span>
                    </span>
                  </td>
                  <td class="ios-mono ios-secondary" style="text-align:right;">${fmt(d.conversations)}</td>
                  <td class="ios-mono ${cc.text}" style="text-align:right;">${secToText(d.avg_sec)}</td>
                  <td class="ios-tertiary" style="text-align:right;">${p5}%</td>
                  <td class="ios-tertiary" style="text-align:right;">${p30}%</td>
                  <td style="text-align:right;"><span class="ios-badge ios-badge-${sc === 'green' ? 'green' : sc === 'yellow' ? 'yellow' : 'red'}">${speedLabel(d.avg_sec)}</span></td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
        <div class="ios-hint">🟢 เร็ว &lt;15น. · 🟡 ปานกลาง 15-50น. · 🔴 ช้า &gt;50น. · (7 วัน)</div>`;
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
  bannerEl.innerHTML = sk(40);
  if (tableEl) tableEl.innerHTML = sk(160);

  try {
    const res  = await fetch(`${PRODUCT_API}?action=overview&days=7`);
    const json = await res.json();
    const products = json.products || [];
    const lowList  = json.low_stock_alerts || [];

    // Banner
    if (lowList.length > 0) {
      bannerEl.innerHTML = `<div class="ios-alert ios-alert-danger">
        <div class="ios-alert-title">
          ⚠️ สินค้าที่ถูกถามแต่ stock ต่ำ
          <span class="ios-badge ios-badge-red">${lowList.length} รายการ</span>
        </div>
        <div class="ios-table-wrap" style="margin-top:6px;">
          <table class="ios-table">
            <thead><tr>
              <th style="width:68px;">รหัส</th>
              <th>ชื่อสินค้า</th>
              <th style="text-align:right;">Stock</th>
              <th style="text-align:right;">จำนวนถาม</th>
              <th>ราคา</th>
            </tr></thead>
            <tbody>
              ${lowList.slice(0, 10).map(p => {
                const critical = parseInt(p.live_qty || 0) === 0;
                return `<tr ${critical ? 'class="ios-row-critical"' : ''}>
                  <td class="ios-mono ios-tertiary ios-fs10">${p.product_code}</td>
                  <td style="max-width:180px;">
                    <div class="ios-primary ios-fs11" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${critical ? '🔴 ' : '🟡 '}${p.name}</div>
                    ${p.generic_name ? `<div class="ios-tertiary ios-fs10">${p.generic_name}</div>` : ''}
                  </td>
                  <td style="text-align:right;">${stockBadge(p.live_qty)}</td>
                  <td style="text-align:right;">${mentionBadge(p.mention_count)}</td>
                  <td class="ios-tertiary ios-fs10">${p.online_price ? `฿${p.online_price}` : '—'}</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
    } else {
      bannerEl.innerHTML = `<div class="ios-alert ios-alert-success">
        ✅ ไม่มีสินค้าที่ถูกถามแล้วใกล้หมด stock
        <span class="ios-tertiary ios-fs10" style="margin-left:10px;">สินค้าที่มีการถาม ${fmt(products.length)} รายการ</span>
      </div>`;
    }

    // Trending table
    if (tableEl && products.length) {
      tableEl.innerHTML = `
        <div class="ios-table-wrap" style="max-height:360px;">
          <table class="ios-table">
            <thead style="position:sticky;top:0;background:rgba(255,255,255,0.96);z-index:2;backdrop-filter:blur(10px);">
              <tr>
                <th style="width:24px;text-align:right;">#</th>
                <th>สินค้า</th>
                <th style="text-align:right;">Stock</th>
                <th style="text-align:right;">ถาม</th>
                <th>ราคา</th>
                <th>ลูกค้าที่ถาม</th>
              </tr>
            </thead>
            <tbody>
              ${products.slice(0, 25).map((p, i) => {
                const mentioners = (p.mentioners || []).slice(0, 2).join(', ');
                const more       = (p.mentioners || []).length - 2;
                const catShort   = (p.category || '').replace(/^[A-Z]{2,3}-\d{2}-/, '').slice(0, 10);
                return `<tr>
                  <td class="ios-tertiary ios-fs10 ios-mono" style="text-align:right;">${i + 1}</td>
                  <td>
                    <div class="ios-primary" style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${p.name}">${p.name}</div>
                    ${p.generic_name ? `<div class="ios-tertiary ios-fs10">${p.generic_name.slice(0, 40)}</div>` : ''}
                    ${catShort ? `<div class="ios-quaternary ios-fs10">${catShort}</div>` : ''}
                  </td>
                  <td style="text-align:right;">${stockBadge(p.live_qty)}</td>
                  <td style="text-align:right;">${mentionBadge(p.mention_count)}</td>
                  <td class="ios-tertiary ios-fs10">${p.online_price ? `฿${p.online_price}` : '—'}</td>
                  <td class="ios-tertiary ios-fs10" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    ${mentioners}${more > 0 ? ` +${more}` : ''}
                  </td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>`;
    } else if (tableEl) {
      tableEl.innerHTML = `<div class="ios-empty-state">ไม่มีข้อมูลสินค้า</div>`;
    }

  } catch (e) {
    if (bannerEl) bannerEl.innerHTML = errEl('โหลด product intelligence ไม่ได้: ' + e.message);
  }
}

// ═══════════════════════════════════════════
// SECTION 6 — CUSTOMER JOURNEY (Direct)
// ═══════════════════════════════════════════

async function loadCustomerJourney() {
  const el = document.getElementById('journey-content');
  if (!el) return;
  el.innerHTML = sk(80);

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
      <div class="ios-conv-row">
        <div class="ios-conv-cell">
          <div class="ios-conv-num ios-blue">${fmt(inboxUsers)}</div>
          <div class="ios-conv-label">ผู้ใช้ใน Inbox</div>
        </div>
        <div class="ios-conv-arrow">→</div>
        <div class="ios-conv-cell">
          <div class="ios-conv-num ios-success">${fmt(hasOrders)}</div>
          <div class="ios-conv-label">มีออเดอร์</div>
        </div>
        <div class="ios-conv-arrow">→</div>
        <div class="ios-conv-cell">
          <div class="ios-conv-num ios-purple">${fmt(repeatCust)}</div>
          <div class="ios-conv-label">ลูกค้าซ้ำ</div>
        </div>
      </div>
      <div class="ios-conv-rate">
        <span class="ios-secondary ios-fs10">Inbox → Order Conversion</span>
        <span class="ios-fw7 ios-mono" style="font-size:18px;color:${crPct >= 20 ? '#34c759' : crPct >= 5 ? '#ff9500' : '#ff3b30'};">${crPct}%</span>
      </div>`;

    const stages = data.consultation_stages || [];
    if (stages.length > 0) {
      const FUNNEL = ['#007aff','#5856d6','#af52de','#ff2d55','#ff3b30'];
      const maxC   = Math.max(...stages.map(s => parseInt(s.count || s.users || 0)), 1);
      html += `<div style="margin-top:10px;">
        <div class="ios-tertiary ios-fs10 ios-fw7" style="letter-spacing:0.1em;text-transform:uppercase;margin-bottom:8px;">Consultation Funnel</div>
        ${stages.map((s, i) => {
          const count = parseInt(s.count || s.users || 0);
          const w     = Math.round(count / maxC * 100);
          const color = FUNNEL[i % FUNNEL.length];
          const name  = s.stage_name || s.stage || s.name || `Stage ${i+1}`;
          const prev  = i > 0 ? parseInt(stages[i-1].count || stages[i-1].users || 0) : null;
          const drop  = prev && prev > 0 ? Math.round((1 - count / prev) * 100) : null;
          return `<div style="margin-bottom:6px;">
            <div class="ios-workload-row">
              <span class="ios-tertiary">${name}${drop !== null ? ` <span class="ios-quaternary">↓${drop}%</span>` : ''}</span>
              <span class="ios-mono ios-fw6" style="color:${color};">${fmt(count)}</span>
            </div>
            <div class="ios-wl-track">
              <div class="ios-wl-fill" style="width:${w}%;background:linear-gradient(90deg,${color}cc,${color}44);"></div>
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

// ═══════════════════════════════════════════
// SECTION 7 — ADMIN WORKLOAD (Direct)
// ═══════════════════════════════════════════

async function loadAdminWorkload() {
  const workEl = document.getElementById('admin-workload-bars');
  if (!workEl) return;
  workEl.innerHTML = sk(80);

  try {
    const wr = await fetch(`${API}?action=admin_workload&days=7`);
    const wj = await wr.json();
    const work = (wj.data || []).filter(d =>
      !String(d.sent_by).startsWith('system:') &&
      !String(d.sent_by).startsWith('admin_')
    );

    if (!work.length) {
      workEl.innerHTML = `<div class="ios-empty-state">ไม่มีข้อมูล workload</div>`;
    } else {
      const maxTotal = Math.max(...work.map(d => parseInt(d.total_sent || 0)), 1);
      const COLS = ['#007aff','#34c759','#ff9500','#ff3b30','#af52de','#5ac8fa','#ff2d55','#64d2ff','#ff9f0a','#30d158'];
      workEl.innerHTML = `
        <div class="ios-workload-grid">
          ${work.slice(0, 12).map((d, i) => {
            const total = parseInt(d.total_sent || 0);
            const today = parseInt(d.today_sent || 0);
            const w     = Math.round(total / maxTotal * 100);
            const color = COLS[i % COLS.length];
            const name  = d.admin_name || d.sent_by || `Admin #${i+1}`;
            return `<div>
              <div class="ios-workload-row">
                <span class="ios-secondary">${name}</span>
                <span>
                  ${today > 0 ? `<span style="color:${color};font-size:9px;">+${today}</span>` : ''}
                  <span class="ios-mono ios-fw6" style="color:${color};">${fmt(total)}</span>
                </span>
              </div>
              <div class="ios-wl-track">
                <div class="ios-wl-fill" style="width:${w}%;background:linear-gradient(90deg,${color}cc,${color}44);"></div>
              </div>
            </div>`;
          }).join('')}
        </div>
        <div class="ios-hint">ข้อความที่ส่งโดยแอดมิน 7 วัน (ไม่รวม system/bot)</div>`;
    }
  } catch (e) {
    workEl.innerHTML = errEl('โหลด workload ไม่ได้: ' + e.message);
  }
}

// ═══════════════════════════════════════════
// SECTION 8 — SENTIMENT (Direct)
// ═══════════════════════════════════════════

const SENT_COLORS = {
  'รอตอบนาน':   { bg: 'rgba(255,59,48,0.72)',   border: '#ff3b30' },
  'เชิงบวก':    { bg: 'rgba(52,199,89,0.72)',  border: '#34c759' },
  'ร้องเรียน':  { bg: 'rgba(255,149,0,0.72)',  border: '#ff9500' },
  'ต้องติดตาม': { bg: 'rgba(0,122,255,0.72)',  border: '#007aff' },
  'ไม่พอใจ':    { bg: 'rgba(175,82,222,0.72)',  border: '#af52de' },
  'เร่งด่วน':   { bg: 'rgba(255,204,0,0.72)',   border: '#ffcc00' },
};
const SENT_FB = [
  { bg: 'rgba(0,122,255,0.72)',  border: '#007aff' },
  { bg: 'rgba(90,200,250,0.72)',  border: '#5ac8fa' },
  { bg: 'rgba(255,45,85,0.72)',  border: '#ff2d55' },
  { bg: 'rgba(255,59,48,0.6)', border: '#ff3b30' },
];

async function loadSentiment() {
  const wrapEl = document.getElementById('sentiment-canvas-wrap');
  const tagsEl = document.getElementById('sentiment-tags');
  if (!wrapEl) return;
  wrapEl.innerHTML = sk(120);

  try {
    const res  = await fetch(`${API}?action=sentiment_summary&days=7`);
    const json = await res.json();
    const data = json.data || [];

    if (!data.length) {
      wrapEl.innerHTML = `<div class="ios-empty-state">ไม่มีข้อมูล sentiment 7 วัน</div>`;
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
          tooltip: { 
            backgroundColor: 'rgba(255,255,255,0.95)',
            titleColor: '#1c1c1e',
            bodyColor: '#3c3c43',
            borderColor: 'rgba(60,60,67,0.12)',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 10,
            callbacks: { label: ctx => ` ${ctx.raw.toLocaleString('th-TH')} ครั้ง (${pct(ctx.raw, total)}%)` } 
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#8e8e93', font: { weight: '500', size: 10 } } },
          y: { grid: { color: 'rgba(60,60,67,0.12)', drawBorder: false }, ticks: { color: '#8e8e93', stepSize: 1, font: { size: 10 } } },
        },
      },
    });

    if (tagsEl) {
      tagsEl.innerHTML = data.map((d, i) => {
        const p = pct(d.count, total);
        return `<span class="ios-stag" style="background:${bgs[i].replace('0.72','0.12')};color:${borders[i]};border:1px solid ${borders[i]}55;">
          ${d.tag_name || d.tag}: <strong>${fmt(d.count)}</strong> <span style="opacity:0.6;">(${p}%)</span>
        </span>`;
      }).join('');
    }

  } catch (e) {
    wrapEl.innerHTML = errEl('โหลด sentiment ไม่ได้: ' + e.message);
  }
}

// ═══════════════════════════════════════════
// SECTION 9 — FLAGGED MESSAGES (Direct)
// ═══════════════════════════════════════════

async function loadFlaggedMessages() {
  try {
    const r = await fetch(API + '?action=flagged_messages&days=7');
    const d = await r.json();
    const data = d.data;

    const fEl = document.getElementById('flagged-list');
    const fCount = document.getElementById('flagged-count');
    if (fEl) {
      if (!data || data.problematic_incoming.length === 0) {
        fEl.innerHTML = '<div class="ios-empty-state">✅ ไม่มีข้อความที่อาจเป็นปัญหาใน 7 วัน</div>';
      } else {
        fEl.innerHTML = data.problematic_incoming.slice(0, 10).map(m => {
          const cat = CATEGORY_LABELS[m.category] || '⚠️ อื่นๆ';
          const time = new Date(m.created_at).toLocaleString('th-TH', {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
          return `<div class="ios-flagged-item ios-flagged-warning">
            <div class="ios-flagged-header">
              <span class="ios-secondary">${esc(m.display_name)}</span>
              <span class="ios-tertiary ios-fs10">${time}</span>
            </div>
            <div class="ios-flagged-content">${esc(m.content)}</div>
            <span class="ios-fs10">${cat}</span>
          </div>`;
        }).join('');
      }
      if (fCount && data) fCount.textContent = '(' + data.problematic_incoming.length + ' ข้อ)';
    }
  } catch(e) {
    console.error('loadFlaggedMessages:', e);
  }
}

// ═══════════════════════════════════════════
// SECTION 10 — UNPROFESSIONAL MESSAGES (Direct)
// ═══════════════════════════════════════════

async function loadUnprofessionalMessages() {
  try {
    const r = await fetch(API + '?action=flagged_messages&days=7');
    const d = await r.json();
    const data = d.data;

    const uEl = document.getElementById('unprofessional-list');
    const uCount = document.getElementById('unprof-count');
    if (uEl) {
      if (!data || data.inappropriate_outgoing.length === 0) {
        uEl.innerHTML = '<div class="ios-empty-state">✅ ไม่พบข้อความที่ไม่เหมาะสม</div>';
      } else {
        uEl.innerHTML = data.inappropriate_outgoing.slice(0, 10).map(m => {
          const time = new Date(m.created_at).toLocaleString('th-TH', {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
          const catLabel = m.category === 'typo_garbage' ? '⌨️ กดพิมพ์ผิด' : '💬 ไม่เหมาะสม';
          return `<div class="ios-flagged-item ios-flagged-danger">
            <div class="ios-flagged-header">
              <span class="ios-danger">${esc(m.admin_name)}</span>
              <span class="ios-tertiary ios-fs10">${time}</span>
            </div>
            <div class="ios-flagged-content ios-mono">${esc(m.content)}</div>
            <span class="ios-fs10">${catLabel}</span>
          </div>`;
        }).join('');
      }
      if (uCount && data) uCount.textContent = '(' + data.inappropriate_outgoing.length + ' ข้อ)';
    }
  } catch(e) {
    console.error('loadUnprofessionalMessages:', e);
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
    const cc = iosColorClass(speedColor(avgSec));

    el.style.display = 'flex';
    el.innerHTML = `
      <span class="ios-secondary">สนทนา:</span>
      <span class="ios-primary ios-fw6 ios-mono" style="margin-left:3px;">${fmt(totalConv)}</span>
      <span class="ios-tertiary" style="margin:0 4px;">·</span>
      <span class="ios-secondary">เวลาตอบ:</span>
      <span class="${cc.text} ios-fw6 ios-mono" style="margin-left:3px;">${secToText(avgSec)}</span>
      <span class="ios-tertiary" style="margin:0 4px;">·</span>
      <span class="ios-secondary">แอดมิน:</span>
      <span class="ios-primary ios-fw6 ios-mono" style="margin-left:3px;">${data.length} คน</span>`;
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
    loadCustomerJourney(),
    loadAdminWorkload(),
    loadSentiment(),
    loadFlaggedMessages(),
    loadUnprofessionalMessages(),
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
