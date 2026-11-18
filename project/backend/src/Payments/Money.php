<?php
declare(strict_types=1);

namespace Eroydad\Payments;

/**
 * Money — محاسبهٔ شفاف مبلغ پایه، مالیات و جمع نهایی.
 * همهٔ مبالغ به تومان و به‌صورت عدد صحیح نگهداری می‌شوند.
 */
final class Money
{
    /**
     * @return array{base:int,tax:int,total:int,participant_total:int,organizer_tax:int,tax_percent:float,tax_payer:string}
     */
    public static function breakdown(int $baseToman, float $taxPercent, string $taxPayer): array
    {
        $base = max(0, $baseToman);
        if ($taxPercent < 0) {
            $taxPercent = 0.0;
        }
        if ($taxPercent > 100) {
            $taxPercent = 100.0;
        }
        if (!in_array($taxPayer, ['participant', 'organizer'], true)) {
            $taxPayer = 'participant';
        }
        $tax = (int)round($base * ($taxPercent / 100));
        $participantTax = $taxPayer === 'participant' ? $tax : 0;
        $organizerTax = $taxPayer === 'organizer' ? $tax : 0;
        return [
            'base' => $base,
            'tax' => $tax,
            'total' => $base + $participantTax,
            'participant_total' => $base + $participantTax,
            'organizer_tax' => $organizerTax,
            'tax_percent' => $taxPercent,
            'tax_payer' => $taxPayer,
        ];
    }

    public static function format(int $toman): string
    {
        return number_format($toman, 0, '.', ',') . ' تومان';
    }

    public static function payerLabel(string $payer): string
    {
        return $payer === 'organizer' ? 'برگزارکننده' : 'فراگیر';
    }
}
