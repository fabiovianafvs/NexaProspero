<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/grid_preferences.php';

// Salva as preferências de colunas dos grids novos (somente do próprio usuário,
// na empresa de login). Só POST, com token da sessão e whitelist de colunas.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Método inválido.');
  $csrf = nexa_grid_csrf();
  if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada.');
  $raw = (string)($_POST['preferences'] ?? '');
  if (strlen($raw) > 5000) throw new RuntimeException('Preferências excedem o limite.');
  $input = json_decode($raw, true);
  if (!is_array($input)) throw new RuntimeException('Preferências inválidas.');
  nexa_grid_prefs_save($pdo, (int)COMPANY_ID, (string)($_POST['grid'] ?? ''), $input);
  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
