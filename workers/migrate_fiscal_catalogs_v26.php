<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root=dirname(__DIR__);
require_once $root.'/config/nexa_env.php';
require $root.'/config/pdo.php';
require_once $root.'/config/fiscal_module.php';

const V26_VERSION = 2026072606;
$errors=[];$stats=['settings'=>0,'backup'=>0,'ncm'=>0,'cest'=>0,'ibpt'=>0,'class'=>0,'cbs'=>0,'ibsuf'=>0,'ibsmun'=>0,'cities'=>0,'states'=>0,'disabled'=>0];
try{fiscal_ensure_schema($pdo);fiscal_ensure_aux_schema($pdo);echo "[OK] Estrutura v2.6 verificada.\n";}catch(Throwable $e){$errors[]='Estrutura: '.$e->getMessage();}

$already=false;
try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS nexa_schema_versions (module_name VARCHAR(80) NOT NULL,version_number BIGINT NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,notes VARCHAR(255) NULL,PRIMARY KEY(module_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $st=$pdo->prepare("SELECT version_number FROM nexa_schema_versions WHERE module_name='fiscal_global_catalogs' LIMIT 1");$st->execute();$already=(int)$st->fetchColumn()>=V26_VERSION;
}catch(Throwable $e){$errors[]='Versão: '.$e->getMessage();}

if(!$already){
  try{
    $companies=[];
    if(fiscal_table_exists($pdo,'companies'))$companies=$pdo->query('SELECT id FROM companies ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)?:[];
    $ins=$pdo->prepare("INSERT INTO fiscal_company_tax_settings (company_id,ibpt_uf,strict_global_catalogs,allow_manual_ibs_cbs_rates,updated_at) VALUES (?,?,1,1,NOW()) ON DUPLICATE KEY UPDATE ibpt_uf=COALESCE(NULLIF(ibpt_uf,''),VALUES(ibpt_uf)),strict_global_catalogs=1,updated_at=NOW()");
    foreach($companies as $cid){
      $uf='';
      try{$st=$pdo->prepare('SELECT uf FROM fiscal_company_config WHERE company_id=? LIMIT 1');$st->execute([(int)$cid]);$uf=strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)$st->fetchColumn()),0,2));}catch(Throwable $e){}
      $ins->execute([(int)$cid,$uf!==''?$uf:null]);$stats['settings']++;
    }
    echo "[OK] Configurações individuais preparadas: {$stats['settings']} empresa(s).\n";
  }catch(Throwable $e){$errors[]='Configurações: '.$e->getMessage();}

  $tables=[
    'fiscal_ncms'=>fn($r)=>(string)($r['ncm']??''),
    'fiscal_cests'=>fn($r)=>(string)($r['cest']??'').'|'.(string)($r['ncm']??''),
    'fiscal_ibs_cbs_classifications'=>fn($r)=>(string)($r['classification_code']??''),
    'fiscal_cbs_rates'=>fn($r)=>(string)($r['code']??'').'|'.(string)($r['validity_start']??''),
    'fiscal_ibs_uf_rates'=>fn($r)=>(string)($r['uf']??'').'|'.(string)($r['validity_start']??''),
    'fiscal_ibs_mun_rates'=>fn($r)=>(string)($r['ibge_code']??'').'|'.(string)($r['validity_start']??''),
    'fiscal_cities'=>fn($r)=>(string)($r['ibge_code']??''),
    'fiscal_states'=>fn($r)=>(string)($r['uf']??''),
  ];
  try{
    $bak=$pdo->prepare("INSERT INTO fiscal_catalog_legacy_backup (table_name,company_id,record_key,payload_json)
      SELECT ?,?,?,? FROM DUAL
      WHERE NOT EXISTS (
        SELECT 1 FROM fiscal_catalog_legacy_backup
        WHERE table_name=? AND company_id=? AND record_key <=> ? AND payload_json=?
      )");
    $upNcm=$pdo->prepare("INSERT INTO fiscal_ncms (company_id,ncm,description,cest,segment_code,segment_name,is_active,source,updated_at) VALUES (0,?,?,?,?,?,1,'MIGRADO_V26',NOW()) ON DUPLICATE KEY UPDATE description=IF(COALESCE(description,'')='',VALUES(description),description),is_active=1,updated_at=NOW()");
    $upCest=$pdo->prepare("INSERT INTO fiscal_cests (company_id,cest,ncm,description,segment_code,segment_name,mva_rate,icms_reduction_rate,validity_start,validity_end,is_active,updated_at) VALUES (0,?,?,?,?,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE description=IF(COALESCE(description,'')='',VALUES(description),description),segment_code=IF(COALESCE(segment_code,'')='',VALUES(segment_code),segment_code),segment_name=IF(COALESCE(segment_name,'')='',VALUES(segment_name),segment_name),is_active=1,updated_at=NOW()");
    $upIbpt=$pdo->prepare("INSERT INTO fiscal_ibpt_rates (uf,code_type,code,ex_code,description,federal_national,federal_imported,state_rate,municipal_rate,version,validity_start,validity_end,source,is_active,updated_at) VALUES (?,'NCM',?,'',?,?,?,?,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description),federal_national=VALUES(federal_national),federal_imported=VALUES(federal_imported),state_rate=VALUES(state_rate),municipal_rate=VALUES(municipal_rate),validity_end=VALUES(validity_end),source=VALUES(source),is_active=1,updated_at=NOW()");
    // As tabelas oficiais não devem receber sobrescrita de uma empresa. Na
    // migração, uma linha local só preenche o catálogo mestre quando a chave
    // global ainda não existe. Registros globais existentes permanecem soberanos.
    $upClass=$pdo->prepare("INSERT IGNORE INTO fiscal_ibs_cbs_classifications
      (company_id,classification_code,description,cst_code,rate_type,ibs_reduction_rate,cbs_reduction_rate,validity_start,validity_end,can_nfse,can_nfe,can_nfce,is_active,notes,updated_at)
      VALUES (0,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $upCbs=$pdo->prepare("INSERT INTO fiscal_cbs_rates
      (company_id,code,validity_start,validity_end,rate,is_active,notes,updated_at)
      SELECT 0,?,?,?,?,1,?,NOW() FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM fiscal_cbs_rates WHERE company_id=0 AND COALESCE(code,'')=COALESCE(?, '') AND validity_start <=> ? AND validity_end <=> ?)");
    $upIbsUf=$pdo->prepare("INSERT INTO fiscal_ibs_uf_rates
      (company_id,uf,validity_start,validity_end,rate,is_active,notes,updated_at)
      SELECT 0,?,?,?,?,1,?,NOW() FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM fiscal_ibs_uf_rates WHERE company_id=0 AND uf=? AND validity_start <=> ? AND validity_end <=> ?)");
    $upIbsMun=$pdo->prepare("INSERT INTO fiscal_ibs_mun_rates
      (company_id,ibge_code,city_name,uf,validity_start,validity_end,rate,is_active,notes,updated_at)
      SELECT 0,?,?,?,?,?,?,1,?,NOW() FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM fiscal_ibs_mun_rates WHERE company_id=0 AND ibge_code=? AND validity_start <=> ? AND validity_end <=> ?)");
    $upCity=$pdo->prepare("INSERT INTO fiscal_cities (company_id,ibge_code,name,uf,is_active,updated_at)
      VALUES (0,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE name=IF(COALESCE(name,'')='',VALUES(name),name),uf=IF(COALESCE(uf,'')='',VALUES(uf),uf),is_active=1,updated_at=NOW()");
    $upState=$pdo->prepare("INSERT INTO fiscal_states (company_id,ibge_code,name,uf,timezone_normal,timezone_dst,is_active,updated_at)
      VALUES (0,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE name=IF(COALESCE(name,'')='',VALUES(name),name),timezone_normal=COALESCE(timezone_normal,VALUES(timezone_normal)),timezone_dst=COALESCE(timezone_dst,VALUES(timezone_dst)),is_active=1,updated_at=NOW()");
    $ufByCompany=[];
    $stUf=$pdo->query('SELECT company_id,ibpt_uf FROM fiscal_company_tax_settings');foreach($stUf->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$ufByCompany[(int)$r['company_id']]=strtoupper((string)$r['ibpt_uf']);
    foreach($tables as $table=>$keyFn){
      if(!fiscal_table_exists($pdo,$table))continue;
      $rows=$pdo->query("SELECT * FROM `{$table}` WHERE company_id<>0")->fetchAll(PDO::FETCH_ASSOC)?:[];
      foreach($rows as $r){
        $payload=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $recordKey=$keyFn($r);
        $bak->execute([$table,(int)$r['company_id'],$recordKey,$payload,$table,(int)$r['company_id'],$recordKey,$payload]);
        $stats['backup'] += $bak->rowCount();
        if($table==='fiscal_ncms' && strlen((string)($r['ncm']??''))===8){
          $upNcm->execute([$r['ncm'],$r['description']??null,$r['cest']??null,$r['segment_code']??null,$r['segment_name']??null]);$stats['ncm']++;
          $hasIbpt=(float)($r['ibpt_federal_national']??0)!==0.0 || (float)($r['ibpt_federal_imported']??0)!==0.0 || (float)($r['ibpt_state']??0)!==0.0 || (float)($r['ibpt_municipal']??0)!==0.0 || trim((string)($r['ibpt_version']??''))!=='';
          $uf=$ufByCompany[(int)$r['company_id']]??'';
          if($hasIbpt && $uf!==''){
            $upIbpt->execute([$uf,$r['ncm'],$r['description']??null,$r['ibpt_federal_national']??0,$r['ibpt_federal_imported']??0,$r['ibpt_state']??0,$r['ibpt_municipal']??0,$r['ibpt_version']??'LEGADO',$r['validity_start']?:'1900-01-01',$r['validity_end']?:null,$r['source']?:'LEGADO_V26']);$stats['ibpt']++;
          }
        }
        if($table==='fiscal_cests' && trim((string)($r['cest']??''))!=='' && trim((string)($r['ncm']??''))!==''){
          $upCest->execute([$r['cest'],$r['ncm'],$r['description']??null,$r['segment_code']??null,$r['segment_name']??null,$r['mva_rate']??0,$r['icms_reduction_rate']??0,$r['validity_start']?:null,$r['validity_end']?:null]);$stats['cest']++;
        }
        if($table==='fiscal_ibs_cbs_classifications' && trim((string)($r['classification_code']??''))!==''){
          $upClass->execute([$r['classification_code'],$r['description']??'Classificação migrada', $r['cst_code']??null,$r['rate_type']??null,$r['ibs_reduction_rate']??0,$r['cbs_reduction_rate']??0,$r['validity_start']?:null,$r['validity_end']?:null,$r['can_nfse']??0,$r['can_nfe']??0,$r['can_nfce']??0,$r['is_active']??1,$r['notes']??'MIGRADO_V26']);
          $stats['class'] += $upClass->rowCount();
        }
        if($table==='fiscal_cbs_rates'){
          $code=trim((string)($r['code']??''));$ini=$r['validity_start']?:null;$fim=$r['validity_end']?:null;
          $upCbs->execute([$code!==''?$code:null,$ini,$fim,$r['rate']??0,$r['notes']??'MIGRADO_V26',$code,$ini,$fim]);$stats['cbs'] += $upCbs->rowCount();
        }
        if($table==='fiscal_ibs_uf_rates' && trim((string)($r['uf']??''))!==''){
          $uf=strtoupper(substr((string)$r['uf'],0,2));$ini=$r['validity_start']?:null;$fim=$r['validity_end']?:null;
          $upIbsUf->execute([$uf,$ini,$fim,$r['rate']??0,$r['notes']??'MIGRADO_V26',$uf,$ini,$fim]);$stats['ibsuf'] += $upIbsUf->rowCount();
        }
        if($table==='fiscal_ibs_mun_rates' && trim((string)($r['ibge_code']??''))!==''){
          $ibge=substr(preg_replace('/\D+/','',(string)$r['ibge_code'])?:'',0,7);$ini=$r['validity_start']?:null;$fim=$r['validity_end']?:null;
          if($ibge!==''){$upIbsMun->execute([$ibge,$r['city_name']??null,$r['uf']??null,$ini,$fim,$r['rate']??0,$r['notes']??'MIGRADO_V26',$ibge,$ini,$fim]);$stats['ibsmun'] += $upIbsMun->rowCount();}
        }
        if($table==='fiscal_cities' && trim((string)($r['ibge_code']??''))!=='' && trim((string)($r['uf']??''))!==''){
          $upCity->execute([$r['ibge_code'],$r['name']??'Município migrado',strtoupper(substr((string)$r['uf'],0,2))]);$stats['cities'] += $upCity->rowCount()>0?1:0;
        }
        if($table==='fiscal_states' && trim((string)($r['ibge_code']??''))!=='' && trim((string)($r['uf']??''))!==''){
          $upState->execute([$r['ibge_code'],$r['name']??'UF migrada',strtoupper(substr((string)$r['uf'],0,2)),$r['timezone_normal']??null,$r['timezone_dst']??null]);$stats['states'] += $upState->rowCount()>0?1:0;
        }
      }
      if(fiscal_column_exists($pdo,$table,'is_active')){$stats['disabled']+=(int)$pdo->exec("UPDATE `{$table}` SET is_active=0 WHERE company_id<>0 AND is_active<>0");}
    }
    echo "[OK] Legado preservado: {$stats['backup']} registro(s); cópias locais desativadas: {$stats['disabled']}.\n";
    echo "[OK] Promovidos ao global: NCM {$stats['ncm']}, CEST {$stats['cest']}, IBPT {$stats['ibpt']}, cClassTrib {$stats['class']}, CBS {$stats['cbs']}, IBS-UF {$stats['ibsuf']}, IBS-Mun {$stats['ibsmun']}, municípios {$stats['cities']}, UFs {$stats['states']}.\n";
  }catch(Throwable $e){$errors[]='Migração de catálogos: '.$e->getMessage();}

  try{
    $pdo->exec("INSERT INTO fiscal_ibs_cbs_classifications (company_id,classification_code,description,cst_code,rate_type,ibs_reduction_rate,cbs_reduction_rate,validity_start,validity_end,can_nfe,can_nfce,is_active,notes,updated_at) VALUES (0,'000001','Situações tributadas integralmente pelo IBS e CBS','000','PADRAO',0,0,'2026-01-01',NULL,1,1,1,'Catálogo global oficial; confirmar enquadramento do produto.',NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description),cst_code='000',can_nfe=1,can_nfce=1,is_active=1,updated_at=NOW()");
    echo "[OK] Classificação global 000001 validada para NF-e/NFC-e.\n";
  }catch(Throwable $e){$errors[]='Classificação global: '.$e->getMessage();}

  if(!$errors){
    try{
      $st=$pdo->prepare("INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes) VALUES ('fiscal_global_catalogs',?,NOW(),'Nexa Fiscal v2.6 - catálogos globais e tributações isoladas por empresa') ON DUPLICATE KEY UPDATE version_number=VALUES(version_number),applied_at=NOW(),notes=VALUES(notes)");$st->execute([V26_VERSION]);
    }catch(Throwable $e){$errors[]='Gravação da versão: '.$e->getMessage();}
  }
}else{echo "[OK] Migração v2.6 já aplicada; nenhuma cópia local foi reprocessada.\n";}

if($errors){fwrite(STDERR,"Migração concluída com erros:\n- ".implode("\n- ",$errors)."\n");exit(1);}echo "[OK] Nexa Fiscal v2.6 concluído.\n";
