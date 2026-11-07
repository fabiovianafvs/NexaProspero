<?php
declare(strict_types=1);

// WhatsApp > Fila de envio: acompanha as mensagens automáticas (pedido da loja,
// venda do PDV, status do pedido) e permite reenviar ou cancelar manualmente.
// Sempre da empresa de login (sem F11).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_service.php';

$cid = (int)COMPANY_ID;
$msg = '';
$err = '';
function waf_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function waf_dt($v): string { $t = strtotime((string)$v); return $t ? date('d/m/Y H:i:s', $t) : '—'; }
function waf_tipo(string $tipo): string {
  return [
    'order_customer_full' => 'Pedido da loja virtual',
    'pdv_order_customer_full' => 'Venda do PDV',
    'status_customer' => 'Status do pedido',
  ][$tipo] ?? $tipo;
}
function waf_phone(string $d): string {
  $d = preg_replace('/\D+/', '', $d) ?? '';
  if (strlen($d) === 13 && str_starts_with($d, '55')) return '(' . substr($d, 2, 2) . ') ' . substr($d, 4, 5) . '-' . substr($d, 9);
  if (strlen($d) === 12 && str_starts_with($d, '55')) return '(' . substr($d, 2, 2) . ') ' . substr($d, 4, 4) . '-' . substr($d, 8);
  return $d;
}
const WAF_STATUS = ['PENDENTE' => 'Aguardando', 'ENVIANDO' => 'Enviando', 'ENVIADO' => 'Enviado', 'FALHOU' => 'Não enviado', 'CANCELADO' => 'Cancelado'];

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_waf_csrf'])) $_SESSION['nexa_waf_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_waf_csrf'] ?? '');
$ready = whatsapp_outbox_ready($pdo);

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $st = $pdo->prepare('SELECT * FROM whatsapp_outbox WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$cid, $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Mensagem não encontrada.');
    $status = (string)$row['status'];

    if ($action === 'reenviar') {
      if ($status === 'ENVIANDO') throw new RuntimeException('Esta mensagem está sendo enviada agora. Aguarde alguns segundos.');
      if (whatsapp_outbox_active($pdo)) {
        // Volta para a fila como nova (a regra de 6 horas recomeça a contar).
        $pdo->prepare("UPDATE whatsapp_outbox SET status='PENDENTE', attempts=0, last_error=NULL, sent_at=NULL, next_attempt_at=NOW(), created_at=NOW() WHERE company_id=? AND id=? AND status<>'ENVIANDO'")->execute([$cid, $id]);
        whatsapp_outbox_signal();
        $msg = 'Mensagem #' . $id . ' colocada na fila para reenvio. Atualize a página em alguns segundos.';
      } else {
        // Processo da fila não instalado: envia na hora.
        $ok = whatsapp_send_message($pdo, $cid, (string)$row['destino'], (string)$row['mensagem'], (string)$row['tipo'], (int)$row['pedido_id'] ?: null);
        $pdo->prepare("UPDATE whatsapp_outbox SET status=?, attempts=attempts+1, last_error=?, sent_at=" . ($ok ? 'NOW()' : 'sent_at') . " WHERE company_id=? AND id=?")
          ->execute([$ok ? 'ENVIADO' : 'FALHOU', $ok ? null : 'Reenvio manual falhou (ver Logs do WhatsApp).', $cid, $id]);
        if (!$ok) throw new RuntimeException('Não foi possível enviar agora. Confira a conexão do WhatsApp.');
        $msg = 'Mensagem #' . $id . ' reenviada.';
      }
    } elseif ($action === 'cancelar') {
      if ($status !== 'PENDENTE') throw new RuntimeException('Só é possível cancelar mensagens que estão aguardando envio.');
      $pdo->prepare("UPDATE whatsapp_outbox SET status='CANCELADO', last_error='Cancelada manualmente.' WHERE company_id=? AND id=? AND status='PENDENTE'")->execute([$cid, $id]);
      $msg = 'Mensagem #' . $id . ' cancelada.';
    } else {
      throw new RuntimeException('Ação inválida.');
    }
    header('Location: whatsapp_fila.php?' . http_build_query(array_filter(['ok' => $msg, 'status' => $_GET['status'] ?? null, 'ini' => $_GET['ini'] ?? null, 'fim' => $_GET['fim'] ?? null, 'q' => $_GET['q'] ?? null])));
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
if (isset($_GET['ok'])) $msg = (string)$_GET['ok'];

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
if (!isset(WAF_STATUS[$statusFilter])) $statusFilter = '';
$ini = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-m-d', strtotime('-7 days'));
$fim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');
$q = trim((string)($_GET['q'] ?? ''));

