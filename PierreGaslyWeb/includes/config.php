<?php
/**
 * PIERRE GASLY - WEB ADMIN CONFIGURATION
 * Uses Supabase REST API
 * Version: 2.1 - Products Page Fixed
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_PATH',    __DIR__ . '/..');
define('SITE_NAME',    'Pierre Gasly Admin');
define('APP_NAME',     'Pierre Gasly Gas Delivery');
define('APP_VERSION',  '2.1.0');
define('BASE_URL',     rtrim(getenv('APP_URL') ?: 'http://localhost/', '/') . '/');

// --- Supabase configuration ---
// IMPORTANT: Never commit your Supabase keys to GitHub.
// Set these as environment variables in Railway/hosting OR in a local-only config file.
// Supported env vars: SUPABASE_URL, SUPABASE_ANON_KEY, SUPABASE_SERVICE_KEY

// Optional: local overrides (DO NOT COMMIT). Create: includes/config.local.php
$__localCfg = __DIR__ . '/config.local.php';
if (is_file($__localCfg)) {
    require_once $__localCfg;
}

$__supabaseUrl = getenv('SUPABASE_URL') ?: (defined('SUPABASE_URL') ? SUPABASE_URL : null);
$__supabaseAnon = getenv('SUPABASE_ANON_KEY') ?: (defined('SUPABASE_ANON_KEY') ? SUPABASE_ANON_KEY : null);
$__supabaseService = getenv('SUPABASE_SERVICE_KEY') ?: (defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : null);

if (!$__supabaseUrl || !$__supabaseAnon || !$__supabaseService) {
    http_response_code(500);
    die('Missing Supabase configuration. Please set SUPABASE_URL, SUPABASE_ANON_KEY, and SUPABASE_SERVICE_KEY as environment variables.');
}

define('SUPABASE_URL', $__supabaseUrl);
define('SUPABASE_ANON_KEY', $__supabaseAnon);
define('SUPABASE_SERVICE_KEY', $__supabaseService);
unset($__localCfg, $__supabaseUrl, $__supabaseAnon, $__supabaseService);

define('UPLOAD_PATH',  BASE_PATH . '/uploads/');
define('UPLOAD_URL',   BASE_URL . 'uploads/');


// File upload type constants
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/jpg', 'image/png', 'image/gif']);
define('ALLOWED_DOC_TYPES', ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf']);

class Database {
    private static ?Database $instance = null;
    private $supabaseUrl;
    private $supabaseKey;
    private $lastInsertId = null;
    private $lastError = null;
    private $debug = false; // Set to true for debugging
    
    private function __construct() {
        $this->supabaseUrl = SUPABASE_URL;
        $this->supabaseKey = SUPABASE_SERVICE_KEY;
        
        // Enable debug mode if URL parameter is present
        if (isset($_GET['debug_db']) && $_GET['debug_db'] === '1') {
            $this->debug = true;
        }
    }
    
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function lastInsertId() {
        return $this->lastInsertId;
    }
    public function getLastError() {
        return $this->lastError;
    }

    
    private function log($message, $data = null) {
        if ($this->debug) {
            error_log("DB DEBUG: $message");
            if ($data !== null) {
                error_log("  Data: " . json_encode($data));
            }
        }
    }
    
    public function select($table, $filters = [], $select = '*') {
        $endpoint = $this->supabaseUrl . '/rest/v1/' . $table . '?select=' . $select;
        
        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                if (is_bool($value)) {
                    $endpoint .= '&' . $key . '=eq.' . ($value ? 'true' : 'false');
                } elseif (is_int($value) && ($key === 'is_active' || strpos($key, 'is_') === 0)) {
                    // Handle is_active and other is_* fields that might be stored as boolean
                    $endpoint .= '&' . $key . '=eq.' . ($value ? 'true' : 'false');
                } else {
                    $endpoint .= '&' . $key . '=eq.' . urlencode($value);
                }
            }
        }
        
        return $this->executeCurl($endpoint, 'GET');
    }
    
    public function insert($table, $data) {
        $this->log("Insert called", ['table' => $table, 'data' => $data]);
        
        $endpoint = $this->supabaseUrl . '/rest/v1/' . $table;
        $result = $this->executeCurl($endpoint, 'POST', $data);
        
        $this->log("Insert result", ['result' => $result]);
        
        // Track last inserted ID
        if (!empty($result) && isset($result[0])) {
            // Try to get the primary key - common patterns
            $idKeys = [$table . '_id', 'id', 'user_id', 'product_id', 'order_id', 'sale_id'];
            foreach ($idKeys as $key) {
                if (isset($result[0][$key])) {
                    $this->lastInsertId = $result[0][$key];
                    $this->log("Last insert ID set", ['id' => $this->lastInsertId]);
                    break;
                }
            }
        }
        
        return $result;
    }
    
    public function update($table, $data, $filters) {
        $endpoint = $this->supabaseUrl . '/rest/v1/' . $table . '?';
        
        $filterParts = [];
        foreach ($filters as $key => $value) {
            $filterParts[] = $key . '=eq.' . urlencode($value);
        }
        $endpoint .= implode('&', $filterParts);
        
        return $this->executeCurl($endpoint, 'PATCH', $data);
    }
    
    public function delete($table, $filters) {
        $endpoint = $this->supabaseUrl . '/rest/v1/' . $table . '?';
        
        $filterParts = [];
        foreach ($filters as $key => $value) {
            $filterParts[] = $key . '=eq.' . urlencode($value);
        }
        $endpoint .= implode('&', $filterParts);
        
        return $this->executeCurl($endpoint, 'DELETE');
    }
    
    public function fetchOne($query, $params = []) {
        // SELECT * FROM table WHERE column = ?
        if (preg_match('/SELECT \* FROM (\w+) WHERE (\w+) = \?/', $query, $matches)) {
            $table = $matches[1];
            $column = $matches[2];
            $value = $params[0] ?? null;
            
            if ($value !== null) {
                $results = $this->select($table, [$column => $value]);
                return $results[0] ?? null;
            }
        }
        
        // SELECT specific_column FROM table WHERE column = ? (e.g., email check)
        if (preg_match('/SELECT (\w+) FROM (\w+) WHERE (\w+) = \?/', $query, $matches)) {
            $selectColumn = $matches[1];
            $table = $matches[2];
            $whereColumn = $matches[3];
            $value = $params[0] ?? null;
            
            if ($value !== null) {
                $results = $this->select($table, [$whereColumn => $value]);
                return $results[0] ?? null;
            }
        }
        
        // Login query with role filter
        if (preg_match('/SELECT \* FROM users WHERE email = \?/', $query)) {
            $results = $this->select('users', ['email' => $params[0]]);
            if (!empty($results)) {
                foreach ($results as $user) {
                    if (in_array($user['role'], ['master_admin', 'sub_admin'])) {
                        return $user;
                    }
                }
            }
            return null;
        }
        
        // COUNT queries with 'count' alias
        if (preg_match('/SELECT COUNT\(\*\) as count FROM (\w+)/', $query, $matches)) {
            $table = $matches[1];
            $results = $this->select($table);
            
            // Apply filters
            if (strpos($query, 'is_active = 1') !== false) {
                $results = array_filter($results, fn($r) => ($r['is_active'] ?? true) === true);
            }
            if (strpos($query, 'is_active = 0') !== false) {
                $results = array_filter($results, fn($r) => ($r['is_active'] ?? true) === false);
            }
            if (strpos($query, "role = 'customer'") !== false) {
                $results = array_filter($results, fn($r) => $r['role'] === 'customer');
            }
            if (strpos($query, "role = 'rider'") !== false) {
                $results = array_filter($results, fn($r) => $r['role'] === 'rider');
            }
            if (strpos($query, "role = 'sub_admin'") !== false) {
                $results = array_filter($results, fn($r) => $r['role'] === 'sub_admin');
            }
            if (strpos($query, "status = 'active'") !== false) {
                $results = array_filter($results, fn($r) => $r['status'] === 'active');
            }
            if (strpos($query, "order_status = 'pending'") !== false) {
                $results = array_filter($results, fn($r) => $r['order_status'] === 'pending');
            }
            
            return ['count' => count($results), 'total' => count($results)];
        }
        
        // COUNT queries with 'total' alias (dashboard compatibility)
        if (preg_match('/SELECT COUNT\(\*\) as total FROM (\w+)/', $query, $matches)) {
            $table = $matches[1];
            $results = $this->select($table);
            
            // Apply filters (same as above)
            if (strpos($query, 'is_active = 1') !== false) {
                $results = array_filter($results, fn($r) => ($r['is_active'] ?? true) === true);
            }
            if (strpos($query, "role = 'customer'") !== false) {
                $results = array_filter($results, fn($r) => $r['role'] === 'customer');
            }
            if (strpos($query, "role = 'rider'") !== false) {
                $results = array_filter($results, fn($r) => $r['role'] === 'rider');
            }
            if (strpos($query, "role = 'sub_admin'") !== false) {
                $results = array_filter($results, fn($r) => $r['role'] === 'sub_admin');
            }
            if (strpos($query, "status = 'active'") !== false) {
                $results = array_filter($results, fn($r) => $r['status'] === 'active');
            }
            if (strpos($query, "order_status = 'pending'") !== false) {
                $results = array_filter($results, fn($r) => $r['order_status'] === 'pending');
            }
            
            return ['total' => count($results), 'count' => count($results)];
        }
        
        // SUM queries for sales
        if (preg_match('/SELECT COALESCE\(SUM\(sale_amount\), 0\) as (\w+) FROM sales/', $query, $matches)) {
            $results = $this->select('sales');
            $total = 0;
            
            $today = date('Y-m-d');
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            foreach ($results as $sale) {
                $saleDate = $sale['sale_date'] ?? '';
                
                if (strpos($query, 'CURDATE()') !== false || strpos($query, 'CURRENT_DATE') !== false) {
                    if (substr($saleDate, 0, 10) === $today) {
                        $total += floatval($sale['sale_amount'] ?? 0);
                    }
                } elseif (strpos($query, 'MONTH(CURDATE())') !== false || strpos($query, 'EXTRACT(MONTH') !== false) {
                    if (substr($saleDate, 5, 2) === $currentMonth && substr($saleDate, 0, 4) === $currentYear) {
                        $total += floatval($sale['sale_amount'] ?? 0);
                    }
                }
            }
            
            return [$matches[1] => $total];
        }
        
        // AVG queries (e.g., average rating)
        if (preg_match('/SELECT COALESCE\(AVG\((\w+)\), 0\) as (\w+) FROM (\w+)/', $query, $matches)) {
            $column = $matches[1];
            $alias = $matches[2];
            $table = $matches[3];
            
            $results = $this->select($table);
            
            if (empty($results)) {
                return [$alias => 0];
            }
            
            $sum = 0;
            $count = 0;
            
            foreach ($results as $row) {
                if (isset($row[$column])) {
                    $sum += floatval($row[$column]);
                    $count++;
                }
            }
            
            $avg = $count > 0 ? $sum / $count : 0;
            return [$alias => $avg];
        }
        
        // Complex aggregate queries (COUNT, SUM, AVG, MAX with JOIN)
        if (preg_match('/SELECT\s+.*COUNT.*FROM\s+(\w+)\s+\w+\s+JOIN/i', $query)) {
            // For complex queries with joins and aggregates, use a simplified approach
            // This handles sales stats queries
            if (strpos($query, 'FROM sales') !== false) {
                $sales = $this->select('sales');
                $orders = $this->select('orders');
                
                // Create order map
                $orderMap = [];
                foreach ($orders as $order) {
                    $orderMap[$order['order_id']] = $order;
                }
                
                // Filter sales based on order status and other conditions
                $filteredSales = [];
                foreach ($sales as $sale) {
                    $order = $orderMap[$sale['order_id']] ?? null;
                    if ($order && $order['order_status'] === 'delivered') {
                        $filteredSales[] = $sale;
                    }
                }
                
                // Calculate stats
                $total_sales = count($filteredSales);
                $total_revenue = 0;
                $amounts = [];
                
                foreach ($filteredSales as $sale) {
                    $amount = floatval($sale['sale_amount'] ?? 0);
                    $total_revenue += $amount;
                    $amounts[] = $amount;
                }
                
                $average_sale = $total_sales > 0 ? $total_revenue / $total_sales : 0;
                $highest_sale = !empty($amounts) ? max($amounts) : 0;
                
                return [
                    'total_sales' => $total_sales,
                    'total_revenue' => $total_revenue,
                    'average_sale' => $average_sale,
                    'highest_sale' => $highest_sale
                ];
            }
        }
        
        return ['total' => 0, 'count' => 0, 'total_today' => 0, 'total_month' => 0];
    }
    
    public function fetchAll($query, $params = []) {
        // Products with categories and brands JOIN
        if (strpos($query, 'FROM products p') !== false && strpos($query, 'JOIN categories') !== false) {
            $isActive = $params[0] ?? 1;
            $products = $this->select('products', ['is_active' => (bool)$isActive]);
            
            foreach ($products as &$product) {
                // Get category
                if (!empty($product['category_id'])) {
                    $category = $this->select('categories', ['category_id' => $product['category_id']]);
                    $product['category_name'] = $category[0]['category_name'] ?? 'Unknown';
                }
                
                // Get brand
                if (!empty($product['brand_id'])) {
                    $brand = $this->select('brands', ['brand_id' => $product['brand_id']]);
                    $product['brand_name'] = $brand[0]['brand_name'] ?? 'Unknown';
                }
            }
            
            // Sort by brand, size, name
            usort($products, function($a, $b) {
                if ($a['brand_name'] !== $b['brand_name']) {
                    return strcmp($a['brand_name'], $b['brand_name']);
                }
                if ($a['size_kg'] !== $b['size_kg']) {
                    return $a['size_kg'] - $b['size_kg'];
                }
                return strcmp($a['product_name'], $b['product_name']);
            });
            
            return $products;
        }
        
        // Orders with JOIN (dashboard)
        if (strpos($query, 'FROM orders o') !== false && strpos($query, 'JOIN') !== false) {
            $orders = $this->select('orders');
            
            usort($orders, function($a, $b) {
                return strtotime($b['ordered_at'] ?? '0') - strtotime($a['ordered_at'] ?? '0');
            });
            $orders = array_slice($orders, 0, 5);
            
            foreach ($orders as &$order) {
                $customer = $this->select('users', ['user_id' => $order['customer_id']]);
                $order['customer_name'] = $customer[0]['full_name'] ?? 'Unknown';
                
                $product = $this->select('products', ['product_id' => $order['product_id']]);
                $order['product_name'] = $product[0]['product_name'] ?? 'Unknown';
                $order['size_kg'] = $product[0]['size_kg'] ?? 0;
                
                if (!empty($product[0]['brand_id'])) {
                    $brand = $this->select('brands', ['brand_id' => $product[0]['brand_id']]);
                    $order['brand_name'] = $brand[0]['brand_name'] ?? 'Unknown';
                }
                
                if (!empty($order['rider_id'])) {
                    $rider = $this->select('users', ['user_id' => $order['rider_id']]);
                    $order['rider_name'] = $rider[0]['full_name'] ?? null;
                }
            }
            
            return $orders;
        }
        
        // Low stock products
        if (strpos($query, 'FROM products p') !== false && strpos($query, 'stock_quantity <= ') !== false) {
            $products = $this->select('products');
            
            $lowStock = array_filter($products, function($p) {
                return ($p['stock_quantity'] ?? 0) <= ($p['minimum_stock'] ?? 10) && ($p['is_active'] ?? true);
            });
            
            usort($lowStock, function($a, $b) {
                return ($a['stock_quantity'] ?? 0) - ($b['stock_quantity'] ?? 0);
            });
            
            $lowStock = array_slice($lowStock, 0, 5);
            
            foreach ($lowStock as &$product) {
                if (!empty($product['brand_id'])) {
                    $brand = $this->select('brands', ['brand_id' => $product['brand_id']]);
                    $product['brand_name'] = $brand[0]['brand_name'] ?? 'Unknown';
                }
            }
            
            return $lowStock;
        }
        
        // Simple SELECT * FROM table
        if (preg_match('/SELECT \* FROM (\w+)/', $query, $matches)) {
            $table = $matches[1];
            return $this->select($table);
        }
        
        return [];
    }
    
    public function query($query, $params = []) {
        $this->log("Query called", ['query' => $query, 'params' => $params]);
        
        // Convert MySQL functions to PostgreSQL equivalents
        $query = $this->convertMySQLToPostgreSQL($query);
        
        // UPDATE last_login with NOW()
        if (preg_match('/UPDATE users SET last_login = NOW\(\) WHERE user_id = \?/', $query)) {
            $result = $this->update('users', [
                'last_login' => date('Y-m-d H:i:s')
            ], ['user_id' => $params[0]]);
            return is_array($result); // Return true if update succeeded
        }
        
        // INSERT queries with CURDATE() or NOW()
        if (preg_match('/INSERT\s+INTO\s+(\w+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/is', $query, $matches)) {
            $table = $matches[1];
            $columnsStr = $matches[2];
            $valuesStr = $matches[3];
            
            // Parse columns
            $columns = array_map('trim', explode(',', $columnsStr));
            
            // Parse values - split by comma but respect quoted strings
            $values = [];
            $current = '';
            $inQuotes = false;
            for ($i = 0; $i < strlen($valuesStr); $i++) {
                $char = $valuesStr[$i];
                if ($char === "'" && ($i === 0 || $valuesStr[$i-1] !== '\\')) {
                    $inQuotes = !$inQuotes;
                    $current .= $char;
                } elseif ($char === ',' && !$inQuotes) {
                    $values[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            if ($current !== '') {
                $values[] = trim($current);
            }
            
            // Map values to columns
            $data = [];
            $paramIndex = 0;
            
            for ($i = 0; $i < count($columns); $i++) {
                $column = $columns[$i];
                $valueToken = $values[$i] ?? '?';
                
                if ($valueToken === '?') {
                    // This is a placeholder - get from params
                    $value = $params[$paramIndex++] ?? null;
                } elseif (stripos($valueToken, 'CURDATE()') !== false || stripos($valueToken, 'CURRENT_DATE') !== false) {
                    $value = date('Y-m-d');
                } elseif (stripos($valueToken, 'NOW()') !== false || stripos($valueToken, 'CURRENT_TIMESTAMP') !== false) {
                    $value = date('Y-m-d H:i:s');
                } else {
                    // Literal value - remove quotes if present
                    $value = trim($valueToken, "'\"");
                }
                
                // Convert boolean-like fields
                if (($column === 'is_active' || strpos($column, 'is_') === 0) && is_numeric($value)) {
                    $value = (bool)((int)$value);
                }
                
                $data[$column] = $value;
            }
            
            $result = $this->insert($table, $data);
            return !empty($result);
        }
        
        // UPDATE queries with dynamic timestamp fields
        if (preg_match('/UPDATE (\w+) SET (.+) WHERE (.+)/', $query, $matches)) {
            $table = $matches[1];
            $setClause = $matches[2];
            $whereClause = $matches[3];
            
            // Parse SET clause
            $data = [];
            $paramIndex = 0;
            
            // Handle NOW() in SET clause
            $setClause = preg_replace_callback('/(\w+)\s*=\s*NOW\(\)/', function($m) use (&$data) {
                $data[$m[1]] = date('Y-m-d H:i:s');
                return '';
            }, $setClause);
            
            // Handle CURRENT_TIMESTAMP
            $setClause = preg_replace_callback('/(\w+)\s*=\s*CURRENT_TIMESTAMP/', function($m) use (&$data) {
                $data[$m[1]] = date('Y-m-d H:i:s');
                return '';
            }, $setClause);
            
            // Parse remaining SET parts with placeholders
            $setParts = array_filter(array_map('trim', explode(',', $setClause)));
            foreach ($setParts as $part) {
                if (preg_match('/(\w+)\s*=\s*\?/', $part, $colMatch)) {
                    $columnName = $colMatch[1];
                    $value = $params[$paramIndex++];
                    
                    // Convert is_active and other boolean-like fields
                    if (($columnName === 'is_active' || strpos($columnName, 'is_') === 0) && is_numeric($value)) {
                        $value = (bool)$value;
                    }
                    
                    $data[$columnName] = $value;
                }
            }
            
            // Parse WHERE clause
            $filters = [];
            if (preg_match('/(\w+)\s*=\s*\?/', $whereClause, $whereMatch)) {
                $filters[$whereMatch[1]] = $params[$paramIndex];
            }
            
            $result = $this->update($table, $data, $filters);
            return is_array($result); // Return true if update succeeded
        }
        
        // DELETE queries
        if (preg_match('/DELETE FROM (\w+) WHERE (\w+) = \?/', $query, $matches)) {
            $table = $matches[1];
            $column = $matches[2];
            
            $result = $this->delete($table, [$column => $params[0]]);
            return true;
        }
        
        $this->lastError = 'Unsupported query pattern: ' . $query;
        $this->log('Unsupported query', ['query' => $query, 'params' => $params]);
        return false;
    }
    
    /**
     * Convert MySQL-specific functions to PostgreSQL equivalents
     */
    private function convertMySQLToPostgreSQL($query) {
        // Convert NOW() to CURRENT_TIMESTAMP
        $query = str_replace('NOW()', 'CURRENT_TIMESTAMP', $query);
        
        // Convert CURDATE() to CURRENT_DATE
        $query = str_replace('CURDATE()', 'CURRENT_DATE', $query);
        
        // Convert DATE_SUB to PostgreSQL interval syntax
        $query = preg_replace(
            '/DATE_SUB\(NOW\(\), INTERVAL (\d+) HOUR\)/',
            "CURRENT_TIMESTAMP - INTERVAL '$1 hours'",
            $query
        );
        
        // Convert YEARWEEK to PostgreSQL equivalent
        $query = preg_replace(
            '/YEARWEEK\(([^)]+)\)/',
            "EXTRACT(YEAR FROM $1) || EXTRACT(WEEK FROM $1)",
            $query
        );
        
        // Note: MONTH() and YEAR() are kept as-is since they'll be handled in fetchAll/fetchOne
        
        return $query;
    }
    
    private function executeCurl($url, $method, $data = null) {
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->supabaseKey,
            'Authorization: Bearer ' . $this->supabaseKey,
            'Prefer: return=representation'
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data !== null && in_array($method, ['POST', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            $this->lastError = "HTTP $httpCode - $response";

// Friendly hint for common Postgres duplicate primary key issue (sequence not in sync)
$decodedErr = json_decode($response, true);
if (is_array($decodedErr) && ($decodedErr['code'] ?? '') === '23505' && strpos($decodedErr['message'] ?? '', 'duplicate key value') !== false) {
    $details = $decodedErr['details'] ?? '';
    if (preg_match('/Key \(([^)]+)\)=\(([^)]+)\) already exists\./', $details, $mm)) {
        $col = $mm[1];
        $val = $mm[2];
        $seqHint = "Hint: Your Postgres ID sequence may be out of sync (it is trying to reuse $col=$val). In Supabase SQL Editor, run:\n" .
                   "SELECT setval(pg_get_serial_sequence('<TABLE_NAME>', '$col'), (SELECT COALESCE(MAX($col),0) FROM <TABLE_NAME>) + 1, false);";
        $this->lastError .= "\n" . $seqHint;
    }
}

            error_log("Supabase Error ($method $url): HTTP $httpCode - $response");
            if ($data !== null) {
                error_log("Data sent: " . json_encode($data));
            }
            return [];
        }
        
        $this->lastError = null;
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->lastError = "JSON decode error: " . json_last_error_msg() . " | Raw: " . $response;
            error_log($this->lastError);
            return [];
        }
        
        return $decoded ?: [];
    }
}

