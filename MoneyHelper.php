<?php
/**
 * MoneyHelper.php — Centralized currency calculations for ArenaReserve
 * Single source of truth for all PKR money math to eliminate rounding drift.
 */

class MoneyHelper
{
    public const CURRENCY = 'PKR';
    public const DECIMALS = 2;
    public const PLATFORM_FEE_PERCENT = 5.0;

    /**
     * Round amount to standard PKR precision (2 decimal places)
     */
    public static function round(float $amount): float
    {
        return round($amount, self::DECIMALS);
    }

    /**
     * Calculate advance payment for a booking
     * Direct bookings: 50% of full price
     * Challenges (open/team): 25% of full price
     */
    public static function calculateAdvance(float $fullPrice, string $bookingType): float
    {
        $percent = match ($bookingType) {
            'direct' => 50.0,
            'open_challenge', 'team_challenge' => 25.0,
            default => 50.0,
        };
        return self::round($fullPrice * $percent / 100.0);
    }

    /**
     * Calculate platform commission on an amount
     */
    public static function calculateCommission(float $amount, float $percent = self::PLATFORM_FEE_PERCENT): float
    {
        return self::round($amount * $percent / 100.0);
    }

    /**
     * Calculate owner payout after platform fee
     * Used for: cancellation late fees, match score payouts
     * $grossAmount = amount collected from player (e.g., 50% of slot price)
     * Returns net amount to owner
     */
    public static function calculateOwnerPayout(float $grossAmount, float $platformFeePercent = self::PLATFORM_FEE_PERCENT): float
    {
        $commission = self::calculateCommission($grossAmount, $platformFeePercent);
        return max(0.0, self::round($grossAmount - $commission));
    }

    /**
     * Calculate cancellation refund for player
     * Returns [refundAmount, ownerPayout, platformFee]
     */
    public static function calculateCancellationRefund(
        float $amountPaid,
        string $bookingStatus,
        int $hoursUntilSlot
    ): array {
        // Challenge open/pending: full refund, no owner payout
        if (in_array($bookingStatus, ['challenge_open', 'challenge_pending'])) {
            return [
                'refund' => self::round($amountPaid),
                'owner_payout' => 0.0,
                'platform_fee' => 0.0,
            ];
        }

        // Confirmed direct booking
        if ($bookingStatus === 'confirmed') {
            if ($hoursUntilSlot > 24) {
                // Full refund
                return [
                    'refund' => self::round($amountPaid),
                    'owner_payout' => 0.0,
                    'platform_fee' => 0.0,
                ];
            } else {
                // Late cancellation: 50% to player, 50% retained
                $refund = self::round($amountPaid * 0.5);
                $retained = self::round($amountPaid - $refund);
                $platformFee = self::calculateCommission($retained);
                $ownerPayout = max(0.0, self::round($retained - $platformFee));
                return [
                    'refund' => $refund,
                    'owner_payout' => $ownerPayout,
                    'platform_fee' => $platformFee,
                ];
            }
        }

        // Default: no refund
        return [
            'refund' => 0.0,
            'owner_payout' => 0.0,
            'platform_fee' => 0.0,
        ];
    }

    /**
     * Calculate match score payout to ground owner
     * Based on online advance paid (25% for challenges, 50% for direct)
     * Platform takes 5% commission on the slot full price
     */
    public static function calculateMatchPayout(
        float $slotFullPrice,
        float $onlinePaid,
        float $platformFeePercent = self::PLATFORM_FEE_PERCENT
    ): array {
        $commission = self::calculateCommission($slotFullPrice, $platformFeePercent);
        $ownerPayout = max(0.0, self::round($onlinePaid - $commission));
        return [
            'owner_payout' => $ownerPayout,
            'platform_fee' => $commission,
        ];
    }

    /**
     * Format amount for display (e.g., "1,250.00 PKR")
     */
    public static function format(float $amount, bool $showCurrency = true): string
    {
        $formatted = number_format($amount, self::DECIMALS, '.', ',');
        return $showCurrency ? $formatted . ' ' . self::CURRENCY : $formatted;
    }

    /**
     * Format amount for display without decimals (e.g., "1,250 PKR")
     */
    public static function formatInt(float $amount, bool $showCurrency = true): string
    {
        $formatted = number_format((int)round($amount), 0, '.', ',');
        return $showCurrency ? $formatted . ' ' . self::CURRENCY : $formatted;
    }

    /**
     * Safe addition with rounding
     */
    public static function add(float ...$amounts): float
    {
        return self::round(array_sum($amounts));
    }

    /**
     * Safe subtraction with rounding (never negative)
     */
    public static function subtract(float $from, float $amount): float
    {
        return max(0.0, self::round($from - $amount));
    }

    /**
     * Calculate wallet balance change for a transaction
     * Positive for deposits/refunds, negative for payments/payouts
     */
    public static function walletDelta(
        string $type,
        float $amount,
        array $meta = []
    ): float {
        return match ($type) {
            'Deposit', 'Refund', 'Commission' => self::round($amount),      // Credit
            'Booking_Payment', 'Payout', 'Payout_Requested' => -self::round($amount), // Debit
            default => 0.0,
        };
    }
}