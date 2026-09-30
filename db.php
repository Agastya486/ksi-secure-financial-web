<?php
require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$dsn = "pgsql:host=aws-0-ap-northeast-1.pooler.supabase.com;port=6543;dbname=postgres";
$user = $_ENV['SUPABASE_DB_USER'];
$pass = $_ENV['SUPABASE_DB_PASSWORD'];

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Gagal: " . $e->getMessage());
}