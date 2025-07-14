<?php
// admin_api.php

session_start(); // Start the session to access admin_id

// Include database connection
require_once 'db_connect.php';

// Set content type to JSON for API responses
header('Content-Type: application/json');

// Function to check if an admin is logged in
function isAdminAuthenticated() {
    return isset($_SESSION['admin_id']) && $_SESSION['is_admin'];
}

// Function to send JSON response
function sendJsonResponse($success, $message, $data = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit();
}

// Ensure admin is authenticated for all admin API actions
if (!isAdminAuthenticated()) {
    sendJsonResponse(false, 'Unauthorized access. Admin login required.');
}

// Get the request method
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ''; // Get action from query parameter
$admin_id = $_SESSION['admin_id']; // Get logged-in admin's ID

// Handle GET requests
if ($method === 'GET') {
    switch ($action) {
        case 'get_all_users':
            getAllUsers($conn);
            break;
        case 'get_all_investments':
            getAllInvestments($conn);
            break;
        case 'get_withdrawal_requests':
            getWithdrawalRequests($conn);
            break;
        case 'get_all_plans': // For admin to view all plans, even inactive ones
            getAllPlans($conn);
            break;
        case 'get_transactions':
            getTransactions($conn);
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
        case 'credit_user_wallet':
            creditUserWallet($conn, $data);
            break;
        case 'approve_withdrawal':
            approveWithdrawal($conn, $data);
            break;
        case 'reject_withdrawal':
            rejectWithdrawal($conn, $data);
            break;
        case 'add_investment_plan':
            addInvestmentPlan($conn, $data);
            break;
        case 'update_investment_plan':
            updateInvestmentPlan($conn, $data);
            break;
        case 'delete_investment_plan':
            deleteInvestmentPlan($conn, $data);
            break;
        default:
            sendJsonResponse(false, 'Invalid POST action.');
            break;
    }
} else {
    sendJsonResponse(false, 'Method not allowed.');
}

/**
 * Fetches all users from the database.
 * @param mysqli $conn Database connection object.
 */
function getAllUsers($conn) {
    $stmt = $conn->prepare("SELECT id, full_name, email, wallet_balance, last_checkin_date, created_at FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    sendJsonResponse(true, 'All users fetched successfully.', ['users' => $users]);
    $stmt->close();
}

/**
 * Fetches all investment records (user_investments) from the database.
 * @param mysqli $conn Database connection object.
 */
function getAllInvestments($conn) {
    $stmt = $conn->prepare("
        SELECT
            ui.id,
            u.full_name AS user_name,
            ip.plan_name,
            ui.purchase_price,
            ui.daily_profit_amount,
            ui.start_date,
            ui.end_date,
            ui.days_remaining,
            ui.total_profit_earned,
            ui.status,
            ui.created_at
        FROM
            user_investments ui
        JOIN
            users u ON ui.user_id = u.id
        JOIN
            investment_plans ip ON ui.plan_id = ip.id
        ORDER BY
            ui.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $investments = [];
    while ($row = $result->fetch_assoc()) {
        $investments[] = $row;
    }
    sendJsonResponse(true, 'All investments fetched successfully.', ['investments' => $investments]);
    $stmt->close();
}

/**
 * Fetches all pending withdrawal requests.
 * @param mysqli $conn Database connection object.
 */
function getWithdrawalRequests($conn) {
    $stmt = $conn->prepare("
        SELECT
            wr.id,
            u.full_name AS user_name,
            u.email AS user_email,
            wr.amount,
            wr.bank_name,
            wr.account_number,
            wr.account_name,
            wr.status,
            wr.request_date
        FROM
            withdrawal_requests wr
        JOIN
            users u ON wr.user_id = u.id
        WHERE
            wr.status = 'pending'
        ORDER BY
            wr.request_date ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    sendJsonResponse(true, 'Pending withdrawal requests fetched successfully.', ['requests' => $requests]);
    $stmt->close();
}

/**
 * Credits a user's wallet manually by an admin.
 * @param mysqli $conn Database connection object.
 * @param array $data Credit details (user_id, amount, description).
 */
function creditUserWallet($conn, $data) {
    $user_id = $data['user_id'] ?? 0;
    $amount = $data['amount'] ?? 0;
    $description = $data['description'] ?? 'Admin manual credit';

    if (!is_numeric($user_id) || $user_id <= 0) {
        sendJsonResponse(false, 'Invalid User ID.');
        return;
    }
    if (!is_numeric($amount) || $amount <= 0) {
        sendJsonResponse(false, 'Please enter a valid amount to credit.');
        return;
    }

    // Begin transaction
    $conn->begin_transaction();
    try {
        // Update user's wallet balance
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
        $stmt->bind_param("di", $amount, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update user's wallet balance.");
        }
        $stmt->close();

        // Record transaction
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?)");
        $type = 'admin_credit';
        $status = 'completed';
        $stmt->bind_param("isdss", $user_id, $type, $amount, $description, $status);
        if (!$stmt->execute()) {
            throw new Exception("Failed to record transaction.");
        }
        $stmt->close();

        $conn->commit();
        sendJsonResponse(true, 'User wallet credited successfully.');

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Admin credit failed for user $user_id: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to credit user wallet. ' . $e->getMessage());
    }
}

/**
 * Approves a pending withdrawal request.
 * @param mysqli $conn Database connection object.
 * @param array $data Withdrawal request ID.
 */
function approveWithdrawal($conn, $data) {
    $request_id = $data['request_id'] ?? 0;
    $admin_notes = $data['admin_notes'] ?? 'Approved by admin';

    if (!is_numeric($request_id) || $request_id <= 0) {
        sendJsonResponse(false, 'Invalid request ID.');
        return;
    }

    // Fetch request details
    $stmt = $conn->prepare("SELECT user_id, amount, status FROM withdrawal_requests WHERE id = ?");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        sendJsonResponse(false, 'Withdrawal request not found.');
        return;
    }
    if ($request['status'] !== 'pending') {
        sendJsonResponse(false, 'This withdrawal request is not pending.');
        return;
    }

    // Begin transaction
    $conn->begin_transaction();
    try {
        // Update withdrawal request status
        $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'approved', processed_date = NOW(), admin_notes = ? WHERE id = ?");
        $status = 'approved';
        $stmt->bind_param("si", $admin_notes, $request_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update withdrawal request status.");
        }
        $stmt->close();

        // Update the corresponding transaction status to completed
        // Assuming there's a transaction linked directly or by user_id and amount for 'pending' withdrawals
        // A more robust system would link withdrawal_requests.id to transactions.id
        // For simplicity, we'll update the latest pending withdrawal transaction for this user/amount
        $stmt = $conn->prepare("UPDATE transactions SET status = 'completed' WHERE user_id = ? AND amount = ? AND type = 'withdrawal' AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("id", $request['user_id'], $request['amount']);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update transaction status.");
        }
        $stmt->close();


        $conn->commit();
        sendJsonResponse(true, 'Withdrawal request approved successfully.');

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Approve withdrawal failed for request $request_id: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to approve withdrawal. ' . $e->getMessage());
    }
}

