<?php
require_once __DIR__ . '/../classes/WebSocketClient.php';
$config = require __DIR__ . '/../config/config.php';
session_set_cookie_params($config['session_cookie']);
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token1'] ?? '';
    if (!$token) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Missing token']); exit; }

    try {
        $ws = new WebSocketClient($config['ws_url']);
        $ws->connect();
        $ws->send(['authorize'=>$token]);
        $auth = null; $started = time();
        while (time() - $started < 8) {
            $m = $ws->receive();
            if (isset($m['authorize'])) { $auth = $m['authorize']; break; }
            usleep(100000);
        }
        if (!$auth) throw new RuntimeException('Authorize timeout');

        $_SESSION['token'] = $token;
        $_SESSION['user'] = [
            'loginid'=>$auth['loginid'] ?? '', 'email'=>$auth['email'] ?? '', 'currency'=>$auth['currency'] ?? 'USD',
            'balance'=>$auth['balance'] ?? 0, 'is_virtual'=>$auth['is_virtual'] ?? 0
        ];
        $runtimeToken = bin2hex(random_bytes(16));
        file_put_contents(__DIR__.'/../shared/session_'.$runtimeToken.'.json', json_encode(['token'=>$token]));
        exec('nohup php ' . escapeshellarg(__DIR__ . '/../bot/BotRunner.php') . ' ' . escapeshellarg($runtimeToken) . ' > /tmp/derivbot.log 2>&1 &');

        echo json_encode(['success'=>true,'user'=>$_SESSION['user']]); exit;
    } catch (Throwable $e) {
        http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit;
    }
}
?>
<!doctype html><html><body><script>
const hash = new URLSearchParams(window.location.hash.slice(1));
fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({token1:hash.get('token1')||''})})
.then(r=>r.json()).then(d=>{ if(!d.success){alert(d.error||'OAuth failed'); return;} location.href='/dashboard';});
</script></body></html>
