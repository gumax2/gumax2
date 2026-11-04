<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/**
 * Calculadora de preços (3.1.6): dois modos de informar o preço original.
 * - "original": preço "De" digitado como aparece na loja (desconto e economia calculados);
 * - "desconto": preço "Por" + % exibido pela loja => preço "De" ESTIMADO.
 * A estimativa nunca é gravada em price_original_cents (campo usado nas publicações e no
 * ranking): fica registrada à parte, na auditoria, ligada ao preço "Por" usado no cálculo.
 * Toda a conta é feita com inteiros (centavos e meios pontos-base), igual ao painel.js.
 */
final class Pricing
{
    public const MODES = ['original' => 'Preço original da loja', 'desconto' => 'Calcular pelo desconto %'];
    public const PCT_MIN = 1;
    public const PCT_MAX = 95;
    private const AUDIT_ACTION = 'preco_original_estimado';

    /** "35", "35%", "35,5" ou "35.25" => [meios pontos-base, casas decimais]; null se inválido (1 a 95 %). */
    public static function parsePercent(string $v): ?array
    {
        $v = str_replace([' ', '%'], '', trim($v));
        if (!preg_match('/^(\d{1,2})(?:[.,](\d{1,2}))?$/D', $v, $m)) {
            return null;
        }
        $dec = isset($m[2]) ? strlen($m[2]) : 0;
        $hundredths = (int)$m[1] * 100 + ($dec ? (int)str_pad($m[2], 2, '0') : 0);
        if ($hundredths < self::PCT_MIN * 100 || $hundredths > self::PCT_MAX * 100) {
            return null;
        }
        return [$hundredths * 2, $dec];
    }

    /** Preço original para um desconto em meios pontos-base (arredondado ao centavo, meio para cima). */
    private static function originalFor(int $promo, int $halfBp): int
    {
        $den = 20000 - $halfBp;
        return intdiv(2 * $promo * 20000 + $den, 2 * $den);
    }

    /**
     * Estimativa a partir do % exibido. Como a loja arredonda o %, o preço real fica numa faixa:
     * ± meia unidade da última casa exibida (35% => 34,5% a 35,5%).
     * @return array{original:int,min:int,max:int,economia:int,economia_min:int,economia_max:int,percentual:string}
     */
    public static function estimate(int $promo, string $percent): array
    {
        $p = self::parsePercent($percent) ?? throw new \InvalidArgumentException('Percentual de desconto inválido (use de 1 a 95, ex.: 35 ou 35,5).');
        [$h, $dec] = $p;
        $step = [0 => 100, 1 => 10, 2 => 1][$dec];
        $o = self::originalFor($promo, $h);
        $min = self::originalFor($promo, max(0, $h - $step));
        $max = self::originalFor($promo, min(19998, $h + $step));
        return ['original' => $o, 'min' => $min, 'max' => $max, 'economia' => $o - $promo, 'economia_min' => $min - $promo, 'economia_max' => $max - $promo,
            'percentual' => number_format($h / 200, $dec, ',', '')];
    }

    /** Origem do preço "De" gravado: 'api', 'manual', 'importado' ou null (sem preço original). */
    public static function originalSource(array $r): ?string
    {
        if (empty($r['price_original_cents'])) {
            return null;
        }
        $src = (string)($r['price_source'] ?? '');
        if ($src === 'conferencia_manual_de_api') {
            return ($r['marketplace'] ?? '') === 'mercadolivre' ? 'api' : 'manual'; // "Por" conferido à mão, "De" mantido da API
        }
        if (str_starts_with($src, 'api_')) {
            // Só o Mercado Livre devolve o preço original; a API da Shopee informa apenas o "Por".
            return $src === 'api_mercadolivre' && ($r['marketplace'] ?? '') === 'mercadolivre' ? 'api' : 'importado';
        }
        return $src === 'conferencia_manual' || in_array($src, ['edicao', 'manual'], true) || (!empty($r['price_verified_at']) && !str_starts_with((string)$r['price_verified_by'], 'api:')) ? 'manual' : 'importado';
    }

    /**
     * Resolve o preço original a gravar conforme o modo.
     * - original: usa o valor digitado (vazio = sem preço original);
     * - desconto: NÃO grava estimativa; preserva o "De" confirmado pela API; demais "De" não
     *   conferidos nesta operação são removidos (o "De" das publicações exige valor real).
     * @return array{original:?int,estimativa:?array,preservado_api:bool,erros:list<string>}
     */
    public static function resolve(string $mode, ?int $promo, string $originalInput, string $percentInput, ?array $current): array
    {
        $out = ['original' => null, 'estimativa' => null, 'preservado_api' => false, 'erros' => []];
        if (!isset(self::MODES[$mode])) {
            $out['erros'][] = 'Modo de preço inválido.';
            return $out;
        }
        if ($mode === 'original') {
            $raw = trim($originalInput);
            if ($raw !== '') {
                $o = Money::tryParse($raw);
                if ($o === null || $o <= 0) {
                    $out['erros'][] = 'Preço original (De) inválido. Use o formato 199,90 ou deixe vazio.';
                } elseif ($promo !== null && $o < $promo) {
                    $out['erros'][] = 'O preço original (De) não pode ser menor que o promocional (Por).';
                } else {
                    $out['original'] = $o;
                }
            }
            return $out;
        }
        if ($promo === null || $promo <= 0) {
            $out['erros'][] = 'Informe o preço promocional (Por) para calcular o preço original.';
            return $out;
        }
        if (self::parsePercent($percentInput) === null) {
            $out['erros'][] = 'Percentual de desconto inválido: informe o % exibido pela loja, de 1 a 95 (ex.: 35 ou 35,5).';
            return $out;
        }
        $out['estimativa'] = self::estimate($promo, $percentInput);
        if ($current && self::originalSource($current) === 'api' && (int)$current['price_original_cents'] >= $promo) {
            $out['original'] = (int)$current['price_original_cents'];
            $out['preservado_api'] = true;
        }
        return $out;
    }

    /** Registra a estimativa (auditoria, somente inclusão). Nunca toca price_original_cents. */
    public static function recordEstimate(PDO $pdo, int $id, int $promo, array $est, string $actor, string $origin): void
    {
        Audit::log($pdo, $actor, self::AUDIT_ACTION, 'catalog', $id, [
            'por' => $promo, 'percentual' => $est['percentual'], 'de_estimado' => $est['original'], 'faixa' => [$est['min'], $est['max']],
            'economia_estimada' => $est['economia'], 'origem' => $origin, 'confirmado' => false,
        ]);
    }

    /** Última estimativa válida para o preço "Por" atual (uma mudança de preço invalida a estimativa). */
    public static function latestEstimate(PDO $pdo, array $r): ?array
    {
        if (empty($r['price_promo_cents'])) {
            return null;
        }
        $row = Db::one($pdo, 'SELECT at, detail FROM gmax3_audit WHERE action=? AND entity=? AND entity_id=? ORDER BY id DESC LIMIT 1', [self::AUDIT_ACTION, 'catalog', (string)$r['id']]);
        $d = $row ? json_decode((string)$row['detail'], true) : null;
        if (!is_array($d) || (int)($d['por'] ?? 0) !== (int)$r['price_promo_cents']) {
            return null;
        }
        return $d + ['em' => $row['at']];
    }
}
