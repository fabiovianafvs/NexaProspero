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

if (!function_exists('person_contacts_has_tags')) {
  /** Coluna de etiquetas instalada (migração 20261112_contatos_etiquetas_v1.sql). */
  function person_contacts_has_tags(PDO $pdo): bool {
    static $memo = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $memo)) return $memo[$key];
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'person_contacts' AND column_name = 'tags'");
      return $memo[$key] = (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
      return $memo[$key] = false;
    }
  }
}

if (!function_exists('person_contact_tags_list')) {
  /** Etiquetas em lista (aceita texto separado por vírgula/ponto e vírgula ou array). Máx. 8. */
  function person_contact_tags_list($tags): array {
    $parts = is_array($tags) ? $tags : preg_split('/[,;]+/', (string)$tags);
    $out = [];
    foreach ($parts ?: [] as $t) {
      $t = trim(preg_replace('/\s+/u', ' ', (string)$t) ?? '');
      if ($t === '') continue;
      $t = mb_substr($t, 0, 30);
      $k = mb_strtolower($t);
      if (!isset($out[$k])) $out[$k] = $t;
      if (count($out) >= 8) break;
    }
    return array_values($out);
  }
}

if (!function_exists('person_contact_tags_text')) {
  function person_contact_tags_text($tags): string {
    return mb_substr(implode(', ', person_contact_tags_list($tags)), 0, 255);
  }
}

if (!function_exists('person_contact_all_tags')) {
  /** Etiquetas já usadas na empresa (sugestões ao digitar). */
  function person_contact_all_tags(PDO $pdo, int $companyId): array {
    if (!person_contacts_ready($pdo) || !person_contacts_has_tags($pdo)) return [];
    $st = $pdo->prepare("SELECT tags FROM person_contacts WHERE company_id = ? AND tags IS NOT NULL AND tags <> '' LIMIT 2000");
    $st->execute([$companyId]);
    $all = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $t) {
      foreach (person_contact_tags_list((string)$t) as $x) $all[mb_strtolower($x)] = $x;
    }
    natcasesort($all);
    return array_slice(array_values($all), 0, 200);
  }
}

if (!function_exists('person_contact_tag_color')) {
  /** Cor fixa por etiqueta [fundo, texto] (mesma conta do assets/tag-input.js). */
  function person_contact_tag_color(string $tag): array {
    $pal = [['#dcfce7', '#166534'], ['#dbeafe', '#1e40af'], ['#fef3c7', '#92400e'], ['#fce7f3', '#9d174d'], ['#ede9fe', '#5b21b6'], ['#ffedd5', '#9a3412'], ['#ccfbf1', '#115e59'], ['#fee2e2', '#991b1b']];
    $h = 0;
    foreach (preg_split('//u', mb_strtolower(trim($tag)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) $h = ($h * 31 + mb_ord($ch, 'UTF-8')) % 4294967296;
    return $pal[$h % count($pal)];
  }
}

if (!function_exists('person_contact_tags_html')) {
  /** Etiquetas como "chips" coloridos (HTML já escapado). */
  function person_contact_tags_html($tags): string {
    $html = '';
    foreach (person_contact_tags_list($tags) as $t) {
      [$bg, $fg] = person_contact_tag_color($t);
      $html .= '<span class="nexa-tag" style="background:' . $bg . ';color:' . $fg . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</span> ';
    }
    return trim($html);
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
      if (person_contacts_has_tags($pdo)) { $sql .= ' OR c.tags LIKE ?'; $params[] = $like; }
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
    // Etiquetas: só grava quando o campo veio no formulário (outras telas não apagam).
    $tagsSql = '';
    if (person_contacts_has_tags($pdo) && array_key_exists('tags', $in)) {
      $tagsSql = ', tags=?';
      $row[] = person_contact_tags_text($in['tags']) ?: null;
    }
    if ($id > 0) {
      if (!person_contact_get($pdo, $companyId, $id)) throw new RuntimeException('Contato não encontrado.');
      $pdo->prepare('UPDATE person_contacts SET person_id=?, name=?, role=?, whatsapp=?, phone=?, email=?, notes=?, whatsapp_digits=?, phone_digits=?, is_primary=?, active=?' . $tagsSql . ', updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute(array_merge($row, [$companyId, $id]));
    } else {
      $pdo->prepare('INSERT INTO person_contacts (person_id, name, role, whatsapp, phone, email, notes, whatsapp_digits, phone_digits, is_primary, active' . ($tagsSql !== '' ? ', tags' : '') . ', company_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?' . ($tagsSql !== '' ? ',?' : '') . ',?,NOW())')
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
