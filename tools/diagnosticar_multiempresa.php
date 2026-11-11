<?php
declare(strict_types=1);

/**
 * Diagnóstico do Multiempresa (troca de empresa pelo F11) de um usuário.
 * Uso (VPS): php /var/www/nexa-homolog/tools/diagnosticar_multiempresa.php email@do.usuario
 *        ou: php /var/www/nexa-homolog/tools/diagnosticar_multiempresa.php ID_DO_USUARIO
 * Mostra por que o botão de troca de empresa aparece (ou não). Só lê dados.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Execute somente pelo terminal.\n"); }

$root = dirname(__DIR__);
require_once $root . '/config/pdo.php';
require_once $root . '/config/helpers.php';
require_once $root . '/config/saas_modules.php';
@include_once $root . '/saas/saas_guard.php';
require_once $root . '/config/multi_company.php';

$line = static function (string $t = ''): void { echo $t . "\n"; };
$yes = static fn(bool $v): string => $v ? 'sim' : 'NÃO';
$reasons = [
  'ok' => 'OK - aparece no F11',
  'estrutura_multiempresa_indisponivel' => 'estrutura do Multiempresa não instalada no banco',
  'multiempresa_desabilitado_origem' => 'módulo Multiempresa desligado na empresa do login (SaaS > Empresa > Módulos)',
  'multiempresa_desabilitado_destino' => 'módulo Multiempresa desligado nesta empresa (SaaS > Empresa > Módulos)',
  'grupo_empresarial_invalido' => 'empresa fora de grupo ativo (ou vínculo inativo no grupo)',
  'empresa_fora_do_grupo' => 'empresa de outro grupo',
  'empresa_destino_indisponivel' => 'empresa bloqueada/suspensa ou com assinatura bloqueada',
  'usuario_sem_vinculo_ativo_no_grupo' => 'usuário ainda não foi configurado em Usuários > Acessos multiempresa (salve os acessos dele)',
  'usuario_sem_acesso_empresa' => 'empresa não marcada para o usuário em Acessos multiempresa',
  'perfil_empresa_nao_configurado' => 'falta escolher o perfil (ou "Administrador nesta empresa") em Acessos multiempresa',
  'perfil_empresa_inativo' => 'o perfil escolhido foi inativado ou não é desta empresa',
  'perfil_integrado_nao_configurado' => 'grupo Integrado: falta o perfil integrado do usuário',
  'perfil_integrado_inativo' => 'grupo Integrado: perfil integrado inativo',
  'modo_grupo_invalido' => 'modo do grupo inválido',
];

$arg = trim((string)($argv[1] ?? ''));
if ($arg === '') { $line('Informe o e-mail ou o ID do usuário. Ex.: php tools/diagnosticar_multiempresa.php fulano@empresa.com.br'); exit(1); }
$st = ctype_digit($arg)
  ? $pdo->prepare('SELECT id, company_id, name, email, role, is_active FROM users WHERE id = ? LIMIT 1')
  : $pdo->prepare('SELECT id, company_id, name, email, role, is_active FROM users WHERE LOWER(email) = LOWER(?) ORDER BY id LIMIT 1');
$st->execute([ctype_digit($arg) ? (int)$arg : $arg]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) { $line('Usuário não encontrado: ' . $arg); exit(1); }

$uid = (int)$user['id'];
$entry = (int)$user['company_id'];
$line('== Multiempresa: ' . $user['name'] . ' <' . $user['email'] . '> (usuário #' . $uid . ', ' . $user['role'] . ', ativo: ' . $yes((int)$user['is_active'] === 1) . ')');
$line('Estrutura Multiempresa no banco: ' . $yes(multi_company_schema_ready($pdo)));
$line('Opção "Administrador nesta empresa" (migração V13): ' . $yes(multi_company_access_admin_column_ready($pdo)));
if (!multi_company_schema_ready($pdo)) exit(1);

$entryRow = multi_company_context_company_row($pdo, $entry);
$line('Empresa do login: #' . $entry . ' ' . (string)($entryRow['name'] ?? '?') . ' (' . (string)($entryRow['slug'] ?? '') . ')');
$line('Módulo Multiempresa na empresa do login: ' . $yes(multi_company_feature_enabled($pdo, $entry)));

$group = multi_company_group_by_company($pdo, $entry);
if (!$group) { $line('A empresa do login não está em um Grupo Empresarial ativo.'); exit(0); }
$gid = (int)$group['id'];
$line('Grupo: #' . $gid . ' ' . $group['name'] . ' · modo: ' . ($group['operation_mode'] === 'isolated' ? 'Isolado' : 'Integrado'));

$bgu = multi_company_user_group_config($pdo, $gid, $uid);
$line('Usuário configurado em Acessos multiempresa: ' . ($bgu ? ('sim (ativo: ' . $yes((int)$bgu['is_active'] === 1) . ')') : 'NÃO - abra Usuários > Acessos multiempresa, escolha o usuário e clique em Salvar acessos'));

$line();
$line('Empresas do grupo:');
$ok = 0;
foreach (multi_company_group_companies($pdo, $gid, true) as $c) {
  $cid = (int)$c['id'];
  $acc = null;
  try {
    $adminCol = multi_company_access_admin_column_ready($pdo) ? ', is_admin' : ', 0 AS is_admin';
    $q = $pdo->prepare('SELECT company_profile_id, is_default, is_active' . $adminCol . ' FROM user_company_access WHERE user_id = ? AND company_id = ? LIMIT 1');
    $q->execute([$uid, $cid]);
    $acc = $q->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {}
  $v = multi_company_context_validate_target_uncached($pdo, $uid, $entry, $cid);
  if (!empty($v['ok'])) $ok++;
  $reason = (string)($v['reason'] ?? '');
  $line(sprintf('  #%d %s (%s)%s', $cid, $c['name'], $c['slug'], $cid === $entry ? ' [empresa do login]' : ''));
  $line('     módulo Multiempresa: ' . $yes(multi_company_feature_enabled($pdo, $cid))
    . ' · acesso marcado: ' . ($acc ? $yes((int)$acc['is_active'] === 1) : 'NÃO')
    . ' · perfil: ' . ($acc && (int)($acc['company_profile_id'] ?? 0) > 0 ? '#' . (int)$acc['company_profile_id'] : '-')
    . ' · administrador: ' . ($acc ? $yes((int)($acc['is_admin'] ?? 0) === 1) : '-'));
  $line('     resultado: ' . ($reasons[$reason] ?? $reason));
}
$line();
$line($ok >= 2
  ? 'O botão de troca de empresa DEVE aparecer para este usuário (' . $ok . ' empresas liberadas). Saia e entre de novo no sistema.'
  : 'O botão NÃO aparece: é preciso ter pelo menos 2 empresas liberadas (hoje: ' . $ok . '). Corrija os itens acima.');
