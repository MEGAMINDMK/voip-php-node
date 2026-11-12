<?php
/**
 * ws_relay.php — WebSocket ↔ UDP bridge (Bidirectional)
 * Phones (WebSocket) → this → UDP relay
 * UDP relay → this → Phones (WebSocket)
 */

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

define('WS_PORT', 27016);
define('UDP_LISTEN_PORT', 27017); // We listen here for packets coming back from the relay
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

// 1. TCP Server for WebSockets
$tcp = @stream_socket_server("tcp://0.0.0.0:" . WS_PORT, $errno, $errstr);
if (!$tcp) {
    file_put_contents($stateFile, 'error: ' . $errstr);
    exit(1);
}
stream_set_blocking($tcp, false);
echo "WS bridge on :" . WS_PORT . "\n";

// 2. UDP Socket to LISTEN for responses from the relay
$udp_listen = @stream_socket_server(
    "udp://0.0.0.0:" . UDP_LISTEN_PORT,
    $e,
    $es,
    STREAM_SERVER_BIND
);
if (!$udp_listen) {
    file_put_contents($stateFile, 'error: UDP bind ' . $es);
    exit(1);
}
stream_set_blocking($udp_listen, false);
echo "UDP listener on :" . UDP_LISTEN_PORT . "\n";

// 3. UDP Socket to SEND packets to the relay
$udp_send = @stream_socket_client(
    "udp://" . UDP_RELAY_HOST . ":" . UDP_RELAY_PORT,
    $e,
    $es
);
if (!$udp_send) {
    file_put_contents($stateFile, 'error: UDP connect ' . $es);
    exit(1);
}
stream_set_blocking($udp_send, false);

$wsClients = [];
$nextId = 1;

while (true) {
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        break;
    }

    $read = [$tcp, $udp_listen];
    foreach ($wsClients as $c) $read[] = $c['sock'];
    $write = $except = null;

    $n = @stream_select($read, $write, $except, 0, 50000);
    if ($n === false) continue;

    foreach ($read as $sock) {
        // ============================================================
        // HANDLE INCOMING UDP FROM RELAY (Audio coming back to phones)
        // ============================================================
        if ($sock === $udp_listen) {
            $peer = '';
            $data = @stream_socket_recvfrom($udp_listen, 2048, 0, $peer);
            
            if ($data !== false && strlen($data) >= 7) {
                // Extract sender ID from the packet
                $senderId = unpack('n', substr($data, 1, 2))[1];
                
                // Build a WebSocket binary frame for this packet
                $len = strlen($data);
                $frame = chr(0x82); // 0x82 = FIN + Binary Opcode
                if ($len < 126) {
                    $frame .= chr($len);
                } elseif ($len < 65536) {
                    $frame .= chr(126) . pack('n', $len);
                } else {
                    $frame .= chr(127) . pack('J', $len);
                }
                $frame .= $data;

                // Send this frame to all connected WebSockets EXCEPT the sender
                foreach ($wsClients as $id => $c) {
                    if ($c['handshake'] && $c['clientId'] !== $senderId) {
                        @fwrite($c['sock'], $frame);
                    }
                }
            }
            continue;
        }

        // ============================================================
        // ACCEPT NEW WEBSOCKET CONNECTIONS
        // ============================================================
        if ($sock === $tcp) {
            $conn = @stream_socket_accept($tcp, 0);
            if ($conn) {
                stream_set_blocking($conn, false);
                $id = $nextId++;
                $wsClients[$id] = [
                    'sock'      => $conn,
                    'buffer'    => '',
                    'handshake' => false,
                    'clientId'  => 0,
                ];
            }
            continue;
        }

        // ============================================================
        // FIND CLIENT ID
        // ============================================================
        $id = null;
        foreach ($wsClients as $cid => $c) {
            if ($c['sock'] === $sock) { $id = $cid; break; }
        }
        if ($id === null) continue;

        // ============================================================
        // READ DATA FROM WEBSOCKET
        // ============================================================
        $data = @fread($sock, 65536);
        if ($data === '' || $data === false) {
            if (feof($sock)) {
                if ($wsClients[$id]['clientId']) {
                    $leave = "\xFE" . pack('N', $wsClients[$id]['clientId']);
                    @stream_socket_sendto($udp_send, $leave, 0, UDP_RELAY_HOST . ':' . UDP_RELAY_PORT);
                }
                @fclose($sock);
                unset($wsClients[$id]);
            }
            continue;
        }

        $wsClients[$id]['buffer'] .= $data;

        // ============================================================
        // WEBSOCKET HANDSHAKE
        // ============================================================
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

        // ============================================================
        // PARSE WEBSOCKET FRAMES
        // ============================================================
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
                $parts = unpack('N2', substr($buf, 2, 8));
                $len = ($parts[1] << 32) | $parts[2];
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
            } elseif ($opcode === 0x1 || $opcode === 0x2) {
                if ($len >= 1) {
                    // Track clientId
                    if ($len >= 6 && ord($payload[0]) === 0xFF) {
                        $wsClients[$id]['clientId'] = unpack('N', substr($payload, 1, 4))[1];
                    } elseif ($len >= 7) {
                        $wsClients[$id]['clientId'] = unpack('n', substr($payload, 1, 2))[1];
                    }
                    
                    // Send packet to relay
                    @stream_socket_sendto($udp_send, $payload, 0, UDP_RELAY_HOST . ':' . UDP_RELAY_PORT);
                }
            }
        }
    }
}