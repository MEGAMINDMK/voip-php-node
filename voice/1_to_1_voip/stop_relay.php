<?php
header('Content-Type: application/json');

$pidFile  = __DIR__ . '/relay.pid';
$stopFile = __DIR__ . '/relay.stop';

if (!file_exists($pidFile)) {
    echo json_encode(['ok' => false, 'error' => 'Not running']);
    exit;
}

@file_put_contents($stopFile, '1');
usleep(800000);

if (file_exists($pidFile)) {
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid > 0) @shell_exec("taskkill /F /PID $pid 2>NUL");
    @unlink($pidFile);
    @unlink($stopFile);
}

echo json_encode(['ok' => true]);