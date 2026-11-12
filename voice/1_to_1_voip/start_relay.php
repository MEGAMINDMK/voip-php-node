<?php
header('Content-Type: application/json');

$pidFile  = __DIR__ . '/relay.pid';
$stopFile = __DIR__ . '/relay.stop';

if (file_exists($pidFile)) {
    $oldPid = (int)trim(file_get_contents($pidFile));
    if ($oldPid > 0) {
        $check = @shell_exec("tasklist /FI \"PID eq $oldPid\" 2>NUL");
        if (strpos($check, (string)$oldPid) !== false) {
            echo json_encode(['ok' => false, 'error' => 'Already running', 'pid' => $oldPid]);
            exit;
        }
    }
}
@unlink($pidFile);
@unlink($stopFile);

$php    = PHP_BINARY;
$worker = __DIR__ . '/relay_worker.php';
$log    = __DIR__ . '/relay.log';

$cmd = 'start /B "" ' . escapeshellarg($php) . ' '
     . escapeshellarg($worker) . ' > ' . escapeshellarg($log) . ' 2>&1';

pclose(popen($cmd, 'r'));
usleep(800000);

if (!file_exists($pidFile)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to start — check relay.log']);
    exit;
}

echo json_encode(['ok' => true, 'pid' => (int)trim(file_get_contents($pidFile))]);