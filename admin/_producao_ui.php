<?php
declare(strict_types=1);
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/producao_module.php';

if (!function_exists('production_h')) {
  function production_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('production_page_start')) {
  function production_page_start(string $title, string $active): void {
    admin_topbar($title, $active);
    ?>
    <style>
      .pr-page{--pr-border:#dbe4f0;--pr-muted:#64748b;--pr-soft:#f8fbff;max-width:1500px;margin:0 auto;padding:18px}
      .pr-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:4px 0 16px}.pr-hero h1{margin:0;color:var(--admin-primary);font-size:clamp(24px,3vw,34px);letter-spacing:-.04em}.pr-hero p{margin:6px 0 0;color:var(--pr-muted);line-height:1.45;max-width:900px}.pr-actions{display:flex;gap:9px;flex-wrap:wrap;margin-left:auto}.pr-actions .btn{min-height:42px;display:inline-flex;align-items:center;justify-content:center}
      .pr-card{background:#fff;border:1px solid var(--pr-border);border-radius:22px;padding:17px;box-shadow:0 16px 40px rgba(11,31,91,.07);margin-bottom:16px}.pr-card h2,.pr-card h3{margin:0 0 12px;color:var(--admin-primary)}.pr-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}.pr-card-head h2,.pr-card-head h3{margin:0}.pr-muted{color:var(--pr-muted)}
      .pr-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.pr-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pr-row label{display:block;font-size:12px;font-weight:850;color:#475569;margin-bottom:5px}.pr-row input,.pr-row select,.pr-row textarea{width:100%;min-height:42px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;padding:9px 11px;color:#0f172a}.pr-row textarea{min-height:90px;resize:vertical}.pr-help{font-size:11px;color:#64748b;margin-top:5px;line-height:1.45}.pr-span-2{grid-column:span 2}.pr-span-3{grid-column:span 3}
      .pr-filter{display:grid;grid-template-columns:minmax(220px,2fr) repeat(3,minmax(150px,1fr)) auto;gap:10px;align-items:end}.pr-filter-actions{display:flex;gap:8px;align-items:center}.pr-table-wrap{overflow:auto;border:1px solid var(--pr-border);border-radius:16px}.pr-table{width:100%;border-collapse:collapse;min-width:780px}.pr-table th{background:#f8fbff;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.03em;padding:10px;text-align:left;border-bottom:1px solid var(--pr-border)}.pr-table td{padding:11px 10px;border-bottom:1px solid #edf2f7;vertical-align:middle}.pr-table tbody tr:last-child td{border-bottom:0}.pr-table input,.pr-table select{width:100%;min-height:39px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;padding:8px 10px;color:#0f172a}.pr-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.pr-actions-inline{display:flex;gap:7px;flex-wrap:wrap}
      .pr-badge{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8}.pr-badge.final{border-color:#a7f3d0;background:#ecfdf5;color:#047857}.pr-badge.process{border-color:#fde68a;background:#fffbeb;color:#a16207}.pr-badge.cancel{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.pr-ok{color:#047857;font-weight:900}.pr-bad{color:#b91c1c;font-weight:900}.pr-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:16px}.pr-kpi{border:1px solid var(--pr-border);border-radius:18px;background:#fff;padding:14px}.pr-kpi small{display:block;color:#64748b;font-weight:800}.pr-kpi strong{display:block;margin-top:5px;color:var(--admin-primary);font-size:22px}
      .pr-check{display:flex;gap:10px;align-items:flex-start;padding:13px;border:1px solid #dbe4f0;border-radius:15px;background:#f8fbff}.pr-check input{margin-top:3px}.pr-check strong{display:block;color:#0f172a}.pr-check span{display:block;color:#64748b;font-size:12px;margin-top:3px;line-height:1.45}
      .pr-toolbar{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.pr-empty{text-align:center;color:#64748b;padding:22px!important}.pr-warning{border:1px solid #fde68a;background:#fffbeb;color:#854d0e;border-radius:14px;padding:11px 13px;margin-bottom:12px}.pr-info{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:14px;padding:11px 13px;margin-bottom:12px}
      @media(max-width:1050px){.pr-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.pr-filter-actions{grid-column:span 2}.pr-kpis{grid-template-columns:1fr 1fr}}
      @media(max-width:720px){.pr-page{padding:12px}.pr-grid,.pr-grid-3,.pr-filter,.pr-kpis{grid-template-columns:1fr}.pr-span-2,.pr-span-3,.pr-filter-actions{grid-column:auto}.pr-actions{width:100%}.pr-actions .btn{flex:1}.pr-table-wrap{overflow:auto}}
    </style>
    <div class="pr-page">
    <?php
  }
}
if (!function_exists('production_page_header')) {
  function production_page_header(string $title, string $description, array $actions=[]): void {
    echo '<div class="pr-hero"><div><h1>'.production_h($title).'</h1><p>'.production_h($description).'</p></div>';
    if($actions){echo '<div class="pr-actions">';foreach($actions as $a)echo '<a class="'.production_h($a['class']??'btn').'" href="'.production_h($a['href']??'#').'">'.production_h($a['label']??'Ação').'</a>';echo '</div>';}
    echo '</div>';
  }
}
if (!function_exists('production_page_end')) { function production_page_end(): void { echo '</div>'; admin_footer(); } }
if (!function_exists('production_status_badge')) {
  function production_status_badge(string $status): string {
    $status=strtoupper(trim($status));$class='';$label=$status;
    if($status==='FINALIZADA'){$class=' final';$label='Finalizada';}
    elseif($status==='EM_PROCESSO'){$class=' process';$label='Em processo';}
    elseif($status==='CANCELADA'){$class=' cancel';$label='Cancelada';}
    return '<span class="pr-badge'.$class.'">'.production_h($label).'</span>';
  }
}