/**
 * Rejects a pending withdrawal request.
 * @param mysqli $conn Database connection object.
 * @param array $data Withdrawal request ID and optional admin notes.
 */
function rejectWithdrawal($conn, $data) {
    $request_id = $data['request_id'] ?? 0;
    $admin_notes = $data['admin_notes'] ?? 'Rejected by admin';

    if (!is_numeric($request_id) || $request_id <= 0) {
        sendJsonResponse(false, 'Invalid request ID.');
        return;
    }

    // Fetch request details
    $stmt = $conn->prepare("SELECT user_id, amount, status FROM withdrawal_requests WHERE id = ?");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        sendJsonResponse(false, 'Withdrawal request not found.');
        return;
    }
    if ($request['status'] !== 'pending') {
        sendJsonResponse(false, 'This withdrawal request is not pending.');
        return;
    }

    // Begin transaction
    $conn->begin_transaction();
    try {
        // Update withdrawal request status
        $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'rejected', processed_date = NOW(), admin_notes = ? WHERE id = ?");
        $status = 'rejected';
        $stmt->bind_param("si", $admin_notes, $request_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update withdrawal request status.");
        }
        $stmt->close();

        // Refund the amount to the user's wallet (since it was deducted upon request)
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
        $stmt->bind_param("di", $request['amount'], $request['user_id']);
        if (!$stmt->execute()) {
            throw new Exception("Failed to refund user wallet.");
        }
        $stmt->close();

        // Update the corresponding transaction status to cancelled
        $stmt = $conn->prepare("UPDATE transactions SET status = 'cancelled', description = CONCAT(description, ' (Rejected: ', ?, ')') WHERE user_id = ? AND amount = ? AND type = 'withdrawal' AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("sid", $admin_notes, $request['user_id'], $request['amount']);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update transaction status.");
        }
        $stmt->close();

        $conn->commit();
        sendJsonResponse(true, 'Withdrawal request rejected and amount refunded to user.');

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Reject withdrawal failed for request $request_id: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to reject withdrawal. ' . $e->getMessage());
    }
}

/**
 * Fetches all investment plans (active and inactive).
 * @param mysqli $conn Database connection object.
 */
