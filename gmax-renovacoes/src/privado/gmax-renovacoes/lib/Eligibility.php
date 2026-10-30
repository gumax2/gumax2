<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Regras de elegibilidade de um pacote para um lembrete. Usado na geração E na revalidação antes do envio.
 * Retorna null quando elegível ou um código de motivo.
 */
final class Eligibility
{
    public const REASONS = [
        'pacote_inexistente' => 'Pacote não encontrado (excluído do painel)',
        'cliente_inexistente' => 'Cliente não encontrado (excluído do painel)',
        'cliente_excluido' => 'Cliente excluído/na lixeira',
        'cliente_inativo' => 'Cliente pausado ou arquivado',
        'pacote_excluido' => 'Pacote excluído',
        'pacote_inativo' => 'Pacote pausado ou arquivado',
        'renovado' => 'Vencimento alterado (renovação registrada)',
        'sem_consentimento' => 'Cliente sem autorização para lembretes',
        'consentimento_revogado' => 'Autorização revogada pelo cliente',
        'telefone_invalido' => 'Telefone ausente ou inválido',
        'telefone_mudou' => 'Telefone mudou depois da autorização (confirme novamente)',
        'cliente_pausado' => 'Lembretes pausados para este cliente',
        'valor_invalido' => 'Valor do pacote zerado ou inválido',
        'fora_do_dia' => 'Data prevista já passou (não envia atrasado)',
        'somente_teste' => 'Bloqueado: modo de teste interno (só números de teste)',
    ];

    public static function label(?string $code): string
    {
        return $code === null ? '' : (self::REASONS[$code] ?? $code);
    }

    /**
     * @param array|null $pkg linha normalizada (PanelData)
     * @param array $contact linha de gr_contatos
     * @param string|null $expectedDue vencimento do lembrete (para detectar renovação)
     */
    public static function check(?array $pkg, array $contact, ?string $expectedDue = null, array $opts = []): ?string
    {
        if ($pkg === null) {
            return 'pacote_inexistente';
        }
        if (!$pkg['cliente_existe']) {
            return 'cliente_inexistente';
        }
        if ($pkg['cliente_excluido']) {
            return 'cliente_excluido';
        }
        if (!$pkg['cliente_ativo']) {
            return 'cliente_inativo';
        }
        if ($pkg['pacote_excluido']) {
            return 'pacote_excluido';
        }
        if (!$pkg['pacote_ativo']) {
            return 'pacote_inativo';
        }
        if ($expectedDue !== null && $pkg['vencimento'] !== $expectedDue) {
            return 'renovado';
        }
        if ($pkg['valor_centavos'] <= 0) {
            return 'valor_invalido';
        }
        $phone = Format::phone($pkg['telefone']);
        if ($phone === null) {
            return 'telefone_invalido';
        }
        if (($contact['consentimento'] ?? 'nenhum') === 'revogado') {
            return 'consentimento_revogado';
        }
        if (($contact['consentimento'] ?? 'nenhum') !== 'concedido') {
            return 'sem_consentimento';
        }
        if (($contact['telefone_consentido'] ?? null) !== $phone) {
            return 'telefone_mudou';
        }
        if (!empty($contact['pausado'])) {
            return 'cliente_pausado';
        }
        if (!empty($opts['somente_teste']) && !in_array($phone, (array)($opts['numeros_teste'] ?? []), true)) {
            return 'somente_teste';
        }
        return null;
    }
}
