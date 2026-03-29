/**
 * Inbox Intelligence Dashboard — v2
 * JS Controller
 */
'use strict';

const API         = '/api/inbox-intelligence.php';
const PRODUCT_API = '/api/inbox-product-check.php';

// ─── Chart instances ──────────────────────────────────────────────
let chartResponseTime   = null;
let chartSentiment      = null;
let chartDailyTrend     = null;
let chartHourlyTraffic  = null;
let chartMsgType        = null;
let chartCategories     = null;

// ─── Chart.js global defaults ─────────────────────────────────────
Chart.defaults.color       = '#9ca3af';
Chart.defaults.font.family = "'Noto Sans Thai', sans-serif";
Chart.defaults.font.size   = 11;

// ═══════════════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════════════

function secToText(sec) {
  sec = Math.round(parseInt(sec, 10) || 0);
  if (sec <= 0) return '—';
  if (sec < 60)  return `${sec} วิ`;
  const m = Math.floor(sec / 60), s = sec % 60;
  if (m < 60) return s > 0 ? `${m} น. ${s} วิ` : `${m} น.`;
  const h = Math.floor(m / 60), rm = m % 60;
  return rm > 0 ? `${h} ชม. ${rm} น.` : `${h} ชม.`;
}


function pctDelta(oldVal, newVal) {
  const o = parseFloat(oldVal) || 0;
  const n = parseFloat(newVal) || 0;
  if (o === 0) return n > 0 ? '+100' : '0';
  const p = Math.round(((n - o) / o) * 100);
  return p >= 0 ? '+' + p : p.toString();
}

function pct(a, b) {
  const n = parseInt(a, 10) || 0, d = parseInt(b, 10) || 0;
  return d === 0 ? 0 : Math.round((n / d) * 100);
}

function fmt(n) {
  return (parseInt(n, 10) || 0).toLocaleString('th-TH');
}

function fmtBaht(n) {
  const num = parseFloat(n) || 0;
  if (num >= 1000000) return `฿${(num/1000000).toFixed(2)}M`;
  if (num >= 1000)    return `฿${(num/1000).toFixed(1)}K`;
  return `฿${Math.round(num).toLocaleString('th-TH')}`;
}

function speedColor(s) {
  s = parseInt(s, 10) || 0;
  if (s <= 300) return 'green';
  if (s <= 600) return 'yellow';
  return 'red';
}
function speedText(s) {
  const c = speedColor(s);
  return c === 'green' ? 'เร็ว' : c === 'yellow' ? 'ปานกลาง' : 'ช้า';
}

function colorClass(c) {
  if (c === 'green')  return { text:'text-emerald-400', badge:'badge-green',  bg:'bg-emerald-500/10', border:'border-emerald-500/20', hex:'#10b981' };
  if (c === 'yellow') return { text:'text-amber-400',   badge:'badge-yellow', bg:'bg-amber-500/10',   border:'border-amber-500/20',   hex:'#f59e0b' };
  if (c === 'red')    return { text:'text-red-400',     badge:'badge-red',    bg:'bg-red-500/10',     border:'border-red-500/20',     hex:'#ef4444' };
  if (c === 'purple') return { text:'text-purple-400',  badge:'badge-blue',   bg:'bg-purple-500/10',  border:'border-purple-500/20',  hex:'#a78bfa' };
  return { text:'text-blue-400', badge:'badge-blue', bg:'bg-blue-500/10', border:'border-blue-500/20', hex:'#60a5fa' };
}

function trendArrow(current, prev) {
  const c = parseFloat(current)||0, p = parseFloat(prev)||0;
  if (p === 0) return '<span class="text-gray-500 text-xs">—</span>';
  const d = ((c-p)/p*100).toFixed(1);
  if (c > p) return `<span class="text-emerald-400 text-xs">▲ ${d}%</span>`;
  if (c < p) return `<span class="text-red-400 text-xs">▼ ${Math.abs(d)}%</span>`;
  return '<span class="text-gray-500 text-xs">= 0%</span>';
}

function stockBadge(qty) {
  if (qty === null || qty === undefined)
    return `<span style="background:rgba(107,114,128,0.1);color:#6b7280;border:1px solid rgba(107,114,128,0.3);padding:1px 6px;border-radius:9999px;font-size:10px;">ไม่มีข้อมูล</span>`;
  const q = parseInt(qty, 10);
  if (q === 0)  return `<span class="badge badge-red" style="font-size:10px;">หมด</span>`;
  if (q <= 3)   return `<span class="badge badge-red" style="font-size:10px;">เหลือ ${q}</span>`;
  if (q <= 10)  return `<span class="badge badge-yellow" style="font-size:10px;">เหลือ ${q}</span>`;
  if (q <= 20)  return `<span class="badge badge-yellow" style="font-size:10px;">${q}</span>`;
  return `<span class="badge badge-green" style="font-size:10px;">${q}</span>`;
}

function mentionBadge(count) {
  const n = parseInt(count, 10)||0;
  if (n >= 50) return `<span class="badge badge-red">🔥 ${n}</span>`;
  if (n >= 20) return `<span class="badge badge-yellow">⚡ ${n}</span>`;
  if (n >= 10) return `<span class="badge badge-blue">${n}</span>`;
  return `<span style="color:#6b7280;font-size:11px;">${n}</span>`;
}

function emptyEl(msg)   { return `<div class="empty-state">${msg}</div>`; }
function okEl(msg)      { return `<div class="empty-state-ok">✅ ${msg}</div>`; }
function loadingEl()    { return `<div class="pulsing h-32 rounded-lg" style="background:rgba(255,255,255,0.04)"></div>`; }

// ═══════════════════════════════════════════════════════════════════
// SUMMARY CARDS
// ═══════════════════════════════════════════════════════════════════
async function loadSummaryCards() {
  const el = document.getElementById('summary-cards');
  try {
    const res  = await fetch(`${API}?action=response_by_admin&days=7`);
    const json = await res.json();
    const data = json.data || [];

    let totalConv = 0, weightedAvg = 0, totalUnder5 = 0, totalOver30 = 0;
    data.forEach(d => {
      const c = parseInt(d.conversations||0);
      totalConv   += c;
      weightedAvg += (parseInt(d.avg_sec||0)) * c;
      totalUnder5 += parseInt(d.under_5min||0);
      totalOver30 += parseInt(d.over_30min||0);
    });
    const avgSec = totalConv > 0 ? Math.round(weightedAvg/totalConv) : 0;
    const p5  = pct(totalUnder5, totalConv);
    const p30 = pct(totalOver30, totalConv);
    const sc  = speedColor(avgSec);
    const rc  = totalOver30 > 0 ? 'red' : 'green';

    const cards = [
      { label:'บทสนทนาทั้งหมด', value:fmt(totalConv),    sub:'7 วันล่าสุด',              col:'blue',   icon:`<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>` },
      { label:'เวลาตอบเฉลี่ย',  value:secToText(avgSec), sub:`${p5}% ตอบภายใน 5 นาที`, col:sc,     icon:`<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>` },
      { label:'ตอบเกิน 30 นาที', value:fmt(totalOver30), sub:`${p30}% ของทั้งหมด`,      col:rc,     icon:`<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>` },
      { label:'แอดมินที่ใช้งาน', value:data.length,      sub:'มีการตอบใน 7 วัน',        col:'blue', icon:`<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>` },
    ];

    el.innerHTML = cards.map(c => {
      const cc = colorClass(c.col);
      return `<div class="card p-4 fade-in">
        <div class="flex items-start justify-between gap-2">
          <div class="flex-1 min-w-0">
            <p class="text-xs text-gray-500 mb-1.5">${c.label}</p>
            <p class="text-2xl font-bold text-white leading-none mb-1.5 truncate">${c.value}</p>
            <p class="text-xs text-gray-600">${c.sub}</p>
          </div>
          <div class="stat-icon ${cc.bg} ${cc.text} shrink-0">${c.icon}</div>
        </div>
      </div>`;
    }).join('');
  } catch(e) {
    el.innerHTML = `<div class="col-span-4 text-center py-6 text-red-400 text-sm">โหลดสรุปข้อมูลไม่ได้: ${e.message}</div>`;
  }
}

