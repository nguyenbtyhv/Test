# 🌟 MangaHub - Website Đọc Truyện Tranh Online

Một website đọc truyện tranh hiện đại với giao diện đẹp mắt, bảo mật cao và tính năng hoàn chỉnh như NetTruyen, TruyenQQ, CManga.

## ✨ Tính Năng Chính

### 🔐 Bảo Mật Cao
- **Mã hóa mật khẩu**: Sử dụng PHP password_hash() với thuật toán bcrypt
- **CSRF Protection**: Bảo vệ chống Cross-Site Request Forgery
- **Rate Limiting**: Giới hạn số lượng request để tránh spam
- **SQL Injection Prevention**: Sử dụng Prepared Statements
- **XSS Protection**: Lọc và escape tất cả input từ người dùng
- **Security Headers**: X-Frame-Options, X-XSS-Protection, Content-Security-Policy

### 📱 Giao Diện Hiện Đại
- **Responsive Design**: Tối ưu cho mọi thiết bị
- **Dark Theme**: Giao diện tối chuyên nghiệp
- **Smooth Animations**: Hiệu ứng mượt mà với CSS3
- **Modern UI Components**: Card design, gradient buttons, glassmorphism
- **Progressive Web App**: Hỗ trợ offline và install trên thiết bị

### 🚀 Tính Năng Người Dùng
- **Đăng ký/Đăng nhập**: Hệ thống authentication hoàn chỉnh
- **Hệ thống cảnh giới**: Gamification với level up system
- **Xu và VIP**: Hệ thống kinh tế nội bộ
- **Theo dõi truyện**: Bookmark và notification
- **Lịch sử đọc**: Lưu tiến độ đọc
- **Đánh giá**: Rating system với 5 sao
- **Bình luận**: Comment với reply và like
- **Tìm kiếm**: Full-text search với autocomplete

### 📚 Quản Lý Truyện
- **Upload truyện**: Giao diện admin dễ sử dụng
- **Quản lý chương**: Batch upload, VIP chapters
- **Phân loại**: Multiple genres và status
- **SEO Optimized**: Meta tags và URL structure
- **Image Optimization**: Lazy loading và compression
- **CDN Ready**: Hỗ trợ multiple image hosts

### 💻 Công Nghệ
- **Backend**: PHP 8+ với OOP architecture
- **Database**: MySQL 8+ với indexes tối ưu
- **Frontend**: Vanilla JavaScript ES6+, CSS3
- **Security**: Modern PHP security practices
- **Performance**: Optimized queries và caching
- **Mobile First**: Responsive design approach

## 🛠️ Cài Đặt

### Yêu Cầu Hệ Thống
- PHP 8.0 hoặc cao hơn
- MySQL 8.0 hoặc MariaDB 10.4+
- Apache/Nginx Web Server
- Composer (tùy chọn)
- Node.js (cho development tools)

### Bước 1: Download Source Code
```bash
git clone https://github.com/your-repo/mangahub.git
cd mangahub
```

### Bước 2: Cấu Hình Database
1. Tạo database mới:
```sql
CREATE DATABASE manga_website CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Import schema:
```bash
mysql -u root -p manga_website < database/schema.sql
```

3. Cập nhật thông tin database trong `config/database.php`:
```php
private $host = 'localhost';
private $username = 'your_username';
private $password = 'your_password';
private $database = 'manga_website';
```

### Bước 3: Cấu Hình Web Server

#### Apache (.htaccess)
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Security Headers
Header always set X-Frame-Options "DENY"
Header always set X-XSS-Protection "1; mode=block"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Cache Static Files
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/pdf "access plus 1 month"
    ExpiresByType text/javascript "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

#### Nginx
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/mangahub;
    index index.php;

    # Security Headers
    add_header X-Frame-Options "DENY" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Cache static files
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1M;
        add_header Cache-Control "public, immutable";
    }
}
```

### Bước 4: Cấu Hình Permissions
```bash
chmod 755 /var/www/mangahub
chmod 644 /var/www/mangahub/*.php
chmod 755 /var/www/mangahub/assets
chown -R www-data:www-data /var/www/mangahub
```

### Bước 5: Tài Khoản Admin Mặc Định
- **Username**: admin
- **Email**: admin@mangasite.com
- **Password**: password (thay đổi ngay sau khi đăng nhập)

## 🔧 Cấu Hình Nâng Cao

### SSL/TLS (Khuyến Nghị)
```bash
# Sử dụng Let's Encrypt
certbot --nginx -d your-domain.com
```

