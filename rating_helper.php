<?php
/**
 * rating_helper.php
 * Core helper for the ArenaReserve Ground Star Rating system.
 * Handles timing validation, participant verification, and aggregate calculations.
 */

if (!function_exists('canUserRateBooking')) {

    /**
     * Determines whether a user is eligible to rate a ground for a specific booking.
     *
     * Rules:
     * 1. Booking must exist and venue must exist.
     * 2. User must be a verified participant (booked_by or opponent_id).
     * 3. Booking must be completed/played ('confirmed' or 'challenge_accepted').
     * 4. Match slot time must have ended (slot_start + 3600 <= current_time).
     *
     * @param PDO $pdo
     * @param int $userId
     * @param int $bookingId
     * @return array ['can_rate' => bool, 'reason' => string, 'booking' => array|null, 'existing_rating' => array|null, 'slot_ended' => bool]
     */
    function canUserRateBooking(PDO $pdo, int $userId, int $bookingId): array {
        if ($userId <= 0 || $bookingId <= 0) {
            return [
                'can_rate'        => false,
                'reason'          => 'Invalid user or booking ID.',
                'booking'         => null,
                'existing_rating' => null,
                'slot_ended'      => false
            ];
        }

        // Fetch booking with ground details
        $stmt = $pdo->prepare("
            SELECT b.*, g.title AS ground_title, g.sport_type, g.owner_id
            FROM bookings b
            JOIN grounds g ON g.id = b.ground_id
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return [
                'can_rate'        => false,
                'reason'          => 'Booking record not found.',
                'booking'         => null,
                'existing_rating' => null,
                'slot_ended'      => false
            ];
        }

        // Check user participation
        $isChallenger = (intval($booking['booked_by']) === $userId);
        $isOpponent   = (intval($booking['opponent_id'] ?? 0) === $userId);

        if (!$isChallenger && !$isOpponent) {
            return [
                'can_rate'        => false,
                'reason'          => 'You did not participate in this match reservation.',
                'booking'         => $booking,
                'existing_rating' => null,
                'slot_ended'      => false
            ];
        }

        // Check booking status (cannot rate cancelled or unaccepted challenges)
        $allowedStatuses = ['confirmed', 'challenge_accepted'];
        if (!in_array(strtolower(trim($booking['status'])), $allowedStatuses)) {
            return [
                'can_rate'        => false,
                'reason'          => 'Only confirmed or completed matches can be reviewed.',
                'booking'         => $booking,
                'existing_rating' => null,
                'slot_ended'      => false
            ];
        }

        // Calculate slot timing (Asia/Karachi PKT)
        $slotDate = $booking['slot_date'];
        $slotHour = intval($booking['slot_hour']);
        $slotStartTime = strtotime($slotDate . ' ' . sprintf('%02d:00:00', $slotHour));
        $slotEndTime   = $slotStartTime + 3600; // 1 hour slot duration
        $currentTime   = time();

        $slotEnded = ($currentTime >= $slotEndTime);

        // Fetch existing rating if any
        $rStmt = $pdo->prepare("
            SELECT * FROM ground_ratings 
            WHERE booking_id = ? AND user_id = ? 
            LIMIT 1
        ");
        $rStmt->execute([$bookingId, $userId]);
        $existingRating = $rStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$slotEnded) {
            $formattedEndTime = date('g:i A, D M j', $slotEndTime);
            return [
                'can_rate'        => false,
                'reason'          => "Rating unlocks right after your match slot ends at {$formattedEndTime}.",
                'booking'         => $booking,
                'existing_rating' => $existingRating,
                'slot_ended'      => false,
                'unlock_time'     => $slotEndTime
            ];
        }

        return [
            'can_rate'        => true,
            'reason'          => $existingRating ? 'You can update your existing rating.' : 'Eligible to rate.',
            'booking'         => $booking,
            'existing_rating' => $existingRating,
            'slot_ended'      => true
        ];
    }

    /**
     * Returns aggregate rating statistics for a given ground.
     *
     * @param PDO $pdo
     * @param int $groundId
     * @return array ['avg_rating' => float, 'total_reviews' => int]
     */
    function getGroundRatingStats(PDO $pdo, int $groundId): array {
        if ($groundId <= 0) {
            return ['avg_rating' => 0.0, 'total_reviews' => 0];
        }

        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(ROUND(AVG(rating), 1), 0.0) AS avg_rating,
                COUNT(*) AS total_reviews
            FROM ground_ratings
            WHERE ground_id = ?
        ");
        $stmt->execute([$groundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'avg_rating'    => floatval($row['avg_rating'] ?? 0.0),
            'total_reviews' => intval($row['total_reviews'] ?? 0)
        ];
    }

    /**
     * Efficiently fetches existing ratings submitted by a user for an array of booking IDs.
     *
     * @param PDO $pdo
     * @param int $userId
     * @param array $bookingIds
     * @return array Keyed by booking_id => rating record
     */
    function getUserRatingsMap(PDO $pdo, int $userId, array $bookingIds): array {
        if ($userId <= 0 || empty($bookingIds)) {
            return [];
        }

        $cleanIds = array_values(array_filter(array_map('intval', $bookingIds), fn($id) => $id > 0));
        if (empty($cleanIds)) {
            return [];
        }

        $inClause = implode(',', $cleanIds);
        $stmt = $pdo->prepare("
            SELECT * FROM ground_ratings 
            WHERE user_id = ? AND booking_id IN ($inClause)
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $r) {
            $map[intval($r['booking_id'])] = $r;
        }

        return $map;
    }

    /**
     * Fetches recent verified player reviews for a ground.
     */
    function getGroundRecentReviews(PDO $pdo, int $groundId, int $limit = 5): array {
        if ($groundId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT r.*, u.name AS user_name, u.city AS user_city
            FROM ground_ratings r
            JOIN users u ON u.id = r.user_id
            WHERE r.ground_id = ?
            ORDER BY r.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $groundId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
