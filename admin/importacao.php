<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/product_import_module.php';

product_import_ensure_schema($pdo);

$msg = '';
$erro = '';
$summary = null;
$importResult = null;
$uploadToken = trim((string)($_POST['upload_token'] ?? ''));
$source = strtolower(trim((string)($_POST['source'] ?? $_GET['source'] ?? '')));
if (!in_array($source, ['prospero', 'planilha'], true)) $source = '';
$cfg = product_import_config($pdo, (int)COMPANY_ID);
$userId = (int)(auth_user_id() ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($source === 'prospero') {
      if (in_array($action, ['save_config', 'test_connection', 'load_source'], true)) {
        $cfg = product_import_save_config($pdo, (int)COMPANY_ID, $_POST);
        if ($action === 'test_connection') {
          $test = product_import_test_connection($cfg);
          if (empty($test['ok'])) throw new RuntimeException((string)($test['error'] ?? 'Falha ao conectar.'));
          $msg = (string)($test['message'] ?? 'Conexão realizada.') . ' Produtos encontrados: ' . (int)($test['count'] ?? 0) . '.';
        } elseif ($action === 'save_config') {
          $msg = 'Configuração da importação salva.';
        }
      }
      if ($action === 'load_source') {
        $summary = product_import_source_summary($cfg, 40);
        $msg = 'Leitura concluída. Foram encontrados ' . (int)$summary['count'] . ' produto(s) no Firebird.';
      }
      if ($action === 'save_tax_map') {
        $summary = product_import_source_summary($cfg, 40);
        product_import_save_tax_mapping_for_source($pdo, (int)COMPANY_ID, 'PROSPERO_FIREBIRD', (array)($_POST['tax_map'] ?? []));
        $msg = 'Mapeamento das tributações do Próspero ERP salvo.';
      }
      if ($action === 'run_import') {
        if (empty($_POST['confirm_import'])) throw new RuntimeException('Marque a confirmação antes de iniciar a importação.');
        $taxCheck = product_import_source_summary($cfg, 1);
        $savedMap = product_import_tax_mapping_for_source($pdo, (int)COMPANY_ID, 'PROSPERO_FIREBIRD');
        $unmapped = array_values(array_filter((array)$taxCheck['tax_codes'], static fn($code) => (int)($savedMap[(string)$code] ?? 0) <= 0));
        if ($unmapped) throw new RuntimeException('Vincule todas as tributações do Firebird antes de importar. Sem vínculo: ' . implode(', ', array_slice($unmapped, 0, 20)) . (count($unmapped) > 20 ? '...' : '') . '.');
        $importResult = product_import_execute($pdo, (int)COMPANY_ID, $cfg, [
          'create_new' => !empty($_POST['create_new']),
          'update_existing' => !empty($_POST['update_existing']),
        ]);
        $prefix = (int)$importResult['errors'] > 0 ? 'Importação finalizada com pendências' : 'Importação concluída';
        $msg = $prefix . ': ' . (int)$importResult['inserted'] . ' incluído(s), ' . (int)$importResult['updated'] . ' atualizado(s), ' . (int)$importResult['skipped'] . ' ignorado(s) e ' . (int)$importResult['errors'] . ' erro(s).';
        if ((int)$importResult['errors'] > 0 && !empty($importResult['messages'])) $erro = implode(' | ', array_slice((array)$importResult['messages'], 0, 5));
        $summary = product_import_source_summary($cfg, 40);
      }
    }

    if ($source === 'planilha') {
      if ($action === 'upload_excel') {
        $upload = product_import_excel_store_upload($pdo, (int)COMPANY_ID, $userId, (array)($_FILES['product_file'] ?? []), (string)($_POST['source_system'] ?? 'PROSPERO_EXCEL'));
        $uploadToken = (string)$upload['token'];
        $summary = product_import_excel_summary($pdo, (int)COMPANY_ID, $uploadToken, 40);
        $msg = 'Planilha validada. Foram encontrados ' . (int)$summary['count'] . ' produto(s) na aba ' . (string)$summary['sheet'] . '.';
      }
      if ($action === 'save_excel_tax_map') {
        if ($uploadToken === '') throw new RuntimeException('Envie a planilha novamente.');
        $summary = product_import_excel_summary($pdo, (int)COMPANY_ID, $uploadToken, 40);
        product_import_save_tax_mapping_for_source($pdo, (int)COMPANY_ID, (string)($summary['upload']['source_type'] ?? 'PROSPERO_EXCEL'), (array)($_POST['tax_map'] ?? []));
        $msg = 'Mapeamento das tributações da planilha salvo.';
      }
      if ($action === 'run_excel_import_batch') {
        if ($uploadToken === '') throw new RuntimeException('Envie a planilha novamente.');
        if (empty($_POST['confirm_import'])) throw new RuntimeException('Marque a confirmação antes de iniciar a importação.');
        $batchSession = strtolower(trim((string)($_POST['batch_session'] ?? '')));
        $batchOffset = max(0, (int)($_POST['batch_offset'] ?? 0));
        $batchSize = max(20, min(250, (int)($_POST['batch_size'] ?? 150)));
        $batchSeconds = max(5.0, min(25.0, (float)($_POST['batch_seconds'] ?? 12)));
        $importResult = product_import_excel_execute_batch($pdo, (int)COMPANY_ID, $uploadToken, $batchSession, $batchOffset, [
          'create_new' => !empty($_POST['create_new']),
          'update_existing' => !empty($_POST['update_existing']),
          'max_rows' => $batchSize,
          'max_seconds' => $batchSeconds,
        ]);
        if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'result' => $importResult], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
      }
      if ($action === 'run_excel_import') {
        if ($uploadToken === '') throw new RuntimeException('Envie a planilha novamente.');
        if (empty($_POST['confirm_import'])) throw new RuntimeException('Marque a confirmação antes de iniciar a importação.');
        $summary = product_import_excel_summary($pdo, (int)COMPANY_ID, $uploadToken, 40);
        $savedMap = product_import_tax_mapping_for_source($pdo, (int)COMPANY_ID, (string)($summary['upload']['source_type'] ?? 'PROSPERO_EXCEL'));
        $unmapped = array_values(array_filter((array)$summary['tax_codes'], static fn($code) => (int)($savedMap[(string)$code] ?? 0) <= 0));
        if ($unmapped) throw new RuntimeException('Vincule todas as tributações da planilha antes de importar. Sem vínculo: ' . implode(', ', array_slice($unmapped, 0, 20)) . (count($unmapped) > 20 ? '...' : '') . '.');
        $importResult = product_import_excel_execute($pdo, (int)COMPANY_ID, $uploadToken, [
          'create_new' => !empty($_POST['create_new']),
          'update_existing' => !empty($_POST['update_existing']),
        ]);
        $prefix = (int)$importResult['errors'] > 0 ? 'Importação da planilha finalizada com pendências' : 'Importação da planilha concluída';
        $msg = $prefix . ': ' . (int)$importResult['inserted'] . ' incluído(s), ' . (int)$importResult['updated'] . ' atualizado(s), ' . (int)$importResult['skipped'] . ' ignorado(s) e ' . (int)$importResult['errors'] . ' erro(s).';
        if ((int)$importResult['errors'] > 0 && !empty($importResult['messages'])) $erro = implode(' | ', array_slice((array)$importResult['messages'], 0, 5));
      }
    }
  } catch (Throwable $e) {
    if ($source === 'planilha' && (string)($_POST['action'] ?? '') === 'run_excel_import_batch') {
      if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
      http_response_code(422);
      echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      exit;
    }
    $erro = $e->getMessage();
    if ($source === 'planilha' && $uploadToken !== '' && $summary === null) {
      try { $summary = product_import_excel_summary($pdo, (int)COMPANY_ID, $uploadToken, 40); } catch (Throwable $ignored) {}
    }
  }
}

