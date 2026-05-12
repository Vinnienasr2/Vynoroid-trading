<?php
header('Content-Type: text/event-stream'); header('Cache-Control: no-cache');
$offset = 0;
while (true) {
  $file = __DIR__.'/../shared/console.log';
  if (file_exists($file)) {
    $content = file_get_contents($file);
    $chunk = substr($content, $offset);
    if ($chunk !== '') { foreach (explode("\n", trim($chunk)) as $line) echo 'data: '.json_encode(['line'=>$line])."\n\n"; $offset = strlen($content); }
  }
  ob_flush(); flush(); usleep(300000);
}
