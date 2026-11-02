<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/fiscal_catalog_admin.php';

fiscal_catalog_admin_ensure_schema($pdo);

if (isset($_GET['template'])) {
  try {
    $template = fiscal_catalog_admin_template((string)$_GET['template']);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $template['filename'] . '"');
    echo "\xEF\xBB\xBF" . $template['content'];
  } catch (Throwable $e) {
    http_response_code(404);
    echo 'Modelo não encontrado.';
  }
  exit;
}

if (empty($_SESSION['saas_catalog_csrf'])) {
  $_SESSION['saas_catalog_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['saas_catalog_csrf'];
$defs = fiscal_catalog_admin_definitions();
$msg = '';
$erro = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('A sessão do formulário expirou. Atualize a página e tente novamente.');
    if (empty($_POST['confirm_official'])) throw new RuntimeException('Confirme que o arquivo foi obtido de fonte oficial ou licenciada e revisado antes da atualização global.');
    if (!empty($_POST['replace_active']) && strtoupper(trim((string)($_POST['replace_confirmation'] ?? ''))) !== 'ATUALIZAR') {
      throw new RuntimeException('Para usar a sincronização completa, digite ATUALIZAR no campo de confirmação.');
    }
    $type = strtolower(trim((string)($_POST['catalog_type'] ?? '')));
    $result = fiscal_catalog_admin_import_upload(
      $pdo,
      $_FILES['catalog_file'] ?? [],
      $type,
      [
        'uf' => $_POST['uf'] ?? '',
        'source_name' => $_POST['source_name'] ?? '',
        'source_url' => $_POST['source_url'] ?? '',
        'version' => $_POST['version'] ?? '',
        'validity_start' => $_POST['validity_start'] ?? '',
        'validity_end' => $_POST['validity_end'] ?? '',
        'preferred_sheet' => $_POST['preferred_sheet'] ?? '',
        'replace_active' => !empty($_POST['replace_active']),
        'force_duplicate' => !empty($_POST['force_duplicate']),
      ],
      (int)(auth_user_id() ?? 0)
    );
    $msg = (string)$result['message'];
    $_SESSION['saas_catalog_csrf'] = bin2hex(random_bytes(24));
    $csrf = (string)$_SESSION['saas_catalog_csrf'];
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$status = fiscal_catalog_admin_status($pdo);
$jobs = fiscal_catalog_admin_jobs($pdo, 60);
$selectedType = strtolower(trim((string)($_POST['catalog_type'] ?? 'ibscbs')));
if (!isset($defs[$selectedType])) $selectedType = 'ibscbs';

saas_layout_start('Catálogos fiscais • Central SaaS', 'Catálogos fiscais globais', 'Atualize NCM, CEST, IBPT, IBS/CBS e tabelas auxiliares em um único ponto, sempre no catálogo global compartilhado.');
?>
<style>
.catalog-warning{background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:18px;padding:15px 17px;line-height:1.5;font-weight:750;margin-bottom:18px}.catalog-warning strong{color:#7c2d12}.catalog-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}.catalog-card{background:#fff;border:1px solid #d9e5fb;border-radius:20px;padding:17px;box-shadow:0 12px 30px rgba(11,31,91,.07);color:#0b1f5b;min-width:0}.catalog-card h3{font-size:17px;margin:0 0 6px}.catalog-card .count{font-size:28px;font-weight:950;letter-spacing:-.04em}.catalog-card .desc{font-size:12px;color:#64748b;line-height:1.4;min-height:34px}.catalog-card .last{font-size:12px;color:#475569;margin-top:10px;padding-top:10px;border-top:1px solid #edf3fb}.catalog-layout{display:grid;grid-template-columns:minmax(350px,.85fr) minmax(0,1.35fr);gap:18px;align-items:start}.catalog-box{background:#fff;border:1px solid #d9e5fb;border-radius:22px;padding:19px;box-shadow:0 18px 48px rgba(9,31,91,.08);color:#0b1f5b}.catalog-box h2{margin:0 0 7px;font-size:20px}.catalog-muted{color:#64748b;font-size:13px;line-height:1.5}.catalog-form label{display:block;font-weight:850;margin:13px 0 6px}.catalog-form input,.catalog-form select{width:100%;border:1px solid #bfd2f4;border-radius:13px;padding:11px 12px;background:#fff;color:#0b1f5b}.catalog-row{display:grid;grid-template-columns:1fr 1fr;gap:11px}.catalog-check{display:flex!important;align-items:flex-start;gap:9px;border:1px solid #d9e5fb;border-radius:15px;padding:11px;background:#f8fbff;margin-top:11px!important}.catalog-check input{width:auto;margin-top:3px}.catalog-danger{background:#fff1f2!important;border-color:#fecdd3!important;color:#9f1239}.catalog-danger input[type=text]{background:#fff}.catalog-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:15px}.source-list{display:grid;gap:10px}.source-item{border:1px solid #dbeafe;border-radius:15px;padding:12px;background:#f8fbff}.source-item strong{display:block}.source-item a{display:inline-flex;margin-top:7px;color:#2454d6;font-weight:850;text-decoration:none}.source-item a:hover{text-decoration:underline}.status-ok{background:#ecfdf5;color:#065f46}.status-erro{background:#fef2f2;color:#991b1b}.status-processando{background:#eff6ff;color:#1d4ed8}.job-status{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:900}.job-error{max-width:420px;white-space:normal;word-break:break-word;color:#991b1b;font-size:12px}.job-message{max-width:420px;white-space:normal;word-break:break-word;font-size:12px}.result-box{border:1px solid #a7f3d0;background:#ecfdf5;color:#065f46;border-radius:16px;padding:13px;margin-top:12px}.sheet-help{font-size:12px;color:#64748b;margin-top:5px}.global-badge{display:inline-flex;padding:5px 9px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:11px;font-weight:900}@media(max-width:1200px){.catalog-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.catalog-layout{grid-template-columns:1fr}}@media(max-width:700px){.catalog-cards,.catalog-row{grid-template-columns:1fr}.catalog-card .desc{min-height:0}}
</style>

<div class="top-actions">
  <a class="btn2 btn2-ghost" href="/saas/dashboard.php">Dashboard</a>
  <a class="btn2 btn2-ghost" href="/saas/logs.php">Logs</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="catalog-warning">
  <strong>Atenção:</strong> esta área altera catálogos compartilhados por todas as empresas. As tributações, perfis e escolhas dos produtos continuam individuais por <code>company_id</code>, mas códigos, vigências e alíquotas globais passam a valer para todo o Nexa.
</div>

<div class="catalog-cards">
  <?php foreach ($status as $type => $item): ?>
    <div class="catalog-card">
      <span class="global-badge">GLOBAL</span>
      <h3><?= e((string)$item['label']) ?></h3>
      <div class="count"><?= number_format((int)$item['count'], 0, ',', '.') ?></div>
      <div class="desc"><?= e((string)$item['description']) ?></div>
      <div class="last">
        <?php if (!empty($item['last'])): ?>
          Última atualização: <strong><?= e(saas_datetime_br($item['last']['finished_at'] ?? $item['last']['created_at'] ?? null)) ?></strong><br>
          Arquivo: <?= e((string)($item['last']['filename'] ?? '—')) ?>
        <?php else: ?>
          Nenhuma atualização registrada pela Central SaaS.
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="catalog-layout">
  <div class="catalog-box">
    <h2>Atualizar catálogo</h2>
    <p class="catalog-muted">Envie CSV, JSON ou XLSX conforme o catálogo. O sistema valida, normaliza, registra o hash e executa a atualização dentro de transação.</p>

    <form method="post" enctype="multipart/form-data" class="catalog-form" id="catalogForm">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <label>Catálogo</label>
      <select name="catalog_type" id="catalogType" required>
        <?php foreach ($defs as $type => $def): ?>
          <option value="<?= e($type) ?>" <?= $selectedType === $type ? 'selected' : '' ?>><?= e((string)$def['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="sheet-help" id="catalogDescription"></div>

      <label>Arquivo da fonte</label>
      <input type="file" name="catalog_file" id="catalogFile" required>
      <div class="sheet-help" id="acceptedFormats"></div>

      <div class="catalog-row">
        <div id="ufWrap">
          <label>UF do IBPT</label>
          <select name="uf" id="catalogUf">
            <option value="">Selecione</option>
            <?php foreach (['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $uf): ?>
              <option value="<?= $uf ?>" <?= (($_POST['uf'] ?? '') === $uf) ? 'selected' : '' ?>><?= $uf ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Versão da tabela</label>
          <input name="version" maxlength="80" value="<?= e((string)($_POST['version'] ?? '')) ?>" placeholder="Ex.: IT 2025.002 v1.60">
        </div>
      </div>

      <div class="catalog-row">
        <div><label>Início da vigência</label><input type="date" name="validity_start" value="<?= e((string)($_POST['validity_start'] ?? '')) ?>"></div>
        <div><label>Fim da vigência</label><input type="date" name="validity_end" value="<?= e((string)($_POST['validity_end'] ?? '')) ?>"></div>
      </div>

      <label>Nome da fonte</label>
      <input name="source_name" id="sourceName" maxlength="190" value="<?= e((string)($_POST['source_name'] ?? '')) ?>">
      <label>Endereço da fonte</label>
      <input type="url" name="source_url" id="sourceUrl" maxlength="500" value="<?= e((string)($_POST['source_url'] ?? '')) ?>">

      <label>Planilha dentro do XLSX — opcional</label>
      <input name="preferred_sheet" maxlength="120" value="<?= e((string)($_POST['preferred_sheet'] ?? '')) ?>" placeholder="Ex.: cClassTrib">
      <div class="sheet-help">Deixe vazio para o Nexa localizar automaticamente a planilha mais compatível.</div>

      <label class="catalog-check">
        <input type="checkbox" name="confirm_official" value="1" required <?= !empty($_POST['confirm_official']) ? 'checked' : '' ?>>
        <span><strong>Arquivo revisado</strong><br><span class="catalog-muted">Confirmo que o arquivo veio de fonte oficial ou licenciada e corresponde à versão que desejo aplicar.</span></span>
      </label>

      <label class="catalog-check">
        <input type="checkbox" name="force_duplicate" value="1" <?= !empty($_POST['force_duplicate']) ? 'checked' : '' ?>>
        <span><strong>Permitir reimportação do mesmo arquivo</strong><br><span class="catalog-muted">Normalmente o hash impede a importação duplicada.</span></span>
      </label>

      <label class="catalog-check catalog-danger">
        <input type="checkbox" name="replace_active" id="replaceActive" value="1" <?= !empty($_POST['replace_active']) ? 'checked' : '' ?>>
        <span><strong>Sincronização completa</strong><br><span class="catalog-muted">Inativa os registros globais atuais do mesmo catálogo/UF e reativa apenas os presentes no arquivo. Use somente com a tabela completa.</span></span>
      </label>
      <div id="replaceConfirmWrap" style="display:none">
        <label>Confirmação da sincronização completa</label>
        <input name="replace_confirmation" autocomplete="off" placeholder="Digite ATUALIZAR">
      </div>

      <div class="catalog-actions">
        <button class="btn2 btn2-dark" type="submit" onclick="return confirmCatalogImport();">Validar e atualizar</button>
        <a class="btn2 btn2-ghost" id="templateLink" href="?template=<?= e($selectedType) ?>">Baixar modelo</a>
      </div>
    </form>

    <?php if ($result): ?>
      <div class="result-box">
        <strong>Atualização concluída.</strong><br>
        Linhas lidas: <?= (int)$result['total'] ?> · válidas: <?= (int)$result['valid'] ?> · importadas: <?= (int)$result['imported'] ?> · ignoradas: <?= (int)$result['invalid'] ?>
        <?php if (!empty($result['sheet'])): ?><br>Planilha utilizada: <?= e((string)$result['sheet']) ?><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="catalog-box">
    <h2>Fontes e procedimento</h2>
    <p class="catalog-muted">O Nexa não baixa arquivos usando URLs digitadas pelo administrador. Isso evita acesso indevido a endereços internos do servidor. Baixe o arquivo da fonte, revise a versão e envie nesta tela.</p>
    <div class="source-list">
      <?php foreach ($defs as $type => $def): ?>
        <div class="source-item" data-source-type="<?= e($type) ?>">
          <strong><?= e((string)$def['label']) ?></strong>
          <span class="catalog-muted"><?= e((string)$def['source_name']) ?></span><br>
          <a href="<?= e((string)$def['source_url']) ?>" target="_blank" rel="noopener noreferrer">Abrir fonte de referência</a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:18px">
  <h3>Histórico de atualizações</h3>
  <div class="table-wrap">
    <table style="min-width:1150px;table-layout:auto">
      <thead><tr><th>Data</th><th>Catálogo</th><th>UF</th><th>Arquivo / versão</th><th>Registros</th><th>Modo</th><th>Administrador</th><th>Status</th><th>Resultado</th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $job): ?>
        <?php $statusClass = strtolower((string)$job['status']) === 'concluido' ? 'status-ok' : (strtolower((string)$job['status']) === 'erro' ? 'status-erro' : 'status-processando'); ?>
        <tr>
          <td><?= e(saas_datetime_br($job['finished_at'] ?? $job['created_at'] ?? null)) ?></td>
          <td><strong><?= e((string)($defs[$job['catalog_type']]['label'] ?? $job['catalog_type'])) ?></strong></td>
          <td><?= e((string)($job['scope_uf'] ?? '—')) ?></td>
          <td><?= e((string)($job['filename'] ?? '—')) ?><br><span class="muted"><?= e((string)($job['version'] ?? '')) ?></span></td>
          <td><?= number_format((int)$job['rows_imported'],0,',','.') ?> / <?= number_format((int)$job['rows_total'],0,',','.') ?><br><span class="muted">ignoradas: <?= number_format((int)$job['rows_invalid'],0,',','.') ?></span></td>
          <td><?= (int)$job['replace_active'] === 1 ? 'Completa' : 'Mesclar' ?></td>
          <td><?= e((string)($job['admin_name'] ?? $job['admin_email'] ?? '—')) ?></td>
          <td><span class="job-status <?= e($statusClass) ?>"><?= e((string)$job['status']) ?></span></td>
          <td>
            <?php if (!empty($job['error_message'])): ?><div class="job-error"><?= e((string)$job['error_message']) ?></div>
            <?php else: ?><div class="job-message"><?= e((string)($job['message'] ?? '')) ?></div><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$jobs): ?><tr><td colspan="9" class="muted">Nenhuma atualização registrada.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const catalogDefs = <?= json_encode($defs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const typeSelect = document.getElementById('catalogType');
const fileInput = document.getElementById('catalogFile');
const ufWrap = document.getElementById('ufWrap');
const ufSelect = document.getElementById('catalogUf');
const sourceName = document.getElementById('sourceName');
const sourceUrl = document.getElementById('sourceUrl');
const description = document.getElementById('catalogDescription');
const accepted = document.getElementById('acceptedFormats');
const templateLink = document.getElementById('templateLink');
const replaceActive = document.getElementById('replaceActive');
const replaceWrap = document.getElementById('replaceConfirmWrap');

function refreshCatalogForm(forceSource) {
  const type = typeSelect.value;
  const def = catalogDefs[type] || {};
  description.textContent = def.description || '';
  accepted.textContent = 'Formatos aceitos: ' + (def.extensions || []).map(v => v.toUpperCase()).join(', ') + '. Limite: 50 MB.';
  fileInput.accept = (def.extensions || []).map(v => '.' + v).join(',');
  ufWrap.style.display = def.requires_uf ? 'block' : 'none';
  ufSelect.required = !!def.requires_uf;
  if (!def.requires_uf) ufSelect.value = '';
  if (forceSource || !sourceName.value.trim()) sourceName.value = def.source_name || '';
  if (forceSource || !sourceUrl.value.trim()) sourceUrl.value = def.source_url || '';
  templateLink.href = '?template=' + encodeURIComponent(type);
}
function refreshReplace() { replaceWrap.style.display = replaceActive.checked ? 'block' : 'none'; }
typeSelect.addEventListener('change', () => refreshCatalogForm(true));
replaceActive.addEventListener('change', refreshReplace);
refreshCatalogForm(false);
refreshReplace();
function confirmCatalogImport() {
  const label = (catalogDefs[typeSelect.value] || {}).label || typeSelect.value;
  const mode = replaceActive.checked ? 'SINCRONIZAÇÃO COMPLETA' : 'MESCLAR/ATUALIZAR';
  return window.confirm('Atualizar o catálogo global “' + label + '” no modo ' + mode + '? Esta operação pode afetar todas as empresas.');
}
</script>
<?php saas_layout_end(); ?>
