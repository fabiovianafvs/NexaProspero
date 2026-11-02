<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/orcamento_pedido_bridge.php';

header('Content-Type: application/json; charset=utf-8');
function orc_bridge_json(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $scope = trim((string)($_GET['scope'] ?? ''));
    if (!orc_bridge_scope_allowed($pdo, $scope)) orc_bridge_json(['ok'=>false,'message'=>'Acesso não autorizado para esta operação.'],403);
    $companyId = orc_bridge_company_id($pdo, $scope);
    if (!orc_bridge_ready($pdo, $companyId)) orc_bridge_json(['ok'=>false,'message'=>'O módulo Orçamento não está habilitado ou sua estrutura ainda não está pronta nesta empresa.'],409);

    $action = trim((string)($_GET['action'] ?? 'list'));
    if ($action === 'list') {
        $rows = orc_bridge_search($pdo, $companyId, (string)($_GET['q'] ?? ''), 80);
        orc_bridge_json(['ok'=>true,'rows'=>$rows]);
    }
    if ($action === 'load') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) orc_bridge_json(['ok'=>false,'message'=>'Informe o orçamento.'],422);
        orc_bridge_json(['ok'=>true,'data'=>orc_bridge_load($pdo, $companyId, $id)]);
    }
    orc_bridge_json(['ok'=>false,'message'=>'Ação não encontrada.'],404);
} catch (Throwable $e) {
    orc_bridge_json(['ok'=>false,'message'=>$e->getMessage()],500);
}