function getDB(): Database {
    return Database::getInstance();
}

function generateCSRFToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

function isAdmin(): bool {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['master_admin', 'sub_admin']);
}

function isMasterAdmin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'master_admin';
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . 'dashboard.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    $users = $db->select('users', ['user_id' => $_SESSION['user_id']]);
    return $users[0] ?? null;
}

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function logActivity($action, $context, $details = '', $userId = null) {
    try {
        $db = getDB();
        
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
        }
        
        $db->insert('activity_logs', [
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

function formatCurrency($amount) {
    if ($amount === null || $amount === '') {
        $amount = 0;
    }
    return '₱' . number_format((float)$amount, 2);
}

function formatDate($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'M d, Y g:i A') {
    return date($format, strtotime($datetime));
}

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    
    return formatDate($datetime);
}

function generateOrderNumber() {
    return 'PG-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
}

function uploadFile($file, $directory = 'products', $allowedTypes = null) {
    if ($allowedTypes === null) {
        $allowedTypes = ALLOWED_IMAGE_TYPES;
    }
    
    $uploadDir = UPLOAD_PATH . $directory . '/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Validate file
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        return [
            'success' => false,
            'message' => 'Invalid file type. Allowed types: ' . implode(', ', $allowedTypes)
        ];
    }
    
    if ($file['size'] > $maxSize) {
        return [
            'success' => false,
            'message' => 'File too large. Maximum size is 5MB.'
        ];
    }
    
    $fileName = uniqid() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [
            'success' => true,
            'filename' => 'uploads/' . $directory . '/' . $fileName
        ];
    }
    
    return [
        'success' => false,
        'message' => 'Failed to upload file.'
    ];
}

function generateTempPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
    $password = '';
    $charsLength = strlen($chars);
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $charsLength - 1)];
    }
    
    // Ensure it has at least one letter and one number
    if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password)) {
        return generateTempPassword($length); // Recursively generate until valid
    }
    
    return $password;
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}
