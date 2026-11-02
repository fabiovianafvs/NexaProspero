<?php
/** Visibilidade por empresa; não faz DDL e preserva o fluxo anterior sem migration. */
if (!function_exists('os_step_visibility')) {
function os_step_visibility(PDO $pdo, int $companyId): array {
    $defaults = ['show_vehicle_step' => 1, 'show_damage_step' => 1, 'migration_ready' => false];
    try {
        $st = $pdo->prepare('SELECT show_vehicle_step, show_damage_step FROM os_settings WHERE company_id=? LIMIT 1');
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $defaults['show_vehicle_step'] = (int)$row['show_vehicle_step'];
            $defaults['show_damage_step'] = (int)$row['show_damage_step'];
            $defaults['migration_ready'] = true;
        }
    } catch (PDOException $e) {
        // Compatibilidade: antes da migration, manter etapas ativas para clientes existentes.
        error_log('[OS_STEP_VISIBILITY] Configuração de etapas indisponível: '.$e->getCode());
    }
    return $defaults;
}
}
