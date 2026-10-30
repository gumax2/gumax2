<?php
declare(strict_types=1);

namespace GmaxRenov;

/** Etapas dos lembretes: dias relativos ao vencimento (negativo = antes). */
final class Stages
{
    public const CODES = ['antes', 'dia', 'depois'];

    public static function all(Settings $s): array
    {
        $out = [];
        foreach ((array)$s->get('etapas') as $e) {
            if (!in_array($e['codigo'] ?? '', self::CODES, true)) {
                continue;
            }
            $dias = (int)$e['dias'];
            $out[] = [
                'codigo' => $e['codigo'],
                'dias' => $dias,
                'ativo' => (bool)($e['ativo'] ?? true),
                'rotulo' => self::label($e['codigo'], $dias),
            ];
        }
        return $out;
    }

    public static function label(string $code, int $dias): string
    {
        if ($code === 'dia') {
            return 'No vencimento';
        }
        $n = abs($dias);
        return $code === 'antes'
            ? "$n dia" . ($n > 1 ? 's' : '') . ' antes'
            : "$n dia" . ($n > 1 ? 's' : '') . ' após';
    }

    /** Valida e normaliza etapas vindas do formulário. */
    public static function sanitize(array $in): array
    {
        $by = [];
        foreach ($in as $e) {
            $by[$e['codigo'] ?? ''] = $e;
        }
        $antes = max(1, min(15, abs((int)($by['antes']['dias'] ?? 3))));
        $depois = max(1, min(15, abs((int)($by['depois']['dias'] ?? 1))));
        return [
            ['codigo' => 'antes', 'dias' => -$antes, 'ativo' => !empty($by['antes']['ativo'])],
            ['codigo' => 'dia', 'dias' => 0, 'ativo' => !empty($by['dia']['ativo'])],
            ['codigo' => 'depois', 'dias' => $depois, 'ativo' => !empty($by['depois']['ativo'])],
        ];
    }
}
