<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/itau_webhook.php';

function itau_oauth_json(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') itau_oauth_json(405,['error'=>'method_not_allowed']);
    billing_ensure_schema($pdo);
    $grant = trim((string)($_POST['grant_type'] ?? ''));
    if ($grant === '') {
        parse_str((string)(file_get_contents('php://input') ?: ''), $parsed);
        $grant = trim((string)($parsed['grant_type'] ?? ''));
    }
    if ($grant !== 'client_credentials') itau_oauth_json(400,['error'=>'unsupported_grant_type']);
    [$clientId,$clientSecret]=itau_webhook_parse_basic_auth();
    $clientId=trim($clientId);$clientSecret=trim($clientSecret);
    if($clientId===''||$clientSecret===''){
        header('WWW-Authenticate: Basic realm="Nexa Itau Webhook"');
        itau_oauth_json(401,['error'=>'invalid_client']);
    }
    $st=$pdo->prepare("SELECT * FROM finance_bank_billing_configs WHERE provider='ITAU' AND integration_mode='API' AND enabled=1 AND itau_webhook_enabled=1 AND itau_webhook_client_id=? LIMIT 1");
    $st->execute([$clientId]);$cfg=$st->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$cfg || !hash_equals((string)($cfg['itau_webhook_client_secret']??''),$clientSecret)){
        header('WWW-Authenticate: Basic realm="Nexa Itau Webhook"');
        itau_oauth_json(401,['error'=>'invalid_client']);
    }
    itau_oauth_json(200,itau_webhook_issue_token($cfg,300));
} catch (Throwable $e) {
    error_log('Nexa Itau webhook OAuth: '.$e->getMessage());
    itau_oauth_json(500,['error'=>'server_error']);
}
