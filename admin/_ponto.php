<?php
declare(strict_types=1);

// Layout e utilidades comuns das telas do Ponto Eletrônico (admin/ponto_*.php).
// Cada tela faz: require _bootstrap.php, _chrome.php e este arquivo; depois ponto_guard().
require_once __DIR__ . '/../config/ponto_module.php';

if (!function_exists('ponto_menu_items')) {
  /** Telas do módulo: chave de permissão => [rótulo, página]. */
  function ponto_menu_items(): array {
    return [
      'ponto_painel' => ['Painel do Ponto', 'ponto_painel.php'],
      'ponto_marcacoes' => ['Marcações', 'ponto_marcacoes.php'],
      'ponto_colaboradores' => ['Colaboradores', 'ponto_colaboradores.php'],
      'ponto_departamentos' => ['Departamentos', 'ponto_departamentos.php'],
      'ponto_cargos' => ['Cargos/Funções', 'ponto_cargos.php'],
      'ponto_estabelecimentos' => ['Estabelecimentos', 'ponto_estabelecimentos.php'],
      'ponto_jornadas' => ['Jornadas', 'ponto_jornadas.php'],
      'ponto_escalas' => ['Escalas', 'ponto_escalas.php'],
      'ponto_locais' => ['Locais de Trabalho', 'ponto_locais.php'],
      'ponto_feriados' => ['Feriados', 'ponto_feriados.php'],
      'ponto_coletores' => ['Aparelhos', 'ponto_coletores.php'],
      'ponto_auditoria' => ['Auditoria', 'ponto_auditoria.php'],
      'ponto_configuracoes' => ['Configurações', 'ponto_configuracoes.php'],
    ];
  }
}

if (!function_exists('ponto_can')) {
  function ponto_can(PDO $pdo, string $perm): bool {
    return access_is_company_master() || access_has_permission($pdo, (int)COMPANY_ID, $perm);
  }
}

