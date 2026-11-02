<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Este script deve ser executado via CLI.\n");
}

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';
require_once __DIR__ . '/../config/reseller_integrations.php';

$ok = 0;
$warn = 0;

function wa_mig_line(string $text): void { echo $text . PHP_EOL; }

try {
  saas_whatsapp_ensure_schema($pdo);
  reseller_integrations_ensure_schema($pdo);

  wa_mig_line('=== Central SaaS ===');
  $central = saas_whatsapp_load_config($pdo);
  $centralChanged = false;
  if ((string)$central['provider'] === 'unofficial') {
    if (trim((string)$central['api_token']) === '') {
      $central['api_token'] = saas_whatsapp_generate_token();
      $centralChanged = true;
      wa_mig_line('Token WebJS da Central SaaS gerado.');
    }
    if ($centralChanged) {
      saas_whatsapp_save_config($pdo, $central);
      $central = saas_whatsapp_load_config($pdo);
    }
    $centralId = saas_whatsapp_unofficial_instance_id($central);
    $sync = saas_whatsapp_register_unofficial_token($central, (int)$centralId, (string)$central['api_token']);
    if (!empty($sync['ok'])) { $ok++; wa_mig_line('OK Central SaaS: company-' . $centralId . ' registrado no serviço.'); }
    else { $warn++; wa_mig_line('AVISO Central SaaS: não foi possível sincronizar token agora: ' . (string)($sync['error'] ?? 'falha desconhecida')); }
  } else {
    wa_mig_line('Central SaaS está configurada para API Oficial; configuração preservada.');
  }

  wa_mig_line('');
  wa_mig_line('=== Revendas ===');
  $resellerIds = [];
  try {
    $resellerIds = $pdo->query('SELECT reseller_company_id FROM saas_reseller_whatsapp_config ORDER BY reseller_company_id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) {}

  foreach ($resellerIds as $ridRaw) {
    $rid = (int)$ridRaw;
    if ($rid <= 0) continue;
    $cfg = reseller_whatsapp_load_config($pdo, $rid);
    if ((string)$cfg['provider'] !== 'unofficial') {
      wa_mig_line('Revenda #' . $rid . ': API Oficial preservada.');
      continue;
    }
    $changed = false;
    $instance = preg_replace('/\D+/', '', (string)$cfg['unofficial_instance_id']) ?: '';
    if ($instance === '' || $instance === '0') {
      $cfg['unofficial_instance_id'] = reseller_whatsapp_default_instance_id($rid);
      $changed = true;
    }
    if (trim((string)$cfg['api_token']) === '') {
      $cfg['api_token'] = saas_whatsapp_generate_token();
      $changed = true;
    }
    if ($changed) $cfg = reseller_whatsapp_save_config($pdo, $rid, $cfg);
    $instance = (string)$cfg['unofficial_instance_id'];
    $sync = saas_whatsapp_register_unofficial_token($cfg, (int)$instance, (string)$cfg['api_token']);
    if (!empty($sync['ok'])) { $ok++; wa_mig_line('OK Revenda #' . $rid . ': company-' . $instance . ' registrado no serviço.'); }
    else { $warn++; wa_mig_line('AVISO Revenda #' . $rid . ': token não sincronizado agora: ' . (string)($sync['error'] ?? 'falha desconhecida')); }
  }

  // Diagnóstico de IDs compartilhados entre empresa/central/revenda. Não altera automaticamente
  // uma sessão existente para evitar forçar novo QR Code sem necessidade.
  $used = [];
  try {
    $st = $pdo->query("SELECT c.id, ac.cfg_json FROM companies c JOIN app_config ac ON ac.company_id=c.id AND ac.cfg_key='whatsapp'");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $cfg = json_decode((string)$r['cfg_json'], true) ?: [];
      $sid = preg_replace('/\D+/', '', (string)($cfg['session_company_id'] ?? '')) ?: (string)$r['id'];
      if ($sid !== '' && $sid !== '0') $used[$sid][] = 'Empresa #' . (int)$r['id'];
    }
  } catch (Throwable $e) {}

  if ((string)$central['provider'] === 'unofficial') {
    $used[saas_whatsapp_unofficial_instance_id($central)][] = 'Central SaaS';
  }
  foreach ($resellerIds as $ridRaw) {
    $rid = (int)$ridRaw;
    if ($rid <= 0) continue;
    $cfg = reseller_whatsapp_load_config($pdo, $rid);
    if ((string)$cfg['provider'] !== 'unofficial') continue;
    $sid = (string)$cfg['unofficial_instance_id'];
    if ($sid !== '' && $sid !== '0') $used[$sid][] = 'Revenda #' . $rid;
  }

  $conflicts = 0;
  foreach ($used as $sid => $owners) {
    $owners = array_values(array_unique($owners));
    if (count($owners) <= 1) continue;
    $conflicts++;
    wa_mig_line('ATENÇÃO: company-' . $sid . ' está compartilhado por: ' . implode(', ', $owners) . '. O monitor bloqueará ações até separar esse ID.');
  }

  wa_mig_line('');
  wa_mig_line('Concluído. Tokens sincronizados: ' . $ok . '; avisos: ' . $warn . '; conflitos de ID: ' . $conflicts . '.');
  exit(0);
} catch (Throwable $e) {
  fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
