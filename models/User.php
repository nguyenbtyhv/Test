<?php
require_once 'config/database.php';
require_once 'config/security.php';

class User {
    private $db;
    private $connection;
    
    public function __construct() {
        $this->db = new Database();
        $this->connection = $this->db->getConnection();
    }
    
    public function register($username, $email, $password) {
        try {
            // Validate input
            if (strlen($username) < 3 || strlen($username) > 20) {
                throw new Exception('Tên đăng nhập phải từ 3-20 ký tự');
            }
            
            if (!Security::validateEmail($email)) {
                throw new Exception('Email không hợp lệ');
            }
            
            if (strlen($password) < 6) {
                throw new Exception('Mật khẩu phải có ít nhất 6 ký tự');
            }
            
            // Check if username or email exists
            $stmt = $this->connection->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                throw new Exception('Tên đăng nhập hoặc email đã tồn tại');
            }
            
            // Hash password and create user
            $hashed_password = Security::hashPassword($password);
            $activation_token = Security::generateRandomString();
            
            $stmt = $this->connection->prepare("
                INSERT INTO users (username, email, password, activation_token, realm, realm_stage, coins, created_at) 
                VALUES (?, ?, ?, ?, 'Luyện Khí', 1, 100, NOW())
            ");
            
            $stmt->bind_param("ssss", $username, $email, $hashed_password, $activation_token);
            
            if ($stmt->execute()) {
                Security::logSecurityEvent('user_registered', ['username' => $username, 'email' => $email]);
                return ['success' => true, 'message' => 'Đăng ký thành công'];
            } else {
                throw new Exception('Lỗi khi tạo tài khoản');
            }
            
        } catch (Exception $e) {
            Security::logSecurityEvent('registration_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function login($username, $password) {
        try {
            // Rate limiting
            if (!Security::checkRateLimit(0, 'login_attempt', 5, 300)) {
                throw new Exception('Quá nhiều lần đăng nhập thất bại. Vui lòng thử lại sau 5 phút.');
            }
            
            $stmt = $this->connection->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->bind_param("ss", $username, $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception('Tên đăng nhập hoặc mật khẩu không đúng');
            }
            
            $user = $result->fetch_assoc();
            
            if (!Security::verifyPassword($password, $user['password'])) {
                Security::logSecurityEvent('login_failed', ['username' => $username, 'reason' => 'wrong_password']);
                throw new Exception('Tên đăng nhập hoặc mật khẩu không đúng');
            }
            
            if ($user['status'] === 'banned') {
                throw new Exception('Tài khoản đã bị khóa');
            }
            
            // Update last login
            $stmt = $this->connection->prepare("UPDATE users SET last_login = NOW(), login_ip = ? WHERE id = ?");
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt->bind_param("si", $ip, $user['id']);
            $stmt->execute();
            
            Security::logSecurityEvent('user_login', ['user_id' => $user['id'], 'username' => $username]);
            
            return ['success' => true, 'user' => $user];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getUserById($id) {
        $stmt = $this->connection->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    public function updateProfile($user_id, $data) {
        try {
            $allowed_fields = ['email', 'avatar', 'bio'];
            $update_fields = [];
            $params = [];
            $types = '';
            
            foreach ($data as $field => $value) {
                if (in_array($field, $allowed_fields)) {
                    if ($field === 'email' && !Security::validateEmail($value)) {
                        throw new Exception('Email không hợp lệ');
                    }
                    
                    $update_fields[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
            }
            
            if (empty($update_fields)) {
                throw new Exception('Không có dữ liệu để cập nhật');
            }
            
            $sql = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ?";
            $params[] = $user_id;
            $types .= 'i';
            
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Cập nhật thông tin thành công'];
            } else {
                throw new Exception('Lỗi khi cập nhật thông tin');
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function changePassword($user_id, $old_password, $new_password) {
        try {
            $user = $this->getUserById($user_id);
            
            if (!Security::verifyPassword($old_password, $user['password'])) {
                throw new Exception('Mật khẩu cũ không đúng');
            }
            
            if (strlen($new_password) < 6) {
                throw new Exception('Mật khẩu mới phải có ít nhất 6 ký tự');
            }
            
            $hashed_password = Security::hashPassword($new_password);
            
            $stmt = $this->connection->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($stmt->execute()) {
                Security::logSecurityEvent('password_changed', ['user_id' => $user_id]);
                return ['success' => true, 'message' => 'Đổi mật khẩu thành công'];
            } else {
                throw new Exception('Lỗi khi đổi mật khẩu');
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function addCoins($user_id, $amount, $reason = '') {
        $stmt = $this->connection->prepare("
            UPDATE users SET coins = coins + ? WHERE id = ?
        ");
        $stmt->bind_param("ii", $amount, $user_id);
        
        if ($stmt->execute()) {
            // Log transaction
            $stmt = $this->connection->prepare("
                INSERT INTO coin_transactions (user_id, amount, reason, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param("iis", $user_id, $amount, $reason);
            $stmt->execute();
            
            return true;
        }
        
        return false;
    }
    
    public function deductCoins($user_id, $amount, $reason = '') {
        // Check if user has enough coins
        $user = $this->getUserById($user_id);
        if (($user['coins'] ?? 0) < $amount) {
            return false;
        }
        
        return $this->addCoins($user_id, -$amount, $reason);
    }
    
    public function levelUp($user_id) {
        $realms = [
            'Luyện Khí', 'Trúc Cơ', 'Kết Đan', 'Nguyên Anh', 'Hoá Thần',
            'Luyện Hư', 'Hợp Thể', 'Đại Thừa', 'Độ Kiếp', 'Tiên Nhân'
        ];
        
        $user = $this->getUserById($user_id);
        $realm_index = array_search($user['realm'], $realms);
        $stage = (int)$user['realm_stage'];
        
        // Calculate required chapters read
        $required = ($realm_index + 1) * 10 + ($stage - 1) * ($realm_index + 1) * 3;
        
        // Get chapters read count
        $stmt = $this->connection->prepare("SELECT COUNT(*) as count FROM read_history WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $chapters_read = $stmt->get_result()->fetch_assoc()['count'];
        
        if ($chapters_read >= $required) {
            if ($stage < 10) {
                // Advance stage
                $stmt = $this->connection->prepare("UPDATE users SET realm_stage = realm_stage + 1 WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
            } else if ($realm_index < count($realms) - 1) {
                // Advance realm
                $next_realm = $realms[$realm_index + 1];
                $stmt = $this->connection->prepare("UPDATE users SET realm = ?, realm_stage = 1 WHERE id = ?");
                $stmt->bind_param("si", $next_realm, $user_id);
                $stmt->execute();
                
                // Give bonus coins for realm advancement
                $this->addCoins($user_id, 500, 'Realm advancement bonus');
            }
        }
    }
}
?>