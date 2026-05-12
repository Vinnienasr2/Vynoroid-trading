<?php
class WebSocketClient {
    private string $host;
    private int $port;
    private string $path;
    private $socket = null;

    public function __construct(string $url) {
        $parts = parse_url($url);
        $this->host = $parts['host'];
        $this->port = $parts['port'] ?? 443;
        $this->path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public function connect(): void {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $this->socket = stream_socket_client("ssl://{$this->host}:{$this->port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) throw new RuntimeException("WS connect failed: $errstr ($errno)");
        stream_set_blocking($this->socket, false);

        $key = base64_encode(random_bytes(16));
        $headers = "GET {$this->path} HTTP/1.1\r\nHost: {$this->host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
        fwrite($this->socket, $headers);
        $response = fread($this->socket, 4096);
        if (!str_contains($response, '101')) throw new RuntimeException('WebSocket handshake failed');
    }

    public function send(array $payload): void { fwrite($this->socket, $this->encode(json_encode($payload))); }
    public function receive(): ?array {
        $raw = fread($this->socket, 8192);
        if (!$raw) return null;
        $decoded = $this->decode($raw);
        if (!$decoded) return null;
        return json_decode($decoded, true);
    }
    public function ping(): void { fwrite($this->socket, $this->encode('', 0x9)); }
    public function close(): void { if ($this->socket) fclose($this->socket); }

    private function encode(string $payload, int $opcode = 0x1): string {
        $len = strlen($payload); $frame = chr(0x80 | $opcode); $mask = random_bytes(4);
        if ($len <= 125) $frame .= chr(0x80 | $len);
        elseif ($len <= 65535) $frame .= chr(0x80 | 126) . pack('n', $len);
        else $frame .= chr(0x80 | 127) . pack('J', $len);
        $masked = '';
        for ($i=0;$i<$len;$i++) $masked .= $payload[$i] ^ $mask[$i%4];
        return $frame . $mask . $masked;
    }
    private function decode(string $data): ?string {
        if (strlen($data) < 2) return null;
        $len = ord($data[1]) & 127; $offset = 2;
        if ($len === 126) { $len = unpack('n', substr($data,2,2))[1]; $offset = 4; }
        elseif ($len === 127) { $len = unpack('J', substr($data,2,8))[1]; $offset = 10; }
        $masked = (ord($data[1]) & 128) === 128;
        if ($masked) {
            $mask = substr($data, $offset, 4); $offset += 4;
            $payload = substr($data, $offset, $len); $out = '';
            for ($i=0;$i<$len;$i++) $out .= $payload[$i] ^ $mask[$i%4];
            return $out;
        }
        return substr($data, $offset, $len);
    }
}
