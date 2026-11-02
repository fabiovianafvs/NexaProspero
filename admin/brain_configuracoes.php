<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/brain.php';

if (function_exists('access_is_company_master') && !access_is_company_master()) {
  access_render_denied('Somente o administrador principal da empresa pode alterar as regras do Nexa Brain.');
}

function brain_cfg_h(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

$companyId = (int)COMPANY_ID;
$userId = (int)(auth_user_id() ?? 0);
$flash = null;
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['brain_config_flash'])) { $flash = $_SESSION['brain_config_flash']; unset($_SESSION['brain_config_flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    brain_actions_csrf_check((string)($_POST['csrf'] ?? ''),'brain_actions_config');
    $catalog = brain_actions_rule_catalog($pdo,$companyId);
    $posted = (array)($_POST['rules'] ?? []);
    foreach ($catalog as $code=>$rule) {
      $data = (array)($posted[$code] ?? []);
      $enabled = !empty($data['enabled']);
      $severity = trim((string)($data['severity'] ?? ''));
      brain_actions_save_rule_setting($pdo,$companyId,$code,$enabled,(array)($data['settings'] ?? []),$severity,$userId);
    }
    $result = brain_actions_evaluate_company($pdo,$companyId,'settings_saved');
    try { brain_dashboard_rebuild_cache($pdo,$companyId); } catch (Throwable $e) {}
    $_SESSION['brain_config_flash'] = ['type'=>'ok','message'=>'Configurações salvas. O Brain reavaliou ' . (int)$result['rules_evaluated'] . ' regra(s).'];
  } catch (Throwable $e) {
    $_SESSION['brain_config_flash'] = ['type'=>'err','message'=>$e->getMessage()];
  }
  header('Location: brain_configuracoes.php');
  exit;
}

$catalog = brain_actions_rule_catalog($pdo,$companyId);
$labels = brain_actions_setting_labels();
admin_topbar('Configurações do Nexa Brain','brain');
?>
<link rel="stylesheet" href="/assets/brain.css?v=2.3.0">
<main class="brain-shell">
  <section class="brain-config-head">
    <div><span class="brain-kicker">PARÂMETROS POR EMPRESA</span><h1>Regras da Central de Ações</h1><p>As regras são globais, mas limites, gravidade e ativação são individuais para esta empresa.</p></div>
    <a class="brain-secondary-button" href="brain.php">Voltar ao Brain</a>
  </section>

  <?php if (!brain_actions_schema_ready($pdo)): ?>
    <div class="brain-system-warning"><strong>Estrutura não instalada.</strong> Execute o worker <code>migrate_brain_actions_v230.php</code>.</div>
  <?php endif; ?>
  <?php if ($flash): ?><div class="brain-flash <?= brain_cfg_h((string)($flash['type'] ?? 'ok')) ?>"><?= brain_cfg_h((string)($flash['message'] ?? '')) ?></div><?php endif; ?>

  <form method="post" class="brain-rules-form">
    <input type="hidden" name="csrf" value="<?= brain_cfg_h(brain_actions_csrf_token('brain_actions_config')) ?>">
    <div class="brain-rules-grid">
      <?php foreach ($catalog as $code=>$rule): ?>
        <article class="brain-rule-card">
          <div class="brain-rule-card-head">
            <div><span class="brain-module-tag"><?= brain_cfg_h((string)$rule['module_code']) ?></span><h2><?= brain_cfg_h((string)$rule['name']) ?></h2></div>
            <label class="brain-switch"><input type="checkbox" name="rules[<?= brain_cfg_h($code) ?>][enabled]" value="1" <?= !empty($rule['enabled'])?'checked':'' ?>><span>Ativa</span></label>
          </div>
          <p><?= brain_cfg_h((string)$rule['description']) ?></p>
          <div class="brain-rule-fields">
            <label>Gravidade
              <select name="rules[<?= brain_cfg_h($code) ?>][severity]">
                <?php foreach (['critical'=>'Crítica','high'=>'Alta','warning'=>'Atenção','opportunity'=>'Oportunidade','info'=>'Informativa'] as $value=>$label): ?>
                  <option value="<?= $value ?>" <?= (string)$rule['severity']===$value?'selected':'' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php foreach ((array)$rule['settings'] as $key=>$value): $meta=$labels[$key]??['label'=>ucfirst(str_replace('_',' ',$key)),'step'=>'0.01']; ?>
              <label><?= brain_cfg_h((string)$meta['label']) ?>
                <input type="number" name="rules[<?= brain_cfg_h($code) ?>][settings][<?= brain_cfg_h((string)$key) ?>]" value="<?= brain_cfg_h((string)$value) ?>" step="<?= brain_cfg_h((string)($meta['step']??'0.01')) ?>" <?= isset($meta['min'])?'min="'.brain_cfg_h((string)$meta['min']).'"':'' ?> <?= isset($meta['max'])?'max="'.brain_cfg_h((string)$meta['max']).'"':'' ?>>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="brain-rule-recommendation"><strong>Ação recomendada:</strong> <?= brain_cfg_h((string)$rule['recommendation']) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="brain-config-save"><button type="submit">Salvar e reavaliar agora</button></div>
  </form>
</main>
<?php admin_footer(); ?>
