<?php
/**
 * Secure Authentication System
 * Enhanced with comprehensive security measures
 */

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database config and security utilities
require_once 'db_config.php';

/**
 * Check if user is logged in with session validation
 */
function is_logged_in() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        return false;
    }

    // Validate session timeout (30 minutes)
    if (isset($_SESSION['last_activity'])) {
        $timeout = 1800; // 30 minutes
        if ((time() - $_SESSION['last_activity']) > $timeout) {
            session_destroy();
            return false;
        }
    }

    // Update last activity
    $_SESSION['last_activity'] = time();

    // Regenerate session ID periodically for security
    if (!isset($_SESSION['session_regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['session_regenerated'] = time();
    } elseif ((time() - $_SESSION['session_regenerated']) > 300) { // 5 minutes
        session_regenerate_id(true);
        $_SESSION['session_regenerated'] = time();
    }

    return true;
}

/**
 * Require login for protected pages
 */
function require_login() {
    if (!is_logged_in()) {
        // Store the requested page for redirect after login
        if (!headers_sent()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
            header('Location: login.php');
            exit;
        }
        die('Authentication required');
    }
}

/**
 * Check if user has specific role
 */
function has_role($required_role) {
    if (!is_logged_in()) {
        return false;
    }

    $user_role = $_SESSION['role'] ?? 'user';

    // Role hierarchy: admin > manager > user > viewer
    $role_levels = [
        'viewer' => 1,
        'user' => 2,
        'manager' => 3,
        'admin' => 4
    ];

    $user_level = $role_levels[$user_role] ?? 1;
    $required_level = $role_levels[$required_role] ?? 4;

    return $user_level >= $required_level;
}

/**
 * Check if user is admin
 */
function is_admin() {
    return has_role('admin');
}

/**
 * Check if user is manager or above
 */
function is_manager() {
    return has_role('manager');
}

/**
 * Require admin access
 */
function require_admin() {
    require_login();
    if (!is_admin()) {
        header('Location: secure-dashboard.php?error=' . urlencode('Access denied: Admin privileges required'));
        exit;
    }
}

/**
 * Require manager access or above
 */
function require_manager() {
    require_login();
    if (!is_manager()) {
        header('Location: secure-dashboard.php?error=' . urlencode('Access denied: Manager privileges required'));
        exit;
    }
}

/**
 * Get current user ID
 */
function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current username
 */
function get_current_user() {
    return $_SESSION['username'] ?? null;
}

/**
 * Get user role
 */
function get_user_role() {
    return $_SESSION['role'] ?? 'user';
}

/**
 * Get user full name
 */
function get_user_full_name() {
    $first = $_SESSION['first_name'] ?? '';
    $last = $_SESSION['last_name'] ?? '';
    return trim($first . ' ' . $last) ?: get_current_user();
}

/**
 * Secure logout function
 */
function secure_logout() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Clear all session variables
        $_SESSION = array();

        // Delete session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        // Destroy session
        session_destroy();
    }

    // Redirect to login
    header('Location: login.php?logged_out=1');
    exit;
}

/**
 * Check session security
 */
function validate_session_security() {
    // Check for session fixation attacks
    if (!isset($_SESSION['user_ip'])) {
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    } elseif ($_SESSION['user_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        // IP changed - possible session hijacking
        secure_logout();
    }

    // Check user agent consistency
    if (!isset($_SESSION['user_agent'])) {
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    } elseif ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        // User agent changed - possible session hijacking
        secure_logout();
    }
}

/**
 * Initialize secure session
 */
function init_secure_session($user_data) {
    // Regenerate session ID
    session_regenerate_id(true);

    // Set session variables
    $_SESSION['user_id'] = $user_data['id'];
    $_SESSION['username'] = $user_data['username'];
    $_SESSION['role'] = $user_data['role'];
    $_SESSION['first_name'] = $user_data['first_name'] ?? '';
    $_SESSION['last_name'] = $user_data['last_name'] ?? '';
    $_SESSION['email'] = $user_data['email'] ?? '';

    // Security tracking
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['session_regenerated'] = time();
}

// Validate session security on every request
if (is_logged_in()) {
    validate_session_security();
}
?>
