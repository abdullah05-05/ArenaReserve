<?php
require_once 'db.php';
$player_lat = 24.8607;
$player_lng = 67.0011;

$sql = "SELECT g.*, 
        (6371 * acos(cos(radians(:player_lat1)) * cos(radians(g.latitude)) * cos(radians(g.longitude) - radians(:player_lng)) + sin(radians(:player_lat2)) * sin(radians(g.latitude)))) AS distance 
        FROM grounds g 
        WHERE g.is_verified = 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'player_lat1' => $player_lat,
    'player_lat2' => $player_lat,
    'player_lng' => $player_lng
]);
$grounds = $stmt->fetchAll();

echo "Total grounds: " . count($grounds) . "\n";
foreach ($grounds as $g) {
    echo "ID: " . $g['id'] . " - Title: " . $g['title'] . " - Verified: " . $g['is_verified'] . " - Distance: " . $g['distance'] . "\n";
}
?>
