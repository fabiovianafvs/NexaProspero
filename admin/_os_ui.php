<?php

if (!function_exists('os_ui_hex_color')) {
    function os_ui_hex_color($value, $fallback)
    {
        $value = strtoupper(trim((string)$value));
        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
    }
}

if (!function_exists('os_ui_theme_config')) {
    function os_ui_theme_config()
    {
        // Mesmos padrões do admin/_chrome.php do Nexa.
        $theme = array(
            'primary' => '#0B1F5B',
            'secondary' => '#2454D6',
            'accent' => '#00B8FF',
            'gradient' => '#06122E',
            'text' => '#FFFFFF',
        );

        try {
            $biz = array();
            if (isset($GLOBALS['ADMIN_BUSINESS_CFG']) && is_array($GLOBALS['ADMIN_BUSINESS_CFG'])) {
                $biz = $GLOBALS['ADMIN_BUSINESS_CFG'];
            } elseif (defined('COMPANY_ID') && function_exists('load_business_config')) {
                $biz = (array)load_business_config((int)COMPANY_ID);
            }

            $admin = isset($biz['admin_theme']) && is_array($biz['admin_theme']) ? $biz['admin_theme'] : array();
            if (!empty($admin['enabled'])) {
                $theme['primary'] = os_ui_hex_color(isset($admin['primary_color']) ? $admin['primary_color'] : null, $theme['primary']);
                $theme['secondary'] = os_ui_hex_color(isset($admin['secondary_color']) ? $admin['secondary_color'] : null, $theme['secondary']);
                $theme['accent'] = os_ui_hex_color(isset($admin['accent_color']) ? $admin['accent_color'] : null, $theme['accent']);
                $theme['gradient'] = os_ui_hex_color(isset($admin['gradient_color']) ? $admin['gradient_color'] : null, $theme['gradient']);
                $theme['text'] = os_ui_hex_color(isset($admin['text_color']) ? $admin['text_color'] : null, $theme['text']);
            }
        } catch (Throwable $e) {
            // Mantém o padrão do Nexa se a configuração visual não estiver disponível.
        }

        return $theme;
    }
}

require_once __DIR__ . '/_chrome.php';

if (!function_exists('os_admin_active_key')) {
    function os_admin_active_key($active)
    {
        $map = array(
            'monitor' => 'os_monitor',
            'nova' => 'os_monitor',
            'mobile' => 'os_mobile',
            'faturas' => 'os_faturas',
            'fiscal' => 'os_fiscal_documentos',
            'config' => 'os_configuracoes',
        );
        return isset($map[$active]) ? $map[$active] : 'os_monitor';
    }
}

