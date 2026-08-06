<?php
/**
 * get_grounds_by_proximity.php
 * 
 * Free JSON API endpoint for proximity-based ground search.
 * Uses Haversine formula in MySQL — no external paid API required.
 * 
 * GET Parameters:
 *   player_lat  float  Player latitude (-90 to 90)
 *   player_lng  float  Player longitude (-180 to 180)
 *   radius_km   int    Optional: filter radius in km (default: no limit)
 *   sport_type  string Optional: filter by sport (Football, Cricket, etc.)
 *   limit       int    Optional: max results (default 50, max 100)
 * 
 * Response: JSON array of grounds with distance in km
 */

session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

// Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Input Validation ──────────────────────────────────────────────
$default_lat = 24.8607; // Karachi fallback
$default_lng = 67.0011;
$using_default = true;

$player_lat = $default_lat;
$player_lng = $default_lng;

if (isset($_GET['player_lat']) && isset($_GET['player_lng'])) {
    $raw_lat = floatval($_GET['player_lat']);
    $raw_lng = floatval($_GET['player_lng']);
    if ($raw_lat >= -90 && $raw_lat <= 90 && $raw_lng >= -180 && $raw_lng <= 180) {
        $player_lat  = $raw_lat;
        $player_lng  = $raw_lng;
        $using_default = false;
    }
}

$sport_type = isset($_GET['sport_type']) && $_GET['sport_type'] !== 'All'
    ? trim($_GET['sport_type'])
    : null;

$radius_km = isset($_GET['radius_km']) && is_numeric($_GET['radius_km']) && intval($_GET['radius_km']) > 0
    ? intval($_GET['radius_km'])
    : null;

$limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;

// ── Build SQL with Haversine distance formula ─────────────────────
// Formula: 6371 * acos(cos(lat1) * cos(lat2) * cos(lng2 - lng1) + sin(lat1) * sin(lat2))
// Distance unit: kilometers

$sql = "SELECT 
            g.id,
            g.title,
            g.address,
            g.latitude,
            g.longitude,
            g.sport_type,
            g.base_price,
            g.peak_price,
            g.image_path,
            (6371 * acos(
                LEAST(1.0, 
                    cos(radians(:player_lat1)) * cos(radians(g.latitude))
                    * cos(radians(g.longitude) - radians(:player_lng))
                    + sin(radians(:player_lat2)) * sin(radians(g.latitude))
                )
            )) AS distance_km
        FROM grounds g
        WHERE g.is_verified = 1
          AND (g.ground_status IS NULL OR g.ground_status = 'Active')";

$params = [
    ':player_lat1' => $player_lat,
    ':player_lat2' => $player_lat,
    ':player_lng'  => $player_lng,
];

if ($sport_type !== null) {
    $sql .= " AND g.sport_type = :sport_type";
    $params[':sport_type'] = $sport_type;
}

// Wrap in subquery for radius filtering (HAVING on alias requires subquery in strict SQL)
if ($radius_km !== null) {
    $sql = "SELECT * FROM ({$sql}) AS inner_q WHERE inner_q.distance_km <= :radius_km";
    $params[':radius_km'] = $radius_km;
}

$sql .= " ORDER BY distance_km ASC LIMIT :result_limit";

// ── Execute ───────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare($sql);

    // Bind named params manually so we can bind the LIMIT as integer
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':result_limit', $limit, PDO::PARAM_INT);

    $stmt->execute();
    $grounds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Enrich each ground with distance badge info ───────────────
    foreach ($grounds as &$g) {
        $d = floatval($g['distance_km']);
        $g['distance_km']     = round($d, 2);
        $g['distance_label']  = $d < 1
            ? round($d * 1000) . ' m away'
            : number_format($d, 1) . ' km away';

        // Color-coded proximity tier
        if ($d < 3) {
            $g['distance_tier']  = 'close';
            $g['distance_color'] = 'green';
        } elseif ($d < 10) {
            $g['distance_tier']  = 'nearby';
            $g['distance_color'] = 'amber';
        } else {
            $g['distance_tier']  = 'far';
            $g['distance_color'] = 'slate';
        }

        // Image URL
        $g['image_url'] = !empty($g['image_path']) ? $g['image_path'] : null;

        // Book slot URL
        $g['book_url'] = 'book_slot.php?ground=' . intval($g['id']);
    }
    unset($g);

    echo json_encode([
        'success'          => true,
        'player_lat'       => $player_lat,
        'player_lng'       => $player_lng,
        'using_default_location' => $using_default,
        'radius_km'        => $radius_km,
        'sport_filter'     => $sport_type ?? 'All',
        'total'            => count($grounds),
        'grounds'          => $grounds,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error',
        'message' => $e->getMessage(),
    ]);
}
