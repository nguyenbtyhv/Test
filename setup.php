<?php
/**
 * MangaHub Setup Script
 * Tự động cài đặt và cấu hình website
 */

echo "🌟 MangaHub Setup Script\n";
echo "=======================\n\n";

// Check PHP version
if (version_compare(PHP_VERSION, '8.0.0') < 0) {
    die("❌ PHP 8.0 hoặc cao hơn được yêu cầu. Phiên bản hiện tại: " . PHP_VERSION . "\n");
}

echo "✅ PHP Version: " . PHP_VERSION . "\n";

// Check extensions
$required_extensions = ['mysqli', 'json', 'session'];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        die("❌ Extension '{$ext}' không được cài đặt.\n");
    }
    echo "✅ Extension '{$ext}' available\n";
}

echo "\n📝 Cấu hình Database\n";
echo "--------------------\n";

// Get database configuration
$host = readline("Database Host (localhost): ") ?: 'localhost';
$username = readline("Database Username (root): ") ?: 'root';
$password = readline("Database Password: ");
$database = readline("Database Name (manga_website): ") ?: 'manga_website';

echo "\n🔍 Đang kiểm tra kết nối database...\n";

try {
    $connection = new mysqli($host, $username, $password);
    
    if ($connection->connect_error) {
        throw new Exception("Không thể kết nối: " . $connection->connect_error);
    }
    
    echo "✅ Kết nối database thành công\n";
    
    // Create database
    $sql = "CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if ($connection->query($sql)) {
        echo "✅ Database '$database' đã được tạo\n";
    } else {
        throw new Exception("Không thể tạo database: " . $connection->error);
    }
    
    // Select database
    $connection->select_db($database);
    
    // Read and execute schema
    echo "📦 Đang tạo bảng...\n";
    
    $schema = file_get_contents('database/schema.sql');
    if (!$schema) {
        throw new Exception("Không thể đọc file schema.sql");
    }
    
    // Execute each statement
    $statements = explode(';', $schema);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            if (!$connection->query($statement)) {
                echo "⚠️ Warning: " . $connection->error . "\n";
            }
        }
    }
    
    echo "✅ Tạo bảng thành công\n";
    
    // Update database config
    echo "📝 Đang cập nhật cấu hình...\n";
    
    $config_content = "<?php
class Database {
    private \$host = '$host';
    private \$username = '$username';
    private \$password = '$password';
    private \$database = '$database';
    private \$connection;
    
    public function __construct() {
        \$this->connect();
    }
    
    private function connect() {
        try {
            \$this->connection = new mysqli(\$this->host, \$this->username, \$this->password, \$this->database);
            \$this->connection->set_charset('utf8mb4');
            
            if (\$this->connection->connect_error) {
                throw new Exception(\"Connection failed: \" . \$this->connection->connect_error);
            }
        } catch (Exception \$e) {
            error_log(\"Database connection error: \" . \$e->getMessage());
            die(\"Database connection failed. Please try again later.\");
        }
    }
    
    public function getConnection() {
        return \$this->connection;
    }
    
    public function prepare(\$query) {
        return \$this->connection->prepare(\$query);
    }
    
    public function escape(\$string) {
        return \$this->connection->real_escape_string(\$string);
    }
    
    public function close() {
        if (\$this->connection) {
            \$this->connection->close();
        }
    }
}
?>";
    
    if (file_put_contents('config/database.php', $config_content)) {
        echo "✅ Cấu hình database đã được cập nhật\n";
    } else {
        echo "⚠️ Không thể cập nhật file cấu hình\n";
    }
    
    // Create .htaccess if not exists
    if (!file_exists('.htaccess')) {
        echo "📝 Tạo file .htaccess...\n";
        $htaccess = "RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Security Headers
Header always set X-Frame-Options \"DENY\"
Header always set X-XSS-Protection \"1; mode=block\"
Header always set X-Content-Type-Options \"nosniff\"
Header always set Referrer-Policy \"strict-origin-when-cross-origin\"

# Cache Static Files
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg \"access plus 1 month\"
    ExpiresByType image/jpeg \"access plus 1 month\"
    ExpiresByType image/gif \"access plus 1 month\"
    ExpiresByType image/png \"access plus 1 month\"
    ExpiresByType text/css \"access plus 1 month\"
    ExpiresByType application/pdf \"access plus 1 month\"
    ExpiresByType text/javascript \"access plus 1 month\"
    ExpiresByType application/javascript \"access plus 1 month\"
</IfModule>";
        
        if (file_put_contents('.htaccess', $htaccess)) {
            echo "✅ File .htaccess đã được tạo\n";
        }
    }
    
    // Create admin user
    echo "\n👤 Cấu hình tài khoản Admin\n";
    echo "----------------------------\n";
    
    $admin_username = readline("Admin Username (admin): ") ?: 'admin';
    $admin_email = readline("Admin Email (admin@mangasite.com): ") ?: 'admin@mangasite.com';
    $admin_password = readline("Admin Password (password): ") ?: 'password';
    
    // Check if admin exists
    $stmt = $connection->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $admin_username, $admin_email);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        echo "⚠️ Tài khoản admin đã tồn tại\n";
    } else {
        $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
        $stmt = $connection->prepare("
            INSERT INTO users (username, email, password, role, coins, realm, realm_stage, created_at) 
            VALUES (?, ?, ?, 'admin', 10000, 'Tiên Nhân', 10, NOW())
        ");
        $stmt->bind_param("sss", $admin_username, $admin_email, $hashed_password);
        
        if ($stmt->execute()) {
            echo "✅ Tài khoản admin đã được tạo\n";
            echo "   Username: $admin_username\n";
            echo "   Email: $admin_email\n";
            echo "   Password: $admin_password\n";
        } else {
            echo "❌ Không thể tạo tài khoản admin: " . $stmt->error . "\n";
        }
    }
    
    $connection->close();
    
    echo "\n🎉 Cài đặt hoàn tất!\n";
    echo "==================\n\n";
    echo "🌐 Truy cập website: http://localhost/\n";
    echo "⚙️ Trang admin: http://localhost/?page=admin\n";
    echo "📚 Tài liệu: README.md\n\n";
    echo "🔒 Thông tin đăng nhập Admin:\n";
    echo "   Username: $admin_username\n";
    echo "   Password: $admin_password\n\n";
    echo "⚠️ Hãy thay đổi mật khẩu admin sau khi đăng nhập!\n";
    
} catch (Exception $e) {
    echo "❌ Lỗi: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✨ Chúc bạn sử dụng website vui vẻ!\n";
?>