if (!function_exists('os_page_start')) {
    function os_page_start($title, $active)
    {
        admin_topbar('Ordem de Serviço - ' . (string)$title, os_admin_active_key($active));
        echo '<style>';
        echo '.os-module{--os-primary:var(--admin-primary);--os-secondary:var(--admin-secondary);--os-accent:var(--admin-accent);--os-on-primary:var(--admin-text);--os-bg:#f6f8fc;--os-surface:#fff;--os-border:#b8c4d1;--os-border-soft:#d5dde7;--os-text:#172033;--os-muted:#64748b;--os-danger:#b42318;--os-success:#08783e;--os-radius:18px;color:var(--os-text)}';
        echo '.os-wrap{width:100%;margin:0 auto;padding:0}.os-title-row{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}.os-title-row h1{margin:0;font-size:26px;color:var(--admin-primary);letter-spacing:-.03em}.os-title-row .spacer{flex:1}';
        echo '.os-module .card{background:var(--os-surface);border:1px solid rgba(219,232,247,.98);border-radius:22px;box-shadow:0 12px 30px rgba(11,31,91,.055);margin-bottom:16px;overflow:hidden}.os-module .card-head{padding:14px 16px;border-bottom:1px solid var(--os-border-soft);font-weight:950;color:var(--admin-primary);background:linear-gradient(180deg,#fff,#fbfdff)}.os-module .card-body{padding:16px}';
        echo '.os-module .grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:12px}.os-module .c1{grid-column:span 1}.os-module .c2{grid-column:span 2}.os-module .c3{grid-column:span 3}.os-module .c4{grid-column:span 4}.os-module .c5{grid-column:span 5}.os-module .c6{grid-column:span 6}.os-module .c7{grid-column:span 7}.os-module .c8{grid-column:span 8}.os-module .c9{grid-column:span 9}.os-module .c10{grid-column:span 10}.os-module .c11{grid-column:span 11}.os-module .c12{grid-column:span 12}.os-module .compact-grid{gap:10px 12px}';
        echo '.os-module label{display:block;font-size:12px;font-weight:850;color:#27364a;margin-bottom:5px}.os-module input,.os-module select,.os-module textarea{width:100%;min-height:40px;border:1px solid #9eacbc;border-radius:12px;padding:8px 10px;background:#fff;color:#172033;font-size:14px;line-height:1.25;box-shadow:inset 0 0 0 1px rgba(15,23,42,.018);transition:border-color .15s ease,box-shadow .15s ease,background .15s ease}.os-module input:hover,.os-module select:hover,.os-module textarea:hover{border-color:#8293a7}.os-module textarea{min-height:82px;resize:vertical}.os-module input:focus,.os-module select:focus,.os-module textarea:focus{outline:none;border-color:var(--admin-secondary);box-shadow:0 0 0 3px color-mix(in srgb,var(--admin-secondary) 16%,transparent),inset 0 0 0 1px color-mix(in srgb,var(--admin-secondary) 16%,transparent)}.os-module input[readonly],.os-module input:disabled,.os-module select:disabled,.os-module textarea:disabled{background:#f1f5f9;color:#475569;border-color:#b8c3cf}.os-module input[type="date"],.os-module input[type="datetime-local"]{font-variant-numeric:tabular-nums}';
        echo '.os-module .section-caption{margin-top:2px;padding:7px 0 2px;border-top:1px solid var(--os-border-soft);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.055em;color:var(--os-muted)}';
        echo '.os-module .btn{min-height:40px;border:1px solid #dbe4f0;border-radius:13px;padding:8px 13px;font-weight:850;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:13px;line-height:1;white-space:nowrap;background:#fff;color:#334155}.os-module .btn-primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary))!important;color:var(--admin-text)!important;border-color:transparent!important}.os-module .btn-secondary{background:#f8fafc;color:#334155}.os-module .btn-outline{background:#fff;color:var(--admin-primary);border-color:color-mix(in srgb,var(--admin-secondary) 38%,#cbd5e1)}.os-module .btn-danger{background:#fff0ef;color:var(--os-danger);border-color:#f2b8b5}.os-module .btn-success{background:#0f7a46;color:#fff;border-color:#0f7a46}.os-module .btn-sm{min-height:32px;padding:6px 9px;font-size:12px}.os-module .btn:hover{filter:brightness(.985)}';
        echo '.os-module .table-wrap{overflow:auto;max-width:100%}.os-module .table{width:100%;border-collapse:collapse;font-size:13px}.os-module .table th{background:#f3f6fa;color:#3f4d60;text-align:left;padding:11px 10px;border-bottom:1px solid #b9c5d1;white-space:nowrap;font-size:11px;text-transform:uppercase;letter-spacing:.035em}.os-module .table td{padding:11px 10px;border-bottom:1px solid #cfd8e2;vertical-align:middle}.os-module .table tbody tr:hover td{background:#f8fbff}.os-module .os-monitor-table tbody tr.os-status-row td{background:color-mix(in srgb,var(--os-row-status) 7%,#fff);border-bottom-color:color-mix(in srgb,var(--os-row-status) 24%,#cbd5e1)}.os-module .os-monitor-table tbody tr.os-status-row td:first-child{border-left:5px solid var(--os-row-status);padding-left:12px}.os-module .os-monitor-table tbody tr.os-status-row:hover td{background:color-mix(in srgb,var(--os-row-status) 12%,#fff)}.os-module .num{text-align:right;white-space:nowrap}';
        echo '.os-module .badge{display:inline-block;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:850}.os-module .muted{color:var(--os-muted)}.os-module .small{font-size:12px}.os-module .alert{padding:11px 14px;border-radius:13px;margin-bottom:14px}.os-module .alert-ok{background:#eaf7ef;color:#0e6b3d;border:1px solid #b8e2c9}.os-module .alert-error{background:#fff0ef;color:#9e2b22;border:1px solid #efc2be}.os-module .hint{font-size:12px;color:var(--os-muted);margin-top:4px}.os-module .totals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.os-module .total-box{border:1px solid #dbe5ed;background:#fff;border-radius:18px;padding:14px;box-shadow:0 8px 22px rgba(15,23,42,.04)}.os-module .total-box span{display:block;font-size:11px;color:#64748b;font-weight:750}.os-module .total-box strong{display:block;font-size:20px;color:var(--admin-primary);margin-top:4px}';
        echo '.os-module .modal-bg{display:none;position:fixed;inset:0;background:rgba(14,31,35,.52);z-index:160;align-items:center;justify-content:center;padding:20px}.os-module .modal-bg.open{display:flex}.os-module .modal{background:#fff;width:min(850px,100%);max-height:88vh;border-radius:18px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25)}.os-module .modal-head{padding:14px 18px;border-bottom:1px solid #dde6e8;display:flex;align-items:center;gap:10px}.os-module .modal-head strong{color:var(--admin-primary);font-size:17px}.os-module .modal-head button{margin-left:auto}.os-module .modal-body{padding:16px;overflow:auto;max-height:72vh}.os-module .search-list{border:1px solid #aebac7;border-radius:12px;overflow:auto;max-height:380px;margin-top:10px}.os-module .search-row{padding:10px 12px;border-bottom:1px solid #cfd8e2;cursor:pointer}.os-module .search-row:last-child{border-bottom:0}.os-module .search-row.selected,.os-module .search-row:hover{background:color-mix(in srgb,var(--admin-secondary) 9%,#fff)}.os-module .search-row b{display:block}.os-module .search-row small{color:#6d7d83}';
        echo '.os-module .search-message{padding:13px;color:var(--os-muted);font-size:13px}.os-module .product-quick-wrap{position:relative;z-index:8}.os-module .product-quick-results{display:none;position:absolute;left:0;right:0;top:calc(100% + 5px);background:#fff;border:1px solid #aebac7;border-radius:12px;box-shadow:0 14px 34px rgba(15,23,42,.16);max-height:320px;overflow:auto;z-index:120}.os-module .product-quick-results.open{display:block}.os-module .product-quick-row{padding:10px 12px;border-bottom:1px solid #cfd8e2;cursor:pointer}.os-module .product-quick-row:last-child{border-bottom:0}.os-module .product-quick-row:hover,.os-module .product-quick-row.selected{background:color-mix(in srgb,var(--admin-secondary) 9%,#fff)}.os-module .product-quick-row b{display:block;color:var(--os-text);font-size:13px}.os-module .product-quick-row small{display:block;margin-top:3px;color:var(--os-muted);font-size:11px}.os-module .product-quick-message{padding:12px;color:var(--os-muted);font-size:12px}';
        echo '.os-module .tabs{display:flex;gap:7px;margin-bottom:15px;flex-wrap:wrap}.os-module .tabs a{padding:8px 11px;border-radius:10px;text-decoration:none;color:#475569;background:#edf2f7}.os-module .tabs a.active{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:var(--admin-text);font-weight:850}.os-module .inline{display:flex;gap:8px;align-items:center}.os-module .inline>*{min-width:0}.os-module .inline input,.os-module .inline select{flex:1 1 auto}.os-module .inline .btn{flex:0 0 auto}.os-module .nowrap{white-space:nowrap}';
        echo '.os-module .filter-grid{display:grid;grid-template-columns:minmax(250px,2.1fr) minmax(125px,1fr) minmax(145px,1.1fr) minmax(145px,1.05fr) minmax(132px,.9fr) minmax(132px,.9fr);gap:11px 12px;align-items:end}.os-module .filter-actions{grid-column:1/-1;display:flex;align-items:center;gap:8px;min-height:40px}.os-module .filter-actions .filter-count{margin-left:5px}.os-module .filter-grid input[type="date"]{max-width:100%}';
        echo '.os-module .item-entry-grid{display:grid;grid-template-columns:minmax(300px,2.3fr) minmax(90px,.62fr) minmax(125px,.82fr) minmax(155px,1fr) minmax(190px,1.35fr) minmax(130px,.9fr);gap:11px;align-items:end}.os-module .item-stock{align-self:stretch;display:flex;flex-direction:column;justify-content:flex-end;padding-bottom:1px}.os-module .item-stock label.checkline{font-weight:500;margin:0;min-height:38px;display:flex;align-items:center;gap:7px}.os-module .item-stock input[type="checkbox"]{width:auto;min-height:auto}.os-module .item-entry-grid .item-action .btn{width:100%}';
        echo '.os-module .item-entry-grid-v16 .item-notes{grid-column:span 5}.os-module .item-entry-grid-v16 .item-action{display:flex;gap:7px;align-items:flex-end}.os-module .item-entry-grid-v16 .item-action .btn,.os-module .item-entry-grid-v16 .item-action a{flex:1 1 auto}';
        echo '.os-module .payment-entry-grid{display:grid;grid-template-columns:minmax(220px,1.5fr) minmax(120px,.75fr) minmax(140px,.85fr) minmax(220px,1.45fr) auto;gap:11px;align-items:end}.os-module .payment-entry-grid .btn{min-width:150px}';
        echo '.os-module .os-form-actions{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;padding-top:4px}.os-module .os-adjustments{display:grid;grid-template-columns:repeat(2,minmax(150px,180px));gap:12px}.os-module .os-save-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px}.os-module .os-save-actions .btn-primary{min-width:130px}';
        echo '@media(max-width:1120px){.os-module .filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.os-module .filter-grid .filter-search{grid-column:span 3}.os-module .item-entry-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.os-module .item-entry-grid .item-product{grid-column:span 2}.os-module .item-entry-grid .item-notes{grid-column:span 3}.os-module .payment-entry-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.os-module .payment-entry-grid .payment-notes{grid-column:span 2}.os-module .payment-entry-grid .payment-action{grid-column:span 2}.os-module .payment-entry-grid .payment-action .btn{width:100%}.os-module .item-entry-grid-v16 .item-notes{grid-column:span 3}.os-module .item-entry-grid-v16 .item-action{grid-column:span 1}}';
        echo '@media(max-width:760px){.os-title-row{align-items:stretch}.os-title-row h1{font-size:23px;width:100%}.os-title-row .spacer{display:none}.os-title-row>.btn{width:100%}.os-module .card{border-radius:18px}.os-module .card-body{padding:13px}.os-module .c1,.os-module .c2,.os-module .c3,.os-module .c4,.os-module .c5,.os-module .c6,.os-module .c7,.os-module .c8,.os-module .c9,.os-module .c10,.os-module .c11{grid-column:span 12}.os-module .filter-grid{grid-template-columns:1fr}.os-module .filter-grid .filter-search{grid-column:auto}.os-module .filter-actions{grid-column:auto;flex-wrap:wrap}.os-module .filter-actions .btn{flex:1 1 120px}.os-module .filter-actions .filter-count{width:100%;margin-left:0}.os-module .item-entry-grid,.os-module .payment-entry-grid{grid-template-columns:1fr}.os-module .item-entry-grid .item-product,.os-module .item-entry-grid .item-notes,.os-module .payment-entry-grid .payment-notes,.os-module .payment-entry-grid .payment-action{grid-column:auto}.os-module .totals{grid-template-columns:1fr}.os-module .os-form-actions{align-items:stretch;flex-direction:column}.os-module .os-adjustments{grid-template-columns:1fr 1fr;width:100%}.os-module .os-save-actions{width:100%}.os-module .os-save-actions .btn{width:100%}.os-module .inline{flex-wrap:wrap}.os-module .inline input,.os-module .inline select{flex:1 1 180px}.os-module .os-monitor-table-wrap{overflow:visible}.os-module .os-monitor-table thead{display:none}.os-module .os-monitor-table,.os-module .os-monitor-table tbody{display:block;width:100%}.os-module .os-monitor-table tr{display:block;border:1px solid #b9c5d1;border-radius:18px;background:#fff;box-shadow:0 8px 22px rgba(2,6,23,.055);padding:10px;margin-bottom:12px}.os-module .os-monitor-table tr.os-status-row{border-color:color-mix(in srgb,var(--os-row-status) 40%,#b9c5d1);border-left:5px solid var(--os-row-status);background:color-mix(in srgb,var(--os-row-status) 7%,#fff)}.os-module .os-monitor-table tr.os-status-row td{background:transparent!important}.os-module .os-monitor-table tr.os-status-row td:first-child{border-left:0!important;padding-left:4px}.os-module .os-monitor-table td{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;border:0!important;padding:7px 4px!important;text-align:right;white-space:normal}.os-module .os-monitor-table td::before{content:attr(data-label);font-weight:900;color:#64748b;text-align:left;min-width:92px}.os-module .os-monitor-table td.os-primary-cell{display:block;text-align:left}.os-module .os-monitor-table td.os-primary-cell::before{display:none}.os-module .os-monitor-table td.os-actions-cell{display:block;text-align:left}.os-module .os-monitor-table td.os-actions-cell::before{display:block;margin-bottom:8px}.os-module .os-monitor-table .os-row-actions{display:flex;gap:7px;flex-wrap:wrap}.os-module .os-monitor-table .os-row-actions .btn{flex:1 1 92px}.os-module .num{text-align:right}}';
        echo '</style>';
        echo '<div class="os-module"><main class="os-wrap">';
    }
}

if (!function_exists('os_page_end')) {
    function os_page_end()
    {
        echo '</main></div>';
        admin_footer();
    }
}
