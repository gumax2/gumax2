<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Configurações NÃO secretas, editáveis pela aba Renovações (gr_configuracoes).
 * Tokens e segredos ficam somente no config.php privado.
 */
class Settings
{
    protected Db $db;
    protected Clock $clock;
    protected ?array $cache = null;

    public function __construct(Db $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    public static function defaults(): array
    {
        return [
            // Módulo desligado e em simulação até você ativar.
            'ativo' => false,
            'ativado_em' => null,
            'modo' => 'simulacao',            // 'simulacao' | 'real'
            'pausa_global' => false,
            'horario' => '09:00',             // a partir de quando os lembretes do dia são gerados/enviados
            'janela_fim' => '20:00',          // depois disso não envia mais (retentativas param)
            'max_tentativas' => 3,
            'etapas' => [
                ['codigo' => 'antes', 'dias' => -3, 'ativo' => true],
                ['codigo' => 'dia', 'dias' => 0, 'ativo' => true],
                ['codigo' => 'depois', 'dias' => 1, 'ativo' => true],
            ],
            'chave_pix' => 'gumaxskins@gmail.com',
            'instrucoes_pagamento' => 'Pix (chave e-mail): {chave_pix}',
            'pagamento_confirmado' => false,
            'pagamento_confirmado_em' => null,
            'rodape' => 'Para não receber mais estes lembretes, responda SAIR.',
            'numeros_teste' => [],
            'somente_numeros_teste' => true,  // 1º teste real: só envia para contatos internos autorizados
            'textos' => [
                'antes' => [
                    'individual' => "Olá, {nome}! 😊 Seu pacote {pacote} vence em {data}, daqui a {dias} dias.\n\nO valor da renovação é {valor}.\n\nJá estamos passando para você se organizar. Para renovar, utilize as instruções de pagamento abaixo:\n{instrucoes_pagamento}\n\nApós pagar, envie o comprovante por aqui para confirmarmos. Se já renovou, fale conosco para conferirmos o cadastro.",
                    'grupo' => "Olá, {nome}! 😊 Os seus pacotes abaixo vencem em {data}, daqui a {dias} dias:\n{lista_pacotes}\n\nO valor total da renovação é {total}.\n\nJá estamos passando para você se organizar. Para renovar, utilize as instruções de pagamento abaixo:\n{instrucoes_pagamento}\n\nApós pagar, envie o comprovante por aqui para confirmarmos. Se já renovou, fale conosco para conferirmos o cadastro.",
                ],
                'dia' => [
                    'individual' => "Olá, {nome}! 📅 Seu pacote {pacote} vence hoje, {data}.\n\nO valor para renovação é {valor}.\n{instrucoes_pagamento}\n\nApós o pagamento, envie o comprovante para confirmarmos sua renovação. Se já pagou, avise por aqui.",
                    'grupo' => "Olá, {nome}! 📅 Os seus pacotes abaixo vencem hoje, {data}:\n{lista_pacotes}\n\nO valor total para renovação é {total}.\n{instrucoes_pagamento}\n\nApós o pagamento, envie o comprovante para confirmarmos sua renovação. Se já pagou, avise por aqui.",
                ],
                'depois' => [
                    'individual' => "Olá, {nome}! O vencimento cadastrado do pacote {pacote} foi ontem, {data}, e ainda não registramos uma nova data de renovação.\n\nO valor para renovar é {valor}.\n{instrucoes_pagamento}\n\nSe já pagou, envie o comprovante para conferirmos. Se precisar de ajuda, é só responder esta mensagem.",
                    'grupo' => "Olá, {nome}! O vencimento cadastrado dos pacotes abaixo foi ontem, {data}, e ainda não registramos uma nova data de renovação:\n{lista_pacotes}\n\nO valor total para renovar é {total}.\n{instrucoes_pagamento}\n\nSe já pagou, envie o comprovante para conferirmos. Se precisar de ajuda, é só responder esta mensagem.",
                ],
            ],
            // Nomes dos modelos aprovados na Meta (preencher depois da aprovação). Não são segredos.
            'modelos' => [
                'antes' => ['individual' => '', 'grupo' => ''],
                'dia' => ['individual' => '', 'grupo' => ''],
                'depois' => ['individual' => '', 'grupo' => ''],
            ],
            'modelo_idioma' => 'pt_BR',
        ];
    }

    public function seedDefaults(): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        foreach (self::defaults() as $k => $v) {
            $this->db->exec(
                'INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES (?,?,?)',
                [$k, json_encode($v, JSON_UNESCAPED_UNICODE), $now]
            );
        }
        $this->cache = null;
    }

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = self::defaults();
            foreach ($this->db->all('SELECT chave, valor FROM gr_configuracoes') as $r) {
                $this->cache[$r['chave']] = json_decode($r['valor'], true);
            }
        }
        return $this->cache;
    }

    public function get(string $k)
    {
        return $this->all()[$k] ?? null;
    }

    public function set(string $k, $v): void
    {
        $this->db->exec(
            'INSERT INTO gr_configuracoes (chave, valor, atualizado_em) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = VALUES(atualizado_em)',
            [$k, json_encode($v, JSON_UNESCAPED_UNICODE), $this->clock->now()->format('Y-m-d H:i:s')]
        );
        $this->cache = null;
    }

    /** Sempre relê do banco (usado na revalidação antes do envio). */
    public function refresh(): void
    {
        $this->cache = null;
    }
}