// ═══════════════════════════════════════════════════════════════════
// DAILY REPORT
// ═══════════════════════════════════════════════════════════════════
async function loadDailyReport() {
  const kpiEl    = document.getElementById('daily-kpi-cards');
  const dateEl   = document.getElementById('daily-report-date');
  const tablesEl = document.getElementById('daily-tables-row');

  try {
    const res  = await fetch(`${API}?action=daily_report`);
    const json = await res.json();
    const d    = json.data || {};

    if (dateEl) dateEl.textContent = `วันที่ ${d.date || '—'}`;

    const ot   = d.orders_today      || {};
    const oy   = d.orders_yesterday  || {};
    const cm   = d.current_month     || {};
    const pm   = d.prev_month        || {};
    const bdo  = d.bdo_today         || {};
    const slp  = d.slips_today       || {};
    const ovd  = d.overdue           || {};
    const msgs = d.messages_today    || {};

    const momAmt    = parseFloat(cm.amount||0);
    const prevAmt   = parseFloat(pm.amount||0);
    const momGrowth = prevAmt > 0 ? ((momAmt/prevAmt-1)*100).toFixed(1) : null;
    const momColor  = momGrowth > 0 ? 'green' : momGrowth < 0 ? 'red' : 'blue';

    const ordDelta    = parseInt(ot.total||0) - parseInt(oy.total||0);
    const ordDeltaStr = ordDelta >= 0 ? `+${ordDelta}` : `${ordDelta}`;

    const kpiCards = [
      { label:'ออเดอร์วันนี้',   value:fmt(ot.total||0),   sub:`${ordDeltaStr} vs เมื่อวาน · เมื่อวาน ${fmt(oy.total||0)}`, col: parseInt(ot.total||0) >= parseInt(oy.total||0) ? 'green':'yellow', emoji:'🛒' },
      { label:'ยอดขายเดือนนี้',  value:fmtBaht(cm.amount), sub:`${fmt(cm.total||0)} ออเดอร์ · ${fmt(cm.customers||0)} ลูกค้า`, col:'blue', emoji:'💰' },
      { label:'เติบโต MoM',     value: momGrowth !== null ? `${momGrowth>0?'+':''}${momGrowth}%` : '—', sub:`vs เดือนก่อน ${fmtBaht(pm.amount)}`, col:momColor, emoji:'📈' },
      { label:'BDO วันนี้',      value:fmt(bdo.total||0),  sub:`${fmtBaht(bdo.amount)} · สำเร็จ ${fmt(bdo.done||0)}`, col:'purple', emoji:'📦' },
      { label:'สลิปวันนี้',      value:fmt(slp.total||0),  sub:`${fmtBaht(slp.amount)} · รอ ${fmt(slp.pending||0)}`, col: parseInt(slp.pending||0) > 0 ? 'yellow':'green', emoji:'🧾' },
      { label:'ยอดค้างชำระ',     value:fmt(ovd.customers||0), sub:`${fmtBaht(ovd.total_amount||0)}`, col: parseInt(ovd.customers||0) > 0 ? 'red':'green', emoji:'⚠️' },
    ];

    kpiEl.innerHTML = kpiCards.map(c => {
      const cc = colorClass(c.col);
      return `<div class="card p-4 fade-in">
        <div class="flex items-start justify-between gap-2">
          <div class="flex-1 min-w-0">
            <p class="text-xs text-gray-500 mb-1">${c.label}</p>
            <p class="text-xl font-bold text-white leading-none mb-1 truncate">${c.value}</p>
            <p class="text-xs text-gray-600 truncate">${c.sub}</p>
          </div>
          <span class="text-xl shrink-0">${c.emoji}</span>
        </div>
      </div>`;
    }).join('');

    // 7-day trend chart
    const trend = d.trend_7d || [];
    if (trend.length) {
      const tArea = document.getElementById('daily-trend-area');
      tArea.innerHTML = '<canvas id="dailyTrendChart"></canvas>';
      if (chartDailyTrend) chartDailyTrend.destroy();
      const ctx = document.getElementById('dailyTrendChart').getContext('2d');
      chartDailyTrend = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: trend.map(t => t.day),
          datasets: [
            { label:'ออเดอร์', data: trend.map(t=>t.orders), backgroundColor:'rgba(59,130,246,0.6)', borderColor:'#3b82f6', borderWidth:1, borderRadius:4, yAxisID:'y1' },
            { label:'ยอด (฿K)', data: trend.map(t=>Math.round(parseFloat(t.amount||0)/1000)), type:'line', borderColor:'#10b981', backgroundColor:'rgba(16,185,129,0.08)', fill:true, tension:0.4, pointRadius:3, yAxisID:'y2' },
          ],
        },
        options: {
          responsive:true, maintainAspectRatio:false,
          interaction:{ mode:'index', intersect:false },
          plugins:{
            legend:{ labels:{ boxWidth:12, padding:10 } },
            tooltip:{ callbacks:{ label: ctx => ctx.dataset.label === 'ยอด (฿K)' ? ` ฿${(ctx.raw).toLocaleString('th-TH')}K` : ` ${ctx.raw} ออเดอร์` } },
          },
          scales:{
            x:{ grid:{ color:'#1e2d45' }, ticks:{ maxRotation:0 } },
            y1:{ grid:{ color:'#253047' }, ticks:{ callback: v => v }, title:{ display:true, text:'ออเดอร์', color:'#4b5563' }, position:'left' },
            y2:{ grid:{ display:false }, ticks:{ callback: v => `฿${v}K` }, position:'right' },
          },
        },
      });
    }

    // Hourly traffic chart
    const hourly = d.hourly_traffic || [];
    if (hourly.length) {
      const hArea = document.getElementById('hourly-traffic-area');
      hArea.innerHTML = '<canvas id="hourlyTrafficChart"></canvas>';
      if (chartHourlyTraffic) chartHourlyTraffic.destroy();
      const hLabels = Array.from({length:24}, (_,i) => `${i}:00`);
      const inMap = {}, outMap = {};
      hourly.forEach(h => { inMap[h.hour] = parseInt(h.incoming||0); outMap[h.hour] = parseInt(h.outgoing||0); });
      const inData  = hLabels.map((_,i) => inMap[i]  || 0);
      const outData = hLabels.map((_,i) => outMap[i] || 0);
      const ctx2 = document.getElementById('hourlyTrafficChart').getContext('2d');
      chartHourlyTraffic = new Chart(ctx2, {
        type: 'bar',
        data: {
          labels: hLabels,
          datasets: [
            { label:'Incoming', data:inData,  backgroundColor:'rgba(139,92,246,0.6)', borderColor:'#8b5cf6', borderWidth:1, borderRadius:3 },
            { label:'Outgoing', data:outData, backgroundColor:'rgba(16,185,129,0.4)', borderColor:'#10b981', borderWidth:1, borderRadius:3 },
          ],
        },
        options: {
          responsive:true, maintainAspectRatio:false,
          plugins:{ legend:{ labels:{ boxWidth:10, padding:8 } } },
          scales:{
            x:{ grid:{ display:false }, ticks:{ maxRotation:0, maxTicksLimit:12, callback:(v,i)=>i%3===0?`${i}:00`:'' } },
            y:{ grid:{ color:'#253047' }, ticks:{ stepSize:1 } },
          },
        },
      });
    }

    // Tables
    const topAdmins    = d.top_admins    || [];
    const topCustomers = d.top_customers || [];
    const salespeople  = d.salespeople   || [];
    const overdueTop   = d.overdue_top   || [];

    let tabHtml = '';

    // Top admins
    if (topAdmins.length) {
      const maxAdmin = Math.max(...topAdmins.map(a=>parseInt(a.count||0)), 1);
      tabHtml += `<div class="card-sm p-4">
        <p class="section-title text-xs mb-3">👑 Top Admin วันนี้</p>
        <div class="space-y-2">
          ${topAdmins.slice(0,8).map(a => {
            const w = Math.round(parseInt(a.count||0)/maxAdmin*100);
            return `<div>
              <div class="flex justify-between text-xs mb-0.5">
                <span class="text-gray-300 truncate">${a.sent_by || `Admin`}</span>
                <span class="text-blue-400 font-mono shrink-0 ml-2">${fmt(a.count)}</span>
              </div>
              <div style="height:6px;background:#0f1623;border-radius:3px;overflow:hidden;">
                <div style="width:${w}%;height:100%;background:linear-gradient(90deg,#60a5fa,#3b82f6);border-radius:3px;"></div>
              </div>
            </div>`;
          }).join('')}
        </div>
      </div>`;
    }

    // Top customers
    if (topCustomers.length) {
      tabHtml += `<div class="card-sm p-4">
        <p class="section-title text-xs mb-3">🏆 Top Customers</p>
        <table class="data-table">
          <thead><tr>
            <th class="text-left">ลูกค้า</th>
            <th class="text-right">ออเดอร์</th>
            <th class="text-right">ยอด</th>
          </tr></thead>
          <tbody>
            ${topCustomers.slice(0,8).map(c=>`<tr>
              <td class="text-gray-300 truncate" style="max-width:120px;">${c.customer_name||c.customer_ref||'—'}</td>
              <td class="text-right font-mono text-blue-400">${fmt(c.orders)}</td>
              <td class="text-right font-mono text-emerald-400">${fmtBaht(c.amount)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
    }

    // Salespeople
    if (salespeople.length) {
      tabHtml += `<div class="card-sm p-4">
        <p class="section-title text-xs mb-3">👤 Salespeople</p>
        <table class="data-table">
          <thead><tr>
            <th class="text-left">พนักงาน</th>
            <th class="text-right">ออเดอร์</th>
            <th class="text-right">ยอด</th>
          </tr></thead>
          <tbody>
            ${salespeople.slice(0,8).map(s=>`<tr>
              <td class="text-gray-300 truncate" style="max-width:120px;">${s.salesperson_name||`#${s.salesperson_id}`}</td>
              <td class="text-right font-mono text-blue-400">${fmt(s.orders)}</td>
              <td class="text-right font-mono text-emerald-400">${fmtBaht(s.amount)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
    }

    // Overdue top
    if (overdueTop.length) {
      tabHtml += `<div class="card-sm p-4">
        <p class="section-title text-xs mb-3 text-red-400">⚠️ ค้างชำระสูงสุด</p>
        <table class="data-table">
          <thead><tr>
            <th class="text-left">ลูกค้า</th>
            <th class="text-right">ค้างชำระ</th>
          </tr></thead>
          <tbody>
            ${overdueTop.slice(0,5).map(o=>`<tr>
              <td class="text-gray-300 truncate" style="max-width:130px;">${o.customer_name||o.customer_ref||'—'}</td>
              <td class="text-right font-mono text-red-400">${fmtBaht(o.overdue_amount)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
    }

    if (!tabHtml) {
      tabHtml = `<div class="col-span-3"><p class="text-xs text-gray-600 text-center py-3">ยังไม่มีข้อมูล top customers / admins วันนี้</p></div>`;
    }

    tablesEl.innerHTML = tabHtml;

    // Messages today
    const msgEl = document.getElementById('daily-msgs-today');
    if (msgEl) {
      msgEl.innerHTML = `<span class="text-xs text-gray-500">ข้อความวันนี้:</span>
        <span class="badge badge-blue ml-1">IN ${fmt(msgs.incoming||0)}</span>
        <span class="badge badge-green ml-1">OUT ${fmt(msgs.outgoing||0)}</span>
        ${parseInt(msgs.unread||0)>0 ? `<span class="badge badge-red ml-1">🔔 ยังไม่อ่าน ${fmt(msgs.unread)}</span>` : ''}`;
    }

  } catch(e) {
    if (kpiEl) kpiEl.innerHTML = `<div class="col-span-3 text-center py-4 text-red-400 text-sm">โหลด daily report ไม่ได้: ${e.message}</div>`;
  }
}

// ═══════════════════════════════════════════════════════════════════
// RESPONSE TIME CHART + TABLE
// ═══════════════════════════════════════════════════════════════════
async function loadResponseTime() {
  const chartArea = document.getElementById('response-time-chart-area');
  const tableEl   = document.getElementById('response-time-table');

  try {
    const res  = await fetch(`${API}?action=response_time&days=7`);
    const json = await res.json();
    const data = json.data || [];

    if (!data.length) {
      chartArea.innerHTML = emptyEl('ยังไม่มีข้อมูล response time ใน 7 วันนี้');
      tableEl.innerHTML = '';
      return;
    }

    chartArea.innerHTML = '<canvas id="responseTimeChart"></canvas>';
    chartArea.style.height = '200px';

    const labels  = data.map(d => (d.date||d.day||'').slice(-5));
    const avgMins = data.map(d => Math.round((parseInt(d.avg_sec||0))/60));
    const minMins = data.map(d => Math.round((parseInt(d.min_sec||0))/60));
    const maxMins = data.map(d => Math.round((parseInt(d.max_sec||0))/60));

    if (chartResponseTime) chartResponseTime.destroy();
    const ctx = document.getElementById('responseTimeChart').getContext('2d');
    chartResponseTime = new Chart(ctx, {
      type:'line',
      data:{ labels, datasets:[
        { label:'เฉลี่ย', data:avgMins, borderColor:'#10b981', backgroundColor:'rgba(16,185,129,0.08)', fill:true, tension:0.4, pointRadius:4, pointBackgroundColor:'#10b981', borderWidth:2 },
        { label:'ต่ำสุด', data:minMins, borderColor:'#60a5fa', backgroundColor:'transparent', borderDash:[5,3], tension:0.4, pointRadius:3, pointBackgroundColor:'#60a5fa', borderWidth:1.5 },
        { label:'สูงสุด', data:maxMins, borderColor:'#ef4444', backgroundColor:'transparent', borderDash:[5,3], tension:0.4, pointRadius:3, pointBackgroundColor:'#ef4444', borderWidth:1.5 },
      ]},
      options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{ mode:'index', intersect:false },
        plugins:{ legend:{ labels:{ boxWidth:12, padding:12 } }, tooltip:{ callbacks:{ label: ctx => ` ${ctx.dataset.label}: ${ctx.raw} น.` } } },
        scales:{
          x:{ grid:{ color:'#1e2d45' }, ticks:{ maxRotation:0 } },
          y:{ grid:{ color:'#253047' }, ticks:{ callback: v => v+' น.' }, title:{ display:true, text:'นาที', color:'#4b5563' } },
        },
      },
    });

    tableEl.innerHTML = `<table class="data-table mt-2">
      <thead><tr>
        <th class="text-left">วันที่</th>
        <th class="text-right">เฉลี่ย</th>
        <th class="text-right">ต่ำสุด</th>
        <th class="text-right">สูงสุด</th>
        <th class="text-right hidden sm:table-cell">ทั้งหมด</th>
        <th class="text-right hidden sm:table-cell">&lt;5น.</th>
        <th class="text-right hidden sm:table-cell">&lt;30น.</th>
      </tr></thead>
      <tbody>
        ${data.map(d => {
          const total = parseInt(d.total||d.conversations||0);
          const sc    = colorClass(speedColor(d.avg_sec));
          return `<tr>
            <td class="text-gray-400">${d.date||d.day||'—'}</td>
            <td class="text-right font-mono ${sc.text}">${secToText(d.avg_sec)}</td>
            <td class="text-right font-mono text-blue-400">${secToText(d.min_sec)}</td>
            <td class="text-right font-mono text-red-400">${secToText(d.max_sec)}</td>
            <td class="text-right hidden sm:table-cell">${fmt(total)}</td>
            <td class="text-right hidden sm:table-cell">${pct(d.under_5min, total)}%</td>
            <td class="text-right hidden sm:table-cell">${pct(d.under_30min, total)}%</td>
          </tr>`;
        }).join('')}
      </tbody>
    </table>`;

  } catch(e) {
    chartArea.innerHTML = emptyEl('โหลด response time ไม่ได้');
    tableEl.innerHTML = '';
  }
}

