<?php
/**
 * Secure Database Configuration and Connection Manager
 * Provides secure database connectivity with connection pooling,
 * prepared statement helpers, and transaction management
 */

require_once 'security-utils.php';

class SecureDatabase {
    
    private static $instance = null;
    private $pdo = null;
    private $transactionLevel = 0;
    private $security;
    
    // Database configuration - Use environment variables in production
    private $config = [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'dbname' => $_ENV['DB_NAME'] ?? 'wms_db',
        'username' => $_ENV['DB_USER'] ?? 'wms_user',
        'password' => $_ENV['DB_PASS'] ?? 'secure_password_123!',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'"
        ]
    ];
    
    private function __construct() {
        $this->security = SecurityUtils::getInstance();
        $this->connect();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish secure database connection
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['dbname']};charset={$this->config['charset']}";
            
            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $this->config['options']);
            
            // Set additional security settings
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
            $this->pdo->exec("SET SESSION time_zone = '+00:00'");
            
            $this->security->logActivity('DATABASE_CONNECTED', [
                'host' => $this->config['host'],
                'database' => $this->config['dbname']
            ]);
            
        } catch (PDOException $e) {
            $this->security->logActivity('DATABASE_CONNECTION_FAILED', [
                'error' => $this->security->sanitizeErrorMessage($e->getMessage())
            ], 'ERROR');
            
            throw new Exception("Database connection failed. Please try again later.");
        }
    }
    
    /**
     * Get PDO instance
     */
    public function getPDO() {
        return $this->pdo;
    }
    
    /**
     * Execute prepared statement with parameters
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            
            // Log query execution (without sensitive data)
            $this->security->logActivity('DATABASE_QUERY', [
                'query_type' => $this->getQueryType($sql),
                'params_count' => count($params)
            ]);
            
            $result = $stmt->execute($params);
            
            if (!$result) {
                throw new PDOException("Query execution failed");
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            $this->security->logActivity('DATABASE_QUERY_FAILED', [
                'error' => $this->security->sanitizeErrorMessage($e->getMessage()),
                'query_type' => $this->getQueryType($sql)
            ], 'ERROR');
            
            throw new Exception("Database query failed. Please try again later.");
        }
    }
    
    /**
     * Fetch single row
     */
    public function fetchRow($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Fetch single value
     */
    public function fetchValue($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Insert record and return last insert ID
     */
    public function insert($table, $data) {
        $this->validateTableName($table);
        $data = $this->sanitizeData($data);
        
        $columns = array_keys($data);
        $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
        
        $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        
        $params = [];
        foreach ($data as $column => $value) {
            $params[':' . $column] = $value;
        }
        
        $this->execute($sql, $params);
        
        $insertId = $this->pdo->lastInsertId();
        
        $this->security->logActivity('DATABASE_INSERT', [
            'table' => $table,
            'insert_id' => $insertId,
            'columns' => $columns
        ]);
        
        return $insertId;
    }
    
    /**
     * Update records
     */
    public function update($table, $data, $where, $whereParams = []) {
        $this->validateTableName($table);
        $data = $this->sanitizeData($data);
        
        $setClause = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $setClause[] = "`{$column}` = :set_{$column}";
            $params[":set_{$column}"] = $value;
        }
        
        // Add where parameters
        foreach ($whereParams as $key => $value) {
            $params[$key] = $value;
        }
        
        $sql = "UPDATE `{$table}` SET " . implode(', ', $setClause) . " WHERE {$where}";
        
        $stmt = $this->execute($sql, $params);
        $affectedRows = $stmt->rowCount();
        
        $this->security->logActivity('DATABASE_UPDATE', [
            'table' => $table,
            'affected_rows' => $affectedRows,
            'columns' => array_keys($data)
        ]);
        
        return $affectedRows;
    }
    
    /**
     * Soft delete record
     */
    public function softDelete($table, $id, $idColumn = 'id') {
        $this->validateTableName($table);
        
        $data = [
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $_SESSION['user_id'] ?? null
        ];
        
        return $this->update($table, $data, "`{$idColumn}` = :id AND deleted_at IS NULL", [':id' => $id]);
    }
    
    /**
     * Hard delete record
     */
    public function delete($table, $where, $whereParams = []) {
        $this->validateTableName($table);
        
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        $stmt = $this->execute($sql, $whereParams);
        $affectedRows = $stmt->rowCount();
        
        $this->security->logActivity('DATABASE_DELETE', [
            'table' => $table,
            'affected_rows' => $affectedRows
        ], 'WARNING');
        
        return $affectedRows;
    }
    
    /**
     * Begin database transaction
     */
    public function beginTransaction() {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
            $this->security->logActivity('TRANSACTION_STARTED');
        }
        $this->transactionLevel++;
    }
    
    /**
     * Commit database transaction
     */
    public function commit() {
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->pdo->commit();
            $this->security->logActivity('TRANSACTION_COMMITTED');
        }
    }
    
    /**
     * Rollback database transaction
     */
    public function rollback() {
        if ($this->transactionLevel > 0) {
            $this->pdo->rollback();
            $this->transactionLevel = 0;
            $this->security->logActivity('TRANSACTION_ROLLBACK', [], 'WARNING');
        }
    }
    
    /**
     * Execute multiple queries in a transaction
     */
    public function transaction($callback) {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * Check if record exists
     */
    public function exists($table, $where, $whereParams = []) {
        $this->validateTableName($table);
        
        $sql = "SELECT 1 FROM `{$table}` WHERE {$where} LIMIT 1";
        $result = $this->fetchValue($sql, $whereParams);
        
        return $result !== false;
    }
    
    /**
     * Count records
     */
    public function count($table, $where = '1=1', $whereParams = []) {
        $this->validateTableName($table);
        
        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        return (int) $this->fetchValue($sql, $whereParams);
    }
    
    /**
     * Get paginated results
     */
    public function paginate($table, $where = '1=1', $whereParams = [], $page = 1, $limit = 20, $orderBy = 'id DESC') {
        $this->validateTableName($table);
        
        $offset = ($page - 1) * $limit;
        
        // Get total count
        $totalCount = $this->count($table, $where, $whereParams);
        
        // Get paginated data
        $sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";
        $data = $this->fetchAll($sql, $whereParams);
        
        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $limit),
                'has_next' => $page < ceil($totalCount / $limit),
                'has_prev' => $page > 1
            ]
        ];
    }
    
    /**
     * Execute database schema migrations
     */
    public function migrate($migrations) {
        foreach ($migrations as $migration) {
            try {
                $this->pdo->exec($migration);
                $this->security->logActivity('DATABASE_MIGRATION', [
                    'migration' => substr($migration, 0, 100) . '...'
                ]);
            } catch (PDOException $e) {
                $this->security->logActivity('DATABASE_MIGRATION_FAILED', [
                    'error' => $this->security->sanitizeErrorMessage($e->getMessage())
                ], 'ERROR');
                throw new Exception("Migration failed: " . $this->security->sanitizeErrorMessage($e->getMessage()));
            }
        }
    }
    
    /**
     * Backup database tables
     */
    public function backup($tables = []) {
        if (empty($tables)) {
            $tables = $this->getAllTables();
        }
        
        $backup = "-- WMS Database Backup\n";
        $backup .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($tables as $table) {
            $this->validateTableName($table);
            
            // Get table structure
            $createTable = $this->fetchRow("SHOW CREATE TABLE `{$table}`");
            $backup .= "-- Structure for table `{$table}`\n";
            $backup .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $backup .= $createTable['Create Table'] . ";\n\n";
            
            // Get table data
            $rows = $this->fetchAll("SELECT * FROM `{$table}`");
            if (!empty($rows)) {
                $backup .= "-- Data for table `{$table}`\n";
                $backup .= "INSERT INTO `{$table}` VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $escapedValues = array_map(function($value) {
                        return $value === null ? 'NULL' : $this->pdo->quote($value);
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escapedValues) . ')';
                }
                
                $backup .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $this->security->logActivity('DATABASE_BACKUP', [
            'tables' => $tables,
            'size' => strlen($backup)
        ]);
        
        return $backup;
    }
    
    /**
     * Get all table names
     */
    private function getAllTables() {
        $tables = $this->fetchAll("SHOW TABLES");
        return array_map('current', $tables);
    }
    
    /**
     * Validate table name to prevent SQL injection
     */
    private function validateTableName($table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
    }
    
    /**
     * Sanitize data array
     */
    private function sanitizeData($data) {
        $sanitized = [];
        foreach ($data as $key => $value) {
            // Validate column name
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                throw new InvalidArgumentException("Invalid column name: {$key}");
            }
            $sanitized[$key] = $value;
        }
        return $sanitized;
    }
    
    /**
     * Get query type from SQL
     */
    private function getQueryType($sql) {
        $sql = trim(strtoupper($sql));
        $words = explode(' ', $sql);
        return $words[0] ?? 'UNKNOWN';
    }
    
    /**
     * Close database connection
     */
    public function close() {
        $this->pdo = null;
        $this->security->logActivity('DATABASE_DISCONNECTED');
    }
    
    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization of the instance
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize a singleton.");
    }
}

