<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Diagnóstico e patch MÍNIMO do .htaccess para liberar SOMENTE:
 *   renovacoes.php, renovacoes-api.php, renovacoes-webhook.php
 * quando existe uma regra que bloqueia PHP e libera apenas o api.php.
 *
 * Nunca libera PHP em geral. Dois formatos reconhecidos:
 *  A) blocos <Files>/<FilesMatch>: um bloco nega PHP e outro libera api.php →
 *     inserimos, logo após a exceção do api.php, uma CÓPIA dela restrita aos 3 arquivos (mesmas diretivas, mesmo estilo).
 *  B) mod_rewrite: RewriteRule ... [F] que bloqueia .php (com exceção para api.php) →
 *     inserimos, antes da regra de bloqueio, uma regra que deixa passar só os 3 arquivos, ancorada na pasta do painel.
 * Formato não reconhecido = NÃO altera nada e explica o que fazer.
 */
final class HtaccessPatch
{
    public const BEGIN = '# BEGIN GMAX Renovacoes (liberar somente os 3 arquivos do modulo)';
    public const END = '# END GMAX Renovacoes';
    public const FILES = ['renovacoes.php', 'renovacoes-api.php', 'renovacoes-webhook.php'];
    public const FILES_REGEX = '^renovacoes(-api|-webhook)?\.php$';

    /** Arquivos que NÃO podem ser liberados pelo patch (controle). */
    public const MUST_STAY = ['renovacoes-caminho.php', 'teste.php', 'shell.php', 'renovacoes.php.bak', 'xrenovacoes.php'];

    private static function apacheRegexMatches(string $pattern, string $name): bool
    {
        $re = '~' . str_replace('~', '\~', $pattern) . '~i';
        return @preg_match($re, $name) === 1;
    }

    /** Blocos <Files>/<FilesMatch> com posição, padrão e tipo (nega/libera). */
    public static function fileBlocks(string $h): array
    {
        preg_match_all('#<(Files|FilesMatch)\s+(~\s+)?"?([^">]+?)"?\s*>(.*?)</\1\s*>#is', $h, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m as $b) {
            $tag = strtolower($b[1][0]);
            $isRegex = $tag === 'filesmatch' || trim($b[2][0] ?? '') === '~';
            $pattern = $b[3][0];
            $body = $b[4][0];
            $deny = (bool)preg_match('~^\s*(Require\s+all\s+denied|Deny\s+from\s+all)\s*$~im', $body);
            $grant = (bool)preg_match('~^\s*(Require\s+all\s+granted|Allow\s+from\s+all)\s*$~im', $body);
            $out[] = [
                'inicio' => $b[0][1],
                'fim' => $b[0][1] + strlen($b[0][0]),
                'texto' => $b[0][0],
                'regex' => $isRegex,
                'padrao' => $pattern,
                'corpo' => $body,
                'nega' => $deny && !$grant,
                'libera' => $grant && !$deny,
            ];
        }
        return $out;
    }

    private static function blockMatches(array $b, string $file): bool
    {
        if ($b['regex']) {
            return self::apacheRegexMatches($b['padrao'], $file);
        }
        // <Files> sem ~ aceita curingas * e ?
        return fnmatch(strtolower($b['padrao']), strtolower($file));
    }

    /**
     * Analisa um .htaccess. $relPainel = caminho da pasta do painel relativo à pasta deste .htaccess ('' se for a mesma).
     * @return array{formato:string, bloqueia:string[], aplicado:bool, plano:?string, motivo:string}
     */
    public static function analyze(string $h, string $relPainel = ''): array
    {
        $applied = strpos($h, self::BEGIN) !== false;
        $blocks = self::fileBlocks($h);
        // Avalia blocos em ordem (o último que casa vence, como no Apache sem AuthMerging).
        $blockedBy = [];
        foreach (array_merge(self::FILES, ['api.php']) as $f) {
            $state = null;
            foreach ($blocks as $b) {
                if (($b['nega'] || $b['libera']) && self::blockMatches($b, $f)) {
                    $state = $b['nega'] ? 'nega' : 'libera';
                }
            }
            if ($state === 'nega') {
                $blockedBy[$f] = 'Files';
            }
        }
        $rw = self::rewriteBlock($h, $relPainel);
        if ($rw) {
            foreach (self::FILES as $f) {
                if (self::rewriteBlocks($rw, ($relPainel !== '' ? rtrim($relPainel, '/') . '/' : '') . $f) && !$applied) {
                    $blockedBy[$f] = $blockedBy[$f] ?? 'Rewrite';
                }
            }
        }
        $blockedOurs = array_values(array_intersect(self::FILES, array_keys($blockedBy)));
        $res = ['formato' => 'nenhum', 'bloqueia' => $blockedOurs, 'aplicado' => $applied, 'plano' => null, 'motivo' => ''];
        if (isset($blockedBy['api.php'])) {
            $res['motivo'] = 'Este .htaccess também bloqueia o api.php pela análise automática. Formato não reconhecido com segurança: nada será alterado; envie o arquivo para análise.';
            $res['formato'] = 'desconhecido';
            return $res;
        }
        if (!$blockedOurs) {
            $res['motivo'] = $applied ? 'Liberação do módulo já aplicada.' : 'Nenhuma regra deste arquivo bloqueia os arquivos do módulo. Nenhuma alteração necessária.';
            return $res;
        }
        $viaFiles = array_filter($blockedOurs, static fn($f) => $blockedBy[$f] === 'Files');
        if ($viaFiles) {
            $res['formato'] = 'Files';
            // Exceções que liberam api.php sem liberar PHP em geral.
            $grants = array_values(array_filter($blocks, fn($b) => $b['libera'] && self::blockMatches($b, 'api.php') && !self::blockMatches($b, 'qualquer-outro.php')));
            $denyIdx = max(array_keys(array_filter($blocks, fn($b) => $b['nega'] && self::blockMatches($b, 'renovacoes.php'))));
            $grants = array_values(array_filter($grants, fn($g) => $g['inicio'] > $blocks[$denyIdx]['inicio']));
            if (!$grants) {
                $res['motivo'] = 'Há bloqueio de PHP por <Files>/<FilesMatch>, mas não encontrei a exceção do api.php depois dele. Nada será alterado.';
                return $res;
            }
            $plan = $h;
            foreach (array_reverse($grants) as $g) {
                $copy = preg_replace('#^<(Files|FilesMatch)\s+(~\s+)?"?[^">]+?"?\s*>#i', '<FilesMatch "' . self::FILES_REGEX . '">', $g['texto']);
                $copy = preg_replace('~</(Files|FilesMatch)\s*>$~i', '</FilesMatch>', $copy);
                $plan = self::insertAfterLine($plan, $g['fim'], self::BEGIN . "\n" . $copy . "\n" . self::END . "\n");
            }
            $res['plano'] = $plan;
            return $res;
        }
        // Formato rewrite
        $res['formato'] = 'Rewrite';
        if (!$rw) {
            $res['formato'] = 'desconhecido';
            $res['motivo'] = 'Formato não reconhecido. Nada será alterado.';
            return $res;
        }
        $anchor = '^' . ($relPainel !== '' ? preg_quote(rtrim($relPainel, '/'), '') . '/' : '') . 'renovacoes(-api|-webhook)?\.php$';
        $ins = self::BEGIN . "\nRewriteRule " . $anchor . " - [L]\n" . self::END . "\n";
        $res['plano'] = substr($h, 0, $rw['inicio_cadeia']) . $ins . substr($h, $rw['inicio_cadeia']);
        return $res;
    }

