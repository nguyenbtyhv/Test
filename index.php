<?php
session_start();

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'manga_website';

try {
    $mysqli = new mysqli($host, $username, $password, $database);
    $mysqli->set_charset('utf8mb4');
    
    if ($mysqli->connect_error) {
        die("Kết nối database thất bại: " . $mysqli->connect_error);
    }
} catch (Exception $e) {
    die("Lỗi database: " . $e->getMessage());
}

// CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper functions
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function getUser($mysqli) {
    if (isset($_SESSION['user_id'])) {
        $user_id = (int)$_SESSION['user_id'];
        $result = $mysqli->query("SELECT * FROM users WHERE id = $user_id");
        return $result ? $result->fetch_assoc() : null;
    }
    return null;
}

function getTimeAgo($datetime) {
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'vừa xong';
    if ($time < 3600) return floor($time/60) . ' phút trước';
    if ($time < 86400) return floor($time/3600) . ' giờ trước';
    return floor($time/86400) . ' ngày trước';
}

// Get current user
$user = getUser($mysqli);
$page = $_GET['page'] ?? 'home';

// Handle like comment
if ($user && isset($_GET['like_comment'])) {
    $comment_id = (int)$_GET['like_comment'];
    $user_id = $user['id'];
    
    $check = $mysqli->query("SELECT id FROM comment_likes WHERE comment_id = $comment_id AND user_id = $user_id");
    if ($check && $check->num_rows > 0) {
        $mysqli->query("DELETE FROM comment_likes WHERE comment_id = $comment_id AND user_id = $user_id");
    } else {
        $mysqli->query("INSERT INTO comment_likes (comment_id, user_id) VALUES ($comment_id, $user_id)");
    }
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Create tables if not exist
$mysqli->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'translator', 'admin') DEFAULT 'user',
    coins INT DEFAULT 100,
    realm VARCHAR(50) DEFAULT 'Luyện Khí',
    realm_stage INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS comics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    thumbnail VARCHAR(500),
    author VARCHAR(100),
    status ENUM('ongoing', 'completed', 'dropped') DEFAULT 'ongoing',
    genres TEXT,
    views INT DEFAULT 0,
    follows INT DEFAULT 0,
    rating DECIMAL(3,2) DEFAULT 0.00,
    total_rating INT DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS chapters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comic_id INT NOT NULL,
    chapter_title VARCHAR(200) NOT NULL,
    images TEXT,
    is_vip BOOLEAN DEFAULT FALSE,
    coins_unlock INT DEFAULT 0,
    views INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS user_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favorite (user_id, comic_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS comic_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT NOT NULL,
    rating INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rating (user_id, comic_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS read_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chapter_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_read (user_id, chapter_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS chapter_unlocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chapter_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_unlock (user_id, chapter_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT,
    chapter_id INT,
    parent_id INT,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS comment_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comment_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (user_id, comment_id)
)");

// Create default admin user if not exists
$admin_check = $mysqli->query("SELECT id FROM users WHERE username = 'admin'");
if (!$admin_check || $admin_check->num_rows == 0) {
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $mysqli->query("INSERT INTO users (username, email, password, role, coins, realm, realm_stage) 
                   VALUES ('admin', 'admin@manga.com', '$admin_password', 'admin', 10000, 'Tiên Nhân', 10)");
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MangaHub - Website Đọc Truyện Tranh</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: #fff;
            min-height: 100vh;
        }
        
        .header {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2rem;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: bold;
            color: #fff;
            text-decoration: none;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .nav-menu {
            display: flex;
            list-style: none;
            gap: 2rem;
        }
        
        .nav-link {
            color: #fff;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
        
        .user-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        
        .btn-outline {
            background: transparent;
            color: #fff;
            border: 2px solid #fff;
        }
        
        .btn-outline:hover {
            background: #fff;
            color: #1e3c72;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-title {
            font-size: 2.5rem;
            text-align: center;
            margin-bottom: 2rem;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .section-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            color: #fff;
            position: relative;
            padding-left: 1rem;
        }
        
        .section-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            border-radius: 2px;
        }
        
        .comic-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        .comic-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .comic-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .comic-thumbnail {
            width: 100%;
            height: 250px;
            position: relative;
            overflow: hidden;
        }
        
        .comic-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .comic-card:hover .comic-thumbnail img {
            transform: scale(1.1);
        }
        
        .comic-info {
            padding: 1rem;
        }
        
        .comic-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #fff;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .comic-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .form-container {
            max-width: 400px;
            margin: 2rem auto;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 2rem;
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            color: #fff;
            font-size: 1rem;
        }
        
        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }
        
        .form-input:focus {
            outline: none;
            border-color: #4ecdc4;
            box-shadow: 0 0 10px rgba(78, 205, 196, 0.3);
        }
        
        .notification {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }
        
        .notification.success {
            background: rgba(46, 204, 113, 0.2);
            border-color: #2ecc71;
            color: #2ecc71;
        }
        
        .notification.error {
            background: rgba(231, 76, 60, 0.2);
            border-color: #e74c3c;
            color: #e74c3c;
        }
        
        .notification.warning {
            background: rgba(241, 196, 15, 0.2);
            border-color: #f1c40f;
            color: #f1c40f;
        }
        
        .chapter-list {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }
        
        .chapter-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: background 0.3s ease;
        }
        
        .chapter-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .chapter-link {
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            flex: 1;
        }
        
        .chapter-link:hover {
            color: #4ecdc4;
        }
        
        .admin-section {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .admin-nav {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            border-radius: 10px;
            color: #fff;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab-btn.active {
            background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
        }
        
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .nav-menu {
                gap: 1rem;
            }
            
            .container {
                padding: 1rem;
            }
            
            .comic-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .page-title {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="navbar">
            <a href="?page=home" class="logo">🌟 MangaHub</a>
            <ul class="nav-menu">
                <li><a href="?page=home" class="nav-link <?php echo $page == 'home' ? 'active' : ''; ?>">Trang chủ</a></li>
                <li><a href="?page=favorites" class="nav-link <?php echo $page == 'favorites' ? 'active' : ''; ?>">Theo dõi</a></li>
                <li><a href="?page=history" class="nav-link <?php echo $page == 'history' ? 'active' : ''; ?>">Lịch sử</a></li>
                <?php if ($user && in_array($user['role'], ['admin', 'translator'])): ?>
                <li><a href="?page=admin" class="nav-link <?php echo $page == 'admin' ? 'active' : ''; ?>">Quản trị</a></li>
                <?php endif; ?>
            </ul>
            <div class="user-area">
                <?php if ($user): ?>
                    <span>Xin chào, <?php echo sanitize($user['username']); ?>!</span>
                    <a href="?page=profile" class="btn btn-outline">Tài khoản</a>
                    <a href="?page=logout" class="btn btn-primary">Đăng xuất</a>
                <?php else: ?>
                    <a href="?page=login" class="btn btn-outline">Đăng nhập</a>
                    <a href="?page=register" class="btn btn-primary">Đăng ký</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main class="container">
        <?php
        // Handle different pages
        switch ($page) {
            case 'home':
                echo '<h1 class="page-title">Truyện Mới Nhất</h1>';
                echo '<div class="comic-grid">';
                
                $comics = $mysqli->query("SELECT * FROM comics ORDER BY updated_at DESC LIMIT 20");
                if ($comics && $comics->num_rows > 0) {
                    while ($comic = $comics->fetch_assoc()) {
                        $thumbnail = $comic['thumbnail'] ?: 'https://via.placeholder.com/200x250?text=No+Image';
                        echo '<div class="comic-card">
                                <a href="?page=comic&id=' . $comic['id'] . '">
                                    <div class="comic-thumbnail">
                                        <img src="' . sanitize($thumbnail) . '" alt="' . sanitize($comic['title']) . '">
                                    </div>
                                    <div class="comic-info">
                                        <h3 class="comic-title">' . sanitize($comic['title']) . '</h3>
                                        <div class="comic-meta">
                                            <span>' . sanitize($comic['author'] ?: 'Chưa rõ') . '</span>
                                            <span>' . number_format($comic['views']) . ' lượt xem</span>
                                        </div>
                                    </div>
                                </a>
                              </div>';
                    }
                } else {
                    echo '<div class="notification warning">Chưa có truyện nào được đăng.</div>';
                }
                echo '</div>';
                break;

            case 'login':
                if ($_POST) {
                    $username = sanitize($_POST['username']);
                    $password = $_POST['password'];
                    
                    $stmt = $mysqli->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
                    $stmt->bind_param("ss", $username, $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result && $result->num_rows > 0) {
                        $user_data = $result->fetch_assoc();
                        if (password_verify($password, $user_data['password'])) {
                            $_SESSION['user_id'] = $user_data['id'];
                            echo '<script>window.location.href = "?page=home";</script>';
                            exit;
                        }
                    }
                    echo '<div class="notification error">Tên đăng nhập hoặc mật khẩu không đúng!</div>';
                }
                
                echo '<div class="form-container">
                        <h2 class="section-title">Đăng Nhập</h2>
                        <form method="POST">
                            <div class="form-group">
                                <input name="username" type="text" class="form-input" placeholder="Tên đăng nhập hoặc email" required>
                            </div>
                            <div class="form-group">
                                <input name="password" type="password" class="form-input" placeholder="Mật khẩu" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Đăng Nhập</button>
                        </form>
                      </div>';
                break;

            case 'register':
                if ($_POST) {
                    $username = sanitize($_POST['username']);
                    $email = sanitize($_POST['email']);
                    $password = $_POST['password'];
                    
                    if (strlen($username) < 3) {
                        echo '<div class="notification error">Tên đăng nhập phải có ít nhất 3 ký tự!</div>';
                    } elseif (strlen($password) < 6) {
                        echo '<div class="notification error">Mật khẩu phải có ít nhất 6 ký tự!</div>';
                    } else {
                        $check = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                        $check->bind_param("ss", $username, $email);
                        $check->execute();
                        
                        if ($check->get_result()->num_rows > 0) {
                            echo '<div class="notification error">Tên đăng nhập hoặc email đã tồn tại!</div>';
                        } else {
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $mysqli->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                            $stmt->bind_param("sss", $username, $email, $hashed_password);
                            
                            if ($stmt->execute()) {
                                echo '<div class="notification success">Đăng ký thành công! Hãy đăng nhập.</div>';
                                echo '<script>setTimeout(() => window.location.href = "?page=login", 2000);</script>';
                            } else {
                                echo '<div class="notification error">Có lỗi xảy ra khi đăng ký!</div>';
                            }
                        }
                    }
                }
                
                echo '<div class="form-container">
                        <h2 class="section-title">Đăng Ký</h2>
                        <form method="POST">
                            <div class="form-group">
                                <input name="username" type="text" class="form-input" placeholder="Tên đăng nhập" required>
                            </div>
                            <div class="form-group">
                                <input name="email" type="email" class="form-input" placeholder="Email" required>
                            </div>
                            <div class="form-group">
                                <input name="password" type="password" class="form-input" placeholder="Mật khẩu" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Đăng Ký</button>
                        </form>
                      </div>';
                break;

            case 'logout':
                session_destroy();
                echo '<script>window.location.href = "?page=home";</script>';
                exit;

            case 'comic':
                $comic_id = (int)($_GET['id'] ?? 0);
                if ($comic_id <= 0) {
                    echo '<div class="notification error">Truyện không tồn tại!</div>';
                    break;
                }
                
                $comic = $mysqli->query("SELECT * FROM comics WHERE id = $comic_id")->fetch_assoc();
                if (!$comic) {
                    echo '<div class="notification error">Không tìm thấy truyện!</div>';
                    break;
                }
                
                // Update view count
                $mysqli->query("UPDATE comics SET views = views + 1 WHERE id = $comic_id");
                
                echo '<h1 class="page-title">' . sanitize($comic['title']) . '</h1>';
                echo '<div style="display: flex; gap: 2rem; margin-bottom: 2rem;">
                        <img src="' . sanitize($comic['thumbnail'] ?: 'https://via.placeholder.com/300x400') . '" 
                             style="width: 300px; height: 400px; object-fit: cover; border-radius: 15px;">
                        <div>
                            <p><strong>Tác giả:</strong> ' . sanitize($comic['author'] ?: 'Chưa rõ') . '</p>
                            <p><strong>Trạng thái:</strong> ' . sanitize($comic['status']) . '</p>
                            <p><strong>Lượt xem:</strong> ' . number_format($comic['views']) . '</p>
                            <p><strong>Theo dõi:</strong> ' . number_format($comic['follows']) . '</p>
                            <p><strong>Mô tả:</strong></p>
                            <p>' . nl2br(sanitize($comic['description'] ?: 'Chưa có mô tả')) . '</p>
                        </div>
                      </div>';
                
                echo '<h2 class="section-title">Danh Sách Chương</h2>';
                $chapters = $mysqli->query("SELECT * FROM chapters WHERE comic_id = $comic_id ORDER BY id ASC");
                if ($chapters && $chapters->num_rows > 0) {
                    echo '<div class="chapter-list">';
                    while ($chapter = $chapters->fetch_assoc()) {
                        echo '<div class="chapter-item">
                                <a href="?page=chapter&id=' . $chapter['id'] . '" class="chapter-link">
                                    ' . sanitize($chapter['chapter_title']) . '
                                </a>
                                <span>' . getTimeAgo($chapter['created_at']) . '</span>
                              </div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="notification warning">Chưa có chương nào.</div>';
                }
                break;

            case 'chapter':
                if (!$user) {
                    echo '<div class="notification warning">Bạn cần <a href="?page=login">đăng nhập</a> để đọc truyện.</div>';
                    break;
                }
                
                $chapter_id = (int)($_GET['id'] ?? 0);
                if ($chapter_id <= 0) {
                    echo '<div class="notification error">Chương không tồn tại!</div>';
                    break;
                }
                
                $chapter = $mysqli->query("
                    SELECT c.*, co.title as comic_title, co.id as comic_id 
                    FROM chapters c 
                    JOIN comics co ON co.id = c.comic_id 
                    WHERE c.id = $chapter_id
                ")->fetch_assoc();
                
                if (!$chapter) {
                    echo '<div class="notification error">Không tìm thấy chương!</div>';
                    break;
                }
                
                // Update view count and reading history
                $mysqli->query("UPDATE chapters SET views = views + 1 WHERE id = $chapter_id");
                $mysqli->query("INSERT INTO read_history (user_id, chapter_id) VALUES ({$user['id']}, $chapter_id) 
                               ON DUPLICATE KEY UPDATE updated_at = NOW()");
                
                echo '<h1 class="page-title">' . sanitize($chapter['comic_title']) . ' - ' . sanitize($chapter['chapter_title']) . '</h1>';
                
                $images = $chapter['images'] ? explode("\n", trim($chapter['images'])) : [];
                if (!empty($images)) {
                    echo '<div style="text-align: center;">';
                    foreach ($images as $image) {
                        $image = trim($image);
                        if (!empty($image)) {
                            echo '<img src="' . sanitize($image) . '" style="max-width: 100%; margin-bottom: 1rem; border-radius: 10px;">';
                        }
                    }
                    echo '</div>';
                } else {
                    echo '<div class="notification warning">Chương này chưa có nội dung.</div>';
                }
                
                echo '<div style="text-align: center; margin-top: 2rem;">
                        <a href="?page=comic&id=' . $chapter['comic_id'] . '" class="btn btn-primary">Quay lại danh sách chương</a>
                      </div>';
                break;

            case 'admin':
                if (!$user || !in_array($user['role'], ['admin', 'translator'])) {
                    echo '<div class="notification error">Bạn không có quyền truy cập trang này!</div>';
                    break;
                }
                
                $action = $_GET['action'] ?? 'comic';
                
                echo '<h1 class="page-title">Quản Trị Hệ Thống</h1>';
                echo '<div class="admin-nav">
                        <button class="tab-btn ' . ($action == 'comic' ? 'active' : '') . '" onclick="location.href=\'?page=admin&action=comic\'">Quản lý truyện</button>
                        <button class="tab-btn ' . ($action == 'chapter' ? 'active' : '') . '" onclick="location.href=\'?page=admin&action=chapter\'">Quản lý chương</button>
                      </div>';
                
                if ($action == 'comic') {
                    if ($_POST && isset($_POST['add_comic'])) {
                        $title = sanitize($_POST['title']);
                        $author = sanitize($_POST['author']);
                        $description = sanitize($_POST['description']);
                        $thumbnail = sanitize($_POST['thumbnail']);
                        
                        if (!empty($title)) {
                            $stmt = $mysqli->prepare("INSERT INTO comics (title, author, description, thumbnail, created_by) VALUES (?, ?, ?, ?, ?)");
                            $stmt->bind_param("ssssi", $title, $author, $description, $thumbnail, $user['id']);
                            
                            if ($stmt->execute()) {
                                echo '<div class="notification success">Đã thêm truyện thành công!</div>';
                            } else {
                                echo '<div class="notification error">Có lỗi khi thêm truyện!</div>';
                            }
                        }
                    }
                    
                    echo '<div class="admin-section">
                            <h2 class="section-title">Thêm Truyện Mới</h2>
                            <form method="POST">
                                <div class="form-group">
                                    <input name="title" type="text" class="form-input" placeholder="Tên truyện" required>
                                </div>
                                <div class="form-group">
                                    <input name="author" type="text" class="form-input" placeholder="Tác giả">
                                </div>
                                <div class="form-group">
                                    <input name="thumbnail" type="url" class="form-input" placeholder="Link ảnh bìa">
                                </div>
                                <div class="form-group">
                                    <textarea name="description" class="form-input" placeholder="Mô tả truyện" rows="4"></textarea>
                                </div>
                                <button type="submit" name="add_comic" class="btn btn-primary">Thêm Truyện</button>
                            </form>
                          </div>';
                    
                    echo '<div class="admin-section">
                            <h2 class="section-title">Danh Sách Truyện</h2>';
                    
                    $comics = $mysqli->query("SELECT * FROM comics ORDER BY id DESC");
                    if ($comics && $comics->num_rows > 0) {
                        while ($comic = $comics->fetch_assoc()) {
                            echo '<div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 10px;">
                                    <img src="' . sanitize($comic['thumbnail'] ?: 'https://via.placeholder.com/60x80') . '" style="width: 60px; height: 80px; object-fit: cover; border-radius: 5px;">
                                    <div style="flex: 1;">
                                        <h4>' . sanitize($comic['title']) . '</h4>
                                        <p style="color: rgba(255,255,255,0.7);">' . sanitize($comic['author'] ?: 'Chưa rõ tác giả') . '</p>
                                    </div>
                                    <div>
                                        <a href="?page=admin&action=chapter&comic_id=' . $comic['id'] . '" class="btn btn-outline" style="margin-right: 0.5rem;">Quản lý chương</a>
                                        <a href="?page=comic&id=' . $comic['id'] . '" class="btn btn-primary">Xem</a>
                                    </div>
                                  </div>';
                        }
                    } else {
                        echo '<div class="notification warning">Chưa có truyện nào.</div>';
                    }
                    echo '</div>';
                    
                } elseif ($action == 'chapter') {
                    $comic_id = (int)($_GET['comic_id'] ?? 0);
                    
                    if ($_POST && isset($_POST['add_chapter'])) {
                        $chapter_comic_id = (int)$_POST['comic_id'];
                        $chapter_title = sanitize($_POST['chapter_title']);
                        $images = sanitize($_POST['images']);
                        
                        if ($chapter_comic_id > 0 && !empty($chapter_title)) {
                            $stmt = $mysqli->prepare("INSERT INTO chapters (comic_id, chapter_title, images) VALUES (?, ?, ?)");
                            $stmt->bind_param("iss", $chapter_comic_id, $chapter_title, $images);
                            
                            if ($stmt->execute()) {
                                echo '<div class="notification success">Đã thêm chương thành công!</div>';
                            } else {
                                echo '<div class="notification error">Có lỗi khi thêm chương!</div>';
                            }
                        }
                    }
                    
                    echo '<div class="admin-section">
                            <h2 class="section-title">Thêm Chương Mới</h2>
                            <form method="POST">
                                <div class="form-group">
                                    <select name="comic_id" class="form-input" required>';
                    
                    $comics = $mysqli->query("SELECT id, title FROM comics ORDER BY title");
                    echo '<option value="">Chọn truyện</option>';
                    while ($comic = $comics->fetch_assoc()) {
                        $selected = ($comic_id == $comic['id']) ? 'selected' : '';
                        echo '<option value="' . $comic['id'] . '" ' . $selected . '>' . sanitize($comic['title']) . '</option>';
                    }
                    
                    echo '</select>
                                </div>
                                <div class="form-group">
                                    <input name="chapter_title" type="text" class="form-input" placeholder="Tên chương" required>
                                </div>
                                <div class="form-group">
                                    <textarea name="images" class="form-input" placeholder="Link ảnh (mỗi dòng một link)" rows="6"></textarea>
                                </div>
                                <button type="submit" name="add_chapter" class="btn btn-primary">Thêm Chương</button>
                            </form>
                          </div>';
                    
                    if ($comic_id > 0) {
                        $comic = $mysqli->query("SELECT title FROM comics WHERE id = $comic_id")->fetch_assoc();
                        echo '<div class="admin-section">
                                <h2 class="section-title">Chương của: ' . sanitize($comic['title']) . '</h2>';
                        
                        $chapters = $mysqli->query("SELECT * FROM chapters WHERE comic_id = $comic_id ORDER BY id DESC");
                        if ($chapters && $chapters->num_rows > 0) {
                            while ($chapter = $chapters->fetch_assoc()) {
                                echo '<div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 10px;">
                                        <div style="flex: 1;">
                                            <h4>' . sanitize($chapter['chapter_title']) . '</h4>
                                            <p style="color: rgba(255,255,255,0.7);">' . getTimeAgo($chapter['created_at']) . '</p>
                                        </div>
                                        <div>
                                            <a href="?page=chapter&id=' . $chapter['id'] . '" class="btn btn-primary">Xem</a>
                                        </div>
                                      </div>';
                            }
                        } else {
                            echo '<div class="notification warning">Chưa có chương nào.</div>';
                        }
                        echo '</div>';
                    }
                }
                break;

            case 'profile':
                if (!$user) {
                    echo '<div class="notification warning">Bạn cần <a href="?page=login">đăng nhập</a> để xem thông tin cá nhân.</div>';
                    break;
                }
                
                echo '<h1 class="page-title">Thông Tin Cá Nhân</h1>';
                echo '<div class="admin-section">
                        <div style="text-align: center;">
                            <h2>' . sanitize($user['username']) . '</h2>
                            <p><strong>Email:</strong> ' . sanitize($user['email'] ?: 'Chưa cập nhật') . '</p>
                            <p><strong>Vai trò:</strong> ' . sanitize($user['role']) . '</p>
                            <p><strong>Số xu:</strong> ' . number_format($user['coins']) . ' xu</p>
                            <p><strong>Cảnh giới:</strong> ' . sanitize($user['realm']) . ' - ' . $user['realm_stage'] . '/10</p>
                            <p><strong>Ngày tham gia:</strong> ' . date('d/m/Y', strtotime($user['created_at'])) . '</p>
                        </div>
                      </div>';
                break;

            default:
                echo '<div class="notification error">Trang không tồn tại!</div>';
                break;
        }
        ?>
    </main>

    <script>
        // Simple JavaScript for interactivity
        document.addEventListener('DOMContentLoaded', function() {
            // Smooth scrolling
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth' });
                    }
                });
            });
            
            // Auto-hide notifications
            setTimeout(() => {
                const notifications = document.querySelectorAll('.notification');
                notifications.forEach(notification => {
                    notification.style.opacity = '0.7';
                });
            }, 3000);
        });
    </script>
</body>
</html>