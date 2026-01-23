<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Video Call</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
        }

        body {
            overscroll-behavior: none;
        }

        .video-full {
            position: fixed;
            inset: 0;
            object-fit: cover;
        }

        .pip {
            position: fixed;
            top: 1rem;
            right: 1rem;
            width: 120px;
            aspect-ratio: 9/16;
            border-radius: 1rem;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, 0.3);
            background: #1f2937;
            z-index: 10;
        }

        .control-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 1.5rem;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
            z-index: 20;
        }

        .ctrl-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.2s;
        }

        .ctrl-btn:active {
            transform: scale(0.9);
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: 0.5
            }
        }

        .pulse {
            animation: pulse 2s infinite;
        }
    </style>
</head>

<body class="bg-black">

    <!-- Loading -->
    <div id="loading" class="fixed inset-0 bg-gray-900 flex flex-col items-center justify-center text-white z-50">
        <div class="text-6xl mb-4 animate-bounce">📹</div>
        <p class="text-lg">กำลังเตรียมพร้อม...</p>
        <p id="loadingStatus" class="text-sm text-gray-400 mt-2">เชื่อมต่อกล้อง</p>
    </div>

    <!-- Pre-Call -->
    <div id="preCall" class="fixed inset-0 hidden">
        <video id="previewVideo" class="video-full" autoplay playsinline muted></video>
        <div class="fixed inset-0 bg-gradient-to-t from-black via-transparent to-transparent"></div>

        <?php if ($appointmentInfo): ?>
            <!-- Appointment Info Banner -->
            <div class="fixed top-4 left-4 right-4 z-10">
                <div class="bg-white/95 backdrop-blur rounded-2xl p-4 shadow-lg">
                    <div class="flex items-center gap-3">
                        <?php if ($appointmentInfo['pharmacist_image']): ?>
                            <img src="<?= htmlspecialchars($appointmentInfo['pharmacist_image']) ?>"
                                class="w-14 h-14 rounded-full object-cover border-2 border-green-500">
                        <?php else: ?>
                            <div class="w-14 h-14 rounded-full bg-green-100 flex items-center justify-center text-2xl">👨‍⚕️
                            </div>
                        <?php endif; ?>
                        <div class="flex-1">
                            <p class="text-xs text-green-600 font-medium">นัดหมาย
                                #<?= htmlspecialchars($appointmentInfo['appointment_id']) ?></p>
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($appointmentInfo['pharmacist_name']) ?>
                            </p>
                            <p class="text-xs text-gray-500">
                                <?= htmlspecialchars($appointmentInfo['pharmacist_title'] ?: 'เภสัชกร') ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-gray-800">
                                <?= date('H:i', strtotime($appointmentInfo['appointment_time'])) ?> น.</p>
                            <p class="text-xs text-gray-500">
                                <?= date('d/m/Y', strtotime($appointmentInfo['appointment_date'])) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($pharmacistInfo): ?>
            <!-- Direct Pharmacist Call Banner -->
            <div class="fixed top-4 left-4 right-4 z-10">
                <div class="bg-white/95 backdrop-blur rounded-2xl p-4 shadow-lg">
                    <div class="flex items-center gap-3">
                        <?php if ($pharmacistInfo['image_url']): ?>
                            <img src="<?= htmlspecialchars($pharmacistInfo['image_url']) ?>"
                                class="w-14 h-14 rounded-full object-cover border-2 border-teal-500">
                        <?php else: ?>
                            <div class="w-14 h-14 rounded-full bg-teal-100 flex items-center justify-center text-2xl">👨‍⚕️
                            </div>
                        <?php endif; ?>
                        <div class="flex-1">
                            <p class="text-xs text-teal-600 font-medium">ปรึกษาวิดีโอ</p>
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($pharmacistInfo['name']) ?></p>
                            <p class="text-xs text-gray-500">
                                <?= htmlspecialchars($pharmacistInfo['title'] ?: $pharmacistInfo['specialty'] ?: 'เภสัชกร') ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="fixed bottom-0 left-0 right-0 p-6 text-white z-10">
            <h1 class="text-2xl font-bold mb-1">📹 Video Call</h1>
            <p class="text-gray-300 mb-6">โทรหาทีมงานของเรา</p>

            <button onclick="startCall(false)" id="btnVideoCall"
                class="w-full py-4 bg-green-500 rounded-2xl text-lg font-bold flex items-center justify-center gap-3 mb-3 active:bg-green-600">
                <span class="text-2xl">📹</span> Video Call
            </button>
            <button onclick="startCall(true)"
                class="w-full py-3 bg-white/20 backdrop-blur rounded-2xl font-medium flex items-center justify-center gap-2 active:bg-white/30">
                <span>📞</span> Audio Only
            </button>
        </div>

        <div class="fixed top-4 right-4 flex gap-2 z-10">
            <button onclick="switchCamera()"
                class="w-12 h-12 bg-black/50 backdrop-blur rounded-full flex items-center justify-center text-white text-xl">🔄</button>
        </div>
    </div>

    <!-- In Call -->
    <div id="inCall" class="fixed inset-0 hidden">
        <video id="remoteVideo" class="video-full" autoplay playsinline></video>
        <div class="pip" id="localPip">
            <video id="localVideo" class="w-full h-full object-cover" autoplay playsinline muted></video>
        </div>

        <!-- Status Bar -->
        <div class="fixed top-4 left-4 z-10">
            <div id="callStatus"
                class="bg-black/60 backdrop-blur px-4 py-2 rounded-full text-white flex items-center gap-2">
                <span class="w-2 h-2 bg-green-500 rounded-full pulse"></span>
                <span id="statusText">กำลังเชื่อมต่อ...</span>
            </div>
        </div>

        <div class="fixed top-4 right-32 z-10">
            <div id="timerBadge" class="bg-black/60 backdrop-blur px-4 py-2 rounded-full text-white hidden">
                <span class="text-red-500 mr-1">●</span>
                <span id="timerDisplay">00:00</span>
            </div>
        </div>

        <!-- Controls -->
        <div class="control-bar">
            <div class="flex justify-center items-center gap-4">
                <button onclick="toggleMute()" id="btnMute"
                    class="ctrl-btn bg-white/20 backdrop-blur text-white">🎤</button>
                <button onclick="endCall()" class="ctrl-btn bg-red-500 text-white w-16 h-16 text-2xl">📵</button>
                <button onclick="toggleVideo()" id="btnVideo"
                    class="ctrl-btn bg-white/20 backdrop-blur text-white">📹</button>
                <button onclick="switchCamera()" class="ctrl-btn bg-white/20 backdrop-blur text-white">🔄</button>
            </div>
        </div>
    </div>

    <!-- End Screen -->
    <div id="endScreen" class="fixed inset-0 bg-gray-900 hidden flex-col items-center justify-center text-white p-6">
        <div class="text-6xl mb-4">✅</div>
        <h2 class="text-2xl font-bold mb-2">สิ้นสุดการโทร</h2>
        <p class="text-gray-400 mb-1">ระยะเวลา</p>
        <p id="finalDuration" class="text-3xl font-mono mb-8">00:00</p>
        <button onclick="resetCall()"
            class="w-full max-w-xs py-4 bg-green-500 rounded-2xl font-bold mb-3">โทรอีกครั้ง</button>
        <button onclick="closeLiff()" class="w-full max-w-xs py-3 bg-white/20 rounded-2xl">ปิด</button>
    </div>

    <script>
        const BASE_URL = '<?= rtrim(BASE_URL, "/") ?>/';
        const LIFF_ID = '<?= $videoLiffId ?>';
        const API = BASE_URL + 'api/video-call.php';
        const APPOINTMENT_ID = <?= $appointmentId ? "'" . addslashes($appointmentId) . "'" : 'null' ?>;
        const LINE_ACCOUNT_ID = <?= (int) $lineAccountId ?>;

        console.log('🔧 Config:', { BASE_URL, LIFF_ID, API });

        const rtcConfig = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
                { urls: 'turn:openrelay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' },
                { urls: 'turn:openrelay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' }
            ]
        };

        let profile = null, localStream = null, peerConnection = null;
        let currentCallId = null, callTimer = null, callSeconds = 0;
        let isMuted = false, isVideoOff = false, facingMode = 'user';
        let signalPoll = null, pendingIceCandidates = [], answerReceived = false;

        async function init() {
            setStatus('เริ่มต้นระบบ...');
            if (LIFF_ID) {
                try {
                    await liff.init({ liffId: LIFF_ID });
                    if (liff.isLoggedIn()) profile = await liff.getProfile();
                } catch (e) { console.log('LIFF error:', e); }
            }

            setStatus('เข้าถึงกล้อง...');
            try {
                localStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode, width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: { echoCancellation: true, noiseSuppression: true }
                });
                document.getElementById('previewVideo').srcObject = localStream;
            } catch (e) {
                console.error('Media error:', e);
                try {
                    localStream = await navigator.mediaDevices.getUserMedia({ video: false, audio: true });
                    isVideoOff = true;
                } catch (e2) {
                    Swal.fire({ icon: 'error', title: 'ไม่สามารถเข้าถึงกล้องหรือไมค์ได้', confirmButtonColor: '#06C755' });
                }
            }

            document.getElementById('loading').classList.add('hidden');
            document.getElementById('preCall').classList.remove('hidden');
        }

        function setStatus(text) {
            const el = document.getElementById('loadingStatus') || document.getElementById('statusText');
            if (el) el.textContent = text;
        }

        async function startCall(audioOnly = false) {
            if (audioOnly && !isVideoOff) {
                localStream?.getVideoTracks().forEach(t => t.enabled = false);
                isVideoOff = true;
            }

            document.getElementById('preCall').classList.add('hidden');
            document.getElementById('inCall').classList.remove('hidden');
            document.getElementById('localVideo').srcObject = localStream;
            setStatus('กำลังโทร...');

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create',
                        user_id: profile?.userId || 'guest_' + Date.now(),
                        display_name: profile?.displayName || 'ลูกค้า',
                        picture_url: profile?.pictureUrl || '',
                        account_id: LINE_ACCOUNT_ID
                    })
                });

                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed');

                currentCallId = data.call_id;
                await setupPeerConnection();
                startSignalPolling();
            } catch (e) {
                console.error('Call error:', e);
                Swal.fire({ icon: 'error', title: 'ไม่สามารถเริ่มการโทรได้', text: e.message, confirmButtonColor: '#06C755' });
                resetCall();
            }
        }

        async function setupPeerConnection() {
            peerConnection = new RTCPeerConnection(rtcConfig);
            localStream?.getTracks().forEach(t => peerConnection.addTrack(t, localStream));

            peerConnection.ontrack = (e) => {
                document.getElementById('remoteVideo').srcObject = e.streams[0];
                setStatus('🟢 เชื่อมต่อแล้ว');
                document.getElementById('timerBadge').classList.remove('hidden');
                if (!callTimer) startTimer();
            };

            peerConnection.onicecandidate = (e) => {
                if (e.candidate) sendSignal('ice-candidate', e.candidate);
            };

            peerConnection.oniceconnectionstatechange = () => {
                const state = peerConnection.iceConnectionState;
                if (state === 'connected' || state === 'completed') {
                    setStatus('🟢 เชื่อมต่อแล้ว');
                    document.getElementById('timerBadge').classList.remove('hidden');
                    if (!callTimer) startTimer();
                } else if (state === 'failed') {
                    setStatus('เชื่อมต่อล้มเหลว');
                    setTimeout(() => endCall('เชื่อมต่อล้มเหลว'), 2000);
                }
            };

            const offer = await peerConnection.createOffer();
            await peerConnection.setLocalDescription(offer);
            await sendSignal('offer', offer);
        }

        async function sendSignal(type, data) {
            await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'signal', call_id: currentCallId, signal_type: type, signal_data: data, from: 'customer' })
            });
        }

        function startSignalPolling() {
            let timeout = setTimeout(() => {
                if (document.getElementById('statusText')?.textContent === 'กำลังโทร...') {
                    setStatus('ไม่มีผู้รับสาย');
                    setTimeout(endCall, 2000);
                }
            }, 60000);

            signalPoll = setInterval(async () => {
                if (!currentCallId) return;
                try {
                    const statusRes = await fetch(`${API}?action=get_status&call_id=${currentCallId}`);
                    const statusData = await statusRes.json();

                    if (statusData.status === 'rejected') {
                        clearTimeout(timeout);
                        setStatus('ถูกปฏิเสธ');
                        setTimeout(endCall, 1500);
                        return;
                    }
                    if (statusData.status === 'completed' || statusData.status === 'ended') {
                        clearTimeout(timeout);
                        setStatus('แอดมินวางสายแล้ว');
                        setTimeout(() => endCall('แอดมินวางสายแล้ว'), 1000);
                        return;
                    }

                    const sigRes = await fetch(`${API}?action=get_signals&call_id=${currentCallId}&for=customer`);
                    const sigData = await sigRes.json();

                    if (sigData.success && sigData.signals?.length > 0) {
                        for (const sig of sigData.signals) await handleSignal(sig);
                    }
                } catch (e) { console.error('Poll error:', e); }
            }, 500);
        }

        async function handleSignal(signal) {
            if (!peerConnection) return;
            try {
                if (signal.signal_type === 'answer') {
                    if (answerReceived || peerConnection.signalingState !== 'have-local-offer') return;
                    await peerConnection.setRemoteDescription(new RTCSessionDescription(signal.signal_data));
                    answerReceived = true;
                    for (const candidate of pendingIceCandidates) {
                        await peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
                    }
                    pendingIceCandidates = [];
                } else if (signal.signal_type === 'ice-candidate' && signal.signal_data) {
                    if (!peerConnection.remoteDescription) {
                        pendingIceCandidates.push(signal.signal_data);
                    } else {
                        await peerConnection.addIceCandidate(new RTCIceCandidate(signal.signal_data));
                    }
                }
            } catch (e) { console.error('Signal error:', e); }
        }

        function toggleMute() {
            isMuted = !isMuted;
            localStream?.getAudioTracks().forEach(t => t.enabled = !isMuted);
            document.getElementById('btnMute').textContent = isMuted ? '🔇' : '🎤';
            document.getElementById('btnMute').classList.toggle('bg-red-500', isMuted);
        }

        function toggleVideo() {
            isVideoOff = !isVideoOff;
            localStream?.getVideoTracks().forEach(t => t.enabled = !isVideoOff);
            document.getElementById('btnVideo').textContent = isVideoOff ? '📷' : '📹';
            document.getElementById('btnVideo').classList.toggle('bg-red-500', isVideoOff);
        }

        async function switchCamera() {
            facingMode = facingMode === 'user' ? 'environment' : 'user';
            try {
                const newStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode, width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: true
                });
                const newVideoTrack = newStream.getVideoTracks()[0];
                localStream?.getVideoTracks().forEach(t => { t.stop(); localStream.removeTrack(t); });
                localStream?.addTrack(newVideoTrack);
                document.getElementById('previewVideo').srcObject = localStream;
                document.getElementById('localVideo').srcObject = localStream;
                const sender = peerConnection?.getSenders().find(s => s.track?.kind === 'video');
                if (sender) await sender.replaceTrack(newVideoTrack);
            } catch (e) { console.error('Switch camera error:', e); }
        }

        async function endCall(showMessage = 'สิ้นสุดการโทร') {
            if (signalPoll) { clearInterval(signalPoll); signalPoll = null; }
            if (peerConnection) { peerConnection.close(); peerConnection = null; }
            stopTimer();

            const duration = callSeconds;
            const callId = currentCallId;
            currentCallId = null;
            answerReceived = false;

            if (callId) {
                try {
                    await fetch(API, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'end', call_id: callId, duration: duration })
                    });
                } catch (e) { }
            }

            document.getElementById('inCall').classList.add('hidden');
            document.getElementById('endScreen').classList.remove('hidden');
            document.getElementById('endScreen').classList.add('flex');
            document.querySelector('#endScreen h2').textContent = showMessage;

            const m = Math.floor(duration / 60).toString().padStart(2, '0');
            const s = (duration % 60).toString().padStart(2, '0');
            document.getElementById('finalDuration').textContent = `${m}:${s}`;
        }

        function resetCall() {
            currentCallId = null; callSeconds = 0;
            answerReceived = false; pendingIceCandidates = [];
            document.getElementById('endScreen').classList.add('hidden');
            document.getElementById('preCall').classList.remove('hidden');
            document.getElementById('timerBadge').classList.add('hidden');
        }

        function closeLiff() {
            if (typeof liff !== 'undefined' && liff.isInClient()) liff.closeWindow();
            else window.close();
        }

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
            if (callTimer) { clearInterval(callTimer); callTimer = null; }
        }

        init();
    </script>
</body>

</html>