<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Voice Relay Control</title>
    <style>
        body { font-family: system-ui; background: #111; color: #eee;
               max-width: 700px; margin: 30px auto; padding: 20px; }
        h1 { color: #4ade80; }
        .card { background: #1f1f1f; padding: 20px; border-radius: 8px; margin: 15px 0; }
        .row { display: flex; align-items: center; justify-content: space-between; margin: 10px 0; }
        button { font-size: 14px; padding: 10px 20px; border: none;
                 border-radius: 6px; cursor: pointer; font-weight: bold; margin-left: 5px; }
        .start { background: #22c55e; color: white; }
        .stop  { background: #ef4444; color: white; }
        .link  { background: #3b82f6; color: white; }
        button:disabled { opacity: 0.4; cursor: not-allowed; }
        .badge { padding: 3px 10px; border-radius: 4px; font-weight: bold; font-size: 13px; }
        .on  { background: #166534; color: #4ade80; }
        .off { background: #7f1d1d; color: #fca5a5; }
        pre { background: #0a0a0a; padding: 10px; border-radius: 4px;
              font-size: 12px; overflow-x: auto; max-height: 200px; }
        code { background: #333; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
    </style>
</head>
<body>

<h1>🎙️ Voice Relay Control</h1>

<div class="card">
    <h3>UDP Relay (port 27015)</h3>
    <div class="row">
        <span id="relayStatus">Loading...</span>
        <div>
            <button class="start" id="btnStartRelay" onclick="startRelay()">▶ Start</button>
            <button class="stop"  id="btnStopRelay"  onclick="stopRelay()">■ Stop</button>
        </div>
    </div>
</div>

<div class="card">
    <h3>WebSocket Bridge (port 27016)</h3>
    <div class="row">
        <span id="wsStatus">Loading...</span>
        <div>
            <button class="start" id="btnStartWs" onclick="startWs()">▶ Start</button>
            <button class="stop"  id="btnStopWs"  onclick="stopWs()">■ Stop</button>
        </div>
    </div>
</div>

<div class="card">
    <h3>Live Stats</h3>
    <pre id="stats">--</pre>
</div>

<div class="card" hidden>
    <h3>Open on your phone</h3>
    <p>Make sure phone is on same WiFi. Open in Chrome:</p>
    <p><code id="phoneUrl">--</code></p>
    <button class="link" onclick="copyUrl()">📋 Copy URL</button>
    <p style="font-size:12px;color:#888;margin-top:10px;">
        If mic is blocked, open <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>
        and add your IP.
    </p>
</div>

<script>
let serverIp = '';

async function refresh() {
    try {
        const r = await fetch('status.php?t=' + Date.now());
        const d = await r.json();

        document.getElementById('relayStatus').innerHTML = d.relay.running
            ? `<span class="badge on">RUNNING</span> PID ${d.relay.pid}`
            : `<span class="badge off">STOPPED</span>`;

        document.getElementById('wsStatus').innerHTML = d.wsbridge.running
            ? `<span class="badge on">RUNNING</span> PID ${d.wsbridge.pid}`
            : `<span class="badge off">STOPPED</span>`;

        document.getElementById('btnStartRelay').disabled = d.relay.running;
        document.getElementById('btnStopRelay').disabled  = !d.relay.running;
        document.getElementById('btnStartWs').disabled    = d.wsbridge.running;
        document.getElementById('btnStopWs').disabled     = !d.wsbridge.running;

        document.getElementById('stats').textContent =
            `Packets forwarded: ${d.packets}\nActive clients: ${d.clients}`;
    } catch (e) {}
}

async function startRelay() { await fetch('start_relay.php'); refresh(); }
async function stopRelay()  { await fetch('stop_relay.php');  refresh(); }
async function startWs()    { await fetch('start_ws.php');    refresh(); }
async function stopWs()     { await fetch('stop_ws.php');     refresh(); }

function copyUrl() {
    const el = document.getElementById('phoneUrl');
    navigator.clipboard.writeText(el.textContent);
}

// Determine server IP from the URL we're on
(function() {
    const host = window.location.hostname;
    const port = window.location.port ? ':' + window.location.port : '';
    serverIp = host;
    document.getElementById('phoneUrl').textContent =
        `http://${host}${port}/voice/client.html`;
})();

refresh();
setInterval(refresh, 2000);
</script>

</body>
</html>