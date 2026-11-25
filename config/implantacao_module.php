<?php
declare(strict_types=1);

/**
 * CRM - Trilha de Implantação (db/20261125_crm_implantacao_v1.sql).
 *
 * Cadastros: etapas (impl_etapas, com cor), status do cliente (impl_status, com cor e tipo
 * AGENDADO/ANDAMENTO/CONCLUIDO), tarefas ligadas à etapa (impl_tarefas) e trilhas
 * (impl_trilhas + impl_trilha_tarefas).
 * Implantação = cliente de Pessoas (finance_people) seguindo uma trilha (impl_implantacoes);
 * as tarefas são COPIADAS da trilha (impl_impl_tarefas), então mudar a trilha depois não mexe
 * nas implantações abertas. Tarefa executada = done_at preenchido.
 * Relatório de atendimento técnico (impl_relatorios + impl_relatorio_tarefas): ao CONFIRMAR,
 * as tarefas marcadas ficam executadas. Assinatura do cliente: no celular do técnico (LOCAL)
 * ou por link (LINK; só o hash do código fica no banco). Fotos dos equipamentos instalados
 * (impl_fotos) entram no relatório de conclusão (status do tipo CONCLUIDO).
 * Arquivos (fotos/assinaturas) ficam fora da pasta pública em
 * /var/www/nexa_private/implantacao/<pasta do app>/ (ou env NEXA_IMPLANTACAO_DIR).
 * Toda consulta filtra por company_id.
 */

if (!defined('IMPL_CONFIG_KEY')) define('IMPL_CONFIG_KEY', 'implantacao');

if (!function_exists('impl_h')) {
  function impl_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('impl_ready')) {
  /** Migração "CRM - Trilha de Implantação v1" aplicada? */
  function impl_ready(PDO $pdo): bool {
    static $memo = [];
    $k = spl_object_id($pdo);
    if (isset($memo[$k])) return $memo[$k];
    try { $pdo->query('SELECT 1 FROM impl_fotos LIMIT 0'); $pdo->query('SELECT 1 FROM impl_relatorio_tarefas LIMIT 0'); return $memo[$k] = true; }
    catch (Throwable $e) { return $memo[$k] = false; }
  }
}

if (!function_exists('impl_status_tipos')) {
  function impl_status_tipos(): array { return ['AGENDADO' => 'Agendado', 'ANDAMENTO' => 'Em andamento', 'CONCLUIDO' => 'Concluído']; }
}

if (!function_exists('impl_cor')) {
  function impl_cor(string $c, string $default = '#2563eb'): string {
    $c = trim($c);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $default;
  }
}

if (!function_exists('impl_config_defaults')) {
  function impl_config_defaults(): array {
    return [
      'aviso_equipamentos' => 'A instalação e a configuração de impressoras, computadores, hardwares e periféricos não fazem parte dos atendimentos de suporte aos sistemas. Caso a estrutura física seja alterada (troca de lugar, de equipamento ou de rede) e sejam necessárias novas configurações ou instalações, esses serviços serão cobrados à parte, de acordo com a tabela de preços vigente.',
    ];
  }
  function impl_config(PDO $pdo, int $companyId): array {
    $cfg = function_exists('config_store_get') ? (array)(config_store_get($pdo, $companyId, IMPL_CONFIG_KEY) ?? []) : [];
    return array_merge(impl_config_defaults(), array_intersect_key($cfg, impl_config_defaults()));
  }
  function impl_config_save(PDO $pdo, int $companyId, array $in): void {
    $aviso = trim(str_replace("\r\n", "\n", (string)($in['aviso_equipamentos'] ?? '')));
    if ($aviso === '') $aviso = impl_config_defaults()['aviso_equipamentos'];
    if (mb_strlen($aviso) > 2000) throw new RuntimeException('O texto do aviso pode ter no máximo 2.000 caracteres.');
    config_store_set($pdo, $companyId, IMPL_CONFIG_KEY, ['aviso_equipamentos' => $aviso]);
  }
}

/* ---------------------------------------------------------------- arquivos */

if (!function_exists('impl_files_root')) {
  function impl_files_root(): string {
    $app = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $base = trim((string)(getenv('NEXA_IMPLANTACAO_DIR') ?: ''));
    if ($base === '') $base = dirname($app) . '/nexa_private/implantacao/' . basename($app);
    if ($base[0] !== '/') throw new RuntimeException('A pasta de arquivos da implantação precisa ser um caminho absoluto.');
    if (!is_dir($base) && !@mkdir($base, 0700, true)) throw new RuntimeException('Não foi possível criar a pasta de arquivos da implantação.');
    $root = realpath($base);
    if ($root === false || !is_writable($root)) throw new RuntimeException('A pasta de arquivos da implantação está sem permissão de gravação.');
    if ($root === $app || str_starts_with($root, $app . '/')) throw new RuntimeException('A pasta de arquivos da implantação não pode ficar dentro da pasta pública.');
    return $root;
  }
}

if (!function_exists('impl_file_path')) {
  /** Caminho de um arquivo gravado (só aceita o formato que o próprio módulo gera). */
  function impl_file_path(int $companyId, string $rel): ?string {
    if (!preg_match('#^' . $companyId . '/(fotos|assinaturas)/[a-f0-9]{24}\.(jpg|png|webp)$#', $rel)) return null;
    $path = impl_files_root() . '/' . $rel;
    return is_file($path) ? $path : null;
  }
}

if (!function_exists('impl_store_bytes')) {
  function impl_store_bytes(int $companyId, string $kind, string $ext, string $bytes): string {
    if (!in_array($kind, ['fotos', 'assinaturas'], true)) throw new RuntimeException('Tipo de arquivo inválido.');
    $dir = impl_files_root() . '/' . $companyId . '/' . $kind;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('Não foi possível criar a pasta de arquivos.');
    $rel = $companyId . '/' . $kind . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (@file_put_contents(impl_files_root() . '/' . $rel, $bytes, LOCK_EX) === false) throw new RuntimeException('Não foi possível gravar o arquivo.');
    return $rel;
  }
}

if (!function_exists('impl_store_photo')) {
  /** Foto enviada ($_FILES): só JPG/PNG/WEBP de verdade, até 10 MB. */
  function impl_store_photo(int $companyId, array $file): string {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'A foto passou do tamanho permitido pelo servidor.' : 'Selecione a foto.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Envio de foto inválido.');
    if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('A foto pode ter no máximo 10 MB.');
    $info = @getimagesize($tmp);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][(string)($info['mime'] ?? '')] ?? '';
    if ($ext === '') throw new RuntimeException('Envie uma foto em JPG, PNG ou WEBP.');
    $bytes = (string)file_get_contents($tmp);
    return impl_store_bytes($companyId, 'fotos', $ext, $bytes);
  }
}

if (!function_exists('impl_store_signature')) {
  /** Assinatura desenhada na tela: data URL PNG, até 600 KB. */
  function impl_store_signature(int $companyId, string $dataUrl): string {
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) throw new RuntimeException('Assinatura inválida. Desenhe a assinatura no quadro.');
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || strlen($bytes) < 200) throw new RuntimeException('Desenhe a assinatura no quadro antes de salvar.');
    if (strlen($bytes) > 600 * 1024) throw new RuntimeException('A imagem da assinatura ficou grande demais.');
    if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('Assinatura inválida.');
    return impl_store_bytes($companyId, 'assinaturas', 'png', $bytes);
  }
}

