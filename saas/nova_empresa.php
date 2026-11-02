<?php

$dbConfig = require __DIR__ . '/../config/database.php';

$dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}";

$db = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);

if($_POST){

    $nome = $_POST['nome'];
    $email = $_POST['email'];
    $subdominio = $_POST['subdominio'];
    $plano = $_POST['plano'];

    $sql = "INSERT INTO saas_empresas
    (nome,email,subdominio,plano,limite_pedidos,data_vencimento)
    VALUES
    (?,?,?,?,100,DATE_ADD(NOW(), INTERVAL 30 DAY))";

    $stmt = $db->prepare($sql);
    $stmt->execute([$nome,$email,$subdominio,$plano]);

    header("Location: empresas.php");
}

?>

<h1>Nova empresa</h1>

<form method="post">

Empresa<br>
<input name="nome"><br><br>

Email<br>
<input name="email"><br><br>

Subdomínio<br>
<input name="subdominio"><br><br>

Plano<br>

<select name="plano">
<option>Start</option>
<option>Profissional</option>
<option>Premium</option>
<option>Enterprise</option>
</select>

<br><br>

<button>Criar empresa</button>

</form>

<br>

<a href="empresas.php">? voltar</a>