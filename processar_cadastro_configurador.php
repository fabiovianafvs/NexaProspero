<?php
declare(strict_types=1);

/**
 * Processamento direto do cadastro vindo do configurador público.
 *
 * Esta rota recebe o formulário completo, cria a empresa e encaminha para a
 * tela final com usuário, senha e links. Ela não exibe novamente o formulário
 * genérico de cadastro do aplicativo.
 */
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  header('Location: https://nexaprospero.com.br/configurador.php', true, 302);
  exit;
}

define('NEXA_DIRECT_SIGNUP', true);
require __DIR__ . '/cadastro.php';
