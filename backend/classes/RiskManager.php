<?php
class RiskManager {
    private float $currentStake;
    private int $wins = 0;
    private int $losses = 0;

    public function __construct(private float $startBalance, private float $maxSessionLossPct = 0.08, float $baseStake = 0.35) {
        $this->currentStake = $baseStake;
    }

    public function stakeFor(int $lossStreak): float {
        $martingale = pow(1.5, $lossStreak);
        return round(max(0.35, $this->currentStake * $martingale), 2);
    }

    public function applyOutcome(string $outcome): void {
        if ($outcome === 'WIN') $this->wins++; else $this->losses++;
        $total = max(1, $this->wins + $this->losses);
        $winRate = $this->wins / $total;
        $lossRate = $this->losses / $total;

        if ($outcome === 'WIN') {
            $this->currentStake = max(0.35, $this->currentStake * (1 - ($winRate * 0.08))); // tighten after wins
        } else {
            $this->currentStake = $this->currentStake * (1 + ($lossRate * 0.12)); // press gradually on losses
        }
        $this->currentStake = round(min($this->currentStake, $this->startBalance * 0.02), 2);
    }

    public function sessionStop(float $currentBalance): bool {
        return (($this->startBalance - $currentBalance) / $this->startBalance) >= $this->maxSessionLossPct;
    }

    public function getCurrentStake(): float { return $this->currentStake; }
    public function getRates(): array {
        $total = max(1, $this->wins + $this->losses);
        return ['win_rate'=>$this->wins / $total, 'loss_rate'=>$this->losses / $total, 'wins'=>$this->wins, 'losses'=>$this->losses];
    }
}
