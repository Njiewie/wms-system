<?php
/**
 * ECWMS Security Utility Library
 * Comprehensive security functions for the WMS system
 */

class WMSSecurity {
    private static $instance = null;
    private $csrf_token = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->initCSRF();
    }

    // CSRF Protection
    private function initCSRF() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $this->csrf_token = $_SESSION['csrf_token'];
    }

    public function getCSRFToken() {
        return $this->csrf_token;
    }

    public function generateCSRFField() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($this->csrf_token) . '">';
    }

    public function validateCSRF($token = null) {
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;
        }

        if (!$token || !hash_equals($this->csrf_token, $token)) {
            throw new Exception('CSRF token validation failed');
        }
        return true;
    }

    // Input Validation & Sanitization
    public static function sanitizeString($input, $max_length = null) {
        if ($input === null) return null;

        $sanitized = trim(strip_tags($input));

        if ($max_length && strlen($sanitized) > $max_length) {
            $sanitized = substr($sanitized, 0, $max_length);
        }

        return $sanitized;
    }

    public static function validateInteger($input, $min = null, $max = null) {
        $value = filter_var($input, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new InvalidArgumentException('Invalid integer value');
        }

        if ($min !== null && $value < $min) {
            throw new InvalidArgumentException("Value must be at least {$min}");
        }

        if ($max !== null && $value > $max) {
            throw new InvalidArgumentException("Value must be at most {$max}");
        }

        return $value;
    }

    public static function validateFloat($input, $min = null, $max = null) {
        $value = filter_var($input, FILTER_VALIDATE_FLOAT);

        if ($value === false) {
            throw new InvalidArgumentException('Invalid float value');
        }

        if ($min !== null && $value < $min) {
            throw new InvalidArgumentException("Value must be at least {$min}");
        }

        if ($max !== null && $value > $max) {
            throw new InvalidArgumentException("Value must be at most {$max}");
        }

        return $value;
    }

    public static function validateDate($input) {
        $date = DateTime::createFromFormat('Y-m-d', $input);

        if (!$date || $date->format('Y-m-d') !== $input) {
            throw new InvalidArgumentException('Invalid date format. Expected YYYY-MM-DD');
        }

        return $input;
    }

    public static function validateEmail($email) {
        $sanitized = filter_var($email, FILTER_VALIDATE_EMAIL);

        if (!$sanitized) {
            throw new InvalidArgumentException('Invalid email address');
        }

        return $sanitized;
    }

    public static function validateAlphaNumeric($input, $allow_spaces = false) {
        $pattern = $allow_spaces ? '/^[a-zA-Z0-9\s]+$/' : '/^[a-zA-Z0-9]+$/';

        if (!preg_match($pattern, $input)) {
            throw new InvalidArgumentException('Input must contain only alphanumeric characters' . ($allow_spaces ? ' and spaces' : ''));
        }

        return $input;
    }

    // Secure Database Operations
    public static function executePreparedQuery($conn, $sql, $types = '', $params = []) {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Database prepare failed: ' . $conn->error);
        }

        if (!empty($params)) {
            if (strlen($types) !== count($params)) {
                throw new InvalidArgumentException('Parameter count does not match types');
            }
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception('Database execution failed: ' . $stmt->error);
        }

        return $stmt;
    }

    public static function selectOne($conn, $sql, $types = '', $params = []) {
        $stmt = self::executePreparedQuery($conn, $sql, $types, $params);
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row;
    }

    public static function selectAll($conn, $sql, $types = '', $params = []) {
        $stmt = self::executePreparedQuery($conn, $sql, $types, $params);
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public static function insertRecord($conn, $table, $data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');
        $types = '';
        $values = [];

        foreach ($data as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } elseif (is_string($value)) {
                $types .= 's';
            } else {
                $types .= 's';
                $value = (string) $value;
            }
            $values[] = $value;
        }

        $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = self::executePreparedQuery($conn, $sql, $types, $values);
        $insert_id = $conn->insert_id;
        $stmt->close();

        return $insert_id;
    }

    public static function updateRecord($conn, $table, $data, $where_condition, $where_types = '', $where_params = []) {
        $set_clauses = [];
        $types = '';
        $values = [];

        foreach ($data as $column => $value) {
            $set_clauses[] = "`{$column}` = ?";
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } elseif (is_string($value)) {
                $types .= 's';
            } else {
                $types .= 's';
                $value = (string) $value;
            }
            $values[] = $value;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $set_clauses) . " WHERE {$where_condition}";

        $all_types = $types . $where_types;
        $all_params = array_merge($values, $where_params);

        $stmt = self::executePreparedQuery($conn, $sql, $all_types, $all_params);
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        return $affected_rows;
    }

    public static function deleteRecord($conn, $table, $where_condition, $where_types = '', $where_params = []) {
        $sql = "DELETE FROM `{$table}` WHERE {$where_condition}";

        $stmt = self::executePreparedQuery($conn, $sql, $where_types, $where_params);
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        return $affected_rows;
    }

    // Password Security
    public static function hashPassword($password) {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long');
        }

        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MB
            'time_cost' => 4,       // 4 iterations
            'threads' => 3,         // 3 threads
        ]);
    }

    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    public static function validatePasswordStrength($password) {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        if (!empty($errors)) {
            throw new InvalidArgumentException(implode('. ', $errors));
        }

        return true;
    }

    // File Upload Security
    public static function validateUploadedFile($file, $allowed_types = [], $max_size = 5242880) { // 5MB default
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new InvalidArgumentException('Invalid file upload');
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new InvalidArgumentException('No file was uploaded');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new InvalidArgumentException('File too large');
            default:
                throw new InvalidArgumentException('Upload error occurred');
        }

        if ($file['size'] > $max_size) {
            throw new InvalidArgumentException('File exceeds maximum size limit');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);

        if (!empty($allowed_types) && !in_array($mime_type, $allowed_types)) {
            throw new InvalidArgumentException('File type not allowed');
        }

        return true;
    }

    // Logging and Audit Trail
    public static function logActivity($conn, $user_id, $action, $details = null, $ip_address = null) {
        $ip_address = $ip_address ?: $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $data = [
            'user_id' => $user_id,
            'action' => $action,
            'details' => $details,
            'ip_address' => $ip_address,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        try {
            self::insertRecord($conn, 'audit_log', $data);
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }

    // Rate Limiting
    public static function checkRateLimit($identifier, $max_attempts = 5, $time_window = 300) { // 5 attempts per 5 minutes
        if (!isset($_SESSION['rate_limit'])) {
            $_SESSION['rate_limit'] = [];
        }

        $now = time();
        $attempts = $_SESSION['rate_limit'][$identifier] ?? [];

        // Remove old attempts outside the time window
        $attempts = array_filter($attempts, function($timestamp) use ($now, $time_window) {
            return ($now - $timestamp) < $time_window;
        });

        if (count($attempts) >= $max_attempts) {
            throw new Exception('Rate limit exceeded. Please try again later.');
        }

        // Record this attempt
        $attempts[] = $now;
        $_SESSION['rate_limit'][$identifier] = $attempts;

        return true;
    }

    // HTML Output Security
    public static function escape($string) {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function escapeJS($string) {
        return json_encode($string, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    // Generate secure random tokens
    public static function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }

    // Secure session management
    public static function regenerateSession() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    // IP-based access control
    public static function validateIPAddress($allowed_ips = []) {
        if (empty($allowed_ips)) {
            return true; // No IP restrictions
        }

        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

        foreach ($allowed_ips as $allowed_ip) {
            if (fnmatch($allowed_ip, $client_ip)) {
                return true;
            }
        }

        throw new Exception('Access denied from this IP address');
    }
}

// Global helper functions
function csrf_field() {
    return WMSSecurity::getInstance()->generateCSRFField();
}

function csrf_token() {
    return WMSSecurity::getInstance()->getCSRFToken();
}

function validate_csrf($token = null) {
    return WMSSecurity::getInstance()->validateCSRF($token);
}

function secure_escape($string) {
    return WMSSecurity::escape($string);
}

function secure_query($conn, $sql, $types = '', $params = []) {
    return WMSSecurity::executePreparedQuery($conn, $sql, $types, $params);
}

function secure_select_one($conn, $sql, $types = '', $params = []) {
    return WMSSecurity::selectOne($conn, $sql, $types, $params);
}

function secure_select_all($conn, $sql, $types = '', $params = []) {
    return WMSSecurity::selectAll($conn, $sql, $types, $params);
}

function secure_insert($conn, $table, $data) {
    return WMSSecurity::insertRecord($conn, $table, $data);
}

function secure_update($conn, $table, $data, $where, $where_types = '', $where_params = []) {
    return WMSSecurity::updateRecord($conn, $table, $data, $where, $where_types, $where_params);
}

function secure_delete($conn, $table, $where, $where_types = '', $where_params = []) {
    return WMSSecurity::deleteRecord($conn, $table, $where, $where_types, $where_params);
}

// Error handler for security violations
function handleSecurityError($message, $code = 403) {
    http_response_code($code);

    if (defined('AJAX_REQUEST') && AJAX_REQUEST) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message, 'code' => $code]);
    } else {
        include 'security_error.php';
    }

    exit;
}

// Set security headers
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\';');
}
