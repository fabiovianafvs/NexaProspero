<?php
declare(strict_types=1);

// Este arquivo pode acabar sendo incluído mais de uma vez (paths diferentes / includes indiretos).
// Por isso, mantenha-o idempotente (sem fatal de redeclare) e garanta $pdo disponível.

if (!function_exists('pdo_connect')) {
function pdo_connect(array $db): PDO {
  $dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['name'],
    $db['charset']
  );

$opt = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

  return new PDO($dsn, $db['user'], $db['pass'], $opt);
}
}

// Carrega config do banco e cria o $pdo automaticamente
$db = require __DIR__ . '/db.php';

// Só cria a conexão se ainda não existir
if (!isset($pdo) || !($pdo instanceof PDO)) {
  $pdo = pdo_connect($db);
}

// Opcional: também retorna o objeto, caso alguma página use "require ...; $pdo = ..."
return $pdo;