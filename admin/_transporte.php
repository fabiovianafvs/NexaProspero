<?php
declare(strict_types=1);

// Layout e utilidades comuns das telas do Transporte - CT-e/MDF-e (admin/transporte_*.php).
// Cada tela faz: require _bootstrap.php, _chrome.php e este arquivo; depois transp_guard().
// Mesmo visual das telas do Ponto (prefixo de CSS tr-).
require_once __DIR__ . '/../config/transporte_module.php';

if (!function_exists('transp_menu_items')) {
  /** Telas do módulo: chave de permissão => [rótulo, página]. */
  function transp_menu_items(): array {
    return [
      'transp_veiculos' => ['Veículos', 'transporte_veiculos.php'],
      'transp_motoristas' => ['Motoristas', 'transporte_motoristas.php'],
      'transp_configuracoes' => ['Configurações', 'transporte_configuracoes.php'],
    ];
  }
}

if (!function_exists('transp_can')) {
  function transp_can(PDO $pdo, string $perm): bool {
    return access_is_company_master() || access_has_permission($pdo, (int)COMPANY_ID, $perm);
  }
}

if (!function_exists('transp_guard')) {
  /** Permissão da tela + tabelas criadas. Devolve o company_id da sessão (nunca do formulário). */
  function transp_guard(PDO $pdo, string $perm): int {
    if (!transp_can($pdo, $perm)) {
      access_render_denied('Seu usuário não possui permissão para esta tela do Transporte (CT-e/MDF-e).');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['transp_csrf'])) $_SESSION['transp_csrf'] = bin2hex(random_bytes(24));
    return (int)COMPANY_ID;
  }
}

if (!function_exists('transp_csrf')) {
  function transp_csrf(): string { return (string)($_SESSION['transp_csrf'] ?? ''); }
  function transp_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . transp_h(transp_csrf()) . '">'; }
  function transp_csrf_check(): void {
    $posted = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($posted === '' || !hash_equals(transp_csrf(), $posted)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
  }
}

if (!function_exists('transp_flash')) {
  function transp_flash(string $type, string $msg): void { $_SESSION['transp_flash'] = [$type, $msg]; }
  function transp_flash_take(): ?array { $f = $_SESSION['transp_flash'] ?? null; unset($_SESSION['transp_flash']); return is_array($f) ? $f : null; }
  function transp_redirect(string $url): void { header('Location: ' . $url); exit; }
}

