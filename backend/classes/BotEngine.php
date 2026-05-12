<?php
require_once __DIR__ . '/WebSocketClient.php';
require_once __DIR__ . '/RiskManager.php';
require_once __DIR__ . '/TradeJournal.php';
require_once __DIR__ . '/Strategies.php';

class BotEngine {
    private array $digits = [];
    private array $candles = [];
    private array $strategyLossStreak = [];
    private array $strategyCooldownUntil = [];
    private array $activeRuns = []; // [symbol][strategyName] => [active,count,results]
    private array $openTradeMeta = []; // contract_id => [symbol,strategy]
    private array $inFlightBySymbolStrategy = [];
    private ?array $currentRun = null; // ['symbol'=>..., 'strategy'=>...]
    private bool $halted = false;
    private int $globalLossStreak = 0;
    private float $balance = 0;
    private float $startBalance = 0;
    private ?RiskManager $risk = null;
    private TradeJournal $journal;

    public function __construct(private array $config, private string $token) {
        $this->journal = new TradeJournal(__DIR__ . '/../logs/trades.json');
    }

    public function run(): void {
        $backoff = 1;
        while (true) {
            try {
                $ws = new WebSocketClient($this->config['ws_url']);
                $ws->connect();
                $this->bootstrapSubscriptions($ws);
                $backoff = 1;
                $lastPing = time();

                while (true) {
                    if ($this->halted) { usleep(250000); continue; }
                    if (time() - $lastPing >= 30) { $ws->ping(); $lastPing = time(); }
                    $msg = $ws->receive();
                    if (!$msg) { usleep(100000); continue; }
                    $this->handle($ws, $msg);
                }
            } catch (Throwable $e) {
                $this->log('WARN', 'WS disconnected: ' . $e->getMessage());
                sleep($backoff);
                $backoff = min(30, $backoff * 2);
            }
        }
    }

    private function bootstrapSubscriptions(WebSocketClient $ws): void {
        $ws->send(['authorize'=>$this->token]);
        foreach ($this->config['symbols'] as $symbol) {
            $this->digits[$symbol] = $this->digits[$symbol] ?? [];
            $this->candles[$symbol] = $this->candles[$symbol] ?? [];
            $this->activeRuns[$symbol] = $this->activeRuns[$symbol] ?? [];
            $ws->send(['ticks'=>$symbol,'subscribe'=>1]);
        }
        $ws->send(['balance'=>1,'subscribe'=>1]);
    }

    private function handle(WebSocketClient $ws, array $msg): void {
        if (isset($msg['balance'])) {
            $this->balance = (float)$msg['balance']['balance'];
            if ($this->startBalance <= 0) { $this->startBalance = $this->balance; $this->risk = new RiskManager($this->startBalance); }
            $this->writeShared('balance.json', ['balance'=>$this->balance,'currency'=>$msg['balance']['currency']]);
            return;
        }
        if (isset($msg['tick'])) {
            $symbol = $msg['tick']['symbol'];
            $lastDigit = (int)substr(preg_replace('/\D/', '', (string)$msg['tick']['quote']), -1);
            $this->push($this->digits[$symbol], $lastDigit, 100);
            $this->aggregateCandle($symbol, (float)$msg['tick']['quote'], (int)$msg['tick']['epoch']);
            $this->maybeTrade($ws, $symbol);
            return;
        }
        if (isset($msg['buy']['contract_id'])) {
            $cid = (string)$msg['buy']['contract_id'];
            if (isset($msg['echo_req']['parameters']['symbol'], $msg['echo_req']['parameters']['contract_type'])) {
                $symbol = $msg['echo_req']['parameters']['symbol'];
                $ctype = $msg['echo_req']['parameters']['contract_type'];
                $strategy = $msg['echo_req']['passthrough']['strategy'] ?? $ctype;
                $this->openTradeMeta[$cid] = ['symbol'=>$symbol,'strategy'=>$strategy];
            }
            $ws->send(['proposal_open_contract'=>1,'contract_id'=>$msg['buy']['contract_id'],'subscribe'=>1]);
            return;
        }
        if (isset($msg['proposal_open_contract']) && ($msg['proposal_open_contract']['is_sold'] ?? 0) == 1) {
            $this->finalizeTrade($msg['proposal_open_contract']);
        }
    }

