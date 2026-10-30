<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Consentimento (autorização) e pausa por cliente. Tudo fica em tabelas gr_*;
 * o cadastro do cliente no painel não é alterado.
 */
final class Contacts
{
    private Db $db;
    private Clock $clock;

    public const OPT_OUT_WORDS = ['SAIR', 'PARAR', 'STOP', 'CANCELAR', 'DESCADASTRAR', 'NAO QUERO', 'NÃO QUERO'];

    public function __construct(Db $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    public function get(int $clientId): array
    {
        return $this->db->one('SELECT * FROM gr_contatos WHERE cliente_id = ?', [$clientId]) ?? [
            'cliente_id' => $clientId, 'consentimento' => 'nenhum', 'telefone_consentido' => null,
            'consentimento_origem' => null, 'consentimento_em' => null, 'revogado_em' => null,
            'pausado' => 0, 'pausado_motivo' => null,
        ];
    }

    public function many(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->all("SELECT * FROM gr_contatos WHERE cliente_id IN ($in)", array_values($ids)) as $r) {
            $out[(int)$r['cliente_id']] = $r;
        }
        return $out;
    }

    public function grant(int $clientId, string $phoneE164, string $origin, string $note = ''): void
    {
        $now = $this->now();
        $this->db->tx(function (Db $db) use ($clientId, $phoneE164, $origin, $note, $now) {
            $db->exec(
                "INSERT INTO gr_contatos (cliente_id, consentimento, telefone_consentido, consentimento_origem, consentimento_em, revogado_em, atualizado_em)
                 VALUES (?, 'concedido', ?, ?, ?, NULL, ?)
                 ON DUPLICATE KEY UPDATE consentimento='concedido', telefone_consentido=VALUES(telefone_consentido),
                   consentimento_origem=VALUES(consentimento_origem), consentimento_em=VALUES(consentimento_em), revogado_em=NULL, atualizado_em=VALUES(atualizado_em)",
                [$clientId, $phoneE164, mb_substr($origin, 0, 120), $now, $now]
            );
            $this->logAction($clientId, 'concedido', $phoneE164, $origin, $note);
        });
    }

    public function revoke(int $clientId, string $origin, string $note = ''): void
    {
        $now = $this->now();
        $this->db->tx(function (Db $db) use ($clientId, $origin, $note, $now) {
            $db->exec(
                "INSERT INTO gr_contatos (cliente_id, consentimento, revogado_em, atualizado_em) VALUES (?, 'revogado', ?, ?)
                 ON DUPLICATE KEY UPDATE consentimento='revogado', revogado_em=VALUES(revogado_em), atualizado_em=VALUES(atualizado_em)",
                [$clientId, $now, $now]
            );
            $this->logAction($clientId, 'revogado', null, $origin, $note);
        });
    }

    /** Descadastramento pelo próprio cliente (resposta "SAIR"): revoga todos os clientes com esse telefone. */
    public function revokeByPhone(string $phoneE164, string $origin): array
    {
        $variants = Format::phoneVariants($phoneE164);
        $in = implode(',', array_fill(0, count($variants), '?'));
        $ids = array_map('intval', array_column(
            $this->db->all("SELECT cliente_id FROM gr_contatos WHERE telefone_consentido IN ($in) AND consentimento = 'concedido'", $variants),
            'cliente_id'
        ));
        foreach ($ids as $id) {
            $this->revoke($id, $origin, 'Descadastramento solicitado pelo cliente');
        }
        return $ids;
    }

    public function setPaused(int $clientId, bool $paused, string $reason = ''): void
    {
        $now = $this->now();
        $this->db->exec(
            'INSERT INTO gr_contatos (cliente_id, pausado, pausado_motivo, atualizado_em) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE pausado=VALUES(pausado), pausado_motivo=VALUES(pausado_motivo), atualizado_em=VALUES(atualizado_em)',
            [$clientId, $paused ? 1 : 0, $paused ? mb_substr($reason, 0, 190) : null, $now]
        );
        $this->logAction($clientId, $paused ? 'pausado' : 'retomado', null, 'painel', $reason);
    }

    public function history(int $clientId): array
    {
        return $this->db->all('SELECT acao, telefone, origem, observacao, registrado_em FROM gr_consentimento_log WHERE cliente_id = ? ORDER BY id DESC LIMIT 50', [$clientId]);
    }

    private function logAction(int $clientId, string $action, ?string $phone, string $origin, string $note): void
    {
        $this->db->exec(
            'INSERT INTO gr_consentimento_log (cliente_id, acao, telefone, origem, observacao, registrado_em) VALUES (?,?,?,?,?,?)',
            [$clientId, $action, $phone, mb_substr($origin, 0, 120), mb_substr($note, 0, 255) ?: null, $this->now()]
        );
    }

    /**
     * Pedido de descadastro: mensagem curta (até 60 caracteres) com a palavra-chave como palavra inteira,
     * ou "não quero (mais) receber". Frases longas não disparam (evita falso positivo).
     */
    public static function isOptOut(string $text): bool
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($t === '' || mb_strlen($t) > 60) {
            return false;
        }
        $t = mb_strtoupper(strtr($t, ['ã' => 'a', 'Ã' => 'A', 'á' => 'a', 'Á' => 'A']));
        if (in_array(trim(preg_replace('/[^\p{L} ]/u', '', $t) ?? ''), self::OPT_OUT_WORDS, true)) {
            return true;
        }
        return (bool)preg_match('/\b(SAIR|PARAR|PARE|STOP|DESCADASTRAR|DESCADASTRE)\b|\bNAO QUERO (MAIS )?RECEBER\b/u', $t);
    }
}
