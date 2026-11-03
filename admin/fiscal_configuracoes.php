<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/nexa_st_recipient.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_settings_write')
  : (int)COMPANY_ID;
$fiscalSettingsWriteF11 = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && $cid !== (int)COMPANY_ID;
if ($fiscalSettingsWriteF11) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
$fiscalSettingsMcCsrf = $fiscalSettingsWriteF11 && function_exists('multi_company_context_csrf_token')
  ? multi_company_context_csrf_token()
  : '';
$fiscalSettingsContextToken = $fiscalSettingsWriteF11
  ? trim((string)(is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? (($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? '')) : ''))
  : '';
$fiscalSettingsUrl = static function(string $url): string {
  return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
// Configuração ST é isolada por empresa e não admite escrita em contexto F11.
$stCanManageHere = !$fiscalSettingsWriteF11 && (
  (function_exists('auth_role') && auth_role() === 'platform_admin') ||
  (function_exists('access_is_company_master') && access_is_company_master())
);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['nexa_st_csrf'])) $_SESSION['nexa_st_csrf'] = bin2hex(random_bytes(24));
$stSchemaReady = false;
try {
  $stSchemaReady = (bool)$pdo->query("SHOW COLUMNS FROM finance_people LIKE 'tax_regime'")->fetch(PDO::FETCH_ASSOC)
    && (bool)$pdo->query("SHOW TABLES LIKE 'fiscal_st_state_rates'")->fetchColumn()
    && (bool)$pdo->query("SHOW TABLES LIKE 'fiscal_st_ncm_mva'")->fetchColumn();
} catch (Throwable $e) {}
$err = '';
$openModal = (string)($_GET['modal'] ?? '');

if (!function_exists('fiscal_cfg_user_has_company_access')) {
  function fiscal_cfg_user_has_company_access(PDO $pdo, int $companyId, int $userId): bool {
    if ($companyId <= 0 || $userId <= 0) return false;
    try {
      $st = $pdo->prepare("SELECT company_id,is_active FROM users WHERE id=? LIMIT 1");
      $st->execute([$userId]);
      $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$u || empty($u['is_active'])) return false;
      if ((int)($u['company_id'] ?? 0) === $companyId) return true;
    } catch (Throwable $e) { return false; }
    try {
      if (function_exists('fiscal_table_exists') && fiscal_table_exists($pdo, 'user_company_access')) {
        $st = $pdo->prepare('SELECT 1 FROM user_company_access WHERE user_id=? AND company_id=? AND is_active=1 LIMIT 1');
        $st->execute([$userId, $companyId]);
        return (bool)$st->fetchColumn();
      }
    } catch (Throwable $e) {}
    return false;
  }
}

