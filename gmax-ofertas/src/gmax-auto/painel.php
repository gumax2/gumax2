<?php
declare(strict_types=1);
/*
 * GMAX Ofertas 3.0 — Painel multiloja (Shopee + Mercado Livre).
 * Arquivo NOVO: não substitui index.php. Usa o mesmo login/sessão do painel atual.
 * Nenhuma ação deste painel envia mensagens: a fila só é processada pela CLI, e o envio
 * real exige automação autorizada + arquivo de ativação + ausência de pausa.
 */
require __DIR__ . '/gmax-lib/bootstrap.php';

use Gmax\{Audit, Auth, Backup, Catalog, Db, Importer, LinkImporter, LinkVerifier, Migrator, Money, Queue, Settings, Time, Url, View};

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

Auth::start();
try {
    $pdo = Db::get();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Banco de dados indisponível. Verifique a extensão pdo_sqlite e o app.php.');
}

$page = is_string($_GET['p'] ?? null) ? $_GET['p'] : 'painel';
$pages = ['painel', 'automacoes', 'integracoes', 'ofertas', 'oferta', 'importar', 'lote', 'links', 'lote_links', 'ranking', 'publicacoes', 'relatorios', 'categorias', 'sistema', 'modelo', 'exportar'];
if (!in_array($page, $pages, true)) {
    $page = 'painel';
}

/** Redireciona sempre para o próprio painel (nunca para URL externa). */
function gmax_redirect(string $page, array $params = []): never
{
    header('Location: ' . View::url($page, $params), true, 303);
    exit;
}

function gmax_post_int(string $key): int
{
    $v = $_POST[$key] ?? '';
    return is_string($v) && preg_match('/^\d{1,10}$/D', $v) ? (int)$v : 0;
}

function gmax_post_str(string $key, int $max = 2000): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

