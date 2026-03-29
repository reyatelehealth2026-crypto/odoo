/**
 * Inbox Intelligence Dashboard — v9 (Dark Theme + API Fix)
 * JS Controller
 */
'use strict';

const API         = '/api/inbox-intelligence.php';
const PRODUCT_API = '/api/inbox-product-check.php';

// ─── Chart instances ──────────────────────────────────────────────
let chartTrend = null;
let chartHourly = null;
let chartVolume = null;
let chartSentiment = null;

// ─── Chart.js global defaults ─────────────────────────────────────
if (typeof Chart !== 'undefined') {
  Chart.defaults.color       = '#94a3b8';
  Chart.defaults.font.family = "'Noto Sans Thai', 'Inter', sans-serif";
  Chart.defaults.font.size   = 11;
}

// ═══════════════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════════════

function setEl(id, html) {
  const el = document.getElementById(id);
  if (el) {
    el.innerHTML = html;
    console.log(`[setEl] Set ${id}: ${html.substring(0, 100)}...`);
  } else {
    console.error(`[setEl] Element not found: ${id}`);
  }
  return el;
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

function secToText(sec) {
  sec = Math.round(parseInt(sec, 10) || 0);
  if (sec <= 0) return '—';
  if (sec < 60)  return `${sec} วิ`;
  const m = Math.floor(sec / 60), s = sec % 60;
  if (m < 60) return s > 0 ? `${m} น. ${s} วิ` : `${m} น.`;
  const h = Math.floor(m / 60), rm = m % 60;
  return rm > 0 ? `${h} ชม. ${rm} น.` : `${h} ชม.`;
}

function pct(a, b) {
  const n = parseInt(a, 10) || 0, d = parseInt(b, 10) || 0;
  return d === 0 ? 0 : Math.round((n / d) * 100);
}

function speedColor(s) {
  s = parseInt(s, 10) || 0;
  if (s <= 300) return 'green';
  if (s <= 600) return 'yellow';
  return 'red';
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

// ═══════════════════════════════════════════════════════════════════
// LOAD FUNCTIONS
// ═══════════════════════════════════════════════════════════════════

async function loadDailyReport() {
  console.log('[loadDailyReport] Starting...');
  try {
    const res  = await fetch(`${API}?action=daily_report`);
    console.log('[loadDailyReport] Fetch complete, status:', res.status);
    const json = await res.json();
    console.log('[loadDailyReport] JSON parsed:', json);
    const d    = json.data || {};

    // Update date
    setEl('exec-date', d.date || '—');
    setEl('today-snapshot', `ออเดอร์วันนี้: ${fmt(d.orders_today?.total || 0)} | เมื่อวาน: ${fmt(d.orders_yesterday?.total || 0)}`);

    // Message strip
    const msgs = d.messages_today || {};
    const msgHtml = `<span class="badge badge-blue">IN ${fmt(msgs.incoming||0)}</span>
      <span class="badge badge-green">OUT ${fmt(msgs.outgoing||0)}</span>
      ${parseInt(msgs.unread||0)>0 ? `<span class="badge badge-red">UNREAD ${fmt(msgs.unread)}</span>` : ''}`;
    setEl('exec-msg-strip', msgHtml);

    // KPI cards
    const ot   = d.orders_today      || {};
    const oy   = d.orders_yesterday  || {};
    const cm   = d.current_month     || {};
    const pm   = d.prev_month        || {};
    const bdo  = d.bdo_today         || {};
    const slp  = d.slips_today       || {};
    const ovd  = d.overdue           || {};

    const momAmt    = parseFloat(cm.amount||0);
    const prevAmt   = parseFloat(pm.amount||0);
    const momGrowth = prevAmt > 0 ? ((momAmt/prevAmt-1)*100).toFixed(1) : null;
    const trend = momGrowth !== null ? (parseFloat(momGrowth) >= 0 ? '📈' : '📉') : '';

    const kpiCards = [
      { label:'Orders Today', value:fmt(ot.total||0), sub:`vs ${fmt(oy.total||0)} เมื่อวาน`, emoji:'🛒' },
      { label:'MTD Revenue',  value:fmtBaht(cm.amount), sub:`${fmt(cm.total||0)} orders`, emoji:'💰' },
      { label:'MoM Growth',   value: momGrowth !== null ? `${momGrowth}%` : '—', sub:`vs ${fmtBaht(pm.amount)}`, emoji: trend || '📊' },
      { label:'BDO Today',    value:fmt(bdo.total||0),  sub:`${fmtBaht(bdo.amount)}`, emoji:'📦' },
      { label:'Slips Today',  value:fmt(slp.total||0),  sub:`${fmtBaht(slp.amount)}`, emoji:'🧾' },
      { label:'Active Debt',  value:fmt(ovd.customers||0), sub:`${fmtBaht(ovd.total_amount||0)}`, emoji:'⚠️' },
    ];

    const kpiHtml = kpiCards.map(c => `<div class="kpi-card">
      <div class="kpi-emoji">${c.emoji}</div>
      <div class="kpi-label">${c.label}</div>
      <div class="kpi-value">${c.value}</div>
      <div class="kpi-sub">${c.sub}</div>
    </div>`).join('');
    
    console.log('[loadDailyReport] Setting KPI HTML');
    setEl('exec-kpi', kpiHtml);

    // Trend chart
    const trendData = d.trend_7d || [];
    console.log('[loadDailyReport] Trend data length:', trendData.length);
    if (trendData.length && typeof Chart !== 'undefined') {
      const container = document.getElementById('exec-trend-chart');
      if (container) {
        container.innerHTML = '';
        const canvas = document.createElement('canvas');
        container.appendChild(canvas);
        
        if (chartTrend) chartTrend.destroy();
        
        const gradient = canvas.getContext('2d').createLinearGradient(0, 0, 0, 240);
        gradient.addColorStop(0, 'rgba(59, 130, 246, 0.3)');
        gradient.addColorStop(1, 'rgba(139, 92, 246, 0.0)');

        chartTrend = new Chart(canvas, {
          type: 'line',
          data: {
            labels: trendData.map(t => t.day),
            datasets: [{
              label: 'ออเดอร์',
              data: trendData.map(t => t.orders),
              borderColor: '#3B82F6',
              backgroundColor: gradient,
              fill: true,
              tension: 0.4,
              pointBackgroundColor: '#3B82F6',
              pointBorderColor: '#0B0F19',
              pointBorderWidth: 2,
              pointRadius: 4,
              pointHoverRadius: 6,
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false }
            },
            scales: {
              x: {
                grid: { color: 'rgba(30, 36, 53, 0.5)' },
                ticks: { color: '#64748b' }
              },
              y: {
                grid: { color: 'rgba(30, 36, 53, 0.5)' },
                ticks: { color: '#64748b' }
              }
            }
          }
        });
        console.log('[loadDailyReport] Chart created');
      }
    }

    // Hourly chart
    const hourlyData = d.hourly_traffic || [];
    console.log('[loadDailyReport] Hourly data length:', hourlyData.length);
    if (hourlyData.length && typeof Chart !== 'undefined') {
      const container = document.getElementById('exec-hourly-chart');
      if (container) {
        container.innerHTML = '';
        const canvas = document.createElement('canvas');
        container.appendChild(canvas);
        
        if (chartHourly) chartHourly.destroy();

        chartHourly = new Chart(canvas, {
          type: 'bar',
          data: {
            labels: hourlyData.map(h => `${h.hour}:00`),
            datasets: [{
              label: 'Incoming',
              data: hourlyData.map(h => parseInt(h.incoming || 0)),
              backgroundColor: 'rgba(59, 130, 246, 0.8)',
              borderRadius: 4,
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false }
            },
            scales: {
              x: {
                grid: { display: false },
                ticks: { color: '#64748b' }
              },
              y: {
                grid: { color: 'rgba(30, 36, 53, 0.5)' },
                ticks: { color: '#64748b' }
              }
            }
          }
        });
        console.log('[loadDailyReport] Hourly chart created');
      }
    }

    console.log('[loadDailyReport] Complete');
  } catch (e) {
    console.error('[loadDailyReport] Error:', e);
    setEl('exec-kpi', `<div class="col-span-full p-6 text-red-500 text-center text-xs">โหลดข้อมูลล้มเหลว: ${e.message}</div>`);
  }
}

async function loadFlagged() {
  console.log('[loadFlagged] Starting...');
  try {
    const res = await fetch(`${API}?action=flagged_messages&days=1`);
    const json = await res.json();
    const data = (json.data || {}).problematic_incoming || [];
    
    setEl('flagged-count', data.length > 0 ? `(${data.length})` : '');
    
    if (!data.length) {
      setEl('flagged-list', `<div class="alert alert-green">✅ ไม่มีข้อความที่เป็นปัญหา</div>`);
      return;
    }

    const html = data.slice(0, 10).map(m => `
      <div class="stat-row">
        <div class="stat-row-icon">⚠️</div>
        <div style="flex:1;min-width:0;">
          <div class="fs12 c-slate3 truncate">${m.customer_name || m.display_name || 'Unknown'}</div>
          <div class="fs10 c-slate5 mt-1">${m.created_at || ''}</div>
        </div>
      </div>
    `).join('');
    
    setEl('flagged-list', html);
  } catch(e) {
    console.error('[loadFlagged] Error:', e);
    setEl('flagged-list', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

async function loadUnprofessional() {
  console.log('[loadUnprofessional] Starting...');
  try {
    const res = await fetch(`${API}?action=flagged_messages&days=1`);
    const json = await res.json();
    const data = (json.data || {}).inappropriate_outgoing || [];
    
    setEl('unprof-count', data.length > 0 ? `(${data.length})` : '');
    
    if (!data.length) {
      setEl('unprofessional-list', `<div class="alert alert-green">✅ ไม่มีข้อความที่ไม่เหมาะสม</div>`);
      return;
    }

    const html = data.slice(0, 10).map(m => `
      <div class="stat-row">
        <div class="stat-row-icon">🚫</div>
        <div style="flex:1;min-width:0;">
          <div class="fs12 c-slate3 truncate">${m.admin_name || 'Admin'} → ${m.customer_name || 'Customer'}</div>
          <div class="fs10 c-slate5 mt-1">${m.created_at || ''}</div>
        </div>
      </div>
    `).join('');
    
    setEl('unprofessional-list', html);
  } catch(e) {
    console.error('[loadUnprofessional] Error:', e);
    setEl('unprofessional-list', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

async function loadAdminWorkload() {
  console.log('[loadAdminWorkload] Starting...');
  try {
    const res = await fetch(`${API}?action=admin_workload&days=7`);
    const json = await res.json();
    const data = json.data || [];
    
    if (!data.length) {
      setEl('admin-workload-bars', `<div class="c-slate5 fs12">ไม่มีข้อมูล</div>`);
      return;
    }

    const maxMsgs = Math.max(...data.map(d => parseInt(d.total_sent || 0)), 1);

    const html = data.map(d => {
      const pct = (parseInt(d.total_sent || 0) / maxMsgs * 100).toFixed(0);
      const gradient = `linear-gradient(90deg, #3B82F6 0%, #8B5CF6 ${pct}%)`;
      
      return `
        <div style="margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span class="fs12 c-slate3">${d.admin_name || `Admin #${d.admin_id}`}</span>
            <span class="fs12 c-blue mono">${fmt(d.total_sent)}</span>
          </div>
          <div class="wl-track">
            <div class="wl-fill" style="width:${pct}%;background:${gradient};"></div>
          </div>
        </div>
      `;
    }).join('');
    
    setEl('admin-workload-bars', html);
  } catch(e) {
    console.error('[loadAdminWorkload] Error:', e);
    setEl('admin-workload-bars', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

async function loadTrendingProducts() {
  console.log('[loadTrendingProducts] Starting...');
  try {
    const res = await fetch(`${PRODUCT_API}?action=overview&days=7`);
    const json = await res.json();
    const products = json.products || [];
    const lowStock = json.low_stock_alerts || [];

    // Low stock banner
    if (lowStock.length) {
      const bannerHtml = `<div class="alert alert-red">
        <div class="alert-title">
          <span>⚠️</span>
          <span>Stock Warning: ${lowStock.length} รายการที่ลูกค้าถามถึงกำลังจะหมด</span>
        </div>
      </div>`;
      setEl('low-stock-banner', bannerHtml);
    } else {
      setEl('low-stock-banner', '');
    }

    if (!products.length) {
      setEl('trending-table', `<div class="c-slate5 fs12 p-4">ไม่มีข้อมูลสินค้า</div>`);
      return;
    }

    const html = `<table class="dt">
      <thead>
        <tr>
          <th>สินค้า</th>
          <th style="text-align:right;">ถาม</th>
          <th style="text-align:right;">Stock</th>
        </tr>
      </thead>
      <tbody>
        ${products.slice(0, 10).map(p => `
          <tr>
            <td class="c-slate3">${p.name}</td>
            <td class="text-right">${mentionBadge(p.mention_count)}</td>
            <td class="text-right">${stockBadge(p.live_qty)}</td>
          </tr>
        `).join('')}
      </tbody>
    </table>`;
    
    setEl('trending-table', html);
  } catch(e) {
    console.error('[loadTrendingProducts] Error:', e);
    setEl('trending-table', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

async function loadTraffic() {
  console.log('[loadTraffic] Starting...');
  try {
    const res = await fetch(`${API}?action=daily_report`);
    const json = await res.json();
    const d = json.data || {};
    const msgs = d.messages_today || {};
    const msgsYtd = d.messages_yesterday || {};

    const diff = (parseInt(msgs.incoming||0) - parseInt(msgsYtd.incoming||0));
    const diffClass = diff >= 0 ? 'c-green' : 'c-red';
    const diffSign = diff >= 0 ? '+' : '';

    const statsHtml = `
      <div class="stat-row">
        <div class="stat-row-icon">📥</div>
        <div style="flex:1;">
          <div class="fs11 c-slate5">Incoming Today</div>
          <div class="fs14 c-white fw6">${fmt(msgs.incoming || 0)}</div>
        </div>
        <div class="fs11 ${diffClass}">${diffSign}${diff}</div>
      </div>
      <div class="stat-row">
        <div class="stat-row-icon">📤</div>
        <div style="flex:1;">
          <div class="fs11 c-slate5">Outgoing Today</div>
          <div class="fs14 c-white fw6">${fmt(msgs.outgoing || 0)}</div>
        </div>
      </div>
      <div class="stat-row">
        <div class="stat-row-icon">👥</div>
        <div style="flex:1;">
          <div class="fs11 c-slate5">Active Senders</div>
          <div class="fs14 c-white fw6">${fmt(msgs.senders || 0)}</div>
        </div>
      </div>
      ${parseInt(msgs.unread||0) > 0 ? `
        <div class="stat-row">
          <div class="stat-row-icon">🔔</div>
          <div style="flex:1;">
            <div class="fs11 c-slate5">Unread</div>
            <div class="fs14 c-red fw6">${fmt(msgs.unread)}</div>
          </div>
        </div>
      ` : ''}
    `;
    
    setEl('traffic-stats', statsHtml);

    // Volume chart
    const trend = d.trend_7d || [];
    if (trend.length && typeof Chart !== 'undefined') {
      const container = document.getElementById('traffic-volume-chart');
      if (container) {
        container.innerHTML = '';
        const canvas = document.createElement('canvas');
        container.appendChild(canvas);
        
        if (chartVolume) chartVolume.destroy();

        const gradient = canvas.getContext('2d').createLinearGradient(0, 0, 0, 200);
        gradient.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
        gradient.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

        chartVolume = new Chart(canvas, {
          type: 'line',
          data: {
            labels: trend.map(t => t.day),
            datasets: [{
              label: 'Messages',
              data: trend.map(t => t.orders),
              borderColor: '#10B981',
              backgroundColor: gradient,
              fill: true,
              tension: 0.4,
              pointRadius: 0,
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false }
            },
            scales: {
              x: {
                grid: { display: false },
                ticks: { color: '#64748b' }
              },
              y: {
                grid: { color: 'rgba(30, 36, 53, 0.5)' },
                ticks: { color: '#64748b' }
              }
            }
          }
        });
      }
    }

  } catch(e) {
    console.error('[loadTraffic] Error:', e);
    setEl('traffic-stats', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

async function loadSLA() {
  const threshold = parseInt(document.getElementById('sla-threshold-val')?.value || 30);
  console.log('[loadSLA] Starting with threshold:', threshold);
  try {
    const res = await fetch(`${API}?action=sla_breach&sla_threshold=${threshold}`);
    const json = await res.json();
    
    const waiting = parseInt(json.total_waiting || 0);
    const breaches = parseInt(json.breach_count || 0);
    const breachList = json.breaches || [];

    const slaHtml = `
      <div class="sla-pair">
        <div class="sla-box">
          <div class="sla-num ${waiting > 0 ? 'c-amber' : 'c-green'}">${fmt(waiting)}</div>
          <div class="sla-label">รอตอบ</div>
        </div>
        <div class="sla-box">
          <div class="sla-num ${breaches > 0 ? 'c-red' : 'c-green'}">${fmt(breaches)}</div>
          <div class="sla-label">เกิน SLA</div>
        </div>
      </div>
      ${breachList.length > 0 ? `
        <div style="margin-top:12px;">
          <div class="fs11 c-slate5 mb-2">รายการเกิน SLA:</div>
          ${breachList.slice(0, 5).map(b => `
            <div class="stat-row">
              <div style="flex:1;min-width:0;" class="fs11 c-slate3 truncate">${b.customer_name || b.display_name}</div>
              <div class="fs11 c-red mono">${b.wait_minutes} น.</div>
            </div>
          `).join('')}
        </div>
      ` : `<div class="alert alert-green mt-3">✅ SLA ผ่านเกณฑ์</div>`}
    `;
    
    setEl('sla-content', slaHtml);
  } catch(e) {
    console.error('[loadSLA] Error:', e);
    setEl('sla-content', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

function onSLAThresholdChange() {
  loadSLA();
}

async function loadAdminPerformance() {
  console.log('[loadAdminPerformance] Starting...');
  try {
    const res = await fetch(`${API}?action=response_by_admin&days=7`);
    const json = await res.json();
    const data = (json.data || []).slice().sort((a, b) => parseInt(a.avg_sec) - parseInt(b.avg_sec));
    
    if (!data.length) {
      setEl('admin-perf-table', `<div class="c-slate5 fs12">ไม่มีข้อมูล</div>`);
      return;
    }

    const html = `<table class="dt">
      <thead>
        <tr>
          <th>Admin</th>
          <th style="text-align:right;">Conv</th>
          <th style="text-align:right;">Avg</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        ${data.map(d => {
          const sec = parseInt(d.avg_sec || 0);
          const color = speedColor(sec);
          const colorClass = color === 'green' ? 'c-green' : color === 'yellow' ? 'c-amber' : 'c-red';
          const badgeClass = color === 'green' ? 'badge-green' : color === 'yellow' ? 'badge-yellow' : 'badge-red';
          const statusText = color === 'green' ? 'เร็ว' : color === 'yellow' ? 'ปานกลาง' : 'ช้า';
          
          return `
            <tr>
              <td class="c-slate3">${d.admin_name || `Admin #${d.admin_id}`}</td>
              <td class="text-right mono c-slate4">${fmt(d.conversations)}</td>
              <td class="text-right mono ${colorClass}">${secToText(sec)}</td>
              <td class="text-right"><span class="badge ${badgeClass}">${statusText}</span></td>
            </tr>
          `;
        }).join('')}
      </tbody>
    </table>`;
    
    setEl('admin-perf-table', html);
  } catch(e) {
    console.error('[loadAdminPerformance] Error:', e);
    setEl('admin-perf-table', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

async function loadJourney() {
  console.log('[loadJourney] Starting...');
  try {
    const res = await fetch(`${API}?action=customer_journey&days=7`);
    const json = await res.json();
    const stages = json.consultation_stages || [];
    
    if (!stages.length) {
      setEl('journey-content', `<div class="c-slate5 fs12">ไม่มีข้อมูล</div>`);
      return;
    }

    const maxUsers = Math.max(...stages.map(s => parseInt(s.users || 0)), 1);

    const html = `
      <div class="conv-row">
        ${stages.map((s, i) => {
          const pct = Math.round((parseInt(s.users || 0) / maxUsers) * 100);
          const gradient = `linear-gradient(135deg, rgba(59,130,246,${0.3 + i*0.1}) 0%, rgba(139,92,246,${0.3 + i*0.1}) 100%)`;
          
          return `
            <div class="conv-cell">
              <div class="conv-num" style="background:${gradient};-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">${fmt(s.users)}</div>
              <div class="conv-label">${s.stage}</div>
            </div>
            ${i < stages.length - 1 ? `<div class="conv-arrow">→</div>` : ''}
          `;
        }).join('')}
      </div>
    `;
    
    setEl('journey-content', html);
  } catch(e) {
    console.error('[loadJourney] Error:', e);
    setEl('journey-content', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

async function loadSentiment() {
  console.log('[loadSentiment] Starting...');
  try {
    const res = await fetch(`${API}?action=sentiment_summary&days=7`);
    const json = await res.json();
    const data = json.data || [];
    
    if (!data.length) {
      setEl('sentiment-tags', `<div class="c-slate5 fs12">ไม่มีข้อมูล</div>`);
      return;
    }

    // Chart
    if (typeof Chart !== 'undefined') {
      const container = document.getElementById('sentiment-canvas-wrap');
      if (container) {
        container.innerHTML = '';
        const canvas = document.createElement('canvas');
        container.appendChild(canvas);
        
        if (chartSentiment) chartSentiment.destroy();

        chartSentiment = new Chart(canvas, {
          type: 'doughnut',
          data: {
            labels: data.map(d => d.tag_name),
            datasets: [{
              data: data.map(d => d.count),
              backgroundColor: [
                '#3B82F6',
                '#8B5CF6',
                '#10B981',
                '#F59E0B',
                '#EF4444',
                '#6B7280'
              ],
              borderWidth: 0,
              hoverOffset: 8,
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
              legend: { display: false }
            }
          }
        });
      }
    }

    // Tags
    const tagsHtml = data.map(d => `
      <span class="badge badge-blue">${d.tag_name} ${fmt(d.count)}</span>
    `).join('');
    
    setEl('sentiment-tags', tagsHtml);
  } catch(e) {
    console.error('[loadSentiment] Error:', e);
    setEl('sentiment-tags', `<div class="text-red-400 text-xs">โหลดล้มเหลว: ${e.message}</div>`);
  }
}

function updateTimestamp() {
  const now = new Date();
  const opts = { 
    day: 'numeric', 
    month: 'short', 
    year: 'numeric', 
    hour: '2-digit', 
    minute: '2-digit',
    second: '2-digit'
  };
  setEl('last-updated', 'Last Sync: ' + now.toLocaleString('th-TH', opts));
}

async function refreshAll() {
  console.log('[refreshAll] Starting...');
  const icon = document.getElementById('refresh-icon');
  const btn  = document.getElementById('refresh-btn');
  if (icon) icon.classList.add('spinning');
  if (btn) btn.disabled = true;

  try {
    await Promise.all([
      loadDailyReport(),
      loadFlagged(),
      loadUnprofessional(),
      loadAdminWorkload(),
      loadTrendingProducts(),
      loadTraffic(),
      loadSLA(),
      loadAdminPerformance(),
      loadJourney(),
      loadSentiment(),
    ]);
    
    updateTimestamp();
    console.log('[refreshAll] Complete');
  } catch(e) {
    console.error('[refreshAll] Error:', e);
  }

  if (icon) icon.classList.remove('spinning');
  if (btn) btn.disabled = false;
}

// ═══════════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
  console.log('[DOMContentLoaded] Initializing...');
  refreshAll();
  
  // Auto-refresh every 5 minutes
  setInterval(refreshAll, 5 * 60 * 1000);
});
