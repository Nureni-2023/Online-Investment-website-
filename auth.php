<?php
// auth.php

// Start session to manage user login state
session_start();

// Include database connection
require_once 'db_connect.php';

// Set content type to JSON for API responses
header('Content-Type: application/json');

// Get the request method
$method = $_SERVER['REQUEST_METHOD'];

// Handle POST requests for registration and login
if ($method === 'POST') {
    $action = $_GET['action'] ?? ''; // Get action from query parameter

    // Get raw POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
        exit();
    }

    switch ($action) {
        case 'register':
            registerUser($conn, $data);
            break;
        case 'login':
            loginUser($conn, $data);
            break;
        case 'admin_login':
            adminLogin($conn, $data);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid authentication action.']);
            break;
    }
} elseif ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'check_session') {
        checkSession();
    } elseif ($action === 'logout') {
        logoutUser();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid GET action.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
}

/**
 * Registers a new user.
 * @param mysqli $conn Database connection object.
 * @param array $data User registration data.
 */
function registerUser($conn, $data) {
    $full_name = $data['name'] ?? '';
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $confirm_password = $data['confirm_password'] ?? '';

    // Basic validation
    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        return;
    }

    if ($password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        return;
    }

    // Hash the password securely
    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already registered.']);
        $stmt->close();
        return;
    }
    $stmt->close();

    // Insert new user into the database
    $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $full_name, $email, $password_hash);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Registration successful! Please login.']);
    } else {
        error_log("User registration failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
    }
    $stmt->close();
}

/**
 * Logs in a user.
 * @param mysqli $conn Database connection object.
 * @param array $data User login data.
 */
function loginUser($conn, $data) {
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        return;
    }

    $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, wallet_balance FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password_hash'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['wallet_balance'] = $user['wallet_balance'];
            $_SESSION['is_admin'] = false; // Not an admin user

            echo json_encode([
                'success' => true,
                'message' => 'Login successful!',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['full_name'],
                    'email' => $user['email'],
                    'wallet_balance' => $user['wallet_balance']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    }
    $stmt->close();
}

/**
 * Logs in an admin user.
 * @param mysqli $conn Database connection object.
 * @param array $data Admin login data.
 */
function adminLogin($conn, $data) {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
        return;
    }

    $stmt = $conn->prepare("SELECT id, username, full_name, password_hash FROM admin_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();
        if (password_verify($password, $admin['password_hash'])) {
            // Set session variables for admin
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['is_admin'] = true; // Mark as admin session

            echo json_encode([
                'success' => true,
                'message' => 'Admin login successful!',
                'admin' => [
                    'id' => $admin['id'],
                    'username' => $admin['username'],
                    'name' => $admin['full_name']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    }
    $stmt->close();
}

/**
 * Checks if a user is currently logged in.
 */
function checkSession() {
    if (isset($_SESSION['user_id']) && !$_SESSION['is_admin']) {
        echo json_encode([
            'success' => true,
            'is_logged_in' => true,
            'is_admin' => false,
            'user' => [
                'id' => $_SESSION['user_id'],
                'name' => $_SESSION['user_name'],
                'email' => $_SESSION['user_email'],
                'wallet_balance' => $_SESSION['wallet_balance']
            ]
        ]);
    } elseif (isset($_SESSION['admin_id']) && $_SESSION['is_admin']) {
        echo json_encode([
            'success' => true,
            'is_logged_in' => true,
            'is_admin' => true,
            'admin' => [
                'id' => $_SESSION['admin_id'],
                'username' => $_SESSION['admin_username'],
                'name' => $_SESSION['admin_name']
            ]
        ]);
    } else {
        echo json_encode(['success' => true, 'is_logged_in' => false]);
    }
}

/**
 * Logs out the current user or admin.
 */
function logoutUser() {
    // Unset all session variables
    $_SESSION = array();

    // Destroy the session
    session_destroy();

    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
}

// Close database connection at the end of script execution
$conn->close();
?>