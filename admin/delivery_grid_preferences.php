<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_delivery_control_ui.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new RuntimeException('Método inválido.');
    dc_csrf_check((string)($_POST['csrf'] ?? ''));
    $companyId=(int)COMPANY_ID;$userId=dc_user_id();
    if($companyId<=0||$userId<=0)throw new RuntimeException('Usuário ou empresa não identificados.');
    $gridId=strtolower(trim((string)($_POST['grid_id']??'')));
    $definitions=[
      'controle_entregas'=>['pedido','cliente','venda','previsao','status','futuro','em_oe','entregue','saldo','acoes'],
      'ordens_entrega'=>['oe','cliente','pedido','programacao','status','fiscal','qtd','acoes'],
      'romaneios'=>['numero','data','status','transportadora','veiculo','motorista','oe','acoes'],
      'veiculos'=>['descricao','placa','motorista','observacoes','situacao','acoes'],
      'fila_fiscal_entregas'=>['id','origem','cliente','documento','status','pedido_fiscal','erro','acoes'],
    ];
    if(!isset($definitions[$gridId]))throw new RuntimeException('Grid inválido.');
    $allowed=$definitions[$gridId];
    $raw=json_decode((string)($_POST['preferences']??''),true);if(!is_array($raw))throw new RuntimeException('Preferências inválidas.');
    $normalized=dc_grid_preferences_normalize($raw,$allowed);
    // A coluna de ações permanece sempre visível e fixa à direita.
    $normalized['visible']=array_values(array_filter($normalized['visible'],fn($c)=>$c!=='acoes'));
    if(!$normalized['visible'])$normalized['visible']=array_values(array_filter($allowed,fn($c)=>$c!=='acoes'));
    $normalized['visible'][]='acoes';
    $normalized['order']=array_values(array_filter($normalized['order'],fn($c)=>$c!=='acoes'));
    foreach($allowed as $c)if($c!=='acoes'&&!in_array($c,$normalized['order'],true))$normalized['order'][]=$c;
    $normalized['order'][]='acoes';
    $key=dc_grid_key($gridId,$userId);$json=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Não foi possível serializar as preferências.');
    $st=$pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()');
    $st->execute([$companyId,$key,$json]);
    echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
