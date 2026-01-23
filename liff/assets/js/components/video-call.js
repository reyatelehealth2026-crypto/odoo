/**
 * WebRTC Video Call Component
 * Implements peer-to-peer video calling for pharmacist consultations
 * 
 * Requirements: 6.2, 6.3, 6.4, 6.5, 6.8
 * - Establish WebRTC peer-to-peer connection using signaling
 * - Display remote and local video with proper layout
 * - Display Mute, Camera toggle, and End Call buttons with clear icons
 * - Display red confirmation button for End Call
 * - Handle iOS WebRTC limitations by offering external browser option
 */

class VideoCallManager {
    constructor() {
        // WebRTC configuration with STUN/TURN servers
        this.rtcConfig = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
                { urls: 'stun:stun2.l.google.com:19302' },
                { urls: 'turn:openrelay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' },
                { urls: 'turn:openrelay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' }
            ],
            iceCandidatePoolSize: 10
        };

        // State
        this.localStream = null;
        this.remoteStream = null;
        this.peerConnection = null;
        this.currentCallId = null;
        this.roomId = null;

        // Call state
        this.callState = 'idle'; // idle, connecting, ringing, active, ended
        this.callStartTime = null;
        this.callDuration = 0;
        this.callTimer = null;

        // Media state
        this.isMuted = false;
        this.isVideoOff = false;
        this.facingMode = 'user';
        this.isSpeakerOn = true;

        // Signaling
        this.signalPollInterval = null;
        this.pendingIceCandidates = [];
        this.answerReceived = false;

        // Callbacks
        this.onStateChange = null;
        this.onRemoteStream = null;
        this.onCallEnded = null;
        this.onError = null;
        this.onControlsUpdate = null;

        // Config
        this.baseUrl = window.APP_CONFIG?.BASE_URL || '';
        this.accountId = window.APP_CONFIG?.ACCOUNT_ID || 1;

        // Device detection
        this.isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
        this.isAndroid = /Android/.test(navigator.userAgent);
        this.isLINE = /Line/i.test(navigator.userAgent);
        this.isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

        // iOS WebRTC limitations flag
        this.hasIOSLimitations = this.isIOS && this.isLINE;
    }

    /**
     * Initialize the video call manager
     * @param {Object} options - Configuration options
     */
    init(options = {}) {
        if (options.baseUrl) this.baseUrl = options.baseUrl;
        if (options.accountId) this.accountId = options.accountId;
        if (options.appointmentId) this.appointmentId = options.appointmentId;
        if (options.onStateChange) this.onStateChange = options.onStateChange;
        if (options.onRemoteStream) this.onRemoteStream = options.onRemoteStream;
        if (options.onCallEnded) this.onCallEnded = options.onCallEnded;
        if (options.onError) this.onError = options.onError;
        if (options.onControlsUpdate) this.onControlsUpdate = options.onControlsUpdate;

        console.log('📹 VideoCallManager initialized', {
            isIOS: this.isIOS,
            isLINE: this.isLINE,
            hasIOSLimitations: this.hasIOSLimitations,
            appointmentId: this.appointmentId
        });
    }

    /**
     * Check if iOS WebRTC limitations apply
     * Requirements: 6.8 - Handle iOS WebRTC limitations
     * @returns {Object} - Limitation info
     */
    checkIOSLimitations() {
        if (!this.hasIOSLimitations) {
            return { hasLimitations: false };
        }

        return {
            hasLimitations: true,
            message: 'LINE บน iOS มีข้อจำกัดในการใช้งานวิดีโอคอล',
            suggestion: 'แนะนำให้เปิดใน Safari เพื่อประสบการณ์ที่ดีที่สุด',
            canProceed: true, // Can still try, but may have issues
            externalBrowserUrl: this.getExternalBrowserUrl()
        };
    }

    /**
     * Get URL for opening in external browser
     * @returns {string} - External browser URL
     */
    getExternalBrowserUrl() {
        const currentUrl = window.location.href;
        // Remove LIFF-specific parameters and add external flag
        const url = new URL(currentUrl);
        url.searchParams.set('external', '1');
        return url.toString();
    }

    /**
     * Open video call in external browser (for iOS)
     * Requirements: 6.8 - Offer external browser option when necessary
     */
    openInExternalBrowser() {
        const url = this.getExternalBrowserUrl();

        if (this.isIOS) {
            // On iOS, use window.open which should open in Safari
            window.open(url, '_blank');
        } else {
            // On other platforms, just navigate
            window.location.href = url;
        }
    }

    /**
     * Get API endpoint URL
     */
    getApiUrl() {
        const base = this.baseUrl.replace(/\/+$/, ''); // Remove trailing slashes
        return `${base}/api/video-call.php`;
    }

    /**
     * Update call state and notify listeners
     * @param {string} state - New state
     * @param {Object} data - Additional data
     */
    setState(state, data = {}) {
        const oldState = this.callState;
        this.callState = state;

        console.log(`📹 Call state: ${oldState} -> ${state}`, data);

        if (this.onStateChange) {
            this.onStateChange(state, oldState, data);
        }
    }

    /**
     * Get current controls state
     * Requirements: 6.4 - Display Mute, Camera toggle, and End Call buttons
     * @returns {Object} - Current controls state
     */
    getControlsState() {
        return {
            isMuted: this.isMuted,
            isVideoOff: this.isVideoOff,
            isSpeakerOn: this.isSpeakerOn,
            facingMode: this.facingMode,
            callState: this.callState,
            duration: this.callDuration,
            formattedDuration: this.formatDuration(this.callDuration)
        };
    }

    /**
     * Notify controls update
     */
    notifyControlsUpdate() {
        if (this.onControlsUpdate) {
            this.onControlsUpdate(this.getControlsState());
        }
    }

    /**
     * Request media permissions and get local stream
     * @param {Object} constraints - Media constraints
     * @returns {Promise<MediaStream>} - Local media stream
     */
    async getLocalStream(constraints = {}) {
        const defaultConstraints = {
            video: {
                facingMode: this.facingMode,
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            }
        };

        const finalConstraints = { ...defaultConstraints, ...constraints };

        try {
            console.log('📹 Requesting media with constraints:', finalConstraints);
            this.localStream = await navigator.mediaDevices.getUserMedia(finalConstraints);
            console.log('📹 Got local stream:', this.localStream.getTracks().map(t => t.kind));
            return this.localStream;
        } catch (error) {
            console.error('📹 Media error:', error);

            // Try audio only if video fails
            if (finalConstraints.video) {
                console.log('📹 Trying audio only...');
                try {
                    this.localStream = await navigator.mediaDevices.getUserMedia({
                        video: false,
                        audio: finalConstraints.audio
                    });
                    this.isVideoOff = true;
                    return this.localStream;
                } catch (audioError) {
                    throw new Error('ไม่สามารถเข้าถึงกล้องหรือไมโครโฟนได้');
                }
            }
            throw error;
        }
    }

    /**
     * Create a new call session
     * @returns {Promise<Object>} - Call info with call_id and room_id
     */
    async createCall() {
        const profile = window.store?.get('profile');
        const apiUrl = this.getApiUrl();

        console.log('📹 Creating call...', { apiUrl, accountId: this.accountId, appointmentId: this.appointmentId });

        const requestBody = {
            action: 'create',
            user_id: profile?.userId || 'guest_' + Date.now(),
            display_name: profile?.displayName || 'ลูกค้า',
            picture_url: profile?.pictureUrl || '',
            account_id: this.accountId,
            appointment_id: this.appointmentId || null
        };

        console.log('📹 Request body:', requestBody);

        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestBody)
        });

        const data = await response.json();
        console.log('📹 Create call response:', data);

        if (!data.success) {
            throw new Error(data.error || 'ไม่สามารถสร้างการโทรได้');
        }

        this.currentCallId = data.call_id;
        this.roomId = data.room_id;

        console.log('📹 Call created:', { callId: this.currentCallId, roomId: this.roomId });

        return data;
    }

    /**
     * Setup WebRTC peer connection
     * Requirements: 6.2 - Establish WebRTC connection using peer-to-peer signaling
     */
    async setupPeerConnection() {
        console.log('📹 Setting up peer connection...');

        // Create peer connection
        this.peerConnection = new RTCPeerConnection(this.rtcConfig);

        // Add local tracks to connection
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => {
                console.log('📹 Adding track:', track.kind);
                this.peerConnection.addTrack(track, this.localStream);
            });
        }

        // Handle remote stream
        // Requirements: 6.3 - Display remote video prominent
        this.peerConnection.ontrack = (event) => {
            console.log('📹 Remote track received:', event.track.kind);

            if (!this.remoteStream) {
                this.remoteStream = new MediaStream();
            }

            this.remoteStream.addTrack(event.track);

            if (this.onRemoteStream) {
                this.onRemoteStream(this.remoteStream);
            }
        };

        // Handle ICE candidates
        this.peerConnection.onicecandidate = (event) => {
            if (event.candidate) {
                console.log('📹 ICE candidate:', event.candidate.type);
                this.sendSignal('ice-candidate', event.candidate);
            }
        };

        // Handle connection state changes
        this.peerConnection.oniceconnectionstatechange = () => {
            const state = this.peerConnection.iceConnectionState;
            console.log('📹 ICE connection state:', state);

            switch (state) {
                case 'connected':
                case 'completed':
                    this.setState('active');
                    this.startCallTimer();
                    break;
                case 'disconnected':
                    this.setState('reconnecting');
                    break;
                case 'failed':
                    this.handleConnectionFailed();
                    break;
                case 'closed':
                    this.setState('ended');
                    break;
            }
        };

        // Handle signaling state changes
        this.peerConnection.onsignalingstatechange = () => {
            console.log('📹 Signaling state:', this.peerConnection.signalingState);
        };

        // Create and send offer
        const offer = await this.peerConnection.createOffer({
            offerToReceiveAudio: true,
            offerToReceiveVideo: true
        });

        await this.peerConnection.setLocalDescription(offer);
        await this.sendSignal('offer', offer);

        console.log('📹 Offer sent');
    }

    /**
     * Send signaling data to server
     * @param {string} type - Signal type (offer, answer, ice-candidate)
     * @param {Object} data - Signal data
     */
    async sendSignal(type, data) {
        try {
            const response = await fetch(this.getApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'signal',
                    call_id: this.currentCallId,
                    signal_type: type,
                    signal_data: data,
                    from: 'customer'
                })
            });

            const result = await response.json();
            console.log('📹 Signal sent:', type, result.success);
            return result;
        } catch (error) {
            console.error('📹 Signal error:', error);
        }
    }

    /**
     * Start polling for signals from server
     */
    startSignalPolling() {
        console.log('📹 Starting signal polling...');

        // Set timeout for no answer
        const noAnswerTimeout = setTimeout(() => {
            if (this.callState === 'ringing' || this.callState === 'connecting') {
                console.log('📹 No answer timeout');
                this.endCall('ไม่มีผู้รับสาย');
            }
        }, 60000); // 60 second timeout

        this.signalPollInterval = setInterval(async () => {
            if (!this.currentCallId) return;

            try {
                // Check call status
                const statusRes = await fetch(`${this.getApiUrl()}?action=get_status&call_id=${this.currentCallId}`);
                const statusData = await statusRes.json();

                if (statusData.status === 'rejected') {
                    clearTimeout(noAnswerTimeout);
                    this.endCall('ถูกปฏิเสธ');
                    return;
                }

                if (statusData.status === 'completed' || statusData.status === 'ended') {
                    clearTimeout(noAnswerTimeout);
                    this.endCall('สิ้นสุดการโทร');
                    return;
                }

                // Get signals
                const sigRes = await fetch(`${this.getApiUrl()}?action=get_signals&call_id=${this.currentCallId}&for=customer`);
                const sigData = await sigRes.json();

                if (sigData.success && sigData.signals?.length > 0) {
                    clearTimeout(noAnswerTimeout);
                    for (const signal of sigData.signals) {
                        await this.handleSignal(signal);
                    }
                }
            } catch (error) {
                console.error('📹 Poll error:', error);
            }
        }, 500); // Poll every 500ms
    }

    /**
     * Handle incoming signal
     * @param {Object} signal - Signal data
     */
    async handleSignal(signal) {
        // Allow hangup and message signals even without peer connection
        if (!this.peerConnection && signal.signal_type !== 'hangup' && signal.signal_type !== 'message') return;

        console.log('📹 Handling signal:', signal.signal_type);

        try {
            switch (signal.signal_type) {
                case 'answer':
                    if (this.answerReceived) {
                        console.log('📹 Answer already received, skipping');
                        return;
                    }

                    if (this.peerConnection.signalingState !== 'have-local-offer') {
                        console.log('📹 Wrong state for answer:', this.peerConnection.signalingState);
                        return;
                    }

                    await this.peerConnection.setRemoteDescription(
                        new RTCSessionDescription(signal.signal_data)
                    );
                    this.answerReceived = true;

                    // Process pending ICE candidates
                    console.log('📹 Processing', this.pendingIceCandidates.length, 'pending ICE candidates');
                    for (const candidate of this.pendingIceCandidates) {
                        await this.peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
                    }
                    this.pendingIceCandidates = [];
                    break;

                case 'ice-candidate':
                    if (signal.signal_data) {
                        if (!this.peerConnection.remoteDescription) {
                            // Queue candidate until remote description is set
                            this.pendingIceCandidates.push(signal.signal_data);
                        } else {
                            await this.peerConnection.addIceCandidate(
                                new RTCIceCandidate(signal.signal_data)
                            );
                        }
                    }
                    break;

                case 'hangup':
                    // Other party hung up
                    console.log('📹 Received hangup signal from admin');
                    this.endCall('เภสัชกรวางสายแล้ว');
                    break;

                case 'message':
                    // Received message from admin
                    console.log('📹 Received message:', signal.signal_data);
                    this.showIncomingMessage(signal.signal_data);
                    break;
            }
        } catch (error) {
            console.error('📹 Signal handling error:', error);
        }
    }

    /**
     * Show incoming message overlay
     * @param {Object} data - Message data
     */
    showIncomingMessage(data) {
        const text = data.text || data.message || 'ข้อความจากเภสัชกร';
        const icon = data.type === 'greeting' ? '👋' : '⏳';

        // Create message overlay
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.85);
            color: white;
            padding: 24px 48px;
            border-radius: 16px;
            font-size: 20px;
            z-index: 10001;
            animation: fadeInScale 0.3s ease;
            text-align: center;
            max-width: 80%;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        `;
        overlay.innerHTML = `<div style="font-size: 48px; margin-bottom: 12px;">${icon}</div>${text}`;
        document.body.appendChild(overlay);

        // Callback if set
        if (this.onMessage) {
            this.onMessage(data);
        }

        // Remove after 3 seconds
        setTimeout(() => {
            overlay.style.opacity = '0';
            overlay.style.transition = 'opacity 0.3s';
            setTimeout(() => overlay.remove(), 300);
        }, 3000);
    }

    /**
     * Start a video call
     * @param {boolean} audioOnly - Start with audio only
     * @returns {Promise<void>}
     */
    async startCall(audioOnly = false) {
        try {
            this.setState('connecting');

            // Get local media if not already available
            if (!this.localStream) {
                try {
                    await this.getLocalStream(audioOnly ? { video: false } : {});
                } catch (mediaError) {
                    console.warn('📹 Could not get media stream, proceeding without:', mediaError.message);
                    // Continue without local stream for testing purposes
                }
            }

            // Disable video if audio only
            if (audioOnly && this.localStream) {
                this.localStream.getVideoTracks().forEach(track => {
                    track.enabled = false;
                });
                this.isVideoOff = true;
            }

            // Create call session - this is the important part
            await this.createCall();
            this.setState('ringing');

            // Setup peer connection (will work even without local stream)
            await this.setupPeerConnection();

            // Start polling for signals
            this.startSignalPolling();

        } catch (error) {
            console.error('📹 Start call error:', error);
            this.setState('error', { error });
            if (this.onError) {
                this.onError(error);
            }
            throw error;
        }
    }

    /**
     * End the current call
     * Requirements: 6.7 - Record session duration
     * @param {string} reason - Reason for ending
     * @param {string} notes - Optional consultation notes
     */
    async endCall(reason = 'สิ้นสุดการโทร', notes = null) {
        console.log('📹 Ending call:', reason);

        const wasCallId = this.currentCallId;

        // Send hangup signal to other party first (unless they hung up)
        if (wasCallId && reason !== 'เภสัชกรวางสายแล้ว') {
            try {
                await fetch(this.getApiUrl(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'signal',
                        call_id: wasCallId,
                        signal_type: 'hangup',
                        signal_data: { reason: reason },
                        from: 'customer'
                    })
                });
            } catch (error) {
                console.error('📹 Failed to send hangup signal:', error);
            }
        }

        // Stop polling
        if (this.signalPollInterval) {
            clearInterval(this.signalPollInterval);
            this.signalPollInterval = null;
        }

        // Stop timer
        this.stopCallTimer();

        // Close peer connection
        if (this.peerConnection) {
            this.peerConnection.close();
            this.peerConnection = null;
        }

        // Notify server with duration and optional notes
        if (wasCallId) {
            try {
                const payload = {
                    action: 'end',
                    call_id: wasCallId,
                    duration: this.callDuration
                };

                if (notes) {
                    payload.notes = notes;
                }

                await fetch(this.getApiUrl(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } catch (error) {
                console.error('📹 End call API error:', error);
            }
        }

        const duration = this.callDuration;
        const callId = wasCallId;

        // Reset state
        this.currentCallId = null;
        this.roomId = null;
        this.answerReceived = false;
        this.pendingIceCandidates = [];
        this.remoteStream = null;

        this.setState('ended', { reason, duration });

        if (this.onCallEnded) {
            this.onCallEnded({ reason, duration, callId });
        }
    }

    /**
     * Save consultation notes
     * Requirements: 6.7 - Save consultation notes
     * @param {string} notes - Consultation notes
     * @returns {Promise<Object>} - API response
     */
    async saveNotes(notes) {
        if (!this.currentCallId) {
            console.warn('📹 No active call to save notes');
            return { success: false, error: 'No active call' };
        }

        try {
            const response = await fetch(this.getApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_notes',
                    call_id: this.currentCallId,
                    notes: notes
                })
            });

            const result = await response.json();
            console.log('📹 Notes saved:', result);
            return result;
        } catch (error) {
            console.error('📹 Save notes error:', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Get call summary
     * Requirements: 6.7 - Display consultation summary
     * @param {string} callId - Call ID (optional, uses current call if not provided)
     * @returns {Promise<Object>} - Call summary
     */
    async getCallSummary(callId = null) {
        const id = callId || this.currentCallId;

        if (!id) {
            return { success: false, error: 'No call ID' };
        }

        try {
            const response = await fetch(`${this.getApiUrl()}?action=get_summary&call_id=${id}`);
            const result = await response.json();
            return result;
        } catch (error) {
            console.error('📹 Get summary error:', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Handle connection failure
     */
    handleConnectionFailed() {
        console.log('📹 Connection failed');
        this.endCall('เชื่อมต่อล้มเหลว');
    }

    /**
     * Toggle mute state
     * Requirements: 6.4 - Display Mute button with clear icon
     * @returns {boolean} - New mute state
     */
    toggleMute() {
        this.isMuted = !this.isMuted;

        if (this.localStream) {
            this.localStream.getAudioTracks().forEach(track => {
                track.enabled = !this.isMuted;
            });
        }

        console.log('📹 Mute:', this.isMuted);
        this.notifyControlsUpdate();
        return this.isMuted;
    }

    /**
     * Toggle video state
     * Requirements: 6.4 - Display Camera toggle button with clear icon
     * @returns {boolean} - New video off state
     */
    toggleVideo() {
        this.isVideoOff = !this.isVideoOff;

        if (this.localStream) {
            this.localStream.getVideoTracks().forEach(track => {
                track.enabled = !this.isVideoOff;
            });
        }

        console.log('📹 Video off:', this.isVideoOff);
        this.notifyControlsUpdate();
        return this.isVideoOff;
    }

    /**
     * Toggle speaker (for mobile devices)
     * @returns {boolean} - New speaker state
     */
    toggleSpeaker() {
        this.isSpeakerOn = !this.isSpeakerOn;

        // On mobile, we can try to switch audio output
        // This is limited by browser support
        const remoteVideo = document.getElementById('vc-remote-video');
        if (remoteVideo && 'setSinkId' in remoteVideo) {
            // setSinkId is not widely supported, but we can try
            console.log('📹 Speaker toggle attempted (limited support)');
        }

        console.log('📹 Speaker:', this.isSpeakerOn);
        this.notifyControlsUpdate();
        return this.isSpeakerOn;
    }

    /**
     * Switch camera (front/back)
     * @returns {Promise<void>}
     */
    async switchCamera() {
        this.facingMode = this.facingMode === 'user' ? 'environment' : 'user';

        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: this.facingMode,
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: true
            });

            const newVideoTrack = newStream.getVideoTracks()[0];

            // Replace track in local stream
            if (this.localStream) {
                const oldVideoTrack = this.localStream.getVideoTracks()[0];
                if (oldVideoTrack) {
                    oldVideoTrack.stop();
                    this.localStream.removeTrack(oldVideoTrack);
                }
                this.localStream.addTrack(newVideoTrack);
            }

            // Replace track in peer connection
            if (this.peerConnection) {
                const sender = this.peerConnection.getSenders().find(s => s.track?.kind === 'video');
                if (sender) {
                    await sender.replaceTrack(newVideoTrack);
                }
            }

            console.log('📹 Camera switched to:', this.facingMode);
            this.notifyControlsUpdate();
            return this.localStream;

        } catch (error) {
            console.error('📹 Switch camera error:', error);
            // Revert facing mode
            this.facingMode = this.facingMode === 'user' ? 'environment' : 'user';
            throw error;
        }
    }

    /**
     * Start call duration timer
     */
    startCallTimer() {
        if (this.callTimer) return;

        this.callStartTime = Date.now();
        this.callDuration = 0;

        this.callTimer = setInterval(() => {
            this.callDuration = Math.floor((Date.now() - this.callStartTime) / 1000);
        }, 1000);
    }

    /**
     * Stop call duration timer
     */
    stopCallTimer() {
        if (this.callTimer) {
            clearInterval(this.callTimer);
            this.callTimer = null;
        }
    }

    /**
     * Format duration as MM:SS
     * @param {number} seconds - Duration in seconds
     * @returns {string} - Formatted duration
     */
    formatDuration(seconds) {
        const mins = Math.floor(seconds / 60).toString().padStart(2, '0');
        const secs = (seconds % 60).toString().padStart(2, '0');
        return `${mins}:${secs}`;
    }

    /**
     * Stop all media tracks and cleanup
     */
    cleanup() {
        console.log('📹 Cleaning up...');

        // Stop polling
        if (this.signalPollInterval) {
            clearInterval(this.signalPollInterval);
            this.signalPollInterval = null;
        }

        // Stop timer
        this.stopCallTimer();

        // Stop local stream
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => track.stop());
            this.localStream = null;
        }

        // Close peer connection
        if (this.peerConnection) {
            this.peerConnection.close();
            this.peerConnection = null;
        }

        // Reset state
        this.remoteStream = null;
        this.currentCallId = null;
        this.roomId = null;
        this.answerReceived = false;
        this.pendingIceCandidates = [];
        this.callState = 'idle';
        this.isMuted = false;
        this.isVideoOff = false;
    }

    /**
     * Get current call state
     * @returns {Object} - Current state info
     */
    getState() {
        return {
            callState: this.callState,
            callId: this.currentCallId,
            roomId: this.roomId,
            duration: this.callDuration,
            isMuted: this.isMuted,
            isVideoOff: this.isVideoOff,
            hasLocalStream: !!this.localStream,
            hasRemoteStream: !!this.remoteStream
        };
    }
}

// Create global instance
window.VideoCallManager = VideoCallManager;
window.videoCallManager = new VideoCallManager();
