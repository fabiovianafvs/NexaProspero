<?php
declare(strict_types=1);
/** Importação e exportação de tributação por empresa, sem reutilizar IDs externos. */

function nexa_tax_allowed_fields(): array {
  return [
    'code',
    'name',
    'is_active',
    'profile_type',
    'tax_mode',
    'nbs_code',
    'nbs_description',
    'lc116_service_code',
    'lc116_service_description',
    'iss_rate',
    'iss_withheld',
    'pis_cofins_retention_type',
    'irrf_rate',
    'csll_rate',
    'inss_rate',
    'municipal_tax_code',
    'cnae',
    'fiscal_incentive',
    'nfse_municipal_tax_type',
    'nfse_municipal_tax_nature',
    'nfse_municipal_iss_exigibility',
    'nfse_municipal_special_regime',
    'nfse_national_tax_type',
    'nfse_national_tax_code',
    'nfse_national_operation_indicator',
    'nfse_simples_tax_regime',
    'nfse_operation_payment_type',
    'nfse_rtc_special_regime',
    'municipal_benefit_type',
    'municipal_differentiated_rate',
    'municipal_reduction_base_percent',
    'municipal_benefit_number',
    'ibs_cbs_cst',
    'ibs_cbs_classification_code',
    'ibs_cbs_classification_name',
    'icms_rate',
    'icms_reduction_rate',
    'icms_cst',
    'icms_csosn',
    'icms_st_rate',
    'ipi_rate',
    'ipi_cst',
    'pis_in_rate',
    'pis_in_cst',
    'cofins_in_rate',
    'cofins_in_cst',
    'pis_out_rate',
    'pis_out_cst',
    'cofins_out_rate',
    'cofins_out_cst',
    'cbs_rate',
    'ibs_uf_rate',
    'ibs_mun_rate',
    'ibs_cbs_rate_mode',
    'cfop_sale_state',
    'cfop_sale_state_desc',
    'cfop_sale_interstate',
    'cfop_sale_interstate_desc',
    'cfop_return_state',
    'cfop_return_state_desc',
    'cfop_return_interstate',
    'cfop_return_interstate_desc',
    'cfop_entry_state',
    'cfop_entry_state_desc',
    'cfop_entry_interstate',
    'cfop_entry_interstate_desc',
    'notes'
  ];
}

