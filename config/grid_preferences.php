<?php
declare(strict_types=1);

/**
 * Preferências dos grids novos (colunas visíveis, ordem e larguras) por usuário.
 * Guardadas em app_config da empresa de login, chave "ui_grid_<grid>_user_<id>".
 * Sem DDL: app_config já existe.
 */

if (!function_exists('nexa_grid_registry')) {
  /** Grids que aceitam preferências e suas colunas válidas (whitelist). */
  function nexa_grid_registry(): array {
    return [
      'fin_receber' => ['id','cliente','doc','historico','atraso','emissao','vencimento','baixa','situacao','original','baixado','aberto','plano'],
      'fin_pagar' => ['id','fornecedor','doc','historico','emissao','vencimento','baixa','situacao','original','baixado','aberto','plano'],
      'fin_movimentos' => ['data','tipo','pessoa','plano','historico','doc','centro','conta','origem','valor'],
    ];
  }
}

if (!function_exists('nexa_grid_allowed_columns')) {
  /** Colunas válidas: whitelist fixa ou, nos grids automáticos (auto_*), nomes simples. */
  function nexa_grid_allowed_columns(string $grid, array $input = []): ?array {
    $registry = nexa_grid_registry();
    if (isset($registry[$grid])) return $registry[$grid];
    if (!preg_match('/^auto_[a-z0-9_]{1,40}$/', $grid)) return null;
    $cols = [];
    foreach (['visible', 'order'] as $part) foreach ((array)($input[$part] ?? []) as $c) if (is_string($c)) $cols[] = $c;
    foreach (array_keys((array)($input['widths'] ?? [])) as $c) $cols[] = (string)$c;
    $cols = array_values(array_unique(array_filter($cols, static fn($c) => (bool)preg_match('/^[a-z0-9_]{1,40}$/', $c))));
    return array_slice($cols, 0, 60);
  }
}

if (!function_exists('nexa_grid_user_id')) {
  function nexa_grid_user_id(): int {
    return function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
  }
}

if (!function_exists('nexa_grid_csrf')) {
  function nexa_grid_csrf(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['nexa_grid_prefs_csrf']) || !is_string($_SESSION['nexa_grid_prefs_csrf'])) {
      $_SESSION['nexa_grid_prefs_csrf'] = bin2hex(random_bytes(20));
    }
    return (string)$_SESSION['nexa_grid_prefs_csrf'];
  }
}

if (!function_exists('nexa_grid_prefs_sanitize')) {
  function nexa_grid_prefs_sanitize(array $input, array $allowed): array {
    $prefs = ['visible' => [], 'order' => [], 'widths' => []];
    foreach (['visible', 'order'] as $part) {
      foreach ((array)($input[$part] ?? []) as $col) {
        if (is_string($col) && in_array($col, $allowed, true) && !in_array($col, $prefs[$part], true)) $prefs[$part][] = $col;
      }
    }
    foreach ((array)($input['widths'] ?? []) as $col => $width) {
      if (in_array((string)$col, $allowed, true) && is_numeric($width)) $prefs['widths'][(string)$col] = max(40, min(650, (int)$width));
    }
    return $prefs;
  }
}

if (!function_exists('nexa_grid_prefs_load')) {
  function nexa_grid_prefs_load(PDO $pdo, int $companyId, string $grid): array {
    $userId = nexa_grid_user_id();
    if ($companyId <= 0 || $userId <= 0 || nexa_grid_allowed_columns($grid) === null) return [];
    try {
      $st = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');
      $st->execute([$companyId, substr('ui_grid_' . $grid . '_user_' . $userId, 0, 64)]);
      $data = json_decode((string)($st->fetchColumn() ?: ''), true);
      return is_array($data) ? nexa_grid_prefs_sanitize($data, (array)nexa_grid_allowed_columns($grid, $data)) : [];
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('nexa_grid_prefs_save')) {
  function nexa_grid_prefs_save(PDO $pdo, int $companyId, string $grid, array $input): array {
    $userId = nexa_grid_user_id();
    $allowed = nexa_grid_allowed_columns($grid, $input);
    if ($companyId <= 0 || $userId <= 0 || $allowed === null) throw new RuntimeException('Grid inválido.');
    $prefs = nexa_grid_prefs_sanitize($input, $allowed);
    $st = $pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()');
    $st->execute([$companyId, substr('ui_grid_' . $grid . '_user_' . $userId, 0, 64), json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return $prefs;
  }
}

if (!function_exists('nexa_grid_icon')) {
  /** Ícone SVG (traço) usado nos botões de ação dos grids novos. */
  function nexa_grid_icon(string $name): string {
    $paths = [
      'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4Z"/>',
      'view' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
      'cancel' => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
      'boleto' => '<path d="M3 5v14M6 5v14M9 5v14M12 5v14M15 5v14M18 5v14M21 5v14" stroke-width="1.4"/><path d="M7 5v14M16 5v14" stroke-width="2.4"/>',
      'whatsapp' => '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.1-5.4A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8.5 8.8c.6 3.2 2.6 5.2 5.8 6l1.6-1.4"/>',
      'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/>',
      'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 15h6"/>',
      'ban' => '<circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/>',
      'products' => '<path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['view']) . '</svg>';
  }
}

if (!function_exists('nexa_grid_assets')) {
  /** CSS + JS do grid (uma vez por página). Coloque antes da tabela. */
  function nexa_grid_assets(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<link rel="stylesheet" href="assets/nexa_titles_grid.css?v=20261108_4">'
      . '<style id="ntgFirstPaint">.ntg-wrap:not(.is-ready){visibility:hidden}</style>'
      . '<script>setTimeout(function(){var s=document.getElementById("ntgFirstPaint");if(s&&!document.documentElement.classList.contains("ntg-ready"))s.remove();document.querySelectorAll("table[data-ntg-auto]").forEach(function(t){t.style.visibility="visible";});},4000);</script>'
      . '<script src="assets/nexa_titles_grid.js?v=20261108_4" defer></script>';
  }
}

if (!function_exists('nexa_grid_auto_attrs')) {
  /** Atributos para transformar uma tabela comum no grid (colunas, ordenação, largura, ícones). */
  function nexa_grid_auto_attrs(PDO $pdo, string $grid): string {
    if (!preg_match('/^auto_[a-z0-9_]{1,40}$/', $grid)) return '';
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $prefs = nexa_grid_prefs_load($pdo, (int)(defined('COMPANY_ID') ? COMPANY_ID : 0), $grid);
    return ' data-ntg-auto="' . $h($grid) . '" data-scope="' . (int)(defined('COMPANY_ID') ? COMPANY_ID : 0) . '" data-save-url="grid_preferencias.php" data-csrf="' . $h(nexa_grid_csrf()) . '" data-prefs="' . $h(json_encode($prefs ?: new stdClass(), JSON_UNESCAPED_UNICODE)) . '"';
  }
}
