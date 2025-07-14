<?php
// user_api.php

session_start(); // Start the session to access user_id

// Include database connection
require_once 'db_connect.php';

// Set content type to JSON for API responses
header('Content-Type: application/json');

// Function to check if a user is logged in
function isAuthenticated() {
    return isset($_SESSION['user_id']) && !$_SESSION['is_admin'];
}

// Function to send JSON response
function sendJsonResponse($success, $message, $data = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit();
}

// Ensure user is authenticated for all user API actions
if (!isAuthenticated()) {
    sendJsonResponse(false, 'Unauthorized access. Please log in.');
}

// Get the request method
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ''; // Get action from query parameter
$user_id = $_SESSION['user_id']; // Get logged-in user's ID

// Handle GET requests
if ($method === 'GET') {
    switch ($action) {
        case 'get_user_data':
            getUserData($conn, $user_id);
            break;
        case 'get_investment_plans':
            getInvestmentPlans($conn);
            break;
        case 'get_my_investments':
            getMyInvestments($conn, $user_id);
            break;
        case 'claim_checkin_bonus':
            claimCheckinBonus($conn, $user_id);
            break;
        default:
            sendJsonResponse(false, 'Invalid GET action.');
            break;
    }
}
// Handle POST requests
elseif ($method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonResponse(false, 'Invalid JSON input.');
    }

    switch ($action) {
        case 'request_recharge':
            requestRecharge($conn, $user_id, $data);
            break;
        case 'request_withdrawal':
            requestWithdrawal($conn, $user_id, $data);
            break;
        case 'buy_plan':
            buyInvestmentPlan($conn, $user_id, $data);
            break;
        default:
            sendJsonResponse(false, 'Invalid POST action.');
            break;
    }
} else {
    sendJsonResponse(false, 'Method not allowed.');
}

/**
 * Fetches current user's data including wallet balance.
 * @param mysqli $conn Database connection object.
 * @param int $user_id ID of the logged-in user.
 */
function getUserData($conn, $user_id) {
    $stmt = $conn->prepare("SELECT full_name, email, wallet_balance, last_checkin_date FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        // Update session wallet balance in case it changed from other actions
        $_SESSION['wallet_balance'] = $user['wallet_balance'];
        sendJsonResponse(true, 'User data fetched successfully.', ['user' => $user]);
    } else {
        sendJsonResponse(false, 'User not found.');
    }
    $stmt->close();
}

/**
 * Claims the daily check-in bonus for the user.
 * @param mysqli $conn Database connection object.
 * @param int $user_id ID of the logged-in user.
 */
function claimCheckinBonus($conn, $user_id) {
    $checkin_bonus_amount = 50.00; // Define daily bonus amount

    // Get current date and last check-in date from DB
    $stmt = $conn->prepare("SELECT last_checkin_date, wallet_balance FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    $current_date = date('Y-m-d');
    $last_checkin_date = $user['last_checkin_date'];

    if ($last_checkin_date === $current_date) {
        sendJsonResponse(false, 'You have already claimed your bonus today. Come back tomorrow!');
        return;
    }

    // Begin transaction
    $conn->begin_transaction();
    try {
        // Update user's wallet balance
        $new_balance = $user['wallet_balance'] + $checkin_bonus_amount;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = ?, last_checkin_date = ? WHERE id = ?");
        $stmt->bind_param("dsi", $new_balance, $current_date, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update wallet balance.");
        }
        $stmt->close();

        // Record transaction
        $description = "Daily check-in bonus";
        $status = "completed";
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?)");
        $type = 'checkin_bonus';
        $stmt->bind_param("isdss", $user_id, $type, $checkin_bonus_amount, $description, $status);
        if (!$stmt->execute()) {
            throw new Exception("Failed to record transaction.");
        }
        $stmt->close();

        $conn->commit();
        $_SESSION['wallet_balance'] = $new_balance; // Update session
        sendJsonResponse(true, '₦' . number_format($checkin_bonus_amount, 2) . ' daily bonus claimed successfully!', ['new_balance' => $new_balance]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Check-in bonus failed for user $user_id: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to claim bonus. Please try again. ' . $e->getMessage());
    }
}

/**
 * Requests a wallet recharge. This is a manual process for admin approval.
 * @param mysqli $conn Database connection object.
 * @param int $user_id ID of the logged-in user.
 * @param array $data Recharge request data.
 */
function requestRecharge($conn, $user_id, $data) {
    $amount = $data['amount'] ?? 0;

    if (!is_numeric($amount) || $amount <= 0) {
        sendJsonResponse(false, 'Please enter a valid amount.');
        return;
    }

    // Record as a pending transaction
    $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?)");
    $type = 'recharge';
    $description = 'Online payment request';
    $status = 'pending'; // Status is pending until admin approves
    $stmt->bind_param("isdss", $user_id, $type, $amount, $description, $status);

    if ($stmt->execute()) {
        sendJsonResponse(true, 'Recharge request submitted successfully. Awaiting admin approval.');
    } else {
        error_log("Recharge request failed for user $user_id: " . $stmt->error);
        sendJsonResponse(false, 'Failed to submit recharge request. Please try again.');
    }
    $stmt->close();
}

