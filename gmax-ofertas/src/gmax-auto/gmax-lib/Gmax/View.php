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
            $seen = $r['price_seen_at'] ? ' (fonte: ' . self::e(Time::show($r['price_seen_at'] . ' 12:00:00', 'd/m/Y')) . ')' : '';
            return '<span class="fresh fresh-no">Preço não conferido' . $seen . '</span>';
        }
        $cls = $age <= $maxHours ? 'fresh-ok' : 'fresh-old';
        return '<span class="fresh ' . $cls . '">Conferido ' . self::e(Time::show($r['price_verified_at'])) . ($age > $maxHours ? ' — desatualizado' : '') . '</span>';
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
