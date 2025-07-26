# 🚀 MangaHub - Hướng Dẫn Triển Khai

## 📋 Tổng Quan

MangaHub là một website đọc truyện tranh hiện đại được xây dựng với:
- **Backend**: PHP 8+ với kiến trúc OOP
- **Database**: MySQL 8.0 với tối ưu hóa cao
- **Frontend**: HTML5, CSS3, JavaScript ES6+
- **Security**: Bảo mật cao với CSRF, rate limiting, SQL injection prevention
- **Performance**: Lazy loading, caching, optimized queries

## 🎯 Tính Năng Chính

### ✨ Cho Người Dùng
- [x] Đăng ký/Đăng nhập bảo mật
- [x] Hệ thống cảnh giới (gamification)
- [x] Xu và chương VIP
- [x] Theo dõi truyện yêu thích
- [x] Lịch sử đọc với bookmark
- [x] Đánh giá 5 sao
- [x] Bình luận với reply và like
- [x] Tìm kiếm full-text với autocomplete
- [x] Responsive design cho mobile

### 🛠️ Cho Admin
- [x] Quản lý truyện và chương
- [x] Upload batch chapters
- [x] Phân quyền người dùng
- [x] Thống kê chi tiết
- [x] Quản lý bình luận
- [x] Cấu hình hệ thống

### 🔒 Bảo Mật
- [x] Password hashing với bcrypt
- [x] CSRF token protection
- [x] Rate limiting cho API
- [x] SQL injection prevention
- [x] XSS protection
- [x] Security headers

## 🚀 Cài Đặt Nhanh

### Option 1: Docker (Khuyến Nghị)

```bash
# Clone repository
git clone https://github.com/your-repo/mangahub.git
cd mangahub

# Start với Docker Compose
docker-compose up -d

# Truy cập website
open http://localhost
```

**Thông tin đăng nhập mặc định:**
- Username: `admin`
- Password: `password`

### Option 2: Manual Setup

#### Bước 1: Yêu Cầu Hệ Thống
```bash
# Ubuntu/Debian
sudo apt update
sudo apt install php8.2 php8.2-mysqli php8.2-json php8.2-session
sudo apt install mysql-server apache2

# CentOS/RHEL
sudo yum install php82 php82-mysqli mysql-server httpd
```

#### Bước 2: Cấu Hình Database
```bash
# Đăng nhập MySQL
mysql -u root -p

# Tạo database và user
CREATE DATABASE manga_website CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'manga_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON manga_website.* TO 'manga_user'@'localhost';
FLUSH PRIVILEGES;
```

#### Bước 3: Setup Website
```bash
# Download source
git clone https://github.com/your-repo/mangahub.git
cd mangahub

# Chạy setup script
php setup.php

# Hoặc import database thủ công
mysql -u manga_user -p manga_website < database/schema.sql
```

#### Bước 4: Cấu Hình Web Server

**Apache (.htaccess đã có sẵn)**
```apache
# Enable mod_rewrite
sudo a2enmod rewrite
sudo systemctl restart apache2
```

**Nginx**
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/mangahub;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## ⚙️ Cấu Hình

### Database Connection
Cập nhật `config/database.php`:
```php
private $host = 'localhost';
private $username = 'manga_user';
private $password = 'your_secure_password';
private $database = 'manga_website';
```

### Security Settings
Cập nhật `config/security.php` nếu cần:
```php
// Rate limiting
public static function checkRateLimit($user_id, $action, $limit = 10, $timeframe = 300)

// CSRF token
public static function generateCSRFToken()
```

### Performance Optimization

#### MySQL Tuning
```sql
-- my.cnf
[mysqld]
innodb_buffer_pool_size = 1G
query_cache_size = 256M
max_connections = 500
innodb_file_per_table = 1
```

#### PHP Optimization
```ini
; php.ini
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 10000
memory_limit = 512M
```

#### Redis Caching (Optional)
```bash
# Install Redis
sudo apt install redis-server

# Enable in PHP
sudo apt install php8.2-redis
```

## 🌐 Deployment Production

### 1. SSL Certificate
```bash
# Let's Encrypt
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d your-domain.com
```

