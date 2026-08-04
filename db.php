<?php
date_default_timezone_set('Asia/Karachi');

// Check if running on Azure (Environment Variables exist)
$host = getenv('DB_HOST');

if ($host) {
    // Azure Environment Variables
    $port = getenv('DB_PORT');
    $db   = getenv('DB_DATABASE');
    $user = getenv('DB_USERNAME');
    $pass = getenv('DB_PASSWORD');
} else {
    // Local XAMPP
    $config = require __DIR__ . '/config.local.php';

    $host = $config['host'];
    $port = $config['port'];
    $db   = $config['database'];
    $user = $config['username'];
    $pass = $config['password'];
}

$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_SSL_CA => __DIR__ . '/certs/ca.pem',
];

$pdo = new PDO($dsn, $user, $pass, $options);