// ═══════════════════════════════════════════════════════════════════
// ADMIN PERFORMANCE TABLE
// ═══════════════════════════════════════════════════════════════════
async function loadAdminPerformance() {
  const el = document.getElementById('admin-performance-table');
  el.innerHTML = loadingEl();
  try {
    const res  = await fetch(`${API}?action=response_by_admin&days=7`);
    const json = await res.json();
    const data = (json.data||[]).slice().sort((a,b)=>parseInt(a.avg_sec)-parseInt(b.avg_sec));

    if (!data.length) { el.innerHTML = emptyEl('ไม่มีข้อมูลแอดมิน'); return; }

    el.innerHTML = `<table class="data-table">
      <thead><tr>
        <th class="text-left">แอดมิน</th>
        <th class="text-right">สนทนา</th>
        <th class="text-right">เฉลี่ย</th>
        <th class="text-right hidden sm:table-cell">&lt;5น.</th>
        <th class="text-right hidden sm:table-cell">&gt;30น.</th>
        <th class="text-right">สถานะ</th>
      </tr></thead>
      <tbody>
        ${data.map(d => {
          const sc  = speedColor(d.avg_sec);
          const cc  = colorClass(sc);
          const p5  = pct(d.under_5min,  d.conversations);
          const p30 = pct(d.over_30min,  d.conversations);
          const icon = parseInt(d.admin_id)===0 ? '🤖' : '👤';
          const name = d.admin_name || `Admin #${d.admin_id}`;
          return `<tr>
            <td>
              <span class="inline-flex items-center gap-1.5">
                <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs ${cc.bg}">${icon}</span>
                <span class="text-gray-300">${name}</span>
              </span>
            </td>
            <td class="text-right">${fmt(d.conversations)}</td>
            <td class="text-right font-mono ${cc.text}">${secToText(d.avg_sec)}</td>
            <td class="text-right hidden sm:table-cell text-gray-500">${p5}%</td>
            <td class="text-right hidden sm:table-cell text-gray-500">${p30}%</td>
            <td class="text-right"><span class="badge ${cc.badge}">${speedText(d.avg_sec)}</span></td>
          </tr>`;
        }).join('')}
      </tbody>
    </table>
    <p class="text-xs text-gray-600 mt-3">🟢 เร็ว = avg &lt;5น. · 🟡 ปานกลาง = 5-10น. · 🔴 ช้า = &gt;10น.</p>`;
  } catch(e) {
    el.innerHTML = emptyEl('โหลดข้อมูลแอดมินไม่ได้');
  }
}

// ═══════════════════════════════════════════════════════════════════
// SLA MONITOR
// ═══════════════════════════════════════════════════════════════════
async function loadSLA() {
  const threshold = parseInt(document.getElementById('sla-threshold').value)||30;
  const summaryEl = document.getElementById('sla-summary');
  const tableEl   = document.getElementById('sla-table');

  summaryEl.innerHTML = '<div class="col-span-2 py-2 pulsing" style="height:64px;background:rgba(255,255,255,0.04);border-radius:8px;"></div>';
  tableEl.innerHTML = '';

  try {
    const res  = await fetch(`${API}?action=sla_breach&sla_threshold=${threshold}`);
    const json = await res.json();
    const breaches    = json.breaches     || [];
    const totalWaiting = json.total_waiting || 0;
    const breachCount  = json.breach_count  || 0;

    const wc = colorClass(totalWaiting > 0 ? 'yellow' : 'green');
    const bc = colorClass(breachCount  > 0 ? 'red'    : 'green');

    summaryEl.innerHTML = `
      <div class="card-sm p-3 ${wc.bg} border ${wc.border}">
        <p class="text-xs text-gray-400 mb-0.5">รอตอบทั้งหมด</p>
        <p class="text-3xl font-bold ${wc.text}">${fmt(totalWaiting)}</p>
        <p class="text-xs text-gray-500">บทสนทนา</p>
      </div>
      <div class="card-sm p-3 ${bc.bg} border ${bc.border}">
        <p class="text-xs text-gray-400 mb-0.5">เกิน ${threshold} น.</p>
        <p class="text-3xl font-bold ${bc.text}">${fmt(breachCount)}</p>
        <p class="text-xs text-gray-500">SLA breach</p>
      </div>`;

    if (!breaches.length) { tableEl.innerHTML = okEl('ไม่มี SLA breach ขณะนี้'); return; }

    tableEl.innerHTML = `<table class="data-table">
      <thead><tr>
        <th class="text-left">ลูกค้า</th>
        <th class="text-right">รอ (น.)</th>
        <th class="text-left pl-3 hidden sm:table-cell">ข้อความล่าสุด</th>
      </tr></thead>
      <tbody>
        ${breaches.map(b => {
          const w = parseInt(b.waiting_minutes||b.wait_minutes||b.wait_min||0);
          const wc2 = colorClass(w>=60?'red':'yellow');
          const wt = w<60?`${w} น.`:`${Math.floor(w/60)} ชม. ${w%60} น.`;
          return `<tr>
            <td class="text-gray-300">${b.customer_name||b.name||`#${b.conversation_id||b.id||'?'}`}</td>
            <td class="text-right font-mono ${wc2.text}">${wt}</td>
            <td class="pl-3 hidden sm:table-cell text-gray-600" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${b.last_message||'—'}</td>
          </tr>`;
        }).join('')}
      </tbody>
    </table>`;
  } catch(e) {
    summaryEl.innerHTML = `<div class="col-span-2 text-center py-4 text-red-400 text-sm">โหลด SLA ไม่ได้: ${e.message}</div>`;
  }
}

// ═══════════════════════════════════════════════════════════════════
// SENTIMENT
// ═══════════════════════════════════════════════════════════════════
const TAG_LABEL = { complaint:'ร้องเรียน', dissatisfied:'ไม่พอใจ', follow_up:'ติดตาม', positive:'เชิงบวก', neutral:'ปกติ', negative:'เชิงลบ', urgent:'เร่งด่วน' };
const TAG_COLOR = {
  complaint:    { bg:'rgba(239,68,68,0.65)',   border:'#ef4444' },
  dissatisfied: { bg:'rgba(245,158,11,0.65)',  border:'#f59e0b' },
  follow_up:    { bg:'rgba(59,130,246,0.65)',  border:'#3b82f6' },
  positive:     { bg:'rgba(16,185,129,0.65)',  border:'#10b981' },
  neutral:      { bg:'rgba(107,114,128,0.65)', border:'#6b7280' },
  negative:     { bg:'rgba(239,68,68,0.50)',   border:'#f87171' },
  urgent:       { bg:'rgba(234,179,8,0.65)',   border:'#eab308' },
};
const DEFAULT_COLORS = [
  { bg:'rgba(139,92,246,0.65)',  border:'#8b5cf6' },
  { bg:'rgba(20,184,166,0.65)',  border:'#14b8a6' },
  { bg:'rgba(236,72,153,0.65)', border:'#ec4899' },
];

// Thai tag name mapping
function tagLabel(raw) {
  const map = { 'ร้องเรียน':'ร้องเรียน', 'ต้องติดตาม':'ต้องติดตาม', 'เชิงบวก':'เชิงบวก', 'รอตอบนาน':'รอตอบนาน', 'ไม่พอใจ':'ไม่พอใจ' };
  return TAG_LABEL[raw] || map[raw] || raw;
}

async function loadSentiment() {
  const chartArea = document.getElementById('sentiment-chart-area');
  const tagsEl    = document.getElementById('sentiment-tags');

  try {
    const res  = await fetch(`${API}?action=sentiment_summary&days=7`);
    const json = await res.json();
    const data = json.data || [];

    if (!data.length) {
      chartArea.innerHTML = emptyEl('ยังไม่มีข้อมูล sentiment tags ใน 7 วันนี้');
      tagsEl.innerHTML = '';
      return;
    }

    chartArea.innerHTML = '<canvas id="sentimentChart"></canvas>';
    chartArea.style.height = '190px';

    const labels   = data.map(d => tagLabel(d.tag_name || d.tag));
    const counts   = data.map(d => parseInt(d.count||0));
    const tagKey   = data.map(d => d.tag_name || d.tag);
    const bgColors = tagKey.map((t,i) => (TAG_COLOR[t] || DEFAULT_COLORS[i%DEFAULT_COLORS.length]).bg);
    const brColors = tagKey.map((t,i) => (TAG_COLOR[t] || DEFAULT_COLORS[i%DEFAULT_COLORS.length]).border);

    if (chartSentiment) chartSentiment.destroy();
    const ctx = document.getElementById('sentimentChart').getContext('2d');
    chartSentiment = new Chart(ctx, {
      type:'bar',
      data:{ labels, datasets:[{ label:'จำนวน', data:counts, backgroundColor:bgColors, borderColor:brColors, borderWidth:1, borderRadius:5 }] },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label: ctx => ` ${ctx.raw.toLocaleString('th-TH')} ครั้ง` } } },
        scales:{ x:{ grid:{ display:false } }, y:{ grid:{ color:'#253047' }, ticks:{ stepSize:1 } } },
      },
    });

    const total = counts.reduce((a,b)=>a+b,0);
    tagsEl.innerHTML = data.map((d,i) => {
      const p = pct(d.count, total);
      return `<span style="background:${bgColors[i].replace('0.65','0.15')};color:${brColors[i]};border:1px solid ${brColors[i]}50;padding:3px 9px;border-radius:9999px;font-size:11px;">
        ${labels[i]}: ${fmt(d.count)} (${p}%)
      </span>`;
    }).join('');
  } catch(e) {
    chartArea.innerHTML = emptyEl('โหลด sentiment ไม่ได้');
  }
}

