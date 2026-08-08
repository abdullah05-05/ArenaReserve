<?php
date_default_timezone_set('Asia/Karachi');

// Environment variables check
$isAzure = getenv('DB_HOST') !== false;

if ($isAzure) {
    // Azure / Aiven Production
    $host = getenv('DB_HOST');
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
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// SSL tabhi lagayen jab Azure/Aiven par chal raha ho
if ($isAzure) {
    $caPath = __DIR__ . '/certs/ca.pem';
    if (file_exists($caPath)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        // Self-signed certificate disconnect error se bachne ke liye:
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false; 
    }
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}