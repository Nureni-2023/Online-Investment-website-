-- Create Database (if it doesn't exist)
CREATE DATABASE IF NOT EXISTS `investment_platform`;
USE `investment_platform`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `wallet_balance` DECIMAL(10, 2) DEFAULT 0.00,
    `last_checkin_date` DATE NULL, -- To track daily check-in bonus
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Investment Plans Table (Admin manages these)
CREATE TABLE IF NOT EXISTS `investment_plans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `plan_name` VARCHAR(255) NOT NULL UNIQUE,
    `price` DECIMAL(10, 2) NOT NULL,
    `duration_days` INT NOT NULL,
    `daily_profit` DECIMAL(10, 2) NOT NULL,
    `total_roi` DECIMAL(10, 2) NOT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. User Investments Table (When a user buys a plan)
CREATE TABLE IF NOT EXISTS `user_investments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `plan_id` INT NOT NULL,
    `purchase_price` DECIMAL(10, 2) NOT NULL, -- Price at time of purchase
    `daily_profit_amount` DECIMAL(10, 2) NOT NULL, -- Daily profit at time of purchase
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `days_remaining` INT NOT NULL,
    `total_profit_earned` DECIMAL(10, 2) DEFAULT 0.00,
    `status` ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    `last_profit_add_date` DATE NULL, -- To track daily profit automation
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`plan_id`) REFERENCES `investment_plans`(`id`) ON DELETE RESTRICT
);

-- 4. Transactions Table (For all wallet movements: recharge, profit, withdrawal, plan purchase)
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `type` ENUM('recharge', 'withdrawal', 'profit', 'plan_purchase', 'checkin_bonus', 'admin_credit') NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `description` VARCHAR(255) NULL,
    `status` ENUM('pending', 'completed', 'failed', 'cancelled') DEFAULT 'completed', -- For recharges/withdrawals
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

-- 5. Withdrawal Requests Table (Specific for user withdrawal requests)
CREATE TABLE IF NOT EXISTS `withdrawal_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `bank_name` VARCHAR(255) NOT NULL,
    `account_number` VARCHAR(255) NOT NULL,
    `account_name` VARCHAR(255) NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `request_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `processed_date` TIMESTAMP NULL,
    `admin_notes` TEXT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

-- 6. Admin Users Table
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert a default admin user (CHANGE PASSWORD IN PRODUCTION!)
INSERT INTO `admin_users` (`username`, `password_hash`, `full_name`) VALUES
('admin', '$2y$10$2.J0.X.L.L.K.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.L.Li> **Daily Profit Automation**
    * Once a user buys a plan:
      * The app **automatically adds daily profit** (e.g., ₦600) to user wallet for `X` days (e.g., 40 days)
      * Stops when duration ends
---

### 2. `db_connect.php`

This file will contain your database connection logic. Make sure to replace the placeholder values with your actual database credentials.

```php
<?php
// db_connect.php

// Database credentials
define('DB_SERVER', 'localhost'); // Your database server (often localhost)
define('DB_USERNAME', 'root');     // Your database username
define('DB_PASSWORD', '');         // Your database password
define('DB_NAME', 'investment_platform'); // The name of the database we created

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // Log the error for debugging, but don't expose sensitive info to the user
    error_log("Failed to connect to MySQL: " . $conn->connect_error);
    die("ERROR: Could not connect to the database. Please try again later.");
}

// Set character set to UTF-8 for proper encoding
$conn->set_charset("utf8mb4");

// Optional: Set default timezone for consistency (important for cron jobs)
date_default_timezone_set('Africa/Lagos'); // Or your desired timezone

?>