// ------------------------------------------------------------------ POST (ações)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = gmax_post_str('action', 40);
    $back = in_array($_POST['back'] ?? '', $pages, true) ? (string)$_POST['back'] : 'painel';
    if (!Auth::validCsrf($_POST['csrf'] ?? null)) {
        View::flash('erro', 'Sessão expirada ou formulário inválido. Tente novamente.');
        gmax_redirect(Auth::check() ? $back : 'painel');
    }
    if ($action === 'login') {
        if (!Auth::hasUser($pdo)) {
            header('Location: index.php', true, 303);
            exit;
        }
        $err = Auth::login($pdo, (string)($_POST['password'] ?? ''));
        if ($err) {
            View::flash('erro', $err);
        }
        gmax_redirect('painel');
    }
    if (!Auth::check()) {
        http_response_code(403);
        exit('Acesso negado');
    }
    if ($action === 'logout') {
        Auth::logout();
        header('Location: painel.php', true, 303);
        exit;
    }
    $actor = Auth::actor();
    $migrated = Migrator::isCurrent($pdo);
    if (!$migrated && !in_array($action, ['migrate', 'backup_create'], true)) {
        View::flash('erro', 'Aplique a migração antes de usar esta função.');
        gmax_redirect('sistema');
    }
    try {
        switch ($action) {
            case 'migrate':
                if (gmax_post_str('confirm', 20) !== 'MIGRAR') {
                    throw new RuntimeException('Digite MIGRAR para confirmar.');
                }
                if (Gmax\Preflight::hasFailures(Gmax\Preflight::run($pdo))) {
                    throw new RuntimeException('A verificação do ambiente tem FALHAS: corrija antes de migrar.');
                }
                $done = Migrator::up($pdo, $actor);
                View::flash('ok', $done ? 'Migração aplicada. Backup prévio: ' . basename($done[0]['backup']) . '. Produtos copiados: ' . json_encode($done[0]['result'], JSON_UNESCAPED_UNICODE) : 'Nada a migrar.');
                gmax_redirect('sistema');
            case 'backup_create':
                $b = Backup::create($pdo, 'manual');
                if (Db::tableExists($pdo, 'gmax3_audit')) {
                    Audit::log($pdo, $actor, 'backup_criado', 'backup', basename($b['file']), ['bytes' => $b['bytes'], 'sha256' => $b['sha256']]);
                }
                View::flash('ok', 'Backup criado e verificado: ' . basename($b['file']));
                gmax_redirect('sistema');
            case 'backup_restore':
                if (gmax_post_str('confirm', 20) !== 'RESTAURAR') {
                    throw new RuntimeException('Digite RESTAURAR para confirmar.');
                }
                $r = Backup::restorePanelTables($pdo, gmax_post_str('name', 120), $actor);
                View::flash('ok', 'Dados do painel 3.0 restaurados. Backup do estado anterior: ' . basename($r['pre_backup']));
                gmax_redirect('sistema');
            case 'import_upload':
                $f = $_FILES['arquivo'] ?? null;
                if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                    throw new RuntimeException('Selecione um arquivo CSV ou XLSX válido.');
                }
                $id = Importer::preview($pdo, (string)$f['tmp_name'], (string)$f['name'], $actor);
                gmax_redirect('lote', ['id' => $id]);
            case 'import_apply':
                $c = Importer::apply($pdo, gmax_post_int('batch'), $actor);
                View::flash('ok', "Importação concluída: {$c['inserir']} novo(s), {$c['atualizar']} atualizado(s), {$c['sem_alteracao']} sem alteração, {$c['rejeitado']} rejeitado(s). Nenhuma publicação foi feita.");
                gmax_redirect('lote', ['id' => gmax_post_int('batch')]);
            case 'import_discard':
                Importer::discard($pdo, gmax_post_int('batch'), $actor);
                View::flash('ok', 'Prévia descartada; nada foi gravado no catálogo.');
                gmax_redirect('importar');
            case 'import_revert':
                $r = Importer::revert($pdo, gmax_post_int('batch'), $actor);
                View::flash($r['ignorados'] ? 'aviso' : 'ok', "Lote desfeito: {$r['removidos']} removido(s), {$r['restaurados']} restaurado(s)." . ($r['ignorados'] ? ' Não desfeitos: ' . implode('; ', $r['ignorados']) : ''));
                gmax_redirect('lote', ['id' => gmax_post_int('batch')]);
            case 'link_upload':
                $f = $_FILES['arquivo'] ?? null;
                if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                    throw new RuntimeException('Selecione um arquivo CSV ou XLSX válido.');
                }
                $id = LinkImporter::preview($pdo, (string)$f['tmp_name'], (string)$f['name'], $actor);
                gmax_redirect('lote_links', ['id' => $id]);
            case 'link_batch_verify':
                $r = LinkImporter::verify($pdo, gmax_post_int('batch'), new LinkVerifier(), $actor);
                View::flash($r['diverge'] ? 'aviso' : 'ok', "Verificação concluída: {$r['confere']} confere(m), {$r['diverge']} diverge(m) (bloqueados), {$r['indeterminado']} indeterminado(s).");
                gmax_redirect('lote_links', ['id' => gmax_post_int('batch')]);
            case 'link_batch_apply':
                $r = LinkImporter::apply($pdo, gmax_post_int('batch'), !empty($_POST['replace']), $actor, !empty($_POST['accept_unverified']));
                View::flash($r['ignorados'] ? 'aviso' : 'ok', "Links associados: {$r['aplicados']} novo(s), {$r['substituidos']} substituído(s). Backup prévio: {$r['backup']}. Nenhum produto foi aprovado ou publicado."
                    . ($r['ignorados'] ? ' Não aplicados: ' . implode('; ', array_slice($r['ignorados'], 0, 10)) : ''));
                gmax_redirect('lote_links', ['id' => gmax_post_int('batch')]);
            case 'link_batch_discard':
                LinkImporter::discard($pdo, gmax_post_int('batch'), $actor);
                View::flash('ok', 'Prévia descartada; nenhum link foi alterado.');
                gmax_redirect('links');
            case 'link_batch_revert':
                $r = LinkImporter::revert($pdo, gmax_post_int('batch'), $actor);
                View::flash($r['ignorados'] ? 'aviso' : 'ok', "Lote desfeito: {$r['restaurados']} link(s) restaurado(s)." . ($r['ignorados'] ? ' Preservados: ' . implode('; ', $r['ignorados']) : ''));
                gmax_redirect('lote_links', ['id' => gmax_post_int('batch')]);
            case 'auto_run':
                if (!in_array('002', Migrator::applied($pdo), true)) {
                    throw new RuntimeException('Aplique a migração 002 em Sistema.');
                }
                $r = Gmax\Automation\Runner::runManual($pdo, gmax_post_str('job', 40), $actor);
                $msg = match ($r['resultado']) {
                    'ja_em_execucao' => 'Outra execução está em andamento. Tente em alguns minutos.',
                    'trava_indisponivel' => 'Pasta privada sem permissão de escrita: execução não realizada.',
                    default => sprintf('Execução %s: %d encontrado(s), %d novo(s), %d atualizado(s), %d aprovado(s), %d bloqueado(s), %d erro(s). Nenhuma mensagem foi enviada.',
                        $r['resultado'], $r['found'] ?? 0, $r['created'] ?? 0, $r['updated'] ?? 0, $r['approved'] ?? 0, $r['blocked'] ?? 0, $r['errors'] ?? 0),
                };
                View::flash(in_array($r['resultado'], ['concluido'], true) ? 'ok' : 'aviso', $msg);
                gmax_redirect('automacoes');
            case 'ml_oauth_start':
                $url = (new Gmax\OAuth\MercadoLivreOAuth())->begin($_SESSION);
                Audit::log($pdo, $actor, 'ml_oauth_iniciado', 'integracao', 'mercadolivre');
                // Sem 303 aqui: o Chromium (Chrome/Edge) aplica form-action a todos os redirecionamentos
                // que seguem um POST, e o autorizador do ML redireciona para o login em outro host quando
                // o navegador não está logado. A página intermediária navega por GET (fora de form-action).
                if (!str_starts_with($url, Gmax\OAuth\MercadoLivreOAuth::AUTH_URL . '?')) {
                    throw new RuntimeException('Endereço de autorização inesperado.');
                }
                $u = View::e($url);
                echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                    . '<meta name="referrer" content="no-referrer"><meta http-equiv="refresh" content="0;url=' . $u . '">'
                    . '<title>Redirecionando ao Mercado Livre</title><link rel="stylesheet" href="gmax-assets/painel.css"></head>'
                    . '<body><main class="wrap"><div class="card"><p>Redirecionando para a autorização do Mercado Livre…</p>'
                    . '<p><a class="btn btn-primary" href="' . $u . '" data-autonav rel="noreferrer">Continuar no Mercado Livre</a></p>'
                    . '<p><a href="' . View::e(View::url('integracoes')) . '">Cancelar</a></p></div></main>'
                    . '<script src="gmax-assets/painel.js"></script></body></html>';
                exit;
            case 'ml_oauth_disconnect':
                if (gmax_post_str('confirm', 20) !== 'DESCONECTAR') {
                    throw new RuntimeException('Digite DESCONECTAR para confirmar.');
                }
                $o = new Gmax\OAuth\MercadoLivreOAuth();
                if ($o->appConfigured()) {
                    $o->store()->delete();
                }
                Audit::log($pdo, $actor, 'ml_oauth_desconectado', 'integracao', 'mercadolivre');
                View::flash('ok', 'Token do Mercado Livre removido do servidor. Para revogar também no Mercado Livre, remova o aplicativo em "Segurança > Aplicativos conectados" na sua conta.');
                gmax_redirect('integracoes');
            case 'ml_oauth_test':
                $p = (new Gmax\Connectors\MercadoLivre(new Gmax\RateLimiter(new Gmax\Http(), $pdo, 'mercadolivre')))->probePermissions();
                $_SESSION['gmax3_ml_probe'] = $p + ['em' => Time::nowSql()];
                Audit::log($pdo, $actor, 'ml_oauth_testado', 'integracao', 'mercadolivre', ['conta' => $p['conta']['detalhe'], 'busca' => $p['busca']['detalhe']]);
                gmax_redirect('integracoes');
            case 'auto_pause':
                Settings::set($pdo, 'automacoes_pausadas', '1', $actor);
                View::flash('ok', 'Todas as automações foram pausadas.');
                gmax_redirect('automacoes');
            case 'auto_resume':
                if (gmax_post_str('confirm', 20) !== 'RETOMAR') {
                    throw new RuntimeException('Digite RETOMAR para confirmar.');
                }
                Settings::set($pdo, 'automacoes_pausadas', '0', $actor);
                View::flash('aviso', 'Automações retomadas: pesquisa, revisão e seleção seguem os horários. Envios continuam bloqueados pelas travas de publicação.');
                gmax_redirect('automacoes');
            case 'auto_job_save':
                Gmax\Automation\Runner::setJob($pdo, gmax_post_str('job', 40), !empty($_POST['enabled']), gmax_post_str('times', 120), implode(',', array_map('intval', (array)($_POST['weekdays'] ?? []))), $actor);
                View::flash('ok', 'Agendamento salvo.');
                gmax_redirect('automacoes');
            case 'auto_alert_resolve':
                Gmax\Automation\Alerts::resolve($pdo, gmax_post_int('id'), $actor);
                gmax_redirect('automacoes');
            case 'template_save':
                $body = str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''));
                $body = mb_substr($body, 0, 1500);
                if ($errs = Gmax\Message::validateTemplate($body)) {
                    throw new RuntimeException(implode(' ', $errs));
                }
                $mpT = gmax_post_str('marketplace', 20);
                if ($mpT !== '*' && !isset(Url::MARKETPLACES[$mpT])) {
                    throw new RuntimeException('Marketplace inválido.');
                }
                $catT = gmax_post_str('category', 80) ?: '*';
                Db::run($pdo, 'INSERT INTO gmax3_templates(marketplace,category,body,active,updated_at) VALUES(?,?,?,1,?) ON CONFLICT(marketplace,category) DO UPDATE SET body=excluded.body, active=1, updated_at=excluded.updated_at', [$mpT, $catT, $body, Time::nowSql()]);
                Audit::log($pdo, $actor, 'modelo_salvo', 'template', "$mpT/$catT");
                View::flash('ok', 'Modelo de mensagem salvo.');
                gmax_redirect('automacoes');
            case 'template_delete':
                $tid = gmax_post_int('id');
                Db::run($pdo, "DELETE FROM gmax3_templates WHERE id=? AND NOT (marketplace='*' AND category='*')", [$tid]);
                Audit::log($pdo, $actor, 'modelo_removido', 'template', $tid);
                View::flash('ok', 'Modelo removido (o modelo padrão não pode ser removido).');
                gmax_redirect('automacoes');
            case 'offer_save':
                $id = gmax_post_int('id');
                [$data, $errors] = gmax_offer_form($pdo);
                if ($errors) {
                    $_SESSION['gmax3_form'] = $_POST;
                    throw new RuntimeException(implode(' ', $errors));
                }
                if ($id) {
                    $cur = Catalog::get($pdo, $id) ?? throw new RuntimeException('Produto não encontrado');
                    $res = Db::immediate($pdo, fn() => Catalog::update($pdo, $cur, $data, $actor, false));
                    View::flash('ok', $res['changed'] ? 'Produto atualizado (' . implode(', ', $res['changed']) . ').' . ($res['status'] !== $cur['validation_status'] ? ' Preço/link alterado: aprovação retirada para nova validação.' : '') : 'Nenhuma alteração.');
                } else {
                    $matches = Catalog::findMatches($pdo, $data);
                    if ($matches) {
                        throw new RuntimeException('Produto já cadastrado (#' . $matches[0]['id'] . '). Edite o existente para evitar duplicidade.');
                    }
                    $id = Db::immediate($pdo, fn() => Catalog::insert($pdo, $data, empty($data['affiliate_url']) ? 'rascunho' : 'aguardando_validacao', $actor));
                    View::flash('ok', 'Produto cadastrado (#' . $id . ') e aguardando validação.');
                }
                if (!empty($_POST['confirm_price'])) {
                    Catalog::confirmPrice($pdo, $id, $actor);
                }
                unset($_SESSION['gmax3_form']);
                gmax_redirect('oferta', ['id' => $id]);
            case 'offer_status':
                $id = gmax_post_int('id');
                $issues = Catalog::setStatus($pdo, $id, gmax_post_str('status', 30), $actor, !empty($_POST['confirm_price']));
                View::flash($issues ? 'erro' : 'ok', $issues ? 'Não aprovado: ' . implode('; ', $issues) : 'Estado atualizado.');
                gmax_redirect($back === 'ofertas' ? 'ofertas' : 'oferta', $back === 'ofertas' ? [] : ['id' => $id]);
            case 'offer_confirm_price':
                $id = gmax_post_int('id');
                View::flash(Catalog::confirmPrice($pdo, $id, $actor) ? 'ok' : 'erro', Catalog::get($pdo, $id) && Catalog::get($pdo, $id)['price_verified_at'] ? 'Preço registrado como conferido agora.' : 'Informe o preço promocional antes de confirmar.');
                gmax_redirect('oferta', ['id' => $id]);
            case 'bulk':
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
                $op = gmax_post_str('op', 30);
                if (!$ids) {
                    throw new RuntimeException('Selecione ao menos um produto.');
                }
                if (count($ids) > 200) {
                    throw new RuntimeException('Máximo de 200 produtos por operação.');
                }
                $ok = 0;
                $fail = [];
                foreach ($ids as $id) {
                    $res = match ($op) {
                        'aprovar' => Catalog::setStatus($pdo, $id, 'aprovado', $actor),
                        'aguardando' => Catalog::setStatus($pdo, $id, 'aguardando_validacao', $actor),
                        'rejeitar' => Catalog::setStatus($pdo, $id, 'rejeitado', $actor),
                        'arquivar' => Catalog::setStatus($pdo, $id, 'arquivado', $actor),
                        'enfileirar' => Queue::enqueue($pdo, $id, 'telegram_privado', null, $actor),
                        default => throw new RuntimeException('Operação inválida'),
                    };
                    $res ? $fail[] = "#$id: " . implode('; ', $res) : $ok++;
                }
                View::flash($fail ? 'aviso' : 'ok', "$ok produto(s) processado(s)." . ($fail ? ' Pendências: ' . implode(' | ', array_slice($fail, 0, 15)) . (count($fail) > 15 ? ' …' : '') : ''));
                gmax_redirect($back, array_intersect_key($_POST, array_flip(['marketplace', 'status', 'q'])));
            case 'link_set':
                $id = gmax_post_int('id');
                $cur = Catalog::get($pdo, $id) ?? throw new RuntimeException('Produto não encontrado');
                $url = gmax_post_str('affiliate_url', 300);
                if (!Url::isAffiliate($cur['marketplace'], $url)) {
                    throw new RuntimeException('Link afiliado inválido para ' . Url::MARKETPLACES[$cur['marketplace']] . ($cur['marketplace'] === 'mercadolivre' ? ' (use o link curto https://meli.la/… do gerador oficial)' : ' (use https://s.shopee.com.br/…)'));
                }
                $other = Db::one($pdo, 'SELECT id FROM gmax3_catalog WHERE affiliate_norm=? AND id<>?', [Url::normalizeAffiliate($url), $id]);
                if ($other) {
                    throw new RuntimeException('Este link já está associado ao produto #' . $other['id'] . '.');
                }
                Db::immediate($pdo, function () use ($pdo, $cur, $url, $actor) {
                    Catalog::update($pdo, $cur, ['affiliate_url' => $url], $actor, false);
                    if ($cur['validation_status'] === 'rascunho') {
                        Db::run($pdo, "UPDATE gmax3_catalog SET validation_status='aguardando_validacao' WHERE id=?", [$cur['id']]);
                    }
                });
                View::flash('ok', 'Link associado ao produto #' . $id . '. Confira se o link abre exatamente este anúncio antes de aprovar.');
                gmax_redirect($back === 'links' ? 'links' : 'oferta', $back === 'links' ? [] : ['id' => $id]);
            case 'queue_add':
                $id = gmax_post_int('id');
                $when = gmax_post_str('when', 20);
                $utc = null;
                if ($when !== '') {
                    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $when, new DateTimeZone('America/Sao_Paulo'));
                    if (!$dt || $dt < new DateTimeImmutable('now')) {
                        throw new RuntimeException('Data/hora de agendamento inválida ou no passado.');
                    }
                    $utc = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
                $reasons = Queue::enqueue($pdo, $id, 'telegram_privado', $utc, $actor);
                View::flash($reasons ? 'erro' : 'ok', $reasons ? 'Não enfileirado: ' . implode('; ', $reasons) : 'Adicionado à fila. Envio real continua bloqueado até autorização explícita.');
                gmax_redirect('oferta', ['id' => $id]);
            case 'queue_cancel':
                View::flash(Queue::cancel($pdo, gmax_post_int('id'), $actor) ? 'ok' : 'erro', 'Cancelamento: somente itens agendados ou bloqueados podem ser cancelados.');
                gmax_redirect('publicacoes');
            case 'queue_reconcile':
                $outcome = gmax_post_str('outcome', 20);
                if (!in_array($outcome, ['publicado', 'nao_publicado'], true) || gmax_post_str('confirm', 20) !== 'CONFERI') {
                    throw new RuntimeException('Confira o grupo no Telegram e digite CONFERI para registrar o resultado.');
                }
                $mid = gmax_post_str('message_id', 12);
                Queue::reconcile($pdo, gmax_post_int('id'), $outcome === 'publicado', preg_match('/^\d{1,12}$/D', $mid) ? (int)$mid : null, $actor);
                View::flash('ok', 'Reconciliação registrada. A fila permanece pausada até você retomá-la.');
                gmax_redirect('publicacoes');
            case 'pause':
                Settings::pause($pdo, 'Pausa emergencial pelo painel: ' . (gmax_post_str('reason', 200) ?: 'sem motivo informado'), $actor);
                View::flash('ok', 'PAUSA EMERGENCIAL ATIVADA. Nenhum envio será processado.');
                gmax_redirect($back);
            case 'resume':
                if (gmax_post_str('confirm', 20) !== 'RETOMAR') {
                    throw new RuntimeException('Digite RETOMAR para confirmar.');
                }
                if ($p = Queue::unreconciled($pdo)) {
                    throw new RuntimeException('Não é possível retomar: ' . implode('; ', $p));
                }
                if (!Settings::resume($pdo, $actor)) {
                    View::flash('aviso', 'O arquivo gmax-publicacao.pause foi criado manualmente e continua pausando os envios; remova-o pelo cPanel se for o caso.');
                }
                View::flash('aviso', 'Pausa removida. Atenção: a automação comercial e o arquivo de ativação continuam exigidos para qualquer envio real.');
                gmax_redirect('publicacoes');
            case 'category_save':
                $name = gmax_post_str('name', 80);
                $pot = gmax_post_str('potential', 3);
                if ($name === '') {
                    throw new RuntimeException('Informe o nome da categoria.');
                }
                if ($pot !== '' && (!ctype_digit($pot) || (int)$pot > 100)) {
                    throw new RuntimeException('Potencial deve ser de 0 a 100 (ou vazio).');
                }
                Db::run($pdo, 'INSERT INTO gmax3_categories(name,potential,active,notes) VALUES(?,?,?,?) ON CONFLICT(name) DO UPDATE SET potential=excluded.potential, active=excluded.active, notes=excluded.notes', [
                    $name, $pot === '' ? null : (int)$pot, empty($_POST['active']) ? 0 : 1, gmax_post_str('notes', 300) ?: null,
                ]);
                Audit::log($pdo, $actor, 'categoria_salva', 'categoria', $name, ['potencial' => $pot]);
                View::flash('ok', 'Categoria salva.');
                gmax_redirect('categorias');
            case 'settings_save':
                foreach (Settings::WEB_EDITABLE as $k) {
                    if (isset($_POST[$k]) && gmax_post_str($k, 2000) !== Settings::get($pdo, $k)) {
                        Settings::setFromWeb($pdo, $k, gmax_post_str($k, 2000), $actor);
                    }
                }
                View::flash('ok', 'Configurações salvas.');
                gmax_redirect($back === 'automacoes' ? 'automacoes' : 'sistema');
            default:
                throw new RuntimeException('Ação desconhecida.');
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            $msg = str_contains($e->getMessage(), 'UNIQUE')
                ? 'Conflito de duplicidade: já existe produto com o mesmo código, ID ou link afiliado.'
                : 'Erro no banco de dados. Nada foi alterado.';
        } else {
            $msg = $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'Erro interno (' . get_class($e) . '). Nada foi alterado.';
        }
        View::flash('erro', $msg);
        error_log('[gmax3] ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage());
        $params = [];
        foreach (['id', 'batch'] as $k) {
            if (gmax_post_int($k)) {
                $params[$k === 'batch' ? 'id' : 'id'] = gmax_post_int($k);
            }
        }
        gmax_redirect($back, $params);
    }
}

