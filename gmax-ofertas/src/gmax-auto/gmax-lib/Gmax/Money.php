<?php
declare(strict_types=1);

namespace Gmax;

/** Valores monetários sempre em centavos inteiros; nunca float na persistência. */
final class Money
{
    /**
     * Converte "1.299,90", "299,90", "R$ 49,90", "299.9", 143.91 em centavos.
     * Retorna null para vazio; lança \InvalidArgumentException para texto inválido.
     */
    public static function parse(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            return (int)round($value * 100);
        }
        $v = trim(str_ireplace(['R$', "\u{00A0}", ' '], '', (string)$value));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, ',')) {
            // Formato brasileiro: ponto = milhar, vírgula = decimal.
            if (!preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$|^\d+,\d{1,2}$/D', $v)) {
                throw new \InvalidArgumentException("Valor monetário inválido: $value");
            }
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/D', $v)) {
            $v = str_replace('.', '', $v); // "1.299" = mil duzentos e noventa e nove
        } elseif (!preg_match('/^\d+(\.\d{1,2})?$/D', $v)) {
            throw new \InvalidArgumentException("Valor monetário inválido: $value");
        }
        [$int, $dec] = array_pad(explode('.', $v, 2), 2, '');
        return (int)$int * 100 + (int)str_pad($dec, 2, '0');
    }

    public static function tryParse(mixed $value, ?string &$error = null): ?int
    {
        try {
            return self::parse($value);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
            return null;
        }
    }

    public static function format(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    /** Valor para formulários: "1299,90". */
    public static function input(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
    }

    /**
     * Desconto real somente quando os dois preços existem e o original é maior.
     * Percentual arredondado para baixo para nunca exagerar a oferta.
     * @return array{percent:int,savings:int}|null
     */
    public static function discount(?int $original, ?int $promo): ?array
    {
        if ($original === null || $promo === null || $promo <= 0 || $original <= $promo) {
            return null;
        }
        $savings = $original - $promo;
        return ['percent' => intdiv($savings * 100, $original), 'savings' => $savings];
    }
}
