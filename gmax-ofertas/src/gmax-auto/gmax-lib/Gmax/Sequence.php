<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/**
 * Conferência sequencial (3.2.2.1). Corrige o ciclo da 3.2.2, em que o "próximo" era calculado
 * sobre a fila reordenada depois de cada salvamento (um produto conferido que continuava com outra
 * pendência voltava a ser "o próximo").
 *
 * Agora: no início é tirada uma FOTOGRAFIA ordenada dos IDs a conferir; cada produto salvo ou pulado
 * é registrado como visitado NESTA sequência e nunca volta automaticamente. "Visitado" não significa
 * "validado": as regras comerciais continuam as mesmas. Estado só na sessão administrativa (sem
 * migração, sem gravar no banco); produtos novos não entram numa sequência já iniciada.
 */
final class Sequence
{
    public const KEY = 'gmax3_seq';
    public const MAX = 500;
    public const DONE = ['salvo' => 'conferido', 'pulado' => 'pulado', 'resolvido' => 'resolvido fora da sequência', 'removido' => 'arquivado ou removido'];

    public static function current(): ?array
    {
        $s = $_SESSION[self::KEY] ?? null;
        return is_array($s) && isset($s['id'], $s['ids'], $s['done']) ? $s : null;
    }

    public static function store(array $s): void
    {
        $_SESSION[self::KEY] = $s;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /** O produto precisa de conferência agora? (mesma regra da fila de trabalho) */
    private static function needsCheck(PDO $pdo, int $id, array $ctx): ?string
    {
        $r = Catalog::get($pdo, $id);
        if (!$r || in_array($r['validation_status'], ['arquivado', 'rejeitado'], true)) {
            return 'removido';
        }
        $a = Funnel::analyze($pdo, $r, $ctx);
        return $a['situation'] !== null && ($a['action']['page'] ?? '') === 'conferir' ? null : 'resolvido';
    }

    /**
     * Fotografia dos produtos a conferir na fila filtrada, na ordem atual; com $fromId, a partir dele.
     * @param list<int>|null $only IDs explícitos (ex.: revisitar os pulados)
     */
    public static function start(PDO $pdo, array $f, ?int $fromId = null, ?array $only = null): array
    {
        if ($only !== null) {
            $ids = array_values(array_unique(array_map('intval', $only)));
        } else {
            $ids = [];
            foreach (Funnel::workQueue($pdo, $f) as $x) {
                if (($x['a']['action']['page'] ?? '') === 'conferir') {
                    $ids[] = (int)$x['item']['id'];
                }
            }
            if ($fromId !== null && ($i = array_search($fromId, $ids, true)) !== false) {
                $ids = array_slice($ids, $i);
            }
        }
        $s = ['id' => bin2hex(random_bytes(6)), 'f' => $f, 'ids' => array_slice($ids, 0, self::MAX), 'done' => [], 'order' => [], 'started' => time(), 'finished' => false];
        self::store($s);
        return $s;
    }

    public static function mark(array &$s, int $id, string $how): void
    {
        if (!isset($s['done'][$id]) || $how === 'salvo') {
            $s['done'][$id] = $how;
        }
        $s['order'] = array_values(array_filter($s['order'], static fn($x) => $x !== $id));
        $s['order'][] = $id;
    }

    /** Próximo da fotografia ainda não visitado; os que deixaram de precisar de conferência são registrados e pulados. */
    public static function nextId(PDO $pdo, array &$s): ?int
    {
        $ctx = Funnel::context($pdo);
        foreach ($s['ids'] as $id) {
            if (isset($s['done'][$id])) {
                continue;
            }
            if (($why = self::needsCheck($pdo, (int)$id, $ctx)) !== null) {
                $s['done'][$id] = $why; // mudou fora da sequência (outra aba, automação, arquivado)
                continue;
            }
            return (int)$id;
        }
        $s['finished'] = true;
        return null;
    }

    /** @return array{0:int,1:int} [posição atual (1…N), total] */
    public static function progress(array $s, ?int $id): array
    {
        $n = count($s['ids']);
        $visited = count($s['done']);
        $k = $id !== null && !isset($s['done'][$id]) ? $visited + 1 : $visited;
        return [min(max(1, $k), max(1, $n)), $n];
    }

    /** Último visitado antes do atual (para "Voltar ao anterior", que só REVISITA). */
    public static function previous(array $s, int $id): ?int
    {
        $order = array_values(array_filter($s['order'], static fn($x) => $x !== $id && in_array($s['done'][$x] ?? '', ['salvo', 'pulado'], true)));
        return $order ? (int)end($order) : null;
    }

    public static function inSequence(?array $s, int $id, string $seqId): bool
    {
        return $s !== null && hash_equals((string)$s['id'], $seqId) && in_array($id, $s['ids'], true);
    }

    /** @return array<string,int> contagem por resultado */
    public static function tally(array $s): array
    {
        $t = array_fill_keys(array_keys(self::DONE), 0);
        foreach ($s['done'] as $how) {
            $t[$how] = ($t[$how] ?? 0) + 1;
        }
        return $t;
    }

    /** @return list<int> */
    public static function skipped(array $s): array
    {
        return array_values(array_map('intval', array_keys(array_filter($s['done'], static fn($h) => $h === 'pulado'))));
    }
}
