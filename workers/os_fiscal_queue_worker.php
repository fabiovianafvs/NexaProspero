<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/os_fiscal_processor.php';
require_once $root . '/config/helpers.php';
// Mesmo horário do site (Brasília) no PHP e na sessão do MySQL: sem isso as datas
// gravadas pelo worker ficavam 3 horas adiantadas e um item em transmissão pela tela
// parecia "abandonado" e era transmitido de novo.
app_apply_company_timezone($pdo, 'America/Sao_Paulo');

$options = getopt('', ['once','batch::','company::','help']);
if (isset($options['help'])) {
    echo "Fila fiscal exclusiva da Ordem de Serviço\n";
    echo "php workers/os_fiscal_queue_worker.php --once [--batch=10] [--company=1]\n";
    echo "A v1.10 não retransmite automaticamente documentos com erro.\n";
    exit(0);
}
$batch = max(1,min(50,(int)($options['batch'] ?? 10)));
$companyFilter = max(0,(int)($options['company'] ?? 0));
$once = array_key_exists('once',$options);
os_fiscal_queue_ensure_schema($pdo);

function osfq_log(string $level,string $message): void
{
    $line = '['.date('Y-m-d H:i:s').'] ['.$level.'] '.$message.PHP_EOL;
    fwrite($level === 'ERRO' ? STDERR : STDOUT,$line);
}

$processed=0;
do {
    // Recupera apenas processamento abandonado há mais de 10 minutos.
    // Nunca recupera item cuja trava ainda está ativa (transmissão em andamento).
    $pdo->exec("UPDATE os_fiscal_queue SET status='PENDENTE',started_at=NULL,scheduled_at=NOW(),updated_at=NOW(),error_message=CONCAT('Recuperado após interrupção. ',COALESCE(error_message,'')) WHERE status='PROCESSANDO' AND started_at < NOW() - INTERVAL 10 MINUTE AND IS_FREE_LOCK(CONCAT('nexa_osfq_',company_id,'_',os_order_id,'_',UPPER(TRIM(document_type))))=1");

    $where="status='PENDENTE' AND (scheduled_at IS NULL OR scheduled_at<=NOW())";
    $params=array();
    if($companyFilter>0){$where.=' AND company_id=?';$params[]=$companyFilter;}
    $st=$pdo->prepare("SELECT id,company_id,document_type,os_order_id FROM os_fiscal_queue WHERE $where ORDER BY priority ASC,id ASC LIMIT ".$batch);
    $st->execute($params);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: array();
    if(!$rows)break;

    foreach($rows as $row){
        $result=os_fiscal_process_queue_job($pdo,(int)$row['company_id'],(int)$row['id']);
        if(!empty($result['ok'])){
            osfq_log('OK','#'.(int)$row['id'].' O.S. #'.(int)$row['os_order_id'].' '.(string)$result['type_label'].' concluída.');
        }else{
            osfq_log('ERRO','#'.(int)$row['id'].' '.(string)($result['message'] ?? 'Falha no processamento').' (aguardando ação manual)');
        }
        $processed++;
    }
    if($once)break;
    usleep(500000);
}while(true);

if($processed===0)osfq_log('OK','Nenhum documento pendente na fila fiscal da O.S.');
exit(0);
