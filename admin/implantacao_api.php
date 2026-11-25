<?php
declare(strict_types=1);

// Trilha de Implantação: ações rápidas das telas (JSON). Grava só por POST com CSRF.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/implantacao_module.php';

$out = static function (array $d, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($d, JSON_UNESCAPED_UNICODE);
  exit;
};
$can = static fn(string $p): bool => access_is_company_master() || access_has_permission($pdo, (int)COMPANY_ID, $p);
if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID) {
  $out(['ok' => false, 'error' => 'Sem o contexto F11 nesta tela.'], 403);
}
if (!impl_ready($pdo)) $out(['ok' => false, 'error' => 'Aplique "CRM - Trilha de Implantação v1" no Atualizador do Banco.'], 409);
$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

try {
  if ($action === 'pessoas') {
    if (!$can('crm_implantacao')) $out(['ok' => false, 'error' => 'Sem permissão.'], 403);
    $out(['ok' => true, 'items' => impl_pessoas_busca($pdo, $cid, (string)($_GET['q'] ?? ''))]);
  }

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $out(['ok' => false, 'error' => 'Use POST.'], 405);
  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $csrf = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
  if ($csrf === '' || !hash_equals((string)($_SESSION['impl_csrf'] ?? ''), $csrf)) $out(['ok' => false, 'error' => 'Sessão expirada. Atualize a página.'], 403);

  $implId = (int)($_POST['impl'] ?? 0);
  switch ($action) {
    case 'toggle':
      if (!$can('crm_implantacao')) $out(['ok' => false, 'error' => 'Sem permissão.'], 403);
      $c = impl_tarefa_marcar($pdo, $cid, $implId, (int)($_POST['it'] ?? 0), !empty($_POST['feito']), $uid);
      $impl = impl_implantacao($pdo, $cid, $implId);
      $out(['ok' => true, 'total' => $c['total'], 'feitas' => $c['feitas'], 'status' => ['nome' => $impl['status_nome'] ?? '', 'cor' => $impl['status_cor'] ?? '', 'id' => (int)($impl['status_id'] ?? 0)]]);

    case 'status':
      if (!$can('crm_implantacao')) $out(['ok' => false, 'error' => 'Sem permissão.'], 403);
      if (!impl_implantacao($pdo, $cid, $implId)) throw new RuntimeException('Implantação não encontrada.');
      impl_set_status($pdo, $cid, $implId, (int)($_POST['status_id'] ?? 0));
      $out(['ok' => true]);

    case 'concluir':
      if (!$can('crm_implantacao')) $out(['ok' => false, 'error' => 'Sem permissão.'], 403);
      if (!impl_implantacao($pdo, $cid, $implId)) throw new RuntimeException('Implantação não encontrada.');
      impl_concluir($pdo, $cid, $implId);
      $out(['ok' => true]);

    case 'add_tarefa':
      if (!$can('crm_implantacao')) $out(['ok' => false, 'error' => 'Sem permissão.'], 403);
      impl_tarefa_adicionar($pdo, $cid, $implId, (int)($_POST['tarefa_id'] ?? 0));
      $out(['ok' => true]);

    case 'rm_tarefa':
      if (!$can('crm_implantacao')) $out(['ok' => false, 'error' => 'Sem permissão.'], 403);
      impl_tarefa_remover($pdo, $cid, $implId, (int)($_POST['it'] ?? 0));
      $out(['ok' => true]);

    case 'assinar':
      if (!$can('crm_implantacao_relatorios')) $out(['ok' => false, 'error' => 'Sem permissão.'], 403);
      impl_relatorio_assinar($pdo, $cid, (int)($_POST['rel'] ?? 0), (string)($_POST['assinatura'] ?? ''), (string)($_POST['nome'] ?? ''), 'LOCAL', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
      $out(['ok' => true]);

    case 'link':
      if (!$can('crm_implantacao_relatorios')) $out(['ok' => false, 'error' => 'Sem permissão.'], 403);
      $url = impl_relatorio_link($pdo, $cid, (int)($_POST['rel'] ?? 0));
      $out(['ok' => true, 'url' => $url]);
  }
  $out(['ok' => false, 'error' => 'Ação inválida.'], 400);
} catch (RuntimeException $e) {
  $out(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
  error_log('[implantacao_api] ' . $e->getMessage());
  $out(['ok' => false, 'error' => 'Não foi possível concluir. Tente de novo.'], 500);
}
