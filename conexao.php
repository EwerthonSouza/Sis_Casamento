<?php
// Usa variáveis de ambiente do Docker quando definidas; caso contrário,
// cai nos padrões do XAMPP.
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'sistema_eventos';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: ''; // Padrão do XAMPP é vazio
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// Cada página roda seus próprios checks de "essa coluna existe? se não, ALTER TABLE"
// a cada requisição (inclusive em cada chamada AJAX). Uma vez confirmado que o schema
// já tem tudo, marca em disco pra nunca mais bater no banco só pra verificar de novo.
function schema_ja_verificado(string $chave): bool {
    return file_exists(__DIR__ . "/uploads/.schema_ok_$chave");
}
function marcar_schema_verificado(string $chave): void {
    @file_put_contents(__DIR__ . "/uploads/.schema_ok_$chave", '1');
}
?>