// ═══════════════════════════════════════════════════════════════════
// PEAK HOURS HEATMAP
// ═══════════════════════════════════════════════════════════════════
async function loadPeakHours() {
  const el = document.getElementById('peak-hours-content');
  if (!el) return;

  try {
    const res  = await fetch(`${API}?action=peak_hours&days=7`);
    const json = await res.json();
    const data = json.data || [];

    if (!data.length) { el.innerHTML = emptyEl('ยังไม่มีข้อมูล heatmap'); return; }

    // Build 7×24 matrix (dow 1=Sun, 2=Mon…7=Sat)
    const matrix = {};
    let maxVal = 0;
    data.forEach(d => {
      const key = `${d.dow}_${d.hr}`;
      matrix[key] = parseInt(d.cnt||0);
      if (matrix[key] > maxVal) maxVal = matrix[key];
    });

    const DOW_LABELS = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
    const HOUR_LABELS = Array.from({length:24}, (_,i)=>i);

    function cellColor(val) {
      if (!val) return 'rgba(30,45,69,0.4)';
      const ratio = val / maxVal;
      if (ratio >= 0.8) return 'rgba(239,68,68,0.85)';
      if (ratio >= 0.6) return 'rgba(245,158,11,0.8)';
      if (ratio >= 0.4) return 'rgba(251,191,36,0.7)';
      if (ratio >= 0.2) return 'rgba(59,130,246,0.55)';
      return 'rgba(59,130,246,0.25)';
    }

    const rows = [2,3,4,5,6,7,1].map((dow, di) => {
      const cells = HOUR_LABELS.map(hr => {
        const cnt = matrix[`${dow}_${hr}`] || 0;
        return `<div title="${DOW_LABELS[di]} ${hr}:00 — ${cnt} ข้อความ"
          style="background:${cellColor(cnt)};border-radius:2px;aspect-ratio:1;min-width:14px;cursor:default;"
          class="heatmap-cell"></div>`;
      }).join('');
      return `<div class="flex items-center gap-1">
        <span style="width:20px;text-align:right;font-size:10px;color:#4b5563;flex-shrink:0;">${DOW_LABELS[di]}</span>
        <div style="display:grid;grid-template-columns:repeat(24,1fr);gap:2px;flex:1;">${cells}</div>
      </div>`;
    }).join('');

    const hourRow = `<div class="flex items-center gap-1 mt-1">
      <span style="width:20px;"></span>
      <div style="display:grid;grid-template-columns:repeat(24,1fr);gap:2px;flex:1;">
        ${HOUR_LABELS.map(h => `<div style="font-size:9px;color:#374151;text-align:center;">${h%6===0?h:''}</div>`).join('')}
      </div>
    </div>`;

    const legend = `<div class="flex items-center gap-2 mt-3 text-xs text-gray-500">
      <span>น้อย</span>
      ${['rgba(59,130,246,0.25)','rgba(59,130,246,0.55)','rgba(251,191,36,0.7)','rgba(245,158,11,0.8)','rgba(239,68,68,0.85)'].map(c=>`<div style="width:14px;height:14px;background:${c};border-radius:2px;"></div>`).join('')}
      <span>มาก</span>
      <span class="ml-auto text-gray-600">peak สูงสุด: ${maxVal} ข้อความ/ช่วง</span>
    </div>`;

    el.innerHTML = `<div class="space-y-1">${rows}</div>${hourRow}${legend}`;
  } catch(e) {
    el.innerHTML = emptyEl('โหลด peak hours ไม่ได้');
  }
}

