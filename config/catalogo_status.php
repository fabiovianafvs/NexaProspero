<?php
declare(strict_types=1);

/**
 * Nexa Próspero — Ativar/Desativar os catálogos públicos da empresa.
 *
 * - "pedidos"  = Catálogo de Pedidos (index.php, produto.php, carrinho.php, checkout.php).
 * - "produtos" = Catálogo de Produtos, só visualização (catalogo_publico.php).
 *
 * A situação fica em app_config, chave "catalogo_status" (por empresa).
 * Sem registro = os dois catálogos ativos (comportamento de sempre).
 * Catálogo desativado mostra a tela "Catálogo em manutenção" no lugar dos produtos.
 */

require_once __DIR__ . '/config_store.php';

if (!function_exists('catalogo_status_tipos')) {
  function catalogo_status_tipos(): array {
    return [
      'pedidos' => 'Catálogo de Pedidos',
      'produtos' => 'Catálogo de Produtos',
    ];
  }
}

if (!function_exists('catalogo_status_get')) {
  /** @return array{pedidos:bool,produtos:bool,raw:array} true = ativo */
  function catalogo_status_get(PDO $pdo, int $companyId): array {
    $raw = [];
    try {
      $raw = config_store_get($pdo, $companyId, 'catalogo_status') ?? [];
    } catch (Throwable $e) {
      // Qualquer falha de leitura mantém o catálogo no ar (nunca derruba o catálogo por erro).
      error_log('[Nexa][Catálogo status] ' . $e->getMessage());
      $raw = [];
    }
    return [
      'pedidos' => empty($raw['pedidos_off']),
      'produtos' => empty($raw['produtos_off']),
      'raw' => $raw,
    ];
  }
}

if (!function_exists('catalogo_status_ativo')) {
  function catalogo_status_ativo(PDO $pdo, int $companyId, string $tipo): bool {
    $st = catalogo_status_get($pdo, $companyId);
    return (bool)($st[$tipo] ?? true);
  }
}

if (!function_exists('catalogo_status_set')) {
  function catalogo_status_set(PDO $pdo, int $companyId, string $tipo, bool $ativo, int $userId = 0): void {
    if (!isset(catalogo_status_tipos()[$tipo])) throw new InvalidArgumentException('Catálogo inválido.');
    $raw = config_store_get($pdo, $companyId, 'catalogo_status') ?? [];
    $raw[$tipo . '_off'] = !$ativo;
    $raw[$tipo . '_alterado_em'] = date('Y-m-d H:i:s');
    $raw[$tipo . '_alterado_por'] = $userId;
    config_store_set($pdo, $companyId, 'catalogo_status', $raw);
  }
}

if (!function_exists('catalogo_manutencao_render')) {
  /**
   * Página "Catálogo em manutenção" (completa, independente do layout do catálogo).
   * Encerra a requisição.
   */
  function catalogo_manutencao_render(array $company, array $biz): void {
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $brand = (array)($biz['brand'] ?? []);
    $color = static function ($v, string $default): string {
      $v = trim((string)$v);
      return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : $default;
    };
    $primary = $color($brand['primary_color'] ?? '', '#0f766e');
    $onPrimary = $color($brand['text_on_primary'] ?? '', '#ffffff');
    $name = trim((string)($brand['catalog_title'] ?? ''));
    if ($name === '') $name = (string)($company['name'] ?? 'Catálogo');
    $logoFile = trim((string)($biz['logo']['file'] ?? ''));
    $logoUrl = ($logoFile !== '' && !empty($biz['logo']['enabled'])) ? ('/uploads/logos/' . rawurlencode(basename($logoFile))) : '';

    if (!headers_sent()) {
      http_response_code(200);
      header('Content-Type: text/html; charset=UTF-8');
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
      header('Pragma: no-cache');
      header('X-Robots-Tag: noindex');
    }
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($name) ?> — Catálogo em manutenção</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px;font:16px/1.5 Inter,"Segoe UI",Arial,sans-serif;color:#1e293b;background:#f1f5f9}
  .cm-card{width:100%;max-width:460px;background:#fff;border-radius:20px;box-shadow:0 12px 40px rgba(15,23,42,.12);overflow:hidden;text-align:center}
  .cm-top{background:<?= $h($primary) ?>;color:<?= $h($onPrimary) ?>;padding:26px 22px 22px}
  .cm-top img{max-width:150px;max-height:80px;object-fit:contain;background:#fff;border-radius:12px;padding:6px;margin-bottom:10px}
  .cm-top h1{margin:0;font-size:20px;font-weight:800}
  .cm-body{padding:28px 24px 30px}
  .cm-ico{font-size:44px;line-height:1;margin-bottom:10px}
  .cm-body h2{margin:0 0 8px;font-size:22px;color:#0f172a}
  .cm-body p{margin:0;color:#475569}
</style>
</head>
<body>
  <main class="cm-card" role="main">
    <div class="cm-top">
      <?php if ($logoUrl !== ''): ?><img src="<?= $h($logoUrl) ?>" alt=""><?php endif; ?>
      <h1><?= $h($name) ?></h1>
    </div>
    <div class="cm-body">
      <div class="cm-ico" aria-hidden="true">🛠️</div>
      <h2>Catálogo em manutenção</h2>
      <p>Estamos atualizando nosso catálogo. Por favor, volte em breve.</p>
    </div>
  </main>
</body>
</html><?php
    exit;
  }
}

if (!function_exists('catalogo_status_exigir')) {
  /** Usado no início das telas públicas: catálogo desativado = tela de manutenção. */
  function catalogo_status_exigir(PDO $pdo, array $company, array $biz, string $tipo): void {
    $cid = (int)($company['id'] ?? 0);
    if ($cid <= 0) return;
    if (catalogo_status_ativo($pdo, $cid, $tipo)) return;
    catalogo_manutencao_render($company, $biz);
  }
}
