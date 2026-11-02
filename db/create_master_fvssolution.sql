-- Nexa Próspero - criação/atualização do usuário master da plataforma
-- Executar no banco principal nexaprospero.
-- O e-mail é único na tabela users; este comando atualiza o cadastro caso já exista.

INSERT INTO users (company_id, name, email, password_hash, role, is_active)
VALUES (NULL, 'FVS Solution Master', 'fvssolution@gmail.com', '$2y$12$KTz8sOTHJEGzwtKfDVUlteAhCTi5/uVz8FOwGHUpNNvQvt86ZB9XO', 'platform_admin', 1)
ON DUPLICATE KEY UPDATE
  company_id = NULL,
  name = VALUES(name),
  password_hash = VALUES(password_hash),
  role = 'platform_admin',
  is_active = 1;
