<?php
/**
 * Enhanced Security Utilities Class
 * Provides comprehensive security features for WMS application
 * 
 * Features:
 * - CSRF Protection with token validation
 * - Input sanitization and validation
 * - Rate limiting with IP-based tracking
 * - Activity logging for audit trails
 * - Session security management
 * - XSS Protection with output encoding
 * - SQL injection prevention helpers
 * - Password security utilities
 */

class SecurityUtils {
    
    private static $instance = null;
    private $rateLimitFile = 'rate_limits.json';
    private $activityLogFile = 'activity_log.json';
    private $maxAttempts = 5;
    private $timeWindow = 900; // 15 minutes
    
    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            $this->initSecureSession();
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize secure session with enhanced security settings
     */
    public function initSecureSession() {
        // Set secure session configuration
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');
        
        // Generate secure session name
        session_name('WMS_SECURE_' . bin2hex(random_bytes(8)));
        
        // Start session with regeneration
        session_start();
        
        // Regenerate session ID periodically for security
        if (!isset($_SESSION['last_regeneration'])) {
            $this->regenerateSession();
        } elseif (time() - $_SESSION['last_regeneration'] > 300) { // 5 minutes
            $this->regenerateSession();
        }
        
        // Set session timeout
        if (!isset($_SESSION['timeout'])) {
            $_SESSION['timeout'] = time() + 3600; // 1 hour
        }
        
        // Check for session timeout
        if (time() > $_SESSION['timeout']) {
            $this->destroySession();
        }
    }
    
    /**
     * Regenerate session ID for security
     */
    public function regenerateSession() {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
        $_SESSION['timeout'] = time() + 3600;
    }
    
