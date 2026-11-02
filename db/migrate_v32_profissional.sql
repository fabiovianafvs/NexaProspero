-- Nexa Catálogo v32 - identidade visual, capa e domínios
-- Observação: a tabela de empresas neste projeto se chama companies.

START TRANSACTION;

ALTER TABLE companies
  ADD COLUMN subdomain VARCHAR(100) NULL AFTER slug,
  ADD COLUMN custom_domain VARCHAR(190) NULL AFTER subdomain;

UPDATE companies SET subdomain = slug WHERE subdomain IS NULL OR subdomain = '';

ALTER TABLE companies
  ADD UNIQUE KEY uk_companies_subdomain (subdomain),
  ADD UNIQUE KEY uk_companies_custom_domain (custom_domain);

COMMIT;
