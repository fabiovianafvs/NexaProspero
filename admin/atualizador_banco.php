<?php
declare(strict_types=1);

// Atualizador do Banco (só platform_admin): aplica as migrations registradas em
// config/schema_updater.php, na ordem, com checksum, trava global e histórico.
// "Fazer atualização" aplica todas as pendentes em sequência e para na primeira falha.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/schema_updater.php';

$dbupIsPlatform = !function_exists('auth_role') || auth_role() === 'platform_admin';
function dbup_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Data da migration pelo id (AAAAMMDD_...), para as aplicadas antes do histórico existir. */
function dbup_id_date(string $id): string {
  return preg_match('/^(\d{4})(\d{2})(\d{2})_/', $id, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
}
function dbup_dt(?string $v): string {
  $t = $v ? strtotime($v) : false;
  return $t ? date('d/m/Y H:i', $t) : '';
}

/** Execuções registradas (todas) e nome dos usuários. */
function dbup_log(PDO $pdo): array {
  if (!nexa_schema_updater_log_table_exists($pdo)) return [];
  try {
    return $pdo->query('SELECT id, migration_id, status, started_at, finished_at, executed_by_user_id, database_name, error_message FROM nexa_schema_migration_log ORDER BY id DESC LIMIT 2000')->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}
function dbup_user_names(PDO $pdo, array $ids): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
  if (!$ids) return [];
  try {
    $st = $pdo->prepare('SELECT id, name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) $out[(int)$u['id']] = (string)$u['name'];
    return $out;
  } catch (Throwable $e) {
    return [];
  }
}

// Detalhes de uma atualização (janela "Abrir"): descrição, arquivo, execuções e comandos SQL.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['detalhe'])) {
  header('Content-Type: application/json; charset=utf-8');
  if (!$dbupIsPlatform) { echo json_encode(['ok' => false, 'error' => 'Acesso restrito.']); exit; }
  $id = (string)$_GET['detalhe'];
  $row = null;
  foreach (nexa_schema_updater_status($pdo) as $r) if (hash_equals((string)$r['id'], $id)) { $row = $r; break; }
  if (!$row) { echo json_encode(['ok' => false, 'error' => 'Atualização não encontrada.']); exit; }
  $sql = '';
  $file = (string)$row['file'];
  if (is_file($file) && is_readable($file)) {
    $sql = (string)file_get_contents($file, false, null, 0, 300000);
    if (filesize($file) > 300000) $sql .= "\n-- (arquivo cortado na visualização)";
  }
  $runs = array_values(array_filter(dbup_log($pdo), static fn($l) => (string)$l['migration_id'] === $id));
  $names = dbup_user_names($pdo, array_column($runs, 'executed_by_user_id'));
  echo json_encode([
    'ok' => true,
    'label' => (string)$row['label'], 'description' => (string)$row['description'], 'module' => (string)$row['module'],
    'version' => (int)$row['version'], 'current' => (int)$row['current_version'], 'applied' => !empty($row['applied']),
    'file' => basename($file), 'file_ok' => !empty($row['file_ok']), 'file_message' => (string)$row['file_message'], 'id_date' => dbup_id_date($id),
    'sql' => $sql,
    'runs' => array_map(static fn($l) => [
      'status' => strtoupper((string)$l['status']), 'start' => dbup_dt($l['started_at'] ?? null), 'end' => dbup_dt($l['finished_at'] ?? null),
      'user' => $names[(int)($l['executed_by_user_id'] ?? 0)] ?? ((int)($l['executed_by_user_id'] ?? 0) > 0 ? '#' . (int)$l['executed_by_user_id'] : ''),
      'db' => (string)($l['database_name'] ?? ''), 'error' => (string)($l['error_message'] ?? ''),
    ], $runs),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$flash = $_SESSION['nexa_schema_updater_flash'] ?? null;
unset($_SESSION['nexa_schema_updater_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    nexa_schema_updater_csrf_check((string)($_POST['csrf'] ?? ''));
    if ((string)($_POST['action'] ?? '') !== 'apply_all') throw new RuntimeException('Ação inválida.');
    // A janela de confirmação envia a palavra; um POST perdido não atualiza nada.
    if (strtoupper(trim((string)($_POST['confirmation'] ?? ''))) !== 'ATUALIZAR') throw new RuntimeException('Confirme a atualização pela janela "Fazer atualização".');
    @set_time_limit(600);
    $done = [];
    $total = 0;
    foreach (nexa_schema_updater_status($pdo) as $r) if (!empty($r['pending'])) $total++;
    $error = '';
    for ($i = 0; $i < $total; $i++) {
      $next = nexa_schema_updater_next_pending($pdo);
      if (!$next) break;
      try {
        $res = nexa_schema_updater_apply($pdo, (string)$next['id'], (int)(auth_user_id() ?? 0));
        $done[] = (string)$next['label'] . ' (' . (int)($res['statements'] ?? 0) . ' comando(s))';
      } catch (Throwable $e) {
        $error = '"' . (string)$next['label'] . '" falhou: ' . rtrim($e->getMessage(), '. ') . '.';
        break;
      }
    }
    if (function_exists('nexa_schema_updater_cache_clear')) nexa_schema_updater_cache_clear();
    if ($error !== '') {
      $msg = ($done ? 'Aplicada(s): ' . implode('; ', $done) . '. ' : '') . $error . ' Ela e as que vêm depois continuam pendentes; corrija e clique em Fazer atualização de novo.';
      $_SESSION['nexa_schema_updater_flash'] = ['type' => 'err', 'message' => $msg];
    } else {
      $_SESSION['nexa_schema_updater_flash'] = ['type' => 'ok', 'message' => $done ? 'Banco atualizado. Aplicada(s): ' . implode('; ', $done) . '.' : 'Nenhuma atualização pendente.'];
    }
  } catch (Throwable $e) {
    $_SESSION['nexa_schema_updater_flash'] = ['type' => 'err', 'message' => $e->getMessage()];
  }
  header('Location: atualizador_banco.php');
  exit;
}

$status = nexa_schema_updater_status($pdo);
$log = dbup_log($pdo);
$names = dbup_user_names($pdo, array_column($log, 'executed_by_user_id'));
$lastRun = [];
$lastOk = [];
foreach ($log as $l) {
  $mid = (string)$l['migration_id'];
  if (!isset($lastRun[$mid])) $lastRun[$mid] = $l;
  if (!isset($lastOk[$mid]) && strtoupper((string)$l['status']) === 'SUCCESS') $lastOk[$mid] = $l;
}
$dbName = '';
$dbVersion = '';
try { $dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''); } catch (Throwable $e) {}
try { $dbVersion = (string)($pdo->query('SELECT VERSION()')->fetchColumn() ?: ''); } catch (Throwable $e) {}

$pending = [];
$blocked = false;
foreach ($status as $row) {
  if (!empty($row['pending'])) {
    $pending[] = $row;
    if (empty($row['file_ok'])) $blocked = true;
  }
}
// Lista: pendentes primeiro (na ordem de aplicação), depois as aplicadas da mais nova para a mais antiga.
$applied = array_reverse(array_values(array_filter($status, static fn($r) => empty($r['pending']))));
$list = array_merge($pending, $applied);

admin_topbar('Atualizador do Banco', 'atualizador_banco');
?>
<style>
.dbup-page{display:grid;gap:14px;max-width:1180px;margin:0 auto}
.dbup-hero{background:linear-gradient(135deg,#071b55,#2454d6);color:#fff;border-radius:22px;padding:18px 20px;box-shadow:0 18px 48px rgba(11,31,91,.16);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center}
.dbup-hero h1{margin:0 0 4px;font-size:22px}.dbup-hero p{margin:0;color:#dbeafe;font-size:13px;line-height:1.5}
.dbup-facts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.dbup-facts span{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700}
.dbup-go{display:grid;gap:6px;justify-items:end;text-align:right}.dbup-go small{color:#bfdbfe;font-weight:700;font-size:12px}
.dbup-btn{border:0;border-radius:12px;padding:12px 18px;background:#22c55e;color:#052e16;font-weight:900;font-size:15px;cursor:pointer;white-space:nowrap}.dbup-btn:hover{filter:brightness(1.05)}.dbup-btn:disabled{opacity:.5;cursor:not-allowed}.dbup-btn.blue{background:#2454d6;color:#fff}
.dbup-ok{display:inline-flex;align-items:center;gap:6px;background:rgba(34,197,94,.18);border:1px solid rgba(134,239,172,.5);color:#dcfce7;border-radius:12px;padding:10px 14px;font-weight:900}
.dbup-alert{border-radius:14px;padding:12px 14px;font-weight:800;line-height:1.45}.dbup-alert.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.dbup-alert.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.dbup-alert.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.dbup-card{background:#fff;border:1px solid #dfe7f3;border-radius:20px;padding:16px;box-shadow:0 12px 34px rgba(15,23,42,.05)}.dbup-card h2{margin:0 0 4px;color:#0b1f5b;font-size:18px}.dbup-muted{color:#64748b;font-size:12.5px;line-height:1.5}
.dbup-list{width:100%;border-collapse:collapse;margin-top:10px;table-layout:fixed}.dbup-list th{text-align:left;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.03em;padding:8px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.dbup-list td{padding:10px;border-bottom:1px solid #eef2f7;font-size:13px;vertical-align:middle;overflow-wrap:anywhere}.dbup-list tr:last-child td{border-bottom:0}.dbup-list tr.is-pending td{background:#fffbeb}
.dbup-list .c-date{width:150px;white-space:nowrap}.dbup-list .c-status{width:120px}.dbup-list .c-open{width:90px;text-align:right}
.dbup-list b{color:#0b1f5b}.dbup-list small{display:block;color:#64748b;font-size:11.5px}
.dbup-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:900;white-space:nowrap}.dbup-badge.ok{background:#dcfce7;color:#166534}.dbup-badge.pending{background:#fef3c7;color:#92400e}.dbup-badge.err{background:#fee2e2;color:#991b1b}.dbup-badge.run{background:#dbeafe;color:#1d4ed8}
.dbup-open{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:6px 12px;font-weight:800;cursor:pointer;color:#0b1f5b}.dbup-open:hover{background:#f1f5ff}
.dbup-more{display:flex;justify-content:center;margin-top:10px}
.dbup-modal[hidden]{display:none!important}.dbup-modal{position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,.5);display:flex;align-items:flex-start;justify-content:center;padding:4vh 14px}
.dbup-dialog{width:min(900px,96vw);max-height:90vh;background:#fff;border-radius:20px;box-shadow:0 24px 70px rgba(15,23,42,.3);display:flex;flex-direction:column;overflow:hidden}
.dbup-dialog-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:14px 16px;border-bottom:1px solid #e5edf6;background:#f8fbff}.dbup-dialog-head strong{display:block;color:#0b1f5b;font-size:17px}.dbup-dialog-head span{display:block;color:#64748b;font-size:12.5px;margin-top:3px}
.dbup-dialog-body{padding:14px 16px;overflow:auto;display:grid;gap:12px}.dbup-dialog-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid #e5edf6}
.dbup-chips{display:flex;gap:6px;flex-wrap:wrap}.dbup-chips span{background:#f1f5f9;border-radius:999px;padding:3px 10px;font-size:12px;color:#334155;font-weight:700}
.dbup-sub{font-size:11px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin-bottom:4px}
.dbup-sql{background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere;max-height:46vh;overflow:auto;margin:0}
.dbup-runs{display:grid;gap:6px}.dbup-run{border:1px solid #e2e8f0;border-radius:12px;padding:8px 10px;font-size:12.5px;display:grid;gap:2px}
.dbup-pend{display:grid;gap:6px;margin:0;padding:0;list-style:none;counter-reset:p}.dbup-pend li{border:1px solid #fde68a;background:#fffbeb;border-radius:12px;padding:8px 10px;font-size:13px}.dbup-pend li b{color:#0b1f5b}
.dbup-code{background:#0f172a;color:#e2e8f0;border-radius:12px;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;overflow-wrap:anywhere;margin-top:8px}
@media(max-width:760px){.dbup-hero{grid-template-columns:1fr}.dbup-go{justify-items:stretch;text-align:left}.dbup-btn{width:100%}.dbup-list thead{display:none}.dbup-list,.dbup-list tbody,.dbup-list tr,.dbup-list td{display:block;width:auto!important}.dbup-list tr{border-bottom:1px solid #e2e8f0;padding:6px 0}.dbup-list td{border:0;padding:3px 4px;text-align:left!important}.dbup-dialog{max-height:94vh}.dbup-modal{padding:8px}}
</style>
<div class="dbup-page">
  <section class="dbup-hero">
    <div>
      <h1>Atualizador do banco</h1>
      <p>As alterações de estrutura só acontecem aqui, por ação do administrador da plataforma, na ordem certa, com conferência do arquivo, trava e histórico.</p>
      <div class="dbup-facts"><span>Banco: <?= dbup_h($dbName !== '' ? $dbName : 'não identificado') ?></span><span>MySQL <?= dbup_h($dbVersion !== '' ? $dbVersion : '?') ?></span></div>
    </div>
    <div class="dbup-go">
      <?php if ($pending && !$blocked): ?>
        <button class="dbup-btn" type="button" data-dbup-apply><?= count($pending) === 1 ? 'Fazer atualização' : 'Fazer atualização (' . count($pending) . ')' ?></button>
        <small><?= count($pending) ?> pendente(s) · faça o backup antes</small>
      <?php elseif ($blocked): ?>
        <button class="dbup-btn" type="button" disabled>Fazer atualização</button>
        <small>Bloqueada: arquivo ausente ou alterado</small>
      <?php else: ?>
        <span class="dbup-ok">✓ Banco em dia</span>
      <?php endif; ?>
    </div>
  </section>

  <?php if (is_array($flash)): ?>
    <div class="dbup-alert <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'err' ?>"><?= dbup_h((string)($flash['message'] ?? '')) ?></div>
  <?php endif; ?>
  <?php if ($blocked): ?>
    <div class="dbup-alert err">Há atualização pendente com arquivo ausente ou com conteúdo diferente do registrado (checksum). A execução foi bloqueada: atualize o código (git pull) antes de tentar de novo.</div>
  <?php endif; ?>

  <section class="dbup-card">
    <h2>Atualizações</h2>
    <div class="dbup-muted">Pendentes primeiro; depois as aplicadas, da mais nova para a mais antiga. Clique em Abrir para ver o que cada uma altera.</div>
    <table class="dbup-list">
      <thead><tr><th class="c-date">Data/hora</th><th>Atualização</th><th class="c-status">Situação</th><th class="c-open"></th></tr></thead>
      <tbody>
      <?php foreach ($list as $i => $row): $id = (string)$row['id']; $ok = $lastOk[$id] ?? null; $last = $lastRun[$id] ?? null; $failed = !empty($row['pending']) && $last && strtoupper((string)$last['status']) === 'FAILED'; ?>
        <tr class="<?= !empty($row['pending']) ? 'is-pending' : '' ?>"<?= $i >= 40 ? ' data-dbup-extra hidden' : '' ?>>
          <td class="c-date">
            <?php if (!empty($row['pending'])): ?>—<small>aguardando</small>
            <?php elseif ($ok): ?><?= dbup_h(dbup_dt($ok['finished_at'] ?: $ok['started_at'])) ?><small><?= dbup_h($names[(int)($ok['executed_by_user_id'] ?? 0)] ?? '') ?></small>
            <?php else: ?><?= dbup_h(dbup_id_date($id) ?: '—') ?><small>antes do histórico</small><?php endif; ?>
          </td>
          <td><b><?= dbup_h($row['label']) ?></b></td>
          <td class="c-status">
            <?php if (!empty($row['applied'])): ?><span class="dbup-badge ok">Aplicada</span>
            <?php elseif (empty($row['file_ok'])): ?><span class="dbup-badge err">Bloqueada</span>
            <?php elseif ($failed): ?><span class="dbup-badge err">Falhou</span>
            <?php else: ?><span class="dbup-badge pending">Pendente</span><?php endif; ?>
          </td>
          <td class="c-open"><button class="dbup-open" type="button" data-dbup-open="<?= dbup_h($id) ?>">Abrir</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($list) > 40): ?><div class="dbup-more"><button class="dbup-open" type="button" data-dbup-showall>Mostrar todas (<?= count($list) ?>)</button></div><?php endif; ?>
  </section>

  <section class="dbup-card">
    <h2>Plano de contingência</h2>
    <div class="dbup-muted">Se uma atualização não puder rodar por esta tela, ela pode ser aplicada pelo terminal da VPS:</div>
    <div class="dbup-code">mysql -u USUARIO -p <?= dbup_h($dbName !== '' ? $dbName : 'BANCO') ?> &lt; /var/www/nexa/db/ARQUIVO_DA_MIGRATION.sql</div>
  </section>
</div>

<?php if ($pending && !$blocked): ?>
<div class="dbup-modal" id="dbupApplyModal" hidden>
  <form method="post" class="dbup-dialog" role="dialog" aria-modal="true" aria-labelledby="dbupApplyTitle" data-dbup-form>
    <input type="hidden" name="csrf" value="<?= dbup_h(nexa_schema_updater_csrf_token()) ?>">
    <input type="hidden" name="action" value="apply_all">
    <input type="hidden" name="confirmation" value="ATUALIZAR">
    <div class="dbup-dialog-head"><div><strong id="dbupApplyTitle">Fazer atualização do banco</strong><span>Banco <b><?= dbup_h($dbName) ?></b> · as atualizações abaixo serão aplicadas nesta ordem; se uma falhar, as seguintes não rodam.</span></div><button class="dbup-open" type="button" data-dbup-close>Fechar</button></div>
    <div class="dbup-dialog-body">
      <ol class="dbup-pend">
        <?php foreach ($pending as $p): ?><li><b><?= dbup_h($p['label']) ?></b><div class="dbup-muted"><?= dbup_h($p['description']) ?></div></li><?php endforeach; ?>
      </ol>
      <div class="dbup-alert warn">Faça o backup do banco antes de confirmar.</div>
    </div>
    <div class="dbup-dialog-foot"><button class="dbup-open" type="button" data-dbup-close>Cancelar</button><button class="dbup-btn" type="submit">Confirmar e atualizar</button></div>
  </form>
</div>
<?php endif; ?>

<div class="dbup-modal" id="dbupInfoModal" hidden>
  <div class="dbup-dialog" role="dialog" aria-modal="true" aria-labelledby="dbupInfoTitle">
    <div class="dbup-dialog-head"><div><strong id="dbupInfoTitle">Atualização</strong><span id="dbupInfoSub"></span></div><button class="dbup-open" type="button" data-dbup-close>Fechar</button></div>
    <div class="dbup-dialog-body" id="dbupInfoBody"></div>
  </div>
</div>

<script>
(function(){
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const applyModal=document.getElementById('dbupApplyModal'),infoModal=document.getElementById('dbupInfoModal');
  const body=document.getElementById('dbupInfoBody'),title=document.getElementById('dbupInfoTitle'),sub=document.getElementById('dbupInfoSub');
  const open=m=>{m.hidden=false;document.body.style.overflow='hidden';};
  const close=()=>{[applyModal,infoModal].forEach(m=>{if(m)m.hidden=true;});document.body.style.overflow='';};
  document.querySelectorAll('[data-dbup-apply]').forEach(b=>b.addEventListener('click',()=>applyModal&&open(applyModal)));
  document.querySelectorAll('[data-dbup-close]').forEach(b=>b.addEventListener('click',close));
  [applyModal,infoModal].forEach(m=>m&&m.addEventListener('click',e=>{if(e.target===m)close();}));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
  const form=document.querySelector('[data-dbup-form]');
  if(form)form.addEventListener('submit',()=>{const b=form.querySelector('button[type=submit]');b.disabled=true;b.textContent='Atualizando… não feche a página';form.querySelectorAll('[data-dbup-close]').forEach(x=>x.disabled=true);});
  const all=document.querySelector('[data-dbup-showall]');
  if(all)all.addEventListener('click',()=>{document.querySelectorAll('[data-dbup-extra]').forEach(r=>r.hidden=false);all.parentNode.remove();});
  const badge=st=>st==='SUCCESS'?'<span class="dbup-badge ok">Concluída</span>':(st==='FAILED'?'<span class="dbup-badge err">Falhou</span>':'<span class="dbup-badge run">'+esc(st)+'</span>');
  document.querySelectorAll('[data-dbup-open]').forEach(b=>b.addEventListener('click',()=>{
    title.textContent='Carregando…';sub.textContent='';body.innerHTML='<div class="dbup-muted">Carregando…</div>';open(infoModal);
    fetch('atualizador_banco.php?detalhe='+encodeURIComponent(b.dataset.dbupOpen),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d.ok)throw new Error(d.error||'Erro');
      title.textContent=d.label;sub.textContent=d.description;
      let h='<div class="dbup-chips"><span>'+(d.applied?'Aplicada':'Pendente')+'</span><span>Módulo: '+esc(d.module)+'</span><span>Versão '+d.version+(d.applied?'':' (atual '+d.current+')')+'</span><span>'+esc(d.file)+'</span>'+(d.id_date?'<span>Criada em '+esc(d.id_date)+'</span>':'')+'</div>';
      if(!d.file_ok)h+='<div class="dbup-alert err">'+esc(d.file_message)+'</div>';
      h+='<div><div class="dbup-sub">Execuções</div>'+(d.runs.length?'<div class="dbup-runs">'+d.runs.map(r=>'<div class="dbup-run"><div>'+badge(r.status)+' '+esc(r.start)+(r.end&&r.end!==r.start?' → '+esc(r.end):'')+'</div><div class="dbup-muted">'+[r.user&&('por '+esc(r.user)),r.db&&('banco '+esc(r.db))].filter(Boolean).join(' · ')+'</div>'+(r.error?'<div style="color:#9f1239">'+esc(r.error)+'</div>':'')+'</div>').join('')+'</div>':'<div class="dbup-muted">'+(d.applied?'Aplicada antes de o histórico existir.':'Ainda não executada.')+'</div>')+'</div>';
      h+='<div><div class="dbup-sub">O que é alterado no banco (comandos SQL)</div><pre class="dbup-sql">'+esc(d.sql||'Arquivo não encontrado.')+'</pre></div>';
      body.innerHTML=h;
    }).catch(e=>{title.textContent='Atualização';body.innerHTML='<div class="dbup-alert err">'+esc(e.message)+'</div>';});
  }));
})();
</script>
<?php admin_footer(); ?>
