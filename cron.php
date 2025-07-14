<?php
// cron.php
// This script is intended to be run by a cron job or scheduled task daily.
// It distributes daily profits to active user investments.

// Include database connection
require_once 'db_connect.php';

// Set content type to plain text for cron output (or JSON if you prefer structured logging)
header('Content-Type: text/plain');

echo "Cron Job Started: " . date('Y-m-d H:i:s') . "\n";

// Define the check-in bonus amount (should match user_api.php)
// const CHECKIN_BONUS_AMOUNT = 50.00; // Not used here, but good to note consistency

// --- 1. Process Daily Profits for Active Investments ---
echo "\n--- Processing Daily Profits ---\n";

// Get all active user investments that are due for daily profit
// An investment is due if its status is 'active' and last_profit_add_date is not today
// or if last_profit_add_date is NULL (for newly purchased plans)
$current_date = date('Y-m-d');

$sql_fetch_investments = "
    SELECT
        ui.id AS user_investment_id,
        ui.user_id,
        ui.daily_profit_amount,
        ui.days_remaining,
        ui.total_profit_earned,
        u.wallet_balance
    FROM
        user_investments ui
    JOIN
        users u ON ui.user_id = u.id
    WHERE
        ui.status = 'active'
        AND (ui.last_profit_add_date IS NULL OR ui.last_profit_add_date < ?)
        AND ui.days_remaining > 0
";

$stmt_fetch = $conn->prepare($sql_fetch_investments);
if (!$stmt_fetch) {
    error_log("Cron: Failed to prepare statement for fetching investments: " . $conn->error);
    echo "Error: Failed to prepare statement for fetching investments.\n";
    exit();
}
$stmt_fetch->bind_param("s", $current_date);
$stmt_fetch->execute();
$result_investments = $stmt_fetch->get_result();
$stmt_fetch->close();

$processed_count = 0;
while ($investment = $result_investments->fetch_assoc()) {
    $conn->begin_transaction(); // Start transaction for each investment
    try {
        $user_investment_id = $investment['user_investment_id'];
        $user_id = $investment['user_id'];
        $daily_profit = $investment['daily_profit_amount'];
        $current_days_remaining = $investment['days_remaining'];
        $current_total_profit_earned = $investment['total_profit_earned'];
        $user_current_wallet_balance = $investment['wallet_balance'];

        // Calculate new values
        $new_days_remaining = $current_days_remaining - 1;
        $new_total_profit_earned = $current_total_profit_earned + $daily_profit;
        $new_wallet_balance = $user_current_wallet_balance + $daily_profit;
        $investment_status = 'active';

        // Check if this is the last day
        if ($new_days_remaining <= 0) {
            $investment_status = 'completed';
            echo "Investment #$user_investment_id for User #$user_id completed. Total profit earned: $new_total_profit_earned.\n";
        } else {
            echo "Processing daily profit for Investment #$user_investment_id (User #$user_id): ₦$daily_profit. Days remaining: $new_days_remaining.\n";
        }

        // Update user's wallet balance
        $stmt_update_user = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
        if (!$stmt_update_user) {
            throw new Exception("Failed to prepare user wallet update: " . $conn->error);
        }
        $stmt_update_user->bind_param("di", $new_wallet_balance, $user_id);
        if (!$stmt_update_user->execute()) {
            throw new Exception("Failed to update user wallet balance for user $user_id.");
        }
        $stmt_update_user->close();

        // Update user investment record
        $stmt_update_investment = $conn->prepare("
            UPDATE user_investments
            SET
                days_remaining = ?,
                total_profit_earned = ?,
                status = ?,
                last_profit_add_date = ?
            WHERE
                id = ?
        ");
        if (!$stmt_update_investment) {
            throw new Exception("Failed to prepare investment update: " . $conn->error);
        }
        $stmt_update_investment->bind_param("idssi", $new_days_remaining, $new_total_profit_earned, $investment_status, $current_date, $user_investment_id);
        if (!$stmt_update_investment->execute()) {
            throw new Exception("Failed to update user investment record for ID $user_investment_id.");
        }
        $stmt_update_investment->close();

        // Record transaction for daily profit
        $description = "Daily profit from investment plan";
        $type = 'profit';
        $status = 'completed';
        $stmt_insert_transaction = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt_insert_transaction) {
            throw new Exception("Failed to prepare transaction insert: " . $conn->error);
        }
        $stmt_insert_transaction->bind_param("isdss", $user_id, $type, $daily_profit, $description, $status);
        if (!$stmt_insert_transaction->execute()) {
            throw new Exception("Failed to record daily profit transaction for user $user_id.");
        }
        $stmt_insert_transaction->close();

        $conn->commit();
        $processed_count++;

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Cron: Daily profit processing failed for investment ID $user_investment_id: " . $e->getMessage());
        echo "Error processing investment ID $user_investment_id: " . $e->getMessage() . "\n";
    }
}
echo "Finished processing daily profits. Total processed: $processed_count.\n";


// --- 2. Clean Up (Optional but Recommended) ---
// You might want to periodically clean up old completed investments or transactions
// For example, mark investments as 'archived' instead of 'completed' if you need to retain them
// but want to filter them out of active views.
// Or, delete very old, failed/cancelled transactions.

echo "\nCron Job Finished: " . date('Y-m-d H:i:s') . "\n";

// Close database connection
$conn->close();
?>