<?php
/**
 * submit_rating.php
 * Authenticated endpoint for players to rate grounds they have played on.
 * Strictly enforces that the booking slot time has ended.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rating_helper.php';
require_once __DIR__ . '/notifications.php';

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Please log in to submit a review.'
    ]);
    exit;
}

$userId = intval($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST is accepted.'
    ]);
    exit;
}

$bookingId = intval($_POST['booking_id'] ?? 0);
$rating    = intval($_POST['rating'] ?? 0);
$review    = trim($_POST['review'] ?? '');

// Sanitize review text
if (strlen($review) > 1000) {
    $review = substr($review, 0, 1000);
}

// Basic input validation
if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid booking ID provided.'
    ]);
    exit;
}

if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Rating must be between 1 and 5 stars.'
    ]);
    exit;
}

try {
    // Check eligibility and timing via helper
    $eligibility = canUserRateBooking($pdo, $userId, $bookingId);

    if (!$eligibility['can_rate']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $eligibility['reason']
        ]);
        exit;
    }

    $booking  = $eligibility['booking'];
    $groundId = intval($booking['ground_id']);

    // Insert or update rating
    $stmt = $pdo->prepare("
        INSERT INTO ground_ratings (ground_id, booking_id, user_id, rating, review)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            rating = VALUES(rating),
            review = VALUES(review),
            updated_at = NOW()
    ");
    $stmt->execute([
        $groundId,
        $bookingId,
        $userId,
        $rating,
        $review ?: null
    ]);

    // Notify venue owner of the review
    $ownerId = intval($booking['owner_id'] ?? 0);
    if ($ownerId > 0 && $ownerId !== $userId) {
        $starIcons = str_repeat('★', $rating);
        $groundTitle = htmlspecialchars($booking['ground_title'] ?? 'your venue');
        createNotification(
            $pdo,
            $ownerId,
            'ground_rating_received',
            'New Player Review',
            "A player rated {$groundTitle} {$starIcons} ({$rating}/5 stars).",
            "owner_dashboard.php"
        );
    }

    // Recalculate fresh aggregate statistics
    $stats = getGroundRatingStats($pdo, $groundId);

    echo json_encode([
        'success'       => true,
        'message'       => 'Thank you! Your rating has been successfully saved.',
        'rating'        => $rating,
        'review'        => $review,
        'avg_rating'    => $stats['avg_rating'],
        'total_reviews' => $stats['total_reviews']
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred while saving your review: ' . $e->getMessage()
    ]);
    exit;
}
