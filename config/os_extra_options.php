<?php
declare(strict_types=1);
/** Recursos adicionais da O.S. por empresa; estrutura aplicada por migration controlada. */
if (!function_exists('os_extra_options')) {
function os_extra_options(PDO $pdo, int $companyId): array {
    $defaults = [
        'auto_fill_contact'=>1, 'require_contact_name'=>0,
        'require_contact_phone'=>0,
    ];
    try {
        $q=$pdo->prepare('SELECT auto_fill_contact,require_contact_name,require_contact_phone FROM os_settings WHERE company_id=? LIMIT 1');
        $q->execute([$companyId]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if ($r) foreach($defaults as $key=>$default) $defaults[$key]=(int)$r[$key];
    } catch (PDOException $e) {
        // Instalações ainda sem migration: comportamento anterior sem habilitar novos controles.
        error_log('[OS_EXTRA_OPTIONS] Migration ainda não aplicada: '.$e->getCode());
    }
    return $defaults;
}
}
