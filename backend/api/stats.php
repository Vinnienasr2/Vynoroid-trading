<?php
require_once __DIR__ . '/../classes/TradeJournal.php';
header('Content-Type: text/event-stream');
$j = new TradeJournal(__DIR__.'/../logs/trades.json');
while (true) { echo 'data: '.json_encode($j->stats())."\n\n"; ob_flush(); flush(); sleep(2);} 
