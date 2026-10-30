<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Acrescenta o link "Renovações" ao menu do painel (index.html) SEM substituir o arquivo:
 * confere o ponto de inserção no arquivo REAL do servidor, faz cópia de segurança e insere 2 trechos.
 * app.js, api.php, style.css e plans.css não são alterados.
 */
final class PanelMenuPatch
{
    public const MARK = 'gr-menu-link';
    public const LINK = '<a class="nav gr-menu-link" href="renovacoes.php"><span>⏰</span>Renovações</a>';
    public const CSS = '<link rel="stylesheet" href="renovacoes-assets/menu.css?v=1">';

    /** @return array{ok:bool, motivo:string, novo:?string} */
    public static function plan(string $html): array
    {
        if (strpos($html, self::MARK) !== false) {
            return ['ok' => false, 'motivo' => 'O link de Renovações já existe neste index.html.', 'novo' => null];
        }
        // Fim do menu: o </nav> que vem logo depois dos botões data-page.
        if (preg_match_all('~(<button[^>]*data-page="[a-z]+"[^>]*>.*?</button>)(\s*</nav>)~s', $html, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return ['ok' => false, 'motivo' => 'Ponto de inserção do menu não encontrado exatamente uma vez. Nada foi alterado; envie o index.html atual para análise.', 'novo' => null];
        }
        if (substr_count($html, '</head>') !== 1) {
            return ['ok' => false, 'motivo' => '</head> não encontrado exatamente uma vez. Nada foi alterado.', 'novo' => null];
        }
        $navEnd = $m[2][0][1];
        $new = substr($html, 0, $navEnd) . self::LINK . substr($html, $navEnd);
        $new = str_replace('</head>', self::CSS . "\n</head>", $new);
        return ['ok' => true, 'motivo' => '', 'novo' => $new];
    }

    public static function revert(string $html): string
    {
        return str_replace([self::LINK, self::CSS . "\n"], '', $html);
    }

    /** Diferença simples linha a linha (para revisão antes de aplicar). */
    public static function diff(string $a, string $b): string
    {
        $la = explode("\n", $a);
        $lb = explode("\n", $b);
        $out = [];
        $n = max(count($la), count($lb));
        for ($i = 0; $i < $n; $i++) {
            $x = $la[$i] ?? null;
            $y = $lb[$i] ?? null;
            if ($x === $y) {
                continue;
            }
            if ($x !== null && !in_array($x, $lb, true)) {
                $out[] = '- ' . mb_strimwidth($x, 0, 220, '…');
            }
            if ($y !== null && !in_array($y, $la, true)) {
                $out[] = '+ ' . mb_strimwidth($y, 0, 220, '…');
            }
        }
        return implode("\n", array_unique($out));
    }
}