/**
 * Requests a withdrawal. This is a manual process for admin approval.
 * @param mysqli $conn Database connection object.
 * @param int $user_id ID of the logged-in user.
 * @param array $data Withdrawal request data.
 */
function requestWithdrawal($conn, $user_id, $data) {
    $amount = $data['amount'] ?? 0;
    $bank_name = $data['bank_name'] ?? '';
    $account_number = $data['account_number'] ?? '';
    $account_name = $data['account_name'] ?? '';

    if (!is_numeric($amount) || $amount <= 0) {
        sendJsonResponse(false, 'Please enter a valid amount.');
        return;
    }
    if (empty($bank_name) || empty($account_number) || empty($account_name)) {
        sendJsonResponse(false, 'All bank details are required.');
        return;
    }

    // Check if user has sufficient balance
    $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user['wallet_balance'] < $amount) {
        sendJsonResponse(false, 'Insufficient wallet balance for this withdrawal.');
        return;
    }

    // Begin transaction for withdrawal request
    $conn->begin_transaction();
    try {
        // Deduct amount from user's wallet immediately (or set status to pending and deduct upon approval)
        // For this implementation, we deduct immediately to prevent over-withdrawal
        $new_balance = $user['wallet_balance'] - $amount;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
        $stmt->bind_param("di", $new_balance, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to deduct from wallet.");
        }
        $stmt->close();

        // Record withdrawal request for admin approval
        $stmt = $conn->prepare("INSERT INTO withdrawal_requests (user_id, amount, bank_name, account_number, account_name, status) VALUES (?, ?, ?, ?, ?, ?)");
        $status = 'pending';
        $stmt->bind_param("idssss", $user_id, $amount, $bank_name, $account_number, $account_name, $status);
        if (!$stmt->execute()) {
            throw new Exception("Failed to record withdrawal request.");
        }
        $stmt->close();

        // Record a pending transaction
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?)");
        $type = 'withdrawal';
        $description = 'Withdrawal request to ' . $bank_name;
        $status = 'pending';
        $stmt->bind_param("isdss", $user_id, $type, $amount, $description, $status);
        if (!$stmt->execute()) {
            throw new Exception("Failed to record transaction.");
        }
        $stmt->close();

        $conn->commit();
        $_SESSION['wallet_balance'] = $new_balance; // Update session
        sendJsonResponse(true, 'Withdrawal request submitted successfully. Awaiting admin approval.', ['new_balance' => $new_balance]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Withdrawal request failed for user $user_id: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to submit withdrawal request. Please try again. ' . $e->getMessage());
    }
}

/**
 * Fetches all active investment plans.
 * @param mysqli $conn Database connection object.
 */
