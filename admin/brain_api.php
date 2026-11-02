<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/brain.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'message'=>'Método não permitido']); exit; }
$input = json_decode((string)file_get_contents('php://input'), true);
$question = trim((string)($input['question'] ?? ''));
if (mb_strlen($question,'UTF-8') > 240) $question = mb_substr($question,0,240,'UTF-8');
$result = brain_answer($pdo, (int)COMPANY_ID, $question);
echo json_encode(['ok'=>true]+$result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