/** Lê o formulário de produto. @return array{0:array,1:list<string>} */
function gmax_offer_form(PDO $pdo): array
{
    $e = [];
    $mp = gmax_post_str('marketplace', 20);
    if (!isset(Url::MARKETPLACES[$mp])) {
        $e[] = 'Marketplace inválido.';
        $mp = 'mercadolivre';
    }
    $d = ['marketplace' => $mp];
    foreach (['title' => 300, 'category' => 80, 'store_name' => 120, 'codigo' => 40, 'original_url' => 2048, 'affiliate_url' => 300, 'payment_note' => 200,
        'sales_public' => 40, 'seller_reputation' => 60, 'variation_note' => 300, 'notes' => 1000, 'commission_rate' => 20] as $f => $max) {
        $d[$f] = gmax_post_str($f, $max);
    }
    if ($d['title'] === '') {
        $e[] = 'Informe o nome do produto.';
    }
    if ($d['codigo'] !== '' && !preg_match('/^[A-Za-z0-9_-]{2,40}$/D', $d['codigo'])) {
        $e[] = 'Código inválido.';
    }
    foreach (['price_original_cents' => 'preço original', 'price_promo_cents' => 'preço promocional'] as $f => $label) {
        $err = null;
        $d[$f] = Money::tryParse(gmax_post_str(str_replace('_cents', '', $f), 20), $err);
        if ($err) {
            $e[] = "Valor de $label inválido.";
        }
    }
    if ($d['price_original_cents'] !== null && ($d['price_promo_cents'] === null || $d['price_original_cents'] < $d['price_promo_cents'])) {
        $e[] = 'Preço original deve ser maior ou igual ao promocional.';
    }
    if ($d['affiliate_url'] !== '' && !Url::isAffiliate($mp, $d['affiliate_url'])) {
        $e[] = 'Link afiliado inválido para ' . Url::MARKETPLACES[$mp] . '.';
    }
    if ($d['original_url'] !== '') {
        if (!Url::isProduct($mp, $d['original_url'])) {
            $e[] = 'URL do produto inválida (use o link do anúncio individual).';
        } else {
            $d['external_id'] = $mp === 'mercadolivre' ? Url::mlId($d['original_url']) : Url::shopeeItemId($d['original_url']);
        }
    }
    $pay = gmax_post_str('payment_condition', 20);
    $d['payment_condition'] = isset(Catalog::PAYMENT[$pay]) ? $pay : null;
    $av = gmax_post_str('availability', 20);
    $d['availability'] = isset(Catalog::AVAILABILITY[$av]) ? $av : 'desconhecida';
    $d['variation_pending'] = empty($_POST['variation_pending']) ? 0 : 1;
    $d['sales_min'] = $d['sales_public'] !== '' ? Catalog::parseSales($d['sales_public']) : null;
    $rating = str_replace(',', '.', gmax_post_str('rating', 5));
    $d['rating'] = null;
    if ($rating !== '') {
        if (is_numeric($rating) && (float)$rating >= 0 && (float)$rating <= 5) {
            $d['rating'] = (float)$rating;
        } else {
            $e[] = 'Avaliação deve ser de 0 a 5.';
        }
    }
    $rc = gmax_post_str('rating_count', 9);
    $d['rating_count'] = null;
    if ($rc !== '') {
        if (ctype_digit($rc)) {
            $d['rating_count'] = (int)$rc;
        } else {
            $e[] = 'Número de avaliações inválido.';
        }
    }
    $d['price_source'] = 'manual';
    foreach ($d as $k => $v) {
        if ($v === '') {
            $d[$k] = null;
        }
    }
    return [$d, $e];
}

