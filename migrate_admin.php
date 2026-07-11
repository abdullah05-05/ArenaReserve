<?php
require 'db.php';
$ops = [
    "ALTER TABLE grounds ADD COLUMN IF NOT EXISTS block_reason TEXT DEFAULT NULL",
    "ALTER TABLE wallet_deposit_requests ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL",
];
foreach ($ops as $sql) {
    try { $pdo->exec($sql); echo "OK: $sql\n"; }
    catch(Exception $e) { echo "SKIP: " . $e->getMessage() . "\n"; }
}