    /**
     * Destroy session securely
     */
    public function destroySession() {
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) 
            || time() - $_SESSION['csrf_token_time'] > 3600) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF token
     */
    public function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }
        
        // Check if token has expired (1 hour)
        if (time() - $_SESSION['csrf_token_time'] > 3600) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
        
        // Use hash_equals to prevent timing attacks
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Sanitize input data to prevent XSS
     */
    public function sanitizeInput($input, $type = 'string') {
        if (is_array($input)) {
            return array_map(function($item) use ($type) {
                return $this->sanitizeInput($item, $type);
            }, $input);
        }
        
        switch ($type) {
            case 'email':
                return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
            case 'url':
                return filter_var(trim($input), FILTER_SANITIZE_URL);
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'alphanumeric':
                return preg_replace('/[^a-zA-Z0-9]/', '', $input);
            case 'filename':
                return preg_replace('/[^a-zA-Z0-9._-]/', '', $input);
            default:
                return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    
    /**
     * Validate input data
     */
    public function validateInput($input, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = isset($input[$field]) ? $input[$field] : null;
            
            // Required field check
            if (isset($rule['required']) && $rule['required'] && empty($value)) {
                $errors[$field] = "Field {$field} is required";
                continue;
            }
            
            if (!empty($value)) {
                // Type validation
                if (isset($rule['type'])) {
                    switch ($rule['type']) {
                        case 'email':
                            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                $errors[$field] = "Invalid email format";
                            }
                            break;
                        case 'int':
                            if (!filter_var($value, FILTER_VALIDATE_INT)) {
                                $errors[$field] = "Must be a valid integer";
                            }
                            break;
                        case 'float':
                            if (!filter_var($value, FILTER_VALIDATE_FLOAT)) {
                                $errors[$field] = "Must be a valid number";
                            }
                            break;
                        case 'url':
                            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                                $errors[$field] = "Invalid URL format";
                            }
                            break;
                    }
                }
                
                // Length validation
                if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
                    $errors[$field] = "Minimum length is {$rule['min_length']} characters";
                }
                if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                    $errors[$field] = "Maximum length is {$rule['max_length']} characters";
                }
                
                // Pattern validation
                if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                    $errors[$field] = $rule['pattern_message'] ?? "Invalid format";
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Rate limiting functionality
     */
    public function checkRateLimit($identifier = null) {
        $identifier = $identifier ?: $this->getClientIdentifier();
        $rateLimits = $this->loadRateLimits();
        
        $currentTime = time();
        
        // Clean old entries
        foreach ($rateLimits as $id => $data) {
            if ($currentTime - $data['first_attempt'] > $this->timeWindow) {
                unset($rateLimits[$id]);
            }
        }
        
        if (!isset($rateLimits[$identifier])) {
            $rateLimits[$identifier] = [
                'attempts' => 1,
                'first_attempt' => $currentTime,
                'last_attempt' => $currentTime
            ];
        } else {
            $rateLimits[$identifier]['attempts']++;
            $rateLimits[$identifier]['last_attempt'] = $currentTime;
        }
        
        $this->saveRateLimits($rateLimits);
        
        return $rateLimits[$identifier]['attempts'] <= $this->maxAttempts;
    }
    
    /**
     * Get remaining rate limit attempts
     */
    public function getRemainingAttempts($identifier = null) {
        $identifier = $identifier ?: $this->getClientIdentifier();
        $rateLimits = $this->loadRateLimits();
        
        if (!isset($rateLimits[$identifier])) {
            return $this->maxAttempts;
        }
        
        $currentTime = time();
        if ($currentTime - $rateLimits[$identifier]['first_attempt'] > $this->timeWindow) {
            return $this->maxAttempts;
        }
        
        return max(0, $this->maxAttempts - $rateLimits[$identifier]['attempts']);
    }
    
    /**
     * Reset rate limit for identifier
     */
    public function resetRateLimit($identifier = null) {
        $identifier = $identifier ?: $this->getClientIdentifier();
        $rateLimits = $this->loadRateLimits();
        unset($rateLimits[$identifier]);
        $this->saveRateLimits($rateLimits);
    }
    
    /**
     * Log security activity
     */
    public function logActivity($action, $details = [], $severity = 'INFO') {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'user_id' => $_SESSION['user_id'] ?? 'Anonymous',
            'action' => $action,
            'details' => $details,
            'severity' => $severity,
            'session_id' => session_id()
        ];
        
        $logs = $this->loadActivityLogs();
        $logs[] = $logEntry;
        
        // Keep only last 1000 entries to prevent file bloat
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        $this->saveActivityLogs($logs);
        
        // Log critical activities to syslog as well
        if (in_array($severity, ['ERROR', 'WARNING', 'CRITICAL'])) {
            error_log("WMS Security: {$action} - " . json_encode($details));
        }
    }
    
    /**
     * Validate user session and permissions
     */
    public function validateSession($requiredRole = null) {
        // Check if session exists and is valid
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['authenticated'])) {
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['timeout']) && time() > $_SESSION['timeout']) {
            $this->destroySession();
            return false;
        }
        
        // Check if role is required and user has it
        if ($requiredRole !== null) {
            $userRole = $_SESSION['role'] ?? '';
            if (!$this->hasRole($userRole, $requiredRole)) {
                $this->logActivity('ACCESS_DENIED', [
                    'required_role' => $requiredRole,
                    'user_role' => $userRole
                ], 'WARNING');
                return false;
            }
        }
        
        // Update session timeout
        $_SESSION['timeout'] = time() + 3600;
        
        return true;
    }
    
    /**
     * Check if user has required role
     */
    public function hasRole($userRole, $requiredRole) {
        $roles = [
            'viewer' => 1,
            'operator' => 2,
            'supervisor' => 3,
            'manager' => 4,
            'admin' => 5
        ];
        
        $userLevel = $roles[$userRole] ?? 0;
        $requiredLevel = $roles[$requiredRole] ?? 0;
        
        return $userLevel >= $requiredLevel;
    }
    
    /**
     * Generate secure password hash
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    /**
     * Verify password against hash
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Generate secure random token
     */
    public function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Validate password strength
     */
    public function validatePasswordStrength($password) {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters long";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }
        
        return $errors;
    }
    
    /**
     * Escape output for safe HTML display
     */
    public function escapeOutput($output) {
        if (is_array($output)) {
            return array_map([$this, 'escapeOutput'], $output);
        }
        return htmlspecialchars($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Get client IP address
     */
    private function getClientIP() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                   'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = trim($_SERVER[$key]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Get client identifier for rate limiting
     */
    private function getClientIdentifier() {
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return hash('sha256', $ip . $userAgent);
    }
    
    /**
     * Load rate limits from file
     */
    private function loadRateLimits() {
        if (!file_exists($this->rateLimitFile)) {
            return [];
        }
        
        $content = file_get_contents($this->rateLimitFile);
        return json_decode($content, true) ?: [];
    }
    
    /**
     * Save rate limits to file
     */
    private function saveRateLimits($rateLimits) {
        file_put_contents($this->rateLimitFile, json_encode($rateLimits), LOCK_EX);
    }
    
    /**
     * Load activity logs from file
     */
    private function loadActivityLogs() {
        if (!file_exists($this->activityLogFile)) {
            return [];
        }
        
        $content = file_get_contents($this->activityLogFile);
        return json_decode($content, true) ?: [];
    }
    
    /**
     * Save activity logs to file
     */
    private function saveActivityLogs($logs) {
        file_put_contents($this->activityLogFile, json_encode($logs), LOCK_EX);
    }
    
    /**
     * Clean sensitive data from error messages
     */
    public function sanitizeErrorMessage($message) {
        // Remove potential sensitive information from error messages
        $patterns = [
            '/password[\'"]?\s*[:=]\s*[\'"]?[^\s\'"]+/i',
            '/api[_-]?key[\'"]?\s*[:=]\s*[\'"]?[^\s\'"]+/i',
            '/secret[\'"]?\s*[:=]\s*[\'"]?[^\s\'"]+/i',
            '/token[\'"]?\s*[:=]\s*[\'"]?[^\s\'"]+/i',
            '/SQLSTATE\[[^\]]+\]/i',
            '/for query:/i'
        ];
        
        $replacements = [
            'password: [HIDDEN]',
            'api_key: [HIDDEN]',
            'secret: [HIDDEN]',
            'token: [HIDDEN]',
            'Database error',
            'in query'
        ];
        
        return preg_replace($patterns, $replacements, $message);
    }
    
    /**
     * Create secure file upload validation
     */
    public function validateFileUpload($file, $allowedTypes = [], $maxSize = 5242880) { // 5MB default
        $errors = [];
        
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "File upload failed";
            return $errors;
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            $errors[] = "File size exceeds maximum allowed size";
        }
        
        // Check file type
        if (!empty($allowedTypes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                $errors[] = "File type not allowed";
            }
        }
        
        // Check for malicious content
        $content = file_get_contents($file['tmp_name']);
        if (preg_match('/<\?php|<script|javascript:/i', $content)) {
            $errors[] = "File contains potentially dangerous content";
        }
        
        return $errors;
    }
}
?>