function nexa_tax_export(PDO $pdo, int $companyId): never {
  if ($companyId <= 0) throw new RuntimeException('Empresa de tributação inválida.');
  $profiles = fiscal_tax_profiles($pdo, $companyId, false);
  $allowed = array_flip(nexa_tax_allowed_fields());
  $st = $pdo->prepare('SELECT t.cfop_in_state, t.cfop_in_interstate, t.cfop_out_state, t.cfop_out_interstate, t.enabled, t.notes, o.code AS operation_code, o.direction AS operation_direction
    FROM fiscal_tax_profile_operation_cfops t
    INNER JOIN fiscal_operations o ON o.id=t.operation_id AND o.company_id=t.company_id
    WHERE t.company_id=? AND t.tax_profile_id=? ORDER BY o.direction,o.code');
  $rows = [];
  foreach ($profiles as $row) {
    $values = array_intersect_key($row, $allowed);
    $st->execute([$companyId, (int)$row['id']]);
    $ops = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows[] = ['fields'=>$values, 'operation_cfops'=>$ops];
  }
  $json = json_encode(['format'=>'nexa_fiscal_tributacoes','version'=>1,'exported_at'=>date(DATE_ATOM),'profiles'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  header('Content-Type: application/json; charset=UTF-8');
  header('Content-Disposition: attachment; filename="tributacoes_nexa_' . date('Ymd_His') . '.json"');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: private, no-store');
  echo $json;
  exit;
}

function nexa_tax_import(PDO $pdo, int $companyId, array $file): array {
  if ($companyId <= 0) throw new RuntimeException('Empresa de importação inválida.');
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new RuntimeException('Selecione o JSON exportado pelo Nexa.');
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0 || $size > 5*1024*1024) throw new RuntimeException('O arquivo deve ter até 5 MB.');
  $json = file_get_contents((string)$file['tmp_name']);
  $doc = json_decode((string)$json, true, 64, JSON_THROW_ON_ERROR);
  if (!is_array($doc) || ($doc['format'] ?? '') !== 'nexa_fiscal_tributacoes' || ($doc['version'] ?? null) !== 1 || !is_array($doc['profiles'] ?? null)) {
    throw new RuntimeException('Formato de tributação incompatível com o Nexa.');
  }
  $items = $doc['profiles'];
  if (count($items) > 1000) throw new RuntimeException('O arquivo excede o limite de 1.000 perfis.');
  $allowed = array_flip(nexa_tax_allowed_fields());
  $acceptedOps = ['cfop_in_state','cfop_in_interstate','cfop_out_state','cfop_out_interstate','enabled','notes'];
  $opMap = [];
  foreach (fiscal_operations($pdo, $companyId, '', false) as $op) {
    $key = strtoupper(trim((string)($op['direction'] ?? ''))) . '|' . strtoupper(trim((string)($op['code'] ?? '')));
    if (trim((string)($op['code'] ?? '')) !== '' && !isset($opMap[$key])) $opMap[$key] = (int)$op['id'];
  }
  // Pré-validação integral: nada é gravado se houver linha inválida.
  $normalized = []; $seen = [];
  foreach ($items as $i=>$item) {
    if (!is_array($item) || !is_array($item['fields'] ?? null)) throw new RuntimeException('Perfil inválido na posição ' . ($i+1));
    $row = array_intersect_key($item['fields'], $allowed);
    foreach ($row as $field=>$value) {
      if ($value !== null && !is_scalar($value)) throw new RuntimeException('Valor inválido no campo ' . $field . ' da posição ' . ($i+1));
    }
    $code = trim((string)($row['code'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    $type = strtoupper(trim((string)($row['profile_type'] ?? 'PRODUCT')));
    if ($name === '' || strlen($name) > 150 || strlen($code) > 30 || !in_array($type, ['PRODUCT','SERVICE'], true)) throw new RuntimeException('Nome, código ou tipo inválido na linha ' . ($i+1));
    $row['code']=$code; $row['name']=$name; $row['profile_type']=$type;
    $key = $type.'|'.($code !== '' ? 'code:'.$code : 'name:'.mb_strtolower($name));
    if (isset($seen[$key])) throw new RuntimeException('Perfil duplicado no arquivo: ' . $name);
    $seen[$key]=1;
    $ops = $item['operation_cfops'] ?? [];
    if (!is_array($ops) || count($ops)>250) throw new RuntimeException('Operações inválidas no perfil ' . $name);
    $cleanOps=[];
    foreach ($ops as $op) {
      if (!is_array($op)) throw new RuntimeException('Operação fiscal inválida no perfil ' . $name);
      $keyOp=strtoupper(trim((string)($op['operation_direction']??''))).'|'.strtoupper(trim((string)($op['operation_code']??'')));
      $cleanOps[]=['operation_key'=>$keyOp,'values'=>array_intersect_key($op,array_flip($acceptedOps))];
    }
    $normalized[]=['fields'=>$row, 'operations'=>$cleanOps];
  }

  $inserted=0; $updated=0; $opSaved=0; $opSkipped=0;
  $pdo->beginTransaction();
  try {
    $qCode = $pdo->prepare('SELECT id FROM fiscal_tax_profiles WHERE company_id=? AND profile_type=? AND code=? ORDER BY id LIMIT 1');
    $qName = $pdo->prepare('SELECT id FROM fiscal_tax_profiles WHERE company_id=? AND profile_type=? AND name=? ORDER BY id LIMIT 1');
    $opInsert = $pdo->prepare('INSERT INTO fiscal_tax_profile_operation_cfops (company_id,tax_profile_id,operation_id,cfop_in_state,cfop_in_interstate,cfop_out_state,cfop_out_interstate,enabled,notes,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfop_in_state=VALUES(cfop_in_state),cfop_in_interstate=VALUES(cfop_in_interstate),cfop_out_state=VALUES(cfop_out_state),cfop_out_interstate=VALUES(cfop_out_interstate),enabled=VALUES(enabled),notes=VALUES(notes),updated_at=NOW()');
    foreach ($normalized as $item) {
      $f=$item['fields'];
      $profileId=0;
      if ($f['code'] !== '') {
        $qCode->execute([$companyId,$f['profile_type'],$f['code']]);
        $profileId=(int)($qCode->fetchColumn()?:0);
      } else {
        $qName->execute([$companyId,$f['profile_type'],$f['name']]);
        $profileId=(int)($qName->fetchColumn()?:0);
      }
      $cols=array_keys($f);
      // Os nomes de colunas são exclusivamente os da lista fixa acima.
      if ($profileId>0) {
        $assign=implode(',',array_map(static fn($key)=>'`'.$key.'`=?',$cols));
        $stmt=$pdo->prepare('UPDATE fiscal_tax_profiles SET '.$assign.', updated_at=NOW() WHERE company_id=? AND id=?');
        $stmt->execute(array_merge(array_values($f),[$companyId,$profileId]));
        $updated++;
      } else {
        $list=implode(',',array_map(static fn($key)=>'`'.$key.'`',$cols));
        $stmt=$pdo->prepare('INSERT INTO fiscal_tax_profiles (`company_id`,'.$list.') VALUES (?,'.implode(',',array_fill(0,count($cols),'?')).')');
        $stmt->execute(array_merge([$companyId],array_values($f)));
        $profileId=(int)$pdo->lastInsertId();
        $inserted++;
      }
      foreach ($item['operations'] as $op) {
        $operationId=(int)($opMap[$op['operation_key']]??0);
        if (!$operationId) {$opSkipped++; continue;}
        $v=$op['values'];
        $cfops=[];
        foreach (['cfop_in_state','cfop_in_interstate','cfop_out_state','cfop_out_interstate'] as $column) {
          $raw=trim((string)($v[$column]??''));
          if ($raw!=='' && !preg_match('/^[0-9]{4}$/',$raw)) throw new RuntimeException('CFOP inválido para a operação '.$op['operation_key']);
          $cfops[]=$raw;
        }
        $opInsert->execute(array_merge([$companyId,$profileId,$operationId],$cfops,[(int)!empty($v['enabled']),mb_substr((string)($v['notes']??''),0,255)]));
        $opSaved++;
      }
      // Não propagar retroativamente alterações às operações dos produtos já faturados.
    }
    $pdo->commit();
  } catch(Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  return compact('inserted','updated','opSaved','opSkipped');
}