### Database Optimization
```sql
-- Tối ưu MySQL
SET GLOBAL innodb_buffer_pool_size = 1G;
SET GLOBAL query_cache_size = 256M;
SET GLOBAL max_connections = 500;

-- Indexes for performance
CREATE INDEX idx_comics_search ON comics(title, author, description);
CREATE INDEX idx_chapters_comic_number ON chapters(comic_id, chapter_number);
CREATE INDEX idx_comments_comic_created ON comments(comic_id, created_at);
```

### CDN Configuration
Cập nhật trong code để sử dụng CDN:
```php
// config/cdn.php
define('CDN_URL', 'https://your-cdn-domain.com/');
define('IMAGE_CDN', 'https://images.your-cdn.com/');
```

### Caching (Redis/Memcached)
```php
// config/cache.php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// Cache hot data
function getCachedComics($key, $callback, $ttl = 3600) {
    global $redis;
    $data = $redis->get($key);
    if ($data === false) {
        $data = $callback();
        $redis->setex($key, $ttl, serialize($data));
    } else {
        $data = unserialize($data);
    }
    return $data;
}
```

## 🎨 Customization

### Theme Customization
Chỉnh sửa `assets/css/style.css`:
```css
:root {
    --primary-color: #your-color;
    --secondary-color: #your-secondary;
    --dark-bg: #your-background;
}
```

### Logo và Branding
1. Thay thế logo trong header
2. Cập nhật favicon
3. Chỉnh sửa meta tags

### Tính Năng Bổ Sung
1. **Payment Integration**: PayPal, Stripe, Momo
2. **Social Login**: Google, Facebook OAuth
3. **Email Notifications**: SMTP configuration
4. **Push Notifications**: WebPush API
5. **Mobile App**: React Native/Flutter

## 📊 Analytics & Monitoring

### Google Analytics
```html
<!-- Thêm vào header -->
<script async src="https://www.googletagmanager.com/gtag/js?id=GA_TRACKING_ID"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'GA_TRACKING_ID');
</script>
```

### Error Logging
```php
// config/logging.php
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/mangahub/php_errors.log');

function logError($message, $context = []) {
    error_log(date('Y-m-d H:i:s') . " - " . $message . " - " . json_encode($context));
}
```

## 🚀 Deployment

### Production Checklist
- [ ] Cấu hình HTTPS
- [ ] Cập nhật database credentials
- [ ] Bật error logging, tắt error display
- [ ] Cấu hình backup tự động
- [ ] Setup monitoring
- [ ] Optimize images
- [ ] Enable compression
- [ ] Configure CDN

### Docker Deployment
```dockerfile
FROM php:8.1-apache
COPY . /var/www/html/
RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN a2enmod rewrite
EXPOSE 80
```

```yaml
# docker-compose.yml
version: '3.8'
services:
  web:
    build: .
    ports:
      - "80:80"
    depends_on:
      - db
  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: rootpassword
      MYSQL_DATABASE: manga_website
    volumes:
      - mysql_data:/var/lib/mysql
volumes:
  mysql_data:
```

## 🔒 Security Best Practices

1. **Regular Updates**: Cập nhật PHP và dependencies
2. **Strong Passwords**: Enforce password complexity
3. **Backup Strategy**: Daily automated backups
4. **Access Control**: Limit admin access
5. **Monitoring**: Log and monitor suspicious activities
6. **Rate Limiting**: Prevent abuse
7. **Input Validation**: Validate all user inputs
8. **HTTPS Only**: Force SSL connections

## 📝 API Documentation

### Endpoints
```
GET /api/comics - Lấy danh sách truyện
GET /api/comics/{id} - Lấy thông tin truyện
POST /api/comics/{id}/follow - Theo dõi truyện
POST /api/comics/{id}/rate - Đánh giá truyện
GET /api/search?q={query} - Tìm kiếm
POST /api/comments - Đăng bình luận
```

### Response Format
```json
{
  "success": true,
  "data": {},
  "message": "Success",
  "pagination": {
    "current_page": 1,
    "total_pages": 10,
    "per_page": 20
  }
}
```

## 🤝 Contributing

1. Fork repository
2. Tạo feature branch
3. Commit changes
4. Push to branch
5. Create Pull Request

## 📄 License

MIT License - see LICENSE file for details

## 💬 Support

- **Email**: support@mangahub.com
- **Discord**: [MangaHub Community](https://discord.gg/mangahub)
- **Documentation**: [docs.mangahub.com](https://docs.mangahub.com)
- **Issues**: [GitHub Issues](https://github.com/your-repo/mangahub/issues)

## 🙏 Credits

Phát triển bởi đội ngũ MangaHub với sự đóng góp từ cộng đồng open source.

---

⭐ **Nếu project này hữu ích, hãy star cho chúng tôi trên GitHub!**