### 2. Security Hardening
```bash
# Set proper permissions
sudo chown -R www-data:www-data /var/www/mangahub
sudo chmod -R 755 /var/www/mangahub
sudo chmod -R 644 /var/www/mangahub/*.php

# Secure uploads directory
sudo chmod 755 /var/www/mangahub/uploads
```

### 3. Backup Strategy
```bash
# Database backup script
#!/bin/bash
mysqldump -u manga_user -p manga_website > backup_$(date +%Y%m%d_%H%M%S).sql

# Cron job for daily backup
0 2 * * * /path/to/backup-script.sh
```

### 4. Monitoring
```bash
# Install monitoring tools
sudo apt install htop iotop nethogs
sudo apt install fail2ban # For security

# Log monitoring
tail -f /var/log/apache2/error.log
tail -f /var/log/mysql/error.log
```

## 🎨 Customization

### Theme Colors
Chỉnh sửa `assets/css/style.css`:
```css
:root {
    --primary-color: #0099ff;      /* Màu chính */
    --secondary-color: #f39c12;    /* Màu phụ */
    --dark-bg: #1a1d29;           /* Nền tối */
    --card-bg: #22252f;           /* Nền card */
}
```

### Logo và Branding
1. Thay thế logo trong header
2. Cập nhật favicon.ico
3. Chỉnh sửa title và meta tags
4. Tùy chỉnh footer

### Add Features
1. **Payment Gateway**: Stripe, PayPal integration
2. **Social Login**: Google, Facebook OAuth
3. **Email System**: SMTP configuration
4. **Mobile App**: API endpoints ready

## 📊 Analytics

### Google Analytics
Thêm vào header:
```html
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=GA_TRACKING_ID"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'GA_TRACKING_ID');
</script>
```

### Performance Monitoring
```php
// Add to config/monitoring.php
function logPerformance($start_time, $page) {
    $execution_time = microtime(true) - $start_time;
    error_log("Performance: $page took {$execution_time}s");
}
```

## 🐛 Troubleshooting

### Common Issues

#### 1. Database Connection Error
```bash
# Check MySQL service
sudo systemctl status mysql

# Check credentials in config/database.php
# Verify database exists and user has permissions
```

#### 2. Permission Denied
```bash
# Fix file permissions
sudo chown -R www-data:www-data /var/www/mangahub
sudo chmod -R 755 /var/www/mangahub
```

#### 3. 404 Errors
```bash
# Enable Apache rewrite module
sudo a2enmod rewrite
sudo systemctl restart apache2

# Check .htaccess file exists and is readable
```

#### 4. PHP Errors
```bash
# Check PHP error log
tail -f /var/log/php_errors.log

# Enable error reporting for debugging
# In php.ini: display_errors = On
```

### Performance Issues

#### Slow Queries
```sql
-- Enable slow query log
SET GLOBAL slow_query_log = 1;
SET GLOBAL long_query_time = 2;

-- Check slow queries
cat /var/log/mysql/mysql-slow.log
```

#### High Memory Usage
```bash
# Check PHP memory limit
php -i | grep memory_limit

# Monitor with htop
htop
```

## 📞 Support

### Documentation
- [README.md](README.md) - Tổng quan và hướng dẫn
- [API Documentation](docs/api.md) - API endpoints
- [Database Schema](database/schema.sql) - Cấu trúc database

### Community
- **GitHub Issues**: [Report bugs](https://github.com/your-repo/mangahub/issues)
- **Discord**: [Join community](https://discord.gg/mangahub)
- **Email**: support@mangahub.com

### Professional Support
Liên hệ cho tư vấn triển khai enterprise:
- Email: enterprise@mangahub.com
- Telegram: @mangahub_support

## 📝 Changelog

### Version 2.0.0 (Latest)
- ✅ Complete rewrite with modern architecture
- ✅ Enhanced security with multiple layers
- ✅ Beautiful responsive UI design
- ✅ Docker support for easy deployment
- ✅ Performance optimizations
- ✅ Comprehensive documentation

### Version 1.0.0
- ✅ Basic functionality
- ✅ User management
- ✅ Comic reading
- ✅ Comment system

---

🎉 **Chúc bạn triển khai thành công!**

Nếu gặp vấn đề, đừng ngần ngại tạo issue trên GitHub hoặc liên hệ support team.