    private function maybeTrade(WebSocketClient $ws, string $symbol): void {
        $d = $this->digits[$symbol];
        if (count($d) < 50 || !$this->risk) return;

        $signal = Strategies::checkAll(array_slice($d,-50), array_slice($d,-10), array_slice($d,-8), $this->candles[$symbol]);
        if (!$signal) return;
        $strategy = $signal['name'];
        if ($this->currentRun && ($this->currentRun['symbol'] !== $symbol || $this->currentRun['strategy'] !== $strategy)) return;

        $run = $this->activeRuns[$symbol][$strategy] ?? ['active'=>false,'count'=>0,'results'=>[]];
        if (!$run['active']) {
            $run = ['active'=>true,'count'=>0,'results'=>[]];
            $this->activeRuns[$symbol][$strategy] = $run;
            $this->currentRun = ['symbol'=>$symbol,'strategy'=>$strategy];
        }

        if (($this->strategyCooldownUntil[$strategy] ?? 0) > time()) return;
        if ($this->risk->sessionStop($this->balance)) { $this->log('CRITICAL','Max session loss reached'); return; }
        if ($this->globalLossStreak >= 5 || $this->halted) return;
        if ($run['count'] >= 7) { $this->activeRuns[$symbol][$strategy]['active'] = false; return; }
        if (($this->inFlightBySymbolStrategy[$symbol][$strategy] ?? false) === true) return;

        $lossStreak = $this->strategyLossStreak[$strategy] ?? 0;
        $stake = $this->risk->stakeFor($lossStreak);
        $proposal = [
            'buy'=>1,
            'price'=>$stake,
            'parameters'=>[
                'amount'=>$stake,'basis'=>'stake','contract_type'=>$signal['contract_type'],'currency'=>'USD','duration'=>1,'duration_unit'=>'t','symbol'=>$symbol
            ],
            'passthrough'=>['strategy'=>$strategy]
        ];
        if (!is_null($signal['barrier'] ?? null)) $proposal['parameters']['barrier'] = (string)$signal['barrier'];
        $ws->send($proposal);
        $this->inFlightBySymbolStrategy[$symbol][$strategy] = true;
        $this->log('TRADE', "{$strategy} {$symbol} stake {$stake}");
    }

    private function finalizeTrade(array $poc): void {
        $profit = (float)($poc['profit'] ?? 0);
        $outcome = $profit >= 0 ? 'WIN' : 'LOSS';
        $cid = (string)($poc['contract_id'] ?? '');
        $meta = $this->openTradeMeta[$cid] ?? ['symbol'=>$poc['underlying'] ?? '', 'strategy'=>$poc['contract_type'] ?? 'UNKNOWN'];
        $symbol = $meta['symbol']; $strategy = $meta['strategy'];
        $this->inFlightBySymbolStrategy[$symbol][$strategy] = false;

        if (!isset($this->activeRuns[$symbol][$strategy])) $this->activeRuns[$symbol][$strategy] = ['active'=>true,'count'=>0,'results'=>[]];
        $this->activeRuns[$symbol][$strategy]['count']++;
        $this->activeRuns[$symbol][$strategy]['results'][] = $outcome;
        $results = $this->activeRuns[$symbol][$strategy]['results'];

        if ($outcome === 'LOSS') {
            $this->globalLossStreak++;
            $this->strategyLossStreak[$strategy] = ($this->strategyLossStreak[$strategy] ?? 0) + 1;
            if (($this->strategyLossStreak[$strategy] ?? 0) >= 3) $this->strategyCooldownUntil[$strategy] = time() + 900;
        } else {
            $this->globalLossStreak = 0;
            $this->strategyLossStreak[$strategy] = 0;
        }

        $count = $this->activeRuns[$symbol][$strategy]['count'];
        $endByPattern = $count >= 3 && count($results) >= 2 && $results[count($results)-2] === 'LOSS' && $results[count($results)-1] === 'WIN';
        if ($endByPattern || $count >= 7) {
            $this->activeRuns[$symbol][$strategy] = ['active'=>false,'count'=>0,'results'=>[]];
            $this->currentRun = null;
            $this->log('STRATEGY', "$strategy run ended on $symbol at count {$count}");
        }

        $this->risk?->applyOutcome($outcome);
        if ($this->globalLossStreak >= 5) { $this->halted = true; $this->log('CRITICAL','5 consecutive losses. Bot stopped to protect account.'); }
        $this->journal->append([
            'symbol'=>$symbol, 'contract_type'=>$poc['contract_type'] ?? '', 'stake'=>$poc['buy_price'] ?? 0,
            'outcome'=>$outcome, 'profit_loss'=>$profit, 'balance_after_trade'=>$this->balance
        ]);
        $this->log($outcome, "$strategy {$outcome} {$profit}");
    }

    private function aggregateCandle(string $symbol, float $price, int $epoch): void {
        $minute = intdiv($epoch, 60);
        $arr = &$this->candles[$symbol];
        $last = end($arr);
        if (!$last || $last['minute'] !== $minute) {
            $arr[] = ['minute'=>$minute,'open'=>$price,'high'=>$price,'low'=>$price,'close'=>$price];
            if (count($arr) > 120) array_shift($arr);
            return;
        }
        $idx = array_key_last($arr);
        $arr[$idx]['high'] = max($arr[$idx]['high'], $price);
        $arr[$idx]['low'] = min($arr[$idx]['low'], $price);
        $arr[$idx]['close'] = $price;
    }

    private function push(array &$arr, int $v, int $max): void { $arr[] = $v; if (count($arr)>$max) array_shift($arr); }
    private function writeShared(string $file, array $data): void {
        $fp = fopen(__DIR__ . '/../shared/' . $file, 'c+'); flock($fp, LOCK_EX); ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data)); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    }
    private function log(string $level, string $message): void {
        file_put_contents(__DIR__ . '/../shared/console.log', '[' . date('Y-m-d H:i:s') . "] [$level] $message\n", FILE_APPEND);
    }

}
