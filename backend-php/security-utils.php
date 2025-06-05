<?php
/**
 * WMS Security Utilities Class
 * Comprehensive security functions for the Warehouse Management System
 *
 * Features:
 * - Input validation and sanitization
 * - CSRF protection
 * - Rate limiting
 * - SQL injection prevention
 * - XSS protection
 * - Activity logging
 * - Security headers
 */

class SecurityUtils {
    private static $instance = null;
    private $conn;

    public function __construct($database_connection = null) {
        $this->conn = $database_connection;
    }

    public static function getInstance($database_connection = null) {
        if (self::$instance === null) {
            self::$instance = new self($database_connection);
        }
        return self::$instance;
    }

    /**
     * Generate CSRF Token
     */
    public function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF Token
     */
    public function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Sanitize input string
     */
    public function sanitizeInput($input, $max_length = 255) {
        if ($input === null) return '';
        $input = trim($input);
        $input = strip_tags($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        return substr($input, 0, $max_length);
    }

    /**
     * Validate integer input
     */
    public function validateInteger($input, $min = null, $max = null) {
        $value = filter_var($input, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new InvalidArgumentException('Invalid integer value');
        }
        if ($min !== null && $value < $min) {
            throw new InvalidArgumentException("Value must be at least $min");
        }
        if ($max !== null && $value > $max) {
            throw new InvalidArgumentException("Value must be at most $max");
        }
        return $value;
    }

    /**
     * Validate float input
     */
    public function validateFloat($input, $min = null, $max = null) {
        $value = filter_var($input, FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            throw new InvalidArgumentException('Invalid float value');
        }
        if ($min !== null && $value < $min) {
            throw new InvalidArgumentException("Value must be at least $min");
        }
        if ($max !== null && $value > $max) {
            throw new InvalidArgumentException("Value must be at most $max");
        }
        return $value;
    }

    /**
     * Validate email
     */
    public function validateEmail($email) {
        $email = filter_var($email, FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            throw new InvalidArgumentException('Invalid email format');
        }
        return $email;
    }

    /**
     * Rate limiting check
     */
    public function checkRateLimit($user_id, $action, $max_attempts = 5, $time_window = 300) {
        if (!$this->conn) return true;

        $cutoff_time = date('Y-m-d H:i:s', time() - $time_window);

        try {
            // Clean old entries
            $cleanup_stmt = $this->conn->prepare("DELETE FROM rate_limits WHERE created_at < ?");
            if ($cleanup_stmt) {
                $cleanup_stmt->bind_param("s", $cutoff_time);
                $cleanup_stmt->execute();
                $cleanup_stmt->close();
            }

            // Check current rate
            $check_stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM rate_limits WHERE user_id = ? AND action = ? AND created_at > ?");
            if ($check_stmt) {
                $check_stmt->bind_param("sss", $user_id, $action, $cutoff_time);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $row = $result->fetch_assoc();
                $check_stmt->close();

                if ($row['count'] >= $max_attempts) {
                    return false;
                }
            }

            // Log this attempt
            $log_stmt = $this->conn->prepare("INSERT INTO rate_limits (user_id, action, ip_address, created_at) VALUES (?, ?, ?, NOW())");
            if ($log_stmt) {
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $log_stmt->bind_param("sss", $user_id, $action, $ip_address);
                $log_stmt->execute();
                $log_stmt->close();
            }

            return true;

        } catch (Exception $e) {
            error_log("Rate limiting error: " . $e->getMessage());
            return true; // Fail open for availability
        }
    }

    /**
     * Log security events
     */
    public function logSecurityEvent($user_id, $event_type, $details = '', $severity = 'medium') {
        if (!$this->conn) return;

        try {
            $stmt = $this->conn->prepare("INSERT INTO security_logs (user_id, event_type, details, severity, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                $stmt->bind_param("ssssss", $user_id, $event_type, $details, $severity, $ip_address, $user_agent);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Security logging error: " . $e->getMessage());
        }
    }

    /**
     * Log general activity
     */
    public function logActivity($user_id, $action, $details = '') {
        if (!$this->conn) return;

        try {
            $stmt = $this->conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                $stmt->bind_param("sssss", $user_id, $action, $details, $ip_address, $user_agent);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Activity logging error: " . $e->getMessage());
        }
    }

    /**
     * Secure database query execution
     */
    public function secureQuery($query, $types = '', $params = []) {
        if (!$this->conn) {
            throw new Exception('Database connection not available');
        }

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            throw new Exception('Query preparation failed: ' . $this->conn->error);
        }

        if (!empty($types) && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Query execution failed: ' . $error);
        }

        return $stmt;
    }

