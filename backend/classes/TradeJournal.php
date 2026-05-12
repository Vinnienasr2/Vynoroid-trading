<?php
class TradeJournal {
    public function __construct(private string $file) {}
    public function append(array $trade): void {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);
        $data = stream_get_contents($fp);
        $rows = $data ? json_decode($data, true) : [];
        $rows[] = $trade + ['timestamp' => date('c')];
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($rows, JSON_PRETTY_PRINT));
        fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    }
    public function stats(): array {
        $rows = file_exists($this->file) ? json_decode(file_get_contents($this->file), true) : [];
        $wins = array_filter($rows, fn($t)=>($t['outcome'] ?? '') === 'WIN');
        $losses = array_filter($rows, fn($t)=>($t['outcome'] ?? '') === 'LOSS');
        return ['total_wins'=>count($wins),'total_losses'=>count($losses),'total_runs'=>count($rows),'trades'=>$rows];
    }
}
