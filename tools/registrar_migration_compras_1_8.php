<?php
declare(strict_types=1);
// Uso exclusivo em CLI, comando explícito do operador; altera apenas registry, nunca executa SQL.
if (PHP_SAPI!=='cli') exit("Somente CLI.\n");
$root=dirname(__DIR__);$file=$root.'/config/schema_updater.php';
$migration=$root.'/db/20261014_compras_listas_v1_8.sql';
if (!is_file($file)||!is_file($migration)) exit("Arquivos ausentes.\n");
$content=file_get_contents($file);
$id='20261014_compras_listas_v1_8';
if (strpos($content,"'id' => '$id'")!==false) exit("Migration já registrada. Nenhuma alteração.\n");
$sha=hash_file('sha256',$migration);
$needle="return [";
$pos=strpos($content,$needle,strpos($content,'function nexa_schema_updater_registry'));
if ($pos===false) exit("Estrutura do registry desconhecida. Nenhuma alteração.\n");
$entry="\n      [\n        'id' => '$id',\n        'module' => 'compras_listas',\n        'version' => 20261014180,\n        'label' => 'Compras Fase 1.8 — Listas persistentes',\n        'description' => 'Cria listas de compras em rascunho por empresa, sem integração com estoque, PDV ou fiscal.',\n        'file' => __DIR__ . '/../db/20261014_compras_listas_v1_8.sql',\n        'sha256' => '$sha',\n        'order' => 20261014180,\n      ],";
$patched=substr_replace($content,$entry,$pos+strlen($needle),0);
$tmp=tempnam(dirname($file),'compras_registry_');file_put_contents($tmp,$patched);
exec('php -l '.escapeshellarg($tmp).' 2>&1',$out,$code);
if($code!==0){unlink($tmp);exit("PHP inválido, nada alterado: ".implode(" ",$out)."\n");}
unlink($tmp);
$backup=$file.'.bak-compras-'.date('Ymd-His');
if(!copy($file,$backup))exit("Falha ao criar backup.\n");
if(file_put_contents($file,$patched,LOCK_EX)===false) exit("Falha na escrita. Backup: $backup\n");
echo "Registrado. Backup: $backup\nAgora aplique a migration pelo Atualizador do Banco (platform_admin).\n";
