<?php
/**
 * ws_relay.php — WebSocket ↔ UDP bridge (1-to-1 pairing)
 */

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

define('WS_PORT', 27016);
define('UDP_LISTEN_PORT', 27017);
define('UDP_RELAY_HOST', '127.0.0.1');
define('UDP_RELAY_PORT', 27015);

$pidFile   = __DIR__ . '/ws.pid';
$stopFile  = __DIR__ . '/ws.stop';
$stateFile = __DIR__ . '/ws.state';

file_put_contents($pidFile, getmypid());
file_put_contents($stateFile, 'running');

register_shutdown_function(function() use ($pidFile, $stateFile) {
    @unlink($pidFile);
    @file_put_contents($stateFile, 'stopped');
});

$tcp = @stream_socket_server("tcp://0.0.0.0:" . WS_PORT, $errno, $errstr);
if (!$tcp) { file_put_contents($stateFile, 'error: ' . $errstr); exit(1); }
stream_set_blocking($tcp, false);
echo "WS bridge on :" . WS_PORT . "\n";

$udp_listen = @stream_socket_server(
    "udp://0.0.0.0:" . UDP_LISTEN_PORT, $e, $es, STREAM_SERVER_BIND
);
if (!$udp_listen) { file_put_contents($stateFile, 'error: UDP bind ' . $es); exit(1); }
stream_set_blocking($udp_listen, false);

$udp_send = @stream_socket_client(
    "udp://" . UDP_RELAY_HOST . ":" . UDP_RELAY_PORT, $e, $es
);
if (!$udp_send) { file_put_contents($stateFile, 'error: UDP connect ' . $es); exit(1); }
stream_set_blocking($udp_send, false);

$wsClients = [];      // id => ['sock'=>..., 'buffer'=>..., 'handshake'=>..., 'clientId'=>...]
$pairs     = [];      // clientId => peerClientId
$nextId    = 1;

// ---- helpers -------------------------------------------------------------

function wsFrame(string $payload): string {
    $len = strlen($payload);
    $f = chr(0x82); // binary, FIN
    if ($len < 126)          $f .= chr($len);
    elseif ($len < 65536)    $f .= chr(126) . pack('n', $len);
    else                     $f .= chr(127) . pack('J', $len);
    return $f . $payload;
}

function findSockByClientId(array $wsClients, int $clientId) {
    foreach ($wsClients as $c) {
        if ($c['handshake'] && $c['clientId'] === $clientId) return $c['sock'];
    }
    return null;
}

function sendControl($sock, int $code, int $value = 0) {
    if ($sock) @fwrite($sock, wsFrame(chr($code) . pack('N', $value)));
}

// ---- main loop -----------------------------------------------------------

