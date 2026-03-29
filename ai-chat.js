// ═══════════════════════════════════════════════════════════════
// AI CHAT FAB — floating button + chat panel
// ═══════════════════════════════════════════════════════════════

const AI_API = '/api/ai-chat.php';
let chatHistory = [];
let chatOpen = false;

function initAIChat() {
  // ── FAB Button ──────────────────────────────────────────────
  const fab = document.createElement('button');
  fab.id = 'ai-fab';
  fab.innerHTML = `<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 1 10 10c0 5.52-4.48 10-10 10S2 17.52 2 12 6.48 2 12 2z"/><path d="M8 10h.01M12 10h.01M16 10h.01"/></svg>`;
  fab.title = 'ถาม REYA AI';
  fab.style.cssText = `
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    width: 52px; height: 52px; border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    border: none; cursor: pointer; color: #fff;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 20px rgba(99,102,241,0.5);
    transition: transform .2s, box-shadow .2s;
  `;
  fab.onmouseenter = () => fab.style.transform = 'scale(1.1)';
  fab.onmouseleave = () => fab.style.transform = 'scale(1)';
  fab.onclick = toggleChat;

  // ── Chat Panel ───────────────────────────────────────────────
  const panel = document.createElement('div');
  panel.id = 'ai-panel';
  panel.style.cssText = `
    position: fixed; bottom: 84px; right: 24px; z-index: 9998;
    width: 360px; max-height: 520px;
    background: #0f172a; border: 1px solid #1e293b;
    border-radius: 16px; display: flex; flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    transform: translateY(20px) scale(0.95); opacity: 0;
    pointer-events: none;
    transition: all .25s cubic-bezier(.34,1.56,.64,1);
    font-family: inherit;
  `;
  panel.innerHTML = `
    <div style="padding:14px 16px 10px;border-bottom:1px solid #1e293b;display:flex;align-items:center;justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:8px;">
        <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:14px;">🤖</div>
        <div>
          <div style="font-size:13px;font-weight:600;color:#f1f5f9;">REYA AI</div>
          <div style="font-size:10px;color:#22c55e;display:flex;align-items:center;gap:3px;"><span style="width:5px;height:5px;background:#22c55e;border-radius:50%;display:inline-block;"></span> เชื่อมต่อฐานข้อมูล</div>
        </div>
      </div>
      <button onclick="clearChat()" style="background:none;border:none;color:#475569;cursor:pointer;font-size:11px;padding:4px 8px;border-radius:4px;transition:color .15s;" onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#475569'">ล้างแชท</button>
    </div>
    <div id="ai-messages" style="flex:1;overflow-y:auto;padding:12px 14px;display:flex;flex-direction:column;gap:10px;min-height:200px;max-height:330px;">
      <div class="ai-msg bot" style="display:flex;gap:8px;align-items:flex-start;">
        <div style="width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:2px;">🤖</div>
        <div style="background:#1e293b;border-radius:0 10px 10px 10px;padding:9px 12px;font-size:12px;color:#e2e8f0;line-height:1.6;max-width:calc(100% - 36px);">
          สวัสดีครับ! ผม REYA AI เชื่อมต่อกับข้อมูลธุรกิจ real-time<br>
          <span style="color:#94a3b8;font-size:11px;">ถามได้เลย เช่น "เมื่อวานขายได้เท่าไหร่" หรือ "admin คนไหนตอบช้าสุด"</span>
        </div>
      </div>
    </div>
    <div style="padding:10px 12px;border-top:1px solid #1e293b;">
      <div style="display:flex;gap-x:0;background:#1e293b;border-radius:10px;overflow:hidden;border:1px solid #334155;transition:border-color .2s;" id="ai-input-wrap">
        <textarea id="ai-input" placeholder="ถามเกี่ยวกับข้อมูลธุรกิจ..." rows="1"
          style="flex:1;background:none;border:none;outline:none;padding:10px 12px;font-size:12px;color:#e2e8f0;resize:none;font-family:inherit;line-height:1.5;max-height:80px;"
          onkeydown="handleChatKey(event)"></textarea>
        <button id="ai-send-btn" onclick="sendAIMessage()" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;cursor:pointer;padding:0 14px;color:#fff;border-radius:0 8px 8px 0;transition:opacity .2s;">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
        </button>
      </div>
      <div style="font-size:10px;color:#334155;margin-top:5px;text-align:center;">ตอบภาษาไทย · ข้อมูล real-time จากฐานข้อมูล</div>
    </div>
  `;

  document.body.appendChild(fab);
  document.body.appendChild(panel);

  // Auto-resize textarea
  document.getElementById('ai-input').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 80) + 'px';
  });
}

function toggleChat() {
  chatOpen = !chatOpen;
  const panel = document.getElementById('ai-panel');
  if (chatOpen) {
    panel.style.transform = 'translateY(0) scale(1)';
    panel.style.opacity = '1';
    panel.style.pointerEvents = 'auto';
    setTimeout(() => document.getElementById('ai-input')?.focus(), 200);
  } else {
    panel.style.transform = 'translateY(20px) scale(0.95)';
    panel.style.opacity = '0';
    panel.style.pointerEvents = 'none';
  }
}

function handleChatKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendAIMessage();
  }
}

