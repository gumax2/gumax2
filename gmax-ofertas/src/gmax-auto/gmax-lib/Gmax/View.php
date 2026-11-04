<?php
declare(strict_types=1);

namespace Gmax;

/** Utilidades de renderização. Toda saída dinâmica passa por View::e(). */
final class View
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $page, array $params = []): string
    {
        $params = array_filter(['p' => $page] + $params, static fn($v) => $v !== null && $v !== '');
        return 'painel.php?' . http_build_query($params);
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e(Auth::csrf()) . '">';
    }

    /** Formulário POST de um botão só (ações rápidas). */
    public static function actionButton(string $action, array $fields, string $label, string $class = 'btn', ?string $confirm = null): string
    {
        $h = '<form method="post" action="painel.php" class="inline" data-loading' . ($confirm ? ' data-confirm="' . self::e($confirm) . '"' : '') . '>' . self::csrfField()
            . '<input type="hidden" name="action" value="' . self::e($action) . '">';
        foreach ($fields as $k => $v) {
            $h .= '<input type="hidden" name="' . self::e($k) . '" value="' . self::e($v) . '">';
        }
        return $h . '<button type="submit" class="' . self::e($class) . '">' . self::e($label) . '</button></form>';
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['gmax3_flash'][] = [$type, $message];
    }

    /** @return list<array{0:string,1:string}> */
    public static function takeFlash(): array
    {
        $f = $_SESSION['gmax3_flash'] ?? [];
        unset($_SESSION['gmax3_flash']);
        return is_array($f) ? $f : [];
    }

    public static function badge(string $status): string
    {
        $label = Catalog::STATUSES[$status] ?? Queue::STATES[$status] ?? $status;
        return '<span class="badge st-' . self::e($status) . '">' . self::e($label) . '</span>';
    }

    public static function mp(string $marketplace): string
    {
        return '<span class="mp mp-' . self::e($marketplace) . '">' . self::e(Url::MARKETPLACES[$marketplace] ?? $marketplace) . '</span>';
    }

    public static function priceBlock(array $r): string
    {
        $orig = isset($r['price_original_cents']) ? (int)$r['price_original_cents'] : null;
        $promo = isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null;
        $d = Money::discount($orig, $promo);
        $h = '<div class="price">';
        if ($d) {
            $h .= '<span class="old">' . self::e(Money::format($orig)) . '</span> ';
        }
        $h .= '<strong>' . self::e($promo ? Money::format($promo) : 'sem preço') . '</strong>';
        if ($d) {
            $h .= ' <span class="off">' . $d['percent'] . '% OFF</span>';
        }
        return $h . '</div>';
    }

    public static function freshness(array $r, int $maxHours): string
    {
        $age = Time::hoursSince($r['price_verified_at'] ?? null);
        if ($age === null) {
            $seen = !empty($r['price_seen_at']) ? ' (fonte: ' . self::e(Time::show($r['price_seen_at'] . ' 12:00:00', 'd/m/Y')) . ')' : '';
            return '<span class="fresh fresh-no">Preço não conferido' . $seen . '</span>';
        }
        $cls = $age <= $maxHours ? 'fresh-ok' : 'fresh-old';
        $src = Catalog::verificationSource($r) === 'api'
            ? '<span class="src src-api" title="Preço devolvido pela API oficial">API ' . self::e(Url::MARKETPLACES[$r['marketplace']] ?? '') . '</span> Validado'
            : '<span class="src src-manual" title="Conferido por uma pessoa na loja">Manual</span> Conferido';
        return '<span class="fresh ' . $cls . '">' . $src . ' ' . self::e(Time::show($r['price_verified_at'])) . ($age > $maxHours ? ' — desatualizado' : '') . '</span>';
    }

    /** Selo da origem do preço original: API, manual, importado ou calculado. */
    public static function originBadge(?string $origin): string
    {
        return match ($origin) {
            'api' => '<span class="src src-api">API</span>',
            'manual' => '<span class="src src-manual">Manual</span>',
            'importado' => '<span class="src src-imported" title="Veio de planilha/cadastro; ainda não conferido">Importado</span>',
            'calculado' => '<span class="src src-calc" title="Estimado a partir do % de desconto; não confirmado">Calculado ≈</span>',
            default => '',
        };
    }

    /**
     * Calculadora de preços (edição e conferência rápida): modo "preço original da loja" ou
     * "calcular pelo desconto %". Os dois campos ficam no formulário: alternar o modo só mostra
     * um ou outro (valores preservados). Sem JavaScript, os dois aparecem e vale o modo marcado.
     * @param array{promo:string,original:string,pct:string,mode:string,row:?array,estimate:?array,uid:string} $o
     */
    public static function priceCalculator(array $o): string
    {
        $row = $o['row'];
        $mode = isset(Pricing::MODES[$o['mode']]) ? $o['mode'] : 'original';
        $u = self::e($o['uid']);
        $apiOrig = $row && Pricing::originalSource($row) === 'api' ? (int)$row['price_original_cents'] : null;
        $h = '<fieldset class="calc" data-calc' . ($apiOrig ? ' data-api-original="' . $apiOrig . '"' : '') . '>'
            . '<legend>Preços</legend>'
            . '<div class="chips seg" role="radiogroup" aria-label="Como informar o preço original">';
        foreach (Pricing::MODES as $k => $label) {
            $h .= '<label><input type="radio" name="price_mode" value="' . $k . '"' . ($mode === $k ? ' checked' : '') . ' data-calc-mode> ' . self::e($label) . '</label>';
        }
        $h .= '</div><div class="form-grid calc-grid">'
            . '<label>Preço promocional (Por) *<input id="' . $u . '-promo" name="price_promo" inputmode="decimal" autocomplete="off" placeholder="119,90" data-price="promo" value="' . self::e($o['promo']) . '"></label>'
            . '<label data-mode-field="original">Preço original (De), como aparece na loja'
            . '<input id="' . $u . '-orig" name="price_original" inputmode="decimal" autocomplete="off" placeholder="vazio se não houver" data-price="orig" value="' . self::e($o['original']) . '">'
            . ($row && !empty($row['price_original_cents']) ? '<span class="small">Atual: ' . self::e(Money::format((int)$row['price_original_cents'])) . ' ' . self::originBadge(Pricing::originalSource($row)) . '</span>' : '')
            . '</label>'
            . '<label data-mode-field="desconto">Desconto exibido na loja (%)'
            . '<input id="' . $u . '-pct" name="desconto_pct" inputmode="decimal" autocomplete="off" placeholder="35" maxlength="6" data-pct value="' . self::e($o['pct']) . '"></label>'
            . '</div>'
            . '<div class="calc-result" data-calc-output aria-live="polite"></div>';
        if ($apiOrig) {
            $h .= '<p class="small" data-mode-field="desconto">O preço original <strong>confirmado pela API</strong> (' . self::e(Money::format($apiOrig)) . ') será mantido; a estimativa fica só como referência.</p>';
        } else {
            $h .= '<p class="small muted" data-mode-field="desconto">No modo desconto, o “De” fica <strong>sem valor confirmado</strong>: a estimativa é registrada à parte e nunca é usada como preço anterior nas publicações.</p>';
        }
        if ($o['estimate']) {
            $e = $o['estimate'];
            $h .= '<p class="small">Última estimativa: ≈ ' . self::e(Money::format((int)$e['de_estimado'])) . ' ' . self::originBadge('calculado') . ' a partir de ' . self::e((string)$e['percentual']) . '% · '
                . self::e(Time::show((string)$e['em'])) . '</p>';
        }
        return $h . '</fieldset>';
    }

    /** URL oficial do anúncio (para conferir na loja), só se for https do domínio da loja. */
    public static function storeUrl(array $r): ?string
    {
        $u = $r['original_url'] ?? null;
        return Url::isProduct((string)$r['marketplace'], $u) ? (string)$u : null;
    }

    /** Link afiliado oficial (o usado na divulgação), só se estiver no formato oficial. */
    public static function affiliateUrl(array $r): ?string
    {
        $u = $r['affiliate_url'] ?? null;
        return Url::isAffiliate((string)$r['marketplace'], $u) ? (string)$u : null;
    }

    /**
     * Botões "Conferir na loja" e "Abrir link afiliado", visualmente distintos.
     * Links https comuns (target=_blank): o sistema operacional decide abrir o aplicativo
     * oficial (Universal Links / App Links) ou o navegador. Sem redirecionamento intermediário.
     */
    public static function storeButtons(array $r, string $size = '', string $context = ''): string
    {
        $shopee = $r['marketplace'] === 'shopee';
        $store = self::storeUrl($r);
        $aff = self::affiliateUrl($r);
        $cls = $size !== '' ? ' ' . $size : '';
        $ctx = ($context !== '' ? '<span class="sr-only"> de ' . self::e(mb_substr($context, 0, 80)) . '</span>' : '') . '<span class="sr-only"> (abre em nova aba)</span>';
        $h = $store
            ? '<a class="btn btn-store btn-store-' . self::e($r['marketplace']) . $cls . '" href="' . self::e($store) . '" target="_blank" rel="noopener noreferrer">'
                . ($shopee ? '<span aria-hidden="true">🛍️</span> Conferir na Shopee' : '<span aria-hidden="true">🛒</span> Conferir no Mercado Livre') . $ctx . '</a>'
            : '<span class="btn btn-disabled' . $cls . '">Sem URL do anúncio</span>';
        $h .= $aff
            ? '<a class="btn btn-affiliate' . $cls . '" href="' . self::e($aff) . '" target="_blank" rel="noopener noreferrer" title="Link de divulgação: abrir registra um clique de afiliado"><span aria-hidden="true">🔗</span> Abrir link afiliado' . $ctx . '</a>'
            : '<span class="btn btn-disabled' . $cls . '">Sem link afiliado</span>';
        return '<div class="store-actions">' . $h . '</div>';
    }

    public static function pager(int $total, int $perPage, int $page, array $params, string $pageName): string
    {
        $pages = (int)ceil($total / $perPage);
        if ($pages <= 1) {
            return '';
        }
        $h = '<nav class="pager" aria-label="Paginação">';
        for ($i = 1; $i <= $pages; $i++) {
            $h .= $i === $page ? '<span class="current">' . $i . '</span>' : '<a href="' . self::e(self::url($pageName, ['page' => $i] + $params)) . '">' . $i . '</a>';
        }
        return $h . '</nav>';
    }
}
