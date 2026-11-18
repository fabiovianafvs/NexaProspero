<?php
declare(strict_types=1);

// Central SaaS > Atestados do Ponto: o desenvolvedor (Próspero Tecnologia) emite, para cada empresa
// cliente com o Ponto Eletrônico ligado, o Atestado Técnico e Termo de Responsabilidade (Portaria
// MTE 671/2021, art. 89). Aqui ele baixa o modelo já preenchido com os dados da empresa, assina com
// o e-CPF fora do sistema e envia de volta; o PDF assinado fica guardado no painel da empresa
// (Ponto > Arquivos legais), igual ao envio feito por lá. Só platform_admin (garantido pelo _bootstrap).

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/ponto_assinatura.php';

if (empty($_SESSION['saas_ponto_attr_csrf'])) $_SESSION['saas_ponto_attr_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['saas_ponto_attr_csrf'];
$tipos = ['REP-P' => 'REP-P (registro do ponto)', 'PTRP' => 'PTRP (tratamento do ponto)'];

/** Empresa com o Ponto Eletrônico ligado (o id nunca é aceito sem essa conferência). */
function spa_empresa(PDO $pdo, int $cid): ?array {
  if ($cid <= 0 || !saas_company_has_module($pdo, $cid, 'ponto_eletronico')) return null;
  $st = $pdo->prepare('SELECT id, name, slug FROM companies WHERE id = ? LIMIT 1');
  $st->execute([$cid]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Modelo para assinar (já preenchido com o estabelecimento escolhido).
if (isset($_GET['modelo'])) {
  $tipo = (string)$_GET['modelo'];
  $cid = (int)($_GET['c'] ?? 0);
  $emp = spa_empresa($pdo, $cid);
  $estab = $emp && isset($tipos[$tipo]) ? ponto_estabelecimento_get($pdo, $cid, (int)($_GET['e'] ?? 0)) : null;
  if (!$estab) { http_response_code(404); exit('Empresa ou estabelecimento não encontrado.'); }
  $pdf = ponto_attr_pdf($pdo, $cid, $estab, $tipo);
  $nome = 'Atestado_' . str_replace('-', '', $tipo) . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string)$emp['slug']) . '_' . ponto_digits((string)$estab['documento']) . '_para_assinar.pdf';
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . $nome . '"');
  header('Cache-Control: no-store');
  echo $pdf;
  exit;
}

// Abrir um atestado já guardado.
if (isset($_GET['ver'])) {
  $cid = (int)($_GET['c'] ?? 0);
  $a = spa_empresa($pdo, $cid) ? (ponto_attr_list($pdo, $cid)[(int)$_GET['ver']] ?? null) : null;
  $f = $a ? ponto_attr_arquivo($a) : null;
  if (!$f) { http_response_code(404); exit('Atestado não encontrado.'); }
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="Atestado_' . str_replace('-', '', (string)$a['tipo']) . '.pdf"');
  header('X-Content-Type-Options: nosniff');
  readfile($f);
  exit;
}

$msg = ''; $erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $cid = (int)($_POST['company_id'] ?? 0);
    $emp = spa_empresa($pdo, $cid);
    if (!$emp) throw new RuntimeException('Empresa não encontrada ou sem o Ponto Eletrônico ligado.');
    if (!ponto_ready($pdo)) throw new RuntimeException('As tabelas do Ponto Eletrônico ainda não foram instaladas no banco.');
    $f = $_FILES['arquivo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new RuntimeException('Escolha o PDF assinado.');
    ponto_attr_salvar($pdo, $cid, (string)($_POST['tipo'] ?? ''), (string)$f['tmp_name'], (string)$f['name']);
    header('Location: /saas/ponto_atestados.php?ok=' . rawurlencode('Atestado ' . (string)($_POST['tipo'] ?? '') . ' de ' . $emp['name'] . ' guardado. Ele já aparece em Ponto > Arquivos legais da empresa.') . '#c' . $cid);
    exit;
  } catch (Throwable $e) {
    $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar o atestado.';
  }
}
if ($msg === '' && isset($_GET['ok'])) $msg = (string)$_GET['ok'];

