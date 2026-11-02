-- Nexa Próspero - Validação Multiempresa V2
-- Esta V2 não exige alteração estrutural no banco. Usa as tabelas criadas na Fundação V1.

SELECT g.id, g.name, g.operation_mode, g.is_active,
       COUNT(DISTINCT gc.company_id) AS empresas
FROM business_groups g
LEFT JOIN business_group_companies gc ON gc.business_group_id = g.id AND gc.is_active = 1
GROUP BY g.id, g.name, g.operation_mode, g.is_active
ORDER BY g.id;

SELECT bg.name AS grupo, bg.operation_mode, u.id AS user_id, u.name AS usuario,
       c.name AS empresa_origem, bgp.name AS perfil_integrado,
       bgu.is_active
FROM business_group_users bgu
JOIN business_groups bg ON bg.id = bgu.business_group_id
JOIN users u ON u.id = bgu.user_id
JOIN companies c ON c.id = u.company_id
LEFT JOIN business_group_profiles bgp ON bgp.id = bgu.group_profile_id
ORDER BY bg.name, u.name;

SELECT u.id AS user_id, u.name AS usuario, c.name AS empresa_autorizada,
       up.name AS perfil_empresa, uca.is_default, uca.is_active
FROM user_company_access uca
JOIN users u ON u.id = uca.user_id
JOIN companies c ON c.id = uca.company_id
LEFT JOIN user_profiles up ON up.id = uca.company_profile_id AND up.company_id = uca.company_id
WHERE uca.is_active = 1
ORDER BY u.name, uca.is_default DESC, c.name;
