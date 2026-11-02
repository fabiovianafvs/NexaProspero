<?php
/** Opções de APRESENTAÇÃO da O.S. enviadas como PDF, não alteram o cadastro. */
declare(strict_types=1);
if (!function_exists('nexa_os_share_options')) {
  function nexa_os_share_options(array $input): array {
    $content=(string)($input['conteudo']??'completa');
    if(!in_array($content,['completa','sem_preco','sem_produtos'],true))$content='completa';
    $paper=(string)($input['papel']??'a4');
    if(!in_array($paper,['a4','carta'],true))$paper='a4';
    $profile=(string)($input['perfil_impressao']??'');
    if($profile!=='hp_p1005')$profile='padrao';
    return ['conteudo'=>$content,'papel'=>$paper,'perfil_impressao'=>$profile];
  }
}
if (!function_exists('nexa_os_share_label')) {
  function nexa_os_share_label(string $content): string {
    return match($content){'sem_preco'=>'O.S. sem preço','sem_produtos'=>'O.S. sem produtos',default=>'O.S. completa'};
  }
}
if (!function_exists('nexa_os_share_query')) {
  function nexa_os_share_query(array $options): string {
    $v=nexa_os_share_options($options);
    return '&conteudo='.rawurlencode($v['conteudo']).'&papel='.rawurlencode($v['papel']).'&perfil_impressao='.rawurlencode($v['perfil_impressao']);
  }
}
