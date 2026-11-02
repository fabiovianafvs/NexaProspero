<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/multi_company.php';
require_once __DIR__ . '/../config/saas_modules.php';

auth_start();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /saas/grupos_empresariais.php'); exit; }
if (!multi_company_schema_ready($pdo)) die('Fundação Multiempresa não instalada. Execute db/20260916_multiempresa_fundacao_v1.sql.');

if (empty($_SESSION['saas_business_group_csrf'])) $_SESSION['saas_business_group_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['saas_business_group_csrf'];
$msg = trim((string)($_GET['ok'] ?? ''));
$erro = '';

$loadGroup = static function(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare('SELECT * FROM business_groups WHERE id = ? LIMIT 1');
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
};

$group = $loadGroup($pdo, $id);
if (!$group) die('Grupo Empresarial não encontrado.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('A sessão do formulário expirou. Atualize a página e tente novamente.');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_group') {
      $name = trim((string)($_POST['name'] ?? ''));
      $mode = (string)($_POST['operation_mode'] ?? 'integrated');
      $notes = trim((string)($_POST['notes'] ?? ''));
      $active = !empty($_POST['is_active']) ? 1 : 0;
      if ($name === '') throw new RuntimeException('Informe o nome do Grupo Empresarial.');
      if (!in_array($mode, ['integrated','isolated'], true)) throw new RuntimeException('Modo de operação inválido.');
      $pdo->prepare('UPDATE business_groups SET name=?, operation_mode=?, notes=?, is_active=?, updated_at=NOW() WHERE id=?')
        ->execute([$name, $mode, ($notes !== '' ? $notes : null), $active, $id]);
      $msg = 'Dados do Grupo Empresarial atualizados.';
    }

    if ($action === 'save_companies') {
      $selected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['company_ids'] ?? [])), static fn($v) => $v > 0)));
      $primary = (int)($_POST['primary_company_id'] ?? 0);
      if ($primary > 0 && !in_array($primary, $selected, true)) throw new RuntimeException('A empresa principal precisa estar marcada no grupo.');
      if ($selected && $primary <= 0) $primary = (int)$selected[0];

      $pdo->beginTransaction();
      try {
        if ($selected) {
          $ph = implode(',', array_fill(0, count($selected), '?'));
          $check = $pdo->prepare("SELECT gc.company_id, g.name AS group_name FROM business_group_companies gc INNER JOIN business_groups g ON g.id=gc.business_group_id WHERE gc.company_id IN ($ph) AND gc.business_group_id <> ? LIMIT 1");
          $check->execute(array_merge($selected, [$id]));
          $conflict = $check->fetch(PDO::FETCH_ASSOC);
          if ($conflict) throw new RuntimeException('Uma das empresas selecionadas já pertence ao grupo "' . (string)$conflict['group_name'] . '". Remova-a do outro grupo antes.');
        }

        $pdo->prepare('DELETE FROM business_group_companies WHERE business_group_id = ?')->execute([$id]);
        if ($selected) {
          $ins = $pdo->prepare('INSERT INTO business_group_companies (business_group_id,company_id,is_primary,is_active,created_at) VALUES (?,?,?,?,NOW())');
          foreach ($selected as $companyId) $ins->execute([$id, $companyId, ($companyId === $primary ? 1 : 0), 1]);
        }
        $pdo->commit();
        $msg = 'Empresas do grupo atualizadas.';
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }
    }

    $_SESSION['saas_business_group_csrf'] = bin2hex(random_bytes(24));
    $csrf = (string)$_SESSION['saas_business_group_csrf'];
    $group = $loadGroup($pdo, $id) ?: $group;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$linked = $pdo->prepare('SELECT company_id,is_primary FROM business_group_companies WHERE business_group_id=? AND is_active=1');
$linked->execute([$id]);
$linkedRows = $linked->fetchAll(PDO::FETCH_ASSOC) ?: [];
$linkedIds = [];
$primaryId = 0;
foreach ($linkedRows as $r) {
  $linkedIds[] = (int)$r['company_id'];
  if (!empty($r['is_primary'])) $primaryId = (int)$r['company_id'];
}

