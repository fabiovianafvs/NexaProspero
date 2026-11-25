<?php
declare(strict_types=1);

// Layout e utilidades das telas da Trilha de Implantação (menu CRM; admin/implantacao*.php).
// Cada tela faz: require _bootstrap.php, _chrome.php e este arquivo; depois impl_guard().
// Mesmo visual das telas do Ponto/Transporte (prefixo de CSS im-).
require_once __DIR__ . '/../config/implantacao_module.php';

if (!function_exists('impl_menu_items')) {
  /** Abas do módulo: chave da aba => [rótulo, página, permissão]. */
  function impl_menu_items(): array {
    return [
      'trilha' => ['Trilha de Implantação', 'implantacao.php', 'crm_implantacao'],
      'acompanhamento' => ['Acompanhamento', 'implantacao_acompanhamento.php', 'crm_implantacao'],
      'relatorios' => ['Relatórios', 'implantacao_relatorios.php', 'crm_implantacao_relatorios'],
      'documentacao' => ['Documentação', 'implantacao_documentacao.php', 'crm_implantacao_documentacao'],
      'cadastros' => ['Cadastros', 'implantacao_cadastros.php', 'crm_implantacao_cadastros'],
    ];
  }
}

if (!function_exists('impl_can')) {
  function impl_can(PDO $pdo, string $perm): bool {
    return access_is_company_master() || access_has_permission($pdo, (int)COMPANY_ID, $perm);
  }
}

if (!function_exists('impl_guard')) {
  /** Permissão da tela. Devolve o company_id da sessão (nunca do formulário). */
  function impl_guard(PDO $pdo, string $perm): int {
    if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID) {
      access_render_denied('A Trilha de Implantação trabalha só com a empresa em que você entrou (sem o contexto F11).');
    }
    if (!impl_can($pdo, $perm)) access_render_denied('Seu usuário não possui permissão para esta tela da Trilha de Implantação.');
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['impl_csrf'])) $_SESSION['impl_csrf'] = bin2hex(random_bytes(24));
    return (int)COMPANY_ID;
  }
}

if (!function_exists('impl_csrf')) {
  function impl_csrf(): string { return (string)($_SESSION['impl_csrf'] ?? ''); }
  function impl_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . impl_h(impl_csrf()) . '">'; }
  function impl_csrf_check(): void {
    $posted = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($posted === '' || !hash_equals(impl_csrf(), $posted)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
  }
  function impl_flash(string $type, string $msg): void { $_SESSION['impl_flash'] = [$type, $msg]; }
  function impl_flash_take(): ?array { $f = $_SESSION['impl_flash'] ?? null; unset($_SESSION['impl_flash']); return is_array($f) ? $f : null; }
  function impl_redirect(string $url): void { header('Location: ' . $url); exit; }
  function impl_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }
  /** Ação POST com CSRF; erro volta como aviso na mesma tela (PRG). $fn devolve a mensagem ou [mensagem, URL]. */
  function impl_post_handle(string $back, callable $fn): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    try {
      impl_csrf_check();
      $res = $fn();
      if (is_array($res)) { $back = (string)($res[1] ?? $back); $res = $res[0] ?? ''; }
      if ((string)$res !== '') impl_flash('ok', (string)$res);
    } catch (Throwable $e) {
      impl_flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível gravar: ' . $e->getMessage());
    }
    impl_redirect($back);
  }
  function impl_pill(?string $nome, ?string $cor): string {
    if ((string)$nome === '') return '<span class="im-badge n">Sem status</span>';
    $c = impl_cor((string)$cor);
    return '<span class="im-pill" style="color:' . $c . ';border-color:' . $c . '55;background:' . $c . '14"><i style="background:' . $c . '"></i>' . impl_h($nome) . '</span>';
  }
}

