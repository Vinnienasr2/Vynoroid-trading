<?php
require_once __DIR__ . '/../classes/BotEngine.php';
$config = require __DIR__ . '/../config/config.php';
$runtimeToken = $argv[1] ?? '';
$sessionFile = __DIR__ . '/../shared/session_' . preg_replace('/[^a-f0-9]/', '', $runtimeToken) . '.json';
if (!file_exists($sessionFile)) exit(1);
$payload = json_decode(file_get_contents($sessionFile), true);
$token = $payload['token'] ?? null;
if (!$token) exit(1);
$engine = new BotEngine($config, $token);
$engine->run();