if (!$fiscalSettingsWriteF11) {
  try { if (function_exists('fiscal_ensure_dfe_distribution_schema')) fiscal_ensure_dfe_distribution_schema($pdo); } catch (Throwable $e) {}
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = strtolower(trim((string)($_POST['action'] ?? 'save_config')));

    if ($fiscalSettingsWriteF11) {
      $allowed = ['save_config','save_series','toggle_series','save_certificate','activate_certificate','delete_certificate','save_user_series_preferences'];
      if (!in_array($action, $allowed, true)) {
        throw new RuntimeException('V11.35.1 Multiempresa: somente parâmetros fiscais, séries, preferências de série por usuário e certificados podem ser alterados pelo F11. Busca DF-e e operações SEFAZ continuam bloqueadas.');
      }
      if (!function_exists('multi_company_context_permission_allowed')
          || !multi_company_context_permission_allowed($pdo, 'fiscal_configuracoes')) {
        throw new RuntimeException('Seu perfil na empresa selecionada não possui permissão para alterar as Configurações fiscais.');
      }
      if (!function_exists('multi_company_context_csrf_valid')
          || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
        throw new RuntimeException('Contexto Multiempresa expirado. Atualize a página e tente novamente.');
      }
    }

    if ($action === 'save_st_recipient') {
      if (!$stCanManageHere) throw new RuntimeException('Somente o administrador principal da empresa pode alterar o ICMS-ST fora do contexto F11.');
      if (!$stSchemaReady) throw new RuntimeException('Execute a migration de ICMS-ST no Atualizador do Banco antes de habilitar a regra.');
      if (!hash_equals((string)$_SESSION['nexa_st_csrf'], (string)($_POST['csrf_st'] ?? ''))) {
        throw new RuntimeException('Validação de segurança expirada. Atualize a página.');
      }
      // Ambiente é verificado no banco, nunca enviado como campo do formulário.
      $stCompanyConfig = fiscal_company_config($pdo, $cid);
      $stEnvironment = strtoupper(trim((string)($stCompanyConfig['environment'] ?? '')));
      $stEnabled = !empty($_POST['enabled']);
      if ($stEnabled && $stEnvironment !== 'HOMOLOGACAO') {
        throw new RuntimeException('Para habilitar esta regra, primeiro selecione e salve o ambiente HOMOLOGAÇÃO. Em produção somente é possível desabilitá-la.');
      }
      $stFlags = nexa_st_settings($pdo, $cid);
      $stFlags['enabled'] = $stEnabled;
      // Não permitir, por este formulário, aprovação de emissão em produção.
      $stFlags['production_approved'] = false;
      $stFlags['updated_by'] = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
      $stJson = json_encode($stFlags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      $stSave = $pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()');
      $stSave->execute([$cid, 'fiscal_st_recipient_v1', $stJson]);
      header('Location: '.$fiscalSettingsUrl('fiscal_configuracoes.php?ok='.rawurlencode('ICMS-ST da empresa salvo.').'#icmsStDestinatario'));
      exit;
    }
    if ($action === 'save_config') {
      fiscal_save_company_config($pdo, $cid, $_POST);
      // % aproximado de tributos do Simples Nacional (pTotTribSN da NFS-e Nacional).
      if (array_key_exists('nfse_sn_total_tax_rate', $_POST)) {
        $snRateInput = str_replace(',', '.', trim((string)$_POST['nfse_sn_total_tax_rate']));
        $snRate = is_numeric($snRateInput) ? max(0.0, min(100.0, round((float)$snRateInput, 2))) : 0.0;
        $nfseExtra = config_store_get($pdo, $cid, 'nfse_nacional') ?: [];
        $nfseExtra['sn_total_tax_rate'] = $snRate;
        config_store_set($pdo, $cid, 'nfse_nacional', $nfseExtra);
      }
      header('Location: ' . $fiscalSettingsUrl('fiscal_configuracoes.php?ok=' . rawurlencode('Configuração fiscal salva.')));
      exit;
    }
    if ($action === 'save_series') {
      fiscal_save_series($pdo, $cid, $_POST);
      header('Location: ' . $fiscalSettingsUrl('fiscal_configuracoes.php?ok=' . rawurlencode('Série fiscal salva.')));
      exit;
    }
    if ($action === 'save_user_series_preferences') {
      if (!function_exists('fiscal_save_user_company_series_preferences')) {
        throw new RuntimeException('A rotina de série por usuário/empresa não está disponível nesta instalação.');
      }
      $currentUserId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
      $targetUserId = (int)($_POST['target_user_id'] ?? $currentUserId);
      if ($targetUserId <= 0) $targetUserId = $currentUserId;
      if ($targetUserId !== $currentUserId && (!function_exists('access_is_company_master') || !access_is_company_master())) {
        throw new RuntimeException('Somente o administrador principal pode definir a série fiscal de outro usuário.');
      }
      if (!fiscal_cfg_user_has_company_access($pdo, $cid, $targetUserId)) {
        throw new RuntimeException('O usuário selecionado não possui acesso ativo à empresa atual.');
      }
      fiscal_save_user_company_series_preferences($pdo, $cid, $targetUserId, $_POST);
      $return = 'fiscal_configuracoes.php?series_user_id=' . $targetUserId . '&ok=' . rawurlencode('Séries do usuário salvas para a empresa e ambiente atuais.');
      header('Location: ' . $fiscalSettingsUrl($return));
      exit;
    }
    if ($action === 'toggle_series') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Série fiscal inválida.');
      $stOwn = $pdo->prepare('SELECT id FROM fiscal_document_series WHERE company_id=? AND id=? LIMIT 1');
      $stOwn->execute([$cid, $id]);
      if (!(int)($stOwn->fetchColumn() ?: 0)) throw new RuntimeException('Série fiscal não encontrada para esta empresa.');
      $active = !empty($_POST['is_active']) ? 1 : 0;
      $pdo->prepare('UPDATE fiscal_document_series SET is_active=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$active, $cid, $id]);
      header('Location: ' . $fiscalSettingsUrl('fiscal_configuracoes.php?ok=' . rawurlencode('Status da série atualizado.')));
      exit;
    }
    if ($action === 'save_certificate') {
      fiscal_save_certificate($pdo, $cid, $_POST, $_FILES['certificate_file'] ?? null);
      header('Location: ' . $fiscalSettingsUrl('fiscal_configuracoes.php?ok=' . rawurlencode('Certificado salvo.')));
      exit;
    }
    if ($action === 'save_dfe_state') {
      if ($fiscalSettingsWriteF11) throw new RuntimeException('V11.34 Multiempresa: a configuração de busca DF-e permanece bloqueada no F11.');
      fiscal_dfe_save_state($pdo, $cid, $_POST);
      header('Location: fiscal_configuracoes.php?ok=' . rawurlencode('Configuração de busca DF-e salva.') . '#buscarDfe');
      exit;
    }
    if ($action === 'buscar_dfe') {
      if ($fiscalSettingsWriteF11) throw new RuntimeException('V11.34 Multiempresa: a busca DF-e/SEFAZ permanece bloqueada no F11.');
      $force = !empty($_POST['force']);
      if (!function_exists('fiscal_dfe_download_from_sefaz')) {
        throw new RuntimeException('A rotina de busca DF-e não está disponível nesta instalação.');
      }
      $res = fiscal_dfe_download_from_sefaz($pdo, $cid, $force);
      $msg = 'Busca DF-e concluída. Documentos localizados: ' . (int)($res['documents_found'] ?? 0) . '. XMLs completos: ' . (int)($res['xmls_stored'] ?? 0) . '. Resumos: ' . (int)($res['summaries_stored'] ?? 0) . '.';
      header('Location: fiscal_configuracoes.php?ok=' . rawurlencode($msg) . '#buscarDfe');
      exit;
    }
    if ($action === 'activate_certificate') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Certificado inválido.');
      $stOwn = $pdo->prepare('SELECT id FROM fiscal_certificates WHERE company_id=? AND id=? LIMIT 1');
      $stOwn->execute([$cid, $id]);
      if (!(int)($stOwn->fetchColumn() ?: 0)) throw new RuntimeException('Certificado não encontrado para esta empresa.');
      $pdo->beginTransaction();
      try {
        $pdo->prepare('UPDATE fiscal_certificates SET is_active=0 WHERE company_id=?')->execute([$cid]);
        $pdo->prepare('UPDATE fiscal_certificates SET is_active=1, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$cid, $id]);
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }
      header('Location: ' . $fiscalSettingsUrl('fiscal_configuracoes.php?ok=' . rawurlencode('Certificado ativo atualizado.')));
      exit;
    }
    if ($action === 'delete_certificate') {
      $id = (int)($_POST['id'] ?? 0);
      if (!function_exists('fiscal_delete_certificate')) throw new RuntimeException('A rotina de exclusão do certificado não está disponível.');
      $res = fiscal_delete_certificate($pdo, $cid, $id);
      $msg = 'Certificado excluído. Agora você pode cadastrar o novo certificado.';
      if (empty($res['file_removed'])) $msg .= ' O cadastro foi removido, mas o arquivo antigo não pôde ser apagado da VPS; verifique permissões da pasta uploads/certificados.';
      header('Location: ' . $fiscalSettingsUrl('fiscal_configuracoes.php?ok=' . rawurlencode($msg)));
      exit;
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
  if (!empty($_POST['action'])) {
    $action = (string)$_POST['action'];
    if ($action === 'save_config') $openModal = 'config';
    if ($action === 'save_series') $openModal = 'series';
    if ($action === 'save_certificate') $openModal = 'certificate';
    if (!$fiscalSettingsWriteF11 && ($action === 'save_dfe_state' || $action === 'buscar_dfe')) $openModal = 'dfe';
  }
}

$orderModernPrintReady = function_exists('fiscal_column_exists')
  && fiscal_column_exists($pdo, 'fiscal_company_config', 'order_modern_print_enabled')
  && fiscal_column_exists($pdo, 'fiscal_company_config', 'order_modern_print_default');
$config = function_exists('fiscal_company_default_config') ? fiscal_company_default_config() : [];
$series = [];
$certs = [];
$dfeState = [];
$dfeLogs = [];
try {
  $config = fiscal_company_config($pdo, $cid);
  $series = fiscal_series_rows($pdo, $cid);
  $certs = fiscal_certificate_rows($pdo, $cid);
  if (!$fiscalSettingsWriteF11 && function_exists('fiscal_dfe_state')) {
    $dfeState = fiscal_dfe_state($pdo, $cid);
    $dfeLogs = fiscal_dfe_recent_logs($pdo, $cid, 5);
  }
} catch (Throwable $e) {
  $err = trim($err . ' ' . 'Falha ao carregar dados fiscais: ' . $e->getMessage());
}

// V11.35.1: preferência de série por usuário na empresa ativa e no ambiente atual.
$currentFiscalUserId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
$stRecipientSettings = nexa_st_settings($pdo, $cid);
$stCompanyName = fiscal_v10_entry_company_name($pdo, $cid);
$currentFiscalEnvironment = strtoupper(trim((string)($config['environment'] ?? 'HOMOLOGACAO')));
if (!in_array($currentFiscalEnvironment, ['HOMOLOGACAO','PRODUCAO'], true)) $currentFiscalEnvironment = 'HOMOLOGACAO';
$userSeriesTableReady = function_exists('fiscal_user_company_series_table_ready') && fiscal_user_company_series_table_ready($pdo);
$canManageOtherFiscalUsers = function_exists('access_is_company_master') && access_is_company_master();
$fiscalAssignableUsers = [];
$fiscalAssignableUserMap = [];
try {
  if ($canManageOtherFiscalUsers && function_exists('fiscal_table_exists') && fiscal_table_exists($pdo, 'user_company_access')) {
    $st = $pdo->prepare("SELECT DISTINCT u.id,u.name,u.email,u.company_id,u.role FROM users u LEFT JOIN user_company_access uca ON uca.user_id=u.id AND uca.company_id=? AND uca.is_active=1 WHERE u.is_active=1 AND u.role IN ('company_admin','company_user') AND (u.company_id=? OR uca.user_id IS NOT NULL) ORDER BY u.name,u.id");
    $st->execute([$cid, $cid]);
    $fiscalAssignableUsers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } elseif ($canManageOtherFiscalUsers) {
    $st = $pdo->prepare("SELECT id,name,email,company_id,role FROM users WHERE company_id=? AND is_active=1 AND role IN ('company_admin','company_user') ORDER BY name,id");
    $st->execute([$cid]);
    $fiscalAssignableUsers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {}
if (!$canManageOtherFiscalUsers || !$fiscalAssignableUsers) {
  try {
    $st = $pdo->prepare('SELECT id,name,email,company_id,role FROM users WHERE id=? LIMIT 1');
    $st->execute([$currentFiscalUserId]);
    $self = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($self) $fiscalAssignableUsers = [$self];
  } catch (Throwable $e) {}
}
foreach ($fiscalAssignableUsers as $u) $fiscalAssignableUserMap[(int)$u['id']] = $u;
if ($currentFiscalUserId > 0 && !isset($fiscalAssignableUserMap[$currentFiscalUserId])) {
  try {
    $st = $pdo->prepare('SELECT id,name,email,company_id,role FROM users WHERE id=? LIMIT 1');
    $st->execute([$currentFiscalUserId]);
    $self = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($self && fiscal_cfg_user_has_company_access($pdo, $cid, $currentFiscalUserId)) {
      $fiscalAssignableUsers[] = $self;
      $fiscalAssignableUserMap[$currentFiscalUserId] = $self;
    }
  } catch (Throwable $e) {}
}
$targetFiscalUserId = (int)($_GET['series_user_id'] ?? $_POST['target_user_id'] ?? $currentFiscalUserId);
if ($targetFiscalUserId <= 0 || !isset($fiscalAssignableUserMap[$targetFiscalUserId])) $targetFiscalUserId = $currentFiscalUserId;
if ($targetFiscalUserId <= 0 && $fiscalAssignableUsers) $targetFiscalUserId = (int)$fiscalAssignableUsers[0]['id'];
$targetFiscalUser = $fiscalAssignableUserMap[$targetFiscalUserId] ?? null;
$userSeriesPreferences = $userSeriesTableReady && function_exists('fiscal_user_company_series_preferences')
  ? fiscal_user_company_series_preferences($pdo, $cid, $targetFiscalUserId, $currentFiscalEnvironment)
  : [];
$userSeriesSelected = [
  'NFE' => (int)($userSeriesPreferences['NFE']['fiscal_series_id'] ?? 0),
  'NFCE' => (int)($userSeriesPreferences['NFCE']['fiscal_series_id'] ?? 0),
  'SERVICE' => (int)($userSeriesPreferences['SERVICE']['fiscal_series_id'] ?? 0),
];
$userSeriesOptions = ['NFE'=>[], 'NFCE'=>[], 'SERVICE'=>[]];
foreach ($series as $row) {
  if (empty($row['is_active']) || strtoupper((string)($row['environment'] ?? '')) !== $currentFiscalEnvironment) continue;
  $m = strtoupper(trim((string)($row['model'] ?? '')));
  if ($m === '55') $userSeriesOptions['NFE'][] = $row;
  elseif ($m === '65') $userSeriesOptions['NFCE'][] = $row;
  elseif (in_array($m, ['NFSE','DPS','RPS','SS'], true)) $userSeriesOptions['SERVICE'][] = $row;
}
$userSeriesEffective = ['NFE'=>null, 'NFCE'=>null, 'SERVICE'=>null];
try {
  if (function_exists('fiscal_user_default_series')) {
    $userSeriesEffective['NFE'] = fiscal_user_default_series($pdo, $cid, $targetFiscalUserId, '55', $currentFiscalEnvironment);
    $userSeriesEffective['NFCE'] = fiscal_user_default_series($pdo, $cid, $targetFiscalUserId, '65', $currentFiscalEnvironment);
    foreach (['NFSE','DPS','RPS','SS'] as $serviceModel) {
      $candidate = fiscal_user_default_series($pdo, $cid, $targetFiscalUserId, $serviceModel, $currentFiscalEnvironment);
      if ($candidate) { $userSeriesEffective['SERVICE'] = $candidate; break; }
    }
  }
} catch (Throwable $e) {}

$editSeriesId = (int)($_GET['edit_series'] ?? 0);
$editSeries = null;
if ($editSeriesId > 0) foreach ($series as $s) if ((int)$s['id'] === $editSeriesId) { $editSeries = $s; break; }
if (isset($_GET['new_series']) || $editSeries) $openModal = 'series';

$editCertId = (int)($_GET['edit_cert'] ?? 0);
$editCert = null;
if ($editCertId > 0) foreach ($certs as $c) if ((int)$c['id'] === $editCertId) { $editCert = $c; break; }
if (isset($_GET['new_cert']) || $editCert) $openModal = 'certificate';
if (isset($_GET['edit_config'])) $openModal = 'config';
if ($fiscalSettingsWriteF11 && $openModal === 'dfe') $openModal = '';

$activeCert = null;
foreach ($certs as $c) { if (!empty($c['is_active'])) { $activeCert = $c; break; } }

function fiscal_cfg_label_model(string $model): string {
  return ['55'=>'NF-e 55', '65'=>'NFC-e 65', 'NFSE'=>'NFS-e', 'DPS'=>'DPS', 'RPS'=>'RPS', 'SS'=>'Série Serviço/SS'][$model] ?? $model;
}
function fiscal_cfg_bool_label($value): string { return !empty($value) ? 'Sim' : 'Não'; }
function fiscal_cfg_date_br($value): string {
  $s = trim((string)$value);
  if ($s === '' || $s === '0000-00-00') return '-';
  try { return (new DateTimeImmutable($s))->format('d/m/Y'); } catch (Throwable $e) { return $s; }
}

fiscal_admin_start('Fiscal - Configurações', 'fiscal');
fiscal_page_header('Configurações fiscais', $fiscalSettingsWriteF11
  ? 'V11.35.1 · Configuração fiscal e série do usuário isoladas pela empresa selecionada. Operações não homologadas continuam bloqueadas.'
  : 'Parâmetros da empresa, séries fiscais, certificado digital e busca DF-e contra o CNPJ.',
  $fiscalSettingsWriteF11 ? [] : [
    ['label' => 'Buscar DF-e agora', 'href' => '#buscarDfe', 'class' => 'btn btn-primary'],
    ['label' => 'RADAR NF-e', 'href' => 'fiscal_radar_xml.php', 'class' => 'btn'],
  ]);
fiscal_tabs('fiscal_configuracoes');
fiscal_flash();
if ($fiscalSettingsWriteF11) echo '<div class="msg ok"><b>V11.35.1 · Configuração fiscal Multiempresa habilitada.</b> Séries e preferência do usuário ficam isoladas pela empresa selecionada. As demais proteções fiscais do F11 permanecem ativas.</div>';
if ($err): ?><div class="msg err"><?= fiscal_h($err) ?></div><?php endif; ?>

<style>
  .fiscal-config-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:14px}
  .fiscal-summary-line{display:grid;grid-template-columns:minmax(130px,.45fr) 1fr;gap:8px;padding:8px 0;border-bottom:1px solid #eef2f7;color:#334155}
  .fiscal-summary-line:last-child{border-bottom:0}.fiscal-summary-line span{color:#64748b;font-weight:850}.fiscal-summary-line strong{font-weight:900;color:#0f172a;min-width:0;overflow-wrap:anywhere}.fiscal-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}.fiscal-card-head h2{margin:0}.fiscal-pill-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.fiscal-soft-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid #dbe4f0;background:#f8fbff;color:#334155;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:900}.fiscal-empty-state{text-align:center;color:#64748b;padding:26px 12px;border:1px dashed #dbe4f0;border-radius:18px;background:#fbfdff}.fiscal-mini-help{font-size:12px;color:#64748b;margin-top:6px;line-height:1.35}.fiscal-modal-dialog .fiscal-form-grid{grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:980px){.fiscal-config-summary,.fiscal-modal-dialog .fiscal-form-grid{grid-template-columns:1fr}.fiscal-summary-line{grid-template-columns:1fr}}
</style>

<div class="fiscal-grid-3">
  <div class="fiscal-kpi"><span>Ambiente</span><strong><?= fiscal_h($config['environment'] ?? 'HOMOLOGACAO') ?></strong></div>
  <div class="fiscal-kpi"><span>Séries cadastradas</span><strong><?= count($series) ?></strong></div>
  <div class="fiscal-kpi"><span>Certificado ativo</span><strong><?= $activeCert ? 'OK' : 'Pendente' ?></strong></div>
</div>

<div class="fiscal-card" style="margin-top:14px">
  <div class="fiscal-card-head">
    <div>
      <h2>Configuração da empresa</h2>
      <p class="fiscal-muted" style="margin:4px 0 0">Resumo dos parâmetros fiscais usados pela emissão de NF-e, NFC-e e NFS-e.</p>
    </div>
    <a class="btn btn-primary" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php?edit_config=1')) ?>">Editar configuração</a>
  </div>
  <div class="fiscal-config-summary">
    <div class="fiscal-section" style="margin:0">
      <h3>Dados cadastrais / Sintegra</h3>
      <div class="fiscal-summary-line"><span>Razão social</span><strong><?= fiscal_h(($config['company_name'] ?? '') ?: '-') ?></strong></div>
      <div class="fiscal-summary-line"><span>CNPJ</span><strong><?= fiscal_h(($config['company_cnpj'] ?? '') ?: '-') ?></strong></div>
      <div class="fiscal-summary-line"><span>Inscrição estadual</span><strong><?= fiscal_h(($config['state_registration'] ?? '') ?: '-') ?></strong></div>
      <div class="fiscal-summary-line"><span>Município/UF</span><strong><?= fiscal_h((($config['company_city'] ?? '') ?: '-') . ' / ' . (($config['uf'] ?? '') ?: '-')) ?></strong></div>
      <div class="fiscal-summary-line"><span>Endereço</span><strong><?= fiscal_h(trim((string)(($config['company_address'] ?? '') . ', ' . ($config['company_number'] ?? ''))) ?: '-') ?></strong></div>
      <div class="fiscal-summary-line"><span>CEP/Fone</span><strong><?= fiscal_h((($config['company_zip'] ?? '') ?: '-') . ' / ' . (($config['company_phone'] ?? '') ?: '-')) ?></strong></div>
    </div>
    <div class="fiscal-section" style="margin:0">
      <h3>Documentos e integração</h3>
      <div class="fiscal-summary-line"><span>NF-e</span><strong><?= fiscal_cfg_bool_label($config['nfe_enabled'] ?? 0) ?></strong></div>
      <div class="fiscal-summary-line"><span>NFC-e</span><strong><?= fiscal_cfg_bool_label($config['nfce_enabled'] ?? 0) ?></strong></div>
      <div class="fiscal-summary-line"><span>NFS-e</span><strong><?= fiscal_cfg_bool_label($config['nfse_enabled'] ?? 0) ?></strong></div>
      <div class="fiscal-summary-line"><span>Somente gravar pedido</span><strong><?= fiscal_cfg_bool_label($config['save_order_enabled'] ?? 1) ?></strong></div>
      <div class="fiscal-summary-line"><span>Impressão automática</span><strong><?= fiscal_cfg_bool_label($config['order_auto_print_enabled'] ?? 1) ?></strong></div>
      <div class="fiscal-summary-line"><span>Impresso de pedido</span><strong><?= !empty($config['order_print_disabled']) ? 'Desabilitado' : 'Habilitado' ?></strong></div>
      <div class="fiscal-summary-line"><span>Pack 58mm</span><strong><?= fiscal_cfg_bool_label($config['print_pack_58mm_enabled'] ?? 0) ?></strong></div>
      <div class="fiscal-summary-line"><span>Contingência</span><strong><?= fiscal_cfg_bool_label($config['contingency_enabled'] ?? 0) ?></strong></div>
      <div class="fiscal-summary-line"><span>Aviso no login</span><strong><?= fiscal_cfg_bool_label($config['contingency_login_alert_enabled'] ?? 1) ?></strong></div>
      <div class="fiscal-summary-line"><span>Motor de emissão</span><strong><?= fiscal_h(($config['emission_adapter'] ?? 'NFEPHP') === 'NFEPHP' ? 'NFePHP/sped-nfe' : (($config['acbr_mode'] ?? '-') . ' - ' . (($config['acbr_host'] ?? '') ?: '-') . ':' . (($config['acbr_port'] ?? '') ?: '-'))) ?></strong></div>
    </div>
    <div class="fiscal-section" style="margin:0">
      <h3>NFC-e, NFS-e e pastas</h3>
      <div class="fiscal-summary-line"><span>ID CSC</span><strong><?= fiscal_h(($config['nfce_csc_id'] ?? '') ?: '-') ?></strong></div>
      <div class="fiscal-summary-line"><span>QR Code</span><strong><?= fiscal_h(($config['nfce_qrcode_version'] ?? '') ?: '-') ?></strong></div>
      <div class="fiscal-summary-line"><span>CFOP NF-e vinculada</span><strong><?= fiscal_h((($config['nfe_nfce_referenced_cfop_internal'] ?? '') ?: '5949') . ' / ' . (($config['nfe_nfce_referenced_cfop_external'] ?? '') ?: '6949')) ?></strong></div>
      <div class="fiscal-summary-line"><span>NFS-e</span><strong><?= fiscal_h(strtoupper((string)($config['nfse_provider'] ?? 'NACIONAL')) === 'PRONIM' ? 'Pronim / GOVBR - ISS Digital' : 'Ambiente Nacional') ?></strong></div>
      <div class="fiscal-summary-line"><span>XML saída</span><strong><?= fiscal_h(($config['xml_out_path'] ?? '') ?: '-') ?></strong></div>
    </div>
  </div>
</div>

<div class="fiscal-card" style="margin-top:14px" id="icmsStDestinatario">
  <div class="fiscal-card-head">
    <div>
      <h2>ICMS-ST para NF-e por regime do destinatário</h2>
      <p class="fiscal-muted" style="margin:4px 0 0">Empresa: <b><?= fiscal_h($stCompanyName) ?> (ID <?= (int)$cid ?>)</b>. Configuração isolada desta empresa; NFC-e (modelo 65) não é alterada.</p>
    </div>
    <a class="btn" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_st_uf.php')) ?>">Alíquotas por UF / MVA</a>
  </div>
  <div class="fiscal-summary-line"><span>Situação da regra</span><strong><?= empty($stRecipientSettings['enabled']) ? 'Desabilitada' : ($currentFiscalEnvironment === 'HOMOLOGACAO' ? 'Habilitada em homologação' : 'Marcada, porém bloqueada fora da homologação') ?></strong></div>
  <?php if (!$stSchemaReady): ?>
    <div class="msg err">Banco ainda não atualizado. Aplique a migration 20261026_fiscal_st_cliente_v1 em Configurações → Atualizador do Banco, usando platform_admin.</div>
  <?php elseif ($fiscalSettingsWriteF11): ?>
    <p class="fiscal-muted">Alteração bloqueada pelo F11. Para configurar ICMS-ST nesta empresa, acesse-a diretamente.</p>
  <?php else: ?>
    <form method="post" class="fiscal-form" action="fiscal_configuracoes.php#icmsStDestinatario">
      <input type="hidden" name="action" value="save_st_recipient">
      <input type="hidden" name="csrf_st" value="<?= fiscal_h($_SESSION['nexa_st_csrf']) ?>">
      <label class="fiscal-checkline" style="display:flex;gap:10px;align-items:flex-start;margin:14px 0">
        <input type="checkbox" name="enabled" value="1" style="width:auto;min-height:0;margin-top:3px"
          <?= !empty($stRecipientSettings['enabled']) ? 'checked' : '' ?>
          <?= !$stCanManageHere || $currentFiscalEnvironment !== 'HOMOLOGACAO' ? 'disabled' : '' ?>>
        Habilitar cálculo de ICMS-ST nas NF-e (55) de pedidos para destinatários PJ Lucro Presumido ou Lucro Real, quando a tributação do produto for compatível.
      </label>
      <p class="fiscal-muted" style="margin:0 0 12px">Ambiente atual: <b><?= fiscal_h($currentFiscalEnvironment) ?></b>. Para ativar, a empresa precisa estar em HOMOLOGAÇÃO; preencher ICMS de referência por UF, MVA por NCM/CEST/UF e a tributação ST do item. A edição global das alíquotas exige platform_admin. Não se aplica automaticamente a qualquer cliente ou produto.</p>
      <?php if ($stCanManageHere): ?>
        <button class="btn btn-primary" type="submit"><?= $currentFiscalEnvironment === 'HOMOLOGACAO' ? 'Salvar habilitação de ICMS-ST' : 'Desabilitar ICMS-ST nesta empresa' ?></button>
      <?php else: ?><div class="fiscal-muted">Somente o administrador principal pode alterar esta configuração.</div><?php endif; ?>
    </form>
    <?php if ($currentFiscalEnvironment !== 'HOMOLOGACAO'): ?>
      <p class="fiscal-muted" style="margin:10px 0 0"><b>Produção:</b> a configuração não pode ser ativada neste ambiente e não libera o motor fiscal sem homologação prévia.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!$fiscalSettingsWriteF11): ?>
<div class="fiscal-card" style="margin-top:14px" id="buscarDfe">
  <div class="fiscal-card-head">
    <div>
      <h2>Busca DF-e contra o CNPJ</h2>
      <p class="fiscal-muted" style="margin:4px 0 0">Configuração usada na tela Entradas para buscar automaticamente NF-e emitida para a empresa.</p>
    </div>
    <a class="btn btn-primary" href="?modal=dfe">Editar DF-e</a>
  </div>
  <div class="fiscal-config-summary">
    <div class="fiscal-section" style="margin:0">
      <h3>Empresa consultada</h3>
      <div class="fiscal-summary-line"><span>CNPJ</span><strong><?= fiscal_h(($dfeState['cnpj'] ?? '') ?: (($config['company_cnpj'] ?? '') ?: '-')) ?></strong></div>
      <div class="fiscal-summary-line"><span>UF</span><strong><?= fiscal_h(($dfeState['uf'] ?? '') ?: (($config['uf'] ?? '') ?: '-')) ?></strong></div>
      <div class="fiscal-summary-line"><span>Ambiente</span><strong><?= fiscal_h(($dfeState['environment'] ?? '') ?: (($config['environment'] ?? '') ?: '-')) ?></strong></div>
    </div>
    <div class="fiscal-section" style="margin:0">
      <h3>Controle NSU</h3>
      <div class="fiscal-summary-line"><span>Último NSU</span><strong><?= fiscal_h(($dfeState['last_nsu'] ?? '') ?: '0') ?></strong></div>
      <div class="fiscal-summary-line"><span>Maior NSU</span><strong><?= fiscal_h(($dfeState['max_nsu'] ?? '') ?: '0') ?></strong></div>
      <div class="fiscal-summary-line"><span>Intervalo mínimo</span><strong><?= (int)($dfeState['minimum_interval_minutes'] ?? 60) ?> min.</strong></div>
    </div>
    <div class="fiscal-section" style="margin:0">
      <h3>Situação</h3>
      <div class="fiscal-summary-line"><span>Conexão</span><strong><?= fiscal_h(($dfeState['provider'] ?? 'NFEPHP_DIRECT') === 'NFEPHP_DIRECT' ? 'NFePHP direto' : ($dfeState['provider'] ?? 'ACBrMonitor')) ?></strong></div>
      <div class="fiscal-summary-line"><span>Busca automática</span><strong><?= !empty($dfeState['auto_check_enabled']) ? 'Habilitada' : 'Manual' ?></strong></div>
      <div class="fiscal-summary-line"><span>Última busca</span><strong><?= fiscal_h(($dfeState['last_success_at'] ?? '') ?: '-') ?></strong></div>
      <div class="fiscal-summary-line"><span>Último retorno</span><strong><?= fiscal_h(trim(($dfeState['last_status_code'] ?? '') . ' ' . ($dfeState['last_status_message'] ?? '')) ?: '-') ?></strong></div>
    </div>
  </div>
  <form method="post" class="fiscal-form" style="margin-top:14px">
    <input type="hidden" name="action" value="buscar_dfe">
    <div class="fiscal-form-grid" style="align-items:end">
      <div><label>CNPJ consultado</label><input value="<?= fiscal_h(($dfeState['cnpj'] ?? '') ?: (($config['company_cnpj'] ?? '') ?: '')) ?>" disabled></div>
      <div><label>UF</label><input value="<?= fiscal_h(($dfeState['uf'] ?? '') ?: (($config['uf'] ?? '') ?: '')) ?>" disabled></div>
      <div><label>Ambiente</label><input value="<?= fiscal_h(($dfeState['environment'] ?? '') ?: (($config['environment'] ?? '') ?: '')) ?>" disabled></div>
      <div class="span-3 fiscal-muted" style="font-weight:750;line-height:1.4">Ao buscar, o sistema consulta a Distribuição DF-e usando o último NSU em sequência. Os XMLs completos aparecerão no <b>RADAR NF-e</b> para conferência antes de gerar a entrada. Quando a SEFAZ informar que não há documentos, o sistema aguardará uma hora antes da próxima consulta.</div>
    </div>
    <div class="fiscal-form-actions"><a class="btn" href="?modal=dfe">Configurar DF-e</a><a class="btn" href="fiscal_radar_xml.php">Abrir RADAR NF-e</a><button class="btn btn-primary">Buscar notas na SEFAZ</button></div>
  </form>
  <?php if ($dfeLogs): ?>
    <div class="fiscal-mini-help" style="margin-top:10px">Última tentativa: <?= fiscal_h($dfeLogs[0]['started_at'] ?? '') ?> — <?= fiscal_h(trim(($dfeLogs[0]['status_code'] ?? '') . ' ' . ($dfeLogs[0]['status_message'] ?? '') . ' ' . ($dfeLogs[0]['error_message'] ?? ''))) ?></div>
  <?php endif; ?>
</div>

<?php endif; ?>

<div class="fiscal-card" style="margin-top:14px">
  <div class="fiscal-card-head">
    <div>
      <h2>Série fiscal deste usuário nesta empresa</h2>
      <p class="fiscal-muted" style="margin:4px 0 0">V11.35.1 · A escolha abaixo vale para o usuário selecionado, somente nesta empresa e no ambiente <?= fiscal_h($currentFiscalEnvironment) ?>.</p>
    </div>
    <span class="fiscal-soft-pill">Empresa: <?= fiscal_h(($config['company_trade_name'] ?? '') ?: (($config['company_name'] ?? '') ?: ('#' . $cid))) ?></span>
  </div>
  <?php if (!$userSeriesTableReady): ?>
    <div class="msg err" style="margin:0">A estrutura da V11.35.1 ainda não foi aplicada. Entre como <b>platform_admin</b> em <b>Configurações → Atualizador do Banco</b> e aplique a migration pendente. Até lá, o sistema mantém o comportamento legado.</div>
  <?php else: ?>
  <?php if ($canManageOtherFiscalUsers && count($fiscalAssignableUsers) > 1): ?>
    <form method="get" action="fiscal_configuracoes.php" class="fiscal-form" style="margin-bottom:12px">
      <?php if ($fiscalSettingsWriteF11 && $fiscalSettingsContextToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($fiscalSettingsContextToken) ?>"><?php endif; ?>
      <div class="fiscal-form-grid"><div><label>Usuário / operador</label><select name="series_user_id" onchange="this.form.submit()"><?php foreach($fiscalAssignableUsers as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $targetFiscalUserId===(int)$u['id']?'selected':'' ?>><?= fiscal_h(($u['name'] ?? '') . ' · ' . ($u['email'] ?? '')) ?></option><?php endforeach; ?></select></div></div>
    </form>
  <?php else: ?>
    <div class="fiscal-soft-pill" style="margin-bottom:12px">Usuário: <?= fiscal_h(($targetFiscalUser['name'] ?? '') ?: ('#' . $targetFiscalUserId)) ?></div>
  <?php endif; ?>
  <form method="post" class="fiscal-form">
    <input type="hidden" name="action" value="save_user_series_preferences">
    <input type="hidden" name="target_user_id" value="<?= (int)$targetFiscalUserId ?>">
    <input type="hidden" name="environment" value="<?= fiscal_h($currentFiscalEnvironment) ?>">
    <?php if ($fiscalSettingsWriteF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalSettingsMcCsrf) ?>"><?php endif; ?>
    <?php if ($fiscalSettingsWriteF11 && $fiscalSettingsContextToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($fiscalSettingsContextToken) ?>"><?php endif; ?>
    <div class="fiscal-form-grid">
      <div><label>NFC-e modelo 65</label><select name="user_nfce_series_id"><option value="">Automático — regra padrão da empresa</option><?php foreach($userSeriesOptions['NFCE'] as $row): ?><option value="<?= (int)$row['id'] ?>" <?= $userSeriesSelected['NFCE']===(int)$row['id']?'selected':'' ?>>Série <?= fiscal_h($row['series']) ?> · <?= fiscal_h($row['environment']) ?></option><?php endforeach; ?></select><div class="fiscal-mini-help">Em uso agora: <?= $userSeriesEffective['NFCE'] ? ('série ' . fiscal_h($userSeriesEffective['NFCE']['series'] ?? '-')) : 'nenhuma série disponível' ?></div></div>
      <div><label>NF-e modelo 55</label><select name="user_nfe_series_id"><option value="">Automático — regra padrão da empresa</option><?php foreach($userSeriesOptions['NFE'] as $row): ?><option value="<?= (int)$row['id'] ?>" <?= $userSeriesSelected['NFE']===(int)$row['id']?'selected':'' ?>>Série <?= fiscal_h($row['series']) ?> · <?= fiscal_h($row['environment']) ?></option><?php endforeach; ?></select><div class="fiscal-mini-help">Em uso agora: <?= $userSeriesEffective['NFE'] ? ('série ' . fiscal_h($userSeriesEffective['NFE']['series'] ?? '-')) : 'nenhuma série disponível' ?></div></div>
      <div><label>NFS-e / serviço</label><select name="user_service_series_id"><option value="">Automático — regra padrão da empresa</option><?php foreach($userSeriesOptions['SERVICE'] as $row): ?><option value="<?= (int)$row['id'] ?>" <?= $userSeriesSelected['SERVICE']===(int)$row['id']?'selected':'' ?>><?= fiscal_h(fiscal_cfg_label_model((string)$row['model'])) ?> · Série <?= fiscal_h($row['series']) ?></option><?php endforeach; ?></select><div class="fiscal-mini-help">Em uso agora: <?= $userSeriesEffective['SERVICE'] ? (fiscal_h(fiscal_cfg_label_model((string)($userSeriesEffective['SERVICE']['model'] ?? ''))) . ' série ' . fiscal_h($userSeriesEffective['SERVICE']['series'] ?? '-')) : 'automático conforme o serviço' ?></div></div>
    </div>
    <div class="fiscal-form-actions"><span class="fiscal-muted" style="margin-right:auto">Trocar de empresa pelo F11 carrega outra preferência; a série da empresa anterior nunca é reutilizada.</span><button class="btn btn-primary" type="submit">Salvar séries deste usuário</button></div>
  </form>
  <?php endif; ?>
</div>

<div class="fiscal-grid" style="align-items:start">
  <div class="fiscal-card">
    <div class="fiscal-card-head">
      <div>
        <h2>Séries fiscais</h2>
        <p class="fiscal-muted" style="margin:4px 0 0">Controle de modelo, ambiente, série e próximo número de emissão.</p>
      </div>
      <a class="btn btn-primary" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php?new_series=1')) ?>">Nova série</a>
    </div>
    <div class="table-wrap"><table class="fiscal-table fiscal-grid-table"><thead><tr><th>Modelo</th><th>Série</th><th>Ambiente</th><th>Próx.</th><th>Status</th><th class="fiscal-actions-head">Ações</th></tr></thead><tbody>
      <?php if(!$series): ?><tr><td colspan="6"><div class="fiscal-empty-state">Nenhuma série cadastrada. Clique em <b>Nova série</b> para configurar NF-e, NFC-e ou NFS-e.</div></td></tr><?php endif; ?>
      <?php foreach($series as $s): ?><tr>
        <td data-label="Modelo" class="primary"><b><?= fiscal_h(fiscal_cfg_label_model((string)$s['model'])) ?></b><br><span class="fiscal-muted"><?= fiscal_h($s['emission_type'] ?? 'NORMAL') ?></span></td>
        <td data-label="Série"><?= fiscal_h($s['series']) ?></td>
        <td data-label="Ambiente"><?= fiscal_h($s['environment']) ?></td>
        <td data-label="Próx."><?= (int)$s['next_number'] ?></td>
        <td data-label="Status"><span class="fiscal-badge <?= !empty($s['is_active'])?'':'off' ?>"><?= !empty($s['is_active'])?'Ativa':'Inativa' ?></span></td>
        <td data-label="Ações" class="fiscal-actions-col"><div class="fiscal-inline-actions" style="justify-content:flex-end"><a class="btn btn-sm" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php?edit_series=' . (int)$s['id'])) ?>">Editar</a><form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_series"><?php if ($fiscalSettingsWriteF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalSettingsMcCsrf) ?>"><?php endif; ?><?php if ($fiscalSettingsWriteF11 && $fiscalSettingsContextToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($fiscalSettingsContextToken) ?>"><?php endif; ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="is_active" value="<?= empty($s['is_active']) ? '1' : '0' ?>"><button class="btn btn-sm" type="submit"><?= empty($s['is_active']) ? 'Ativar' : 'Inativar' ?></button></form></div></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  </div>

  <div class="fiscal-card">
    <div class="fiscal-card-head">
      <div>
        <h2>Certificados digitais</h2>
        <p class="fiscal-muted" style="margin:4px 0 0">Certificado A1/A3 usado pelo serviço fiscal. Ao importar A1, os dados serão lidos automaticamente. Você pode excluir um certificado antigo e cadastrar novamente sem afetar os documentos já emitidos.</p>
      </div>
      <a class="btn btn-primary" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php?new_cert=1')) ?>">Novo certificado</a>
    </div>
    <div class="table-wrap"><table class="fiscal-table fiscal-grid-table"><thead><tr><th>Certificado</th><th>Tipo</th><th>Validade</th><th>Status</th><th class="fiscal-actions-head">Ações</th></tr></thead><tbody>
      <?php if(!$certs): ?><tr><td colspan="5"><div class="fiscal-empty-state">Nenhum certificado cadastrado. Clique em <b>Novo certificado</b> para enviar o A1/A3.</div></td></tr><?php endif; ?>
      <?php foreach($certs as $c): ?><tr>
        <td data-label="Certificado" class="primary"><b><?= fiscal_h($c['display_name'] ?: ('Certificado #' . $c['id'])) ?></b><br><span class="fiscal-muted fiscal-cell-clip"><?= fiscal_h($c['subject_name'] ?? '') ?></span></td>
        <td data-label="Tipo"><?= fiscal_h($c['certificate_type']) ?></td>
        <td data-label="Validade"><?= fiscal_h(fiscal_cfg_date_br($c['valid_until'] ?? '')) ?></td>
        <td data-label="Status"><span class="fiscal-badge <?= !empty($c['is_active'])?'':'off' ?>"><?= !empty($c['is_active'])?'Ativo':'Inativo' ?></span></td>
        <td data-label="Ações" class="fiscal-actions-col"><div class="fiscal-inline-actions" style="justify-content:flex-end"><a class="btn btn-sm" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php?edit_cert=' . (int)$c['id'])) ?>">Editar</a><?php if(empty($c['is_active'])): ?><form method="post" style="display:inline"><input type="hidden" name="action" value="activate_certificate"><?php if ($fiscalSettingsWriteF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalSettingsMcCsrf) ?>"><?php endif; ?><?php if ($fiscalSettingsWriteF11 && $fiscalSettingsContextToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($fiscalSettingsContextToken) ?>"><?php endif; ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm" type="submit">Ativar</button></form><?php endif; ?><form method="post" style="display:inline" onsubmit="return confirm('Excluir este certificado digital? O arquivo do certificado também será removido da VPS. Os documentos fiscais já emitidos não serão apagados.');"><input type="hidden" name="action" value="delete_certificate"><?php if ($fiscalSettingsWriteF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalSettingsMcCsrf) ?>"><?php endif; ?><?php if ($fiscalSettingsWriteF11 && $fiscalSettingsContextToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($fiscalSettingsContextToken) ?>"><?php endif; ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm" type="submit" style="border-color:#fecaca;color:#b91c1c;background:#fff">Excluir</button></form></div></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>


<?php if ($openModal === 'dfe'):
  $dfeForm = array_merge([
    'cnpj' => $config['company_cnpj'] ?? '',
    'uf' => $config['uf'] ?? 'MG',
    'environment' => $config['environment'] ?? 'HOMOLOGACAO',
    'last_nsu' => '0',
    'minimum_interval_minutes' => 60,
    'auto_check_enabled' => 0,
    'provider' => 'NFEPHP_DIRECT',
  ], $dfeState ?: []);
?>
<div class="fiscal-modal" role="dialog" aria-modal="true">
  <div class="fiscal-modal-dialog fiscal-modal-wide">
    <div class="fiscal-modal-head"><div><h2>Configurar busca DF-e</h2><p class="fiscal-muted" style="margin:5px 0 0">Informe os dados para a Distribuição DF-e. Esta configuração será usada no botão Buscar notas da tela Entradas.</p></div><a class="fiscal-modal-close" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php')) ?>" aria-label="Fechar">×</a></div>
    <form method="post" class="fiscal-form">
      <input type="hidden" name="action" value="save_dfe_state">
      <div class="fiscal-section"><h3>Identificação da empresa</h3><div class="fiscal-form-grid">
        <div><label>CNPJ da empresa</label><input name="cnpj" value="<?= fiscal_h($dfeForm['cnpj'] ?? '') ?>" placeholder="Somente números"></div>
        <div><label>UF</label><input name="uf" maxlength="2" value="<?= fiscal_h($dfeForm['uf'] ?? 'MG') ?>"></div>
        <div><label>Ambiente</label><select name="environment"><option value="HOMOLOGACAO" <?= (($dfeForm['environment'] ?? '')==='HOMOLOGACAO')?'selected':'' ?>>Homologação</option><option value="PRODUCAO" <?= (($dfeForm['environment'] ?? '')==='PRODUCAO')?'selected':'' ?>>Produção</option></select></div>
        <div><label>Provedor da consulta</label><select name="provider">
          <option value="NFEPHP_DIRECT" <?= (($dfeForm['provider'] ?? 'NFEPHP_DIRECT')==='NFEPHP_DIRECT')?'selected':'' ?>>NFePHP direto — recomendado</option>
          <option value="ACBRMONITOR" <?= (($dfeForm['provider'] ?? '')==='ACBRMONITOR')?'selected':'' ?>>ACBrMonitor</option>
        </select></div>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5">A conexão direta usa o certificado A1 ativo da própria empresa e não depende de aplicação instalada no computador do cliente.</div>
      </div></div>
      <div class="fiscal-section"><h3>Controle da distribuição</h3><div class="fiscal-form-grid">
        <div><label>Último NSU</label><input name="last_nsu" value="<?= fiscal_h($dfeForm['last_nsu'] ?? '0') ?>"></div>
        <div><label>Intervalo mínimo entre buscas (min.)</label><input type="number" min="60" name="minimum_interval_minutes" value="<?= (int)($dfeForm['minimum_interval_minutes'] ?? 60) ?>"></div>
        <label class="fiscal-checkline"><input type="checkbox" name="auto_check_enabled" value="1" <?= !empty($dfeForm['auto_check_enabled'])?'checked':'' ?>> Busca automática habilitada</label>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5">O NSU controla o ponto da última consulta. Altere apenas quando for necessário reiniciar ou corrigir a sequência de busca junto à SEFAZ.</div>
      </div></div>
      <div class="fiscal-form-actions"><a class="btn" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php')) ?>">Cancelar</a><button class="btn btn-primary">Salvar configuração DF-e</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($openModal === 'config'): ?>
<div class="fiscal-modal" role="dialog" aria-modal="true">
  <div class="fiscal-modal-dialog fiscal-modal-wide">
    <div class="fiscal-modal-head"><div><h2>Editar configuração da empresa</h2><p class="fiscal-muted" style="margin:5px 0 0">Informe os parâmetros usados pela emissão fiscal e pelas rotinas de entradas.</p></div><a class="fiscal-modal-close" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php')) ?>" aria-label="Fechar">×</a></div>
    <form method="post" class="fiscal-form">
      <input type="hidden" name="action" value="save_config"><?php if ($fiscalSettingsWriteF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalSettingsMcCsrf) ?>"><?php endif; ?><?php if ($fiscalSettingsWriteF11 && $fiscalSettingsContextToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($fiscalSettingsContextToken) ?>"><?php endif; ?>
      <div class="fiscal-section"><h3>Dados cadastrais da empresa</h3><div class="fiscal-form-grid">
        <div class="span-2"><label>Razão social</label><input id="fiscalCompanyName" name="company_name" value="<?= fiscal_h($config['company_name'] ?? '') ?>" placeholder="Razão social completa"></div>
        <div><label>Nome fantasia</label><input id="fiscalCompanyTradeName" name="company_trade_name" value="<?= fiscal_h($config['company_trade_name'] ?? '') ?>"></div>
        <div>
          <label>CNPJ da empresa</label>
          <div class="fiscal-inline-actions" style="justify-content:flex-start;flex-wrap:nowrap">
            <input id="fiscalCompanyCnpj" name="company_cnpj" maxlength="18" inputmode="numeric" autocomplete="off" value="<?= fiscal_h($config['company_cnpj'] ?? '') ?>" placeholder="00.000.000/0000-00" style="flex:1;min-width:0">
            <button type="button" class="btn btn-sm" id="fiscalLookupCnpj">Buscar CNPJ</button>
          </div>
          <div id="fiscalCnpjLookupStatus" class="fiscal-mini-help" aria-live="polite"></div>
        </div>
        <div><label>Inscrição estadual</label><input id="fiscalStateRegistration" name="state_registration" value="<?= fiscal_h($config['state_registration'] ?? '') ?>" placeholder="ISENTO ou número da IE"></div>
        <div><label>Inscrição municipal</label><input name="municipal_registration" value="<?= fiscal_h($config['municipal_registration'] ?? '') ?>" placeholder="Usada na NFS-e"></div>
        <div><label>UF</label><input id="fiscalCompanyUf" name="uf" maxlength="2" value="<?= fiscal_h($config['uf'] ?? '') ?>"></div>
        <div><label>Município</label><input id="fiscalCompanyCity" name="company_city" value="<?= fiscal_h($config['company_city'] ?? '') ?>" placeholder="Ex.: Itabira"></div>
        <div><label>Cidade IBGE</label><input id="fiscalCityIbge" name="city_ibge" maxlength="7" value="<?= fiscal_h($config['city_ibge'] ?? '') ?>" placeholder="3131703"></div>
        <div>
          <label>CEP</label>
          <div class="fiscal-inline-actions" style="justify-content:flex-start;flex-wrap:nowrap">
            <input id="fiscalCompanyZip" name="company_zip" maxlength="9" inputmode="numeric" autocomplete="postal-code" value="<?= fiscal_h($config['company_zip'] ?? '') ?>" placeholder="00000-000" style="flex:1;min-width:0">
            <button type="button" class="btn btn-sm" id="fiscalLookupCep">Buscar CEP</button>
          </div>
          <div id="fiscalCepLookupStatus" class="fiscal-mini-help" aria-live="polite"></div>
        </div>
        <div class="span-2"><label>Logradouro</label><input id="fiscalCompanyAddress" name="company_address" value="<?= fiscal_h($config['company_address'] ?? '') ?>" placeholder="Rua, avenida, rodovia..."></div>
        <div><label>Número</label><input id="fiscalCompanyNumber" name="company_number" value="<?= fiscal_h($config['company_number'] ?? '') ?>" placeholder="S/N"></div>
        <div><label>Complemento</label><input id="fiscalCompanyComplement" name="company_complement" value="<?= fiscal_h($config['company_complement'] ?? '') ?>"></div>
        <div><label>Bairro</label><input id="fiscalCompanyDistrict" name="company_district" value="<?= fiscal_h($config['company_district'] ?? '') ?>"></div>
        <div><label>Contato</label><input name="company_contact" value="<?= fiscal_h($config['company_contact'] ?? '') ?>"></div>
        <div><label>Telefone</label><input id="fiscalCompanyPhone" name="company_phone" value="<?= fiscal_h($config['company_phone'] ?? '') ?>"></div>
        <div><label>Fax Sintegra</label><input name="company_fax" value="<?= fiscal_h($config['company_fax'] ?? '') ?>" placeholder="Opcional"></div>
      </div></div>
      <div class="fiscal-section"><h3>Ambiente e regime</h3><div class="fiscal-form-grid">
        <div><label>Ambiente</label><select name="environment"><option value="HOMOLOGACAO" <?= (($config['environment'] ?? '')==='HOMOLOGACAO')?'selected':'' ?>>Homologação</option><option value="PRODUCAO" <?= (($config['environment'] ?? '')==='PRODUCAO')?'selected':'' ?>>Produção</option></select></div>
        <div><label>CRT</label><select id="fiscalCrt" name="crt"><option value="">Selecione</option><?php foreach(['1'=>'1 - Simples Nacional','2'=>'2 - Simples Nacional — excesso de sublimite','3'=>'3 - Regime Normal','4'=>'4 - Simples Nacional — MEI'] as $k=>$v): ?><option value="<?= $k ?>" <?= ((string)($config['crt'] ?? '')===$k)?'selected':'' ?>><?= fiscal_h($v) ?></option><?php endforeach; ?></select></div>
        <div><label>Nome do regime</label><input id="fiscalRegimeName" name="regime_name" value="<?= fiscal_h($config['regime_name'] ?? '') ?>" placeholder="Ex.: Simples Nacional"></div>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5">Esses dados alimentam os Registros 10 e 11 do Sintegra e passam a ser a fonte principal do CNPJ/IE/UF usados nas rotinas fiscais da empresa.</div>
      </div></div>
      <div class="fiscal-section"><h3>Opções disponíveis na finalização</h3><div class="fiscal-form-grid">
        <label class="fiscal-checkline"><input type="checkbox" name="nfce_enabled" value="1" <?= !empty($config['nfce_enabled'])?'checked':'' ?>> Habilita emissão de NFC-e</label>
        <label class="fiscal-checkline"><input type="checkbox" name="nfe_enabled" value="1" <?= !empty($config['nfe_enabled'])?'checked':'' ?>> Habilita emissão de NF-e</label>
        <label class="fiscal-checkline"><input type="checkbox" name="nfse_enabled" value="1" <?= !empty($config['nfse_enabled'])?'checked':'' ?>> Habilita emissão de NFS-e</label>
        <label class="fiscal-checkline span-3"><input type="checkbox" name="save_order_enabled" value="1" <?= !array_key_exists('save_order_enabled',$config) || !empty($config['save_order_enabled'])?'checked':'' ?>> Somente Gravar Pedido</label>
        <label class="fiscal-checkline span-3"><input type="checkbox" name="order_auto_print_enabled" value="1" <?= !array_key_exists('order_auto_print_enabled', $config) || !empty($config['order_auto_print_enabled'])?'checked':'' ?>> Habilitar impressão automática do pedido em toda finalização de venda</label>
        <label class="fiscal-checkline span-3"><input type="checkbox" name="order_print_disabled" value="1" <?= !empty($config['order_print_disabled'])?'checked':'' ?>> Desabilitar impresso de pedido</label>
        <?php if ($orderModernPrintReady): ?>
        <label class="fiscal-checkline span-2"><input type="checkbox" name="order_modern_print_enabled" value="1" <?= !empty($config['order_modern_print_enabled'])?'checked':'' ?>> Habilitar novos modelos do Pedido (A4 e 72 mm)</label>
        <div><label>Modelo padrão do novo Pedido</label><select name="order_modern_print_default"><option value="A4" <?= strtoupper((string)($config['order_modern_print_default']??'A4'))==='A4'?'selected':'' ?>>A4</option><option value="72MM" <?= strtoupper((string)($config['order_modern_print_default']??''))==='72MM'?'selected':'' ?>>72 mm</option></select></div>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5"><b>Compatibilidade:</b> enquanto esta opção estiver desmarcada, o impresso atual do pedido permanece exatamente como está. Ao habilitar, o botão normal de imprimir abre o novo layout e permite alternar entre A4 e 72 mm; o modelo anterior continua disponível como contingência.</div>
        <?php else: ?>
        <div class="span-3" style="padding:10px 12px;border:1px solid #f59e0b;background:#fffbeb;border-radius:12px;color:#92400e;font-weight:800">Novos modelos do Pedido ainda não disponíveis. Aplique a migration <b>V11.45.18</b> pelo Atualizador do Banco.</div>
        <?php endif; ?>
        <label class="fiscal-checkline span-3"><input type="checkbox" name="print_pack_58mm_enabled" value="1" <?= !empty($config['print_pack_58mm_enabled'])?'checked':'' ?>> Habilitar pack de impressos 58mm para máquinas de cartão</label>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5">Na finalização do PDV e das comandas aparecem somente as opções marcadas acima. Desmarque Somente Gravar Pedido quando o operador for obrigado a escolher um documento fiscal. A impressão automática só dispara a impressão ao finalizar se estiver habilitada. Quando o impresso de pedido estiver desabilitado, o sistema finaliza e volta para a operação sem abrir a tela do pedido. Os novos modelos A4/72 mm do Pedido só entram em uso após habilitação explícita; caso contrário, o modelo atual permanece intacto. O pack 58mm continua valendo para os impressos legados/produção, cozinha e NFC-e.</div>
      </div></div>
      <div class="fiscal-section"><h3>Contingência fiscal</h3><div class="fiscal-form-grid">
        <label class="fiscal-checkline span-3"><input type="checkbox" name="contingency_enabled" value="1" <?= !empty($config['contingency_enabled'])?'checked':'' ?>> Habilitar contingência automática em falha comprovada do serviço autorizador</label>
        <label class="fiscal-checkline"><input type="checkbox" name="nfce_offline_contingency_enabled" value="1" <?= !array_key_exists('nfce_offline_contingency_enabled',$config) || !empty($config['nfce_offline_contingency_enabled'])?'checked':'' ?>> NFC-e offline (tpEmis 9)</label>
        <label class="fiscal-checkline"><input type="checkbox" name="nfe_svc_contingency_enabled" value="1" <?= !array_key_exists('nfe_svc_contingency_enabled',$config) || !empty($config['nfe_svc_contingency_enabled'])?'checked':'' ?>> NF-e pela SVC</label>
        <label class="fiscal-checkline"><input type="checkbox" name="contingency_status_check_enabled" value="1" <?= !array_key_exists('contingency_status_check_enabled',$config) || !empty($config['contingency_status_check_enabled'])?'checked':'' ?>> Consultar status antes de transmitir</label>
        <label class="fiscal-checkline span-2"><input type="checkbox" name="contingency_login_alert_enabled" value="1" <?= !array_key_exists('contingency_login_alert_enabled',$config) || !empty($config['contingency_login_alert_enabled'])?'checked':'' ?>> Ao entrar no sistema, avisar e abrir Notas em lote quando houver NFC-e pendente de contingência</label>
        <div><label>Tipo de SVC da NF-e</label><select name="nfe_svc_type"><option value="AUTO" <?= (($config['nfe_svc_type'] ?? 'AUTO')==='AUTO')?'selected':'' ?>>Automático conforme UF</option><option value="SVCAN" <?= (($config['nfe_svc_type'] ?? '')==='SVCAN')?'selected':'' ?>>SVC-AN</option><option value="SVCRS" <?= (($config['nfe_svc_type'] ?? '')==='SVCRS')?'selected':'' ?>>SVC-RS</option></select></div>
        <div class="span-2"><label>Justificativa padrão</label><input name="contingency_reason" minlength="15" maxlength="255" value="<?= fiscal_h($config['contingency_reason'] ?? 'Falha de comunicação com o ambiente autorizador da SEFAZ.') ?>"></div>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5"><b>NFC-e:</b> quando o status do serviço estiver indisponível antes do envio normal, gera o XML offline, permite imprimir e mantém a nota em <b>Notas em lote</b> até a transmissão. <b>NF-e:</b> tenta a SVC configurada; quando autorizada pela SVC, a NF-e já possui protocolo e não entra no lote. Esta versão não automatiza EPEC ou FS-DA.</div>
      </div></div>
      <div class="fiscal-section"><h3>Motor de emissão fiscal</h3><div class="fiscal-form-grid">
        <div><label>Motor recomendado</label><select name="emission_adapter"><option value="NFEPHP" <?= (($config['emission_adapter'] ?? 'NFEPHP')==='NFEPHP')?'selected':'' ?>>NFePHP/sped-nfe</option><option value="ACBRMONITOR" <?= (($config['emission_adapter'] ?? '')==='ACBRMONITOR')?'selected':'' ?>>ACBrMonitor</option><option value="ACBRLIB" <?= (($config['emission_adapter'] ?? '')==='ACBRLIB')?'selected':'' ?>>ACBrLib</option><option value="OUTRO" <?= (($config['emission_adapter'] ?? '')==='OUTRO')?'selected':'' ?>>Outro</option></select></div>
        <div><label>Modo ACBr, se usar ACBr</label><select name="acbr_mode"><option value="ACBRMONITOR" <?= (($config['acbr_mode'] ?? '')==='ACBRMONITOR')?'selected':'' ?>>ACBrMonitor</option><option value="ACBRLIB" <?= (($config['acbr_mode'] ?? '')==='ACBRLIB')?'selected':'' ?>>ACBrLib</option><option value="OUTRO" <?= (($config['acbr_mode'] ?? '')==='OUTRO')?'selected':'' ?>>Outro</option></select></div>
        <div><label>Host ACBr</label><input name="acbr_host" value="<?= fiscal_h($config['acbr_host'] ?? '') ?>" placeholder="127.0.0.1"></div>
        <div><label>Porta ACBr</label><input name="acbr_port" value="<?= fiscal_h($config['acbr_port'] ?? '') ?>" placeholder="3434"></div>
        <div><label>Pasta XML saída</label><input name="xml_out_path" value="<?= fiscal_h($config['xml_out_path'] ?? '') ?>"></div>
        <div><label>Pasta XML entrada</label><input name="xml_in_path" value="<?= fiscal_h($config['xml_in_path'] ?? '') ?>"></div>
        <div><label>Pasta DANFE/PDF</label><input name="danfe_path" value="<?= fiscal_h($config['danfe_path'] ?? '') ?>"></div>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5">Sugestão para este projeto web/PHP: usar <b>NFePHP/sped-nfe</b> como motor principal. O ACBr permanece previsto para uma integração futura, se a operação exigir um serviço local/desktop.</div>
      </div></div>
      <div class="fiscal-section"><h3>NFC-e e NFS-e</h3><div class="fiscal-form-grid">
        <div><label>ID CSC NFC-e</label><input name="nfce_csc_id" value="<?= fiscal_h($config['nfce_csc_id'] ?? '') ?>"></div>
        <div class="span-2"><label>Token CSC NFC-e</label><input name="nfce_csc_token" value="<?= fiscal_h($config['nfce_csc_token'] ?? '') ?>"></div>
        <div><label>Versão QR Code</label><input name="nfce_qrcode_version" value="<?= fiscal_h($config['nfce_qrcode_version'] ?? '2.00') ?>"></div>
        <div><label>Provedor NFS-e</label><select name="nfse_provider"><option value="NACIONAL" <?= strtoupper((string)($config['nfse_provider'] ?? 'NACIONAL'))==='NACIONAL'?'selected':'' ?>>Ambiente Nacional</option><option value="PRONIM" <?= strtoupper((string)($config['nfse_provider'] ?? ''))==='PRONIM'?'selected':'' ?>>Pronim / GOVBR - ISS Digital</option></select></div>
        <label class="fiscal-checkline"><input type="checkbox" name="nfse_national_standard" value="1" <?= !empty($config['nfse_national_standard'])?'checked':'' ?>> Usar leiaute Nacional 1.01</label>
        <?php $nfseSnRate = (float)((config_store_get($pdo, $cid, 'nfse_nacional') ?: [])['sn_total_tax_rate'] ?? 0); ?>
        <div><label>% aproximado de tributos do Simples Nacional</label><input name="nfse_sn_total_tax_rate" inputmode="decimal" value="<?= $nfseSnRate > 0 ? fiscal_h(number_format($nfseSnRate, 2, ',', '')) : '' ?>" placeholder="Ex.: 6,00"></div>
        <div class="span-2 fiscal-muted" style="font-weight:700;line-height:1.5">NFS-e Nacional para ME/EPP do Simples: alíquota efetiva do DAS da empresa (percentual informado na nota como total aproximado de tributos). Confirme o valor com o contador; ele muda conforme a faixa de faturamento.</div>
        <div><label>Perfil Pronim</label><input name="nfse_pronim_profile" value="<?= fiscal_h($config['nfse_pronim_profile'] ?? 'ITABIRA_MG') ?>" placeholder="ITABIRA_MG"></div>
        <div class="span-3"><label>URL homologação Pronim</label><input name="nfse_pronim_hom_base" value="<?= fiscal_h($config['nfse_pronim_hom_base'] ?? 'https://reformatributaria.govbr.cloud/NFSe.Api.Teste/NotaNacional') ?>"></div>
        <div class="span-3"><label>URL produção Pronim</label><input name="nfse_pronim_prod_base" value="<?= fiscal_h($config['nfse_pronim_prod_base'] ?? 'https://webapp1-itabira.cidade360.cloud/NFSe.Api/NotaNacional') ?>"></div>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5">No modo Pronim/GOVBR o Nexa gera DPS no leiaute Nacional 1.01, aplica o perfil municipal e transmite pela API ISS Digital. O emissor Nacional permanece independente.</div>
      </div></div>
      <div class="fiscal-section"><h3>NF-e vinculada a NFC-e</h3><div class="fiscal-form-grid">
        <div><label>CFOP dentro do estado</label><input name="nfe_nfce_referenced_cfop_internal" maxlength="4" inputmode="numeric" value="<?= fiscal_h(($config['nfe_nfce_referenced_cfop_internal'] ?? '') ?: '5949') ?>"></div>
        <div><label>CFOP fora do estado</label><input name="nfe_nfce_referenced_cfop_external" maxlength="4" inputmode="numeric" value="<?= fiscal_h(($config['nfe_nfce_referenced_cfop_external'] ?? '') ?: '6949') ?>"></div>
        <div class="span-3 fiscal-muted" style="font-weight:700;line-height:1.5">Padrão sugerido: 5.949/6.949 para NF-e posterior vinculada a NFC-e. Evita as validações de Cupom Fiscal/ECF dos CFOPs 5.929/6.929. Valide o CFOP definitivo com o contador da empresa.</div>
      </div></div>
      <div class="fiscal-section"><h3>Observações</h3><textarea name="notes"><?= fiscal_h($config['notes'] ?? '') ?></textarea></div>
      <div class="fiscal-form-actions"><a class="btn" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php')) ?>">Cancelar</a><button class="btn btn-primary">Salvar configurações</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($openModal === 'series'):
  $seriesForm = $editSeries ?: ['id'=>0,'model'=>'55','series'=>'1','environment'=>($config['environment'] ?? 'HOMOLOGACAO'),'next_number'=>1,'last_number'=>0,'emission_type'=>'NORMAL','is_active'=>1,'notes'=>''];
?>
<div class="fiscal-modal" role="dialog" aria-modal="true">
  <div class="fiscal-modal-dialog">
    <div class="fiscal-modal-head"><div><h2><?= $editSeries ? 'Editar série fiscal' : 'Nova série fiscal' ?></h2><p class="fiscal-muted" style="margin:5px 0 0">A série controla a numeração sequencial de cada modelo e ambiente.</p></div><a class="fiscal-modal-close" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php')) ?>" aria-label="Fechar">×</a></div>
    <form method="post" class="fiscal-form">
      <input type="hidden" name="action" value="save_series"><?php if ($fiscalSettingsWriteF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalSettingsMcCsrf) ?>"><?php endif; ?><?php if ($fiscalSettingsWriteF11 && $fiscalSettingsContextToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($fiscalSettingsContextToken) ?>"><?php endif; ?><input type="hidden" name="id" value="<?= (int)($seriesForm['id'] ?? 0) ?>">
      <div class="fiscal-form-grid">
        <div><label>Modelo</label><select name="model" required><?php foreach(['55'=>'NF-e 55','65'=>'NFC-e 65','NFSE'=>'NFS-e','DPS'=>'DPS','RPS'=>'RPS','SS'=>'Série Serviço/SS'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($seriesForm['model'] ?? '55')===$k)?'selected':'' ?>><?= fiscal_h($v) ?></option><?php endforeach; ?></select></div>
        <div><label>Série</label><input name="series" value="<?= fiscal_h($seriesForm['series'] ?? '1') ?>" required></div>
        <div><label>Ambiente</label><select name="environment"><option value="HOMOLOGACAO" <?= (($seriesForm['environment'] ?? 'HOMOLOGACAO')==='HOMOLOGACAO')?'selected':'' ?>>Homologação</option><option value="PRODUCAO" <?= (($seriesForm['environment'] ?? '')==='PRODUCAO')?'selected':'' ?>>Produção</option></select></div>
        <div><label>Próximo número</label><input name="next_number" value="<?= fiscal_h($seriesForm['next_number'] ?? '1') ?>" inputmode="numeric"></div>
        <div><label>Último número</label><input name="last_number" value="<?= fiscal_h($seriesForm['last_number'] ?? '0') ?>" inputmode="numeric"></div>
        <div><label>Tipo emissão</label><input name="emission_type" value="<?= fiscal_h($seriesForm['emission_type'] ?? 'NORMAL') ?>"></div>
        <label class="fiscal-checkline"><input type="checkbox" name="is_active" value="1" <?= (!isset($seriesForm['is_active']) || !empty($seriesForm['is_active']))?'checked':'' ?>> Série ativa</label>
        <div class="span-2"><label>Observações</label><input name="notes" value="<?= fiscal_h($seriesForm['notes'] ?? '') ?>"></div>
      </div>
      <div class="fiscal-form-actions"><a class="btn" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php')) ?>">Cancelar</a><button class="btn btn-primary">Salvar série</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($openModal === 'certificate'):
  $certForm = $editCert ?: ['id'=>0,'certificate_type'=>'A1','display_name'=>'','file_path'=>'','serial_number'=>'','subject_name'=>'','valid_from'=>'','valid_until'=>'','is_active'=>1];
?>
<div class="fiscal-modal" role="dialog" aria-modal="true">
  <div class="fiscal-modal-dialog fiscal-modal-wide">
    <div class="fiscal-modal-head"><div><h2><?= $editCert ? 'Editar certificado digital' : 'Novo certificado digital' ?></h2><p class="fiscal-muted" style="margin:5px 0 0">Ao enviar um certificado A1 .pfx/.p12 com a senha correta, o sistema preenche automaticamente número de série, subject e validade.</p></div><a class="fiscal-modal-close" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php')) ?>" aria-label="Fechar">×</a></div>
    <form method="post" enctype="multipart/form-data" class="fiscal-form">
      <input type="hidden" name="action" value="save_certificate"><?php if ($fiscalSettingsWriteF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalSettingsMcCsrf) ?>"><?php endif; ?><?php if ($fiscalSettingsWriteF11 && $fiscalSettingsContextToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($fiscalSettingsContextToken) ?>"><?php endif; ?><input type="hidden" name="id" value="<?= (int)($certForm['id'] ?? 0) ?>"><input type="hidden" name="current_file_path" value="<?= fiscal_h($certForm['file_path'] ?? '') ?>">
      <div class="fiscal-section"><h3>Arquivo e identificação</h3><div class="fiscal-form-grid">
        <div><label>Tipo</label><select name="certificate_type"><option value="A1" <?= (($certForm['certificate_type'] ?? 'A1')==='A1')?'selected':'' ?>>A1</option><option value="A3" <?= (($certForm['certificate_type'] ?? '')==='A3')?'selected':'' ?>>A3</option></select></div>
        <div class="span-2"><label>Nome de exibição</label><input name="display_name" value="<?= fiscal_h($certForm['display_name'] ?? '') ?>" placeholder="Será preenchido pelo certificado se ficar em branco"></div>
        <div><label>Arquivo</label><input type="file" name="certificate_file" accept=".pfx,.p12,.cer,.crt,.pem"><div class="fiscal-mini-help"><?= !empty($certForm['file_path']) ? 'Arquivo atual: ' . fiscal_h($certForm['file_path']) : 'Formatos aceitos: .pfx, .p12, .cer, .crt ou .pem' ?></div></div>
        <div><label>Senha</label><input type="password" name="password_secret" autocomplete="new-password" placeholder="Obrigatória para ler A1 .pfx/.p12"><div class="fiscal-mini-help">Deixe em branco ao editar sem trocar o arquivo. Ao enviar um novo .pfx/.p12, informe a senha exata do novo certificado.</div></div>
        <label class="fiscal-checkline"><input type="checkbox" name="is_active" value="1" <?= (!isset($certForm['is_active']) || !empty($certForm['is_active']))?'checked':'' ?>> Ativar certificado</label>
      </div></div>
      <div class="fiscal-section"><h3>Dados lidos do certificado</h3><div class="fiscal-form-grid">
        <div><label>Nº série</label><input name="serial_number" value="<?= fiscal_h($certForm['serial_number'] ?? '') ?>" placeholder="Preenchido automaticamente"></div>
        <div><label>Válido de</label><input type="date" name="valid_from" value="<?= fiscal_h($certForm['valid_from'] ?? '') ?>"></div>
        <div><label>Válido até</label><input type="date" name="valid_until" value="<?= fiscal_h($certForm['valid_until'] ?? '') ?>"></div>
        <div class="span-3"><label>Subject/Nome</label><input name="subject_name" value="<?= fiscal_h($certForm['subject_name'] ?? '') ?>" placeholder="Preenchido automaticamente"></div>
      </div></div>
      <div class="fiscal-form-actions"><a class="btn" href="<?= fiscal_h($fiscalSettingsUrl('fiscal_configuracoes.php')) ?>">Cancelar</a><button class="btn btn-primary">Salvar certificado</button></div>
    </form>
  </div>
</div>
<?php endif; ?>


<?php if ($openModal === 'config'): ?>
<script>
(() => {
  const byId = (id) => document.getElementById(id);
  const onlyDigits = (value) => String(value || '').replace(/\D+/g, '');
  const formatCnpj = (value) => {
    const d = onlyDigits(value).slice(0, 14);
    return d.replace(/^(\d{2})(\d)/, '$1.$2')
      .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/\.(\d{3})(\d)/, '.$1/$2')
      .replace(/(\d{4})(\d)/, '$1-$2');
  };
  const formatCep = (value) => onlyDigits(value).slice(0, 8).replace(/(\d{5})(\d{1,3})$/, '$1-$2');
  const setStatus = (element, message, type = '') => {
    if (!element) return;
    element.textContent = message || '';
    element.style.color = type === 'error' ? '#b42318' : (type === 'success' ? '#067647' : '');
    element.style.fontWeight = message ? '700' : '';
  };
  const setValue = (id, value, overwrite = true) => {
    const element = byId(id);
    const next = String(value ?? '').trim();
    if (!element || next === '') return;
    if (overwrite || String(element.value || '').trim() === '') element.value = next;
  };

  const cnpjInput = byId('fiscalCompanyCnpj');
  const cepInput = byId('fiscalCompanyZip');
  const cnpjStatus = byId('fiscalCnpjLookupStatus');
  const cepStatus = byId('fiscalCepLookupStatus');
  const cnpjButton = byId('fiscalLookupCnpj');
  const cepButton = byId('fiscalLookupCep');
  let cnpjTimer = null;
  let cepTimer = null;
  let lastCnpj = '';
  let lastCep = '';
  let cnpjBusy = false;
  let cepBusy = false;

  async function lookupCep(force = false) {
    const cep = onlyDigits(cepInput?.value);
    if (cep.length !== 8) {
      if (force) setStatus(cepStatus, 'Informe um CEP com 8 dígitos.', 'error');
      return;
    }
    if (!force && (cep === lastCep || cepBusy)) return;
    cepBusy = true;
    if (cepButton) cepButton.disabled = true;
    setStatus(cepStatus, 'Consultando CEP...');
    try {
      const response = await fetch('../api/cep.php?cep=' + encodeURIComponent(cep), {
        headers: {'Accept': 'application/json'}, cache: 'no-store'
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'CEP não encontrado.');
      const data = payload.data || {};
      setValue('fiscalCompanyAddress', data.logradouro || '');
      setValue('fiscalCompanyComplement', data.complemento || '', false);
      setValue('fiscalCompanyDistrict', data.bairro || '');
      setValue('fiscalCompanyCity', data.cidade || '');
      setValue('fiscalCompanyUf', data.uf || '');
      setValue('fiscalCityIbge', data.ibge || '');
      lastCep = cep;
      setStatus(cepStatus, 'Endereço carregado pelo CEP. Confira o número antes de salvar.', 'success');
      if (byId('fiscalCompanyNumber') && !byId('fiscalCompanyNumber').value) byId('fiscalCompanyNumber').focus();
    } catch (error) {
      setStatus(cepStatus, error.message || 'Não foi possível consultar o CEP.', 'error');
    } finally {
      cepBusy = false;
      if (cepButton) cepButton.disabled = false;
    }
  }

  async function lookupCnpj(force = false) {
    const cnpj = onlyDigits(cnpjInput?.value);
    if (cnpj.length !== 14) {
      if (force) setStatus(cnpjStatus, 'Informe um CNPJ com 14 dígitos.', 'error');
      return;
    }
    if (!force && (cnpj === lastCnpj || cnpjBusy)) return;
    cnpjBusy = true;
    if (cnpjButton) cnpjButton.disabled = true;
    setStatus(cnpjStatus, 'Consultando CNPJ...');
    try {
      const response = await fetch('../api/cnpj.php?cnpj=' + encodeURIComponent(cnpj), {
        headers: {'Accept': 'application/json'}, cache: 'no-store'
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'CNPJ não encontrado.');
      const data = payload.data || {};
      setValue('fiscalCompanyName', data.razao_social || '');
      setValue('fiscalCompanyTradeName', data.nome_fantasia || '');
      setValue('fiscalStateRegistration', data.inscricao_estadual || '', false);
      setValue('fiscalCompanyPhone', data.telefone || '', false);
      if (data.cep) {
        setValue('fiscalCompanyZip', formatCep(data.cep));
        lastCep = '';
      }
      setValue('fiscalCompanyAddress', data.endereco || '');
      setValue('fiscalCompanyNumber', data.numero || '', false);
      setValue('fiscalCompanyComplement', data.complemento || '', false);
      setValue('fiscalCompanyDistrict', data.bairro || '');
      setValue('fiscalCompanyCity', data.cidade || '');
      setValue('fiscalCompanyUf', data.estado || '');
      setValue('fiscalCityIbge', data.city_ibge || '');
      lastCnpj = cnpj;
      setStatus(cnpjStatus, 'Dados cadastrais carregados. Confira antes de salvar.', 'success');

      const addressMissing = !String(byId('fiscalCompanyAddress')?.value || '').trim();
      if (data.cep && addressMissing) await lookupCep(true);
    } catch (error) {
      setStatus(cnpjStatus, error.message || 'Não foi possível consultar o CNPJ.', 'error');
    } finally {
      cnpjBusy = false;
      if (cnpjButton) cnpjButton.disabled = false;
    }
  }

  if (cnpjInput) {
    cnpjInput.value = formatCnpj(cnpjInput.value);
    cnpjInput.addEventListener('input', () => {
      cnpjInput.value = formatCnpj(cnpjInput.value);
      lastCnpj = '';
      clearTimeout(cnpjTimer);
      if (onlyDigits(cnpjInput.value).length === 14) cnpjTimer = setTimeout(() => lookupCnpj(false), 500);
    });
    cnpjInput.addEventListener('blur', () => lookupCnpj(false));
  }
  if (cepInput) {
    cepInput.value = formatCep(cepInput.value);
    cepInput.addEventListener('input', () => {
      cepInput.value = formatCep(cepInput.value);
      lastCep = '';
      clearTimeout(cepTimer);
      if (onlyDigits(cepInput.value).length === 8) cepTimer = setTimeout(() => lookupCep(false), 450);
    });
    cepInput.addEventListener('blur', () => lookupCep(false));
  }
  cnpjButton?.addEventListener('click', () => lookupCnpj(true));
  cepButton?.addEventListener('click', () => lookupCep(true));

  const crt = byId('fiscalCrt');
  const regime = byId('fiscalRegimeName');
  crt?.addEventListener('change', () => {
    const labels = {
      '1': 'Simples Nacional',
      '2': 'Simples Nacional - excesso de sublimite',
      '3': 'Regime Normal',
      '4': 'Simples Nacional - MEI'
    };
    if (regime && labels[crt.value]) regime.value = labels[crt.value];
  });
})();
</script>
<?php endif; ?>

<?php fiscal_admin_end(); ?>