$taxProfiles = fiscal_tax_profiles($pdo, (int)COMPANY_ID, false, 'PRODUCT');
$sourceType = $source === 'planilha' ? (string)($summary['upload']['source_type'] ?? 'PROSPERO_EXCEL') : 'PROSPERO_FIREBIRD';
$taxMapping = product_import_tax_mapping_for_source($pdo, (int)COMPANY_ID, $sourceType);
$runs = [];
try {
  $st = $pdo->prepare('SELECT * FROM product_import_runs WHERE company_id=? ORDER BY id DESC LIMIT 30');
  $st->execute([COMPANY_ID]);
  $runs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

admin_topbar('Central - Importações', 'importacao');
?>
<style>
.import-wrap{max-width:1280px;margin:0 auto;padding:22px}.import-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.import-card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:20px;box-shadow:0 10px 28px rgba(15,23,42,.06)}.import-card h2,.import-card h3{margin:0 0 8px;color:#0b1f5b}.import-muted{color:#64748b;line-height:1.55}.import-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.import-field{display:flex;flex-direction:column;gap:6px}.import-field.span-2{grid-column:span 2}.import-field label{font-weight:800;color:#334155}.import-field input,.import-field select{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:12px;background:#fff}.import-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:15px}.import-btn{border:1px solid #cbd5e1;border-radius:12px;padding:10px 15px;background:#fff;color:#0f172a;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.import-btn.primary{background:#0b1f5b;border-color:#0b1f5b;color:#fff}.import-btn.danger{background:#991b1b;border-color:#991b1b;color:#fff}.import-msg{margin-bottom:14px;padding:13px 15px;border-radius:14px}.import-msg.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.import-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.import-msg.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}.import-table{width:100%;border-collapse:collapse;min-width:760px}.import-table th,.import-table td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.import-table th{background:#f8fafc;color:#334155;font-size:13px}.import-scroll{overflow:auto;border:1px solid #e2e8f0;border-radius:14px}.import-step{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#dbeafe;color:#1d4ed8;font-weight:900;margin-right:8px}.import-check{display:flex;gap:9px;align-items:flex-start;padding:12px;border:1px solid #dbe4f0;border-radius:14px;background:#f8fbff}.import-status{display:inline-flex;padding:5px 9px;border-radius:999px;background:#f1f5f9;font-size:12px;font-weight:800}.import-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.import-full{grid-column:1/-1}.source-card{position:relative;min-height:210px;display:flex;flex-direction:column}.source-icon{width:54px;height:54px;border-radius:17px;display:flex;align-items:center;justify-content:center;background:#e0e7ff;color:#1e3a8a;font-size:27px;font-weight:900;margin-bottom:14px}.source-card.excel .source-icon{background:#dcfce7;color:#166534}.source-card.customers .source-icon{background:#fef3c7;color:#92400e}.source-card.future{opacity:.72;background:#f8fafc}.source-card .import-actions{margin-top:auto}.source-badge{position:absolute;right:16px;top:16px;padding:5px 9px;border-radius:999px;background:#ecfdf5;color:#047857;font-size:11px;font-weight:900}.source-badge.future{background:#e2e8f0;color:#475569}.import-nav{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}.import-file-info{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.import-kpi{padding:12px;border:1px solid #dbe4f0;border-radius:14px;background:#f8fafc}.import-kpi strong{display:block;font-size:20px;color:#0b1f5b}.required-columns{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.required-columns span{padding:5px 8px;border-radius:8px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:800}@media(max-width:850px){.import-grid,.import-fields,.import-file-info{grid-template-columns:1fr}.import-field.span-2,.import-full{grid-column:auto}.import-wrap{padding:14px}.import-nav{align-items:flex-start;flex-direction:column}}
</style>
<div class="import-wrap">
  <?php if ($msg !== ''): ?><div class="import-msg ok"><?= product_import_h($msg) ?></div><?php endif; ?>
  <?php if ($erro !== ''): ?><div class="import-msg err"><?= product_import_h($erro) ?></div><?php endif; ?>

  <?php if ($source === ''): ?>
    <section class="import-card import-full" style="margin-bottom:16px">
      <h2>Escolha o que deseja importar</h2>
      <p class="import-muted">A Central de Importação reúne produtos, clientes e dados financeiros. Escolha abaixo a operação desejada; cada importação possui suas próprias validações, prévia e controles antes de gravar os dados.</p>
    </section>
    <?php if (function_exists('auth_role') && auth_role() === 'platform_admin'): ?>
      <div class="import-msg warn" style="margin-bottom:16px"><strong>Antes de uma migração:</strong> gere e baixe um backup completo do banco. <a href="backup_banco.php" style="font-weight:900;color:inherit">Abrir Backup do Banco</a>.</div>
    <?php endif; ?>
    <div class="import-grid">
      <section class="import-card source-card">
        <span class="source-badge">Disponível</span><div class="source-icon">P</div>
        <h2>Importar do Próspero ERP</h2>
        <p class="import-muted">Conecta diretamente ao banco Firebird 5.0, lê produtos e grupos, permite vincular as tributações e importa para a empresa selecionada.</p>
        <div class="import-actions"><a class="import-btn primary" href="importacao.php?source=prospero">Abrir integração Firebird</a></div>
      </section>
      <section class="import-card source-card excel">
        <span class="source-badge">Disponível</span><div class="source-icon">X</div>
        <h2>Importar por planilha</h2>
        <p class="import-muted">Importa uma planilha XLSX, XLSM ou CSV no mesmo padrão de campos do Próspero ERP, com prévia, vínculo tributário e atualização controlada.</p>
        <div class="import-actions"><a class="import-btn primary" href="importacao.php?source=planilha">Abrir importação por planilha</a><a class="import-btn" href="../assets/modelos/modelo_importacao_produtos_prospero.xlsx">Baixar modelo</a></div>
      </section>
      <section class="import-card source-card customers">
        <span class="source-badge">Disponível</span><div class="source-icon">C</div>
        <h2>Importar clientes por planilha</h2>
        <p class="import-muted">Importa clientes em XLSX, XLSM ou CSV, com vínculo das colunas, identificação de Pessoa Física/Jurídica e tratamento de valores como 1/2, F/J, PF/PJ ou Física/Jurídica.</p>
        <div class="import-actions"><a class="import-btn primary" href="gestao_clientes_importar.php">Abrir importação de clientes</a></div>
      </section>
      <section class="import-card source-card customers">
        <span class="source-badge">Disponível</span><div class="source-icon">R</div>
        <h2>Importar Contas a Receber</h2>
        <p class="import-muted">Importa títulos por planilha usando o código histórico do cliente, com conferência de vínculo, totais, idempotência e tratamento de saldo parcialmente recebido.</p>
        <div class="import-actions"><a class="import-btn primary" href="financeiro_contas_receber_importar.php">Abrir importação financeira</a></div>
      </section>
      <section class="import-card source-card customers">
        <span class="source-badge">Disponível após configurar worker</span><div class="source-icon">A</div>
        <h2>Importar anexos de clientes — CPlus4</h2>
        <p class="import-muted">Envie o arquivo ZIP em partes pequenas, com processamento na VPS em lotes de 20 a 150 anexos, acompanhamento do progresso e retomada após interrupção.</p>
        <div class="import-actions"><a class="import-btn primary" href="importacao_anexos_cplus4.php">Abrir importação de anexos</a></div>
      </section>
      <section class="import-card source-card future">
        <span class="source-badge future">Preparado</span><div class="source-icon">+</div>
        <h2>Próximas integrações</h2>
        <p class="import-muted">A Central está preparada para receber novos conectores e outros cadastros sem alterar os fluxos já existentes.</p>
      </section>
    </div>
  <?php else: ?>
    <div class="import-nav">
      <div><a class="import-btn" href="importacao.php">← Trocar origem</a></div>
      <div><strong><?= $source === 'planilha' ? 'Importação por planilha' : 'Próspero ERP / Firebird' ?></strong></div>
    </div>
    <div class="import-grid">

    <?php if ($source === 'prospero'): ?>
      <section class="import-card import-full">
        <h2><span class="import-step">1</span>Conexão com o Próspero ERP / Firebird 5.0</h2>
        <p class="import-muted">A configuração pertence somente a esta empresa do Nexa. O módulo pode ser habilitado durante a migração e desabilitado ao final.</p>
        <form method="post"><input type="hidden" name="source" value="prospero">
          <div class="import-fields">
            <div class="import-field"><label>IP ou servidor</label><input name="fb_host" required value="<?= product_import_h($cfg['host'] ?? '') ?>" placeholder="127.0.0.1"></div>
            <div class="import-field"><label>Porta</label><input name="fb_port" type="number" min="1" value="<?= (int)($cfg['port'] ?? 3050) ?>"></div>
            <div class="import-field span-2"><label>Banco de dados / caminho do arquivo</label><input name="fb_database" required value="<?= product_import_h($cfg['database'] ?? '') ?>" placeholder="C:\PROSPERO\DADOS\EMPRESA.FDB ou /dados/empresa.fdb"></div>
            <div class="import-field"><label>Usuário</label><input name="fb_user" required value="<?= product_import_h($cfg['user'] ?? 'SYSDBA') ?>"></div>
            <div class="import-field"><label>Senha</label><input name="fb_password" type="password" value="" placeholder="Deixe em branco para manter a senha salva" autocomplete="new-password"></div>
            <div class="import-field"><label>Charset</label><input name="fb_charset" value="<?= product_import_h($cfg['charset'] ?? 'UTF8') ?>"></div>
            <div class="import-field"><label>Código da empresa no Firebird</label><input name="fb_empresa_codigo" type="number" min="0" value="<?= (int)($cfg['empresa_codigo'] ?? 1) ?>"><small class="import-muted">Use zero quando a tabela não possuir o campo EMPRESA.</small></div>
            <div class="import-field"><label>Tabela de produtos</label><input name="fb_table_products" value="<?= product_import_h($cfg['tables']['products'] ?? 'CAD_PRODUTOS') ?>"></div>
            <div class="import-field"><label>Tabela de grupos</label><input name="fb_table_groups" value="<?= product_import_h($cfg['tables']['groups'] ?? 'CAD_GRUPO') ?>"></div>
          </div>
          <div class="import-actions"><button class="import-btn" type="submit" name="action" value="save_config">Salvar configuração</button><button class="import-btn primary" type="submit" name="action" value="test_connection">Salvar e testar conexão</button><button class="import-btn" type="submit" name="action" value="load_source">Ler produtos e tributações</button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($source === 'planilha'): ?>
      <section class="import-card import-full">
        <h2><span class="import-step">1</span>Enviar planilha de produtos</h2>
        <p class="import-muted">Selecione a origem correta: no CPlus 4 a coluna ATIVO é obrigatória (1=ativo, 0=inativo), e os códigos devem estar em formato texto. Utilize o padrão de campos do Próspero ERP. O sistema procura automaticamente o cabeçalho e aceita as extensões XLSX, XLSM e CSV. Arquivos XLS antigos devem ser salvos novamente como XLSX.</p>
        <div class="required-columns"><span>CODIGO *</span><span>DESCRICAO *</span><span>GRUPO</span><span>DESCRICAO_GRUPO</span><span>UNIDADE</span><span>CODIGO_BARRA</span><span>NCM</span><span>CEST</span><span>COD_TRIBUTACAO</span><span>PRC_CUSTO</span><span>PRC_VENDA</span><span>REFERENCIA</span><span>ATIVO (CPlus 4 obrigatório)</span></div>
        <form method="post" enctype="multipart/form-data" style="margin-top:16px"><input type="hidden" name="source" value="planilha">
          <div class="import-fields">
            <div class="import-field"><label>Sistema de origem</label><select name="source_system"><option value="PROSPERO_EXCEL">Planilha tradicional / Próspero</option><option value="CPLUS4">CPlus 4</option></select></div>
            <div class="import-field span-2"><label>Arquivo da planilha</label><input type="file" name="product_file" required accept=".xlsx,.xlsm,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"><small class="import-muted">Limite: 20 MB e 100.000 produtos. Códigos e EAN devem preferencialmente estar formatados como texto para preservar zeros à esquerda.</small></div>
          </div>
          <div class="import-actions"><button class="import-btn primary" type="submit" name="action" value="upload_excel">Enviar, validar e visualizar</button><a class="import-btn" href="../assets/modelos/modelo_importacao_produtos_prospero.xlsx">Baixar planilha modelo</a></div>
        </form>
        <?php if (is_array($summary) && isset($summary['upload'])): ?>
          <div class="import-file-info">
            <div class="import-kpi"><small>Arquivo</small><strong style="font-size:14px"><?= product_import_h($summary['upload']['original_name'] ?? '') ?></strong></div>
            <div class="import-kpi"><small>Origem</small><strong><?= product_import_h(product_import_source_label((string)($summary['upload']['source_type'] ?? 'PROSPERO_EXCEL'))) ?></strong></div>
            <div class="import-kpi"><small>Aba localizada</small><strong style="font-size:16px"><?= product_import_h($summary['sheet'] ?? '') ?></strong></div>
            <div class="import-kpi"><small>Linha do cabeçalho</small><strong><?= (int)($summary['header_row'] ?? 0) ?></strong></div>
            <div class="import-kpi"><small>Produtos válidos</small><strong><?= (int)($summary['count'] ?? 0) ?></strong></div>
          </div>
          <?php foreach ((array)($summary['warnings'] ?? []) as $warning): ?><div class="import-msg warn" style="margin-top:10px;margin-bottom:0"><?= product_import_h($warning) ?></div><?php endforeach; ?>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (is_array($summary)): ?>
      <section class="import-card import-full">
        <h2><span class="import-step">2</span>Mapeamento manual das tributações</h2>
        <p class="import-muted">O código <strong>COD_TRIBUTACAO</strong> da origem é ligado a uma regra de tributação de produto cadastrada no Nexa. NCM e CEST vêm de cada item; os demais campos fiscais são copiados da regra escolhida.</p>
        <?php if (!$taxProfiles): ?><div class="import-msg err">Nenhuma tributação de produto foi cadastrada nesta empresa. Cadastre ao menos uma regra antes da importação.</div><?php endif; ?>
        <form method="post"><input type="hidden" name="source" value="<?= product_import_h($source) ?>"><?php if ($source === 'planilha'): ?><input type="hidden" name="upload_token" value="<?= product_import_h($uploadToken) ?>"><?php endif; ?>
          <div class="import-scroll"><table class="import-table" style="min-width:650px"><thead><tr><th>Código na origem</th><th>Tributação no Nexa</th></tr></thead><tbody>
          <?php if (empty($summary['tax_codes'])): ?><tr><td colspan="2">Nenhum código de tributação foi localizado. NCM e CEST ainda poderão ser importados.</td></tr><?php endif; ?>
          <?php foreach ((array)$summary['tax_codes'] as $sourceTax): ?><tr><td><strong class="import-code"><?= product_import_h($sourceTax) ?></strong></td><td><select name="tax_map[<?= product_import_h($sourceTax) ?>]" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:10px"><option value="0">— Vincular tributação —</option><?php foreach ($taxProfiles as $profile): ?><option value="<?= (int)$profile['id'] ?>" <?= (int)($taxMapping[$sourceTax] ?? 0)===(int)$profile['id']?'selected':'' ?>><?= product_import_h(trim((string)($profile['code'] ?? '')) . ' - ' . (string)$profile['name']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <div class="import-actions"><button class="import-btn primary" type="submit" name="action" value="<?= $source === 'planilha' ? 'save_excel_tax_map' : 'save_tax_map' ?>">Salvar mapeamento</button><a class="import-btn" href="fiscal_tributacoes.php" target="_blank">Abrir cadastro de tributações</a></div>
        </form>
      </section>

      <section class="import-card import-full">
        <h2><span class="import-step">3</span>Prévia dos produtos</h2>
        <p class="import-muted"><strong><?= (int)$summary['count'] ?></strong> produto(s) encontrado(s). A grade mostra somente os primeiros registros para conferência.</p>
        <div class="import-scroll"><table class="import-table"><thead><tr><th>Código</th><th>Descrição</th><th>Referência</th><th>Categoria</th><th>Un.</th><th>EAN</th><th>NCM</th><th>CEST</th><th>Trib.</th><th>Custo</th><th>Preço</th><th>Ativo</th></tr></thead><tbody>
        <?php foreach ((array)$summary['rows'] as $row): ?><tr><td class="import-code"><?= product_import_h($row['SOURCE_CODE'] ?? '') ?></td><td><strong><?= product_import_h($row['SOURCE_DESCRIPTION'] ?? '') ?></strong></td><td><?= product_import_h($row['SOURCE_REFERENCE'] ?? '') ?></td><td><?= product_import_h(($row['GROUP_DESCRIPTION'] ?? '') ?: ($row['SOURCE_GROUP'] ?? '')) ?></td><td><?= product_import_h($row['SOURCE_UNIT'] ?? '') ?></td><td><?= product_import_h($row['SOURCE_BARCODE'] ?? '') ?></td><td><?= product_import_h($row['SOURCE_NCM'] ?? '') ?></td><td><?= product_import_h($row['SOURCE_CEST'] ?? '') ?></td><td><?= product_import_h($row['SOURCE_TAX_CODE'] ?? '') ?></td><td>R$ <?= number_format(product_import_decimal($row['SOURCE_COST'] ?? 0),2,',','.') ?></td><td>R$ <?= number_format(product_import_decimal($row['SOURCE_PRICE'] ?? 0),2,',','.') ?></td><td><?= !empty(product_import_parse_active($row['SOURCE_ACTIVE'] ?? '')) ? 'Ativo' : 'Inativo' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </section>

      <section class="import-card import-full">
        <h2><span class="import-step">4</span>Executar importação</h2>
        <p class="import-muted">Os produtos são localizados pelo vínculo da origem, pelo código do Próspero, pelo número do cadastro e, como alternativa, pelo código de barras. Cada execução fica registrada para auditoria.</p>
        <form method="post" id="productImportExecuteForm"><input type="hidden" name="source" value="<?= product_import_h($source) ?>"><?php if ($source === 'planilha'): ?><input type="hidden" name="upload_token" value="<?= product_import_h($uploadToken) ?>"><?php endif; ?>
          <div class="import-fields">
            <label class="import-check"><input type="checkbox" name="create_new" value="1" checked><span><strong>Criar produtos novos</strong><br><small class="import-muted">Inclui códigos ainda inexistentes na empresa.</small></span></label>
            <label class="import-check"><input type="checkbox" name="update_existing" value="1" checked><span><strong>Atualizar produtos existentes</strong><br><small class="import-muted">Atualiza descrição, referência, categoria, unidade, EAN, NCM, CEST, tributação, custo, preço e situação ativo/inativo (quando informada).</small></span></label>
            <label class="import-check import-full"><input type="checkbox" name="confirm_import" value="1"><span><strong>Confirmo a importação para esta empresa</strong><br><small class="import-muted">Empresa Nexa de destino: #<?= (int)COMPANY_ID ?>. Esta ação altera o cadastro de produtos.</small></span></label>
          </div>
          <div class="import-actions">
            <?php if ($source === 'planilha'): ?>
              <button class="import-btn danger" type="button" id="productImportBatchStart">Importar produtos em lotes</button>
            <?php else: ?>
              <button class="import-btn danger" type="submit" name="action" value="run_import" onclick="return confirm('Confirma a importação dos produtos para esta empresa?')">Importar produtos agora</button>
            <?php endif; ?>
          </div>
          <?php if ($source === 'planilha'): ?>
          <div id="productImportProgress" class="import-msg ok" style="display:none;margin-top:14px;margin-bottom:0">
            <strong id="productImportProgressTitle">Preparando importação...</strong>
            <div style="height:14px;background:#dbe4f0;border-radius:999px;overflow:hidden;margin:10px 0 7px"><div id="productImportProgressBar" style="height:100%;width:0%;background:#0b1f5b;transition:width .2s ease"></div></div>
            <small id="productImportProgressText">0%</small>
          </div>
          <div id="productImportBatchResult" class="import-msg ok" style="display:none;margin-top:10px;margin-bottom:0"></div>
          <?php endif; ?>
        </form>
      </section>
    <?php else: ?>
      <section class="import-card import-full"><h2>Próxima etapa</h2><p class="import-muted"><?= $source === 'planilha' ? 'Envie a planilha para validar os campos, montar o vínculo fiscal e visualizar a prévia.' : 'Salve a conexão e clique em Ler produtos e tributações para montar o vínculo fiscal e visualizar a prévia.' ?></p></section>
    <?php endif; ?>

    <section class="import-card import-full">
      <h2>Histórico das importações</h2>
      <div class="import-scroll"><table class="import-table"><thead><tr><th>Execução</th><th>Início</th><th>Integração</th><th>Status</th><th>Origem</th><th>Incluídos</th><th>Atualizados</th><th>Ignorados</th><th>Erros</th></tr></thead><tbody>
      <?php if (!$runs): ?><tr><td colspan="9">Nenhuma importação executada.</td></tr><?php endif; ?>
      <?php foreach ($runs as $run): $runSource=(string)($run['source_type'] ?? 'PROSPERO_FIREBIRD'); ?><tr><td>#<?= (int)$run['id'] ?></td><td><?= product_import_h(date('d/m/Y H:i', strtotime((string)$run['started_at']))) ?></td><td><?= product_import_h(product_import_source_label($runSource)) ?></td><td><span class="import-status"><?= product_import_h($run['status']) ?></span></td><td><?= product_import_h(($run['source_file_name'] ?? '') ?: ($run['source_database'] ?? '')) ?><?php if ($runSource==='PROSPERO_FIREBIRD'): ?><br><small>Empresa FB: <?= (int)($run['source_company_code'] ?? 0) ?></small><?php endif; ?></td><td><?= (int)$run['inserted_count'] ?></td><td><?= (int)$run['updated_count'] ?></td><td><?= (int)$run['skipped_count'] ?></td><td><?= (int)$run['error_count'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
    </div>
  <?php endif; ?>
</div>
<?php if ($source === 'planilha' && is_array($summary) && isset($summary['upload'])): ?>
<script>
(function(){
  const form=document.getElementById('productImportExecuteForm');
  const start=document.getElementById('productImportBatchStart');
  const progress=document.getElementById('productImportProgress');
  const title=document.getElementById('productImportProgressTitle');
  const text=document.getElementById('productImportProgressText');
  const bar=document.getElementById('productImportProgressBar');
  const resultBox=document.getElementById('productImportBatchResult');
  if(!form||!start||!progress||!title||!text||!bar||!resultBox)return;
  let running=false;
  const expectedTotal=<?= (int)($summary['count'] ?? 0) ?>;
  function makeSession(){
    try{const a=new Uint8Array(16);crypto.getRandomValues(a);return Array.from(a,b=>b.toString(16).padStart(2,'0')).join('');}
    catch(e){return (Date.now().toString(16)+Math.random().toString(16).slice(2)).replace(/[^a-f0-9]/g,'').padEnd(32,'0').slice(0,32);}
  }
  function updateProgress(r){
    const total=Number(r.data_total||expectedTotal||0);
    const done=Number(r.next_offset||0);
    const pct=total>0?Math.min(100,Math.round((done/total)*100)):100;
    bar.style.width=pct+'%';
    title.textContent='Importando produtos... '+done+' de '+total;
    const c=r.cumulative||{};
    text.textContent=pct+'% · último lote: '+Number(r.processed||0)+' linha(s) em '+Number(r.elapsed_seconds||0).toFixed(1)+'s · incluídos: '+Number(c.inserted||0)+' · atualizados: '+Number(c.updated||0)+' · ignorados: '+Number(c.skipped||0)+' · erros: '+Number(c.errors||0);
  }
  async function requestBatch(offset,session,retry){
    const fd=new FormData(form);
    fd.set('action','run_excel_import_batch');
    fd.set('batch_offset',String(offset));
    fd.set('batch_size','150');
    fd.set('batch_seconds','12');
    fd.set('batch_session',session);
    try{
      const response=await fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
      let data=null;try{data=await response.json();}catch(e){}
      if(!response.ok||!data||data.ok!==true)throw new Error((data&&data.error)?data.error:'Falha ao importar o lote.');
      return data.result||{};
    }catch(e){
      if(retry<3){
        title.textContent='Conexão interrompida. Repetindo o mesmo lote com segurança...';
        await new Promise(resolve=>setTimeout(resolve,1500*(retry+1)));
        return requestBatch(offset,session,retry+1);
      }
      throw e;
    }
  }
  start.addEventListener('click',async function(){
    if(running)return;
    const confirmBox=form.querySelector('input[name="confirm_import"]');
    if(!confirmBox||!confirmBox.checked){alert('Marque a confirmação antes de iniciar a importação.');return;}
    if(!window.confirm('Confirma a importação dos produtos para esta empresa? O processamento será feito em lotes de até 150 produtos para evitar timeout.'))return;
    const session=makeSession();
    let offset=0;
    running=true;start.disabled=true;progress.style.display='block';progress.className='import-msg ok';resultBox.style.display='none';bar.style.width='0%';
    title.textContent='Preparando importação em lotes...';text.textContent='0%';
    try{
      while(true){
        const r=await requestBatch(offset,session,0);
        updateProgress(r);
        const next=Number(r.next_offset||0);
        if(r.done){
          const c=r.cumulative||{};
          title.textContent='Importação concluída.';bar.style.width='100%';
          text.textContent='100% · todos os lotes processados.';
          resultBox.className=Number(c.errors||0)>0?'import-msg warn':'import-msg ok';
          resultBox.innerHTML='<strong>Resultado:</strong> '+Number(c.inserted||0)+' incluído(s), '+Number(c.updated||0)+' atualizado(s), '+Number(c.skipped||0)+' ignorado(s) e '+Number(c.errors||0)+' erro(s).';
          resultBox.style.display='block';
          break;
        }
        if(next<=offset)throw new Error('O lote não avançou. A importação foi interrompida para evitar repetição infinita.');
        offset=next;
      }
    }catch(e){
      progress.className='import-msg err';title.textContent='Importação interrompida.';text.textContent=e&&e.message?e.message:String(e);
      resultBox.className='import-msg warn';resultBox.innerHTML='<strong>Importante:</strong> os lotes já confirmados permanecem gravados. Você pode iniciar novamente; os produtos existentes serão reconhecidos pelo vínculo da origem e não serão duplicados.';resultBox.style.display='block';
    }finally{running=false;start.disabled=false;}
  });
})();
</script>
<?php endif; ?>
<?php admin_footer(); ?>
