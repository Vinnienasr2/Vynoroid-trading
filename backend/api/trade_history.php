<?php
require_once __DIR__ . '/../classes/TradeJournal.php';
header('Content-Type: application/json');
$j = new TradeJournal(__DIR__.'/../logs/trades.json');
echo json_encode($j->stats());
