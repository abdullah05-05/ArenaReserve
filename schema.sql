-- Database schema for ArenaReserve

CREATE DATABASE IF NOT EXISTS `ar_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `ar_db`;

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `phone` VARCHAR(20) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `current_role` ENUM('Player', 'Owner', 'Admin') NOT NULL DEFAULT 'Player',
  `current_active_mode` ENUM('Player', 'Owner', 'Admin') NOT NULL DEFAULT 'Player',
  `city` VARCHAR(100) NOT NULL DEFAULT 'Lahore',
  `status` ENUM('Active', 'Suspended') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wallets Table
CREATE TABLE IF NOT EXISTS `wallets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `available_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `frozen_escrow_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `currency_type` VARCHAR(10) NOT NULL DEFAULT 'PKR',
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grounds Table
CREATE TABLE IF NOT EXISTS `grounds` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `address` TEXT NOT NULL,
  `latitude` DOUBLE NOT NULL,
  `longitude` DOUBLE NOT NULL,
  `sport_type` VARCHAR(100) NOT NULL,
  `base_price` DECIMAL(10, 2) NOT NULL,
  `peak_price` DECIMAL(10, 2) NOT NULL,
  `image_path` VARCHAR(255) DEFAULT NULL,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0, -- 0: Pending, 1: Approved, 2: Rejected/Needs revision
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Onboarding Packages (Verification Docs)
CREATE TABLE IF NOT EXISTS `onboarding_packages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `ground_id` INT NOT NULL,
  `verification_method` ENUM('StampPaper', 'SecurityDeposit') NOT NULL,
  `legal_docs_path` VARCHAR(255) DEFAULT NULL,
  `security_fee_receipt` VARCHAR(255) DEFAULT NULL,
  `approval_status` ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ground_id`) REFERENCES `grounds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wallet Deposit Requests (Manual top-up verification)
CREATE TABLE IF NOT EXISTS `wallet_deposit_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `player_id` INT NOT NULL,
  `amount` DECIMAL(15, 2) NOT NULL,
  `reference_details` VARCHAR(255) NOT NULL,
  `receipt_path` VARCHAR(255) NOT NULL,
  `status` ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`player_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wallet Transactions Table
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `wallet_id` INT NOT NULL,
  `amount` DECIMAL(15, 2) NOT NULL,
  `transaction_type` ENUM('Deposit', 'Booking_Payment', 'Refund', 'Payout') NOT NULL,
  `reference_id` VARCHAR(255) DEFAULT NULL,
  `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Admin User (Default admin login: admin@arenareserve.com / admin123)
-- Password hash for 'admin123' using bcrypt: $2y$10$X86Z2u9s76GgW29hF8F/mOrBf5L9/y5Q0bS1wZlD7h1GkG96c5678
INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password`, `current_role`, `current_active_mode`, `city`) 
VALUES (1, 'Super Admin', 'admin@arenareserve.com', '03001234567', '$2y$10$X86Z2u9s76GgW29hF8F/mOrBf5L9/y5Q0bS1wZlD7h1GkG96c5678', 'Admin', 'Admin', 'Lahore')
ON DUPLICATE KEY UPDATE `id`=`id`;
