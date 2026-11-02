-- Ajusta empresas antigas para usar apenas o prefixo do subdominio.
-- Exemplo correto: loja-exemplo
-- Exemplo incorreto: loja-exemplo-nexaprospero-com-br

UPDATE companies
SET subdomain = slug
WHERE subdomain IS NULL
   OR subdomain = ''
   OR subdomain <> slug;

UPDATE app_config ac
JOIN companies c ON c.id = ac.company_id
SET ac.cfg_json = JSON_SET(ac.cfg_json, '$.domain.subdomain', c.slug)
WHERE ac.cfg_key = 'business';
