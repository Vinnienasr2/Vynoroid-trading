<?php
class Strategies {
    public static function digitDistribution(array $digits): array {
        $freq = array_fill(0, 10, 0);
        foreach ($digits as $d) $freq[$d]++;
        $total = max(1, count($digits));
        return array_map(fn($c) => ($c / $total) * 100, $freq);
    }

    public static function checkAll(array $digits50, array $digits10, array $digits8, array $candles, array $meta = []): array {
        $dist = self::digitDistribution($digits50);
        $maxDigit = array_keys($dist, max($dist))[0];
        $minDigit = array_keys($dist, min($dist))[0];
        $highPct = max($dist);
        $odd10 = count(array_filter($digits10, fn($d)=>in_array($d,[1,3,5,7,9], true)));
        $even10 = count(array_filter($digits10, fn($d)=>in_array($d,[0,2,4,6,8], true)));
        $low8 = count(array_filter($digits8, fn($d)=>$d < 4));
        $high8 = count(array_filter($digits8, fn($d)=>$d > 5));
        $under3 = count(array_filter($digits50, fn($d)=>$d <= 2));
        $under4 = count(array_filter($digits50, fn($d)=>$d <= 3));
        $high789 = count(array_filter($digits50, fn($d)=>$d >= 7));

        $signals = [];
        if (in_array($maxDigit, [2,4,6,8], true) && $highPct >= 11.7 && $odd10 >= 4) $signals[] = ['name'=>'EVEN','contract_type'=>'DIGITEVEN','barrier'=>null,'module'=>'EvenOdd'];
        if (in_array($maxDigit, [1,3,5,7,9], true) && $highPct >= 11.7 && $even10 >= 4) $signals[] = ['name'=>'ODD','contract_type'=>'DIGITODD','barrier'=>null,'module'=>'EvenOdd'];
        if ($maxDigit > 4 && $minDigit < 4 && $low8 >= 3) $signals[] = ['name'=>'OVER4','contract_type'=>'DIGITOVER','barrier'=>4,'module'=>'OverUnder'];
        if ($maxDigit < 5 && $minDigit > 5 && $high8 >= 3) $signals[] = ['name'=>'UNDER5','contract_type'=>'DIGITUNDER','barrier'=>5,'module'=>'OverUnder'];
        if (($under3 / max(1,count($digits50))) < 0.30 && count(array_filter($digits8, fn($d)=>$d<=2)) < 3) $signals[] = ['name'=>'OVER2','contract_type'=>'DIGITOVER','barrier'=>2,'module'=>'Trend'];
        if (($under4 / max(1,count($digits50))) < 0.35) $signals[] = ['name'=>'OVER3','contract_type'=>'DIGITOVER','barrier'=>3,'module'=>'Trend'];
        if (count($digits10) >= 3 && $digits10[count($digits10)-1] <= 1 && $digits10[count($digits10)-2] <= 1) $signals[] = ['name'=>'OVER1','contract_type'=>'DIGITOVER','barrier'=>1,'module'=>'Trend','stake_pct'=>0.01];
        if (($high789 / max(1,count($digits50))) > 0.80) $signals[] = ['name'=>'UNDER7','contract_type'=>'DIGITUNDER','barrier'=>7,'module'=>'OverUnder'];

        $trendSignal = self::trendSignals($candles);
        if ($trendSignal) $signals[] = $trendSignal;

        $priority = ['EvenOdd'=>1,'OverUnder'=>2,'Trend'=>3,'RiseFall'=>4];
        usort($signals, fn($a,$b)=>($priority[$a['module']]??9) <=> ($priority[$b['module']]??9));
        return $signals[0] ?? [];
    }

    private static function trendSignals(array $candles): array {
        if (count($candles) < 50) return [];
        $closes = array_column($candles, 'close');
        $ema20 = self::ema($closes,20); $ema50 = self::ema($closes,50); $rsi = self::rsi($closes,14);
        if (!$ema20 || !$ema50 || !$rsi) return [];
        $last = end($candles); $prev = prev($candles) ?: $last;
        $flat = abs($ema20 - $ema50) / max(0.00001,$ema50) <= 0.001;
        if ($flat) return [];
        if ($ema20 > $ema50 && $rsi > 45 && $last['close'] > $last['open']) return ['name'=>'RISE','contract_type'=>'CALL','module'=>'RiseFall'];
        if ($ema20 < $ema50 && $rsi < 55 && $last['close'] < $last['open']) return ['name'=>'FALL','contract_type'=>'PUT','module'=>'RiseFall'];
        return [];
    }

    public static function ema(array $values, int $period): ?float {
        if (count($values) < $period) return null;
        $k = 2 / ($period + 1); $ema = $values[0];
        foreach ($values as $v) $ema = ($v * $k) + ($ema * (1 - $k));
        return $ema;
    }

    public static function rsi(array $closes, int $period): ?float {
        if (count($closes) < $period + 1) return null;
        $gains = $losses = 0.0;
        for ($i = count($closes)-$period; $i < count($closes); $i++) {
            $d = $closes[$i] - $closes[$i-1];
            if ($d >= 0) $gains += $d; else $losses += abs($d);
        }
        if ($losses == 0.0) return 100.0;
        $rs = ($gains/$period) / ($losses/$period);
        return 100 - (100 / (1 + $rs));
    }
}