// ═══════════════════════════════════════════════════════════════════
// TRAFFIC COMPARISON
// ═══════════════════════════════════════════════════════════════════
async function loadTrafficComparison() {
  const el = document.getElementById('traffic-comparison-content');
  if (!el) return;

  try {
    const res  = await fetch(`${API}?action=traffic_comparison&days=7`);
    const json = await res.json();

    if (!json || json.error) { el.innerHTML = emptyEl('ไม่มีข้อมูล'); return; }

    const todayIn    = parseInt(json.today_in||0);
    const todayOut   = parseInt(json.today_out||0);
    const ydayIn     = parseInt(json.yday_in||0);
    const ydayOut    = parseInt(json.yday_out||0);
    const avgIn      = parseInt(json.avg_in||0);
    const avgOut     = parseInt(json.avg_out||0);
    const todayUsers = parseInt(json.today_users||0);
    const ydayUsers  = parseInt(json.yday_users||0);
    const newToday   = parseInt(json.new_today||0);
    const newYday    = parseInt(json.new_yday||0);

    const metrics = [
      { label:'ข้อความ IN วันนี้',     today:todayIn,    yday:ydayIn,     avg:avgIn,    unit:'ข้อความ', icon:'📨' },
      { label:'ข้อความ OUT วันนี้',    today:todayOut,   yday:ydayOut,    avg:avgOut,   unit:'ข้อความ', icon:'📤' },
      { label:'Users ที่คุย',          today:todayUsers, yday:ydayUsers,  avg:null,     unit:'ราย',     icon:'👥' },
      { label:'ลูกค้าใหม่ใน Inbox',   today:newToday,   yday:newYday,    avg:null,     unit:'ราย',     icon:'🆕' },
    ];

    el.innerHTML = `<div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      ${metrics.map(m => {
        const diffPct = m.yday > 0 ? ((m.today - m.yday) / m.yday * 100).toFixed(0) : null;
        const diffColor = diffPct === null ? 'text-gray-500' : diffPct >= 0 ? 'text-emerald-400' : 'text-red-400';
        const diffArrow = diffPct === null ? '—' : diffPct >= 0 ? `▲ ${diffPct}%` : `▼ ${Math.abs(diffPct)}%`;
        return `<div class="cmp-card">
          <p class="text-xs text-gray-500 mb-1">${m.icon} ${m.label}</p>
          <p class="text-2xl font-bold text-white">${fmt(m.today)}</p>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-xs ${diffColor} font-medium">${diffArrow}</span>
            <span class="text-xs text-gray-600">เมื่อวาน ${fmt(m.yday)}</span>
          </div>
          ${m.avg !== null ? `<p class="text-xs text-gray-700 mt-0.5">avg 7d: ${fmt(m.avg)}</p>` : ''}
        </div>`;
      }).join('')}
    </div>`;
  } catch(e) {
    el.innerHTML = emptyEl('โหลดข้อมูล traffic ไม่ได้');
  }
}

