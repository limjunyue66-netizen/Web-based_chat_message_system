(function () {
    if (!window.WC_BOOT || !WC_BOOT.me) {
        return;
    }

    const overlay = document.getElementById('call-overlay');
    const remoteVideo = document.getElementById('remote-video');
    const localVideo = document.getElementById('local-video');
    const remoteAudio = document.getElementById('remote-audio');
    const callName = document.getElementById('call-name');
    const callStatus = document.getElementById('call-status');
    const callTimer = document.getElementById('call-timer');
    const callAvatar = document.getElementById('call-avatar');
    const muteBtn = document.getElementById('call-mute');
    const deafBtn = document.getElementById('call-deaf');
    const cameraBtn = document.getElementById('call-camera');
    const acceptBtn = document.getElementById('call-accept');
    const endBtn = document.getElementById('call-end');
    if (!overlay || !remoteVideo || !localVideo || !remoteAudio || !muteBtn || !cameraBtn) {
        return;
    }

    const Call = {
        pc: null,
        localStream: null,
        remoteStream: null,
        session: null,
        type: 'voice',
        role: null,
        lastSignalId: 0,
        timer: null,
        incomingTimer: null,
        durationTimer: null,
        connected: false,
        accepted: false,
        iceQueue: [],
        remoteReady: false,
        ending: false,
        ringTimer: null,
        audioCtx: null,
        micMuted: false,
        peerMicMuted: false,
        cameraOff: false,
        forcedMute: false
    };

    function iceServers() {
        const configured = Array.isArray(WC_BOOT.iceServers) ? WC_BOOT.iceServers : [];
        return { iceServers: configured.length ? configured : [{ urls: 'stun:stun.l.google.com:19302' }] };
    }

    function setStatus(text) {
        callStatus.textContent = text;
    }

    function resetControlUi() {
        Call.micMuted = false;
        Call.peerMicMuted = false;
        Call.cameraOff = false;
        Call.forcedMute = false;
        muteBtn.setAttribute('aria-pressed', 'false');
        muteBtn.innerHTML = '<i class="fa-solid fa-microphone"></i>';
        muteBtn.title = 'Mute my microphone';
        muteBtn.disabled = false;
        if (deafBtn) {
            deafBtn.setAttribute('aria-pressed', 'false');
            deafBtn.innerHTML = '<i class="fa-solid fa-microphone-lines-slash"></i>';
            deafBtn.title = 'Mute their microphone';
            deafBtn.classList.add('d-none');
        }
        cameraBtn.setAttribute('aria-pressed', 'false');
        cameraBtn.innerHTML = '<i class="fa-solid fa-video"></i>';
        cameraBtn.title = 'Turn camera off';
        localVideo.classList.remove('is-camera-off');
        remoteAudio.muted = false;
        remoteVideo.muted = false;
    }

    function showPeer(peer, type) {
        Call.type = type || Call.type;
        callName.textContent = peer ? peer.username : 'Call';
        callAvatar.src = peer ? peer.avatar_url : '';
        callAvatar.alt = peer ? peer.username : '';
        cameraBtn.classList.toggle('d-none', Call.type !== 'video');
        if (Call.type === 'video' && Call.localStream && !Call.cameraOff) {
            localVideo.classList.remove('d-none');
        } else if (Call.type !== 'video') {
            localVideo.classList.add('d-none');
        }
        overlay.classList.remove('d-none');
    }

    function syncInCallControls() {
        if (deafBtn) {
            deafBtn.classList.toggle('d-none', !Call.accepted && Call.role === 'callee');
            if (Call.accepted || Call.role === 'caller') {
                deafBtn.classList.remove('d-none');
            }
        }
    }

    function mediaError(error) {
        const host = location.hostname;
        const local = host === 'localhost' || host === '127.0.0.1';
        if (location.protocol !== 'https:' && !local) {
            return 'Calls need HTTPS when the site is not opened from localhost. On cPanel, enable AutoSSL and open the https:// URL.';
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return 'This browser cannot access the microphone or camera.';
        }
        if (error && error.name === 'NotAllowedError') {
            return 'Permission was denied. Allow the microphone or camera and try again.';
        }
        if (error && (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError')) {
            return 'No microphone or camera was found on this device.';
        }
        if (error && (error.name === 'NotReadableError' || error.name === 'TrackStartError')) {
            return 'The microphone or camera is busy or unavailable. Close other apps using it and try again.';
        }
        if (error && error.name === 'SecurityError') {
            return 'The browser blocked media access. Use HTTPS and allow microphone/camera permission.';
        }
        return 'Unable to access the microphone or camera.';
    }

    async function getMedia(type) {
        const constraints = type === 'video'
            ? { audio: true, video: { facingMode: 'user' } }
            : { audio: true, video: false };
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        Call.localStream = stream;
        if (Call.micMuted || Call.forcedMute) {
            stream.getAudioTracks().forEach((track) => { track.enabled = false; });
        }
        if (type === 'video') {
            if (Call.cameraOff) {
                stream.getVideoTracks().forEach((track) => { track.enabled = false; });
                localVideo.classList.add('d-none');
            } else {
                localVideo.srcObject = stream;
                localVideo.classList.remove('d-none');
            }
        }
        return stream;
    }

    function stopStream(stream) {
        if (!stream) {
            return;
        }
        stream.getTracks().forEach((track) => track.stop());
    }

    function ensureAudioContext() {
        const Context = window.AudioContext || window.webkitAudioContext;
        if (!Context) {
            return null;
        }
        if (!Call.audioCtx || Call.audioCtx.state === 'closed') {
            Call.audioCtx = new Context();
        }
        if (Call.audioCtx.state === 'suspended') {
            Call.audioCtx.resume().catch(() => {});
        }
        return Call.audioCtx;
    }

    function tone(ctx, frequency, start, duration, volume) {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(volume, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
        oscillator.connect(gain).connect(ctx.destination);
        oscillator.start(start);
        oscillator.stop(start + duration + 0.02);
    }

    function playRingBurst(mode) {
        const ctx = ensureAudioContext();
        if (!ctx) {
            return;
        }
        const now = ctx.currentTime;
        if (mode === 'incoming') {
            tone(ctx, 440, now, 0.18, 0.12);
            tone(ctx, 480, now, 0.18, 0.1);
            tone(ctx, 440, now + 0.22, 0.18, 0.12);
            tone(ctx, 480, now + 0.22, 0.18, 0.1);
        } else {
            tone(ctx, 425, now, 0.35, 0.045);
            tone(ctx, 425, now + 0.45, 0.35, 0.045);
        }
    }

    function startRing(mode) {
        stopRing(false);
        ensureAudioContext();
        playRingBurst(mode);
        Call.ringTimer = setInterval(function () {
            playRingBurst(mode);
        }, mode === 'incoming' ? 2000 : 3200);
    }

    function stopRing(closeContext) {
        if (Call.ringTimer) {
            clearInterval(Call.ringTimer);
            Call.ringTimer = null;
        }
        if (closeContext !== false && Call.audioCtx) {
            Call.audioCtx.close().catch(() => {});
            Call.audioCtx = null;
        }
    }

    function sendControl(action, extra) {
        if (!Call.session) {
            return Promise.resolve();
        }
        const payload = Object.assign({ action: action }, extra || {});
        return WC.api('api/calls.php?action=signal', {
            method: 'POST',
            json: { session_id: Call.session.id, signal_type: 'control', payload: payload }
        }).catch(() => {});
    }

    function setLocalMicMuted(muted, forced) {
        Call.micMuted = !!muted;
        if (forced) {
            Call.forcedMute = !!muted;
        }
        if (Call.localStream) {
            Call.localStream.getAudioTracks().forEach((track) => {
                track.enabled = !Call.micMuted && !Call.forcedMute;
            });
        }
        const pressed = Call.micMuted || Call.forcedMute;
        muteBtn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        muteBtn.innerHTML = pressed
            ? '<i class="fa-solid fa-microphone-slash"></i>'
            : '<i class="fa-solid fa-microphone"></i>';
        muteBtn.title = Call.forcedMute
            ? 'Microphone muted by the other person'
            : (Call.micMuted ? 'Unmute my microphone' : 'Mute my microphone');
        muteBtn.disabled = Call.forcedMute && !Call.micMuted ? false : false;
        if (Call.forcedMute) {
            muteBtn.disabled = true;
        } else {
            muteBtn.disabled = false;
        }
    }

    function setPeerMicMuted(muted) {
        Call.peerMicMuted = !!muted;
        if (deafBtn) {
            deafBtn.setAttribute('aria-pressed', Call.peerMicMuted ? 'true' : 'false');
            deafBtn.title = Call.peerMicMuted ? 'Unmute their microphone' : 'Mute their microphone';
            deafBtn.innerHTML = Call.peerMicMuted
                ? '<i class="fa-solid fa-microphone-slash"></i>'
                : '<i class="fa-solid fa-microphone-lines-slash"></i>';
        }
    }

    function setCameraOff(off) {
        Call.cameraOff = !!off;
        if (Call.localStream) {
            Call.localStream.getVideoTracks().forEach((track) => {
                track.enabled = !Call.cameraOff;
            });
        }
        cameraBtn.setAttribute('aria-pressed', Call.cameraOff ? 'true' : 'false');
        cameraBtn.innerHTML = Call.cameraOff
            ? '<i class="fa-solid fa-video-slash"></i>'
            : '<i class="fa-solid fa-video"></i>';
        cameraBtn.title = Call.cameraOff ? 'Turn camera on' : 'Turn camera off';
        if (Call.type === 'video') {
            if (Call.cameraOff) {
                localVideo.classList.add('d-none');
                localVideo.classList.add('is-camera-off');
            } else if (Call.localStream) {
                localVideo.srcObject = Call.localStream;
                localVideo.classList.remove('d-none');
                localVideo.classList.remove('is-camera-off');
            }
        }
    }

    function ensurePeer() {
        if (Call.pc) {
            return Call.pc;
        }
        const pc = new RTCPeerConnection(iceServers());
        Call.pc = pc;
        (Call.localStream ? Call.localStream.getTracks() : []).forEach((track) => pc.addTrack(track, Call.localStream));
        pc.onicecandidate = (event) => {
            if (!event.candidate || !Call.session) {
                return;
            }
            WC.api('api/calls.php?action=signal', {
                method: 'POST',
                json: { session_id: Call.session.id, signal_type: 'ice', payload: event.candidate.toJSON() }
            }).catch(() => {});
        };
        pc.ontrack = (event) => {
            const stream = event.streams[0] || Call.remoteStream || new MediaStream();
            if (!event.streams[0]) {
                stream.addTrack(event.track);
            }
            Call.remoteStream = stream;
            if (Call.type === 'video') {
                remoteVideo.srcObject = stream;
                overlay.classList.add('has-video');
            } else {
                remoteAudio.srcObject = stream;
            }
        };
        pc.onconnectionstatechange = () => {
            const state = pc.connectionState;
            if (state === 'connected') {
                Call.connected = true;
                stopRing();
                setStatus(Call.type === 'video' ? 'Video call' : 'Voice call');
                startDuration(Call.session && Call.session.elapsed_seconds);
                syncInCallControls();
            } else if (state === 'failed') {
                setStatus('Unable to establish the call. Your network may require a TURN server.');
            } else if (state === 'disconnected') {
                setStatus('Reconnecting...');
            } else if (state === 'connecting' && !Call.connected) {
                setStatus('Connecting...');
            }
        };
        return pc;
    }

    async function flushIce() {
        if (!Call.pc || !Call.remoteReady) {
            return;
        }
        const queued = Call.iceQueue.splice(0);
        for (const candidate of queued) {
            try {
                await Call.pc.addIceCandidate(new RTCIceCandidate(candidate));
            } catch (error) {
                /* A late candidate can fail after the call ends. */
            }
        }
    }

    async function handleControl(signal) {
        const payload = signal.payload || {};
        const action = payload.action || '';
        if (action === 'force_mute') {
            setLocalMicMuted(true, true);
            WC.toast('Your microphone was muted by the other person.');
            setStatus('Microphone muted by peer');
        } else if (action === 'force_unmute') {
            Call.forcedMute = false;
            setLocalMicMuted(Call.micMuted, false);
            WC.toast('Your microphone was unmuted.');
        } else if (action === 'camera_off') {
            /* Peer turned their camera off — keep the call alive. */
            setStatus('Their camera is off');
        } else if (action === 'camera_on') {
            setStatus(Call.connected ? 'Connected' : callStatus.textContent);
        }
    }

    async function handleSignal(signal) {
        if (signal.signal_type === 'hangup') {
            return;
        }
        if (signal.signal_type === 'control') {
            await handleControl(signal);
            return;
        }
        if (!Call.pc) {
            return;
        }
        if (signal.signal_type === 'offer' && Call.role === 'callee') {
            await Call.pc.setRemoteDescription(new RTCSessionDescription(signal.payload));
            Call.remoteReady = true;
            await flushIce();
            const answer = await Call.pc.createAnswer();
            await Call.pc.setLocalDescription(answer);
            await WC.api('api/calls.php?action=signal', {
                method: 'POST',
                json: { session_id: Call.session.id, signal_type: 'answer', payload: answer }
            });
            setStatus('Connecting...');
        } else if (signal.signal_type === 'answer' && Call.role === 'caller') {
            await Call.pc.setRemoteDescription(new RTCSessionDescription(signal.payload));
            Call.remoteReady = true;
            await flushIce();
            if (!Call.connected) {
                setStatus('Connecting...');
            }
        } else if (signal.signal_type === 'ice') {
            if (!Call.remoteReady) {
                Call.iceQueue.push(signal.payload);
            } else {
                await Call.pc.addIceCandidate(new RTCIceCandidate(signal.payload));
            }
        }
    }

    function hideTimer() {
        if (!callTimer) {
            return;
        }
        callTimer.textContent = '0:00';
        callTimer.classList.add('d-none');
    }

    function paintTimer(totalSeconds) {
        if (!callTimer) {
            return;
        }
        const total = Math.max(0, totalSeconds);
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const seconds = String(total % 60).padStart(2, '0');
        callTimer.textContent = hours > 0
            ? hours + ':' + String(minutes).padStart(2, '0') + ':' + seconds
            : minutes + ':' + seconds;
        callTimer.classList.remove('d-none');
    }

    function startDuration(elapsedSeconds) {
        if (Call.durationTimer || !callTimer) {
            return;
        }
        const origin = Date.now() - Math.max(0, Number(elapsedSeconds) || 0) * 1000;
        const paint = () => {
            paintTimer(Math.floor((Date.now() - origin) / 1000));
        };
        paint();
        Call.durationTimer = setInterval(paint, 1000);
    }

    function stopTimers() {
        if (Call.timer) {
            clearInterval(Call.timer);
            Call.timer = null;
        }
        if (Call.durationTimer) {
            clearInterval(Call.durationTimer);
            Call.durationTimer = null;
        }
    }

    async function poll() {
        if (!Call.session) {
            return;
        }
        try {
            const result = await WC.api('api/calls.php', {
                query: { action: 'poll', session_id: Call.session.id, after_id: Call.lastSignalId }
            });
            const session = result.data.session;
            Call.session = session;
            if (session.status === 'accepted') {
                stopRing(false);
                Call.accepted = true;
                if (!Call.durationTimer) {
                    setStatus(session.call_type === 'video' ? 'Video call' : 'Voice call');
                }
                startDuration(session.elapsed_seconds);
            }
            (result.data.signals || []).forEach((signal) => {
                Call.lastSignalId = Math.max(Call.lastSignalId, Number(signal.id));
                handleSignal(signal).catch(() => setStatus('Call failed.'));
            });
            if (['rejected', 'missed', 'cancelled', 'ended'].includes(session.status)) {
                const labels = {
                    rejected: 'Call declined',
                    missed: 'No answer',
                    cancelled: 'Call ended',
                    ended: 'Call ended'
                };
                setStatus(labels[session.status] || 'Call ended');
                await cleanup(true);
            }
        } catch (error) {
            if (error.status === 404) {
                setStatus('Call ended');
                await cleanup(true);
            }
        }
    }

    function startPolling() {
        if (Call.timer) {
            clearInterval(Call.timer);
        }
        poll();
        Call.timer = setInterval(poll, 1000);
    }

    async function cleanup(localOnly) {
        if (Call.ending) {
            return;
        }
        Call.ending = true;
        stopRing();
        stopTimers();
        hideTimer();
        if (Call.pc) {
            Call.pc.onicecandidate = null;
            Call.pc.ontrack = null;
            Call.pc.close();
        }
        stopStream(Call.localStream);
        remoteVideo.srcObject = null;
        remoteAudio.srcObject = null;
        localVideo.srcObject = null;
        const status = callStatus.textContent;
        Call.pc = null;
        Call.localStream = null;
        Call.remoteStream = null;
        Call.session = null;
        Call.connected = false;
        Call.accepted = false;
        Call.remoteReady = false;
        Call.iceQueue = [];
        Call.lastSignalId = 0;
        Call.ending = false;
        resetControlUi();
        overlay.classList.remove('has-video');
        acceptBtn.classList.add('d-none');
        setTimeout(() => {
            if (!Call.session) {
                overlay.classList.add('d-none');
                setStatus(status);
            }
        }, localOnly ? 900 : 200);
        watchIncoming();
    }

    async function finish(action) {
        const sessionId = Call.session ? Call.session.id : 0;
        if (sessionId) {
            try {
                await WC.api('api/calls.php?action=' + action, { method: 'POST', json: { session_id: sessionId } });
            } catch (error) {
                /* The local media still needs to stop if the network request fails. */
            }
        }
        setStatus(action === 'reject' ? 'Call declined' : 'Call ended');
        await cleanup(true);
    }

    async function start(userId, type) {
        if (Call.session) {
            WC.toast('You are already in a call.');
            return;
        }
        if (!navigator.mediaDevices || !window.RTCPeerConnection) {
            WC.toast('This browser does not support WebRTC calls.');
            return;
        }
        resetControlUi();
        Call.role = 'caller';
        Call.type = type;
        Call.accepted = true;
        showPeer({ username: 'Calling...', avatar_url: '' }, type);
        hideTimer();
        setStatus('Calling...');
        acceptBtn.classList.add('d-none');
        syncInCallControls();
        startRing('outgoing');
        try {
            await getMedia(type);
            const result = await WC.api('api/calls.php?action=start', {
                method: 'POST',
                json: { callee_id: userId, call_type: type }
            });
            Call.session = result.data.session;
            showPeer(Call.session.peer, Call.session.call_type);
            setStatus('Ringing...');
            ensurePeer();
            const offer = await Call.pc.createOffer();
            await Call.pc.setLocalDescription(offer);
            await WC.api('api/calls.php?action=signal', {
                method: 'POST',
                json: { session_id: Call.session.id, signal_type: 'offer', payload: Call.pc.localDescription }
            });
            startPolling();
        } catch (error) {
            stopRing();
            setStatus(error.name ? mediaError(error) : error.message);
            WC.toast(error.name ? mediaError(error) : error.message);
            if (Call.session) {
                await finish('cancel');
            } else {
                await cleanup(true);
            }
        }
    }

    async function acceptIncoming() {
        if (!Call.session) {
            return;
        }
        stopRing();
        Call.accepted = true;
        acceptBtn.classList.add('d-none');
        setStatus('Connecting...');
        syncInCallControls();
        try {
            await getMedia(Call.type);
            await WC.api('api/calls.php?action=accept', { method: 'POST', json: { session_id: Call.session.id } });
            ensurePeer();
            startPolling();
        } catch (error) {
            setStatus(error.name ? mediaError(error) : error.message);
            await finish('reject');
        }
    }

    function showIncoming(call) {
        if (Call.session) {
            return;
        }
        resetControlUi();
        Call.session = call;
        Call.role = 'callee';
        Call.type = call.call_type;
        Call.accepted = false;
        showPeer(call.peer, call.call_type);
        hideTimer();
        setStatus(call.call_type === 'video' ? 'Incoming video call' : 'Incoming voice call');
        acceptBtn.classList.remove('d-none');
        if (deafBtn) {
            deafBtn.classList.add('d-none');
        }
        startRing('incoming');
        if (Call.incomingTimer) {
            clearInterval(Call.incomingTimer);
            Call.incomingTimer = null;
        }
        try {
            if (navigator.vibrate) {
                navigator.vibrate([200, 100, 200, 100, 200]);
            }
        } catch (error) {
            /* Vibration is optional. */
        }
    }

    async function watchIncoming() {
        if (Call.incomingTimer || Call.session) {
            return;
        }
        const check = async () => {
            if (Call.session) {
                return;
            }
            try {
                const result = await WC.api('api/calls.php', { query: { action: 'incoming' } });
                if (result.data.call) {
                    showIncoming(result.data.call);
                }
            } catch (error) {
                /* Ignore a single failed incoming-call check. */
            }
        };
        check();
        Call.incomingTimer = setInterval(check, 1500);
    }

    muteBtn.addEventListener('click', () => {
        if (Call.forcedMute) {
            WC.toast('The other person muted your microphone.');
            return;
        }
        if (!Call.localStream && !Call.session) {
            return;
        }
        setLocalMicMuted(!Call.micMuted, false);
    });

    if (deafBtn) {
        deafBtn.addEventListener('click', () => {
            if (!Call.session || (!Call.accepted && Call.role === 'callee')) {
                return;
            }
            const next = !Call.peerMicMuted;
            setPeerMicMuted(next);
            sendControl(next ? 'force_mute' : 'force_unmute');
            WC.toast(next ? 'Their microphone is muted.' : 'Their microphone is unmuted.');
        });
    }

    cameraBtn.addEventListener('click', () => {
        if (Call.type !== 'video') {
            return;
        }
        if (!Call.localStream && !Call.session) {
            return;
        }
        const next = !Call.cameraOff;
        setCameraOff(next);
        sendControl(next ? 'camera_off' : 'camera_on');
        if (next) {
            WC.toast('Camera turned off. The call stays connected.');
        }
    });

    acceptBtn.addEventListener('click', () => {
        ensureAudioContext();
        acceptIncoming();
    });
    endBtn.addEventListener('click', () => {
        if (Call.role === 'callee' && Call.session && !Call.accepted) {
            finish('reject');
            return;
        }
        if (Call.role === 'caller' && Call.session && Call.session.status === 'ringing') {
            finish('cancel');
            return;
        }
        finish('end');
    });

    document.addEventListener('click', function unlockAudioOnce() {
        ensureAudioContext();
        document.removeEventListener('click', unlockAudioOnce);
    }, true);

    WC.calls = { start: start };
    watchIncoming();
})();