function appendMsg(role, content, streaming = false) {
  const el = document.getElementById('ai-messages');
  const div = document.createElement('div');
  div.style.cssText = 'display:flex;gap:8px;align-items:flex-start;';

  const isBot = role === 'bot';
  const avatar = isBot
    ? `<div style="width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:2px;">🤖</div>`
    : `<div style="width:26px;height:26px;border-radius:50%;background:#334155;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:2px;">👤</div>`;
  const bubble = document.createElement('div');
  bubble.style.cssText = isBot
    ? 'background:#1e293b;border-radius:0 10px 10px 10px;padding:9px 12px;font-size:12px;color:#e2e8f0;line-height:1.6;max-width:calc(100% - 36px);'
    : 'background:#3730a3;border-radius:10px 0 10px 10px;padding:9px 12px;font-size:12px;color:#e2e8f0;line-height:1.6;max-width:calc(100% - 36px);';

  div.innerHTML = isBot ? avatar : '';
  if (isBot) div.firstChild && null;
  if (!isBot) div.innerHTML = '';

  if (isBot) {
    const ava = document.createElement('div');
    ava.style.cssText = 'width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:2px;';
    ava.textContent = '🤖';
    div.appendChild(ava);
  }

  bubble.id = streaming ? 'streaming-bubble' : '';
  bubble.textContent = content;
  div.appendChild(bubble);

  if (!isBot) {
    const ava = document.createElement('div');
    ava.style.cssText = 'width:26px;height:26px;border-radius:50%;background:#334155;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:2px;';
    ava.textContent = '👤';
    div.appendChild(ava);
  }

  el.appendChild(div);
  el.scrollTop = el.scrollHeight;
  return bubble;
}

function showTyping() {
  const el = document.getElementById('ai-messages');
  const div = document.createElement('div');
  div.id = 'typing-indicator';
  div.style.cssText = 'display:flex;gap:8px;align-items:flex-start;';
  div.innerHTML = `
    <div style="width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:2px;">🤖</div>
    <div style="background:#1e293b;border-radius:0 10px 10px 10px;padding:9px 12px;display:flex;gap:4px;align-items:center;">
      <span style="width:6px;height:6px;background:#6366f1;border-radius:50%;animation:bounce .8s infinite"></span>
      <span style="width:6px;height:6px;background:#6366f1;border-radius:50%;animation:bounce .8s infinite .2s"></span>
      <span style="width:6px;height:6px;background:#6366f1;border-radius:50%;animation:bounce .8s infinite .4s"></span>
    </div>`;
  el.appendChild(div);
  el.scrollTop = el.scrollHeight;
}

function removeTyping() {
  document.getElementById('typing-indicator')?.remove();
}

async function sendAIMessage() {
  const input = document.getElementById('ai-input');
  const msg = input.value.trim();
  if (!msg) return;

  const btn = document.getElementById('ai-send-btn');
  input.value = '';
  input.style.height = 'auto';
  btn.disabled = true;
  btn.style.opacity = '0.5';

  appendMsg('user', msg);
  showTyping();

  try {
    const resp = await fetch(AI_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: msg, history: chatHistory }),
    });

    removeTyping();
    const bubble = appendMsg('bot', '', true);
    let fullText = '';

    const reader = resp.body.getReader();
    const decoder = new TextDecoder();
    let buf = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buf += decoder.decode(value, { stream: true });
      const lines = buf.split('\n');
      buf = lines.pop();
      for (const line of lines) {
        if (line.startsWith('data: ')) {
          const raw = line.slice(6);
          if (raw === '[DONE]') continue;
          try {
            const json = JSON.parse(raw);
            if (json.token) {
              fullText += json.token;
              bubble.textContent = fullText;
              document.getElementById('ai-messages').scrollTop = document.getElementById('ai-messages').scrollHeight;
            }
            if (json.error) {
              bubble.style.color = '#f87171';
              bubble.textContent = 'เกิดข้อผิดพลาด: ' + json.error;
            }
          } catch {}
        }
      }
    }

    bubble.id = '';
    chatHistory.push({ role: 'user', content: msg });
    chatHistory.push({ role: 'assistant', content: fullText });
    if (chatHistory.length > 20) chatHistory = chatHistory.slice(-20);

  } catch (e) {
    removeTyping();
    appendMsg('bot', 'ขอโทษครับ เกิดข้อผิดพลาด: ' + e.message);
  } finally {
    btn.disabled = false;
    btn.style.opacity = '1';
    input.focus();
  }
}

function clearChat() {
  chatHistory = [];
  const el = document.getElementById('ai-messages');
  el.innerHTML = `<div style="display:flex;gap:8px;align-items:flex-start;">
    <div style="width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:2px;">🤖</div>
    <div style="background:#1e293b;border-radius:0 10px 10px 10px;padding:9px 12px;font-size:12px;color:#e2e8f0;line-height:1.6;max-width:calc(100% - 36px);">
      ล้างประวัติแล้วครับ ถามใหม่ได้เลย 😊
    </div></div>`;
}

// ── Bounce animation for typing indicator ────────────────
const _aiStyle = document.createElement('style');
_aiStyle.textContent = `@keyframes bounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-5px)} }`;
document.head.appendChild(_aiStyle);

// Init on load
document.addEventListener('DOMContentLoaded', initAIChat);
