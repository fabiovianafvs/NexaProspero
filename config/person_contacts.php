<?php
declare(strict_types=1);

/**
 * Contatos das pessoas (clientes/fornecedores): quem chama, contador, financeiro,
 * compras etc. Tabela person_contacts (migração 20261112_contatos_pessoas_v1.sql).
 * Usados na aba Contatos do cadastro de pessoas, na busca de contato do Atendimento
 * WhatsApp e para reconhecer de qual cliente é a conversa que chega.
 */

if (!function_exists('person_contacts_ready')) {
  function person_contacts_ready(PDO $pdo): bool {
    static $memo = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $memo)) return $memo[$key];
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'person_contacts'");
      return $memo[$key] = (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
      return $memo[$key] = false;
    }
  }
}

if (!function_exists('person_contact_roles')) {
  /** Sugestões de função (o campo aceita qualquer texto). */
  function person_contact_roles(): array {
    return ['Responsável', 'Financeiro', 'Contador', 'Compras', 'Vendas', 'Gerente', 'Proprietário', 'Técnico', 'Recepção'];
  }
}

if (!function_exists('person_contact_digits')) {
  /** Telefone só com números e sem o 55 do Brasil (para comparar números). */
  function person_contact_digits($phone): string {
    $d = preg_replace('/\D+/', '', (string)$phone) ?? '';
    if (strlen($d) > 11 && str_starts_with($d, '55')) $d = substr($d, 2);
    return substr($d, 0, 20);
  }
}