while (true) {
    if (file_exists($stopFile)) { @unlink($stopFile); break; }

    $read = [$tcp, $udp_listen];
    foreach ($wsClients as $c) $read[] = $c['sock'];
    $write = $except = null;

    $n = @stream_select($read, $write, $except, 0, 50000);
    if ($n === false) continue;

    foreach ($read as $sock) {

        // =================================================================
        // UDP FROM RELAY → forward to the correct peer only
        // =================================================================
        if ($sock === $udp_listen) {
            $peer = '';
            $data = @stream_socket_recvfrom($udp_listen, 2048, 0, $peer);
            if ($data === false || strlen($data) < 9) continue; // need header w/ target

            $senderId = unpack('n', substr($data, 1, 2))[1];
            $targetId = unpack('n', substr($data, 3, 2))[1];

            // Only forward if both sides agree they're paired
            if (($pairs[$senderId] ?? 0) !== $targetId) continue;
            if (($pairs[$targetId] ?? 0) !== $senderId) continue;

            $targetSock = findSockByClientId($wsClients, $targetId);
            if ($targetSock) @fwrite($targetSock, wsFrame($data));
            continue;
        }

        // =================================================================
        // NEW WEBSOCKET CONNECTION
        // =================================================================
        if ($sock === $tcp) {
            $conn = @stream_socket_accept($tcp, 0);
            if ($conn) {
                stream_set_blocking($conn, false);
                $id = $nextId++;
                $wsClients[$id] = [
                    'sock' => $conn, 'buffer' => '',
                    'handshake' => false, 'clientId' => 0,
                ];
            }
            continue;
        }

        // find client id
        $id = null;
        foreach ($wsClients as $cid => $c) {
            if ($c['sock'] === $sock) { $id = $cid; break; }
        }
        if ($id === null) continue;

        $data = @fread($sock, 65536);
        if ($data === '' || $data === false) {
            if (feof($sock)) {
                $myClientId = $wsClients[$id]['clientId'];
                // If this client was paired, tell the peer it's over
                if ($myClientId && isset($pairs[$myClientId])) {
                    $peerId = $pairs[$myClientId];
                    unset($pairs[$myClientId], $pairs[$peerId]);
                    $peerSock = findSockByClientId($wsClients, $peerId);
                    if ($peerSock) sendControl($peerSock, 0xFB, 3); // 3 = peer left
                }
                @fclose($sock);
                unset($wsClients[$id]);
            }
            continue;
        }

        $wsClients[$id]['buffer'] .= $data;

        // ---- handshake --------------------------------------------------
        if (!$wsClients[$id]['handshake']) {
            if (strpos($wsClients[$id]['buffer'], "\r\n\r\n") !== false) {
                if (preg_match('/Sec-WebSocket-Key:\s*(.+)\r\n/i', $wsClients[$id]['buffer'], $m)) {
                    $key    = trim($m[1]);
                    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                    $resp   = "HTTP/1.1 101 Switching Protocols\r\n"
                            . "Upgrade: websocket\r\n"
                            . "Connection: Upgrade\r\n"
                            . "Sec-WebSocket-Accept: $accept\r\n\r\n";
                    @fwrite($sock, $resp);
                    $wsClients[$id]['handshake'] = true;
                    $wsClients[$id]['buffer']    = '';
                }
            }
            continue;
        }

        // ---- parse frames ----------------------------------------------
        while (strlen($wsClients[$id]['buffer']) >= 2) {
            $buf = $wsClients[$id]['buffer'];
            $b0 = ord($buf[0]);
            $b1 = ord($buf[1]);
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $len    = $b1 & 0x7F;
            $offset = 2;

            if ($len === 126) {
                if (strlen($buf) < 4) break;
                $len = unpack('n', substr($buf, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if (strlen($buf) < 10) break;
                $p = unpack('N2', substr($buf, 2, 8));
                $len = ($p[1] << 32) | $p[2];
                $offset = 10;
            }

            if ($masked) $offset += 4;
            if (strlen($buf) < $offset + $len) break;

            $mask = $masked ? substr($buf, $offset - 4, 4) : null;
            $payload = substr($buf, $offset, $len);
            $wsClients[$id]['buffer'] = substr($buf, $offset + $len);

            if ($masked) {
                for ($i = 0; $i < $len; $i++) {
                    $payload[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
                }
            }

            if ($opcode === 0x8) {
                @fclose($sock);
                unset($wsClients[$id]);
                break 2;
            }

            if ($opcode !== 0x1 && $opcode !== 0x2) continue;
            if ($len < 1) continue;

            $type = ord($payload[0]);

            // ---------- PAIR_REQUEST: 0xFD + from(4) + to(4) ----------
            if ($type === 0xFD && $len >= 9) {
                $fromId = unpack('N', substr($payload, 1, 4))[1];
                $toId   = unpack('N', substr($payload, 5, 4))[1];

                // Trust the socket's own clientId
                $wsClients[$id]['clientId'] = $fromId;

                $peerSock = findSockByClientId($wsClients, $toId);

                if (!$peerSock) {
                    sendControl($sock, 0xFB, 1); // peer offline
                } elseif (isset($pairs[$toId]) || isset($pairs[$fromId])) {
                    sendControl($sock, 0xFB, 2); // busy
                } elseif ($toId === $fromId) {
                    sendControl($sock, 0xFB, 4);
                } else {
                    $pairs[$fromId] = $toId;
                    $pairs[$toId]   = $fromId;
                    sendControl($sock,   0xFC, $toId);
                    sendControl($peerSock, 0xFC, $fromId);
                }
                continue;
            }

            // ---------- AUDIO: room(1)+sender(2)+target(2)+seq(4)+pcm ----------
            if ($len >= 9) {
                $senderId = unpack('n', substr($payload, 1, 2))[1];
                $targetId = unpack('n', substr($payload, 3, 2))[1];

                if ($wsClients[$id]['clientId'] === 0) {
                    $wsClients[$id]['clientId'] = $senderId;
                }
                // Only accept audio from a paired sender
                if (($pairs[$senderId] ?? 0) !== $targetId) continue;

                @stream_socket_sendto($udp_send, $payload, 0,
                    UDP_RELAY_HOST . ':' . UDP_RELAY_PORT);
            }
        }
    }
}