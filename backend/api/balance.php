<?php
header('Content-Type: text/event-stream'); header('Cache-Control: no-cache');
while (true) {
  $balance = @json_decode(@file_get_contents(__DIR__.'/../shared/balance.json'), true) ?: ['balance'=>0,'currency'=>'USD'];
  echo 'data: ' . json_encode($balance) . "\n\n";
  ob_flush(); flush(); sleep(1);
}
