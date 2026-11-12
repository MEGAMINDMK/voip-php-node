<?php
header('Content-Type: application/json');

function isRunning($pidFile) {
    if (!file_exists($pidFile)) return [false, 0];
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid <= 0) return [false, 0];
    $check = @shell_exec("tasklist /FI \"PID eq $pid\" 2>NUL");
    return [strpos($check, (string)$pid) !== false, $pid];
}

list($relayRunning, $relayPid) = isRunning(__DIR__ . '/relay.pid');
list($wsRunning,    $wsPid)    = isRunning(__DIR__ . '/ws.pid');

$stats = ['packets' => 0, 'clients' => 0];
if (file_exists(__DIR__ . '/relay.stats')) {
    $d = json_decode(file_get_contents(__DIR__ . '/relay.stats'), true);
    if (is_array($d)) $stats = $d;
}

echo json_encode([
    'relay'    => ['running' => $relayRunning, 'pid' => $relayPid],
    'wsbridge' => ['running' => $wsRunning,    'pid' => $wsPid],
    'packets'  => $stats['packets'] ?? 0,
    'clients'  => $stats['clients'] ?? 0,
]);