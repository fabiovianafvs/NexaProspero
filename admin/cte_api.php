<?php
declare(strict_types=1);

// Transporte: apoio da tela do CT-e (JSON). Pessoas, cidades, NF-e pela chave ou pelo XML e conferência dos impostos.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_transporte.php';
require_once __DIR__ . '/../config/cte_emissao.php';

if (!(access_is_company_master() || access_has_permission($pdo, (int)COMPANY_ID, 'cte_documentos'))) transp_json(['ok' => false, 'error' => 'Sem permissão.'], 403);
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$cid = (int)COMPANY_ID;
$acao = (string)($_GET['acao'] ?? '');

try {
  if ($acao === 'pessoas') transp_json(['ok' => true, 'itens' => cte_pessoas_buscar($pdo, $cid, (string)($_GET['q'] ?? ''))]);
  if ($acao === 'cidades') transp_json(['ok' => true, 'itens' => cte_cidades_buscar($pdo, (string)($_GET['q'] ?? ''), (string)($_GET['uf'] ?? ''))]);
  if ($acao === 'nfe') {
    $r = cte_nfe_buscar($pdo, $cid, (string)($_GET['chave'] ?? ''));
    transp_json(['ok' => true, 'encontrada' => (bool)$r, 'nfe' => $r]);
  }
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') transp_json(['ok' => false, 'error' => 'Ação inválida.'], 400);
  transp_csrf_check();
  if ($acao === 'nfe_xml') {
    $xml = (string)($_POST['xml'] ?? '');
    if (strlen($xml) > 5 * 1024 * 1024) throw new RuntimeException('Arquivo muito grande.');
    $r = cte_nfe_resumo_xml($xml);
    if (!$r || $r['chave'] === '') throw new RuntimeException('Este arquivo não é o XML de uma NF-e.');
    transp_json(['ok' => true, 'nfe' => $r]);
  }
  if ($acao === 'conferir') {
    $cfg = transp_config($pdo, $cid);
    $d = cte_dados_do_formulario($_POST, $cfg);
    $imp = cte_impostos($pdo, $cid, $d, $cfg, date('Y-m-d'));
    transp_json(['ok' => true, 'impostos' => $imp, 'pendencias' => cte_validar($pdo, $cid, $d, $cfg)]);
  }
  transp_json(['ok' => false, 'error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
  transp_json(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Erro ao processar.'], 400);
}
