<?php
return [
    'app_id' => getenv('DERIV_APP_ID') ?: 'YOUR_APP_ID',
    'oauth_redirect_uri' => getenv('DERIV_REDIRECT_URI') ?: 'http://localhost:8000/backend/auth/callback.php',
    'ws_url' => 'wss://ws.binaryws.com/websockets/v3?app_id=' . (getenv('DERIV_APP_ID') ?: 'YOUR_APP_ID'),
    'symbols' => ['R_10','R_25','R_50','R_75','R_100'],
    'session_cookie' => [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ],
];
