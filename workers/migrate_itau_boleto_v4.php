<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/billing_module.php';

billing_ensure_schema($pdo);
$checks = [
  ['finance_bank_billing_configs','itau_species_code'],
  ['finance_bank_billing_configs','itau_nosso_numero_next'],
  ['finance_boleto_charges','api_http_status'],
  ['finance_boleto_charges','api_correlation_id'],
  ['finance_boleto_charges','dac_titulo'],
  ['finance_boleto_charges','pix_key'],
  ['finance_boleto_charges','pix_txid'],
  ['finance_boleto_charges','pix_emv'],
  ['finance_boleto_charges','pix_qr_base64'],
  ['finance_boleto_charges','pix_location_id'],
  ['finance_boleto_charges','pix_location'],
  ['finance_boleto_charges','simulation_request_payload'],
  ['finance_boleto_charges','simulation_response_payload'],
  ['finance_boleto_charges','simulation_http_status'],
  ['finance_boleto_charges','simulated_at'],
];
foreach($checks as [$table,$column]){
  echo (billing_has_column($pdo,$table,$column)?'[OK] ':'[FALHA] ').$table.'.'.$column.PHP_EOL;
}
