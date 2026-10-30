<?php
declare(strict_types=1);

namespace Gmax;

/**
 * Verifica para onde um link curto meli.la realmente aponta, seguindo redirecionamentos
 * um a um (sem baixar páginas) e comparando o ID do anúncio encontrado com o esperado.
 * O formato correto do link NÃO é considerado prova de validade.
 */
final class LinkVerifier
{
    public const CONFERE = 'confere';
    public const DIVERGE = 'diverge';
    public const INDETERMINADO = 'indeterminado';

    private const ALLOWED_HOSTS = '/(^|\.)(meli\.la|mercadolivre\.com\.br|mercadolibre\.com)$/';

    public function __construct(private Http $http = new Http())
    {
    }

    /**
     * @param string $expected ID esperado, ex.: "item:MLB4047815501" ou "catalogo:MLB30144703"
     * @return array{resultado:string,detalhe:string,destino:?string}
     */
    public function check(string $url, string $expected): array
    {
        $current = $url;
        for ($hop = 0; $hop < 5; $hop++) {
            $host = strtolower((string)parse_url($current, PHP_URL_HOST));
            if (!preg_match(self::ALLOWED_HOSTS, $host) || strtolower((string)parse_url($current, PHP_URL_SCHEME)) !== 'https') {
                return self::result(self::INDETERMINADO, 'Redirecionamento para domínio não esperado: ' . $host, $current);
            }
            $found = self::idsIn($current);
            if ($found) {
                return self::compare($found, $expected, $current);
            }
            try {
                $r = $this->http->probe($current);
            } catch (\Throwable $e) {
                return self::result(self::INDETERMINADO, 'Consulta não realizada: ' . $e->getMessage(), null);
            }
            if ($r['error'] !== null) {
                return self::result(self::INDETERMINADO, 'Sem resposta do link (rede/timeout)', null);
            }
            if ($r['status'] === 404 || $r['status'] === 410) {
                return self::result(self::DIVERGE, "Link inexistente (HTTP {$r['status']})", $current);
            }
            if ($r['status'] < 300 || $r['status'] >= 400 || !$r['location']) {
                return self::result(self::INDETERMINADO, "Destino final sem ID de anúncio identificável (HTTP {$r['status']})", $current);
            }
            $next = $r['location'];
            if (str_starts_with($next, '/')) {
                $next = 'https://' . $host . $next;
            }
            $current = $next;
        }
        return self::result(self::INDETERMINADO, 'Redirecionamentos demais', $current);
    }

    /** @return list<string> IDs encontrados na URL, no formato item:MLB… ou catalogo:MLB… */
    public static function idsIn(string $url): array
    {
        $decoded = rawurldecode($url);
        $ids = [];
        if (preg_match_all('~/p/(MLB\d{5,15})~', $decoded, $m)) {
            foreach ($m[1] as $id) {
                $ids[] = 'catalogo:' . $id;
            }
        }
        if (preg_match_all('~(?<![A-Za-z0-9])(MLB)-?(\d{6,15})(?!\d)~', preg_replace('~/p/MLB\d+~', '', $decoded), $m)) {
            foreach ($m[2] as $n) {
                $ids[] = 'item:MLB' . $n;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function compare(array $found, string $expected, string $dest): array
    {
        if (in_array($expected, $found, true)) {
            return self::result(self::CONFERE, 'O link leva ao anúncio ' . substr($expected, strpos($expected, ':') + 1), $dest);
        }
        $sameKind = array_filter($found, static fn($f) => strtok($f, ':') === strtok($expected, ':'));
        if ($sameKind) {
            return self::result(self::DIVERGE, 'O link leva a OUTRO anúncio: ' . implode(', ', $sameKind), $dest);
        }
        return self::result(self::INDETERMINADO, 'O link leva a ' . implode(', ', $found) . ' (tipo diferente do cadastrado: confira manualmente)', $dest);
    }

    private static function result(string $r, string $detail, ?string $dest): array
    {
        return ['resultado' => $r, 'detalhe' => $detail, 'destino' => $dest === null ? null : mb_substr($dest, 0, 300)];
    }
}
