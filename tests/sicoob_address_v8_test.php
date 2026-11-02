<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/sicoob_boleto_register.php';

function test_ok(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FALHOU: ' . $name);
}

$address = sicoob_v18_prepare_payer_address([
  'payer_address' => 'Rua Prefeito Virgilino Quintao',
  'payer_number' => '276',
  'payer_complement' => 'Loja 01, primeiro andar',
  'payer_district' => 'Major Lage de Baixo',
]);
test_ok($address['endereco'] === 'Rua Prefeito Virgilino Quintao, 276', 'Exemplo com complemento longo');
test_ok($address['bairro'] === 'Major Lage de Baixo', 'Bairro original preservado');

$regular = sicoob_v18_prepare_payer_address([
  'payer_address' => 'Rua A', 'payer_number' => '10', 'payer_complement' => 'Sala 2', 'payer_district' => 'Centro',
]);
test_ok($regular['endereco'] === 'Rua A, 10, Sala 2', 'Complemento cabe, é mantido');

$duplicate = sicoob_v18_prepare_payer_address([
  'payer_address' => 'Rua Principal, 35', 'payer_number' => '35', 'payer_district' => 'Centro',
]);
test_ok($duplicate['endereco'] === 'Rua Principal, 35', 'Não duplica número');

$shortened = sicoob_v18_prepare_payer_address([
  'payer_address' => 'Avenida Doutor Fernando de Azevedo Neto', 'payer_number' => '123', 'payer_district' => 'Centro',
]);
test_ok(sicoob_v18_char_length($shortened['endereco']) <= 40 && str_ends_with($shortened['endereco'], '123'), 'Abrevia prefixo mantendo número');

test_ok(sicoob_v18_char_length('São João') === 8, 'Contagem UTF-8 sem mbstring');

$bank = ['convenio'=>'12345','carteira'=>'1','account_number'=>'4321'];
$charge = [
  'id' => 132,
  'payer_document' => '12345678901',
  'payer_name' => 'Cliente Exemplo',
  'payer_address' => 'Rua Prefeito Virgilino Quintao',
  'payer_number' => '276',
  'payer_complement' => 'Loja 01, primeiro andar',
  'payer_district' => 'Major Lage de Baixo',
  'payer_city' => 'Itabira', 'payer_state'=>'MG', 'payer_zip_code'=>'35900211',
  'seu_numero'=>'CTR000077-202611', 'amount'=>'218.00',
  'due_date'=>date('Y-m-d', strtotime('+60 days')), 'issue_date'=>date('Y-m-d'),
  'installment'=>'1/1',
];
$payload = sicoob_v15_build_payload($charge, $bank);
test_ok($payload['pagador']['endereco'] === 'Rua Prefeito Virgilino Quintao, 276', 'Payload Sicoob corrige endereço');
test_ok($payload['pagador']['bairro'] === 'Major Lage de Baixo', 'Payload Sicoob mantém bairro');
test_ok($payload['valor'] === 218.00 && $payload['numeroContaCorrente'] === 4321, 'Demais campos preservados');

$long = $charge;
$long['payer_address'] = 'Rua da Comunidade Nossa Senhora Aparecida da Serra e Vale';
try {
    sicoob_v15_build_payload($long, $bank);
    throw new RuntimeException('ACEITOU LOGRADOURO LONGO');
} catch (RuntimeException $e) {
    test_ok(str_contains($e->getMessage(), 'logradouro + número') && !str_contains($e->getMessage(), 'ACEITOU LOGRADOURO LONGO'), 'Longo não truncado silenciosamente');
}

$long = $charge;
$long['payer_district'] = 'Comunidade Nossa Senhora Aparecida do Alto';
try {
    sicoob_v15_build_payload($long, $bank);
    throw new RuntimeException('ACEITOU BAIRRO LONGO');
} catch (RuntimeException $e) {
    test_ok(str_contains($e->getMessage(), 'bairro com ') && !str_contains($e->getMessage(), 'ACEITOU BAIRRO LONGO'), 'Bairro longo identificado isoladamente');
}

$long = $charge;
$long['payer_district'] = 'Jardim Santa Maria dos Ipês Altos';
$auto = sicoob_v18_prepare_payer_address($long);
test_ok(sicoob_v18_char_length($auto['bairro']) <= 30 && str_starts_with($auto['bairro'], 'Jd.'), 'Abrevia bairro padrão com excesso');

echo "OK - V8 10 cenários: montagem do endereço / caracteres / validação / demais valores\n";