$companiesSt = $pdo->prepare("\n  SELECT c.id,c.name,c.slug,c.status,other.business_group_id AS other_group_id,og.name AS other_group_name\n  FROM companies c\n  LEFT JOIN business_group_companies other ON other.company_id=c.id AND other.business_group_id<>?\n  LEFT JOIN business_groups og ON og.id=other.business_group_id\n  ORDER BY c.name ASC,c.id ASC\n");
$companiesSt->execute([$id]);
$companies = $companiesSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$modeIntegrated = (string)$group['operation_mode'] === 'integrated';
saas_layout_start('Grupo Empresarial • Central SaaS', 'Grupo Empresarial', 'Defina o relacionamento entre empresas. A operação do ERP ainda permanece no modelo atual nesta etapa.');
?>
<style>
.bge-grid{display:grid;grid-template-columns:minmax(320px,.8fr) minmax(0,1.6fr);gap:16px}.bge-form{display:grid;gap:12px}.bge-form label{font-weight:850}.bge-company-list{display:grid;gap:8px;max-height:620px;overflow:auto;padding-right:4px}.bge-company{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:center;border:1px solid #dbe7f6;border-radius:15px;padding:11px 12px;background:#fff}.bge-company.disabled{background:#f8fafc;opacity:.7}.bge-company input{width:auto}.bge-company-name{font-weight:900;color:#0b1f5b}.bge-company-sub{font-size:12px;color:#64748b;margin-top:3px}.bge-primary{display:flex;gap:6px;align-items:center;font-size:12px;font-weight:850;color:#334155}.bge-feature{display:flex;gap:7px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.bge-feature-pill{display:inline-flex;padding:5px 8px;border-radius:999px;font-weight:900;font-size:11px;background:#ecfdf5;color:#166534;border:1px solid #a7f3d0}.bge-feature-pill.off{background:#fef2f2;color:#991b1b;border-color:#fecaca}.bge-feature-link{font-size:11px;font-weight:850;color:#1d4ed8;text-decoration:none}.bge-mode-info{padding:12px 14px;border-radius:14px;background:#effaff;border:1px solid #bae6fd;color:#075985;font-weight:700;line-height:1.45}.bge-warning{padding:12px 14px;border-radius:14px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-weight:700;line-height:1.45}@media(max-width:1000px){.bge-grid{grid-template-columns:1fr}.bge-company{grid-template-columns:auto minmax(0,1fr)}.bge-primary{grid-column:2}}
</style>
<div class="top-actions"><a class="btn2 btn2-ghost" href="/saas/grupos_empresariais.php">Voltar</a></div>
<?php if ($msg !== ''): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro !== ''): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<div class="bge-grid">
  <section class="panel">
    <h3>Dados do grupo</h3>
    <form method="post" class="bge-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save_group"><input type="hidden" name="id" value="<?= $id ?>">
      <label>Nome<input name="name" maxlength="150" required value="<?= e((string)$group['name']) ?>"></label>
      <label>Modo de operação
        <select name="operation_mode">
          <option value="integrated" <?= $modeIntegrated ? 'selected' : '' ?>>Integrado — mesma atividade</option>
          <option value="isolated" <?= !$modeIntegrated ? 'selected' : '' ?>>Isolado — ramos/empresas distintas</option>
        </select>
      </label>
      <div class="bge-mode-info"><?= $modeIntegrated ? '<strong>Integrado:</strong> perfil poderá ser único no grupo. Compartilhamentos de cadastros só serão ativados posteriormente e de forma explícita.' : '<strong>Isolado:</strong> o vínculo permitirá login/troca de empresa pelo mesmo usuário, sem compartilhamento de dados entre empresas.' ?></div>
      <label>Observações<textarea name="notes" rows="5"><?= e((string)($group['notes'] ?? '')) ?></textarea></label>
      <label style="display:flex;gap:8px;align-items:center"><input style="width:auto" type="checkbox" name="is_active" value="1" <?= !empty($group['is_active']) ? 'checked' : '' ?>> Grupo ativo</label>
      <button class="btn2 btn2-dark" type="submit">Salvar grupo</button>
    </form>
  </section>

  <section class="panel">
    <h3>Empresas do grupo</h3>
    <div class="bge-warning">Vincular uma empresa ao grupo <strong>não altera COMPANY_ID, login, estoque, vendas, financeiro, fiscal ou O.S.</strong> A partir da V2.1, o selo abaixo também mostra se o módulo <strong>Multiempresa</strong> está habilitado para cada empresa vinculada. Mesmo habilitado, F11/contexto ainda permanecem desligados até a V3/V4.</div>
    <form method="post" class="bge-form" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save_companies"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="bge-company-list">
      <?php foreach ($companies as $c): ?>
        <?php $cid=(int)$c['id']; $checked=in_array($cid,$linkedIds,true); $blocked=!empty($c['other_group_id']); ?>
        <div class="bge-company <?= $blocked ? 'disabled' : '' ?>">
          <input type="checkbox" name="company_ids[]" value="<?= $cid ?>" <?= $checked ? 'checked' : '' ?> <?= $blocked ? 'disabled' : '' ?>>
          <div><div class="bge-company-name"><?= e((string)$c['name']) ?></div><div class="bge-company-sub">ID <?= $cid ?> · <?= e((string)$c['slug']) ?> · <?= e((string)$c['status']) ?><?php if ($blocked): ?> · já pertence a <?= e((string)$c['other_group_name']) ?><?php endif; ?></div></div>
          <div>
            <label class="bge-primary"><input type="radio" name="primary_company_id" value="<?= $cid ?>" <?= $primaryId===$cid ? 'checked' : '' ?> <?= $blocked ? 'disabled' : '' ?>> Principal</label>
            <?php if ($checked): ?>
              <?php $featureOn = multi_company_feature_enabled($pdo, $cid); ?>
              <div class="bge-feature" style="margin-top:6px">
                <span class="bge-feature-pill <?= $featureOn ? '' : 'off' ?>">Multiempresa <?= $featureOn ? 'habilitado' : 'desabilitado' ?></span>
                <a class="bge-feature-link" href="/saas/empresa.php?id=<?= $cid ?>#modulesForm">Módulos</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <button class="btn2 btn2-dark" type="submit">Salvar empresas</button>
    </form>
  </section>
</div>
<?php saas_layout_end(); ?>
