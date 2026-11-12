// www/voice.js
// Cordova voice chat client — connects to your WS bridge

const VOICE_CONFIG = {
    wsHost: 'wno-host.ddns.net',   // your laptop's LAN IP
    wsPort: 27016,
    roomId: 1,
    clientId: Math.floor(Math.random() * 60000) + 1
};

let voiceWs = null;
let voiceAudioCtx = null;
let voiceMicStream = null;
let voiceProcessor = null;
let voiceTalking = false;
let voiceSeq = 0;
let voicePlaybackTime = 0;
let voiceConnected = false;

// ============================================================
// PERMISSION REQUEST
// ============================================================
function requestMicPermission() {
    return new Promise((resolve, reject) => {
        if (typeof cordova === 'undefined' || !cordova.plugins.permissions) {
            resolve();  // not in Cordova, try browser
            return;
        }
        const permissions = cordova.plugins.permissions;
        permissions.checkPermission(permissions.RECORD_AUDIO, (status) => {
            if (status.hasPermission) {
                resolve();
            } else {
                permissions.requestPermission(permissions.RECORD_AUDIO, (s) => {
                    if (s.hasPermission) resolve();
                    else reject(new Error('Mic permission denied'));
                }, reject);
            }
        }, reject);
    });
}

// ============================================================
// AUDIO CONTEXT
// ============================================================
async function initVoiceAudio() {
    if (voiceAudioCtx) return;
    voiceAudioCtx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 16000 });
    if (voiceAudioCtx.state === 'suspended') await voiceAudioCtx.resume();

    // silent buffer to unlock audio
    const buf = voiceAudioCtx.createBuffer(1, 1, 16000);
    const src = voiceAudioCtx.createBufferSource();
    src.buffer = buf;
    src.connect(voiceAudioCtx.destination);
    src.start(0);
}

// ============================================================
// MIC SETUP
// ============================================================
async function setupVoiceMic() {
    await requestMicPermission();
    try {
        voiceMicStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                channelCount: 1,
                sampleRate: 16000
            }
        });
    } catch (e) {
        voiceLog('❌ Mic denied: ' + e.message);
        return false;
    }

    const source = voiceAudioCtx.createMediaStreamSource(voiceMicStream);
    voiceProcessor = voiceAudioCtx.createScriptProcessor(1024, 1, 1);

    voiceProcessor.onaudioprocess = (e) => {
        if (!voiceTalking) return;
        if (!voiceWs || voiceWs.readyState !== WebSocket.OPEN) return;

        const input = e.inputBuffer.getChannelData(0);
        const pcm16 = new Int16Array(input.length);
        for (let i = 0; i < input.length; i++) {
            const s = Math.max(-1, Math.min(1, input[i]));
            pcm16[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
        }

        const headerLen = 7;
        const packet = new Uint8Array(headerLen + pcm16.length * 2);
        const dv = new DataView(packet.buffer);
        packet[0] = VOICE_CONFIG.roomId;
        dv.setUint16(1, VOICE_CONFIG.clientId, false);
        dv.setUint32(3, voiceSeq++, false);

        const outView = new DataView(packet.buffer, headerLen);
        for (let i = 0; i < pcm16.length; i++) {
            outView.setInt16(i * 2, pcm16[i], true);
        }
        try { voiceWs.send(packet.buffer); } catch (err) {}
    };

    source.connect(voiceProcessor);
    const muteGain = voiceAudioCtx.createGain();
    muteGain.gain.value = 0;
    voiceProcessor.connect(muteGain);
    muteGain.connect(voiceAudioCtx.destination);
    return true;
}

// ============================================================
// PLAYBACK
// ============================================================
function handleVoiceAudio(data) {
    if (!voiceAudioCtx) return;
    const buf = new Uint8Array(data);
    if (buf.length < 7) return;

    const dv = new DataView(buf.buffer);
    const senderId = dv.getUint16(1, false);
    if (senderId === VOICE_CONFIG.clientId) return;

    const payloadLen = buf.length - 7;
    if (payloadLen < 2) return;
    const sampleCount = payloadLen >> 1;
    const pcmView = new DataView(buf.buffer, 7);
    const f32 = new Float32Array(sampleCount);
    for (let i = 0; i < sampleCount; i++) {
        f32[i] = pcmView.getInt16(i * 2, true) / 32768;
    }

    const audioBuffer = voiceAudioCtx.createBuffer(1, sampleCount, 16000);
    audioBuffer.getChannelData(0).set(f32);

    const src = voiceAudioCtx.createBufferSource();
    src.buffer = audioBuffer;
    src.connect(voiceAudioCtx.destination);

    const now = voiceAudioCtx.currentTime;
    if (voicePlaybackTime < now + 0.01) voicePlaybackTime = now + 0.05;
    src.start(voicePlaybackTime);
    voicePlaybackTime += audioBuffer.duration;
}

// ============================================================
// CONNECT
// ============================================================
function connectVoice() {
    if (voiceWs && voiceWs.readyState === WebSocket.OPEN) return;

    const url = `ws://${VOICE_CONFIG.wsHost}:${VOICE_CONFIG.wsPort}`;
    voiceLog('Connecting to ' + url);

    voiceWs = new WebSocket(url);
    voiceWs.binaryType = 'arraybuffer';

    voiceWs.onopen = async () => {
        const join = new Uint8Array(6);
        join[0] = 0xFF;
        new DataView(join.buffer).setUint32(1, VOICE_CONFIG.clientId, false);
        join[5] = VOICE_CONFIG.roomId;
        voiceWs.send(join.buffer);

        voiceConnected = true;
        voiceLog('✅ Connected · ID ' + VOICE_CONFIG.clientId);

        await initVoiceAudio();
        await setupVoiceMic();

        setInterval(() => {
            if (voiceWs.readyState !== WebSocket.OPEN) return;
            const hb = new Uint8Array(7);
            hb[0] = VOICE_CONFIG.roomId;
            new DataView(hb.buffer).setUint16(1, VOICE_CONFIG.clientId, false);
            new DataView(hb.buffer).setUint32(3, voiceSeq++, false);
            voiceWs.send(hb.buffer);
        }, 5000);
    };

    voiceWs.onmessage = (ev) => {
        if (ev.data instanceof ArrayBuffer) handleVoiceAudio(ev.data);
    };

    voiceWs.onclose = () => {
        voiceConnected = false;
        voiceLog('❌ Disconnected');
        setTimeout(connectVoice, 2000);
    };

    voiceWs.onerror = (e) => {
        voiceLog('❌ WS error');
        console.error(e);
    };
}

// ============================================================
// PTT
// ============================================================
async function startVoiceTalk() {
    if (!voiceMicStream) {
        await initVoiceAudio();
        const ok = await setupVoiceMic();
        if (!ok) return;
    }
    if (voiceAudioCtx.state === 'suspended') await voiceAudioCtx.resume();
    voiceTalking = true;
    const btn = document.getElementById('voicePttBtn');
    if (btn) { btn.classList.add('talking'); btn.textContent = '🎤 TALKING'; }
}

function stopVoiceTalk() {
    voiceTalking = false;
    const btn = document.getElementById('voicePttBtn');
    if (btn) { btn.classList.remove('talking'); btn.textContent = 'HOLD TO TALK'; }
}

// ============================================================
// LOGGING
// ============================================================
function voiceLog(msg) {
    console.log('[VOICE] ' + msg);
    const el = document.getElementById('voiceStatus');
    if (el) el.textContent = msg;
}