// ------------------------------------------------------------------ GET (páginas)
if (!Auth::check()) {
    $flash = View::takeFlash();
    $hasUser = Auth::hasUser($pdo);
    require __DIR__ . '/gmax-lib/pages/login.php';
    exit;
}
$migrated = Migrator::isCurrent($pdo);
if (!$migrated && $page !== 'sistema') {
    $page = 'sistema';
}
if ($migrated) {
    try {
        $sig = Catalog::legacySignature($pdo);
        $synced = ['shopee' => 0, 'mercadolivre' => 0];
        if (($_SESSION['gmax3_legacy_sig'] ?? '') !== $sig) {
            $synced = Db::immediate($pdo, fn() => Catalog::syncLegacy($pdo, 'sincronizacao_legado'));
            $_SESSION['gmax3_legacy_sig'] = $sig;
        }
        if (array_sum($synced) > 0) {
            View::flash('ok', 'Novos produtos encontrados nas tabelas antigas e copiados para o painel: ' . $synced['shopee'] . ' Shopee, ' . $synced['mercadolivre'] . ' Mercado Livre.');
        }
    } catch (Throwable $e) {
        error_log('[gmax3] sync: ' . $e->getMessage());
    }
}
if ($page === 'modelo') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gmax-modelo-importacao.csv"');
    echo Importer::template();
    exit;
}
if ($page === 'exportar') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gmax-catalogo-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'marketplace', 'codigo', 'id_anuncio', 'produto', 'categoria', 'estado', 'preco_original', 'preco_promocional', 'desconto_%', 'condicao', 'preco_conferido_em', 'url_original', 'url_afiliado'], ';', '"', '');
    foreach ($pdo->query('SELECT * FROM gmax3_catalog ORDER BY id') as $r) {
        $d = Money::discount($r['price_original_cents'] === null ? null : (int)$r['price_original_cents'], $r['price_promo_cents'] === null ? null : (int)$r['price_promo_cents']);
        $cells = [$r['id'], $r['marketplace'], $r['codigo'], $r['external_id'], $r['title'], $r['category'], $r['validation_status'], Money::input($r['price_original_cents'] === null ? null : (int)$r['price_original_cents']),
            Money::input($r['price_promo_cents'] === null ? null : (int)$r['price_promo_cents']), $d['percent'] ?? '', $r['payment_condition'], $r['price_verified_at'], $r['original_url'], $r['affiliate_url']];
        // Evita injeção de fórmulas ao abrir no Excel.
        fputcsv($out, array_map(static fn($c) => is_string($c) && preg_match('/^[=+\-@\t\r]/', $c) ? "'" . $c : $c, $cells), ';', '"', '');
    }
    exit;
}

$flash = View::takeFlash();
$paused = $migrated ? Settings::paused($pdo) : true;
$staleHours = $migrated ? Settings::int($pdo, 'validade_preco_horas', 24) : 24;
$titles = ['painel' => 'Visão geral', 'automacoes' => 'Central de Automações', 'integracoes' => 'Integrações', 'ofertas' => 'Ofertas', 'oferta' => 'Produto', 'importar' => 'Importar lote', 'lote' => 'Lote de importação', 'links' => 'Links afiliados',
    'lote_links' => 'Lote de links afiliados', 'ranking' => 'Ranking de oportunidades', 'publicacoes' => 'Publicações', 'relatorios' => 'Relatórios', 'categorias' => 'Categorias', 'sistema' => 'Sistema e segurança'];
$title = $titles[$page] ?? 'Painel';
require __DIR__ . '/gmax-lib/pages/_layout_top.php';
require __DIR__ . '/gmax-lib/pages/' . $page . '.php';
require __DIR__ . '/gmax-lib/pages/_layout_bottom.php';