if (!function_exists('transp_json')) {
  function transp_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (!function_exists('transp_start')) {
  /** Cabeçalho da tela: menu do sistema, título, abas do módulo e aviso de migração pendente. */
  function transp_start(PDO $pdo, string $perm, string $title, string $subtitle = '', string $actionsHtml = ''): void {
    admin_topbar($title, $perm);
    $items = transp_menu_items();
    ?>
<style>
.tr{--tr-line:#dbe4f0;--tr-mut:#64748b;--tr-ink:#0b1f5b;--tr-blue:#2454d6;max-width:1240px;margin:10px auto 0;color:#0f172a}
.tr *{box-sizing:border-box}
.tr [hidden]{display:none!important}
.tr-hero{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;justify-content:space-between;margin:4px 0 10px}
.tr-hero h1{margin:0;font-size:clamp(22px,2.6vw,30px);color:var(--tr-ink);letter-spacing:-.02em}.tr-hero p{margin:4px 0 0;color:var(--tr-mut);font-size:13.5px;max-width:760px;line-height:1.45}
.tr-actions{display:flex;gap:8px;flex-wrap:wrap}
.tr-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.tr-tabs a{padding:7px 11px;border:1px solid var(--tr-line);border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;font-weight:800;font-size:12.5px;white-space:nowrap}
.tr-tabs a.on{background:linear-gradient(135deg,#071b55,#2454d6);color:#fff;border-color:transparent}
.tr-card{background:#fff;border:1px solid var(--tr-line);border-radius:20px;padding:16px;box-shadow:0 14px 38px rgba(11,31,91,.06);min-width:0}
.tr-card h2{margin:0 0 4px;font-size:17px;color:var(--tr-ink)}.tr-sub{margin:0 0 10px;color:var(--tr-mut);font-size:12.5px;line-height:1.45}
.tr-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px;padding:0 15px;border-radius:12px;border:1px solid var(--tr-line);background:#fff;color:var(--tr-ink);font-weight:850;font-size:13.5px;cursor:pointer;text-decoration:none;white-space:nowrap}
.tr-btn:hover{background:#f1f5ff}.tr-btn.pri{background:var(--tr-blue);border-color:var(--tr-blue);color:#fff}.tr-btn.pri:hover{background:#1d46b8}
.tr-btn.ok{background:#16a34a;border-color:#16a34a;color:#fff}.tr-btn.red{color:#b91c1c;border-color:#fecaca}.tr-btn.red:hover{background:#fef2f2}.tr-btn.sm{min-height:32px;padding:0 10px;font-size:12.5px;border-radius:10px}
.tr-btn:disabled{opacity:.55;cursor:default}
.tr-search{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:12px}
.tr-search .grow{flex:1 1 260px}
.tr label.f{display:grid;gap:4px;font-size:12px;font-weight:800;color:#334155;margin:0!important;min-height:0!important}
.tr input[type=text],.tr input[type=date],.tr input[type=number],.tr input[type=email],.tr input[type=tel],.tr input[type=time],.tr select,.tr textarea{width:100%;min-height:40px;border:1px solid #cbd5e1;border-radius:11px;padding:0 11px;font:inherit;font-size:14px;background:#fff;color:#0f172a}
.tr textarea{padding:9px 11px;min-height:70px}
.tr input[type=range]{min-height:0;border:0;padding:0;background:none;outline:none;box-shadow:none}
.tr input:focus,.tr select:focus,.tr textarea:focus{outline:2px solid rgba(36,84,214,.25);border-color:var(--tr-blue)}
.tr label.ck{display:flex!important;gap:8px;align-items:center;font-size:13.5px;font-weight:700;color:#0f172a;margin:0!important;min-height:0!important}
.tr label.ck input{width:18px;height:18px}
.tr-table,.tr table{width:100%;min-width:0!important;border-collapse:separate;border-spacing:0}
.tr-table th{background:#f8fbff;color:#334155;font-size:12px;text-align:left;padding:10px;border-bottom:1px solid var(--tr-line);text-transform:uppercase;letter-spacing:.02em}
.tr-table td{padding:10px;border-bottom:1px solid #edf2f8;font-size:13.5px;vertical-align:middle;overflow-wrap:anywhere;white-space:normal}
.tr-table tr.off td{color:#94a3b8}
.tr-table .act{text-align:right;white-space:nowrap}
.tr-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:900;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;white-space:nowrap}
.tr-badge.g{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}.tr-badge.r{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.tr-badge.y{border-color:#fde68a;background:#fffbeb;color:#a16207}.tr-badge.n{border-color:#e2e8f0;background:#f8fafc;color:#475569}
.tr-empty{padding:22px;text-align:center;color:var(--tr-mut);border:1px dashed #cbd5e1;border-radius:14px}
.tr-msg{padding:11px 14px;border-radius:14px;font-weight:700;margin-bottom:12px}.tr-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.tr-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.tr-msg.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.tr-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}.tr-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.tr-grid .s2{grid-column:span 2}.tr-grid .s3{grid-column:1/-1}
dialog.tr-modal{border:0;border-radius:22px;padding:0;width:min(760px,96vw);max-height:92vh;box-shadow:0 30px 80px rgba(2,6,23,.35);color:#0f172a}
dialog.tr-modal.wide{width:min(980px,96vw)}
dialog.tr-modal::backdrop{background:rgba(15,23,42,.55)}
.tr-mh{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid #e2e8f0;position:sticky;top:0;background:#fff;z-index:2}
.tr-mh h3{margin:0;font-size:18px;color:#0b1f5b}.tr-x{border:0;background:#f1f5f9;border-radius:10px;width:36px;height:36px;font-size:20px;cursor:pointer}
.tr-mb{padding:16px 18px;overflow:auto;max-height:calc(92vh - 140px)}
.tr-mf{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;padding:12px 18px;border-top:1px solid #e2e8f0;background:#f8fafc;position:sticky;bottom:0}
.tr-mtabs{display:flex;gap:4px;flex-wrap:wrap;margin:-4px 0 12px;border-bottom:1px solid #e2e8f0}
.tr-mtabs button{border:0;background:none;padding:9px 12px;font-weight:850;color:#64748b;cursor:pointer;border-bottom:3px solid transparent;font-size:13.5px}
.tr-mtabs button.on{color:#2454d6;border-bottom-color:#2454d6}
.tr-pane{display:none}.tr-pane.on{display:block}
.tr-hint{color:var(--tr-mut);font-size:12px;line-height:1.4}
.tr-grid .sec{grid-column:1/-1;margin:6px 0 0;padding-top:10px;border-top:1px solid #e2e8f0;font-weight:900;color:#0b1f5b;font-size:13px}
.tr-checks{list-style:none;margin:0;padding:0;display:grid;gap:6px}.tr-checks li{display:flex;gap:8px;align-items:flex-start;font-size:13.5px}.tr-checks li b{flex:0 0 auto}
.tr-cols{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}@media(max-width:900px){.tr-cols{grid-template-columns:1fr}}
.tr-kbd{display:inline-block;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:6px;padding:0 5px;font-size:11px;font-weight:800;color:#334155;background:#fff}
@media(max-width:760px){
  .tr-grid,.tr-grid.three{grid-template-columns:1fr}.tr-grid .s2{grid-column:auto}
  .tr-table.cards thead{display:none}
  .tr-table.cards,.tr-table.cards tbody,.tr-table.cards tr,.tr-table.cards td{display:block;width:auto}
  .tr-table.cards tr{border-bottom:1px solid #e2e8f0;padding:8px 0}
  .tr-table.cards td{border:0;padding:3px 6px}
  .tr-table.cards td[data-l]::before{content:attr(data-l) ': ';color:#64748b;font-weight:700;font-size:12px}
  .tr-table .act{text-align:left}
  .tr-search .tr-btn{flex:1 1 auto}
}
</style>
<div class="tr">
  <div class="tr-hero">
    <div><h1><?= transp_h($title) ?></h1><?php if ($subtitle !== ''): ?><p><?= transp_h($subtitle) ?></p><?php endif; ?></div>
    <?php if ($actionsHtml !== ''): ?><div class="tr-actions"><?= $actionsHtml ?></div><?php endif; ?>
  </div>
  <nav class="tr-tabs">
    <?php foreach ($items as $key => [$label, $page]): if (!transp_can($pdo, $key)) continue; ?>
      <a class="<?= $key === $perm ? 'on' : '' ?>" href="<?= transp_h($page) ?>"><?= transp_h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php
    if (!transp_ready($pdo)) {
      echo '<div class="tr-msg warn">O módulo de Transporte (CT-e/MDF-e) ainda não foi instalado no banco. O administrador da plataforma precisa aplicar a atualização "Transporte (CT-e/MDF-e) - V1 Fundação" no Atualizador do Banco.</div></div>';
      admin_footer();
      exit;
    }
    $f = transp_flash_take();
    if ($f) echo '<div class="tr-msg ' . ($f[0] === 'ok' ? 'ok' : 'err') . '">' . transp_h($f[1]) . '</div>';
  }
}

if (!function_exists('transp_end')) {
  /** Fecha a tela: JS dos modais (Esc fecha, F8 vai para a busca) e rodapé. */
  function transp_end(): void {
    ?>
</div>
<script>
(function(){
  window.trOpen=function(id){const d=document.getElementById(id);if(!d)return;if(!d.open)d.showModal();const f=d.querySelector('[autofocus],input:not([type=hidden]):not([disabled]),select');if(f)setTimeout(()=>f.focus(),30);};
  window.trClose=function(el){const d=el.closest?el.closest('dialog'):document.getElementById(el);if(d)d.close();};
  // A janela não fecha ao clicar no fundo: arrastar o mouse de um campo até fora dela
  // contava como clique no fundo e apagava o que foi digitado. Fecha pelo X, Cancelar ou Esc.
  // Abas dentro dos modais.
  document.addEventListener('click',e=>{const b=e.target.closest('.tr-mtabs button[data-pane]');if(!b)return;const box=b.closest('dialog,.tr-card');box.querySelectorAll('.tr-mtabs button').forEach(x=>x.classList.toggle('on',x===b));box.querySelectorAll('.tr-pane').forEach(p=>p.classList.toggle('on',p.id===b.dataset.pane));});
  // F8: vai para a pesquisa da tela (padrão do Nexa).
  document.addEventListener('keydown',e=>{if(e.key!=='F8')return;const open=document.querySelector('dialog[open]');if(open)return;const s=document.querySelector('[data-tr-search]');if(s){e.preventDefault();s.focus();s.select();}});
  // Botões que gravam: evita clique duplo.
  document.addEventListener('submit',e=>{const f=e.target;if(f.dataset.confirm&&!confirm(f.dataset.confirm)){e.preventDefault();return;}const b=f.querySelector('button[type=submit]:not([data-keep])');if(b)setTimeout(()=>{b.disabled=true;},0);});
})();
</script>
<?php
    admin_footer();
  }
}

if (!function_exists('transp_post_handle')) {
  /**
   * Executa uma ação POST com CSRF; erro volta como aviso na mesma tela (PRG).
   * $fn devolve a mensagem de sucesso, ou [mensagem, URL de retorno].
   */
  function transp_post_handle(string $back, callable $fn): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    try {
      transp_csrf_check();
      $res = $fn();
      if (is_array($res)) { $back = (string)($res[1] ?? $back); $res = $res[0] ?? ''; }
      $msg = (string)$res;
      if ($msg !== '') transp_flash('ok', $msg);
    } catch (Throwable $e) {
      transp_flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível gravar: ' . $e->getMessage());
    }
    transp_redirect($back);
  }
}
