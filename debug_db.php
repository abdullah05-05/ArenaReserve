<?php
require_once 'db.php';

echo "=== ground_slots ===" . PHP_EOL;
foreach($pdo->query('DESCRIBE ground_slots') as $r) echo $r['Field'] . ' | ' . $r['Type'] . PHP_EOL;

echo PHP_EOL . "=== slot_holds ===" . PHP_EOL;
try {
    foreach($pdo->query('DESCRIBE slot_holds') as $r) echo $r['Field'] . ' | ' . $r['Type'] . PHP_EOL;
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . PHP_EOL; }

echo PHP_EOL . "=== bookings ===" . PHP_EOL;
try {
    foreach($pdo->query('DESCRIBE bookings') as $r) echo $r['Field'] . ' | ' . $r['Type'] . PHP_EOL;
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . PHP_EOL; }

$c = $pdo->query('SELECT COUNT(*) as cnt FROM ground_slots WHERE is_available=1')->fetch();
echo PHP_EOL . "Available slots in DB: " . $c['cnt'] . PHP_EOL;

$g = $pdo->query('SELECT id, title FROM grounds WHERE is_verified=1 LIMIT 3')->fetchAll();
echo "Verified grounds: " . count($g) . PHP_EOL;
foreach($g as $row) echo "  id=" . $row['id'] . " => " . $row['title'] . PHP_EOL;
