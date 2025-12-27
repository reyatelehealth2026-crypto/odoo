<?php
/**
 * Video Call V2 - ระบบ Video Call แบบง่าย
 * ใช้ WebRTC + Database Polling สำหรับ Signaling
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Get account ID
$accountId = $_GET['account_id'] ?? 1;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📹 Video Call - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes ring {
            0%, 100% { transform: rotate(-15deg); }
            50% { transform: rotate(15deg); }
        }
        .ringing { animation: ring 0.5s ease-in-out infinite; }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-900 to-gray-800 min-h-screen text-white">
    <div class="container mx-auto p-4 max-w-6xl">
        
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold flex items-center gap-3">
                📹 Video Call Center
                <span id="statusBadge" class="text-sm bg-green-500 px-3 py-1 rounded-full">🟢 Online</span>
            </h1>
            <a href="/admin/" class="text-gray-400 hover:text-white">← กลับ</a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Main Video Area -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Video Container -->
                <div class="bg-black rounded-2xl overflow-hidden aspect-video relative">
                    <!-- Remote Video -->
                    <video id="remoteVideo" class="w-full h-full object-cover" autoplay playsinline></video>
                    
                    <!-- Local Video PIP -->
                    <div class="absolute bottom-4 right-4 w-40 aspect-video bg-gray-800 rounded-lg overflow-hidden border-2 border-white/20">
                        <video id="localVideo" class="w-full h-full object-cover" autoplay playsinline muted></video>
                    </div>
                    
                    <!-- Waiting Overlay -->
                    <div id="waitingOverlay" class="absolute inset-0 bg-gray-900/90 flex flex-col items-center justify-center">
                        <div class="text-6xl mb-4">📹</div>
                        <h2 class="text-xl font-bold mb-2">Video Call Center</h2>
                        <p class="text-gray-400 mb-4">รอสายเรียกเข้า...</p>
                        <div id="callCount" class="text-sm text-gray-500">กำลังตรวจสอบ...</div>
                    </div>
                    
                    <!-- Incoming Call Overlay -->
                    <div id="incomingOverlay" class="absolute inset-0 bg-green-900/95 hidden flex-col items-center justify-center">
                        <div class="text-6xl mb-4 ringing">📞</div>
                        <img id="callerPic" src="" class="w-20 h-20 rounded-full mb-4 border-4 border-white">
                        <h2 class="text-xl font-bold mb-1" id="callerName">ลูกค้า</h2>
                        <p class="text-green-300 mb-6 animate-pulse">กำลังโทรเข้า...</p>
                        <div class="flex gap-4">
                            <button onclick="answerCall()" class="px-8 py-4 bg-green-500 rounded-2xl text-lg font-bold hover:bg-green-400 flex items-center gap-2">
                                <span class="text-2xl">📞</span> รับสาย
                            </button>
                            <button onclick="rejectCall()" class="px-6 py-4 bg-red-500 rounded-2xl text-lg font-bold hover:bg-red-400">
                                ❌
                            </button>
                        </div>
                    </div>
                    
                    <!-- In Call Overlay -->
                    <div id="callTimer" class="absolute top-4 left-4 bg-black/60 px-4 py-2 rounded-full hidden">
                        <span class="text-red-500 animate-pulse mr-2">●</span>
                        <span id="timerDisplay">00:00</span>
                    </div>
                </div>
                
                <!-- Controls -->
                <div class="bg-gray-800 rounded-xl p-4">
                    <div class="flex justify-center gap-4">
                        <button onclick="toggleMute()" id="btnMute" class="p-4 bg-gray-700 rounded-full hover:bg-gray-600 transition">
                            <span class="text-2xl" id="muteIcon">🎤</span>
                        </button>
                        <button onclick="toggleVideo()" id="btnVideo" class="p-4 bg-gray-700 rounded-full hover:bg-gray-600 transition">
                            <span class="text-2xl" id="videoIcon">📹</span>
                        </button>
                        <button onclick="endCall()" id="btnEnd" class="p-4 bg-red-500 rounded-full hover:bg-red-400 transition hidden">
                            <span class="text-2xl">📵</span>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Pending Calls -->
                <div class="bg-gray-800 rounded-xl overflow-hidden">
                    <div class="p-4 bg-gradient-to-r from-green-600 to-emerald-600">
                        <h3 class="font-bold flex items-center gap-2">
                            📞 สายเรียกเข้า
                            <span id="pendingBadge" class="bg-white/20 px-2 py-0.5 rounded-full text-sm">0</span>
                        </h3>
                    </div>
                    <div id="pendingList" class="divide-y divide-gray-700 max-h-64 overflow-y-auto">
                        <div class="p-6 text-center text-gray-500">
                            <div class="text-3xl mb-2">📭</div>
                            <p>ไม่มีสายเรียกเข้า</p>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Link -->
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl p-4">
                    <h3 class="font-bold mb-2">📱 ลิงก์สำหรับลูกค้า</h3>
                    <p class="text-sm text-blue-200 mb-3">แชร์ลิงก์นี้ให้ลูกค้า</p>
                    <div class="flex gap-2">
                        <input type="text" id="customerLink" value="<?= BASE_URL ?>liff-video-call.php" readonly 
                               class="flex-1 px-3 py-2 bg-white/20 rounded-lg text-sm">
                        <button onclick="copyLink()" class="px-4 py-2 bg-white text-blue-600 rounded-lg font-medium hover:bg-blue-50">
                            📋
                        </button>
                    </div>
                </div>
                
                <!-- Debug Panel -->
                <div class="bg-gray-800 rounded-xl p-4">
                    <h3 class="font-bold mb-3 flex items-center gap-2">
                        🔧 Debug
                        <button onclick="toggleDebug()" class="text-xs bg-gray-700 px-2 py-1 rounded">Toggle</button>
                    </h3>
                    <div id="debugPanel" class="hidden">
                        <div id="debugLog" class="bg-black rounded-lg p-3 font-mono text-xs text-green-400 h-40 overflow-y-auto mb-3"></div>
                        <div class="flex gap-2 flex-wrap">
                            <button onclick="checkCalls()" class="px-3 py-1 bg-blue-500 rounded text-sm">Check</button>
                            <button onclick="createTestCall()" class="px-3 py-1 bg-purple-500 rounded text-sm">Test Call</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
const API_URL = 'api/video-call.php';
const ACCOUNT_ID = <?= $accountId ?>;

// WebRTC - เพิ่ม TURN server สำหรับ NAT traversal
const rtcConfig = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' },
        { urls: 'stun:stun3.l.google.com:19302' },
        // Free TURN servers (for testing)
        {
            urls: 'turn:openrelay.metered.ca:80',
            username: 'openrelayproject',
            credential: 'openrelayproject'
        },
        {
            urls: 'turn:openrelay.metered.ca:443',
            username: 'openrelayproject',
            credential: 'openrelayproject'
        }
    ],
    iceCandidatePoolSize: 10
};

let localStream = null;
let peerConnection = null;
let currentCall = null;
let callTimer = null;
let callSeconds = 0;
let isMuted = false;
let isVideoOff = false;
let pollInterval = null;
let signalPollInterval = null;

// Debug
function log(msg) {
    console.log(msg);
    const el = document.getElementById('debugLog');
    if (el) {
        const time = new Date().toLocaleTimeString();
        el.innerHTML += `<div>[${time}] ${msg}</div>`;
        el.scrollTop = el.scrollHeight;
    }
}

function toggleDebug() {
    document.getElementById('debugPanel').classList.toggle('hidden');
}

// Initialize
async function init() {
    log('Initializing...');
    
    // Get media
    try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        document.getElementById('localVideo').srcObject = localStream;
        log('✅ Camera ready');
    } catch (err) {
        log('❌ Camera error: ' + err.message);
        // Try audio only
        try {
            localStream = await navigator.mediaDevices.getUserMedia({ video: false, audio: true });
            log('✅ Audio only mode');
        } catch (e) {
            log('❌ No media access');
        }
    }
    
    // Start polling for calls
    checkCalls();
    pollInterval = setInterval(checkCalls, 3000);
    
    log('✅ Ready');
}

// Check for incoming calls
async function checkCalls() {
    try {
        const res = await fetch(`${API_URL}?action=check_calls&account_id=${ACCOUNT_ID}`);
        const data = await res.json();
        
        if (data.success) {
            updatePendingList(data.calls);
            document.getElementById('callCount').textContent = `${data.calls.length} สายรอรับ`;
            
            // Show first ringing call
            const ringing = data.calls.find(c => c.status === 'ringing');
            if (ringing && !currentCall) {
                showIncomingCall(ringing);
            }
        }
    } catch (err) {
        log('Poll error: ' + err.message);
    }
}

function updatePendingList(calls) {
    const list = document.getElementById('pendingList');
    const badge = document.getElementById('pendingBadge');
    
    badge.textContent = calls.length;
    
    if (calls.length === 0) {
        list.innerHTML = `<div class="p-6 text-center text-gray-500">
            <div class="text-3xl mb-2">📭</div>
            <p>ไม่มีสายเรียกเข้า</p>
        </div>`;
        return;
    }
    
    list.innerHTML = calls.map(call => `
        <div class="p-3 hover:bg-gray-700/50 flex items-center gap-3 ${call.status === 'ringing' ? 'bg-green-900/30' : ''}">
            <img src="${call.picture_url || 'https://via.placeholder.com/40'}" class="w-10 h-10 rounded-full">
            <div class="flex-1 min-w-0">
                <div class="font-medium truncate">${call.display_name || 'ลูกค้า'}</div>
                <div class="text-xs text-gray-400">${call.status === 'ringing' ? '📞 กำลังโทร...' : call.status}</div>
            </div>
            <button onclick="pickupCall(${call.id})" class="px-3 py-1.5 bg-green-500 rounded-lg text-sm hover:bg-green-400">
                รับ
            </button>
        </div>
    `).join('');
}

function showIncomingCall(call) {
    currentCall = call;
    document.getElementById('callerName').textContent = call.display_name || 'ลูกค้า';
    document.getElementById('callerPic').src = call.picture_url || 'https://via.placeholder.com/80';
    document.getElementById('incomingOverlay').classList.remove('hidden');
    document.getElementById('incomingOverlay').classList.add('flex');
    
    // Play sound
    playRingtone();
    
    log('📞 Incoming call from: ' + (call.display_name || 'Unknown'));
}

function playRingtone() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 440;
        gain.gain.value = 0.3;
        osc.start();
        setTimeout(() => osc.stop(), 300);
    } catch (e) {}
}

// Answer call
async function answerCall() {
    if (!currentCall) return;
    
    log('Answering call ' + currentCall.id);
    
    // Hide overlays
    document.getElementById('incomingOverlay').classList.add('hidden');
    document.getElementById('waitingOverlay').classList.add('hidden');
    document.getElementById('callTimer').classList.remove('hidden');
    document.getElementById('btnEnd').classList.remove('hidden');
    
    // Update status
    await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'answer', call_id: currentCall.id })
    });
    
    // Setup WebRTC
    await setupPeerConnection();
    
    // Start timer
    startTimer();
    
    // Start signal polling
    startSignalPolling();
    
    log('✅ Call answered');
}

async function pickupCall(callId) {
    // Find call
    const res = await fetch(`${API_URL}?action=check_calls&account_id=${ACCOUNT_ID}`);
    const data = await res.json();
    const call = data.calls.find(c => c.id == callId);
    
    if (call) {
        currentCall = call;
        await answerCall();
    }
}

async function rejectCall() {
    if (!currentCall) return;
    
    await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reject', call_id: currentCall.id })
    });
    
    document.getElementById('incomingOverlay').classList.add('hidden');
    currentCall = null;
    
    log('❌ Call rejected');
}

// WebRTC Setup
async function setupPeerConnection() {
    peerConnection = new RTCPeerConnection(rtcConfig);
    
    // Add local tracks
    if (localStream) {
        localStream.getTracks().forEach(track => {
            peerConnection.addTrack(track, localStream);
        });
    }
    
    // Handle remote stream
    peerConnection.ontrack = (event) => {
        log('📹 Remote stream received');
        document.getElementById('remoteVideo').srcObject = event.streams[0];
    };
    
    // Handle ICE candidates
    peerConnection.onicecandidate = (event) => {
        if (event.candidate) {
            sendSignal('ice-candidate', event.candidate);
        }
    };
    
    peerConnection.onconnectionstatechange = () => {
        log('Connection state: ' + peerConnection.connectionState);
        if (peerConnection.connectionState === 'connected') {
            log('✅ WebRTC Connected!');
        } else if (peerConnection.connectionState === 'failed') {
            log('❌ Connection failed - try refreshing');
        }
    };
    
    peerConnection.oniceconnectionstatechange = () => {
        log('ICE state: ' + peerConnection.iceConnectionState);
    };
}

async function sendSignal(type, data) {
    await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'signal',
            call_id: currentCall.id,
            signal_type: type,
            signal_data: data,
            from: 'admin'
        })
    });
}

function startSignalPolling() {
    signalPollInterval = setInterval(async () => {
        if (!currentCall) return;
        
        try {
            const res = await fetch(`${API_URL}?action=get_signals&call_id=${currentCall.id}&for=admin`);
            const data = await res.json();
            
            if (data.success && data.signals) {
                for (const signal of data.signals) {
                    await handleSignal(signal);
                }
            }
        } catch (err) {
            log('Signal poll error: ' + err.message);
        }
    }, 1000);
}

async function handleSignal(signal) {
    if (!peerConnection) return;
    
    log('Signal: ' + signal.signal_type);
    
    try {
        if (signal.signal_type === 'offer') {
            await peerConnection.setRemoteDescription(new RTCSessionDescription(signal.signal_data));
            const answer = await peerConnection.createAnswer();
            await peerConnection.setLocalDescription(answer);
            await sendSignal('answer', answer);
            log('✅ Sent answer');
        } else if (signal.signal_type === 'ice-candidate') {
            await peerConnection.addIceCandidate(new RTCIceCandidate(signal.signal_data));
        }
    } catch (err) {
        log('Signal error: ' + err.message);
    }
}

// End call
async function endCall() {
    log('Ending call...');
    
    if (signalPollInterval) {
        clearInterval(signalPollInterval);
        signalPollInterval = null;
    }
    
    if (peerConnection) {
        peerConnection.close();
        peerConnection = null;
    }
    
    stopTimer();
    
    if (currentCall) {
        await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'end',
                call_id: currentCall.id,
                duration: callSeconds
            })
        });
    }
    
    // Reset UI
    document.getElementById('waitingOverlay').classList.remove('hidden');
    document.getElementById('callTimer').classList.add('hidden');
    document.getElementById('btnEnd').classList.add('hidden');
    document.getElementById('remoteVideo').srcObject = null;
    
    currentCall = null;
    callSeconds = 0;
    
    log('✅ Call ended');
}

// Controls
function toggleMute() {
    isMuted = !isMuted;
    if (localStream) {
        localStream.getAudioTracks().forEach(t => t.enabled = !isMuted);
    }
    document.getElementById('muteIcon').textContent = isMuted ? '🔇' : '🎤';
    document.getElementById('btnMute').classList.toggle('bg-red-500', isMuted);
}

function toggleVideo() {
    isVideoOff = !isVideoOff;
    if (localStream) {
        localStream.getVideoTracks().forEach(t => t.enabled = !isVideoOff);
    }
    document.getElementById('videoIcon').textContent = isVideoOff ? '📷' : '📹';
    document.getElementById('btnVideo').classList.toggle('bg-red-500', isVideoOff);
}

// Timer
function startTimer() {
    callSeconds = 0;
    callTimer = setInterval(() => {
        callSeconds++;
        const m = Math.floor(callSeconds / 60).toString().padStart(2, '0');
        const s = (callSeconds % 60).toString().padStart(2, '0');
        document.getElementById('timerDisplay').textContent = `${m}:${s}`;
    }, 1000);
}

function stopTimer() {
    if (callTimer) {
        clearInterval(callTimer);
        callTimer = null;
    }
}

// Utils
function copyLink() {
    navigator.clipboard.writeText(document.getElementById('customerLink').value);
    alert('คัดลอกลิงก์แล้ว!');
}

async function createTestCall() {
    log('Creating test call...');
    const res = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'create',
            user_id: 'test_' + Date.now(),
            display_name: 'Test Customer',
            account_id: ACCOUNT_ID
        })
    });
    const data = await res.json();
    log('Test call: ' + JSON.stringify(data));
    checkCalls();
}

// Start
init();
</script>
</body>
</html>
