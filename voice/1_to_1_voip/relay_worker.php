<?php
/**
 * relay_worker.php — UDP Voice Relay
 * Runs in background via start_relay.php
 */

set_time_limit(0);
error_reporting(0);
ini_set('display_errors', 0);

define('UDP_PORT', 27015);
define('WS_BRIDGE_UDP_PORT', 27017); // The WS bridge listens here for responses
define('MAX_PACKET', 2048);
define('CLIENT_TIMEOUT', 30);
define('CLEANUP_INTERVAL', 10);

$pidFile   = __DIR__ . '/relay.pid';
$stopFile  = __DIR__ . '/relay.stop';
$statsFile = __DIR__ . '/relay.stats';
$stateFile = __DIR__ . '/relay.state';

file_put_contents($pidFile, getmypid());
file_put_contents($stateFile, 'running');

register_shutdown_function(function() use ($pidFile, $stateFile) {
    @unlink($pidFile);
    @file_put_contents($stateFile, 'stopped');
});

$sock = @stream_socket_server(
    "udp://0.0.0.0:" . UDP_PORT,
    $errno,
    $errstr,
    STREAM_SERVER_BIND
);

if (!$sock) {
    file_put_contents($stateFile, 'error: ' . $errstr);
    exit(1);
}
stream_set_blocking($sock, false);

$clients     = [];
$lastCleanup = time();
$packetCount = 0;
$lastStats   = time();

while (true) {
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        break;
    }

    $read = [$sock];
    $write = null;
    $except = null;
    $n = @stream_select($read, $write, $except, 0, 100000);
    if ($n === false) continue;

    if ($n > 0) {
        $peer = '';
        $data = @stream_socket_recvfrom($sock, MAX_PACKET, 0, $peer);

        if ($data !== false && strlen($data) >= 1) {
            $packetCount++;
            $len  = strlen($data);
            $now  = time();
            $type = ord($data[0]);

            // JOIN: 0xFF + client_id(4) + room(1)
            if ($type === 0xFF && $len >= 6) {
                $clientId = unpack('N', substr($data, 1, 4))[1];
                $roomId   = ord($data[5]);
                $clients[$clientId] = ['addr' => $peer, 'room' => $roomId, 'last' => $now];
            }
            // LEAVE: 0xFE + client_id(4)
            elseif ($type === 0xFE && $len >= 5) {
                $clientId = unpack('N', substr($data, 1, 4))[1];
                unset($clients[$clientId]);
            }
            // AUDIO: room(1) + sender(2) + seq(4) + payload
            elseif ($len >= 7) {
                $roomId   = ord($data[0]);
                $senderId = unpack('n', substr($data, 1, 2))[1];
                if (isset($clients[$senderId])) {
                    $clients[$senderId]['last'] = $now;
                }

                // Send to the WS bridge's listening port (27017)
                // The WS bridge will then forward it to the WebSocket clients
                @stream_socket_sendto($sock, $data, 0, '127.0.0.1:' . WS_BRIDGE_UDP_PORT);
            }
        }
    }

    $now = time();
    if ($now - $lastCleanup >= CLEANUP_INTERVAL) {
        foreach ($clients as $cid => $c) {
            if ($now - $c['last'] > CLIENT_TIMEOUT) unset($clients[$cid]);
        }
        $lastCleanup = $now;
    }

    if ($now - $lastStats >= 3) {
        @file_put_contents($statsFile, json_encode([
            'packets' => $packetCount,
            'clients' => count($clients),
            'pid'     => getmypid(),
        ]));
        $lastStats = $now;
    }
}

@fclose($sock);