function getInvestmentPlans($conn) {
    $stmt = $conn->prepare("SELECT id, plan_name, price, duration_days, daily_profit, total_roi FROM investment_plans WHERE is_active = TRUE ORDER BY price ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $plans = [];
    while ($row = $result->fetch_assoc()) {
        $plans[] = $row;
    }
    sendJsonResponse(true, 'Investment plans fetched successfully.', ['plans' => $plans]);
    $stmt->close();
}

/**
 * Allows a user to buy an investment plan.
 * @param mysqli $conn Database connection object.
 * @param int $user_id ID of the logged-in user.
 * @param array $data Plan purchase data.
 */
function buyInvestmentPlan($conn, $user_id, $data) {
    $plan_id = $data['plan_id'] ?? 0;

    if (!is_numeric($plan_id) || $plan_id <= 0) {
        sendJsonResponse(false, 'Invalid plan selected.');
        return;
    }

    // Fetch plan details
    $stmt = $conn->prepare("SELECT id, plan_name, price, duration_days, daily_profit FROM investment_plans WHERE id = ? AND is_active = TRUE");
    $stmt->bind_param("i", $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $plan = $result->fetch_assoc();
    $stmt->close();

    if (!$plan) {
        sendJsonResponse(false, 'Investment plan not found or not active.');
        return;
    }

    // Fetch user's current wallet balance
    $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user['wallet_balance'] < $plan['price']) {
        sendJsonResponse(false, 'Insufficient wallet balance to purchase this plan.');
        return;
    }

    // Begin transaction for plan purchase
    $conn->begin_transaction();
    try {
        // Deduct plan price from user's wallet
        $new_balance = $user['wallet_balance'] - $plan['price'];
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
        $stmt->bind_param("di", $new_balance, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to deduct plan price from wallet.");
        }
        $stmt->close();

        // Record user investment
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime("+$plan[duration_days] days"));
        $days_remaining = $plan['duration_days'];
        $status = 'active';
        $last_profit_add_date = null; // Will be set by cron job on first profit add

        $stmt = $conn->prepare("INSERT INTO user_investments (user_id, plan_id, purchase_price, daily_profit_amount, start_date, end_date, days_remaining, status, last_profit_add_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iidssdiss", $user_id, $plan['id'], $plan['price'], $plan['daily_profit'], $start_date, $end_date, $days_remaining, $status, $last_profit_add_date);
        if (!$stmt->execute()) {
            throw new Exception("Failed to record user investment.");
        }
        $stmt->close();

        // Record transaction for plan purchase
        $description = "Purchase of " . $plan['plan_name'];
        $type = 'plan_purchase';
        $status = 'completed';
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isdss", $user_id, $type, $plan['price'], $description, $status);
        if (!$stmt->execute()) {
            throw new Exception("Failed to record plan purchase transaction.");
        }
        $stmt->close();

        $conn->commit();
        $_SESSION['wallet_balance'] = $new_balance; // Update session
        sendJsonResponse(true, $plan['plan_name'] . ' purchased successfully! Daily profits will begin.', ['new_balance' => $new_balance]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Plan purchase failed for user $user_id, plan $plan_id: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to purchase plan. Please try again. ' . $e->getMessage());
    }
}

/**
 * Fetches all investments for the logged-in user.
 * @param mysqli $conn Database connection object.
 * @param int $user_id ID of the logged-in user.
 */
function getMyInvestments($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT
            ui.id,
            ip.plan_name,
            ui.purchase_price,
            ui.daily_profit_amount,
            ui.start_date,
            ui.end_date,
            ui.days_remaining,
            ui.total_profit_earned,
            ui.status
        FROM
            user_investments ui
        JOIN
            investment_plans ip ON ui.plan_id = ip.id
        WHERE
            ui.user_id = ?
        ORDER BY
            ui.status ASC, ui.start_date DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $investments = [];
    while ($row = $result->fetch_assoc()) {
        $investments[] = $row;
    }
    sendJsonResponse(true, 'My investments fetched successfully.', ['investments' => $investments]);
    $stmt->close();
}

// Close database connection at the end of script execution
$conn->close();
?>