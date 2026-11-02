<?php
declare(strict_types=1);

namespace Nexa\PagBank;

use RuntimeException;

final class Checkout
{
    private $client;
    private $config;
    public function __construct(Client $client, array $config) { $this->client=$client; $this->config=$config; }

    public function createCard(array $customer, array $item, string $referenceId, string $notificationUrl, string $returnUrl): array
    {
        $amount=max(100,(int)($item['unit_amount']??0));
        $payload=[
            'reference_id'=>substr($referenceId,0,64),
            'customer'=>$customer,
            'items'=>[array_merge(['reference_id'=>'NEXA-'.substr($referenceId,0,40),'name'=>'Assinatura Nexa Próspero','quantity'=>1,'unit_amount'=>$amount],$item)],
            'payment_methods'=>[['type'=>'CREDIT_CARD']],
            'payment_methods_configs'=>[['type'=>'CREDIT_CARD','config_options'=>[['option'=>'INSTALLMENTS_LIMIT','value'=>(string)max(1,min(18,(int)($this->config['max_installments']??12)))]]]],
            'soft_descriptor'=>(string)($this->config['soft_descriptor']??'NEXAPROSPERO'),
            'redirect_url'=>$returnUrl,
            'return_url'=>$returnUrl,
            'notification_urls'=>[$notificationUrl],
            'payment_notification_urls'=>[$notificationUrl],
        ];
        $response=$this->client->request('create_card_checkout','POST','/checkouts',$payload);
        $checkout=$response['json']; $payUrl='';
        foreach(($checkout['links']??[]) as $link) if(is_array($link)&&strtoupper((string)($link['rel']??''))==='PAY'&&!empty($link['href'])){$payUrl=(string)$link['href'];break;}
        if($payUrl==='') throw new RuntimeException('O checkout foi criado, mas o PagBank não devolveu o link PAY.');
        return ['id'=>(string)($checkout['id']??''),'reference_id'=>(string)($checkout['reference_id']??$referenceId),'pay_url'=>$payUrl,'status'=>(string)($checkout['status']??'ACTIVE'),'payload'=>$checkout,'request_payload'=>$payload,'log_id'=>(int)$response['log_id']];
    }

    public function get(string $checkoutId): array { return $this->client->request('get_checkout','GET','/checkouts/'.rawurlencode($checkoutId))['json']; }
}