function getAllPlans($conn) {
    $stmt = $conn->prepare("SELECT id, plan_name, price, duration_days, daily_profit, total_roi, is_active FROM investment_plans ORDER BY id ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $plans = [];
    while ($row = $result->fetch_assoc()) {
        $plans[] = $row;
    }
    sendJsonResponse(true, 'All investment plans fetched successfully.', ['plans' => $plans]);
    $stmt->close();
}

/**
 * Adds a new investment plan.
 * @param mysqli $conn Database connection object.
 * @param array $data Plan details.
 */
function addInvestmentPlan($conn, $data) {
    $plan_name = $data['plan_name'] ?? '';
    $price = $data['price'] ?? 0;
    $duration_days = $data['duration_days'] ?? 0;
    $daily_profit = $data['daily_profit'] ?? 0;
    $total_roi = $data['total_roi'] ?? 0;
    $is_active = $data['is_active'] ?? true; // Default to active

    if (empty($plan_name) || !is_numeric($price) || $price <= 0 ||
        !is_numeric($duration_days) || $duration_days <= 0 ||
        !is_numeric($daily_profit) || $daily_profit <= 0 ||
        !is_numeric($total_roi) || $total_roi <= 0) {
        sendJsonResponse(false, 'All plan fields must be valid and positive numbers.');
        return;
    }

    $stmt = $conn->prepare("INSERT INTO investment_plans (plan_name, price, duration_days, daily_profit, total_roi, is_active) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sdddis", $plan_name, $price, $duration_days, $daily_profit, $total_roi, $is_active);

    if ($stmt->execute()) {
        sendJsonResponse(true, 'Investment plan added successfully.');
    } else {
        error_log("Add plan failed: " . $stmt->error);
        sendJsonResponse(false, 'Failed to add investment plan. Plan name might already exist.');
    }
    $stmt->close();
}

/**
 * Updates an existing investment plan.
 * @param mysqli $conn Database connection object.
 * @param array $data Plan details including ID.
 */
function updateInvestmentPlan($conn, $data) {
    $plan_id = $data['id'] ?? 0;
    $plan_name = $data['plan_name'] ?? null;
    $price = $data['price'] ?? null;
    $duration_days = $data['duration_days'] ?? null;
    $daily_profit = $data['daily_profit'] ?? null;
    $total_roi = $data['total_roi'] ?? null;
    $is_active = $data['is_active'] ?? null;

    if (!is_numeric($plan_id) || $plan_id <= 0) {
        sendJsonResponse(false, 'Invalid Plan ID.');
        return;
    }

    $updates = [];
    $params = [];
    $types = "";

    if ($plan_name !== null) { $updates[] = "plan_name = ?"; $params[] = $plan_name; $types .= "s"; }
    if ($price !== null && is_numeric($price) && $price > 0) { $updates[] = "price = ?"; $params[] = $price; $types .= "d"; }
    if ($duration_days !== null && is_numeric($duration_days) && $duration_days > 0) { $updates[] = "duration_days = ?"; $params[] = $duration_days; $types .= "i"; }
    if ($daily_profit !== null && is_numeric($daily_profit) && $daily_profit > 0) { $updates[] = "daily_profit = ?"; $params[] = $daily_profit; $types .= "d"; }
    if ($total_roi !== null && is_numeric($total_roi) && $total_roi > 0) { $updates[] = "total_roi = ?"; $params[] = $total_roi; $types .= "d"; }
    if ($is_active !== null) { $updates[] = "is_active = ?"; $params[] = (bool)$is_active; $types .= "i"; }

    if (empty($updates)) {
        sendJsonResponse(false, 'No valid fields provided for update.');
        return;
    }

    $sql = "UPDATE investment_plans SET " . implode(", ", $updates) . " WHERE id = ?";
    $params[] = $plan_id;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare statement failed: " . $conn->error);
        sendJsonResponse(false, 'Database error during update preparation.');
        return;
    }
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            sendJsonResponse(true, 'Investment plan updated successfully.');
        } else {
            sendJsonResponse(false, 'Investment plan not found or no changes made.');
        }
    } else {
        error_log("Update plan failed: " . $stmt->error);
        sendJsonResponse(false, 'Failed to update investment plan.');
    }
    $stmt->close();
}

/**
 * Deletes an investment plan.
 * @param mysqli $conn Database connection object.
 * @param array $data Plan ID.
 */
function deleteInvestmentPlan($conn, $data) {
    $plan_id = $data['id'] ?? 0;

    if (!is_numeric($plan_id) || $plan_id <= 0) {
        sendJsonResponse(false, 'Invalid Plan ID.');
        return;
    }

    // Check if any user investments are linked to this plan
    $stmt = $conn->prepare("SELECT COUNT(*) FROM user_investments WHERE plan_id = ?");
    $stmt->bind_param("i", $plan_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count > 0) {
        sendJsonResponse(false, 'Cannot delete plan: There are active user investments linked to this plan. Consider deactivating it instead.');
        return;
    }

    $stmt = $conn->prepare("DELETE FROM investment_plans WHERE id = ?");
    $stmt->bind_param("i", $plan_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            sendJsonResponse(true, 'Investment plan deleted successfully.');
        } else {
            sendJsonResponse(false, 'Investment plan not found.');
        }
    } else {
        error_log("Delete plan failed: " . $stmt->error);
        sendJsonResponse(false, 'Failed to delete investment plan.');
    }
    $stmt->close();
}

/**
 * Fetches all transactions from the database.
 * @param mysqli $conn Database connection object.
 */
function getTransactions($conn) {
    $stmt = $conn->prepare("
        SELECT
            t.id,
            u.full_name AS user_name,
            t.type,
            t.amount,
            t.description,
            t.status,
            t.created_at
        FROM
            transactions t
        JOIN
            users u ON t.user_id = u.id
        ORDER BY
            t.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    sendJsonResponse(true, 'All transactions fetched successfully.', ['transactions' => $transactions]);
    $stmt->close();
}


// Close database connection at the end of script execution
$conn->close();
?>