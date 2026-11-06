<?php
declare(strict_types=1);

namespace Gmax;

/**
 * Regras determinísticas de texto (3.2.2), só para LEITURA administrativa: o título original
 * nunca é alterado no banco.
 * - shortTitle: remove repetições e termos genéricos de anúncio; nunca descarta números
 *   (modelo, capacidade, voltagem) nem a primeira palavra (normalmente a marca ou o produto).
 * - similarity: semelhança entre títulos (conjuntos de palavras), para sinalizar possíveis duplicados.
 * - segment: segmento comercial por palavras-chave (Tecnologia, Ferramentas, Casa…).
 */
final class Text
{
    private const GENERIC = ['original', 'originais', 'promocao', 'promoção', 'oferta', 'imperdivel', 'imperdível', 'lancamento', 'lançamento', 'envio', 'imediato',
        'pronta', 'entrega', 'frete', 'gratis', 'grátis', 'top', 'premium', 'qualidade', 'melhor', 'barato', 'novo', 'nova', 'novos', 'brasil', 'nf', 'nota', 'fiscal',
        'garantia', 'oficial', 'super', 'mega', 'ultra', 'hot', 'sale', 'black', 'friday', 'liquidacao', 'liquidação', 'queima', 'estoque', 'unidade', 'un', 'pronto'];
    private const STOP = ['de', 'da', 'do', 'das', 'dos', 'para', 'pra', 'com', 'sem', 'e', 'em', 'a', 'o', 'as', 'os', 'um', 'uma', 'kit', 'cor', 'cores', 'na', 'no', 'por', '-', '+', '/', '|'];
    public const SEGMENTS = [
        'tecnologia' => ['Tecnologia', ['fone', 'bluetooth', 'celular', 'smartphone', 'notebook', 'tablet', 'mouse', 'teclado', 'monitor', 'carregador', 'cabo', 'usb', 'hdmi', 'smartwatch', 'relogio inteligente', 'caixa de som', 'som', 'gamer', 'ssd', 'pendrive', 'roteador', 'wifi', 'camera', 'tv', 'alexa', 'echo', 'headset', 'power bank', 'microfone']],
        'ferramentas' => ['Ferramentas', ['parafusadeira', 'furadeira', 'ferramenta', 'chave', 'alicate', 'serra', 'esmerilhadeira', 'lixadeira', 'trena', 'nivel', 'martelo', 'broca', 'compressor', 'solda', 'bateria 18v', 'jogo de soquetes', 'maleta', 'multimetro', 'lavadora de alta pressao']],
        'casa' => ['Casa e organização', ['organizador', 'cozinha', 'panela', 'air fryer', 'fritadeira', 'garrafa', 'termica', 'pote', 'cama', 'travesseiro', 'toalha', 'cortina', 'tapete', 'luminaria', 'lampada', 'aspirador', 'vassoura', 'cabide', 'prateleira', 'banheiro', 'jogo de lencol', 'liquidificador', 'cafeteira', 'mop', 'utensilio']],
        'beleza' => ['Beleza', ['perfume', 'maquiagem', 'batom', 'base', 'secador', 'chapinha', 'prancha', 'escova', 'creme', 'hidratante', 'shampoo', 'condicionador', 'skincare', 'serum', 'protetor solar', 'barbeador', 'aparador', 'depilador', 'esmalte', 'cilios']],
        'infantil' => ['Maternidade e infantil', ['bebe', 'bebê', 'infantil', 'crianca', 'criança', 'fralda', 'mamadeira', 'chupeta', 'carrinho de bebe', 'berco', 'berço', 'brinquedo', 'boneca', 'lego', 'pelucia', 'maternidade', 'gestante', 'babador']],
        'utilidades' => ['Utilidades', ['mochila', 'bolsa', 'carteira', 'guarda-chuva', 'garrafa de agua', 'lanterna', 'pilha', 'cadeado', 'adaptador', 'extensao', 'suporte', 'capa', 'pelicula', 'copo', 'marmita', 'necessaire', 'mala', 'balanca']],
    ];

    public static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return is_string($t) && $t !== '' ? strtolower($t) : $s;
    }

    public static function shortTitle(string $title, int $max = 70): string
    {
        $title = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title)));
        $words = preg_split('/\s+/u', $title) ?: [];
        $seen = [];
        $keep = [];
        foreach ($words as $i => $w) {
            $k = self::fold(trim($w, ".,;:!?()[]{}\"'*"));
            if ($k === '' || isset($seen[$k])) {
                continue; // repetição
            }
            $hasDigit = preg_match('/\d/', $w) === 1;
            if ($i > 0 && !$hasDigit && in_array($k, self::GENERIC, true)) {
                continue; // termo genérico de anúncio
            }
            $seen[$k] = true;
            $keep[] = $w;
        }
        $keep = array_values(array_filter($keep, static fn($w) => !in_array($w, ['-', '|', '/', '+'], true)));
        $out = '';
        $dropped = [];
        foreach ($keep as $w) {
            if (mb_strlen($out . ' ' . $w) > $max && $out !== '') {
                $dropped[] = $w;
                continue;
            }
            $out = trim($out . ' ' . $w);
        }
        // Números (modelo, capacidade, voltagem) nunca somem: os cortados voltam ao final.
        $nums = array_values(array_filter($dropped, static fn($w) => preg_match('/\d/', $w) === 1));
        if ($nums) {
            $out .= ' … ' . implode(' ', $nums);
        } elseif ($dropped) {
            $out .= '…';
        }
        return $out;
    }

    /** @return array<string,true> palavras significativas */
    public static function tokens(string $title): array
    {
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', self::fold($title)) ?: [] as $w) {
            if (strlen($w) >= 2 && !in_array($w, self::STOP, true) && !in_array($w, self::GENERIC, true)) {
                $out[$w] = true;
            }
        }
        return $out;
    }

    /** Semelhança de Jaccard (0–1) entre dois títulos. */
    public static function similarity(string $a, string $b): float
    {
        $x = self::tokens($a);
        $y = self::tokens($b);
        if (!$x || !$y) {
            return 0.0;
        }
        $inter = count(array_intersect_key($x, $y));
        return $inter / (count($x) + count($y) - $inter);
    }

    public static function segment(string $title, ?string $category = null): ?string
    {
        $t = ' ' . self::fold($title . ' ' . (string)$category) . ' ';
        $best = null;
        $score = 0;
        foreach (self::SEGMENTS as $k => [, $words]) {
            $n = 0;
            foreach ($words as $w) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote(self::fold($w), '/') . '(?![a-z0-9])/', $t)) {
                    $n++;
                }
            }
            if ($n > $score) {
                [$best, $score] = [$k, $n];
            }
        }
        return $best;
    }
}