// Empresas com o Ponto Eletrônico ligado.
$rows = [];
$pontoOk = ponto_ready($pdo);
foreach ($pdo->query('SELECT id, name, slug FROM companies ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) as $c) {
  $cid = (int)$c['id'];
  if (!saas_company_has_module($pdo, $cid, 'ponto_eletronico')) continue;
  $estabs = $pontoOk ? ponto_options($pdo, $cid, 'estabelecimentos', false) : [];
  $attrs = ponto_attr_list($pdo, $cid);
  $tem = [];
  foreach ($attrs as $a) $tem[(string)$a['tipo']] = true;
  $rows[] = ['id' => $cid, 'name' => (string)$c['name'], 'slug' => (string)$c['slug'], 'estabs' => $estabs, 'attrs' => $attrs,
    'ok' => isset($tem['REP-P'], $tem['PTRP']), 'tem' => $tem];
}
$total = count($rows);
$completos = count(array_filter($rows, static fn($r) => $r['ok']));
$plat = ponto_plataforma();
$semInpi = trim((string)($plat['inpi'] ?? '')) === '';

saas_layout_start('Atestados do Ponto • Central SaaS', 'Atestados Técnicos do Nexa Ponto', 'Um atestado por empresa cliente com o Ponto ligado: baixe o modelo, assine com o seu e-CPF e envie de volta.');
?>
<style>
.spa-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}
.spa-kpi{background:#fff;border:1px solid #d9e5fb;border-radius:16px;padding:10px 14px}
.spa-kpi small{display:block;color:#64748b;font-size:12px}.spa-kpi b{font-size:20px;color:#0b1f5b}
.spa-card{background:#fff;border:1px solid #d9e5fb;border-radius:18px;padding:16px;margin-bottom:12px;color:#0b1f5b}
.spa-card h3{margin:0 0 4px;font-size:16px}
.spa-pill{display:inline-flex;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:800;white-space:nowrap;margin-right:4px}
.spa-pill.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.spa-pill.warn{background:#fff7ed;color:#c2410c;border:1px solid #fdba74}
.spa-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px}
.spa-row label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:#475569}
.spa-row select,.spa-row input[type=file]{min-height:38px;border:1px solid #cbd5e1;border-radius:10px;padding:6px 8px;background:#fff}
.spa-muted{color:#64748b;font-size:13px}
.spa-list{margin:8px 0 0;padding:0;list-style:none;font-size:13px}.spa-list li{padding:4px 0;border-top:1px dashed #e2e8f0}
@media(max-width:900px){.spa-grid{grid-template-columns:1fr}}
</style>

<?php if ($msg !== ''): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro !== ''): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="spa-grid">
  <div class="spa-kpi"><small>Empresas com o Ponto ligado</small><b><?= (int)$total ?></b></div>
  <div class="spa-kpi"><small>Com os dois atestados (REP-P e PTRP)</small><b><?= (int)$completos ?></b></div>
  <div class="spa-kpi"><small>Faltando atestado</small><b style="color:<?= $total - $completos ? '#c2410c' : '#065f46' ?>"><?= (int)($total - $completos) ?></b></div>
</div>

<div class="spa-card">
  <b>Como fazer, para cada empresa:</b>
  <ol class="spa-muted" style="margin:6px 0 0;padding-left:18px;line-height:1.6">
    <li>Baixe o modelo REP-P e o PTRP, já preenchidos com o estabelecimento da empresa.</li>
    <li>Assine cada PDF com o seu e-CPF (responsável legal e técnico da Próspero), no assinador da certificadora ou no Adobe Reader.</li>
    <li>Envie o PDF assinado aqui. Ele fica guardado no painel da empresa (Ponto &gt; Arquivos legais) para a fiscalização.</li>
  </ol>
  <?php if ($semInpi): ?><p class="alert-err" style="margin:10px 0 0">O número do registro no INPI ainda não foi gravado no servidor: o modelo sai com "(registro no INPI)". Depois do registro, rode de novo <code>configurar_certificado_ponto.sh</code> e emita os atestados.</p><?php endif; ?>
  <?php if (!$pontoOk): ?><p class="alert-err" style="margin:10px 0 0">As tabelas do Ponto Eletrônico ainda não foram instaladas no banco (Atualizador do Banco).</p><?php endif; ?>
</div>

<?php if (!$rows): ?><div class="spa-card spa-muted">Nenhuma empresa com o módulo Ponto Eletrônico ligado.</div><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <section class="spa-card" id="c<?= (int)$r['id'] ?>">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <div><h3><?= e($r['name']) ?></h3><span class="spa-muted"><?= e($r['slug']) ?></span></div>
      <div><?php foreach (array_keys($tipos) as $tp): ?><span class="spa-pill <?= isset($r['tem'][$tp]) ? 'ok' : 'warn' ?>"><?= e($tp) ?>: <?= isset($r['tem'][$tp]) ? 'enviado' : 'falta' ?></span><?php endforeach; ?></div>
    </div>
    <?php if (!$r['estabs']): ?>
      <p class="spa-muted" style="margin:10px 0 0">A empresa ainda não cadastrou o estabelecimento (CNPJ) em Ponto &gt; Estabelecimentos: o modelo precisa desses dados.</p>
    <?php else: ?>
      <form method="get" class="spa-row">
        <input type="hidden" name="c" value="<?= (int)$r['id'] ?>">
        <label>Estabelecimento<select name="e"><?php foreach ($r['estabs'] as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
        <button class="btn2 btn2-ghost" type="submit" name="modelo" value="REP-P">Baixar modelo REP-P</button>
        <button class="btn2 btn2-ghost" type="submit" name="modelo" value="PTRP">Baixar modelo PTRP</button>
      </form>
      <form method="post" enctype="multipart/form-data" class="spa-row">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
        <label>Tipo<select name="tipo"><?php foreach ($tipos as $k => $v): ?><option value="<?= e($k) ?>" <?= !isset($r['tem']['REP-P']) ? ($k === 'REP-P' ? 'selected' : '') : ($k === 'PTRP' ? 'selected' : '') ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label>PDF assinado<input type="file" name="arquivo" accept="application/pdf" required></label>
        <button class="btn2" type="submit">Enviar atestado assinado</button>
      </form>
    <?php endif; ?>
    <?php if ($r['attrs']): ?>
      <ul class="spa-list"><?php foreach (array_reverse($r['attrs'], true) as $i => $a): ?>
        <li><b><?= e((string)$a['tipo']) ?></b> · <?= e((string)$a['nome']) ?> · <span class="spa-muted"><?= e(saas_datetime_br($a['enviado_em'] ?? '')) ?></span> · <a href="/saas/ponto_atestados.php?<?= e(http_build_query(['c' => $r['id'], 'ver' => $i])) ?>" target="_blank" rel="noopener">abrir</a></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
<?php saas_layout_end(); ?>
