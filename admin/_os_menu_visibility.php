<?php
/**
 * Carregue após _bootstrap.php no arquivo que monta o menu do admin.
 * Disponibiliza $osModuleEnabled para controlar menus da O.S.
 */
require_once __DIR__ . '/../config/os_module.php';
$osModuleEnabled = false;
try {
    $osModuleEnabled = os_module_enabled(os_db(), os_company_id());
} catch (Exception $e) {
    $osModuleEnabled = false;
}
