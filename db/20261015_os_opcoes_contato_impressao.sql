-- Migration de contato O.S. Desktop/Mobile. Sem dados destrutivos.
ALTER TABLE os_settings
  ADD COLUMN auto_fill_contact TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN require_contact_name TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN require_contact_phone TINYINT(1) NOT NULL DEFAULT 0;