// ═══════════════════════════════════════════════════════════════════
// MESSAGE TYPE DISTRIBUTION
// ═══════════════════════════════════════════════════════════════════
async function loadMessageTypeDist() {
  const chartArea = document.getElementById('msg-type-chart-area');
  const legendEl  = document.getElementById('msg-type-legend');
  if (!chartArea) return;

  try {
    const res  = await fetch(`${API}?action=message_type_dist&days=7`);
    const json = await res.json();
    const data = json.data || [];

    if (!data.length) { chartArea.innerHTML = emptyEl('ไม่มีข้อมูล'); return; }

    // Group by type, separate incoming/outgoing
    const types = {};
    data.forEach(d => {
      if (!types[d.message_type]) types[d.message_type] = { in:0, out:0 };
      if (d.direction === 'incoming') types[d.message_type].in  += parseInt(d.cnt||0);
      else                             types[d.message_type].out += parseInt(d.cnt||0);
    });

    // Total per type
    const sorted = Object.entries(types).sort((a,b)=>(b[1].in+b[1].out)-(a[1].in+a[1].out));
    const TYPE_COLORS = { text:'#60a5fa', image:'#a78bfa', file:'#10b981', sticker:'#f59e0b', flex:'#f97316', video:'#ec4899', audio:'#14b8a6' };
    const TYPE_LABELS = { text:'ข้อความ', image:'รูปภาพ', file:'ไฟล์', sticker:'สติ๊กเกอร์', flex:'Flex Message', video:'วิดีโอ', audio:'เสียง' };

    const labels   = sorted.map(([k]) => TYPE_LABELS[k]||k);
    const inData   = sorted.map(([,v]) => v.in);
    const outData  = sorted.map(([,v]) => v.out);
    const colors   = sorted.map(([k]) => TYPE_COLORS[k]||'#6b7280');

    chartArea.innerHTML = '<canvas id="msgTypeChart"></canvas>';
    chartArea.style.height = '200px';

    if (chartMsgType) chartMsgType.destroy();
    const ctx = document.getElementById('msgTypeChart').getContext('2d');
    chartMsgType = new Chart(ctx, {
      type:'bar',
      data:{
        labels,
        datasets:[
          { label:'Incoming', data:inData,  backgroundColor: colors.map(c=>c+'99'), borderColor:colors, borderWidth:1, borderRadius:4 },
          { label:'Outgoing', data:outData, backgroundColor: colors.map(c=>c+'44'), borderColor:colors, borderWidth:1, borderRadius:4, borderDash:[3,2] },
        ],
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ labels:{ boxWidth:10, padding:8 } } },
        scales:{ x:{ grid:{ display:false } }, y:{ grid:{ color:'#253047' } } },
      },
    });

    const grandTotal = sorted.reduce((s,[,v])=>s+v.in+v.out, 0);
    legendEl.innerHTML = sorted.slice(0,6).map(([k,v],i) => {
      const total = v.in + v.out;
      const p = pct(total, grandTotal);
      return `<span style="background:${colors[i]}22;color:${colors[i]};border:1px solid ${colors[i]}55;padding:2px 8px;border-radius:9999px;font-size:11px;">
        ${labels[i]}: ${fmt(total)} (${p}%)
      </span>`;
    }).join('');
  } catch(e) {
    chartArea.innerHTML = emptyEl('โหลดข้อมูลไม่ได้');
  }
}

