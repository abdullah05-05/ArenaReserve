<?php
header('Content-Type: application/json');

$ch = curl_init('https://api.ipify.org');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$outboundIp = curl_exec($ch);
curl_close($ch);

echo json_encode([
    'environment' => (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'])) ? 'localhost' : 'live',
    'host'        => $_SERVER['HTTP_HOST'] ?? '',
    'server_addr' => $_SERVER['SERVER_ADDR'] ?? '',
    'outbound_ip' => trim($outboundIp),
    'timestamp'   => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT);