    /** Localiza a regra RewriteRule [F] que bloqueia .php e o início da sua cadeia de RewriteCond. */
    private static function rewriteBlock(string $h, string $rel = ''): ?array
    {
        if (!preg_match('~^\s*RewriteEngine\s+On~im', $h)) {
            return null;
        }
        $lines = preg_split('~(?<=\n)~', $h);
        $offset = 0;
        $chainStart = null;
        $conds = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('~^RewriteCond\s+(\S+)\s+(\S+)(\s+\[([^\]]*)\])?~i', $t, $c)) {
                if ($chainStart === null) {
                    $chainStart = $offset;
                }
                $conds[] = ['var' => $c[1], 'pat' => $c[2], 'flags' => strtoupper($c[4] ?? '')];
            } elseif (preg_match('~^RewriteRule\s+(\S+)\s+(\S+)(\s+\[([^\]]*)\])?~i', $t, $r)) {
                $flags = strtoupper($r[4] ?? '');
                if ((preg_match('~(^|,)\s*F\s*(,|$)~', $flags) || preg_match('~(^|,)\s*G\s*(,|$)~', $flags)) && (self::apacheRegexMatches($r[1], 'x.php') || self::apacheRegexMatches($r[1], ($rel !== '' ? rtrim($rel, '/') . '/' : '') . 'x.php'))) {
                    return ['inicio_cadeia' => $chainStart ?? $offset, 'padrao' => $r[1], 'conds' => $conds];
                }
                $chainStart = null;
                $conds = [];
            } elseif ($t !== '' && $t[0] !== '#') {
                $chainStart = null;
                $conds = [];
            }
            $offset += strlen($line);
        }
        return null;
    }

    /** A regra de bloqueio (com suas condições de exceção) bloquearia este caminho? Avaliação simplificada. */
    private static function rewriteBlocks(array $rw, string $path): bool
    {
        if (!self::apacheRegexMatches($rw['padrao'], $path) && !self::apacheRegexMatches($rw['padrao'], basename($path))) {
            return false;
        }
        foreach ($rw['conds'] as $c) {
            if (strpos($c['flags'], 'OR') !== false) {
                return true; // cadeia com OR: tratamos como bloqueio (conservador)
            }
            $neg = $c['pat'][0] === '!';
            $pat = $neg ? substr($c['pat'], 1) : $c['pat'];
            if (stripos($c['var'], 'REQUEST_FILENAME') !== false && in_array($pat, ['-f', '-d', '-s', '-l'], true)) {
                continue; // arquivo existe: verdadeiro para nossos arquivos
            }
            $m = self::apacheRegexMatches($pat, '/' . $path) || self::apacheRegexMatches($pat, basename($path));
            if ($neg ? $m : !$m) {
                return false; // condição falha → regra não se aplica
            }
        }
        return true;
    }

    /** Insere $block no início da linha seguinte a $pos (ou no fim do arquivo). */
    private static function insertAfterLine(string $h, int $pos, string $block): string
    {
        $nl = strpos($h, "\n", $pos);
        if ($nl === false) {
            return $h . "\n" . rtrim($block, "\n"); // arquivo sem quebra final
        }
        return substr($h, 0, $nl + 1) . $block . substr($h, $nl + 1);
    }

    /** Remove exatamente o que foi inserido (reversão byte a byte). */
    public static function revert(string $h): string
    {
        $b = preg_quote(self::BEGIN, '~');
        $e = preg_quote(self::END, '~');
        $h = preg_replace('~(?<=\n|^)' . $b . '.*?' . $e . '\n~s', '', $h) ?? $h;
        return preg_replace('~\n' . $b . '.*?' . $e . '$~s', '', $h) ?? $h;
    }
}
