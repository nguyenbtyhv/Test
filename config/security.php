<?php
class Security {
    
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    public static function checkRateLimit($user_id, $action, $limit = 10, $timeframe = 300) {
        $key = "rate_limit_{$action}_{$user_id}";
        $current_time = time();
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        
        // Remove old timestamps
        $_SESSION[$key] = array_filter($_SESSION[$key], function($timestamp) use ($current_time, $timeframe) {
            return ($current_time - $timestamp) < $timeframe;
        });
        
        if (count($_SESSION[$key]) >= $limit) {
            return false;
        }
        
        $_SESSION[$key][] = $current_time;
        return true;
    }
    
    public static function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
    
    public static function validateImageUrl($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $allowed_domains = ['imgur.com', 'imgbb.com', 'i.imgur.com', 'cdn.discordapp.com'];
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        
        foreach ($allowed_domains as $domain) {
            if (strpos($host, $domain) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    public static function preventSQLInjection($db, $data) {
        if (is_array($data)) {
            return array_map(function($item) use ($db) {
                return $db->escape($item);
            }, $data);
        }
        return $db->escape($data);
    }
    
    public static function logSecurityEvent($event, $details = []) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'event' => $event,
            'details' => $details
        ];
        
        error_log("SECURITY: " . json_encode($log_entry));
    }
    
    public static function checkPermission($user, $required_roles) {
        if (!$user) return false;
        
        if (is_string($required_roles)) {
            $required_roles = [$required_roles];
        }
        
        return in_array($user['role'], $required_roles);
    }
}
?>