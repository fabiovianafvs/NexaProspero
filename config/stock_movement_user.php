<?php
declare(strict_types=1);

/*
 * Nexa Próspero - usuário nos movimentos de estoque (arquivo leve, sem dependências).
 * Chamado pelo admin/_bootstrap.php ao fim de cada POST do painel.
 */

if (!function_exists('stock_movements_stamp_user')) {
  /**
   * Fim de um POST do painel: grava o usuário logado nos movimentos de estoque que
   * esta conexão criou (coluna conexao_id = CONNECTION_ID(), preenchida pelo próprio
   * MySQL). Não mexe em nenhuma rotina que grava estoque; sem a migração, não faz nada.
   */
  function stock_movements_stamp_user(PDO $pdo, int $userId, float $requestStart): void {
    if ($userId <= 0) return;
    try {
      if ($pdo->inTransaction()) return;
      $seconds = max(5, (int)ceil(microtime(true) - $requestStart) + 5);
      $pdo->prepare('UPDATE estoque_movimentos SET usuario_id = ? WHERE conexao_id = CONNECTION_ID() AND usuario_id IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)')
        ->execute([$userId, $seconds]);
    } catch (Throwable $e) {}
  }
}