/* --------------------------------------------------------------- cadastros */

if (!function_exists('impl_seed_status')) {
  /** Status padrão (Agendado, Em andamento, Concluído) na primeira vez. Só INSERT. */
  function impl_seed_status(PDO $pdo, int $companyId): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM impl_status WHERE company_id=?');
    $st->execute([$companyId]);
    if ((int)$st->fetchColumn() > 0) return;
    $ins = $pdo->prepare('INSERT INTO impl_status (company_id, nome, cor, tipo, ordem) VALUES (?,?,?,?,?)');
    foreach ([['Agendado', '#2563eb', 'AGENDADO', 1], ['Em andamento', '#16a34a', 'ANDAMENTO', 2], ['Concluído', '#64748b', 'CONCLUIDO', 3]] as $s) {
      $ins->execute([$companyId, $s[0], $s[1], $s[2], $s[3]]);
    }
  }
}

if (!function_exists('impl_seed_modelo')) {
  /** Modelo sugerido (etapas, tarefas e 2 trilhas). Só quando ainda não há etapas. */
  function impl_seed_modelo(PDO $pdo, int $companyId): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM impl_etapas WHERE company_id=?');
    $st->execute([$companyId]);
    if ((int)$st->fetchColumn() > 0) throw new RuntimeException('O modelo só pode ser carregado quando ainda não há etapas cadastradas.');
    $modelo = [
      ['Primeiros Passos', '#2563eb', ['Conferência dos dados importados', 'Entrada de notas', 'Abertura do caixa', 'Vendas', 'Emissão de NFC-e', 'Emissão de NF-e', 'Fechamento de caixa']],
      ['Cadastros e Operações', '#f97316', ['Cadastro de produtos', 'Cadastro de serviços', 'Geração de orçamentos', 'Envio de orçamentos via WhatsApp e e-mail', 'Geração de ordens de serviço']],
      ['Controle e Conferência', '#9333ea', ['Conferência de estoque', 'Balanço de estoque']],
      ['Finalização', '#16a34a', ['Revisão do treinamento']],
    ];
    $pdo->beginTransaction();
    try {
      $insE = $pdo->prepare('INSERT INTO impl_etapas (company_id, nome, cor, ordem) VALUES (?,?,?,?)');
      $insT = $pdo->prepare('INSERT INTO impl_tarefas (company_id, etapa_id, nome, ordem) VALUES (?,?,?,?)');
      $ids = []; $importacao = 0;
      foreach ($modelo as $ei => [$nome, $cor, $tarefas]) {
        $insE->execute([$companyId, $nome, $cor, $ei + 1]);
        $eid = (int)$pdo->lastInsertId();
        foreach ($tarefas as $ti => $t) {
          $insT->execute([$companyId, $eid, $t, $ti + 1]);
          $tid = (int)$pdo->lastInsertId();
          if ($t === 'Conferência dos dados importados') $importacao = $tid; else $ids[] = $tid;
        }
      }
      $insTr = $pdo->prepare('INSERT INTO impl_trilhas (company_id, nome, descricao) VALUES (?,?,?)');
      $insTT = $pdo->prepare('INSERT INTO impl_trilha_tarefas (company_id, trilha_id, tarefa_id, ordem) VALUES (?,?,?,?)');
      $insTr->execute([$companyId, 'Novo cliente | Nova base de dados', 'Cliente começando do zero no sistema.']);
      $tr1 = (int)$pdo->lastInsertId();
      foreach ($ids as $i => $tid) $insTT->execute([$companyId, $tr1, $tid, $i + 1]);
      $insTr->execute([$companyId, 'Cliente de Importação | Migração de Sistema', 'Cliente vindo de outro sistema, com dados importados.']);
      $tr2 = (int)$pdo->lastInsertId();
      foreach (array_merge([$importacao], $ids) as $i => $tid) $insTT->execute([$companyId, $tr2, $tid, $i + 1]);
      $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    impl_seed_status($pdo, $companyId);
  }
}