$rows = [];
$counts = array_fill_keys(array_keys(WAF_STATUS), 0);
if ($ready) {
  $where = 'company_id = ? AND created_at >= ? AND created_at < (? + INTERVAL 1 DAY)';
  $params = [$cid, $ini . ' 00:00:00', $fim];
  $stc = $pdo->prepare("SELECT status, COUNT(*) c FROM whatsapp_outbox WHERE $where GROUP BY status");
  $stc->execute($params);
  foreach ($stc->fetchAll(PDO::FETCH_ASSOC) as $c) if (isset($counts[$c['status']])) $counts[$c['status']] = (int)$c['c'];
  if ($statusFilter !== '') { $where .= ' AND status = ?'; $params[] = $statusFilter; }
  if ($q !== '') {
    $digits = preg_replace('/\D+/', '', $q) ?? '';
    $where .= ' AND (mensagem LIKE ?' . ($digits !== '' ? ' OR destino LIKE ? OR pedido_id = ?' : '') . ')';
    $params[] = '%' . $q . '%';
    if ($digits !== '') { $params[] = '%' . $digits . '%'; $params[] = (int)$digits; }
  }
  $st = $pdo->prepare("SELECT * FROM whatsapp_outbox WHERE $where ORDER BY id DESC LIMIT 300");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$breaker = whatsapp_breaker_status(whatsapp_load_config($pdo, $cid, false));
$selfUrl = static fn(array $extra = []) => 'whatsapp_fila.php?' . http_build_query(array_filter(array_merge(['status' => $statusFilter, 'ini' => $ini, 'fim' => $fim, 'q' => $q], $extra), static fn($v) => $v !== '' && $v !== null));

admin_topbar('Fila de envio do WhatsApp', 'whatsapp_fila');
?>
<style>
.waf-wrap{max-width:1280px;margin:14px auto 0;display:grid;gap:14px}.waf-card{background:#fff;border:1px solid #dbeafe;border-radius:22px;padding:18px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.waf-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.waf-head h2{margin:0;color:#0b1f5b;font-size:22px}.waf-muted{color:#64748b;line-height:1.5;font-size:13px}
.waf-kpis{display:flex;gap:8px;flex-wrap:wrap}.waf-kpi{border:1px solid #dbe4f0;border-radius:14px;padding:8px 12px;background:#f8fbff;text-decoration:none;color:#0f172a;font-size:13px}.waf-kpi strong{font-size:18px;display:block}.waf-kpi.on{border-color:#2454d6;background:#eef4ff}
.waf-filter{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.waf-filter label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:4px}.waf-filter input,.waf-filter select{padding:8px 10px;border:1px solid #cbd5e1;border-radius:10px}
.waf-table{width:100%;border-collapse:collapse;font-size:13px}.waf-table th{text-align:left;color:#475569;font-size:12px;padding:8px;border-bottom:1px solid #e2e8f0;white-space:nowrap}.waf-table td{padding:8px;border-bottom:1px solid #f1f5f9;vertical-align:top}.waf-chip{display:inline-block;padding:3px 9px;border-radius:999px;font-weight:800;font-size:11px;white-space:nowrap}
.waf-PENDENTE,.waf-ENVIANDO{background:#fef3c7;color:#92400e}.waf-ENVIADO{background:#dcfce7;color:#166534}.waf-FALHOU{background:#fee2e2;color:#b91c1c}.waf-CANCELADO{background:#e2e8f0;color:#475569}
.waf-msg summary{cursor:pointer;color:#2454d6}.waf-msg pre{white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px;margin:6px 0 0;font-family:inherit;max-width:420px}.waf-err{color:#b91c1c;font-size:12px;max-width:260px}.waf-actions{display:flex;gap:6px;flex-wrap:wrap}.waf-actions form{margin:0}.waf-scroll{overflow-x:auto}
</style>
<div class="waf-wrap">
<?php if ($msg !== ''): ?><div class="msg ok"><?= waf_h($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= waf_h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="msg err">A fila de envio ainda não foi instalada. Peça ao administrador da plataforma para aplicar "WhatsApp - fila de envio" no Atualizador do Banco.</div><?php endif; ?>
<?php if ($breaker['open']): ?><div class="msg err"><strong>WhatsApp com problemas no momento.</strong> Os envios estão pausados por mais <?= (int)ceil($breaker['seconds_left'] / 60) ?> minuto(s); as mensagens aguardando serão enviadas depois automaticamente.</div><?php endif; ?>
<section class="waf-card">
  <div class="waf-head">
    <div><h2>Fila de envio</h2><div class="waf-muted">Mensagens automáticas do WhatsApp (pedido da loja virtual, venda do PDV e status do pedido). O sistema tenta enviar na hora e, se não conseguir, tenta de novo após 1, 2, 5 e 15 minutos. Mensagens que não saírem em 6 horas ficam como <b>Não enviado</b> e podem ser reenviadas aqui.</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn" href="whatsapp.php">Configuração WhatsApp</a><a class="btn" href="<?= waf_h($selfUrl()) ?>">Atualizar</a></div>
  </div>
  <div class="waf-kpis" style="margin-top:12px">
    <a class="waf-kpi<?= $statusFilter === '' ? ' on' : '' ?>" href="<?= waf_h($selfUrl(['status' => null])) ?>"><strong><?= array_sum($counts) ?></strong>Todas</a>
    <?php foreach (WAF_STATUS as $k => $label): if ($k === 'ENVIANDO') continue; ?><a class="waf-kpi<?= $statusFilter === $k ? ' on' : '' ?>" href="<?= waf_h($selfUrl(['status' => $k])) ?>"><strong><?= (int)$counts[$k] + ($k === 'PENDENTE' ? (int)$counts['ENVIANDO'] : 0) ?></strong><?= waf_h($label) ?></a><?php endforeach; ?>
  </div>
  <form method="get" class="waf-filter" style="margin-top:12px">
    <div><label>Data inicial</label><input type="date" name="ini" value="<?= waf_h($ini) ?>"></div>
    <div><label>Data final</label><input type="date" name="fim" value="<?= waf_h($fim) ?>"></div>
    <div><label>Situação</label><select name="status"><option value="">Todas</option><?php foreach (WAF_STATUS as $k => $label): ?><option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= waf_h($label) ?></option><?php endforeach; ?></select></div>
    <div><label>Busca</label><input name="q" value="<?= waf_h($q) ?>" placeholder="Telefone, nº do pedido ou texto"></div>
    <div><button class="btn btn-primary">Filtrar</button></div>
  </form>
</section>
<section class="waf-card">
  <div class="waf-scroll"><table class="waf-table">
    <thead><tr><th>#</th><th>Criada</th><th>Tipo</th><th>Destino</th><th>Pedido</th><th>Situação</th><th>Tentativas</th><th>Enviada / próxima tentativa</th><th>Mensagem</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="10" class="waf-muted">Nenhuma mensagem no período.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $s = (string)$r['status']; ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td style="white-space:nowrap"><?= waf_h(waf_dt($r['created_at'])) ?></td>
        <td><?= waf_h(waf_tipo((string)$r['tipo'])) ?></td>
        <td style="white-space:nowrap"><?= waf_h(waf_phone((string)$r['destino'])) ?></td>
        <td><?= (int)$r['pedido_id'] > 0 ? '#' . (int)$r['pedido_id'] : '—' ?></td>
        <td><span class="waf-chip waf-<?= waf_h($s) ?>"><?= waf_h(WAF_STATUS[$s] ?? $s) ?></span><?php if (!empty($r['last_error']) && $s !== 'ENVIADO'): ?><div class="waf-err"><?= waf_h($r['last_error']) ?></div><?php endif; ?></td>
        <td><?= (int)$r['attempts'] ?></td>
        <td style="white-space:nowrap"><?= $s === 'ENVIADO' ? waf_h(waf_dt($r['sent_at'])) : ($s === 'PENDENTE' ? 'Próxima: ' . waf_h(waf_dt($r['next_attempt_at'])) : '—') ?></td>
        <td class="waf-msg"><details><summary>Ver texto</summary><pre><?= waf_h($r['mensagem']) ?></pre></details></td>
        <td><div class="waf-actions">
          <?php if ($s !== 'ENVIANDO'): ?><form method="post" action="<?= waf_h($selfUrl()) ?>" onsubmit="return confirm('<?= $s === 'ENVIADO' ? 'Esta mensagem já consta como enviada. Enviar de novo para o cliente?' : 'Reenviar esta mensagem agora?' ?>');"><input type="hidden" name="csrf" value="<?= waf_h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="reenviar"><button class="btn btn-primary" type="submit">Reenviar</button></form><?php endif; ?>
          <?php if ($s === 'PENDENTE'): ?><form method="post" action="<?= waf_h($selfUrl()) ?>" onsubmit="return confirm('Cancelar o envio desta mensagem?');"><input type="hidden" name="csrf" value="<?= waf_h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="cancelar"><button class="btn btn-danger" type="submit">Cancelar</button></form><?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 300): ?><div class="waf-muted" style="margin-top:8px">Mostrando as 300 mais recentes. Use os filtros para refinar.</div><?php endif; ?>
</section>
</div>