    /**
     * Secure password hashing
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }

    /**
     * Verify password
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    /**
     * Generate secure random string
     */
    public function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Set security headers
     */
    public function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\';');
    }

    /**
     * Clean old logs
     */
    public function cleanOldLogs($days = 30) {
        if (!$this->conn) return;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));

        try {
            // Clean activity logs
            $stmt1 = $this->conn->prepare("DELETE FROM activity_logs WHERE created_at < ?");
            if ($stmt1) {
                $stmt1->bind_param("s", $cutoff_date);
                $stmt1->execute();
                $stmt1->close();
            }

            // Clean security logs (keep longer)
            $security_cutoff = date('Y-m-d H:i:s', strtotime("-90 days"));
            $stmt2 = $this->conn->prepare("DELETE FROM security_logs WHERE created_at < ? AND severity != 'high'");
            if ($stmt2) {
                $stmt2->bind_param("s", $security_cutoff);
                $stmt2->execute();
                $stmt2->close();
            }

        } catch (Exception $e) {
            error_log("Log cleanup error: " . $e->getMessage());
        }
    }
}

/**
 * Helper functions for backward compatibility
 */

function generateCSRFToken() {
    return SecurityUtils::getInstance()->generateCSRFToken();
}

function validateCSRFToken($token) {
    return SecurityUtils::getInstance()->validateCSRFToken($token);
}

function sanitizeInput($input, $max_length = 255) {
    return SecurityUtils::getInstance()->sanitizeInput($input, $max_length);
}

function validateInteger($input, $min = null, $max = null) {
    return SecurityUtils::getInstance()->validateInteger($input, $min, $max);
}

function setSecurityHeaders() {
    SecurityUtils::getInstance()->setSecurityHeaders();
}

/**
 * CSRF token field for forms
 */
function csrf_field() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Validate CSRF token from POST data
 */
function validate_csrf() {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        throw new Exception('Invalid security token. Please refresh the page and try again.');
    }
}

/**
 * Secure escape for output
 */
function secure_escape($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Handle security errors
 */
function handleSecurityError($message, $redirect = 'secure-dashboard.php') {
    SecurityUtils::getInstance()->logSecurityEvent($_SESSION['user_id'] ?? 'unknown', 'security_error', $message, 'high');

    // Log to error log as well
    error_log("Security Error: $message | User: " . ($_SESSION['user_id'] ?? 'unknown') . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    // Redirect or show error
    header("Location: $redirect?error=" . urlencode('Security error occurred'));
    exit;
}

/**
 * Secure database operations
 */
function secure_select_one($conn, $query, $types = '', $params = []) {
    $security = SecurityUtils::getInstance($conn);
    $stmt = $security->secureQuery($query, $types, $params);
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

function secure_select_all($conn, $query, $types = '', $params = []) {
    $security = SecurityUtils::getInstance($conn);
    $stmt = $security->secureQuery($query, $types, $params);
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function secure_insert($conn, $table, $data) {
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($data), '?');
    $values = array_values($data);

    $query = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $types = str_repeat('s', count($values));

    $security = SecurityUtils::getInstance($conn);
    $stmt = $security->secureQuery($query, $types, $values);
    $insert_id = $conn->insert_id;
    $stmt->close();

    return $insert_id;
}

function secure_update($conn, $table, $data, $where, $where_types = '', $where_params = []) {
    $set_parts = [];
    $values = [];

    foreach ($data as $column => $value) {
        $set_parts[] = "$column = ?";
        $values[] = $value;
    }

    $query = "UPDATE $table SET " . implode(', ', $set_parts) . " WHERE $where";
    $types = str_repeat('s', count($values)) . $where_types;
    $all_params = array_merge($values, $where_params);

    $security = SecurityUtils::getInstance($conn);
    $stmt = $security->secureQuery($query, $types, $all_params);
    $affected_rows = $stmt->affected_rows;
    $stmt->close();

    return $affected_rows;
}

function secure_delete($conn, $table, $where, $where_types = '', $where_params = []) {
    $query = "UPDATE $table SET deleted_at = NOW() WHERE $where AND deleted_at IS NULL";

    $security = SecurityUtils::getInstance($conn);
    $stmt = $security->secureQuery($query, $where_types, $where_params);
    $affected_rows = $stmt->affected_rows;
    $stmt->close();

    return $affected_rows;
}

// Auto-create required tables if they don't exist
function createSecurityTables($conn) {
    $tables = [
        "CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(255) NOT NULL,
            action VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_action (user_id, action),
            INDEX idx_created (created_at)
        )",

        "CREATE TABLE IF NOT EXISTS security_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(255),
            event_type VARCHAR(100) NOT NULL,
            details TEXT,
            severity ENUM('low', 'medium', 'high') DEFAULT 'medium',
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_event_type (event_type),
            INDEX idx_created (created_at)
        )",

        "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(255),
            action VARCHAR(100) NOT NULL,
            table_name VARCHAR(100),
            record_id INT,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        )"
    ];

    foreach ($tables as $sql) {
        try {
            $conn->query($sql);
        } catch (Exception $e) {
            error_log("Failed to create security table: " . $e->getMessage());
        }
    }
}

// Initialize security tables when this file is included
if (isset($conn) && $conn instanceof mysqli) {
    createSecurityTables($conn);
}
?>