// ═══════════════════════════════════════════════════════════════════
// ADMIN WORKLOAD
// ═══════════════════════════════════════════════════════════════════
async function loadAdminWorkload() {
  const el = document.getElementById('admin-workload-content');
  if (!el) return;

  try {
    const res  = await fetch(`${API}?action=admin_workload&days=7`);
    const json = await res.json();
    const data = json.data || [];

    if (!data.length) { el.innerHTML = emptyEl('ไม่มีข้อมูล'); return; }

    const maxTotal = Math.max(...data.map(d=>parseInt(d.total_sent||0)), 1);
    const WORKLOAD_COLORS = ['#60a5fa','#a78bfa','#10b981','#f59e0b','#f97316','#ec4899','#14b8a6','#6b7280'];

    el.innerHTML = `<div class="space-y-2.5">
      ${data.map((d,i) => {
        const total  = parseInt(d.total_sent||0);
        const today  = parseInt(d.today_sent||0);
        const w      = Math.round(total/maxTotal*100);
        const color  = WORKLOAD_COLORS[i%WORKLOAD_COLORS.length];
        const name   = d.admin_name || d.sent_by || `Admin #${i+1}`;
        return `<div>
          <div class="flex items-center justify-between text-xs mb-1 gap-2">
            <span class="text-gray-300 truncate">${name}</span>
            <div class="flex items-center gap-2 shrink-0">
              ${today > 0 ? `<span style="color:${color};font-size:10px;">+${today} วันนี้</span>` : ''}
              <span class="font-mono" style="color:${color};">${fmt(total)}</span>
            </div>
          </div>
          <div style="height:8px;background:#0f1623;border-radius:4px;overflow:hidden;">
            <div style="width:${w}%;height:100%;background:linear-gradient(90deg,${color}cc,${color}66);border-radius:4px;transition:width 0.8s ease;"></div>
          </div>
        </div>`;
      }).join('')}
    </div>
    <p class="text-xs text-gray-700 mt-3">รวมเฉพาะ admin ที่ส่งข้อความ (ไม่รวม system/bot)</p>`;
  } catch(e) {
    el.innerHTML = emptyEl('โหลดข้อมูลไม่ได้');
  }
}

// ═══════════════════════════════════════════════════════════════════
// UNREAD MESSAGES
// ═══════════════════════════════════════════════════════════════════
async function loadUnread() {
  const el    = document.getElementById('unread-table');
  const badge = document.getElementById('unread-badge');
  el.innerHTML = loadingEl();

  try {
    const res  = await fetch(`${API}?action=unread_wait`);
    const json = await res.json();
    const data = json.data || [];

    if (!data.length) {
      badge.textContent = '0 รายการ';
      badge.className   = 'badge badge-green';
      el.innerHTML = okEl('ไม่มีข้อความรอการอ่าน');
      return;
    }

    badge.textContent = `${data.length} รายการ`;
    badge.className   = 'badge badge-red';

    el.innerHTML = `<table class="data-table">
      <thead><tr>
        <th class="text-left">ลูกค้า</th>
        <th class="text-right">รอนาน</th>
        <th class="text-left pl-3 hidden sm:table-cell">ข้อความ</th>
      </tr></thead>
      <tbody>
        ${data.map(d => {
          const w  = parseInt(d.waiting_minutes||d.wait_min||d.wait_minutes||0);
          const wt = w<60?`${w} น.`:`${Math.floor(w/60)} ชม. ${w%60} น.`;
          const wc = colorClass(w>=60?'red':w>=30?'yellow':'blue');
          return `<tr>
            <td class="text-gray-300">${d.customer_name||d.name||d.contact_name||d.display_name||`#${d.user_id||'?'}`}</td>
            <td class="text-right font-mono ${wc.text}">${wt}</td>
            <td class="pl-3 hidden sm:table-cell text-gray-600" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${d.last_message||d.preview||d.message||'—'}</td>
          </tr>`;
        }).join('')}
      </tbody>
    </table>`;
  } catch(e) {
    badge.textContent = 'ข้อผิดพลาด';
    el.innerHTML = emptyEl('โหลด unread ไม่ได้');
  }
}

// ═══════════════════════════════════════════════════════════════════
// CUSTOMER JOURNEY FUNNEL
// ═══════════════════════════════════════════════════════════════════
const FUNNEL_COLORS = ['#3b82f6','#6366f1','#8b5cf6','#a855f7','#ec4899','#f43f5e'];

async function loadCustomerJourney() {
  const el = document.getElementById('customer-journey-content');
  el.innerHTML = loadingEl();

  try {
    const res = await fetch(`${API}?action=customer_journey&days=7`);
    if (!res.ok) { el.innerHTML = emptyEl(`API ยังไม่พร้อม (HTTP ${res.status})`); return; }
    const json = await res.json();
    if (json.error) { el.innerHTML = emptyEl(`API error: ${json.error}`); return; }

    const stages = json.stages || json.data?.consultation_stages || json.data || [];
    if (!stages.length) { el.innerHTML = emptyEl('ไม่มีข้อมูล Customer Journey'); return; }

    const maxCount = Math.max(...stages.map(s=>parseInt(s.count||s.users||0)), 1);

    const funnelRows = stages.map((s,i) => {
      const count   = parseInt(s.count||s.users||0);
      const width   = Math.round((count/maxCount)*100);
      const color   = FUNNEL_COLORS[i%FUNNEL_COLORS.length];
      const prev    = i>0 ? parseInt(stages[i-1].count||stages[i-1].users||0) : null;
      const dropPct = prev && prev>0 ? Math.round((1-count/prev)*100) : null;
      const name    = s.stage_name||s.stage||s.name||s.label||`Stage ${i+1}`;

      return `<div class="mb-3">
        <div class="flex items-center justify-between text-xs mb-1">
          <span class="flex items-center gap-1.5 text-gray-300">
            <span style="width:10px;height:10px;border-radius:2px;background:${color};display:inline-block;opacity:0.8;"></span>
            ${name}
            ${dropPct!==null?`<span style="color:#6b7280;font-size:10px;">↓${dropPct}%</span>`:''}
          </span>
          <span class="font-mono text-white font-semibold">${fmt(count)}</span>
        </div>
        <div style="height:26px;background:#0f1623;border-radius:6px;overflow:hidden;">
          <div class="funnel-bar" style="width:${width}%;height:100%;background:linear-gradient(90deg,${color}cc,${color}44);"></div>
        </div>
      </div>`;
    });

    const conv = json.data?.conversion;
    let convBlock = '';
    if (conv) {
      const cr = conv.inbox_users > 0 ? Math.round((conv.has_orders||0)/conv.inbox_users*100) : 0;
      convBlock = `<div class="flex justify-between items-center mt-4 pt-3" style="border-top:1px solid #253047;">
        <span class="text-xs text-gray-400">Inbox → Order Conversion</span>
        <span class="text-lg font-bold text-emerald-400">${cr}%</span>
      </div>`;
    } else if (stages.length >= 2) {
      const first = parseInt(stages[0].count||stages[0].users||0);
      const last  = parseInt(stages[stages.length-1].count||stages[stages.length-1].users||0);
      const cr    = first>0?Math.round(last/first*100):0;
      convBlock = `<div class="flex justify-between items-center mt-4 pt-3" style="border-top:1px solid #253047;">
        <span class="text-xs text-gray-400">อัตราการแปลง (ต้น → ปลาย)</span>
        <span class="text-lg font-bold text-emerald-400">${cr}%</span>
      </div>`;
    }

    el.innerHTML = `<div>${funnelRows.join('')}${convBlock}</div>`;
  } catch(e) {
    el.innerHTML = emptyEl('โหลด Customer Journey ไม่ได้');
  }
}

// ═══════════════════════════════════════════════════════════════════
// PRODUCT INTELLIGENCE
// ═══════════════════════════════════════════════════════════════════
async function loadProductIntelligence() {
  const bannerEl   = document.getElementById('low-stock-banner');
  const productsEl = document.getElementById('trending-products-content');
  const catArea    = document.getElementById('product-categories-area');
  if (!bannerEl) return;

  bannerEl.innerHTML   = loadingEl();
  if (productsEl) productsEl.innerHTML = loadingEl();

  try {
    const res  = await fetch(`${PRODUCT_API}?action=overview&days=7`);
    const json = await res.json();

    const products      = json.products       || [];
    const lowStockAlerts = json.low_stock_alerts || [];
    const categories    = json.categories     || [];

    // ── Low Stock Alert Banner
    if (lowStockAlerts.length > 0) {
      const critical = lowStockAlerts.filter(p => (p.live_qty === 0 || p.live_qty === null) && parseInt(p.mention_count||0) >= 5);
      const warn     = lowStockAlerts.filter(p => parseInt(p.live_qty||0) > 0 && parseInt(p.live_qty||0) <= 20 && parseInt(p.mention_count||0) >= 5);

      bannerEl.innerHTML = `
        <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:12px 16px;">
          <div class="flex items-center gap-2 mb-3">
            <span class="text-red-400 text-sm font-semibold">⚠️ Low Stock Alert — สินค้าที่ลูกค้าถามแต่ใกล้หมด</span>
            <span class="badge badge-red">${lowStockAlerts.length} รายการ</span>
          </div>
          <div class="overflow-x-auto max-h-52 overflow-y-auto">
            <table class="data-table">
              <thead><tr>
                <th class="text-left">รหัส</th>
                <th class="text-left">ชื่อสินค้า</th>
                <th class="text-right">stock</th>
                <th class="text-right">📣 ถาม</th>
                <th class="text-left hidden sm:table-cell">ราคา</th>
              </tr></thead>
              <tbody>
                ${lowStockAlerts.slice(0,15).map(p => {
                  const isCritical = !p.live_qty || parseInt(p.live_qty||0) === 0;
                  const rowStyle   = isCritical ? 'background:rgba(239,68,68,0.05);' : '';
                  return `<tr style="${rowStyle}">
                    <td class="font-mono text-gray-400" style="font-size:10px;">${p.product_code}</td>
                    <td class="text-gray-200" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${p.name}">
                      ${isCritical ? '🔴 ' : '🟡 '}${p.name}
                      ${p.generic_name ? `<span style="color:#4b5563;font-size:10px;display:block;">${p.generic_name}</span>` : ''}
                    </td>
                    <td class="text-right">${stockBadge(p.live_qty)}</td>
                    <td class="text-right">${mentionBadge(p.mention_count)}</td>
                    <td class="text-right hidden sm:table-cell text-gray-500" style="font-size:11px;">
                      ${p.online_price ? `฿${p.online_price}` : '—'}
                    </td>
                  </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>
        </div>`;
    } else {
      bannerEl.innerHTML = `<div style="background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.2);border-radius:10px;padding:10px 16px;" class="text-emerald-400 text-sm">
        ✅ ไม่มีสินค้าที่ถูกถามแล้วใกล้หมดสต๊อก
      </div>`;
    }

    // ── Trending Products Table
    const topProducts = products.slice(0, 30);
    if (productsEl) {
      productsEl.innerHTML = `
        <div class="overflow-x-auto max-h-96 overflow-y-auto pr-1">
          <table class="data-table">
            <thead style="position:sticky;top:0;background:#1a2236;z-index:1;">
              <tr>
                <th class="text-right" style="width:28px;">#</th>
                <th class="text-left">สินค้า</th>
                <th class="text-right">stock</th>
                <th class="text-right">📣</th>
                <th class="text-left hidden md:table-cell">ราคา</th>
                <th class="text-left hidden lg:table-cell">ลูกค้าที่ถาม</th>
              </tr>
            </thead>
            <tbody>
              ${topProducts.map((p, i) => {
                const mentioners = (p.mentioners||[]).slice(0,3).join(', ');
                const moreCount  = (p.mentioners||[]).length - 3;
                return `<tr>
                  <td class="text-right text-gray-600" style="font-size:11px;">${i+1}</td>
                  <td style="max-width:240px;">
                    <span style="color:#d1d5db;font-size:12px;" title="${p.name}">${p.name.length>50?p.name.slice(0,50)+'…':p.name}</span>
                    ${p.generic_name?`<span style="color:#4b5563;font-size:10px;display:block;">${p.generic_name.length>60?p.generic_name.slice(0,60)+'…':p.generic_name}</span>`:''}
                    <span style="font-size:10px;color:#374151;">${p.category||''}</span>
                  </td>
                  <td class="text-right">${stockBadge(p.live_qty)}</td>
                  <td class="text-right">${mentionBadge(p.mention_count)}</td>
                  <td class="hidden md:table-cell text-gray-500" style="font-size:11px;">${p.online_price?`฿${p.online_price}`:'—'}</td>
                  <td class="hidden lg:table-cell text-gray-600" style="font-size:10px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    ${mentioners}${moreCount>0?` +${moreCount}`:''}
                  </td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>`;
    }

    // ── Category Chart
    if (catArea && categories.length) {
      const topCats   = categories.slice(0, 12);
      const catLabels = topCats.map(c => (c.category||'').replace(/^[A-Z]{2,3}-\d{2}-/,'').slice(0,14));
      const catCounts = topCats.map(c => parseInt(c.mentions||0));
      const maxCat    = Math.max(...catCounts, 1);

      catArea.innerHTML = topCats.map((c,i) => {
        const w = Math.round(catCounts[i]/maxCat*100);
        const hue = Math.round(200 + i*12) % 360;
        return `<div class="mb-1.5">
          <div class="flex justify-between text-xs mb-0.5">
            <span class="text-gray-400 truncate" style="max-width:120px;" title="${c.category}">${catLabels[i]}</span>
            <span class="text-xs font-mono" style="color:hsl(${hue},70%,60%);flex-shrink:0;">${fmt(catCounts[i])}</span>
          </div>
          <div style="height:6px;background:#0f1623;border-radius:3px;overflow:hidden;">
            <div style="width:${w}%;height:100%;background:hsl(${hue},70%,50%);border-radius:3px;"></div>
          </div>
        </div>`;
      }).join('');
    }

  } catch(e) {
    if (bannerEl) bannerEl.innerHTML = emptyEl(`โหลด product intelligence ไม่ได้: ${e.message}`);
  }
}

// ═══════════════════════════════════════════════════════════════════
// TIMESTAMP
// ═══════════════════════════════════════════════════════════════════
function updateTimestamp() {
  const now  = new Date();
  const opts = { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', timeZone:'Asia/Bangkok' };
  document.getElementById('last-updated').textContent = 'อัปเดตล่าสุด: ' + now.toLocaleString('th-TH', opts);
}

// ═══════════════════════════════════════════════════════════════════
// REFRESH ALL
// ═══════════════════════════════════════════════════════════════════
let refreshTimer = null;

async function refreshAll() {
  const icon = document.getElementById('refresh-icon');
  const btn  = document.getElementById('refresh-btn');
  icon.classList.add('spinning');
  btn.disabled = true;

  await Promise.allSettled([
    loadSummaryCards(),
    loadDailyReport(),
    loadResponseTime(),
    loadAdminPerformance(),
    loadSLA(),
    loadSentiment(),
    loadPeakHours(),
    loadTrafficComparison(),
    loadMessageTypeDist(),
    loadAdminWorkload(),
    loadUnread(),
    loadCustomerJourney(),
    loadProductIntelligence(),
  ]);

  updateTimestamp();
  icon.classList.remove('spinning');
  btn.disabled = false;
}

// ═══════════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  refreshAll();
  refreshTimer = setInterval(refreshAll, 5 * 60 * 1000);
});
