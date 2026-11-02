<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';

$cid = (int)COMPANY_ID;
$sqlFile = __DIR__ . '/../db/patch_fiscal_documentos_compras_mysql_safe.sql';
$embeddedSql = <<<'FISCAL_SQL'
-- Patch seguro: Módulo Fiscal operacional + Compras/Radar XML
-- Execute no banco ativo do painel ou use admin/fiscal_instalar_operacional.php
-- Versão segura: substitui JSON por LONGTEXT para compatibilidade com MySQL/MariaDB antigos.

-- Patch proposto: Módulo Fiscal operacional + Compras/Radar XML
-- Projeto Nexa/Delivery integrado ao Próspero
-- Banco alvo deste patch: MySQL/MariaDB usado pelo painel web atual.
-- Revisado em 18/05/2026.



-- 1) Configuração fiscal por empresa: certificado, ambiente, CSC NFC-e, paths e preferências ACBr.
CREATE TABLE IF NOT EXISTS fiscal_company_config (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO', -- HOMOLOGACAO | PRODUCAO
  uf CHAR(2) NULL,
  city_ibge VARCHAR(7) NULL,
  crt VARCHAR(3) NULL,
  regime_name VARCHAR(80) NULL,
  nfe_enabled TINYINT(1) NOT NULL DEFAULT 0,
  nfce_enabled TINYINT(1) NOT NULL DEFAULT 0,
  nfse_enabled TINYINT(1) NOT NULL DEFAULT 0,
  fiscal_emission_enabled TINYINT(1) NOT NULL DEFAULT 1,
  save_order_enabled TINYINT(1) NOT NULL DEFAULT 1,
  acbr_mode VARCHAR(30) NOT NULL DEFAULT 'ACBRMONITOR', -- ACBRMONITOR | ACBRLIB | OUTRO
  acbr_host VARCHAR(120) NULL,
  acbr_port INT NULL,
  xml_out_path VARCHAR(255) NULL,
  xml_in_path VARCHAR(255) NULL,
  danfe_path VARCHAR(255) NULL,
  nfce_csc_id VARCHAR(20) NULL,
  nfce_csc_token VARCHAR(255) NULL,
  nfce_qrcode_version VARCHAR(10) NULL,
  nfse_provider VARCHAR(80) NULL,
  nfse_national_standard TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_company_config_company (company_id),
  KEY idx_fiscal_company_config_env (company_id, environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Certificados digitais A1/A3. Senha deve ser protegida/criptografada na aplicação.
CREATE TABLE IF NOT EXISTS fiscal_certificates (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  certificate_type VARCHAR(10) NOT NULL DEFAULT 'A1',
  display_name VARCHAR(120) NULL,
  file_path VARCHAR(255) NULL,
  serial_number VARCHAR(120) NULL,
  subject_name VARCHAR(255) NULL,
  valid_from DATE NULL,
  valid_until DATE NULL,
  password_secret TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fiscal_cert_company_active (company_id, is_active, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Séries e numeração por modelo fiscal.
CREATE TABLE IF NOT EXISTS fiscal_document_series (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  model VARCHAR(10) NOT NULL, -- 55, 65, NFSE, DPS/RPS
  series VARCHAR(10) NOT NULL,
  environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
  next_number BIGINT NOT NULL DEFAULT 1,
  last_number BIGINT NOT NULL DEFAULT 0,
  emission_type VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_series_company_model (company_id, model, series, environment),
  KEY idx_fiscal_series_active (company_id, model, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Natureza de operação / regras por tipo de emissão.
CREATE TABLE IF NOT EXISTS fiscal_operation_natures (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  code VARCHAR(30) NULL,
  description VARCHAR(180) NOT NULL,
  model VARCHAR(10) NULL,
  direction VARCHAR(10) NOT NULL DEFAULT 'SAIDA', -- ENTRADA | SAIDA
  tp_nf VARCHAR(1) NULL,
  finality VARCHAR(2) NULL,
  default_cfop_state VARCHAR(4) NULL,
  default_cfop_interstate VARCHAR(4) NULL,
  tax_profile_id INT NULL,
  move_stock TINYINT(1) NOT NULL DEFAULT 1,
  generate_finance TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fiscal_operation_company (company_id, direction, model, is_active),
  KEY idx_fiscal_operation_tax_profile (tax_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Cabeçalho unificado de documentos fiscais: NF-e, NFC-e e NFS-e.
CREATE TABLE IF NOT EXISTS fiscal_documents (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  direction VARCHAR(10) NOT NULL DEFAULT 'SAIDA', -- ENTRADA | SAIDA
  model VARCHAR(10) NOT NULL, -- 55 | 65 | NFSE
  series VARCHAR(10) NULL,
  number BIGINT NULL,
  access_key VARCHAR(60) NULL,
  protocol_number VARCHAR(60) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'DIGITACAO', -- DIGITACAO, PENDENTE, AUTORIZADA, CANCELADA, DENEGADA, REJEITADA, IMPORTADA
  sefaz_status_code VARCHAR(10) NULL,
  sefaz_status_message VARCHAR(255) NULL,
  environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
  operation_nature_id INT NULL,
  operation_nature VARCHAR(180) NULL,
  issue_datetime DATETIME NULL,
  exit_entry_datetime DATETIME NULL,
  competence_date DATE NULL,
  issuer_person_id INT NULL,
  recipient_person_id INT NULL,
  supplier_person_id INT NULL,
  customer_person_id INT NULL,
  total_products DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_discount DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_freight DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_other DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_icms DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_icms_st DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_fcp DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_ipi DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_iss DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_ibs_uf DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_ibs_mun DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_cbs DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_document DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_tax_approx DECIMAL(15,2) NOT NULL DEFAULT 0,
  xml_path VARCHAR(255) NULL,
  xml_raw LONGTEXT NULL,
  xml_hash VARCHAR(64) NULL,
  pdf_path VARCHAR(255) NULL,
  source_type VARCHAR(30) NULL, -- PEDIDO, PDV, COMPRA_XML, MANUAL, API
  source_id BIGINT NULL,
  finance_generated TINYINT(1) NOT NULL DEFAULT 0,
  stock_generated TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_documents_access_key (company_id, access_key),
  KEY idx_fiscal_documents_lookup (company_id, model, series, number),
  KEY idx_fiscal_documents_status (company_id, direction, status, issue_datetime),
  KEY idx_fiscal_documents_source (company_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Snapshot das partes do documento. Mantém histórico mesmo que o cadastro seja alterado.
CREATE TABLE IF NOT EXISTS fiscal_document_participants (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  role VARCHAR(20) NOT NULL, -- EMITENTE, DESTINATARIO, PRESTADOR, TOMADOR, TRANSPORTADOR
  person_id INT NULL,
  document VARCHAR(30) NULL,
  state_registration VARCHAR(30) NULL,
  municipal_registration VARCHAR(30) NULL,
  name VARCHAR(180) NULL,
  fantasy_name VARCHAR(180) NULL,
  email VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  zip_code VARCHAR(20) NULL,
  address VARCHAR(180) NULL,
  number VARCHAR(30) NULL,
  complement VARCHAR(120) NULL,
  district VARCHAR(100) NULL,
  city VARCHAR(120) NULL,
  city_ibge VARCHAR(7) NULL,
  state CHAR(2) NULL,
  country_code VARCHAR(4) NULL,
  country_name VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_doc_part_doc (fiscal_document_id, role),
  KEY idx_fiscal_doc_part_document (company_id, document)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Itens de mercadoria/serviço.
CREATE TABLE IF NOT EXISTS fiscal_document_items (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  item_number INT NOT NULL,
  product_id INT NULL,
  supplier_product_code VARCHAR(80) NULL,
  product_code VARCHAR(80) NULL,
  ean VARCHAR(20) NULL,
  ean_trib VARCHAR(20) NULL,
  description VARCHAR(255) NOT NULL,
  ncm VARCHAR(8) NULL,
  cest VARCHAR(12) NULL,
  cfop VARCHAR(4) NULL,
  unidade VARCHAR(20) NULL,
  quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_value DECIMAL(18,6) NOT NULL DEFAULT 0,
  total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  unidade_trib VARCHAR(20) NULL,
  quantity_trib DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_value_trib DECIMAL(18,6) NOT NULL DEFAULT 0,
  discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  freight_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  insurance_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  other_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  ind_total VARCHAR(1) NULL,
  order_item_id INT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_doc_item_number (fiscal_document_id, item_number),
  KEY idx_fiscal_doc_items_product (company_id, product_id),
  KEY idx_fiscal_doc_items_ncm_cest (company_id, ncm, cest),
  KEY idx_fiscal_doc_items_ean (company_id, ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Impostos detalhados por item, flexível para ICMS, ICMSST, FCP, IPI, PIS, COFINS, ISS, IBS, CBS e IS.
CREATE TABLE IF NOT EXISTS fiscal_document_item_taxes (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_item_id BIGINT NOT NULL,
  fiscal_document_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  tax_group VARCHAR(30) NOT NULL, -- ICMS, ICMSST, FCPST, IPI, PIS, COFINS, ISS, IBSUF, IBSMUN, CBS, IS
  tax_tag VARCHAR(40) NULL, -- ICMS00, ICMSSN102, PISAliq, PISNT etc.
  origin_code VARCHAR(2) NULL,
  cst VARCHAR(10) NULL,
  csosn VARCHAR(10) NULL,
  class_code VARCHAR(20) NULL,
  base_calc DECIMAL(15,2) NOT NULL DEFAULT 0,
  base_calc_st DECIMAL(15,2) NOT NULL DEFAULT 0,
  rate DECIMAL(12,4) NOT NULL DEFAULT 0,
  reduction_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
  mva_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  extra_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_item_taxes_item (fiscal_document_item_id, tax_group),
  KEY idx_fiscal_item_taxes_doc (fiscal_document_id, tax_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) Pagamentos informados em NF-e/NFC-e.
CREATE TABLE IF NOT EXISTS fiscal_document_payments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  payment_type VARCHAR(10) NULL, -- tPag
  payment_description VARCHAR(120) NULL,
  payment_indicator VARCHAR(2) NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  card_brand VARCHAR(20) NULL,
  authorization_code VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_doc_payments_doc (fiscal_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10) Duplicatas/vencimentos do XML e vínculo futuro com contas a pagar/receber.
CREATE TABLE IF NOT EXISTS fiscal_document_installments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  installment_number VARCHAR(20) NULL,
  due_date DATE NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  finance_payable_id INT NULL,
  finance_receivable_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fiscal_doc_installments_doc (fiscal_document_id),
  KEY idx_fiscal_doc_installments_due (company_id, due_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11) Eventos: autorização, rejeição, cancelamento, carta de correção, inutilização e manifestação.
CREATE TABLE IF NOT EXISTS fiscal_document_events (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT NULL,
  company_id INT NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  event_sequence INT NOT NULL DEFAULT 1,
  event_datetime DATETIME NULL,
  protocol_number VARCHAR(60) NULL,
  status_code VARCHAR(10) NULL,
  status_message VARCHAR(255) NULL,
  request_xml LONGTEXT NULL,
  response_xml LONGTEXT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_doc_events_doc (fiscal_document_id, event_type),
  KEY idx_fiscal_doc_events_company (company_id, event_type, event_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12) Fila de emissão/consulta/cancelamento para integração com ACBrMonitor/ACBrLib.
CREATE TABLE IF NOT EXISTS fiscal_emission_queue (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  fiscal_document_id BIGINT NULL,
  action VARCHAR(40) NOT NULL, -- EMITIR, CONSULTAR, CANCELAR, INUTILIZAR, IMPRIMIR, ENVIAR_EMAIL
  status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE', -- PENDENTE, PROCESSANDO, CONCLUIDO, ERRO
  priority INT NOT NULL DEFAULT 5,
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  request_payload LONGTEXT NULL,
  response_payload LONGTEXT NULL,
  error_message TEXT NULL,
  scheduled_at DATETIME NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fiscal_queue_status (company_id, status, priority, scheduled_at),
  KEY idx_fiscal_queue_doc (fiscal_document_id, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13) Radar XML: arquivos de entrada/compra encontrados ou enviados ao painel.
CREATE TABLE IF NOT EXISTS fiscal_xml_radar (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'UPLOAD', -- UPLOAD, PASTA, EMAIL, SEFAZ_DISTRIBUICAO
  file_name VARCHAR(255) NULL,
  file_path VARCHAR(255) NULL,
  xml_hash VARCHAR(64) NOT NULL,
  access_key VARCHAR(60) NULL,
  model VARCHAR(10) NULL,
  direction VARCHAR(10) NOT NULL DEFAULT 'ENTRADA',
  document_number BIGINT NULL,
  series VARCHAR(10) NULL,
  issue_datetime DATETIME NULL,
  issuer_document VARCHAR(30) NULL,
  issuer_name VARCHAR(180) NULL,
  recipient_document VARCHAR(30) NULL,
  recipient_name VARCHAR(180) NULL,
  total_document DECIMAL(15,2) NOT NULL DEFAULT 0,
  protocol_number VARCHAR(60) NULL,
  status_code VARCHAR(10) NULL,
  status_message VARCHAR(255) NULL,
  radar_status VARCHAR(30) NOT NULL DEFAULT 'NOVO', -- NOVO, ANALISADO, VINCULADO, IMPORTADO, IGNORADO, ERRO
  fiscal_document_id BIGINT NULL,
  purchase_invoice_id BIGINT NULL,
  xml_raw LONGTEXT NULL,
  error_message TEXT NULL,
  imported_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_xml_radar_hash (company_id, xml_hash),
  KEY idx_fiscal_xml_radar_access (company_id, access_key),
  KEY idx_fiscal_xml_radar_status (company_id, radar_status, issue_datetime),
  KEY idx_fiscal_xml_radar_emit (company_id, issuer_document)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_xml_radar_items (
  id BIGINT NOT NULL AUTO_INCREMENT,
  radar_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  item_number INT NOT NULL,
  supplier_product_code VARCHAR(80) NULL,
  ean VARCHAR(20) NULL,
  description VARCHAR(255) NOT NULL,
  ncm VARCHAR(8) NULL,
  cest VARCHAR(12) NULL,
  cfop VARCHAR(4) NULL,
  unidade VARCHAR(20) NULL,
  quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_value DECIMAL(18,6) NOT NULL DEFAULT 0,
  total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  matched_product_id INT NULL,
  match_status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE', -- PENDENTE, ENCONTRADO, NAO_ENCONTRADO, IGNORADO
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_xml_radar_items_radar (radar_id),
  KEY idx_fiscal_xml_radar_items_match (company_id, match_status, matched_product_id),
  KEY idx_fiscal_xml_radar_items_ean (company_id, ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14) Mapeamento produto do fornecedor -> produto interno; essencial para entrada de XML de compra.
CREATE TABLE IF NOT EXISTS fiscal_supplier_product_map (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  supplier_document VARCHAR(30) NOT NULL,
  supplier_product_code VARCHAR(80) NULL,
  supplier_ean VARCHAR(20) NULL,
  supplier_description VARCHAR(255) NULL,
  product_id INT NOT NULL,
  conversion_factor DECIMAL(18,6) NOT NULL DEFAULT 1,
  supplier_unit VARCHAR(20) NULL,
  internal_unit VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_supplier_product_map_lookup (company_id, supplier_document, supplier_product_code, supplier_ean),
  KEY idx_supplier_product_map_product (company_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15) Nota de compra importada/lançada a partir do XML.
CREATE TABLE IF NOT EXISTS purchase_invoices (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  fiscal_document_id BIGINT NULL,
  radar_id BIGINT NULL,
  supplier_person_id INT NULL,
  supplier_document VARCHAR(30) NULL,
  supplier_name VARCHAR(180) NULL,
  model VARCHAR(10) NOT NULL DEFAULT '55',
  series VARCHAR(10) NULL,
  number BIGINT NULL,
  access_key VARCHAR(60) NULL,
  issue_date DATE NULL,
  entry_date DATE NULL,
  total_products DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_document DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_discount DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_freight DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_other DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_icms DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_icms_st DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_fcp_st DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_ipi DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'DIGITACAO', -- DIGITACAO, CONFERIDA, IMPORTADA, CANCELADA
  generate_payables TINYINT(1) NOT NULL DEFAULT 1,
  payables_generated TINYINT(1) NOT NULL DEFAULT 0,
  stock_generated TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_purchase_invoice_access (company_id, access_key),
  KEY idx_purchase_invoice_supplier (company_id, supplier_document, issue_date),
  KEY idx_purchase_invoice_status (company_id, status, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_invoice_items (
  id BIGINT NOT NULL AUTO_INCREMENT,
  purchase_invoice_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  fiscal_document_item_id BIGINT NULL,
  item_number INT NOT NULL,
  product_id INT NULL,
  supplier_product_code VARCHAR(80) NULL,
  ean VARCHAR(20) NULL,
  description VARCHAR(255) NOT NULL,
  ncm VARCHAR(8) NULL,
  cest VARCHAR(12) NULL,
  cfop VARCHAR(4) NULL,
  unidade VARCHAR(20) NULL,
  quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_value DECIMAL(18,6) NOT NULL DEFAULT 0,
  total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  freight_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  other_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  cost_value DECIMAL(18,6) NOT NULL DEFAULT 0,
  internal_quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
  conversion_factor DECIMAL(18,6) NOT NULL DEFAULT 1,
  match_status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_purchase_invoice_item (purchase_invoice_id, item_number),
  KEY idx_purchase_invoice_items_product (company_id, product_id),
  KEY idx_purchase_invoice_items_match (company_id, match_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_invoice_installments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  purchase_invoice_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  installment_number VARCHAR(20) NULL,
  due_date DATE NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  finance_payable_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_purchase_invoice_installments_inv (purchase_invoice_id),
  KEY idx_purchase_invoice_installments_due (company_id, due_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16) Detalhes próprios de NF-e/NFC-e.
CREATE TABLE IF NOT EXISTS fiscal_nfe_details (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  cuf VARCHAR(2) NULL,
  cnf VARCHAR(8) NULL,
  cdv VARCHAR(1) NULL,
  tp_nf VARCHAR(1) NULL,
  id_dest VARCHAR(1) NULL,
  cmun_fg VARCHAR(7) NULL,
  tp_imp VARCHAR(1) NULL,
  tp_emis VARCHAR(1) NULL,
  fin_nfe VARCHAR(2) NULL,
  ind_final VARCHAR(1) NULL,
  ind_pres VARCHAR(2) NULL,
  proc_emi VARCHAR(2) NULL,
  ver_proc VARCHAR(40) NULL,
  mod_frete VARCHAR(2) NULL,
  additional_info_fisco TEXT NULL,
  additional_info_complementar TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_nfe_details_doc (fiscal_document_id),
  KEY idx_fiscal_nfe_details_company (company_id, cmun_fg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17) Detalhes próprios de NFS-e Nacional/DPS.
CREATE TABLE IF NOT EXISTS fiscal_nfse_details (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  pedido_id BIGINT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PREPARADA',
  environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
  nfse_number BIGINT NULL,
  dfse_number BIGINT NULL,
  dps_series VARCHAR(10) NULL,
  dps_number BIGINT NULL,
  dps_id VARCHAR(100) NULL,
  nfse_id VARCHAR(100) NULL,
  verification_code VARCHAR(80) NULL,
  service_city_ibge VARCHAR(7) NULL,
  incidence_city_ibge VARCHAR(7) NULL,
  national_service_code VARCHAR(10) NULL,
  municipal_service_code VARCHAR(30) NULL,
  nbs_code VARCHAR(20) NULL,
  service_description TEXT NULL,
  iss_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
  iss_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  iss_withheld TINYINT(1) NOT NULL DEFAULT 0,
  ibs_cbs_cst VARCHAR(10) NULL,
  ibs_cbs_class_code VARCHAR(20) NULL,
  operation_indicator VARCHAR(30) NULL,
  dps_xml LONGTEXT NULL,
  signed_dps_xml LONGTEXT NULL,
  response_xml LONGTEXT NULL,
  request_payload LONGTEXT NULL,
  response_payload LONGTEXT NULL,
  request_hash VARCHAR(64) NULL,
  api_http_status INT NULL,
  api_error_code VARCHAR(40) NULL,
  api_error_message TEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NULL,
  authorized_at DATETIME NULL,
  consulted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_nfse_details_doc (fiscal_document_id),
  UNIQUE KEY ux_nfse_details_order (company_id, pedido_id, environment),
  UNIQUE KEY ux_nfse_details_dps (company_id, environment, dps_series, dps_number),
  KEY idx_fiscal_nfse_details_lookup (company_id, nfse_number, dps_number),
  KEY idx_nfse_details_dps_id (company_id, dps_id),
  KEY idx_nfse_details_status (company_id, environment, status, last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18) Documentos referenciados: refNFe, refNF, NF-e complementar/devolução etc.
CREATE TABLE IF NOT EXISTS fiscal_document_references (
  id BIGINT NOT NULL AUTO_INCREMENT,
  fiscal_document_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  reference_type VARCHAR(20) NOT NULL DEFAULT 'NFE',
  reference_key VARCHAR(60) NULL,
  model VARCHAR(10) NULL,
  series VARCHAR(10) NULL,
  number BIGINT NULL,
  issue_month VARCHAR(7) NULL,
  document VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_doc_refs_doc (fiscal_document_id),
  KEY idx_fiscal_doc_refs_key (company_id, reference_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





-- 21) Inutilizações homologadas/rejeitadas de NF-e e NFC-e.
CREATE TABLE IF NOT EXISTS fiscal_inutilizations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  model VARCHAR(2) NOT NULL,
  series INT NOT NULL,
  start_number BIGINT NOT NULL,
  end_number BIGINT NOT NULL,
  fiscal_year CHAR(2) NOT NULL,
  environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
  justification VARCHAR(255) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
  status_code VARCHAR(10) NULL,
  status_message VARCHAR(255) NULL,
  protocol_number VARCHAR(60) NULL,
  event_datetime DATETIME NULL,
  request_xml LONGTEXT NULL,
  response_xml LONGTEXT NULL,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fiscal_inut_company_date (company_id, event_datetime, created_at),
  KEY idx_fiscal_inut_range (company_id, model, series, fiscal_year, start_number, end_number),
  KEY idx_fiscal_inut_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

FISCAL_SQL;
$results = [];
$errors = [];
$dbName = '';
try { $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) { $dbName = 'indisponível: ' . $e->getMessage(); }

function fiscal_install_split_sql(string $sql): array {
  $sql = preg_replace('~/\*.*?\*/~s', '', $sql) ?? $sql;
  $lines = [];
  foreach (preg_split('/\R/', $sql) as $line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) continue;
    $lines[] = $line;
  }
  $sql = implode("\n", $lines);
  $parts = array_filter(array_map('trim', explode(';', $sql)), static fn($s) => $s !== '');
  return array_values($parts);
}

function fiscal_install_table_name(string $stmt): string {
  if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $stmt, $m)) return $m[1];
  return '';
}

function fiscal_install_seed_defaults(PDO $pdo, int $companyId): void {
  if ($companyId <= 0) return;
  if (fiscal_table_exists($pdo, 'fiscal_company_config')) {
    $st = $pdo->prepare("INSERT IGNORE INTO fiscal_company_config
      (company_id, environment, uf, city_ibge, acbr_mode, acbr_host, acbr_port, nfce_qrcode_version, nfse_national_standard, created_at)
      VALUES (?, 'HOMOLOGACAO', 'MG', '3131703', 'ACBRMONITOR', '127.0.0.1', 3434, '2.00', 1, NOW())");
    $st->execute([$companyId]);
  }
  if (fiscal_table_exists($pdo, 'fiscal_document_series')) {
    $st = $pdo->prepare("INSERT IGNORE INTO fiscal_document_series
      (company_id, model, series, environment, next_number, last_number, emission_type, is_active, notes, created_at)
      VALUES (?, ?, '1', 'HOMOLOGACAO', 1, 0, 'NORMAL', 1, ?, NOW())");
    $st->execute([$companyId, '55', 'Série inicial NF-e criada pelo instalador fiscal.']);
    $st->execute([$companyId, '65', 'Série inicial NFC-e criada pelo instalador fiscal.']);
    $st->execute([$companyId, 'NFSE', 'Série inicial NFS-e criada pelo instalador fiscal.']);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $usingEmbeddedSql = false;
    if (is_file($sqlFile)) {
      $sql = (string)file_get_contents($sqlFile);
      if (trim($sql) === '') {
        $sql = $embeddedSql;
        $usingEmbeddedSql = true;
      }
    } else {
      $sql = $embeddedSql;
      $usingEmbeddedSql = true;
    }
    if (trim($sql) === '') throw new RuntimeException('Patch SQL indisponível. Envie o arquivo db/patch_fiscal_documentos_compras_mysql_safe.sql ou use a versão embutida do instalador.');
    if ($usingEmbeddedSql) {
      $results[] = ['table' => 'patch embutido', 'status' => 'OK', 'message' => 'Arquivo SQL externo não encontrado. Executando patch embutido no instalador.'];
    }
    foreach (fiscal_install_split_sql($sql) as $stmt) {
      $table = fiscal_install_table_name($stmt);
      try {
        $pdo->exec($stmt);
        $results[] = ['table' => $table ?: 'SQL', 'status' => 'OK', 'message' => $table ? 'Tabela criada/validada.' : 'Comando executado.'];
      } catch (Throwable $e) {
        $msg = $e->getMessage();
        $results[] = ['table' => $table ?: 'SQL', 'status' => 'ERRO', 'message' => $msg];
        $errors[] = ($table ? $table . ': ' : '') . $msg;
      }
    }
    if (!$errors && !empty($_POST['seed_defaults'])) {
      try {
        fiscal_install_seed_defaults($pdo, $cid);
        $results[] = ['table' => 'configuração inicial', 'status' => 'OK', 'message' => 'Configuração e séries iniciais criadas quando estavam ausentes.'];
      } catch (Throwable $e) {
        $errors[] = 'Configuração inicial: ' . $e->getMessage();
        $results[] = ['table' => 'configuração inicial', 'status' => 'ERRO', 'message' => $e->getMessage()];
      }
    }
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

$schema = fiscal_op_schema_status($pdo);
$missing = array_values(array_filter($schema, static fn($r) => empty($r['exists'])));

fiscal_admin_start('Fiscal - Instalar tabelas operacionais', 'fiscal');
fiscal_page_header('Instalar tabelas operacionais', 'Cria/valida as tabelas de documentos fiscais, Radar XML, compras e integração ACBr usando a mesma conexão do painel.', [
  ['label' => 'Voltar às configurações', 'href' => 'fiscal_configuracoes.php', 'class' => 'btn'],
  ['label' => 'Radar XML', 'href' => 'fiscal_radar_xml.php', 'class' => 'btn btn-primary'],
]);
fiscal_tabs('fiscal_configuracoes');
?>

<div class="fiscal-grid-4">
  <div class="fiscal-kpi"><span>Banco ativo</span><strong style="font-size:18px"><?= fiscal_h($dbName) ?></strong></div>
  <div class="fiscal-kpi"><span>Empresa</span><strong><?= (int)$cid ?></strong></div>
  <div class="fiscal-kpi"><span>Tabelas OK</span><strong><?= count($schema) - count($missing) ?>/<?= count($schema) ?></strong></div>
  <div class="fiscal-kpi"><span>Pendentes</span><strong><?= count($missing) ?></strong></div>
</div>

<?php if ($errors): ?>
  <div class="msg err" style="margin-top:14px">
    <b>Alguns comandos não foram executados.</b><br>
    <?= fiscal_h(implode(' | ', array_slice($errors, 0, 6))) ?><?= count($errors) > 6 ? ' ...' : '' ?>
  </div>
<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <div class="msg ok" style="margin-top:14px">Instalação concluída. Atualize a tela de configurações para conferir os cadastros.</div>
<?php endif; ?>

<div class="fiscal-card" style="margin-top:14px">
  <h2>Executar instalação</h2>
  <p class="fiscal-muted">
    Use este instalador quando o SQL foi importado em outro banco ou não criou as tabelas. Ele executa o patch no banco exibido acima: <b><?= fiscal_h($dbName) ?></b>.
    Se o arquivo SQL externo não existir na pasta <code>db</code>, esta versão usa o patch embutido no próprio instalador.
  </p>
  <form method="post" class="fiscal-form">
    <label class="fiscal-checkline" style="margin:0 0 12px 0">
      <input type="checkbox" name="seed_defaults" value="1" checked>
      Criar configuração inicial e séries padrão de homologação quando estiverem ausentes
    </label>
    <div class="fiscal-form-actions" style="justify-content:flex-start">
      <button class="btn btn-primary" onclick="return confirm('Confirma criar/validar as tabelas operacionais fiscais no banco <?= fiscal_h($dbName) ?>?')">Instalar / validar tabelas agora</button>
      <a class="btn" href="fiscal_configuracoes.php">Cancelar</a>
    </div>
  </form>
</div>

<?php if ($results): ?>
<div class="fiscal-card">
  <h2>Resultado da execução</h2>
  <div class="table-wrap"><table class="fiscal-table fiscal-grid-table"><thead><tr><th>Tabela/comando</th><th>Status</th><th>Mensagem</th></tr></thead><tbody>
    <?php foreach ($results as $r): ?>
      <tr>
        <td data-label="Tabela"><b><?= fiscal_h($r['table']) ?></b></td>
        <td data-label="Status"><span class="fiscal-badge <?= $r['status'] === 'OK' ? '' : 'off' ?>"><?= fiscal_h($r['status']) ?></span></td>
        <td data-label="Mensagem"><span class="fiscal-cell-clip"><?= fiscal_h($r['message']) ?></span></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="fiscal-card">
  <h2>Validação atual das tabelas</h2>
  <div class="table-wrap"><table class="fiscal-table fiscal-grid-table"><thead><tr><th>Tabela</th><th>Status</th><th>Registros</th></tr></thead><tbody>
    <?php foreach ($schema as $r): ?>
      <tr>
        <td data-label="Tabela"><b><?= fiscal_h($r['table']) ?></b><br><span class="fiscal-muted"><?= fiscal_h($r['label']) ?></span></td>
        <td data-label="Status"><span class="fiscal-badge <?= $r['exists'] ? '' : 'off' ?>"><?= $r['exists'] ? 'OK' : 'Ausente' ?></span></td>
        <td data-label="Registros"><?= $r['count'] === null ? '-' : (int)$r['count'] ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php fiscal_admin_end(); ?>