if (!function_exists('person_contacts_by_person')) {
  function person_contacts_by_person(PDO $pdo, int $companyId, int $personId): array {
    if (!person_contacts_ready($pdo) || $personId <= 0) return [];
    $st = $pdo->prepare('SELECT * FROM person_contacts WHERE company_id = ? AND person_id = ? ORDER BY is_primary DESC, active DESC, name, id');
    $st->execute([$companyId, $personId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('person_contacts_search')) {
  /** Busca por nome do contato, nome/fantasia/documento da pessoa, telefone ou e-mail. */
  function person_contacts_search(PDO $pdo, int $companyId, string $q = '', int $limit = 100, bool $activeOnly = false): array {
    if (!person_contacts_ready($pdo)) return [];
    $sql = 'SELECT c.*, p.name AS person_name, p.fantasy_name AS person_fantasy, p.document AS person_document
      FROM person_contacts c
      LEFT JOIN finance_people p ON p.company_id = c.company_id AND p.id = c.person_id
      WHERE c.company_id = ?';
    $params = [$companyId];
    if ($activeOnly) $sql .= ' AND c.active = 1';
    $q = trim($q);
    if ($q !== '') {
      $like = '%' . mb_substr($q, 0, 80) . '%';
      $digits = person_contact_digits($q);
      $sql .= ' AND (c.name LIKE ? OR c.role LIKE ? OR c.email LIKE ? OR p.name LIKE ? OR p.fantasy_name LIKE ? OR p.document LIKE ?';
      array_push($params, $like, $like, $like, $like, $like, $like);
      if (strlen($digits) >= 4) {
        $sql .= ' OR c.whatsapp_digits LIKE ? OR c.phone_digits LIKE ?';
        array_push($params, '%' . $digits . '%', '%' . $digits . '%');
      }
      $sql .= ')';
    }
    $sql .= ' ORDER BY c.active DESC, p.name, c.is_primary DESC, c.name, c.id LIMIT ' . max(1, min(500, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('person_contact_get')) {
  function person_contact_get(PDO $pdo, int $companyId, int $id): ?array {
    if (!person_contacts_ready($pdo) || $id <= 0) return null;
    $st = $pdo->prepare('SELECT c.*, p.name AS person_name FROM person_contacts c LEFT JOIN finance_people p ON p.company_id = c.company_id AND p.id = c.person_id WHERE c.company_id = ? AND c.id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('person_contact_save')) {
  /** Inclui/altera um contato. Retorna o id. */
  function person_contact_save(PDO $pdo, int $companyId, array $in): int {
    if (!person_contacts_ready($pdo)) throw new RuntimeException('Cadastro de contatos não instalado. Aplique "Contatos das pessoas" no Atualizador do Banco.');
    $id = (int)($in['id'] ?? 0);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    if ($name === '') throw new RuntimeException('Informe o nome do contato.');
    $personId = (int)($in['person_id'] ?? 0) ?: null;
    if ($personId) {
      $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1');
      $st->execute([$companyId, $personId]);
      if (!$st->fetchColumn()) throw new RuntimeException('Pessoa não encontrada.');
    }
    $whatsapp = mb_substr(trim((string)($in['whatsapp'] ?? '')), 0, 30);
    $phone = mb_substr(trim((string)($in['phone'] ?? '')), 0, 30);
    $email = mb_substr(trim((string)($in['email'] ?? '')), 0, 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail inválido.');
    if ($whatsapp === '' && $phone === '' && $email === '') throw new RuntimeException('Informe o WhatsApp, o telefone ou o e-mail do contato.');
    $waDigits = person_contact_digits($whatsapp);
    if ($whatsapp !== '' && strlen($waDigits) < 10) throw new RuntimeException('WhatsApp inválido: informe com DDD.');
    $row = [
      $personId,
      $name,
      mb_substr(trim((string)($in['role'] ?? '')), 0, 60) ?: null,
      $whatsapp !== '' ? $whatsapp : null,
      $phone !== '' ? $phone : null,
      $email !== '' ? $email : null,
      mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null,
      $waDigits !== '' ? $waDigits : null,
      person_contact_digits($phone) ?: null,
      !empty($in['is_primary']) ? 1 : 0,
      array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1,
    ];
    if ($id > 0) {
      if (!person_contact_get($pdo, $companyId, $id)) throw new RuntimeException('Contato não encontrado.');
      $pdo->prepare('UPDATE person_contacts SET person_id=?, name=?, role=?, whatsapp=?, phone=?, email=?, notes=?, whatsapp_digits=?, phone_digits=?, is_primary=?, active=?, updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute(array_merge($row, [$companyId, $id]));
    } else {
      $pdo->prepare('INSERT INTO person_contacts (person_id, name, role, whatsapp, phone, email, notes, whatsapp_digits, phone_digits, is_primary, active, company_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute(array_merge($row, [$companyId]));
      $id = (int)$pdo->lastInsertId();
    }
    // Um só contato principal por pessoa.
    if ($personId && !empty($in['is_primary'])) {
      $pdo->prepare('UPDATE person_contacts SET is_primary = 0 WHERE company_id = ? AND person_id = ? AND id <> ?')->execute([$companyId, $personId, $id]);
    }
    return $id;
  }
}

if (!function_exists('person_contact_delete')) {
  function person_contact_delete(PDO $pdo, int $companyId, int $id): void {
    if (!person_contacts_ready($pdo)) return;
    $pdo->prepare('DELETE FROM person_contacts WHERE company_id = ? AND id = ?')->execute([$companyId, $id]);
    try { $pdo->prepare('UPDATE whatsapp_bot_sessions SET contact_id = NULL WHERE company_id = ? AND contact_id = ?')->execute([$companyId, $id]); } catch (Throwable $e) {}
  }
}

if (!function_exists('person_contact_find_by_phone')) {
  /** Contato ativo com este WhatsApp/telefone (com ou sem o 9º dígito). */
  function person_contact_find_by_phone(PDO $pdo, int $companyId, string $phone): ?array {
    if (!person_contacts_ready($pdo)) return null;
    $d = person_contact_digits($phone);
    if (strlen($d) < 10) return null;
    $cands = [$d];
    // Celular com e sem o 9 extra (o WhatsApp às vezes informa o número antigo de 8 dígitos).
    if (strlen($d) === 11 && $d[2] === '9') $cands[] = substr($d, 0, 2) . substr($d, 3);
    if (strlen($d) === 10) $cands[] = substr($d, 0, 2) . '9' . substr($d, 2);
    $in = implode(',', array_fill(0, count($cands), '?'));
    $st = $pdo->prepare("SELECT c.*, p.name AS person_name FROM person_contacts c LEFT JOIN finance_people p ON p.company_id = c.company_id AND p.id = c.person_id
      WHERE c.company_id = ? AND c.active = 1 AND (c.whatsapp_digits IN ($in) OR c.phone_digits IN ($in))
      ORDER BY (c.person_id IS NULL), c.is_primary DESC, c.id LIMIT 1");
    $st->execute(array_merge([$companyId], $cands, $cands));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}
