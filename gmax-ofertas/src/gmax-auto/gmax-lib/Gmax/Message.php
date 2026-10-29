<?php
declare(strict_types=1);

namespace Gmax;

/**
 * Modelo de publicação GMAX. Texto simples (sem parse_mode), portanto o conteúdo do
 * produto nunca é interpretado como formatação pelo Telegram.
 * "De", "% OFF" e "Economize" só aparecem quando há preço original real e maior.
 */
final class Message
{
    public const DISCLAIMER = 'Preços e disponibilidade podem mudar a qualquer momento. Confira as condições na loja antes de comprar. Link de afiliado GMAX.';

    public static function render(array $r): string
    {
        $store = mb_strtoupper(Url::MARKETPLACES[$r['marketplace']] ?? 'LOJA', 'UTF-8');
        $title = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$r['title']));
        $orig = isset($r['price_original_cents']) ? (int)$r['price_original_cents'] : null;
        $promo = isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null;
        $lines = ["GMAX OFERTAS | $store", '', $title, ''];
        $discount = Money::discount($orig, $promo);
        if ($discount) {
            $lines[] = 'De: ' . Money::format($orig);
        }
        $lines[] = 'Por: ' . ($promo ? Money::format($promo) : 'preço não confirmado');
        if ($discount && $discount['percent'] >= 1) {
            $lines[] = $discount['percent'] . '% OFF';
        }
        if ($discount) {
            $lines[] = 'Economize ' . Money::format($discount['savings']);
        }
        if ($cond = self::condition($r)) {
            $lines[] = '';
            $lines[] = 'Condição: ' . $cond;
        }
        $lines[] = '';
        $lines[] = (string)($r['affiliate_url'] ?? '[link afiliado ausente]');
        $lines[] = '';
        $lines[] = self::DISCLAIMER;
        return implode("\n", $lines);
    }

    public static function condition(array $r): ?string
    {
        $note = trim((string)($r['payment_note'] ?? ''));
        $base = match ($r['payment_condition'] ?? null) {
            'pix' => 'pagamento via Pix',
            'cupom' => 'preço com cupom',
            'cartao' => 'preço no cartão',
            'outro' => null,
            default => null,
        };
        if ($base && $note !== '') {
            return "$base ($note)";
        }
        return $base ?? ($note !== '' ? $note : null);
    }
}
