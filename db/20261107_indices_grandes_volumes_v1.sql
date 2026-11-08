-- Nexa Próspero - Índices para grandes volumes (contas a receber/pagar, movimento,
-- pedidos, documentos fiscais, produtos). Cada índice só é criado se a tabela e as
-- colunas existirem e se ainda não houver índice equivalente. Criação ONLINE
-- (ALGORITHM=INPLACE, LOCK=NONE): o sistema continua funcionando durante a criação.
-- Aplicar pelo Atualizador do Banco: primeiro na homologação, depois em produção.

-- finance_receivables (company_id,status,due_date)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_receivables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' AND FIND_IN_SET(column_name, 'company_id,status,due_date') > 0) = 3 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,status,due_date' OR nx_i.cols LIKE 'company_id,status,due_date,%') = 0, 'ALTER TABLE `finance_receivables` ADD INDEX `idx_nx_rec_status_due` (company_id,status,due_date), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_receivables (company_id,issue_date)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_receivables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' AND FIND_IN_SET(column_name, 'company_id,issue_date') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,issue_date' OR nx_i.cols LIKE 'company_id,issue_date,%') = 0, 'ALTER TABLE `finance_receivables` ADD INDEX `idx_nx_rec_issue` (company_id,issue_date), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_receivables (company_id,person_id,due_date)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_receivables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' AND FIND_IN_SET(column_name, 'company_id,person_id,due_date') > 0) = 3 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,person_id,due_date' OR nx_i.cols LIKE 'company_id,person_id,due_date,%') = 0, 'ALTER TABLE `finance_receivables` ADD INDEX `idx_nx_rec_person_due` (company_id,person_id,due_date), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_receivables (company_id,document_number)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_receivables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' AND FIND_IN_SET(column_name, 'company_id,document_number') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,document_number' OR nx_i.cols LIKE 'company_id,document_number,%') = 0, 'ALTER TABLE `finance_receivables` ADD INDEX `idx_nx_rec_document` (company_id,document_number), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_receivables (company_id,order_id)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_receivables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' AND FIND_IN_SET(column_name, 'company_id,order_id') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_receivables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,order_id' OR nx_i.cols LIKE 'company_id,order_id,%') = 0, 'ALTER TABLE `finance_receivables` ADD INDEX `idx_nx_rec_order` (company_id,order_id), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_payables (company_id,status,due_date)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_payables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_payables' AND FIND_IN_SET(column_name, 'company_id,status,due_date') > 0) = 3 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_payables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,status,due_date' OR nx_i.cols LIKE 'company_id,status,due_date,%') = 0, 'ALTER TABLE `finance_payables` ADD INDEX `idx_nx_pay_status_due` (company_id,status,due_date), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_payables (company_id,issue_date)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_payables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_payables' AND FIND_IN_SET(column_name, 'company_id,issue_date') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_payables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,issue_date' OR nx_i.cols LIKE 'company_id,issue_date,%') = 0, 'ALTER TABLE `finance_payables` ADD INDEX `idx_nx_pay_issue` (company_id,issue_date), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_payables (company_id,person_id,due_date)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_payables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_payables' AND FIND_IN_SET(column_name, 'company_id,person_id,due_date') > 0) = 3 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_payables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,person_id,due_date' OR nx_i.cols LIKE 'company_id,person_id,due_date,%') = 0, 'ALTER TABLE `finance_payables` ADD INDEX `idx_nx_pay_person_due` (company_id,person_id,due_date), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_payables (company_id,document_number)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_payables') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_payables' AND FIND_IN_SET(column_name, 'company_id,document_number') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_payables' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,document_number' OR nx_i.cols LIKE 'company_id,document_number,%') = 0, 'ALTER TABLE `finance_payables` ADD INDEX `idx_nx_pay_document` (company_id,document_number), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_boleto_charges (company_id,receivable_id)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_boleto_charges') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_boleto_charges' AND FIND_IN_SET(column_name, 'company_id,receivable_id') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_boleto_charges' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,receivable_id' OR nx_i.cols LIKE 'company_id,receivable_id,%') = 0, 'ALTER TABLE `finance_boleto_charges` ADD INDEX `idx_nx_boleto_receivable` (company_id,receivable_id), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_movements (company_id,bank_account_id,movement_date)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_movements') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_movements' AND FIND_IN_SET(column_name, 'company_id,bank_account_id,movement_date') > 0) = 3 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_movements' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,bank_account_id,movement_date' OR nx_i.cols LIKE 'company_id,bank_account_id,movement_date,%') = 0, 'ALTER TABLE `finance_movements` ADD INDEX `idx_nx_mov_bank_date` (company_id,bank_account_id,movement_date), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- finance_settlements (company_id,origin_type,origin_id,status)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'finance_settlements') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_settlements' AND FIND_IN_SET(column_name, 'company_id,origin_type,origin_id,status') > 0) = 4 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_settlements' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,origin_type,origin_id,status' OR nx_i.cols LIKE 'company_id,origin_type,origin_id,status,%') = 0, 'ALTER TABLE `finance_settlements` ADD INDEX `idx_nx_set_origin_status` (company_id,origin_type,origin_id,status), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- gestao_order_payments (company_id,receivable_id)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'gestao_order_payments') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'gestao_order_payments' AND FIND_IN_SET(column_name, 'company_id,receivable_id') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'gestao_order_payments' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,receivable_id' OR nx_i.cols LIKE 'company_id,receivable_id,%') = 0, 'ALTER TABLE `gestao_order_payments` ADD INDEX `idx_nx_gop_receivable` (company_id,receivable_id), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- pedidos (company_id,created_at)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pedidos') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND FIND_IN_SET(column_name, 'company_id,created_at') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'pedidos' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,created_at' OR nx_i.cols LIKE 'company_id,created_at,%') = 0, 'ALTER TABLE `pedidos` ADD INDEX `idx_nx_ped_created` (company_id,created_at), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- pedidos (company_id,status,created_at)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pedidos') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND FIND_IN_SET(column_name, 'company_id,status,created_at') > 0) = 3 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'pedidos' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,status,created_at' OR nx_i.cols LIKE 'company_id,status,created_at,%') = 0, 'ALTER TABLE `pedidos` ADD INDEX `idx_nx_ped_status_created` (company_id,status,created_at), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- fiscal_documents (company_id,model,issue_datetime)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fiscal_documents') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'fiscal_documents' AND FIND_IN_SET(column_name, 'company_id,model,issue_datetime') > 0) = 3 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'fiscal_documents' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,model,issue_datetime' OR nx_i.cols LIKE 'company_id,model,issue_datetime,%') = 0, 'ALTER TABLE `fiscal_documents` ADD INDEX `idx_nx_fdoc_model_issue` (company_id,model,issue_datetime), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- fiscal_document_participants (company_id,fiscal_document_id)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fiscal_document_participants') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'fiscal_document_participants' AND FIND_IN_SET(column_name, 'company_id,fiscal_document_id') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'fiscal_document_participants' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,fiscal_document_id' OR nx_i.cols LIKE 'company_id,fiscal_document_id,%') = 0, 'ALTER TABLE `fiscal_document_participants` ADD INDEX `idx_nx_fpart_doc` (company_id,fiscal_document_id), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- produtos (company_id,nome)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'produtos') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'produtos' AND FIND_IN_SET(column_name, 'company_id,nome') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'produtos' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,nome' OR nx_i.cols LIKE 'company_id,nome,%') = 0, 'ALTER TABLE `produtos` ADD INDEX `idx_nx_prod_company_nome` (company_id,nome), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- contract_generations (company_id,order_id)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'contract_generations') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contract_generations' AND FIND_IN_SET(column_name, 'company_id,order_id') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'contract_generations' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,order_id' OR nx_i.cols LIKE 'company_id,order_id,%') = 0, 'ALTER TABLE `contract_generations` ADD INDEX `idx_nx_cgen_order` (company_id,order_id), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

-- whatsapp_logs (company_id,created_at)
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'whatsapp_logs') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'whatsapp_logs' AND FIND_IN_SET(column_name, 'company_id,created_at') > 0) = 2 AND (SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'whatsapp_logs' GROUP BY index_name) AS nx_i WHERE nx_i.cols = 'company_id,created_at' OR nx_i.cols LIKE 'company_id,created_at,%') = 0, 'ALTER TABLE `whatsapp_logs` ADD INDEX `idx_nx_walog_company_created` (company_id,created_at), ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