// Initialize database connection
function getDB() {
    return SecureDatabase::getInstance();
}

// Database initialization script
function initializeDatabase() {
    $db = getDB();
    
    // Create tables if they don't exist
    $migrations = [
        // Users table
        "CREATE TABLE IF NOT EXISTS `users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL UNIQUE,
            `email` varchar(100) NOT NULL UNIQUE,
            `password_hash` varchar(255) NOT NULL,
            `role` enum('viewer','operator','supervisor','manager','admin') NOT NULL DEFAULT 'operator',
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `last_login` timestamp NULL,
            `failed_login_attempts` int(11) DEFAULT 0,
            `locked_until` timestamp NULL,
            `password_changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` timestamp NULL,
            `deleted_by` int(11) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_username` (`username`),
            KEY `idx_email` (`email`),
            KEY `idx_role` (`role`),
            KEY `idx_active` (`is_active`),
            KEY `idx_deleted` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Activity logs table
        "CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NULL,
            `action` varchar(100) NOT NULL,
            `table_name` varchar(50) NULL,
            `record_id` int(11) NULL,
            `old_values` json NULL,
            `new_values` json NULL,
            `ip_address` varchar(45) NOT NULL,
            `user_agent` text NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_action` (`action`),
            KEY `idx_table_record` (`table_name`, `record_id`),
            KEY `idx_created_at` (`created_at`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    
    try {
        $db->migrate($migrations);
        return true;
    } catch (Exception $e) {
        error_log("Database initialization failed: " . $e->getMessage());
        return false;
    }
}

// Initialize database on first load
if (!defined('DB_INITIALIZED')) {
    define('DB_INITIALIZED', true);
    initializeDatabase();
}
?>