if (!function_exists('ponto_guard')) {
  /** Permissão da tela + tabelas criadas. Devolve o company_id da sessão (nunca do formulário). */
  function ponto_guard(PDO $pdo, string $perm): int {
    if (!ponto_can($pdo, $perm)) {
      access_render_denied('Seu usuário não possui permissão para esta tela do Ponto Eletrônico.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['ponto_csrf'])) $_SESSION['ponto_csrf'] = bin2hex(random_bytes(24));
    return (int)COMPANY_ID;
  }
}

if (!function_exists('ponto_csrf')) {
  function ponto_csrf(): string { return (string)($_SESSION['ponto_csrf'] ?? ''); }
  function ponto_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . ponto_h(ponto_csrf()) . '">'; }
  function ponto_csrf_check(): void {
    $posted = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($posted === '' || !hash_equals(ponto_csrf(), $posted)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
  }
}

if (!function_exists('ponto_flash')) {
  function ponto_flash(string $type, string $msg): void { $_SESSION['ponto_flash'] = [$type, $msg]; }
  function ponto_flash_take(): ?array { $f = $_SESSION['ponto_flash'] ?? null; unset($_SESSION['ponto_flash']); return is_array($f) ? $f : null; }
  function ponto_redirect(string $url): void { header('Location: ' . $url); exit; }
}

if (!function_exists('ponto_json')) {
  function ponto_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (!function_exists('ponto_start')) {
  /** Cabeçalho da tela: menu do sistema, título, abas do módulo e aviso de migração pendente. */
  function ponto_start(PDO $pdo, string $perm, string $title, string $subtitle = '', string $actionsHtml = ''): void {
    admin_topbar($title, $perm);
    $items = ponto_menu_items();
    ?>
<style>
.pt{--pt-line:#dbe4f0;--pt-mut:#64748b;--pt-ink:#0b1f5b;--pt-blue:#2454d6;max-width:1240px;margin:10px auto 0;color:#0f172a}
.pt *{box-sizing:border-box}
.pt-hero{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;justify-content:space-between;margin:4px 0 10px}
.pt-hero h1{margin:0;font-size:clamp(22px,2.6vw,30px);color:var(--pt-ink);letter-spacing:-.02em}.pt-hero p{margin:4px 0 0;color:var(--pt-mut);font-size:13.5px;max-width:760px;line-height:1.45}
.pt-actions{display:flex;gap:8px;flex-wrap:wrap}
.pt-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.pt-tabs a{padding:7px 11px;border:1px solid var(--pt-line);border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;font-weight:800;font-size:12.5px;white-space:nowrap}
.pt-tabs a.on{background:linear-gradient(135deg,#071b55,#2454d6);color:#fff;border-color:transparent}
.pt-card{background:#fff;border:1px solid var(--pt-line);border-radius:20px;padding:16px;box-shadow:0 14px 38px rgba(11,31,91,.06);min-width:0}
.pt-card h2{margin:0 0 4px;font-size:17px;color:var(--pt-ink)}.pt-sub{margin:0 0 10px;color:var(--pt-mut);font-size:12.5px;line-height:1.45}
.pt-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px;padding:0 15px;border-radius:12px;border:1px solid var(--pt-line);background:#fff;color:var(--pt-ink);font-weight:850;font-size:13.5px;cursor:pointer;text-decoration:none;white-space:nowrap}
.pt-btn:hover{background:#f1f5ff}.pt-btn.pri{background:var(--pt-blue);border-color:var(--pt-blue);color:#fff}.pt-btn.pri:hover{background:#1d46b8}
.pt-btn.ok{background:#16a34a;border-color:#16a34a;color:#fff}.pt-btn.red{color:#b91c1c;border-color:#fecaca}.pt-btn.red:hover{background:#fef2f2}.pt-btn.sm{min-height:32px;padding:0 10px;font-size:12.5px;border-radius:10px}
.pt-btn:disabled{opacity:.55;cursor:default}
.pt-search{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:12px}
.pt-search .grow{flex:1 1 260px}
.pt label.f{display:grid;gap:4px;font-size:12px;font-weight:800;color:#334155;margin:0!important;min-height:0!important}
.pt input[type=text],.pt input[type=date],.pt input[type=number],.pt input[type=email],.pt input[type=tel],.pt input[type=time],.pt select,.pt textarea{width:100%;min-height:40px;border:1px solid #cbd5e1;border-radius:11px;padding:0 11px;font:inherit;font-size:14px;background:#fff;color:#0f172a}
.pt textarea{padding:9px 11px;min-height:70px}
.pt input[type=range]{min-height:0;border:0;padding:0;background:none;outline:none;box-shadow:none}
.pt input:focus,.pt select:focus,.pt textarea:focus{outline:2px solid rgba(36,84,214,.25);border-color:var(--pt-blue)}
.pt label.ck{display:flex!important;gap:8px;align-items:center;font-size:13.5px;font-weight:700;color:#0f172a;margin:0!important;min-height:0!important}
.pt label.ck input{width:18px;height:18px}
.pt-table,.pt table{width:100%;min-width:0!important;border-collapse:separate;border-spacing:0}
.pt-table th{background:#f8fbff;color:#334155;font-size:12px;text-align:left;padding:10px;border-bottom:1px solid var(--pt-line);text-transform:uppercase;letter-spacing:.02em}
.pt-table td{padding:10px;border-bottom:1px solid #edf2f8;font-size:13.5px;vertical-align:middle;overflow-wrap:anywhere;white-space:normal}
.pt-table tr.off td{color:#94a3b8}
.pt-table .act{text-align:right;white-space:nowrap}
.pt-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:900;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;white-space:nowrap}
.pt-badge.g{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}.pt-badge.r{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.pt-badge.y{border-color:#fde68a;background:#fffbeb;color:#a16207}.pt-badge.n{border-color:#e2e8f0;background:#f8fafc;color:#475569}
.pt-empty{padding:22px;text-align:center;color:var(--pt-mut);border:1px dashed #cbd5e1;border-radius:14px}
.pt-msg{padding:11px 14px;border-radius:14px;font-weight:700;margin-bottom:12px}.pt-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.pt-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.pt-msg.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.pt-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}.pt-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.pt-grid .s2{grid-column:span 2}.pt-grid .s3{grid-column:1/-1}
dialog.pt-modal{border:0;border-radius:22px;padding:0;width:min(760px,96vw);max-height:92vh;box-shadow:0 30px 80px rgba(2,6,23,.35);color:#0f172a}
dialog.pt-modal.wide{width:min(980px,96vw)}
dialog.pt-modal::backdrop{background:rgba(15,23,42,.55)}
.pt-mh{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid #e2e8f0;position:sticky;top:0;background:#fff;z-index:2}
.pt-mh h3{margin:0;font-size:18px;color:#0b1f5b}.pt-x{border:0;background:#f1f5f9;border-radius:10px;width:36px;height:36px;font-size:20px;cursor:pointer}
.pt-mb{padding:16px 18px;overflow:auto;max-height:calc(92vh - 140px)}
.pt-mf{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;padding:12px 18px;border-top:1px solid #e2e8f0;background:#f8fafc;position:sticky;bottom:0}
.pt-mtabs{display:flex;gap:4px;flex-wrap:wrap;margin:-4px 0 12px;border-bottom:1px solid #e2e8f0}
.pt-mtabs button{border:0;background:none;padding:9px 12px;font-weight:850;color:#64748b;cursor:pointer;border-bottom:3px solid transparent;font-size:13.5px}
.pt-mtabs button.on{color:#2454d6;border-bottom-color:#2454d6}
.pt-pane{display:none}.pt-pane.on{display:block}
.pt-hint{color:var(--pt-mut);font-size:12px;line-height:1.4}
.pt-kbd{display:inline-block;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:6px;padding:0 5px;font-size:11px;font-weight:800;color:#334155;background:#fff}
@media(max-width:760px){
  .pt-grid,.pt-grid.three{grid-template-columns:1fr}.pt-grid .s2{grid-column:auto}
  .pt-table.cards thead{display:none}
  .pt-table.cards,.pt-table.cards tbody,.pt-table.cards tr,.pt-table.cards td{display:block;width:auto}
  .pt-table.cards tr{border-bottom:1px solid #e2e8f0;padding:8px 0}
  .pt-table.cards td{border:0;padding:3px 6px}
  .pt-table.cards td[data-l]::before{content:attr(data-l) ': ';color:#64748b;font-weight:700;font-size:12px}
  .pt-table .act{text-align:left}
  .pt-search .pt-btn{flex:1 1 auto}
}
</style>
<div class="pt">
  <div class="pt-hero">
    <div><h1><?= ponto_h($title) ?></h1><?php if ($subtitle !== ''): ?><p><?= ponto_h($subtitle) ?></p><?php endif; ?></div>
    <?php if ($actionsHtml !== ''): ?><div class="pt-actions"><?= $actionsHtml ?></div><?php endif; ?>
  </div>
  <nav class="pt-tabs">
    <?php foreach ($items as $key => [$label, $page]): if (!ponto_can($pdo, $key)) continue; ?>
      <a class="<?= $key === $perm ? 'on' : '' ?>" href="<?= ponto_h($page) ?>"><?= ponto_h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php
    if (!ponto_ready($pdo)) {
      echo '<div class="pt-msg warn">O Ponto Eletrônico ainda não foi instalado no banco. O administrador da plataforma precisa aplicar a atualização "Ponto Eletrônico - V1 Fundação" no Atualizador do Banco.</div></div>';
      admin_footer();
      exit;
    }
    $f = ponto_flash_take();
    if ($f) echo '<div class="pt-msg ' . ($f[0] === 'ok' ? 'ok' : 'err') . '">' . ponto_h($f[1]) . '</div>';
  }
}

if (!function_exists('ponto_require_v2')) {
  /** Telas da marcação (V2): avisa e encerra a página se a migração V2 não foi aplicada. */
  function ponto_require_v2(PDO $pdo): void {
    require_once __DIR__ . '/../config/ponto_marcacao.php';
    if (ponto_v2_ready($pdo)) return;
    echo '<div class="pt-msg warn">A marcação de ponto ainda não foi instalada no banco. O administrador da plataforma precisa aplicar a atualização "Ponto Eletrônico - V2 Marcação de ponto" no Atualizador do Banco.</div>';
    ponto_end();
    exit;
  }
}

if (!function_exists('ponto_end')) {
  /** Fecha a tela: JS dos modais (Esc fecha, F8 vai para a busca) e rodapé. */
  function ponto_end(): void {
    ?>
</div>
<script>
(function(){
  window.ptOpen=function(id){const d=document.getElementById(id);if(!d)return;if(!d.open)d.showModal();const f=d.querySelector('[autofocus],input:not([type=hidden]):not([disabled]),select');if(f)setTimeout(()=>f.focus(),30);};
  window.ptClose=function(el){const d=el.closest?el.closest('dialog'):document.getElementById(el);if(d)d.close();};
  // A janela não fecha ao clicar no fundo: arrastar o mouse de um campo até fora dela
  // contava como clique no fundo e apagava o que foi digitado. Fecha pelo X, Cancelar ou Esc.
  // Abas dentro dos modais.
  document.addEventListener('click',e=>{const b=e.target.closest('.pt-mtabs button[data-pane]');if(!b)return;const box=b.closest('dialog,.pt-card');box.querySelectorAll('.pt-mtabs button').forEach(x=>x.classList.toggle('on',x===b));box.querySelectorAll('.pt-pane').forEach(p=>p.classList.toggle('on',p.id===b.dataset.pane));});
  // F8: vai para a pesquisa da tela (padrão do Nexa).
  document.addEventListener('keydown',e=>{if(e.key!=='F8')return;const open=document.querySelector('dialog[open]');if(open)return;const s=document.querySelector('[data-pt-search]');if(s){e.preventDefault();s.focus();s.select();}});
  // Botões que gravam: evita clique duplo.
  document.addEventListener('submit',e=>{const f=e.target;if(f.dataset.confirm&&!confirm(f.dataset.confirm)){e.preventDefault();return;}const b=f.querySelector('button[type=submit]:not([data-keep])');if(b)setTimeout(()=>{b.disabled=true;},0);});
})();
</script>
<?php
    admin_footer();
  }
}

if (!function_exists('ponto_post_handle')) {
  /**
   * Executa uma ação POST com CSRF; erro volta como aviso na mesma tela (PRG).
   * $fn devolve a mensagem de sucesso, ou [mensagem, URL de retorno].
   */
  function ponto_post_handle(string $back, callable $fn): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    try {
      ponto_csrf_check();
      $res = $fn();
      if (is_array($res)) { $back = (string)($res[1] ?? $back); $res = $res[0] ?? ''; }
      $msg = (string)$res;
      if ($msg !== '') ponto_flash('ok', $msg);
    } catch (Throwable $e) {
      ponto_flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível gravar: ' . $e->getMessage());
    }
    ponto_redirect($back);
  }
}
