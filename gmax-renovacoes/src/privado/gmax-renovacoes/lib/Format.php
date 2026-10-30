<?php
declare(strict_types=1);

namespace GmaxRenov;

final class Format
{
    /** Converte DECIMAL ("120.00"), número ou texto brasileiro ("1.234,56", "R$ 120") em centavos. */
    public static function toCents($v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_int($v)) {
            return $v * 100;
        }
        if (is_float($v)) {
            return (int)round($v * 100);
        }
        $s = preg_replace('/[^\d,.\-]/', '', (string)$v) ?? '';
        if (strpos($s, ',') !== false) {
            // Formato brasileiro: ponto = milhar, vírgula = decimal.
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }
        if ($s === '' || !is_numeric($s)) {
            return 0;
        }
        // Evita erro de ponto flutuante: separa inteiro e fração.
        $neg = $s[0] === '-';
        $s = ltrim($s, '-');
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $frac3 = substr(str_pad($frac, 3, '0'), 0, 3);
        $cents = (int)$int * 100 + intdiv((int)$frac3 + 5, 10); // arredonda na 3ª casa
        return $neg ? -$cents : $cents;
    }

    public static function money(int $cents): string
    {
        $neg = $cents < 0;
        $cents = abs($cents);
        return ($neg ? '-' : '') . 'R$ ' . number_format(intdiv($cents, 100), 0, ',', '.') . ',' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function date(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $d ? $d->format('d/m/Y') : $ymd;
    }

    /**
     * Normaliza telefone brasileiro para E.164 sem "+" (ex.: 5531999998888).
     * Aceita fixo (8 dígitos) e celular (9 dígitos começando com 9). Retorna null se inválido.
     */
    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string)$raw) ?? '';
        if ($d === '') {
            return null;
        }
        $d = ltrim($d, '0'); // remove prefixo de operadora/discagem "0"
        if (strlen($d) === 10 || strlen($d) === 11) {
            $d = '55' . $d;
        }
        if (!preg_match('/^55([1-9][1-9])(\d{8,9})$/', $d, $m)) {
            return null;
        }
        $local = $m[2];
        if (strlen($local) === 9 && $local[0] !== '9') {
            return null;
        }
        if (strlen($local) === 8 && !in_array($local[0], ['2', '3', '4', '5', '6', '7', '8', '9'], true)) {
            return null;
        }
        return $d;
    }

    public static function maskPhone(?string $e164): string
    {
        if (!$e164) {
            return '—';
        }
        return '+' . substr($e164, 0, 2) . ' (' . substr($e164, 2, 2) . ') ' . substr($e164, 4, -4) . '-' . substr($e164, -4);
    }
}