if (!function_exists('impl_etapas')) {
  function impl_etapas(PDO $pdo, int $companyId, bool $soAtivas = false): array {
    $st = $pdo->prepare('SELECT e.*, (SELECT COUNT(*) FROM impl_tarefas t WHERE t.company_id=e.company_id AND t.etapa_id=e.id AND t.ativo=1) AS tarefas
      FROM impl_etapas e WHERE e.company_id=?' . ($soAtivas ? ' AND e.ativo=1' : '') . ' ORDER BY e.ativo DESC, e.ordem, e.nome');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  function impl_status_list(PDO $pdo, int $companyId, bool $soAtivos = false): array {
    impl_seed_status($pdo, $companyId);
    $st = $pdo->prepare('SELECT * FROM impl_status WHERE company_id=?' . ($soAtivos ? ' AND ativo=1' : '') . ' ORDER BY ativo DESC, ordem, nome');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  function impl_tarefas(PDO $pdo, int $companyId, bool $soAtivas = false): array {
    $st = $pdo->prepare('SELECT t.*, e.nome AS etapa_nome, e.cor AS etapa_cor, e.ordem AS etapa_ordem FROM impl_tarefas t
      JOIN impl_etapas e ON e.company_id=t.company_id AND e.id=t.etapa_id
      WHERE t.company_id=?' . ($soAtivas ? ' AND t.ativo=1 AND e.ativo=1' : '') . ' ORDER BY t.ativo DESC, e.ordem, t.ordem, t.nome');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  function impl_trilhas(PDO $pdo, int $companyId, bool $soAtivas = false): array {
    $st = $pdo->prepare('SELECT tr.*, (SELECT COUNT(*) FROM impl_trilha_tarefas tt JOIN impl_tarefas t ON t.company_id=tt.company_id AND t.id=tt.tarefa_id AND t.ativo=1 WHERE tt.company_id=tr.company_id AND tt.trilha_id=tr.id) AS tarefas
      FROM impl_trilhas tr WHERE tr.company_id=?' . ($soAtivas ? ' AND tr.ativo=1' : '') . ' ORDER BY tr.ativo DESC, tr.nome');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  function impl_trilha_tarefa_ids(PDO $pdo, int $companyId, int $trilhaId): array {
    $st = $pdo->prepare('SELECT tarefa_id FROM impl_trilha_tarefas WHERE company_id=? AND trilha_id=? ORDER BY ordem, id');
    $st->execute([$companyId, $trilhaId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  }
}

if (!function_exists('impl_etapa_save')) {
  function impl_etapa_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $nome = trim((string)($in['nome'] ?? ''));
    if ($nome === '') throw new RuntimeException('Informe o nome da etapa.');
    $cor = impl_cor((string)($in['cor'] ?? ''));
    $ordem = (int)($in['ordem'] ?? 0);
    $ativo = !empty($in['ativo']) ? 1 : 0;
    if ($id > 0) {
      $st = $pdo->prepare('UPDATE impl_etapas SET nome=?, cor=?, ordem=?, ativo=?, updated_at=NOW() WHERE company_id=? AND id=?');
      $st->execute([mb_substr($nome, 0, 80), $cor, $ordem, $ativo, $companyId, $id]);
      return $id;
    }
    $pdo->prepare('INSERT INTO impl_etapas (company_id, nome, cor, ordem, ativo) VALUES (?,?,?,?,?)')->execute([$companyId, mb_substr($nome, 0, 80), $cor, $ordem, $ativo]);
    return (int)$pdo->lastInsertId();
  }
  function impl_status_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $nome = trim((string)($in['nome'] ?? ''));
    if ($nome === '') throw new RuntimeException('Informe o nome do status.');
    $tipo = strtoupper((string)($in['tipo'] ?? 'ANDAMENTO'));
    if (!isset(impl_status_tipos()[$tipo])) throw new RuntimeException('Tipo de status inválido.');
    $vals = [mb_substr($nome, 0, 60), impl_cor((string)($in['cor'] ?? '')), $tipo, (int)($in['ordem'] ?? 0), !empty($in['ativo']) ? 1 : 0];
    if ($id > 0) {
      $pdo->prepare('UPDATE impl_status SET nome=?, cor=?, tipo=?, ordem=?, ativo=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute(array_merge($vals, [$companyId, $id]));
      return $id;
    }
    $pdo->prepare('INSERT INTO impl_status (nome, cor, tipo, ordem, ativo, company_id) VALUES (?,?,?,?,?,?)')->execute(array_merge($vals, [$companyId]));
    return (int)$pdo->lastInsertId();
  }
  function impl_tarefa_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $nome = trim((string)($in['nome'] ?? ''));
    if ($nome === '') throw new RuntimeException('Informe o nome da tarefa.');
    $etapa = (int)($in['etapa_id'] ?? 0);
    $st = $pdo->prepare('SELECT id FROM impl_etapas WHERE company_id=? AND id=?');
    $st->execute([$companyId, $etapa]);
    if (!$st->fetchColumn()) throw new RuntimeException('Escolha a etapa da tarefa.');
    $vals = [$etapa, mb_substr($nome, 0, 160), trim((string)($in['descricao'] ?? '')) ?: null, (int)($in['ordem'] ?? 0), !empty($in['ativo']) ? 1 : 0];
    if ($id > 0) {
      $pdo->prepare('UPDATE impl_tarefas SET etapa_id=?, nome=?, descricao=?, ordem=?, ativo=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute(array_merge($vals, [$companyId, $id]));
      return $id;
    }
    $pdo->prepare('INSERT INTO impl_tarefas (etapa_id, nome, descricao, ordem, ativo, company_id) VALUES (?,?,?,?,?,?)')->execute(array_merge($vals, [$companyId]));
    return (int)$pdo->lastInsertId();
  }
  /** Trilha + tarefas (na ordem marcada). */
  function impl_trilha_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $nome = trim((string)($in['nome'] ?? ''));
    if ($nome === '') throw new RuntimeException('Informe o nome da trilha.');
    $tarefas = array_values(array_unique(array_filter(array_map('intval', (array)($in['tarefas'] ?? [])), static fn($v) => $v > 0)));
    if (!$tarefas) throw new RuntimeException('Marque as tarefas que fazem parte da trilha.');
    $ph = implode(',', array_fill(0, count($tarefas), '?'));
    $st = $pdo->prepare("SELECT id FROM impl_tarefas WHERE company_id=? AND id IN ($ph)");
    $st->execute(array_merge([$companyId], $tarefas));
    $validas = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $tarefas = array_values(array_filter($tarefas, static fn($t) => in_array($t, $validas, true)));
    $pdo->beginTransaction();
    try {
      $vals = [mb_substr($nome, 0, 120), trim((string)($in['descricao'] ?? '')) ?: null, !empty($in['ativo']) ? 1 : 0];
      if ($id > 0) {
        $st = $pdo->prepare('UPDATE impl_trilhas SET nome=?, descricao=?, ativo=?, updated_at=NOW() WHERE company_id=? AND id=?');
        $st->execute(array_merge($vals, [$companyId, $id]));
        $chk = $pdo->prepare('SELECT id FROM impl_trilhas WHERE company_id=? AND id=?'); $chk->execute([$companyId, $id]);
        if (!$chk->fetchColumn()) throw new RuntimeException('Trilha não encontrada.');
      } else {
        $pdo->prepare('INSERT INTO impl_trilhas (nome, descricao, ativo, company_id) VALUES (?,?,?,?)')->execute(array_merge($vals, [$companyId]));
        $id = (int)$pdo->lastInsertId();
      }
      $pdo->prepare('DELETE FROM impl_trilha_tarefas WHERE company_id=? AND trilha_id=?')->execute([$companyId, $id]);
      $ins = $pdo->prepare('INSERT INTO impl_trilha_tarefas (company_id, trilha_id, tarefa_id, ordem) VALUES (?,?,?,?)');
      foreach ($tarefas as $i => $t) $ins->execute([$companyId, $id, $t, $i + 1]);
      $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $id;
  }
}

if (!function_exists('impl_users')) {
  /** Usuários ativos da empresa (implantadores). */
  function impl_users(PDO $pdo, int $companyId): array {
    try {
      $st = $pdo->prepare('SELECT id, name FROM users WHERE company_id=? AND is_active=1 ORDER BY name');
      $st->execute([$companyId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('impl_pessoas_busca')) {
  /** Clientes de Pessoas para escolher na nova implantação. */
  function impl_pessoas_busca(PDO $pdo, int $companyId, string $q): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $digits = preg_replace('/\D+/', '', $q) ?? '';
    $sql = 'SELECT id, name, fantasy_name, document, city, state FROM finance_people WHERE company_id=? AND active=1 AND (name LIKE ? OR fantasy_name LIKE ?' . ($digits !== '' ? ' OR document LIKE ?' : '') . ') ORDER BY name LIMIT 20';
    $params = [$companyId, $like, $like];
    if ($digits !== '') $params[] = '%' . $digits . '%';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

/* ------------------------------------------------------------ implantações */

if (!function_exists('impl_status_por_tipo')) {
  function impl_status_por_tipo(PDO $pdo, int $companyId, string $tipo): ?array {
    impl_seed_status($pdo, $companyId);
    $st = $pdo->prepare('SELECT * FROM impl_status WHERE company_id=? AND tipo=? AND ativo=1 ORDER BY ordem, id LIMIT 1');
    $st->execute([$companyId, $tipo]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}

if (!function_exists('impl_implantacao_criar')) {
  function impl_implantacao_criar(PDO $pdo, int $companyId, array $in, int $userId): int {
    $personId = (int)($in['person_id'] ?? 0);
    $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $personId]);
    if (!$st->fetchColumn()) throw new RuntimeException('Escolha o cliente (cadastro de Pessoas).');
    $trilhaId = (int)($in['trilha_id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM impl_trilhas WHERE company_id=? AND id=? AND ativo=1');
    $st->execute([$companyId, $trilhaId]);
    $trilha = $st->fetch(PDO::FETCH_ASSOC);
    if (!$trilha) throw new RuntimeException('Escolha a trilha que o cliente vai seguir.');
    $statusId = (int)($in['status_id'] ?? 0);
    $status = null;
    if ($statusId > 0) { $st = $pdo->prepare('SELECT * FROM impl_status WHERE company_id=? AND id=?'); $st->execute([$companyId, $statusId]); $status = $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    if (!$status) $status = impl_status_por_tipo($pdo, $companyId, 'AGENDADO');
    $inicio = (string)($in['data_inicio'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) $inicio = date('Y-m-d');
    $impl = (int)($in['implantador_user_id'] ?? 0);
    if ($impl > 0) { $st = $pdo->prepare('SELECT id FROM users WHERE company_id=? AND id=?'); $st->execute([$companyId, $impl]); if (!$st->fetchColumn()) $impl = 0; }

    $st = $pdo->prepare('SELECT t.id, t.nome, t.ordem, e.id AS etapa_id, e.nome AS etapa_nome, e.cor AS etapa_cor, e.ordem AS etapa_ordem, tt.ordem AS tordem
      FROM impl_trilha_tarefas tt
      JOIN impl_tarefas t ON t.company_id=tt.company_id AND t.id=tt.tarefa_id AND t.ativo=1
      JOIN impl_etapas e ON e.company_id=t.company_id AND e.id=t.etapa_id
      WHERE tt.company_id=? AND tt.trilha_id=? ORDER BY e.ordem, tt.ordem, t.ordem');
    $st->execute([$companyId, $trilhaId]);
    $tarefas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$tarefas) throw new RuntimeException('A trilha escolhida não tem tarefas ativas.');

    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT INTO impl_implantacoes (company_id, person_id, trilha_id, trilha_nome, status_id, implantador_user_id, data_inicio, data_conclusao, observacoes, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$companyId, $personId, $trilhaId, (string)$trilha['nome'], $status ? (int)$status['id'] : null, $impl ?: null, $inicio,
          ($status && $status['tipo'] === 'CONCLUIDO') ? date('Y-m-d') : null, trim((string)($in['observacoes'] ?? '')) ?: null, $userId ?: null]);
      $id = (int)$pdo->lastInsertId();
      $ins = $pdo->prepare('INSERT INTO impl_impl_tarefas (company_id, implantacao_id, tarefa_id, etapa_id, etapa_nome, etapa_cor, etapa_ordem, nome, ordem) VALUES (?,?,?,?,?,?,?,?,?)');
      foreach ($tarefas as $i => $t) $ins->execute([$companyId, $id, (int)$t['id'], (int)$t['etapa_id'], (string)$t['etapa_nome'], (string)$t['etapa_cor'], (int)$t['etapa_ordem'], (string)$t['nome'], $i + 1]);
      $pdo->commit();
      return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  }
}

if (!function_exists('impl_implantacao_editar')) {
  function impl_implantacao_editar(PDO $pdo, int $companyId, int $id, array $in): void {
    $impl = impl_implantacao($pdo, $companyId, $id);
    if (!$impl) throw new RuntimeException('Implantação não encontrada.');
    $inicio = (string)($in['data_inicio'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) throw new RuntimeException('Informe a data de início.');
    $u = (int)($in['implantador_user_id'] ?? 0);
    if ($u > 0) { $st = $pdo->prepare('SELECT id FROM users WHERE company_id=? AND id=?'); $st->execute([$companyId, $u]); if (!$st->fetchColumn()) $u = 0; }
    $pdo->prepare('UPDATE impl_implantacoes SET implantador_user_id=?, data_inicio=?, observacoes=?, updated_at=NOW() WHERE company_id=? AND id=?')
      ->execute([$u ?: null, $inicio, trim((string)($in['observacoes'] ?? '')) ?: null, $companyId, $id]);
    if ((int)($in['status_id'] ?? 0) > 0 && (int)$in['status_id'] !== (int)$impl['status_id']) impl_set_status($pdo, $companyId, $id, (int)$in['status_id']);
  }
}

if (!function_exists('impl_implantacao')) {
  function impl_implantacao(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT i.*, p.name AS cliente, p.document AS cliente_doc, p.whatsapp AS cliente_whatsapp, p.phone AS cliente_phone, p.email AS cliente_email,
        p.address AS cliente_endereco, p.number AS cliente_numero, p.district AS cliente_bairro, p.city AS cliente_cidade, p.state AS cliente_uf,
        s.nome AS status_nome, s.cor AS status_cor, s.tipo AS status_tipo, u.name AS implantador
      FROM impl_implantacoes i
      JOIN finance_people p ON p.company_id=i.company_id AND p.id=i.person_id
      LEFT JOIN impl_status s ON s.company_id=i.company_id AND s.id=i.status_id
      LEFT JOIN users u ON u.company_id=i.company_id AND u.id=i.implantador_user_id
      WHERE i.company_id=? AND i.id=? LIMIT 1');
    $st->execute([$companyId, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}

if (!function_exists('impl_implantacoes')) {
  /**
   * Lista com progresso, treinandos (participantes dos relatórios confirmados) e próximos passos.
   * Filtros: status_ids[], implantador, person_id, desde (data de início >=), q (nome).
   */
  function impl_implantacoes(PDO $pdo, int $companyId, array $f = []): array {
    $w = ['i.company_id=?']; $p = [$companyId];
    $sids = array_values(array_filter(array_map('intval', (array)($f['status_ids'] ?? [])), static fn($v) => $v > 0));
    if ($sids) { $w[] = 'i.status_id IN (' . implode(',', array_fill(0, count($sids), '?')) . ')'; $p = array_merge($p, $sids); }
    if ((int)($f['implantador'] ?? 0) > 0) { $w[] = 'i.implantador_user_id=?'; $p[] = (int)$f['implantador']; }
    if ((int)($f['person_id'] ?? 0) > 0) { $w[] = 'i.person_id=?'; $p[] = (int)$f['person_id']; }
    if (!empty($f['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['desde'])) { $w[] = 'i.data_inicio>=?'; $p[] = (string)$f['desde']; }
    if (trim((string)($f['q'] ?? '')) !== '') { $w[] = 'p.name LIKE ?'; $p[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string)$f['q'])) . '%'; }
    $st = $pdo->prepare('SELECT i.*, p.name AS cliente, s.nome AS status_nome, s.cor AS status_cor, s.tipo AS status_tipo, u.name AS implantador,
        (SELECT COUNT(*) FROM impl_impl_tarefas t WHERE t.company_id=i.company_id AND t.implantacao_id=i.id) AS total,
        (SELECT COUNT(*) FROM impl_impl_tarefas t WHERE t.company_id=i.company_id AND t.implantacao_id=i.id AND t.done_at IS NOT NULL) AS feitas,
        (SELECT MAX(r.data) FROM impl_relatorios r WHERE r.company_id=i.company_id AND r.implantacao_id=i.id AND r.status=\'CONFIRMADO\') AS ultimo_atendimento
      FROM impl_implantacoes i
      JOIN finance_people p ON p.company_id=i.company_id AND p.id=i.person_id
      LEFT JOIN impl_status s ON s.company_id=i.company_id AND s.id=i.status_id
      LEFT JOIN users u ON u.company_id=i.company_id AND u.id=i.implantador_user_id
      WHERE ' . implode(' AND ', $w) . ' ORDER BY (s.tipo=\'CONCLUIDO\'), i.data_inicio DESC, i.id DESC LIMIT 500');
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $treinandos = []; $proximos = [];
    $st = $pdo->prepare("SELECT implantacao_id, participantes FROM impl_relatorios WHERE company_id=? AND status='CONFIRMADO' AND implantacao_id IN ($ph) ORDER BY data, id");
    $st->execute(array_merge([$companyId], $ids));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      foreach (impl_participantes((string)$r['participantes']) as $n) $treinandos[(int)$r['implantacao_id']][mb_strtolower($n)] ??= $n;
    }
    $st = $pdo->prepare("SELECT implantacao_id, etapa_nome, etapa_cor, nome FROM impl_impl_tarefas WHERE company_id=? AND done_at IS NULL AND implantacao_id IN ($ph) ORDER BY implantacao_id, etapa_ordem, ordem, id");
    $st->execute(array_merge([$companyId], $ids));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $iid = (int)$r['implantacao_id'];
      if (count($proximos[$iid] ?? []) < 3) $proximos[$iid][] = $r;
    }
    foreach ($rows as &$r) {
      $iid = (int)$r['id'];
      $r['total'] = (int)$r['total']; $r['feitas'] = (int)$r['feitas'];
      $r['progresso'] = $r['total'] > 0 ? (int)floor($r['feitas'] * 100 / $r['total']) : 0;
      $r['treinandos'] = array_values($treinandos[$iid] ?? []);
      $r['proximos'] = $proximos[$iid] ?? [];
    }
    unset($r);
    return $rows;
  }
}

if (!function_exists('impl_participantes')) {
  /** Participantes digitados um por linha (ou separados por vírgula/;). */
  function impl_participantes(string $txt): array {
    $out = [];
    foreach (preg_split('/[\r\n;,]+/', $txt) ?: [] as $n) {
      $n = trim(preg_replace('/\s+/', ' ', $n) ?? '');
      if ($n !== '') $out[mb_strtolower($n)] = mb_substr($n, 0, 120);
    }
    return array_values($out);
  }
}

if (!function_exists('impl_impl_tarefas')) {
  /** Tarefas da implantação agrupadas por etapa: [etapa_key => ['nome','cor','tarefas'=>[...]]]. */
  function impl_impl_tarefas(PDO $pdo, int $companyId, int $implId): array {
    $st = $pdo->prepare('SELECT t.*, u.name AS feito_por, r.numero AS relatorio_numero FROM impl_impl_tarefas t
      LEFT JOIN users u ON u.company_id=t.company_id AND u.id=t.done_by
      LEFT JOIN impl_relatorios r ON r.company_id=t.company_id AND r.id=t.relatorio_id
      WHERE t.company_id=? AND t.implantacao_id=? ORDER BY t.etapa_ordem, t.etapa_nome, t.ordem, t.id');
    $st->execute([$companyId, $implId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
      $k = (string)$t['etapa_ordem'] . '|' . (string)$t['etapa_nome'];
      if (!isset($out[$k])) $out[$k] = ['nome' => (string)$t['etapa_nome'], 'cor' => (string)$t['etapa_cor'], 'tarefas' => []];
      $out[$k]['tarefas'][] = $t;
    }
    return $out;
  }
}

if (!function_exists('impl_auto_andamento')) {
  /** Primeira tarefa executada numa implantação "Agendada": passa para "Em andamento". */
  function impl_auto_andamento(PDO $pdo, int $companyId, int $implId): void {
    $impl = impl_implantacao($pdo, $companyId, $implId);
    if (!$impl || ($impl['status_tipo'] ?? '') !== 'AGENDADO') return;
    $and = impl_status_por_tipo($pdo, $companyId, 'ANDAMENTO');
    if ($and) $pdo->prepare('UPDATE impl_implantacoes SET status_id=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([(int)$and['id'], $companyId, $implId]);
  }
}

if (!function_exists('impl_tarefa_marcar')) {
  /** Marca/desmarca uma tarefa da implantação pela tela da trilha. */
  function impl_tarefa_marcar(PDO $pdo, int $companyId, int $implId, int $itId, bool $feito, int $userId): array {
    $st = $pdo->prepare('SELECT * FROM impl_impl_tarefas WHERE company_id=? AND implantacao_id=? AND id=?');
    $st->execute([$companyId, $implId, $itId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new RuntimeException('Tarefa não encontrada nesta implantação.');
    if ($feito) {
      if ($t['done_at'] === null) $pdo->prepare('UPDATE impl_impl_tarefas SET done_at=NOW(), done_by=?, relatorio_id=NULL WHERE company_id=? AND id=?')->execute([$userId ?: null, $companyId, $itId]);
      impl_auto_andamento($pdo, $companyId, $implId);
    } else {
      $pdo->prepare('UPDATE impl_impl_tarefas SET done_at=NULL, done_by=NULL, relatorio_id=NULL WHERE company_id=? AND id=?')->execute([$companyId, $itId]);
    }
    $st = $pdo->prepare('SELECT COUNT(*) AS total, SUM(done_at IS NOT NULL) AS feitas FROM impl_impl_tarefas WHERE company_id=? AND implantacao_id=?');
    $st->execute([$companyId, $implId]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'feitas' => 0];
    return ['total' => (int)$c['total'], 'feitas' => (int)$c['feitas']];
  }
}

if (!function_exists('impl_set_status')) {
  function impl_set_status(PDO $pdo, int $companyId, int $implId, int $statusId): void {
    $st = $pdo->prepare('SELECT * FROM impl_status WHERE company_id=? AND id=?');
    $st->execute([$companyId, $statusId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new RuntimeException('Status não encontrado.');
    $concluido = $s['tipo'] === 'CONCLUIDO';
    $pdo->prepare('UPDATE impl_implantacoes SET status_id=?, data_conclusao=' . ($concluido ? 'COALESCE(data_conclusao, CURDATE())' : 'NULL') . ', updated_at=NOW() WHERE company_id=? AND id=?')
      ->execute([$statusId, $companyId, $implId]);
  }
  function impl_concluir(PDO $pdo, int $companyId, int $implId): void {
    $s = impl_status_por_tipo($pdo, $companyId, 'CONCLUIDO');
    if (!$s) throw new RuntimeException('Cadastre um status do tipo "Concluído" em Cadastros > Status.');
    impl_set_status($pdo, $companyId, $implId, (int)$s['id']);
  }
}

if (!function_exists('impl_tarefa_adicionar')) {
  /** Acrescenta uma tarefa do cadastro numa implantação (fora da trilha). */
  function impl_tarefa_adicionar(PDO $pdo, int $companyId, int $implId, int $tarefaId): void {
    if (!impl_implantacao($pdo, $companyId, $implId)) throw new RuntimeException('Implantação não encontrada.');
    $st = $pdo->prepare('SELECT t.id, t.nome, e.id AS etapa_id, e.nome AS etapa_nome, e.cor AS etapa_cor, e.ordem AS etapa_ordem FROM impl_tarefas t JOIN impl_etapas e ON e.company_id=t.company_id AND e.id=t.etapa_id WHERE t.company_id=? AND t.id=?');
    $st->execute([$companyId, $tarefaId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new RuntimeException('Tarefa não encontrada.');
    $st = $pdo->prepare('SELECT id FROM impl_impl_tarefas WHERE company_id=? AND implantacao_id=? AND tarefa_id=?');
    $st->execute([$companyId, $implId, $tarefaId]);
    if ($st->fetchColumn()) throw new RuntimeException('Esta tarefa já está na trilha deste cliente.');
    $st = $pdo->prepare('SELECT COALESCE(MAX(ordem),0)+1 FROM impl_impl_tarefas WHERE company_id=? AND implantacao_id=?');
    $st->execute([$companyId, $implId]);
    $pdo->prepare('INSERT INTO impl_impl_tarefas (company_id, implantacao_id, tarefa_id, etapa_id, etapa_nome, etapa_cor, etapa_ordem, nome, ordem) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, $implId, $tarefaId, (int)$t['etapa_id'], (string)$t['etapa_nome'], (string)$t['etapa_cor'], (int)$t['etapa_ordem'], (string)$t['nome'], (int)$st->fetchColumn()]);
  }
  function impl_tarefa_remover(PDO $pdo, int $companyId, int $implId, int $itId): void {
    $st = $pdo->prepare('SELECT done_at FROM impl_impl_tarefas WHERE company_id=? AND implantacao_id=? AND id=?');
    $st->execute([$companyId, $implId, $itId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Tarefa não encontrada.');
    if ($row['done_at'] !== null) throw new RuntimeException('Tarefa já executada não pode ser retirada. Desmarque antes.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM impl_relatorio_tarefas WHERE company_id=? AND impl_tarefa_id=?');
    $st->execute([$companyId, $itId]);
    if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Esta tarefa está num relatório de atendimento e não pode ser retirada.');
    $pdo->prepare('DELETE FROM impl_impl_tarefas WHERE company_id=? AND id=?')->execute([$companyId, $itId]);
  }
}

/* -------------------------------------------------------------- relatórios */

if (!function_exists('impl_relatorio')) {
  function impl_relatorio(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT r.*, u.name AS implantador, i.person_id, p.name AS cliente, p.whatsapp AS cliente_whatsapp, p.phone AS cliente_phone
      FROM impl_relatorios r
      JOIN impl_implantacoes i ON i.company_id=r.company_id AND i.id=r.implantacao_id
      JOIN finance_people p ON p.company_id=i.company_id AND p.id=i.person_id
      LEFT JOIN users u ON u.company_id=r.company_id AND u.id=r.implantador_user_id
      WHERE r.company_id=? AND r.id=? LIMIT 1');
    $st->execute([$companyId, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $st = $pdo->prepare('SELECT impl_tarefa_id FROM impl_relatorio_tarefas WHERE company_id=? AND relatorio_id=?');
    $st->execute([$companyId, $id]);
    $r['tarefas'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return $r;
  }
}

if (!function_exists('impl_relatorio_tarefas_nomes')) {
  function impl_relatorio_tarefas_nomes(PDO $pdo, int $companyId, int $relId): array {
    $st = $pdo->prepare('SELECT t.etapa_nome, t.etapa_cor, t.nome FROM impl_relatorio_tarefas rt JOIN impl_impl_tarefas t ON t.company_id=rt.company_id AND t.id=rt.impl_tarefa_id
      WHERE rt.company_id=? AND rt.relatorio_id=? ORDER BY t.etapa_ordem, t.ordem, t.id');
    $st->execute([$companyId, $relId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('impl_relatorio_salvar')) {
  /** Cria/atualiza o relatório (rascunho). Retorna o id. */
  function impl_relatorio_salvar(PDO $pdo, int $companyId, array $in, int $userId): int {
    $id = (int)($in['id'] ?? 0);
    if ($id > 0) {
      $old = impl_relatorio($pdo, $companyId, $id);
      if (!$old) throw new RuntimeException('Relatório não encontrado.');
      if ($old['status'] === 'CONFIRMADO') throw new RuntimeException('Relatório confirmado não pode ser alterado.');
      if (!empty($old['assinatura_arquivo'])) throw new RuntimeException('O cliente já assinou este relatório; ele não pode mais ser alterado. Só falta confirmar.');
      $implId = (int)$old['implantacao_id'];
    } else {
      $implId = (int)($in['implantacao_id'] ?? 0);
    }
    $impl = impl_implantacao($pdo, $companyId, $implId);
    if (!$impl) throw new RuntimeException('Escolha o cliente (implantação).');
    $data = (string)($in['data'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) throw new RuntimeException('Informe a data do atendimento.');
    $hi = substr((string)($in['hora_inicio'] ?? ''), 0, 5); $hf = substr((string)($in['hora_fim'] ?? ''), 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $hi) || !preg_match('/^\d{2}:\d{2}$/', $hf)) throw new RuntimeException('Informe a hora inicial e a final.');
    if ($hf <= $hi) throw new RuntimeException('A hora final precisa ser depois da inicial.');
    $u = (int)($in['implantador_user_id'] ?? 0);
    if ($u > 0) { $st = $pdo->prepare('SELECT id FROM users WHERE company_id=? AND id=?'); $st->execute([$companyId, $u]); if (!$st->fetchColumn()) $u = 0; }
    $part = implode("\n", impl_participantes((string)($in['participantes'] ?? '')));
    $obs = trim(str_replace("\r\n", "\n", (string)($in['observacoes'] ?? '')));
    $tarefas = array_values(array_unique(array_filter(array_map('intval', (array)($in['tarefas'] ?? [])), static fn($v) => $v > 0)));
    if ($tarefas) {
      $ph = implode(',', array_fill(0, count($tarefas), '?'));
      $st = $pdo->prepare("SELECT id FROM impl_impl_tarefas WHERE company_id=? AND implantacao_id=? AND id IN ($ph)");
      $st->execute(array_merge([$companyId, $implId], $tarefas));
      $tarefas = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    $pdo->beginTransaction();
    try {
      if ($id > 0) {
        $pdo->prepare("UPDATE impl_relatorios SET data=?, hora_inicio=?, hora_fim=?, implantador_user_id=?, participantes=?, observacoes=?, updated_at=NOW() WHERE company_id=? AND id=? AND status='RASCUNHO'")
          ->execute([$data, $hi, $hf, $u ?: null, $part ?: null, $obs ?: null, $companyId, $id]);
      } else {
        $st = $pdo->prepare('SELECT COALESCE(MAX(numero),0)+1 FROM impl_relatorios WHERE company_id=? FOR UPDATE');
        $st->execute([$companyId]);
        $numero = (int)$st->fetchColumn();
        $pdo->prepare("INSERT INTO impl_relatorios (company_id, implantacao_id, numero, data, hora_inicio, hora_fim, implantador_user_id, participantes, observacoes, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,'RASCUNHO',?,NOW())")
          ->execute([$companyId, $implId, $numero, $data, $hi, $hf, $u ?: null, $part ?: null, $obs ?: null, $userId ?: null]);
        $id = (int)$pdo->lastInsertId();
      }
      $pdo->prepare('DELETE FROM impl_relatorio_tarefas WHERE company_id=? AND relatorio_id=?')->execute([$companyId, $id]);
      $ins = $pdo->prepare('INSERT INTO impl_relatorio_tarefas (company_id, relatorio_id, impl_tarefa_id) VALUES (?,?,?)');
      foreach ($tarefas as $t) $ins->execute([$companyId, $id, $t]);
      $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $id;
  }
}

if (!function_exists('impl_relatorio_confirmar')) {
  /** Confirma o relatório: as tarefas marcadas ficam executadas (data/hora do fim do atendimento). */
  function impl_relatorio_confirmar(PDO $pdo, int $companyId, int $id, int $userId): void {
    $r = impl_relatorio($pdo, $companyId, $id);
    if (!$r) throw new RuntimeException('Relatório não encontrado.');
    if ($r['status'] === 'CONFIRMADO') throw new RuntimeException('Este relatório já foi confirmado.');
    if (!impl_participantes((string)$r['participantes'])) throw new RuntimeException('Informe o nome de quem participou do treinamento.');
    if (!$r['tarefas'] && trim((string)$r['observacoes']) === '') throw new RuntimeException('Marque os processos implantados ou descreva o atendimento nas observações.');
    $quando = (string)$r['data'] . ' ' . substr((string)$r['hora_fim'], 0, 5) . ':00';
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("UPDATE impl_relatorios SET status='CONFIRMADO', confirmado_em=NOW(), confirmado_por=?, updated_at=NOW() WHERE company_id=? AND id=? AND status='RASCUNHO'");
      $st->execute([$userId ?: null, $companyId, $id]);
      if ($st->rowCount() < 1) throw new RuntimeException('Este relatório já foi confirmado.');
      if ($r['tarefas']) {
        $ph = implode(',', array_fill(0, count($r['tarefas']), '?'));
        $pdo->prepare("UPDATE impl_impl_tarefas SET done_at=?, done_by=?, relatorio_id=? WHERE company_id=? AND implantacao_id=? AND done_at IS NULL AND id IN ($ph)")
          ->execute(array_merge([$quando, (int)($r['implantador_user_id'] ?: $userId) ?: null, $id, $companyId, (int)$r['implantacao_id']], $r['tarefas']));
      }
      $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    impl_auto_andamento($pdo, $companyId, (int)$r['implantacao_id']);
  }
}

if (!function_exists('impl_relatorio_excluir')) {
  /** Só rascunho pode ser excluído. */
  function impl_relatorio_excluir(PDO $pdo, int $companyId, int $id): void {
    $r = impl_relatorio($pdo, $companyId, $id);
    if (!$r) throw new RuntimeException('Relatório não encontrado.');
    if ($r['status'] !== 'RASCUNHO') throw new RuntimeException('Relatório confirmado não pode ser excluído.');
    if (!empty($r['assinatura_arquivo'])) throw new RuntimeException('Relatório assinado pelo cliente não pode ser excluído.');
    $pdo->prepare('DELETE FROM impl_relatorio_tarefas WHERE company_id=? AND relatorio_id=?')->execute([$companyId, $id]);
    $pdo->prepare("DELETE FROM impl_relatorios WHERE company_id=? AND id=? AND status='RASCUNHO'")->execute([$companyId, $id]);
  }
}

if (!function_exists('impl_relatorios')) {
  function impl_relatorios(PDO $pdo, int $companyId, array $f = []): array {
    $w = ['r.company_id=?']; $p = [$companyId];
    $sids = array_values(array_filter(array_map('intval', (array)($f['status_ids'] ?? [])), static fn($v) => $v > 0));
    if ($sids) { $w[] = 'i.status_id IN (' . implode(',', array_fill(0, count($sids), '?')) . ')'; $p = array_merge($p, $sids); }
    if ((int)($f['implantador'] ?? 0) > 0) { $w[] = 'r.implantador_user_id=?'; $p[] = (int)$f['implantador']; }
    if ((int)($f['person_id'] ?? 0) > 0) { $w[] = 'i.person_id=?'; $p[] = (int)$f['person_id']; }
    if (!empty($f['de']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['de'])) { $w[] = 'r.data>=?'; $p[] = (string)$f['de']; }
    if (!empty($f['ate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['ate'])) { $w[] = 'r.data<=?'; $p[] = (string)$f['ate']; }
    $st = $pdo->prepare('SELECT r.*, p.name AS cliente, u.name AS implantador, s.nome AS status_nome, s.cor AS status_cor,
        (SELECT COUNT(*) FROM impl_relatorio_tarefas rt WHERE rt.company_id=r.company_id AND rt.relatorio_id=r.id) AS processos
      FROM impl_relatorios r
      JOIN impl_implantacoes i ON i.company_id=r.company_id AND i.id=r.implantacao_id
      JOIN finance_people p ON p.company_id=i.company_id AND p.id=i.person_id
      LEFT JOIN impl_status s ON s.company_id=i.company_id AND s.id=i.status_id
      LEFT JOIN users u ON u.company_id=r.company_id AND u.id=r.implantador_user_id
      WHERE ' . implode(' AND ', $w) . ' ORDER BY r.data DESC, r.hora_inicio DESC, r.id DESC LIMIT 500');
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('impl_minutos')) {
  function impl_minutos(string $hi, string $hf): int {
    [$a, $b] = array_map('intval', explode(':', substr($hi, 0, 5)) + [0, 0]);
    [$c, $d] = array_map('intval', explode(':', substr($hf, 0, 5)) + [0, 0]);
    return max(0, ($c * 60 + $d) - ($a * 60 + $b));
  }
  function impl_horas_txt(int $min): string { return sprintf('%dh%02d', intdiv($min, 60), $min % 60); }
  function impl_data_br(?string $d): string { $d = (string)$d; return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "$m[3]/$m[2]/$m[1]" : '-'; }
}

/* ---------------------------------------------------------------- assinatura */

if (!function_exists('impl_relatorio_assinar')) {
  /** Grava a assinatura do cliente (uma vez). origem LOCAL (celular do técnico) ou LINK. */
  function impl_relatorio_assinar(PDO $pdo, int $companyId, int $id, string $dataUrl, string $nome, string $origem, string $ip): void {
    $r = impl_relatorio($pdo, $companyId, $id);
    if (!$r) throw new RuntimeException('Relatório não encontrado.');
    if (!empty($r['assinatura_arquivo'])) throw new RuntimeException('Este relatório já foi assinado.');
    $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
    if (mb_strlen($nome) < 3) throw new RuntimeException('Informe o nome de quem está assinando.');
    $rel = impl_store_signature($companyId, $dataUrl);
    $st = $pdo->prepare('UPDATE impl_relatorios SET assinatura_arquivo=?, assinatura_nome=?, assinatura_em=NOW(), assinatura_ip=?, assinatura_origem=?, link_hash=NULL, link_expira=NULL, updated_at=NOW()
      WHERE company_id=? AND id=? AND assinatura_arquivo IS NULL');
    $st->execute([$rel, mb_substr($nome, 0, 120), mb_substr($ip, 0, 45), $origem === 'LINK' ? 'LINK' : 'LOCAL', $companyId, $id]);
    if ($st->rowCount() < 1) { @unlink(impl_files_root() . '/' . $rel); throw new RuntimeException('Este relatório já foi assinado.'); }
  }
}

if (!function_exists('impl_relatorio_link')) {
  /** Gera (ou renova) o link de assinatura: código aleatório, só o hash fica no banco, vale 7 dias. */
  function impl_relatorio_link(PDO $pdo, int $companyId, int $id): string {
    $r = impl_relatorio($pdo, $companyId, $id);
    if (!$r) throw new RuntimeException('Relatório não encontrado.');
    if (!empty($r['assinatura_arquivo'])) throw new RuntimeException('Este relatório já foi assinado.');
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('UPDATE impl_relatorios SET link_hash=?, link_expira=?, updated_at=NOW() WHERE company_id=? AND id=?')
      ->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + 7 * 86400), $companyId, $id]);
    return impl_base_url() . '/implantacao_assinar.php?t=' . $token;
  }
  function impl_relatorio_por_link(PDO $pdo, int $companyId, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $st = $pdo->prepare('SELECT id FROM impl_relatorios WHERE company_id=? AND link_hash=? AND link_expira>=NOW() LIMIT 1');
    $st->execute([$companyId, hash('sha256', $token)]);
    $id = (int)($st->fetchColumn() ?: 0);
    return $id > 0 ? impl_relatorio($pdo, $companyId, $id) : null;
  }
  function impl_base_url(): string {
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
      || stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false;
    $local = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);
    return ($https || !$local ? 'https' : 'http') . '://' . $host;
  }
}

/* ------------------------------------------------------- fotos e conclusão */

if (!function_exists('impl_fotos')) {
  function impl_fotos(PDO $pdo, int $companyId, int $implId): array {
    $st = $pdo->prepare('SELECT * FROM impl_fotos WHERE company_id=? AND implantacao_id=? ORDER BY id');
    $st->execute([$companyId, $implId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  function impl_foto_add(PDO $pdo, int $companyId, int $implId, array $file, string $legenda, int $userId): void {
    if (!impl_implantacao($pdo, $companyId, $implId)) throw new RuntimeException('Implantação não encontrada.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM impl_fotos WHERE company_id=? AND implantacao_id=?');
    $st->execute([$companyId, $implId]);
    if ((int)$st->fetchColumn() >= 30) throw new RuntimeException('Limite de 30 fotos por implantação.');
    $rel = impl_store_photo($companyId, $file);
    $pdo->prepare('INSERT INTO impl_fotos (company_id, implantacao_id, arquivo, legenda, created_by) VALUES (?,?,?,?,?)')
      ->execute([$companyId, $implId, $rel, mb_substr(trim($legenda), 0, 200) ?: null, $userId ?: null]);
  }
  function impl_foto_del(PDO $pdo, int $companyId, int $implId, int $fotoId): void {
    $st = $pdo->prepare('SELECT arquivo FROM impl_fotos WHERE company_id=? AND implantacao_id=? AND id=?');
    $st->execute([$companyId, $implId, $fotoId]);
    $rel = (string)($st->fetchColumn() ?: '');
    if ($rel === '') throw new RuntimeException('Foto não encontrada.');
    $pdo->prepare('DELETE FROM impl_fotos WHERE company_id=? AND id=?')->execute([$companyId, $fotoId]);
    $path = impl_file_path($companyId, $rel);
    if ($path) @unlink($path);
  }
  function impl_aviso_salvar(PDO $pdo, int $companyId, int $implId, string $texto): void {
    $texto = trim(str_replace("\r\n", "\n", $texto));
    if (mb_strlen($texto) > 2000) throw new RuntimeException('O aviso pode ter no máximo 2.000 caracteres.');
    $pdo->prepare('UPDATE impl_implantacoes SET aviso_equipamentos=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$texto !== '' ? $texto : null, $companyId, $implId]);
  }
}

if (!function_exists('impl_conclusao_dados')) {
  /** Tudo que vai no relatório de conclusão da implantação. */
  function impl_conclusao_dados(PDO $pdo, int $companyId, int $implId): array {
    $impl = impl_implantacao($pdo, $companyId, $implId);
    if (!$impl) throw new RuntimeException('Implantação não encontrada.');
    $st = $pdo->prepare("SELECT r.*, u.name AS implantador FROM impl_relatorios r LEFT JOIN users u ON u.company_id=r.company_id AND u.id=r.implantador_user_id
      WHERE r.company_id=? AND r.implantacao_id=? AND r.status='CONFIRMADO' ORDER BY r.data, r.hora_inicio, r.id");
    $st->execute([$companyId, $implId]);
    $rels = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $treinandos = []; $minutos = 0; $implantadores = [];
    foreach ($rels as &$r) {
      $r['tarefas_nomes'] = impl_relatorio_tarefas_nomes($pdo, $companyId, (int)$r['id']);
      $r['minutos'] = impl_minutos((string)$r['hora_inicio'], (string)$r['hora_fim']);
      $minutos += $r['minutos'];
      foreach (impl_participantes((string)$r['participantes']) as $n) $treinandos[mb_strtolower($n)] ??= $n;
      if (!empty($r['implantador'])) $implantadores[(string)$r['implantador']] = true;
    }
    unset($r);
    $st = $pdo->prepare('SELECT t.*, u.name AS feito_por FROM impl_impl_tarefas t LEFT JOIN users u ON u.company_id=t.company_id AND u.id=t.done_by
      WHERE t.company_id=? AND t.implantacao_id=? ORDER BY t.etapa_ordem, t.ordem, t.id');
    $st->execute([$companyId, $implId]);
    $todas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $feitas = array_values(array_filter($todas, static fn($t) => $t['done_at'] !== null));
    $pendentes = array_values(array_filter($todas, static fn($t) => $t['done_at'] === null));
    $st = $pdo->prepare('SELECT name, cnpj, cpf, endereco, numero, bairro, cidade, estado, email, whatsapp FROM companies WHERE id=? LIMIT 1');
    $st->execute([$companyId]);
    $empresa = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $aviso = trim((string)($impl['aviso_equipamentos'] ?? '')) ?: impl_config($pdo, $companyId)['aviso_equipamentos'];
    return [
      'impl' => $impl, 'empresa' => $empresa, 'relatorios' => $rels, 'treinandos' => array_values($treinandos),
      'minutos' => $minutos, 'implantadores' => array_keys($implantadores), 'feitas' => $feitas, 'pendentes' => $pendentes,
      'total' => count($todas), 'fotos' => impl_fotos($pdo, $companyId, $implId), 'aviso' => $aviso,
      'primeiro' => $rels ? (string)$rels[0]['data'] : (string)$impl['data_inicio'],
      'ultimo' => $rels ? (string)$rels[count($rels) - 1]['data'] : (string)($impl['data_conclusao'] ?? ''),
    ];
  }
}