if (!function_exists('impl_start')) {
  /** Cabeçalho: menu do sistema, título, abas do módulo e aviso de migração pendente. */
  function impl_start(PDO $pdo, string $tab, string $title, string $subtitle = '', string $actionsHtml = ''): void {
    $items = impl_menu_items();
    $nav = ['trilha' => 'crm_implantacao', 'acompanhamento' => 'crm_implantacao_acompanhamento', 'relatorios' => 'crm_implantacao_relatorios', 'documentacao' => 'crm_implantacao_documentacao', 'cadastros' => 'crm_implantacao_cadastros'][$tab] ?? 'crm_implantacao';
    admin_topbar($title, $nav);
    ?>
<style>
.im{--im-line:#dbe4f0;--im-mut:#64748b;--im-ink:#0b1f5b;--im-blue:#2454d6;max-width:1240px;margin:10px auto 0;color:#0f172a}
.im *{box-sizing:border-box}
.im [hidden]{display:none!important}
.im-hero{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;justify-content:space-between;margin:4px 0 10px}
.im-hero h1{margin:0;font-size:clamp(22px,2.6vw,30px);color:var(--im-ink);letter-spacing:-.02em}.im-hero p{margin:4px 0 0;color:var(--im-mut);font-size:13.5px;max-width:760px;line-height:1.45}
.im-actions{display:flex;gap:8px;flex-wrap:wrap}
.im-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.im-tabs a{padding:7px 11px;border:1px solid var(--im-line);border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;font-weight:800;font-size:12.5px;white-space:nowrap}
.im-tabs a.on{background:linear-gradient(135deg,#071b55,#2454d6);color:#fff;border-color:transparent}
.im-card{background:#fff;border:1px solid var(--im-line);border-radius:20px;padding:16px;box-shadow:0 14px 38px rgba(11,31,91,.06);min-width:0}
.im-card h2{margin:0 0 4px;font-size:17px;color:var(--im-ink)}.im-sub{margin:0 0 10px;color:var(--im-mut);font-size:12.5px;line-height:1.45}
.im-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px;padding:0 15px;border-radius:12px;border:1px solid var(--im-line);background:#fff;color:var(--im-ink);font-weight:850;font-size:13.5px;cursor:pointer;text-decoration:none;white-space:nowrap}
.im-btn:hover{background:#f1f5ff}.im-btn.pri{background:var(--im-blue);border-color:var(--im-blue);color:#fff}.im-btn.pri:hover{background:#1d46b8}
.im-btn.ok{background:#16a34a;border-color:#16a34a;color:#fff}.im-btn.red{color:#b91c1c;border-color:#fecaca}.im-btn.red:hover{background:#fef2f2}.im-btn.sm{min-height:32px;padding:0 10px;font-size:12.5px;border-radius:10px}
.im-btn:disabled{opacity:.55;cursor:default}
.im-search{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:12px}
.im-search .grow{flex:1 1 260px}
.im label.f{display:grid;gap:4px;font-size:12px;font-weight:800;color:#334155;margin:0!important;min-height:0!important}
.im input[type=text],.im input[type=date],.im input[type=number],.im input[type=email],.im input[type=tel],.im input[type=time],.im select,.im textarea{width:100%;min-height:40px;border:1px solid #cbd5e1;border-radius:11px;padding:0 11px;font:inherit;font-size:14px;background:#fff;color:#0f172a}
.im textarea{padding:9px 11px;min-height:70px}
.im input[type=range]{min-height:0;border:0;padding:0;background:none;outline:none;box-shadow:none}
.im input:focus,.im select:focus,.im textarea:focus{outline:2px solid rgba(36,84,214,.25);border-color:var(--im-blue)}
.im label.ck{display:flex!important;gap:8px;align-items:center;font-size:13.5px;font-weight:700;color:#0f172a;margin:0!important;min-height:0!important}
.im label.ck input{width:18px;height:18px}
.im-table,.im table{width:100%;min-width:0!important;border-collapse:separate;border-spacing:0}
.im-table th{background:#f8fbff;color:#334155;font-size:12px;text-align:left;padding:10px;border-bottom:1px solid var(--im-line);text-transform:uppercase;letter-spacing:.02em}
.im-table td{padding:10px;border-bottom:1px solid #edf2f8;font-size:13.5px;vertical-align:middle;overflow-wrap:anywhere;white-space:normal}
.im-table tr.off td{color:#94a3b8}
.im-table .act{text-align:right;white-space:nowrap}
.im-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:900;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;white-space:nowrap}
.im-badge.g{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}.im-badge.r{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.im-badge.y{border-color:#fde68a;background:#fffbeb;color:#a16207}.im-badge.n{border-color:#e2e8f0;background:#f8fafc;color:#475569}
.im-empty{padding:22px;text-align:center;color:var(--im-mut);border:1px dashed #cbd5e1;border-radius:14px}
.im-msg{padding:11px 14px;border-radius:14px;font-weight:700;margin-bottom:12px}.im-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.im-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.im-msg.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.im-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}.im-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.im-grid .s2{grid-column:span 2}.im-grid .s3{grid-column:1/-1}
dialog.im-modal{border:0;border-radius:22px;padding:0;width:min(760px,96vw);max-height:92vh;box-shadow:0 30px 80px rgba(2,6,23,.35);color:#0f172a}
dialog.im-modal.wide{width:min(980px,96vw)}
dialog.im-modal::backdrop{background:rgba(15,23,42,.55)}
.im-mh{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid #e2e8f0;position:sticky;top:0;background:#fff;z-index:2}
.im-mh h3{margin:0;font-size:18px;color:#0b1f5b}.im-x{border:0;background:#f1f5f9;border-radius:10px;width:36px;height:36px;font-size:20px;cursor:pointer}
.im-mb{padding:16px 18px;overflow:auto;max-height:calc(92vh - 140px)}
.im-mf{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;padding:12px 18px;border-top:1px solid #e2e8f0;background:#f8fafc;position:sticky;bottom:0}
.im-mtabs{display:flex;gap:4px;flex-wrap:wrap;margin:-4px 0 12px;border-bottom:1px solid #e2e8f0}
.im-mtabs button{border:0;background:none;padding:9px 12px;font-weight:850;color:#64748b;cursor:pointer;border-bottom:3px solid transparent;font-size:13.5px}
.im-mtabs button.on{color:#2454d6;border-bottom-color:#2454d6}
.im-pane{display:none}.im-pane.on{display:block}
.im-hint{color:var(--im-mut);font-size:12px;line-height:1.4}
.im-grid .sec{grid-column:1/-1;margin:6px 0 0;padding-top:10px;border-top:1px solid #e2e8f0;font-weight:900;color:#0b1f5b;font-size:13px}
.im-checks{list-style:none;margin:0;padding:0;display:grid;gap:6px}.im-checks li{display:flex;gap:8px;align-items:flex-start;font-size:13.5px}.im-checks li b{flex:0 0 auto}
.im-cols{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}@media(max-width:900px){.im-cols{grid-template-columns:1fr}}
.im-kbd{display:inline-block;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:6px;padding:0 5px;font-size:11px;font-weight:800;color:#334155;background:#fff}
@media(max-width:760px){
  .im-grid,.im-grid.three{grid-template-columns:1fr}.im-grid .s2{grid-column:auto}
  .im-table.cards thead{display:none}
  .im-table.cards,.im-table.cards tbody,.im-table.cards tr,.im-table.cards td{display:block;width:auto}
  .im-table.cards tr{border-bottom:1px solid #e2e8f0;padding:8px 0}
  .im-table.cards td{border:0;padding:3px 6px}
  .im-table.cards td[data-l]::before{content:attr(data-l) ': ';color:#64748b;font-weight:700;font-size:12px}
  .im-table .act{text-align:left}
  .im-search .im-btn{flex:1 1 auto}
}

.im-pill{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:999px;font-weight:850;font-size:12.5px;border:1px solid;white-space:nowrap}
.im-pill i{width:9px;height:9px;border-radius:50%;display:inline-block;flex:0 0 auto}
.im-board{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;align-items:start}
.im-col{background:#fff;border:1px solid var(--im-line);border-top:4px solid var(--c,#2563eb);border-radius:18px;box-shadow:0 10px 26px rgba(11,31,91,.05);overflow:hidden}
.im-col-h{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #eef2f7;font-weight:900;color:#0b1f5b}
.im-col-h i{width:10px;height:10px;border-radius:50%;background:var(--c,#2563eb)}.im-col-h span.n{margin-left:auto;font-size:12px;background:#eef2ff;color:#1e3a8a;border-radius:999px;padding:2px 9px}
.im-col ul{list-style:none;margin:0;padding:8px 10px 12px;display:grid;gap:4px}
.im-col li{display:flex;gap:9px;align-items:flex-start;padding:6px 6px;border-radius:10px}
.im-col li:hover{background:#f8fafc}
.im-col li input{width:19px;height:19px;margin-top:1px;flex:0 0 auto;accent-color:#2563eb;cursor:pointer}
.im-col li .t{font-size:14px;line-height:1.35;color:#0f172a;flex:1}
.im-col li.done .t .nm{text-decoration:line-through;color:#64748b}
.im-col li small{display:block;font-size:11px;color:#94a3b8;text-decoration:none}
.im-col li .rm{border:0;background:none;color:#cbd5e1;cursor:pointer;font-size:16px;line-height:1;padding:0 2px}.im-col li .rm:hover{color:#dc2626}
.im-bar{height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;min-width:120px}.im-bar b{display:block;height:100%;background:linear-gradient(90deg,#2563eb,#16a34a);border-radius:999px}
.im-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}.im-kpi{background:#fff;border:1px solid var(--im-line);border-radius:16px;padding:12px 14px}.im-kpi small{color:var(--im-mut);font-weight:800;font-size:12px}.im-kpi strong{display:block;font-size:24px;color:#0b1f5b}
.im-steps{margin:0;padding-left:0;list-style:none;font-size:12.5px;color:#334155}.im-table td.nw{white-space:nowrap}.im-steps li{margin:1px 0}
.im-sig{border:2px dashed #94a3b8;border-radius:14px;background:#fff;touch-action:none;width:100%;height:200px;display:block;cursor:crosshair}
.im-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}.im-photo{border:1px solid var(--im-line);border-radius:14px;overflow:hidden;background:#fff}.im-photo img{width:100%;height:130px;object-fit:cover;display:block}.im-photo div{padding:7px 9px;font-size:12.5px;display:flex;gap:6px;align-items:center;justify-content:space-between}
.im-stchecks{display:flex;gap:12px;flex-wrap:wrap;align-items:center;min-height:40px}
.im-swatch{display:inline-block;width:16px;height:16px;border-radius:5px;vertical-align:middle;border:1px solid rgba(0,0,0,.1)}
.im input[type=color]{width:56px;min-height:40px;padding:2px;border:1px solid #cbd5e1;border-radius:11px;background:#fff}
@media(max-width:760px){.im-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<div class="im">
  <div class="im-hero">
    <div><h1><?= impl_h($title) ?></h1><?php if ($subtitle !== ''): ?><p><?= impl_h($subtitle) ?></p><?php endif; ?></div>
    <?php if ($actionsHtml !== ''): ?><div class="im-actions"><?= $actionsHtml ?></div><?php endif; ?>
  </div>
  <nav class="im-tabs">
    <?php foreach ($items as $key => [$label, $page, $perm]): if (!impl_can($pdo, $perm)) continue; ?>
      <a class="<?= $key === $tab ? 'on' : '' ?>" href="<?= impl_h($page) ?>"><?= impl_h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php
    if (!impl_ready($pdo)) {
      echo '<div class="im-msg warn">A Trilha de Implantação ainda não foi instalada no banco. O administrador da plataforma precisa aplicar a atualização "CRM - Trilha de Implantação v1" no Atualizador do Banco.</div></div>';
      admin_footer();
      exit;
    }
    $f = impl_flash_take();
    if ($f) echo '<div class="im-msg ' . ($f[0] === 'ok' ? 'ok' : ($f[0] === 'warn' ? 'warn' : 'err')) . '">' . nl2br(impl_h($f[1])) . '</div>';
  }
}

if (!function_exists('impl_end')) {
  /** Fecha a tela: JS dos modais (Esc fecha) e rodapé. */
  function impl_end(): void {
    ?>
</div>
<script>
(function(){
  window.imOpen=function(id){const d=document.getElementById(id);if(!d)return;if(!d.open)d.showModal();const f=d.querySelector('[autofocus],input:not([type=hidden]):not([disabled]),select');if(f)setTimeout(()=>f.focus(),30);};
  window.imClose=function(el){const d=el.closest?el.closest('dialog'):document.getElementById(el);if(d)d.close();};
  document.addEventListener('click',e=>{const b=e.target.closest('.im-mtabs button[data-pane]');if(!b)return;const box=b.closest('dialog,.im-card');box.querySelectorAll('.im-mtabs button').forEach(x=>x.classList.toggle('on',x===b));box.querySelectorAll('.im-pane').forEach(p=>p.classList.toggle('on',p.id===b.dataset.pane));});
  document.addEventListener('submit',e=>{const f=e.target;if(f.dataset.confirm&&!confirm(f.dataset.confirm)){e.preventDefault();return;}const b=f.querySelector('button[type=submit]:not([data-keep])');if(b)setTimeout(()=>{b.disabled=true;},0);});
})();
</script>
<?php
    admin_footer();
  }
}
