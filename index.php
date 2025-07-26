<?php
session_start();

// Error reporting and security headers
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Include required files
require_once 'config/database.php';
require_once 'config/security.php';
require_once 'models/User.php';
require_once 'models/Comic.php';

// Initialize database and models
try {
    $db = new Database();
    $userModel = new User();
    $comicModel = new Comic();
} catch (Exception $e) {
    die('System error. Please try again later.');
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();

// Get current user
$user = null;
if (isset($_SESSION['user_id'])) {
    $user = $userModel->getUserById($_SESSION['user_id']);
}

// Get page parameter
$page = $_GET['page'] ?? 'home';
function getCommentCount($comic_id) {
    global $mysqli;
    $result = $mysqli->query("SELECT COUNT(*) as count FROM comments WHERE comic_id = $comic_id")->fetch_assoc();
    return $result['count'];
} 
function getTimeAgo($time) {
    $diff = time() - strtotime($time);
    if($diff < 60) return 'vài giây trước';
    if($diff < 3600) return round($diff/60) . ' phút trước';
    if($diff < 86400) return round($diff/3600) . ' giờ trước';
    if($diff < 2592000) return round($diff/86400) . ' ngày trước';
    if($diff < 31536000) return round($diff/2592000) . ' tháng trước';
    return round($diff/31536000) . ' năm trước';
}
function getPrevChapter($comic_id, $chapter_id) {
    global $mysqli;
    $res = $mysqli->query("SELECT id FROM chapters WHERE comic_id=$comic_id AND id < $chapter_id ORDER BY id DESC LIMIT 1")->fetch_assoc();
    return $res ? $res['id'] : null;
}
function getNextChapter($comic_id, $chapter_id) {
    global $mysqli;
    $res = $mysqli->query("SELECT id FROM chapters WHERE comic_id=$comic_id AND id > $chapter_id ORDER BY id ASC LIMIT 1")->fetch_assoc();
    return $res ? $res['id'] : null;
}
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function canPostComment($user_id) {
    global $mysqli;
    $check = $mysqli->query(
        "SELECT COUNT(*) as count 
         FROM comments 
         WHERE user_id = $user_id 
         AND created_at >= NOW() - INTERVAL 5 MINUTE"
    )->fetch_assoc();
    if ($check['count'] >= 10) {
        return ['allowed' => false, 'message' => 'Bạn đã bình luận quá nhiều. Vui lòng đợi một lát.'];
    }
    $lastComment = $mysqli->query(
        "SELECT created_at 
         FROM comments 
         WHERE user_id = $user_id 
         ORDER BY created_at DESC 
         LIMIT 1"
    )->fetch_assoc();
    if ($lastComment && (time() - strtotime($lastComment['created_at'])) < 15) {
        return ['allowed' => false, 'message' => 'Vui lòng đợi 15 giây giữa các lần bình luận.'];
    }
    return ['allowed' => true];
}
function handleLevelUp($user_id) {
    global $mysqli;
    $realms = [
        'Luyện Khí', 'Trúc Cơ', 'Kết Đan', 'Nguyên Anh', 'Hoá Thần',
        'Luyện Hư', 'Hợp Thể', 'Đại Thừa', 'Độ Kiếp'
    ];
    $current = $mysqli->query("SELECT level,realm,realm_stage FROM users WHERE id=$user_id")->fetch_assoc();
    $realmIdx = array_search($current['realm'], $realms);
    $stage = (int)$current['realm_stage'];

    // Quy tắc: Mỗi cảnh giới cần số chương đọc: (10*($realmIdx+1)+$stage*($realmIdx+1)*3)
    $needed = ($realmIdx+1)*10 + ($stage-1)*($realmIdx+1)*3;
    $history = $mysqli->query("SELECT COUNT(*) as cnt FROM read_history WHERE user_id=$user_id")->fetch_assoc()['cnt'];
    if ($history >= $needed) {
        if ($stage < 10) {
            $mysqli->query("UPDATE users SET realm_stage = realm_stage + 1 WHERE id = $user_id");
        } else {
            if ($realmIdx < count($realms)-1) {
                $nextRealm = $realms[$realmIdx+1];
                $mysqli->query("UPDATE users SET realm = '$nextRealm', realm_stage = 1 WHERE id = $user_id");
            }
        }
    }
}
function displayComments($comic_id, $chapter_id = null) {
    global $mysqli, $user;

    $where = $chapter_id ? "chapter_id = $chapter_id" : "comic_id = $comic_id AND chapter_id IS NULL";
    $query = "
        SELECT c.*, u.username, u.role, u.realm, u.realm_stage
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE $where AND parent_id IS NULL
        ORDER BY c.created_at DESC
    ";
    $comments = $mysqli->query($query);

    while ($row = $comments->fetch_assoc()) {
        echo "<div class='comment-item'>";
        // Avatar
        echo "<div class='comment-avatar'><img src='https://via.placeholder.com/40?text=Avatar'></div>";
        // Nội dung comment
        echo "<div class='comment-content'>";
        // Header
        echo "<div class='comment-header'><span class='comment-username'>".htmlspecialchars($row['username'])."</span> ";

        // Cảnh giới sau tên
        if ($row['realm']) {
            $stage = $row['realm_stage'];
            $display_stage = ($stage == 10) ? "Đỉnh phong" : $stage;
            echo "<span class='comment-realm' style='color:#f7c500;font-size:0.92em;padding-left:4px'>[".htmlspecialchars($row['realm'])." - $display_stage/10]</span>";
        }

        // Tag vai trò
        echo ($row['role'] == 'admin' ? "<span class='tag-admin'>Admin</span>" : "") .
             ($row['role'] == 'team' ? "<span class='tag-team'>Nhóm dịch</span>" : "") .
             ($row['role'] == 'translator' ? "<span class='tag-translator'>Nhà dịch</span>" : "");
        echo "<span class='comment-time'>".getTimeAgo($row['created_at'])."</span></div>";
        // Nội dung
        echo "<div class='comment-text'>".nl2br(htmlspecialchars($row['content']))."</div>";

        // Like, trả lời
        $likes = $mysqli->query("SELECT COUNT(*) as cnt FROM comment_likes WHERE comment_id={$row['id']}")->fetch_assoc()['cnt'];
        $userLiked = $user ? $mysqli->query("SELECT 1 FROM comment_likes WHERE comment_id={$row['id']} AND user_id={$user['id']}")->num_rows : 0;
        echo "<div class='comment-actions' style='margin-top:4px'>";
        echo "<a href='?page=".$_GET['page']."&id=".$comic_id."&like_comment={$row['id']}' class='btn-link' style='color:".($userLiked?"#f44336":"#09f")."'>";
        echo "❤ {$likes}</a>";
        if ($user) {
            echo "<button class='btn-link' type='button' onclick='showReplyForm({$row['id']})'>Trả lời</button>";
        }
        echo "</div>";

        // Form trả lời
        if ($user) {
            echo "
            <form method='POST' class='reply-form' id='reply-form-{$row['id']}' style='display:none;margin-top:10px;'>
                <input type='hidden' name='csrf_token' value='{$_SESSION['csrf_token']}'>
                <input type='hidden' name='parent_id' value='{$row['id']}'>
                <textarea name='reply_content' class='form-input' rows='2' placeholder='Nội dung phản hồi...' required></textarea>
                <button type='submit' name='post_reply' class='primary-btn' style='margin-top:6px;'>Gửi</button>
            </form>
            ";
        }

        echo "</div>"; // comment-content

        // PHẢN HỒI
        $replies = $mysqli->query("SELECT c.*, u.username, u.role, u.realm, u.realm_stage
                                   FROM comments c 
                                   JOIN users u ON c.user_id = u.id 
                                   WHERE parent_id = {$row['id']} 
                                   ORDER BY c.created_at ASC");
        if ($replies && $replies->num_rows > 0) {
            echo "<div class='comment-replies'>";
            while ($rep = $replies->fetch_assoc()) {
                echo "<div class='reply-item'>";
                echo "<div class='comment-avatar'><img src='https://via.placeholder.com/30?text=Avatar'></div>";
                echo "<div class='reply-content'>";
                echo "<div class='comment-header'><span class='comment-username'>".htmlspecialchars($rep['username'])."</span> ";
                // Cảnh giới reply
                if ($rep['realm']) {
                    $stage = $rep['realm_stage'];
                    $display_stage = ($stage == 10) ? "Đỉnh phong" : $stage;
                    echo "<span class='comment-realm' style='color:#f7c500;font-size:0.92em;padding-left:4px'>[".htmlspecialchars($rep['realm'])." - $display_stage/10]</span>";
                }
                echo ($rep['role'] == 'admin' ? "<span class='tag-admin'>Admin</span>" : "") .
($rep['role'] == 'translator' ? "<span class='tag-translator'>Nhà dịch</span>" : "");
                echo "<span class='comment-time'>".getTimeAgo($rep['created_at'])."</span></div>";
                echo "<div class='comment-text'>".nl2br(htmlspecialchars($rep['content']))."</div>";
                // Like cho reply
                $replyLikes = $mysqli->query("SELECT COUNT(*) as cnt FROM comment_likes WHERE comment_id={$rep['id']}")->fetch_assoc()['cnt'];
                $replyUserLiked = $user ? $mysqli->query("SELECT 1 FROM comment_likes WHERE comment_id={$rep['id']} AND user_id={$user['id']}")->num_rows : 0;
                echo "<div class='comment-actions' style='margin-top:2px'>";
                echo "<a href='?page=".$_GET['page']."&id=".$comic_id."&like_comment={$rep['id']}' class='btn-link' style='color:".($replyUserLiked?"#f44336":"#09f")."'>";
                echo "❤ {$replyLikes}</a>";
                echo "</div>";
                echo "</div></div>";
            }
            echo "</div>";
        }

        echo "</div>"; // comment-item
    }
}
function getUser() {
    global $mysqli;
    return isset($_SESSION['user_id']) ? $mysqli->query("SELECT * FROM users WHERE id={$_SESSION['user_id']}")->fetch_assoc() : null;
}
function isAdmin() { 
    $user = getUser();
    return $user && $user['role'] === 'admin'; 
}
function isTranslator() { 
    $user = getUser();
    return $user && $user['role'] === 'translator'; 
}
function requireRole($roles) { 
    $user = getUser();
    if (!$user || !in_array($user['role'], $roles)) {
        die('<div class="notice warning">Bạn không có quyền truy cập trang này.</div>');
    }
}
$page = $_GET['page'] ?? 'home';
$user = getUser();
function increaseChapterView($chapter_id) {
    global $mysqli;
    $mysqli->query("UPDATE chapters SET views = IFNULL(views,0)+1 WHERE id = $chapter_id");
}
function addHistory($user_id, $chapter_id) {
    global $mysqli;
    $mysqli->query("INSERT INTO read_history(user_id, chapter_id, updated_at) VALUES ($user_id, $chapter_id, NOW())
        ON DUPLICATE KEY UPDATE updated_at = NOW()");
}
function getReadChapters($user_id, $comic_id = null) {
    global $mysqli;
    $q = "SELECT chapter_id FROM read_history WHERE user_id = $user_id";
    if ($comic_id) $q .= " AND chapter_id IN (SELECT id FROM chapters WHERE comic_id = $comic_id)";
    $res = $mysqli->query($q);
    $ids = [];
    if ($res) while($r = $res->fetch_assoc()) $ids[] = $r['chapter_id'];
    return $ids;
}
function getUnlockedChapters($user_id, $comic_id = null) {
    global $mysqli;
    $q = "SELECT chapter_id FROM chapter_unlocks WHERE user_id = $user_id";
    if ($comic_id) $q .= " AND chapter_id IN (SELECT id FROM chapters WHERE comic_id = $comic_id)";
    $res = $mysqli->query($q);
    $ids = [];
    if ($res) while($r = $res->fetch_assoc()) $ids[] = $r['chapter_id'];
    return $ids;
}
function getUserComicRating($user_id, $comic_id) {
    global $mysqli;
    $r = $mysqli->query("SELECT rating FROM comic_ratings WHERE user_id=$user_id AND comic_id=$comic_id")->fetch_assoc();
    return $r ? intval($r['rating']) : 0;
}
function displayChapterContent($ch) {
    echo '<div class="chapter-content">';
    echo '<h1 class="chapter-title">'.$ch['comic_title'].' - '.$ch['chapter_title'].'</h1>';
    $images = json_decode($ch['images'] ?? '[]', true);
    if ($images && is_array($images)) {
        echo '<div class="image-list">';
        foreach ($images as $img) {
            echo '<div class="image-item">';
            echo '<img src="'.htmlspecialchars($img).'" alt="Chapter Image" loading="lazy">';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<div class="notice warning">Chương này chưa có nội dung.</div>';
    }
    echo '<div class="chapter-nav">';
    echo '<a href="?page=comic&id='.$ch['comic_id'].'" class="primary-btn">Quay lại danh sách chương</a>';
    echo '</div>';
    echo '</div>';
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $csrf_token; ?>">
    <title><?php echo $page == 'home' ? 'MangaHub - Website Đọc Truyện Tranh Online' : 'MangaHub'; ?></title>
    <meta name="description" content="Website đọc truyện tranh online miễn phí với giao diện đẹp mắt và tính năng hiện đại">
    <meta name="keywords" content="truyện tranh, manga, comic, đọc truyện online, NetTruyen, TruyenQQ">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
    body {
        background: #16181d;
        color: #fff;
        margin: 0;
        font-family: "Segoe UI", Arial, Helvetica, sans-serif;
        min-height: 100vh;
    }
    a {
        color: #09f;
        text-decoration: none;
        transition: color 0.2s;
    }
    a:hover { color: #fff; }
    .header-area {
        background: #1c1e23;
        border-bottom: 2px solid #24262b;
        padding: 0;
    }
    .navbar-area {
        padding: 0 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 64px;
    }
    .logo {
        font-size: 1.7rem;
        font-weight: bold;
        color: #09f;
        letter-spacing: 2px;
        padding: 0 18px;
        background: linear-gradient(45deg, #09f 30%, #fff 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .menu-area {
        display: flex;
        align-items: center;
    }
    .menu {
        display: flex;
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .menu-item {
        margin-left: 24px;
    }
    .menu-link {
        color: #fff;
        padding: 8px 12px;
        border-radius: 4px;
        font-weight: 500;
        transition: background 0.2s;
    }
    .menu-link.active, .menu-link:hover {
        background: #262932;
        color: #09f;
    }
    .user-area, .auth-area {
        margin-left: 36px;
        display: flex;
        align-items: center;
    }
    .avatar, .user-avatar-img img {
        width: 36px; height: 36px;
        border-radius: 50%;
        border: 2px solid #09f;
        cursor: pointer;
        background: #222;
    }
    .dropdown-menu {
        display: none;
        position: absolute;
        right: 18px;
        top: 64px;
        background: #22242a;
        border: 1px solid #22293a;
        border-radius: 8px;
        min-width: 180px;
        z-index: 10;
        box-shadow: 0 6px 20px #0006;
    }
    .user-toggle:hover .dropdown-menu, .user-toggle:focus .dropdown-menu {
        display: block;
    }
    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        color: #fff;
        border: none;
        background: none;
        width: 100%;
        transition: background 0.15s;
    }
    .dropdown-item:hover {
        background: #09f2;
        color: #fff;
    }
    .primary-btn, .btn, .outline-btn {
        padding: 8px 20px;
        border: none;
        border-radius: 6px;
        font-weight: bold;
        background: linear-gradient(90deg, #0184d3, #096bf6);
        color: #fff;
        margin-right: 8px;
        cursor: pointer;
        transition: 0.15s;
        box-shadow: 0 2px 8px #0a3a;
    }
    .primary-btn.outline, .outline-btn {
        background: transparent;
        color: #09f;
        border: 2px solid #09f;
    }
    .primary-btn:hover, .btn:hover {
        background: linear-gradient(90deg, #096bf6 50%, #0184d3);
        color: #fff;
        transform: translateY(-2px) scale(1.03);
    }
    .container-fluid, .container {
        max-width: 1050px;
        margin: 0 auto;
        padding: 24px 16px;
    }
    .section-header, .section-title {
        margin-bottom: 16px;
        font-size: 1.5rem;
        color: #09f;
        font-weight: 700;
        letter-spacing: 1px;
    }
    .story-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(170px,1fr));
        gap: 18px;
    }
    .story-item {
        background: #20222a;
        border-radius: 7px;
        overflow: hidden;
        box-shadow: 0 2px 8px #0006;
        transition: transform .18s;
    }
    .story-item:hover { transform: translateY(-4px) scale(1.03); }
    .story-item .image img {
        width: 100%;
        height: 220px;
        object-fit: cover;
        border-bottom: 1px solid #24262b;
        background: #2a2c33;
    }
    .story-item .info {
        padding: 10px 11px 13px 11px;
    }
    .story-item .title {
        font-size: 1.08rem;
        font-weight: 600;
        margin: 0 0 8px 0;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .story-item .meta {
        font-size: 0.93rem;
        color: #aaa;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .notice, .alert {
        background: #23262f;
        color: #fff;
        padding: 13px 16px;
        border-radius: 5px;
        margin: 16px 0;
        font-size: 1rem;
        border-left: 4px solid #09f;
    }
    .notice.warning, .alert-danger { background: #ffb30020; color: #ffc107; border-left-color: #ffc107; }
    .notice.error { background: #d32f2f26; color: #ff6e6e; border-left-color:#d32f2f;}
    .notice.success { background: #43a04718; color: #43a047; border-left-color:#43a047;}
    .auth-form, .form-area {
        background: #21232b;
        border-radius: 10px;
        box-shadow: 0 2px 12px #0007;
        max-width: 380px;
        margin: 36px auto;
        padding: 32px 27px;
    }
    .form-title { color: #09f; font-size: 1.3rem; font-weight: bold; margin-bottom: 18px; }
    .form-group { margin-bottom: 18px; }
    .form-input, .form-control, select {
        width: 100%; padding: 10px 12px;
        border-radius: 6px;
        border: 1px solid #2e3038;
        background: #23252b;
        color: #fff;
        font-size: 1rem;
        transition: border 0.18s;
        outline: none;
    }
    .form-input:focus, .form-control:focus, select:focus {
        border: 1.5px solid #09f;
        background: #23293b;
    }
    .chapter-wrapper, .chapter-list {
        margin-top: 12px;
        background: #23242d;
        border-radius: 7px;
        padding: 18px 15px;
        box-shadow: 0 2px 8px #0005;
    }
    .chapter-item {
        padding: 10px 0;
        border-bottom: 1px solid #31313b;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .chapter-link, .chapter-info .name {
        color: #fff;
        font-weight: 500;
        font-size: 1.06rem;
        transition: color 0.15s;
    }
    .chapter-link:hover { color: #09f; }
    .profile-info {
        background: #21232b;
        border-radius: 10px;
        padding: 28px 25px;
        margin: 0 auto;
        max-width: 400px;
        box-shadow: 0 2px 10px #0005;
    }
    .profile-info .info-item {
        display: flex;
        justify-content: space-between;
        padding: 11px 0;
        border-bottom: 1px solid #282a33;
        font-size: 1.08rem;
    }
    .profile-info .info-item:last-child { border-bottom: none; }
    .profile-info .label { color: #aaa; }
    .profile-info .value { color: #fff; font-weight: 500; }
    .admin-nav {
        display: flex; gap: 16px; margin: 20px 0;
    }
    .tab-item {
        font-weight: bold;
        color: #aaa;
        background: #232631;
        padding: 11px 23px;
        border-radius: 8px 8px 0 0;
        border-bottom: 2px solid #232631;
        transition: all .15s;
    }
    .tab-item.active, .tab-item:hover {
        color: #09f;
        background: #191a1e;
        border-bottom: 2px solid #09f;
    }
    .section-content { background: #21232b; border-radius: 9px; padding: 21px 17px; margin-top: 0;}
    .list-table, .chapter-wrapper.admin {
        margin-top: 13px;
        background: #23242d;
        border-radius: 7px;
        padding: 17px 9px;
    }
    .list-item, .chapter-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #292b33;
    }
    .list-item:last-child, .chapter-item:last-child { border-bottom: none; }
    .item-info { display: flex; gap: 13px; align-items: center;}
    .item-thumb { width: 55px; height: 72px; object-fit: cover; border-radius: 4px; }
    .item-title { font-weight: bold; color: #fff; }
    .item-meta { color: #aaa; }
    .item-actions, .chapter-actions {
        display: flex; gap: 8px;
    }
    .action-btn {
        padding: 6px 14px; border-radius: 5px; background: #21232b;
        color: #09f; border: 1px solid #09f; font-weight: 500;
        transition: 0.13s;
    }
    .action-btn.edit { color: #fff; background: #09f; border: none;}
    .action-btn.delete { color: #ff5656; border: 1px solid #ff5656; background: #282a32;}
    .action-btn:hover { background: #09f; color: #fff; }
    .chapter-content { background: #23242c; border-radius: 10px; padding: 28px 17px; margin-bottom: 20px;}
    .chapter-title { color: #09f; font-size: 1.4rem; font-weight: bold; margin-bottom: 16px;}
    .image-list { display: flex; flex-wrap: wrap; gap: 12px; }
    .image-item img { width: 100%; max-width: 560px; border-radius: 6px; background: #191a1e;}
    .chapter-nav { margin-top: 18px;}
    .list-all-comments { background: #22242b; border-radius: 7px; margin-top: 16px; padding: 19px 13px;}
    .comment-section { background: #23242d; border-radius: 6px; margin-top: 12px; padding: 13px 19px; }
    .comment-item, .reply-item {
        display: flex; align-items: flex-start; gap: 11px; margin-bottom: 14px;
    }
    .comment-avatar img { width: 40px; height: 40px; border-radius: 50%; background: #222;}
    .reply-item .comment-avatar img { width: 30px; height: 30px;}
    .comment-content, .reply-content { background: #1d1e25; border-radius: 7px; padding: 8px 13px; width: 100%;}
    .comment-header { display: flex; align-items: center; gap: 12px; font-size: 0.93rem;}
    .comment-username { color: #09f; font-weight: 600;}
    .comment-time { color: #aaa;}
    .comment-text { margin-top: 6px; color: #eee;}
    .comment-actions { margin-top: 8px;}
    .btn-link { background: none; border: none; color: #09f; cursor: pointer; font-size: 0.92rem;}
    .reply-form { margin: 10px 0 10px 40px;}
    .comment-replies { margin-left: 40px; border-left: 2px solid #22293a; padding-left: 15px;}
    .no-comment { color: #aaa; font-style: italic; margin-top: 10px;}
    .vip-notice { background: #24273a; border-radius: 7px; padding: 28px 18px; margin: 16px 0;}
    .vip-notice .chapter-title { color: #f7c500;}
    .notice.warning, .vip-notice { border-left: 4px solid #f7c500;}
    .vip-cost { color: #f7c500; font-weight: bold; }
    .fa-crown.vip { color: #f7c500;}
    .unlock-box { margin-top: 13px;}
    .error-text { color: #ff4a4a;}
    @media (max-width: 700px) {
        .navbar-area, .container-fluid, .container { padding: 11px 4vw;}
        .story-list { grid-template-columns: repeat(2, 1fr);}
        .chapter-wrapper, .chapter-content { padding: 11px 7px;}
        .auth-form, .form-area, .profile-info { padding: 17px 6vw; }
    }
    #loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 9999;
    transition: opacity 0.3s;
}

.loading-spinner {
    text-align: center;
}

.spinner {
    width: 50px;
    height: 50px;
    border: 3px solid transparent;
    border-top-color: #09f;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

.loading-text {
    color: #fff;
    margin-top: 10px;
    font-size: 1.1em;
}

.vip-notice {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #1c1e23;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 0 20px rgba(0,0,0,0.5);
    z-index: 1000;
    animation: fadeIn 0.3s ease;
}

.vip-content {
    text-align: center;
}

.vip-content h3 {
    color: #f44336;
    margin-bottom: 10px;
}

.notice.error {
    background: linear-gradient(45deg, #f44336, #d32f2f);
    color: #fff;
}

.fade-out {
    opacity: 0;
    transition: opacity 0.3s;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translate(-50%, -60%); }
    to { opacity: 1; transform: translate(-50%, -50%); }
}
.tag-translator { background: #43a047; color: #fff; border-radius: 4px; font-size: 0.88em; padding: 1px 6px; margin-left: 6px;}
.comment-realm { color:#f7c500; font-size:0.92em; padding-left:4px;}
    </style>
</head>
<body>
<header class="header-area">
    <div class="navbar-area">
        <a href="?page=home" class="logo">ComicHub</a>
        <div class="menu-area">
            <ul class="menu">
                <li class="menu-item">
                    <a href="?page=home" class="menu-link'.($page=='home'?' active':'').'">Trang chủ</a>
                </li>
                <li class="menu-item">
                    <a href="?page=fav" class="menu-link'.($page=='fav'?' active':'').'">Theo dõi</a>
                </li>
                <li class="menu-item">
                    <a href="?page=history" class="menu-link'.($page=='history'?' active':'').'">Lịch sử</a>
                </li>
            </ul>';

if ($user) {
    echo '<div class="user-area">
            <div class="user-toggle" tabindex="0" style="position:relative;">
                <img src="https://via.placeholder.com/150?text=Avatar" class="avatar">
                <div class="dropdown-menu" id="menu">
                    <a href="?page=profile" class="dropdown-item">
                        <i class="fa-solid fa-user"></i> Thông tin
                    </a>
                    <a href="?page=fav" class="dropdown-item">
                        <i class="fa-solid fa-heart"></i> Theo dõi
                    </a>
                    <a href="?page=history" class="dropdown-item">
                        <i class="fa-solid fa-clock-rotate-left"></i> Lịch sử
                    </a>';
    if (in_array($user['role'], ['admin', 'translator'])) {
        echo '<a href="?page=admin" class="dropdown-item">
                <i class="fa-solid fa-gear"></i> Quản trị
              </a>';
    }
    echo '<a href="?page=logout" class="dropdown-item">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Đăng xuất
          </a>
          </div>
          </div>';
} else {
    echo '<div class="auth-area">
            <a href="?page=login" class="primary-btn outline">Đăng nhập</a>
            <a href="?page=register" class="primary-btn">Đăng ký</a>
          </div>';
}
echo '</div>
    </div>
</header>
<main class="main-area">
    <div class="container-fluid">';
    
    // ============= TIẾP TỤC XỬ LÝ PAGE ===============
if ($page == 'home') {
    echo '<div class="section-header">
            <h2 class="section-title">Truyện Mới Nhất</h2>
          </div>';
    echo '<div class="story-list">';
    $res = $mysqli->query("SELECT * FROM comics ORDER BY updated_at DESC LIMIT 18");
    if ($res && $res->num_rows > 0) {
        while($c = $res->fetch_assoc()) {
            $thumb = $c['thumbnail'] ?? 'https://via.placeholder.com/200x270?text=No+Image';
            echo '<div class="story-item">
                    <a href="?page=comic&id='.$c['id'].'" class="item-link">
                        <div class="image">
                            <img src="'.htmlspecialchars($thumb).'" alt="thumb">
                        </div>
                        <div class="info">
                            <h3 class="title">'.htmlspecialchars($c['title']).'</h3>
                            <div class="meta">
                                <span class="time">'.date('d/m/Y', strtotime($c['updated_at'])).'</span>
                            </div>
                        </div>
                    </a>
                  </div>';
        }
    } else {
        echo '<div class="notice warning">Chưa có truyện nào được đăng.</div>';
    }
    echo '</div>';
}
elseif ($page == 'login') {
    if ($_POST) {
        $u = $mysqli->real_escape_string($_POST['username']);
        $p = md5($_POST['password']);
        $user_result = $mysqli->query("SELECT * FROM users WHERE username='$u' AND password='$p'")->fetch_assoc();
        if ($user_result) { 
            $_SESSION['user_id'] = $user_result['id']; 
            echo "<script>window.location.href = 'index.php?page=home';</script>";
            exit;
        }
        echo '<div class="notice error">Tên đăng nhập hoặc mật khẩu không đúng!</div>';
    }
    echo '<div class="auth-form">
            <h1 class="form-title">Đăng Nhập</h1>
            <form method="POST">
                <div class="form-group">
                    <input name="username" type="text" class="form-input" placeholder="Tên đăng nhập" required>
                </div>
                <div class="form-group">
                    <input name="password" type="password" class="form-input" placeholder="Mật khẩu" required>
                </div>
                <button type="submit" class="primary-btn">Đăng Nhập</button>
            </form>
          </div>';
}
elseif ($page == 'register') {
    if ($_POST) {
        $u = $mysqli->real_escape_string($_POST['username']);
        $p = md5($_POST['password']);
        $check = $mysqli->query("SELECT id FROM users WHERE username='$u'")->num_rows;
        if ($check > 0) {
            echo '<div class="notice error">Tên đăng nhập đã tồn tại!</div>';
        } else {
            $mysqli->query("INSERT INTO users(username,password) VALUES('$u','$p')");
            echo "<script>window.location.href = 'index.php?page=login';</script>";
            exit;
        }
    }
    echo '<div class="auth-form">
            <h1 class="form-title">Đăng Ký</h1>
            <form method="POST">
                <div class="form-group">
                    <input name="username" type="text" class="form-input" placeholder="Tên đăng nhập" required>
                </div>
                <div class="form-group">
                    <input name="password" type="password" class="form-input" placeholder="Mật khẩu" required>
                </div>
                <button type="submit" class="primary-btn">Đăng Ký</button>
            </form>
          </div>';
}
elseif ($page == 'logout') { 
    unset($_SESSION['user_id']); 
    echo "<script>window.location.href = 'index.php?page=home';</script>";
    exit; 
}
elseif ($page == 'fav') {
    echo '<div class="section-header">
            <h2 class="section-title">Danh Sách Theo Dõi</h2>
          </div>';
    if (!$user) {
        echo '<div class="notice warning">Bạn cần đăng nhập để xem danh sách theo dõi.</div>';
    } else {
        $res = $mysqli->query("SELECT comics.* FROM user_favorites 
            JOIN comics ON comics.id = user_favorites.comic_id 
            WHERE user_favorites.user_id = {$user['id']}");
        if ($res && $res->num_rows > 0) {
            echo '<div class="story-list">';
            while($c = $res->fetch_assoc()) {
                $thumb = $c['thumbnail'] ?? 'https://via.placeholder.com/200x270?text=No+Image';
                echo '<div class="story-item">
                        <a href="?page=comic&id='.$c['id'].'" class="item-link">
                            <div class="image">
                                <img src="'.htmlspecialchars($thumb).'" alt="thumb">
                            </div>
                            <div class="info">
                                <h3 class="title">'.htmlspecialchars($c['title']).'</h3>
                                <div class="meta">
                                    <span class="time">'.date('d/m/Y', strtotime($c['updated_at'])).'</span>
                                </div>
                            </div>
                        </a>
                      </div>';
            }
            echo '</div>';
        } else {
            echo '<div class="notice warning">Bạn chưa theo dõi truyện nào.</div>';
        }
    }
}
elseif ($page == 'history') {
    echo '<div class="section-header">
            <h2 class="section-title">Lịch Sử Đọc</h2>
          </div>';
    if (!$user) {
        echo '<div class="notice warning">Bạn cần đăng nhập để xem lịch sử đọc.</div>';
    } else {
        $res = $mysqli->query(
            "SELECT chapters.id as ch_id, chapters.chapter_title, chapters.comic_id, comics.title AS comic_title, read_history.updated_at
            FROM read_history 
            JOIN chapters ON chapters.id = read_history.chapter_id
            JOIN comics ON comics.id = chapters.comic_id
            WHERE read_history.user_id = {$user['id']}
            ORDER BY read_history.updated_at DESC LIMIT 50"
        );
        if ($res && $res->num_rows > 0) {
            echo '<div class="chapter-wrapper">';
            while($r = $res->fetch_assoc()) {
                echo '<div class="chapter-item">
                        <a href="?page=chapter&id='.$r['ch_id'].'" class="chapter-link">
                            <span class="name">'.$r['comic_title'].' - '.$r['chapter_title'].'</span>
                            <span class="time">'.date('d/m/Y H:i', strtotime($r['updated_at'])).'</span>
                        </a>
                      </div>';
            }
            echo '</div>';
        } else {
            echo '<div class="notice warning">Bạn chưa đọc chương nào.</div>';
        }
    }
}
elseif ($page == 'profile') {
    echo '<div class="section-header">
            <h2 class="section-title">Thông Tin Cá Nhân</h2>
          </div>';
    echo '<div class="profile-info">
            <div class="info-item">
                <span class="label">Tên đăng nhập:</span>
                <span class="value">'.htmlspecialchars($user['username']).'</span>
            </div>
            <div class="info-item">
                <span class="label">Vai trò:</span>
                <span class="value">'.ucfirst($user['role']).'</span>
            </div>
            <div class="info-item">
                <span class="label">Số xu:</span>
                <span class="value">'.number_format($user['coins'] ?? 0).' xu</span>
            </div>
 <div class="info-item">
    <span class="label">Cảnh giới:</span>
    <span class="value">'.htmlspecialchars($user['realm']).' - '.($user['realm_stage']==10?'Đỉnh phong':$user['realm_stage']).'/10</span>
          </div>';
}
// ... (TIẾP: admin, comic, chapter page và JS cuối file)
elseif ($page == 'admin') {
    requireRole(['admin','translator']);
    echo '<div class="section-header">
            <h2 class="section-title">Quản Lý Hệ Thống</h2>
          </div>';
    $action = $_GET['action'] ?? '';
    echo '<div class="admin-nav">
            <a href="?page=admin&action=comic" class="tab-item '.($action=='comic'?'active':'').'">Quản lý truyện</a>
            <a href="?page=admin&action=chapter" class="tab-item '.($action=='chapter'?'active':'').'">Quản lý chương</a>
          </div>';
    if ($action == 'comic') {
        echo '<div class="section-content">';
        if ($_POST && isset($_POST['title'])) {
            $title = $mysqli->real_escape_string($_POST['title']);
            $description = $mysqli->real_escape_string($_POST['description'] ?? '');
            $thumbnail = $mysqli->real_escape_string($_POST['thumbnail'] ?? '');
            $author = $mysqli->real_escape_string($_POST['author'] ?? '');
            $status = $mysqli->real_escape_string($_POST['status'] ?? '');
            $genres = $mysqli->real_escape_string($_POST['genres'] ?? '');
            $mysqli->query("INSERT INTO comics(title,description,thumbnail,author,status,genres,created_by) 
                          VALUES('$title','$description','$thumbnail','$author','$status','$genres',{$user['id']})");
            echo '<div class="notice success">Đã thêm truyện mới!</div>';
        }
        echo '<form method="POST" class="form-area">
                <div class="form-group">
                    <input name="title" class="form-input" placeholder="Tên truyện" required>
                </div>
                <div class="form-group">
                    <input name="author" class="form-input" placeholder="Tác giả">
                </div>
                <div class="form-group">
                    <input name="thumbnail" class="form-input" placeholder="Link ảnh bìa (nên có)">
                </div>
                <div class="form-group">
                    <select name="status" class="form-input">
                        <option value="ongoing">Đang tiến hành</option>
                        <option value="completed">Hoàn thành</option>
                        <option value="dropped">Tạm ngưng</option>
                    </select>
                </div>
                <div class="form-group">
                    <input name="genres" class="form-input" placeholder="Thể loại (ngăn cách bằng dấu phẩy)">
                </div>
                <div class="form-group">
                    <textarea name="description" class="form-input" placeholder="Mô tả truyện" rows="4"></textarea>
                </div>
                <button type="submit" class="primary-btn">Thêm Truyện</button>
              </form>';
        $query = $user['role'] === 'admin' ? 
                "SELECT * FROM comics ORDER BY id DESC" : 
                "SELECT * FROM comics WHERE created_by={$user['id']} ORDER BY id DESC";
        $res = $mysqli->query($query);
        if ($res && $res->num_rows > 0) {
            echo '<div class="list-table">';
            while($r = $res->fetch_assoc()) {
                $thumb = $r['thumbnail'] ?? 'https://via.placeholder.com/100x130?text=No+Image';
                echo '<div class="list-item">
                        <div class="item-info">
                            <img src="'.htmlspecialchars($thumb).'" alt="thumb" class="item-thumb">
                            <div class="item-detail">
                                <h4 class="item-title">'.htmlspecialchars($r['title']).'</h4>
                                <p class="item-meta">'.htmlspecialchars($r['author'] ?: 'Chưa có tác giả').'</p>
                            </div>
                        </div>
                        <div class="item-actions">
                            <a href="?page=admin&action=edit&id='.$r['id'].'" class="action-btn edit">Sửa</a>
                            <a href="?page=admin&action=chapter&comic='.$r['id'].'" class="action-btn">Chương</a>
                            <a href="?page=admin&action=del&id='.$r['id'].'" class="action-btn delete" 
                               onclick="return confirm(\'Bạn có chắc muốn xóa?\')">Xóa</a>
                        </div>
                      </div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    elseif ($action == 'chapter') {
        $comic_id = isset($_GET['comic']) ? (int)$_GET['comic'] : 0;
        echo '<div class="section-content">';
        if ($comic_id) {
            $comic = $mysqli->query("SELECT title FROM comics WHERE id=$comic_id")->fetch_assoc();
            echo '<div class="section-header">
                    <h3 class="section-title">Quản Lý Chương: '.htmlspecialchars($comic['title']).'</h3>
                  </div>';
        }
        if ($_POST && isset($_POST['comic_id'])) {
            $post_comic_id = (int)$_POST['comic_id'];
            $title = $mysqli->real_escape_string($_POST['title']);
            $is_vip = isset($_POST['is_vip']) ? 1 : 0;
            $coins = (int)($_POST['coins_unlock'] ?? 0);
            $images = isset($_POST['images']) ? json_encode(array_filter(explode("\n", $_POST['images']))) : '[]';
            $mysqli->query("INSERT INTO chapters(comic_id,chapter_title,is_vip,coins_unlock,images) 
                          VALUES($post_comic_id,'$title',$is_vip,$coins,'$images')");
            echo '<div class="notice success">Đã thêm chương mới!</div>';
        }
        $comic_query = $user['role'] === 'admin' ? 
                      "SELECT * FROM comics" : 
                      "SELECT * FROM comics WHERE created_by={$user['id']}";
        $comics = $mysqli->query($comic_query);
        echo '<form method="POST" class="form-area">
                <div class="form-group">
                    <select name="comic_id" class="form-input" required '.($comic_id ? 'disabled' : '').'>
                        <option value="">Chọn truyện</option>';
        if ($comics) {
            while($comic = $comics->fetch_assoc()) {
                $selected = $comic_id == $comic['id'] ? 'selected' : '';
                echo '<option value="'.$comic['id'].'" '.$selected.'>'.htmlspecialchars($comic['title']).'</option>';
            }
        }
        echo '</select>';
        if ($comic_id) {
            echo '<input type="hidden" name="comic_id" value="'.$comic_id.'">';
        }
        echo '</div>
              <div class="form-group">
                <input name="title" class="form-input" placeholder="Tên chương" required>
              </div>
              <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_vip" value="1" class="checkbox-input" 
                           onchange="document.getElementById(\'coins\').style.display=this.checked?\'block\':\'none\'">
                    <span class="checkbox-text">Chương VIP</span>
                </label>
              </div>
              <div id="coins" class="form-group" style="display:none">
                <input name="coins_unlock" type="number" class="form-input" placeholder="Số xu cần để mở khóa" min="0">
              </div>
              <div class="form-group">
                <textarea name="images" class="form-input" placeholder="Link ảnh mỗi dòng một link" rows="5"></textarea>
              </div>
              <button type="submit" class="primary-btn">Thêm Chương</button>
            </form>';
        $chapter_query = "SELECT chapters.*, comics.title AS comic_title 
                         FROM chapters 
                         JOIN comics ON comics.id = chapters.comic_id 
                         WHERE " . ($comic_id ? "chapters.comic_id = $comic_id AND " : "") . 
                         "(comics.created_by = {$user['id']} OR '{$user['role']}' = 'admin') 
                         ORDER BY chapters.id DESC";
        $res = $mysqli->query($chapter_query);
        if ($res && $res->num_rows > 0) {
            echo '<div class="chapter-wrapper admin">';
            while($r = $res->fetch_assoc()) {
                echo '<div class="chapter-item">
                        <div class="chapter-info">
                            <span class="name">'.($comic_id ? '' : htmlspecialchars($r['comic_title']).' - ').
                            htmlspecialchars($r['chapter_title']).
                            ($r['is_vip'] ? ' <i class="fa-solid fa-crown vip"></i>' : '').'</span>
                            '.($r['is_vip'] ? '<span class="vip-cost">'.$r['coins_unlock'].' xu</span>' : '').'
                        </div>
                        <div class="chapter-actions">
                            <a href="?page=chapter&id='.$r['id'].'" class="action-btn">Xem</a>
                            <a href="?page=admin&action=edit_chapter&id='.$r['id'].'" class="action-btn edit">Sửa</a>
                            <a href="?page=admin&action=del_chapter&id='.$r['id'].'" class="action-btn delete" 
                               onclick="return confirm(\'Bạn có chắc muốn xóa?\')">Xóa</a>
                        </div>
                      </div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    elseif ($action == 'edit') {
        $id = (int)$_GET['id'];
        if ($_POST && isset($_POST['title'])) {
            $title = $mysqli->real_escape_string($_POST['title']);
            $description = $mysqli->real_escape_string($_POST['description'] ?? '');
            $thumbnail = $mysqli->real_escape_string($_POST['thumbnail'] ?? '');
            $author = $mysqli->real_escape_string($_POST['author'] ?? '');
            $status = $mysqli->real_escape_string($_POST['status'] ?? '');
            $genres = $mysqli->real_escape_string($_POST['genres'] ?? '');
            $mysqli->query("UPDATE comics SET 
                          title='$title', 
                          description='$description', 
                          thumbnail='$thumbnail',
                          author='$author',
                          status='$status',
                          genres='$genres'
                          WHERE id=$id");
            echo '<div class="notice success">Đã cập nhật truyện!</div>';
        }
        $c = $mysqli->query("SELECT * FROM comics WHERE id=$id")->fetch_assoc();
        if ($c) {
            echo '<div class="section-content">
                    <h3 class="section-title">Sửa Truyện</h3>
                    <form method="POST" class="form-area">
                        <div class="form-group">
                            <input name="title" class="form-input" value="'.htmlspecialchars($c['title']).'" required>
                        </div>
                        <div class="form-group">
                            <input name="author" class="form-input" value="'.htmlspecialchars($c['author'] ?? '').'" placeholder="Tác giả">
                        </div>
                        <div class="form-group">
                            <input name="thumbnail" class="form-input" value="'.htmlspecialchars($c['thumbnail'] ?? '').'" placeholder="Link ảnh bìa">
                        </div>
                        <div class="form-group">
                            <select name="status" class="form-input">
                                <option value="ongoing" '.($c['status']=='ongoing'?'selected':'').'>Đang tiến hành</option>
                                <option value="completed" '.($c['status']=='completed'?'selected':'').'>Hoàn thành</option>
                                <option value="dropped" '.($c['status']=='dropped'?'selected':'').'>Tạm ngưng</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <input name="genres" class="form-input" value="'.htmlspecialchars($c['genres'] ?? '').'" placeholder="Thể loại (ngăn cách bằng dấu phẩy)">
                        </div>
                        <div class="form-group">
                            <textarea name="description" class="form-input" placeholder="Mô tả truyện" rows="4">'.htmlspecialchars($c['description'] ?? '').'</textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="primary-btn">Cập Nhật</button>
                            <a href="?page=admin&action=comic" class="outline-btn">Hủy</a>
                        </div>
                    </form>
                  </div>';
        }
    }
    elseif ($action == 'edit_chapter') {
        $id = (int)$_GET['id'];
        if ($_POST && isset($_POST['title'])) {
            $title = $mysqli->real_escape_string($_POST['title']);
            $is_vip = isset($_POST['is_vip']) ? 1 : 0;
            $coins = (int)($_POST['coins_unlock'] ?? 0);
            $images = isset($_POST['images']) ? json_encode(array_filter(explode("\n", $_POST['images']))) : '[]';
            $mysqli->query("UPDATE chapters SET 
                          chapter_title='$title',
                          is_vip=$is_vip,
                          coins_unlock=$coins,
                          images='$images'
                          WHERE id=$id");
            echo '<div class="notice success">Đã cập nhật chương!</div>';
        }
        $ch = $mysqli->query("SELECT chapters.*, comics.title AS comic_title 
                            FROM chapters 
                            JOIN comics ON comics.id = chapters.comic_id 
                            WHERE chapters.id=$id")->fetch_assoc();
        if ($ch) {
            echo '<div class="section-content">
                    <h3 class="section-title">Sửa Chương: '.htmlspecialchars($ch['comic_title']).'</h3>
                    <form method="POST" class="form-area">
                        <div class="form-group">
                            <input name="title" class="form-input" value="'.htmlspecialchars($ch['chapter_title']).'" required>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_vip" value="1" class="checkbox-input" '.($ch['is_vip']?'checked':'').'
                                       onchange="document.getElementById(\'coins\').style.display=this.checked?\'block\':\'none\'">
                                <span class="checkbox-text">Chương VIP</span>
                            </label>
                        </div>
                        <div id="coins" class="form-group" style="display:'.($ch['is_vip']?'block':'none').'">
                            <input name="coins_unlock" type="number" class="form-input" value="'.$ch['coins_unlock'].'" 
                                   placeholder="Số xu cần để mở khóa" min="0">
                        </div>
                        <div class="form-group">
                            <textarea name="images" class="form-input" placeholder="Link ảnh mỗi dòng một link" rows="5">'.
                            implode("\n", json_decode($ch['images'] ?? '[]', true)).'</textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="primary-btn">Cập Nhật</button>
                            <a href="?page=admin&action=chapter&comic='.$ch['comic_id'].'" class="outline-btn">Hủy</a>
                        </div>
                    </form>
                  </div>';
        }
    }
    elseif ($action == 'del') {
        $id = (int)$_GET['id'];
        $mysqli->query("DELETE FROM comics WHERE id=$id");
        echo '<div class="notice success">Đã xóa truyện!</div>';
        echo '<script>setTimeout(() => window.location.href="?page=admin&action=comic", 1500);</script>';
    }
    elseif ($action == 'del_chapter') {
        $id = (int)$_GET['id'];
        $ch = $mysqli->query("SELECT comic_id FROM chapters WHERE id=$id")->fetch_assoc();
        $mysqli->query("DELETE FROM chapters WHERE id=$id");
        echo '<div class="notice success">Đã xóa chương!</div>';
        echo '<script>setTimeout(() => window.location.href="?page=admin&action=chapter&comic='.$ch['comic_id'].'", 1500);</script>';
    }
}
elseif ($page == 'comic') {
    $id = (int)$_GET['id'];
    $c = $mysqli->query("SELECT * FROM comics WHERE id=$id")->fetch_assoc();
    if (!$c) {
        echo '<div class="alert alert-danger">Không tìm thấy truyện!</div>';
    } else {
        // Update view count
        $mysqli->query("UPDATE comics SET views=views+1 WHERE id=$id");
        $c['views']++;
        // Handle follow/unfollow
        if ($user && isset($_GET['follow'])) {
            $isFollowed = $mysqli->query("SELECT 1 FROM user_favorites WHERE user_id={$user['id']} AND comic_id=$id")->num_rows;
            if ($_GET['follow'] == '1' && !$isFollowed) {
                $mysqli->query("INSERT IGNORE INTO user_favorites(user_id, comic_id) VALUES({$user['id']}, $id)");
                $mysqli->query("UPDATE comics SET follows=follows+1 WHERE id=$id");
                echo "<script>window.location.href='?page=comic&id=$id';</script>";
                exit;
            }
            if ($_GET['follow'] == '0' && $isFollowed) {
                $mysqli->query("DELETE FROM user_favorites WHERE user_id={$user['id']} AND comic_id=$id");
                $mysqli->query("UPDATE comics SET follows=GREATEST(follows-1,0) WHERE id=$id");
                echo "<script>window.location.href='?page=comic&id=$id';</script>";
                exit;
            }
        }
        $isFollowed = $user ? $mysqli->query("SELECT 1 FROM user_favorites WHERE user_id={$user['id']} AND comic_id=$id")->num_rows : false;
        // Handle rating
        if ($user && isset($_POST['rating']) && is_numeric($_POST['rating']) && $_POST['rating'] >= 1 && $_POST['rating'] <= 5) {
            $rating = intval($_POST['rating']);
            $old = $mysqli->query("SELECT rating FROM comic_ratings WHERE user_id={$user['id']} AND comic_id=$id")->fetch_assoc();
            if ($old) {
                $mysqli->query("UPDATE comic_ratings SET rating=$rating WHERE user_id={$user['id']} AND comic_id=$id");
            } else {
                $mysqli->query("INSERT INTO comic_ratings (user_id, comic_id, rating) VALUES ({$user['id']}, $id, $rating)");
            }
            $avg = $mysqli->query("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM comic_ratings WHERE comic_id=$id")->fetch_assoc();
            $mysqli->query("UPDATE comics SET rating=".round($avg['avg'],2).", total_rating={$avg['cnt']} WHERE id=$id");
            $c['rating'] = round($avg['avg'], 2);
            $c['total_rating'] = $avg['cnt'];
        }
        $rating_avg = $c['total_rating'] > 0 ? round($c['rating'],1) : 0;
        $rating_count = intval($c['total_rating']);
        $userRating = ($user) ? getUserComicRating($user['id'], $id) : 0;

        echo '<div class="section-header">
                <h2 class="section-title">'.htmlspecialchars($c['title']).'</h2>
              </div>';
        echo '<div class="chapter-content">';
        echo '<div style="display: flex;flex-wrap:wrap;gap:20px">';
        echo '<div style="min-width:200px;max-width:230px;"><img src="'.htmlspecialchars($c['thumbnail'] ?? 'https://via.placeholder.com/200x270?text=No+Image').'" style="width:100%;border-radius:8px;background:#222;"></div>';
        echo '<div style="flex:1 1 240px">';
        echo '<div><b>Tác giả:</b> '.htmlspecialchars($c['author'] ?: 'Đang cập nhật').'</div>';
        echo '<div><b>Trạng thái:</b> '.ucfirst($c['status']).'</div>';
        echo '<div><b>Lượt xem:</b> '.number_format($c['views']).'</div>';
        echo '<div><b>Lượt theo dõi:</b> '.number_format($c['follows']).'</div>';
        echo '<div><b>Thể loại:</b> '.htmlspecialchars($c['genres']).'</div>';
        echo '<div><b>Đánh giá:</b> <span style="color:#f7c500">'.($rating_avg).'</span> ('.($rating_count).' lượt)';
        if ($user) {
            echo '<form method="POST" style="display:inline-block;margin-left:10px;">
            <select name="rating" class="form-input" style="display:inline-block;width:auto;height:30px;padding:0 10px;vertical-align:middle;">';
            for($i=1;$i<=5;$i++) echo '<option '.($userRating==$i?'selected':'').' value="'.$i.'">'.$i.'</option>';
            echo '</select>
            <button type="submit" class="primary-btn" style="padding:4px 10px">Đánh giá</button>
            </form>';
        }
        echo '</div>';
        echo '</div></div>';
        echo '<div style="margin-top:20px"><b>Mô tả:</b> '.nl2br(htmlspecialchars($c['description'])).'</div>';
        if ($user) {
            if ($isFollowed) {
                echo '<a href="?page=comic&id='.$id.'&follow=0" class="btn unfollow-btn">Bỏ theo dõi</a>';
            } else {
                echo '<a href="?page=comic&id='.$id.'&follow=1" class="btn follow-btn">Theo dõi</a>';
            }
        } else {
            echo '<a href="?page=login" class="btn follow-btn">Theo dõi</a>';
        }
        echo '</div>';

        // Danh sách chương
        echo '<div class="section-header"><h2 class="section-title">Danh Sách Chương</h2></div>';
        $chapters = $mysqli->query("SELECT * FROM chapters WHERE comic_id=$id ORDER BY id DESC");
        if ($chapters && $chapters->num_rows > 0) {
            echo '<div class="chapter-wrapper">';
            while($ch = $chapters->fetch_assoc()) {
                echo '<div class="chapter-item">
                        <a href="?page=chapter&id='.$ch['id'].'" class="chapter-link">
                            <span class="name">'.$ch['chapter_title'].'</span>
                            <span class="time">'.getTimeAgo($ch['created_at']).'</span>
                        </a>
                      </div>';
            }
            echo '</div>';
        } else {
            echo '<div class="notice warning">Chưa có chương nào.</div>';
        }

       echo '<div class="section-header" style="margin-top:25px"><h2 class="section-title">Bình luận</h2></div>';
        echo '<div class="comment-section">';
        if ($user) {
            echo '<form method="POST">
                    <input type="hidden" name="csrf_token" value="'.$_SESSION['csrf_token'].'">
                    <textarea name="content" class="form-input" rows="2" placeholder="Nội dung bình luận..." required></textarea>
                    <button type="submit" name="post_comment" class="primary-btn">Bình luận</button>
                  </form>';
            // Xử lý gửi bình luận mới
            if (isset($_POST['post_comment']) && validateCSRFToken($_POST['csrf_token'])) {
                $content = trim($_POST['content']);
                if ($content !== '') {
                    $check = canPostComment($user['id']);
                    if ($check['allowed']) {
                        $mysqli->query("INSERT INTO comments(user_id,comic_id,content,created_at) VALUES ({$user['id']},$id,'".$mysqli->real_escape_string($content)."',NOW())");
                        echo '<div class="notice success">Đã đăng bình luận.</div><script>setTimeout(()=>location.reload(),800);</script>';
                    } else {
                        echo '<div class="notice warning">'.$check['message'].'</div>';
                    }
                }
            }
            // Xử lý gửi trả lời bình luận
            if (isset($_POST['post_reply']) && validateCSRFToken($_POST['csrf_token'])) {
                $reply_content = trim($_POST['reply_content']);
                $parent_id = (int)$_POST['parent_id'];
                if ($reply_content !== '' && $parent_id > 0) {
                    $check = canPostComment($user['id']);
                    if ($check['allowed']) {
                        $mysqli->query("INSERT INTO comments(user_id,comic_id,parent_id,content,created_at) VALUES ({$user['id']},$id,$parent_id,'".$mysqli->real_escape_string($reply_content)."',NOW())");
                        echo '<div class="notice success">Đã trả lời bình luận.</div><script>setTimeout(()=>location.reload(),800);</script>';
                    } else {
                        echo '<div class="notice warning">'.$check['message'].'</div>';
                    }
                }
            }
        } else {
            echo '<div class="notice warning">Bạn cần <a href="?page=login">đăng nhập</a> để bình luận.</div>';
        }
        displayComments($id);
        echo '</div>'; // comment-section
    }
}

elseif ($page == 'chapter') {
    if (!$user) {
        echo '<div class="notice warning">Bạn cần <a href="?page=login">đăng nhập</a> để đọc chương.</div>';
    } else {
        $id = (int)$_GET['id'];
        $ch = $mysqli->query("SELECT chapters.*, comics.title as comic_title, comics.id as comic_id 
                            FROM chapters 
                            JOIN comics ON comics.id = chapters.comic_id 
                            WHERE chapters.id=$id")->fetch_assoc();
        if (!$ch) {
            echo '<div class="notice error">Không tìm thấy chương!</div>';
        } else {
            // Tăng view chương
            increaseChapterView($id);
            // Thêm lịch sử đọc
            addHistory($user['id'], $id);
            // Xử lý tăng cấp cảnh giới
            handleLevelUp($user['id']);

            // Kiểm tra mở khóa
            $unlock = $ch['is_vip'] ? 
                     $mysqli->query("SELECT 1 FROM chapter_unlocks WHERE user_id={$user['id']} AND chapter_id=$id")->num_rows : 
                     true;

            if ($ch['is_vip'] && !$unlock) {
                if (!isset($_POST['unlock'])) {
                    echo '<div class="vip-notice">
                            <h1 class="chapter-title">'.htmlspecialchars($ch['comic_title']).' - '.htmlspecialchars($ch['chapter_title']).' <span class="fa-crown vip">👑</span></h1>
                            <div class="notice warning">Chương VIP - Cần mở khóa để đọc</div>
                            <div class="unlock-box">
                                <p>Chương này yêu cầu <strong>'.$ch['coins_unlock'].' xu</strong> để mở khóa.</p>
                                <p>Bạn hiện có: <strong>'.($user['coins'] ?? 0).' xu</strong></p>';
                    if (($user['coins'] ?? 0) >= $ch['coins_unlock']) {
                        echo '<form method="POST">
                                <button type="submit" name="unlock" class="primary-btn">
                                    <span class="fa-unlock">🔓</span> Mở khóa chương
                                </button>
                              </form>';
                    } else {
                        echo '<p class="error-text">Không đủ xu để mở khóa chương này.</p>';
                    }
                    echo '</div>
                          </div>';
                } else {
                    if (($user['coins'] ?? 0) < $ch['coins_unlock']) {
                        echo '<div class="notice error">Không đủ xu để mở khóa chương này.</div>';
                    } else {
                        $mysqli->query("UPDATE users SET coins = coins - {$ch['coins_unlock']} WHERE id = {$user['id']}");
                        $mysqli->query("INSERT INTO chapter_unlocks (user_id, chapter_id) VALUES ({$user['id']}, $id)");
                        echo '<div class="notice success">Đã mở khóa chương thành công!</div>';
                        echo '<script>setTimeout(() => window.location.reload(), 1500);</script>';
                    }
                }
            } else {
                // Nội dung chương
                displayChapterContent($ch);

                // Điều hướng chương
                $prev = getPrevChapter($ch['comic_id'], $ch['id']);
                $next = getNextChapter($ch['comic_id'], $ch['id']);
                echo "<div class='chapter-nav'>";
                if ($prev) echo "<a href='?page=chapter&id=$prev' class='primary-btn outline'>← Chương trước</a>";
                if ($next) echo "<a href='?page=chapter&id=$next' class='primary-btn'>Chương sau →</a>";
                echo "</div>";

                // Tải trước ảnh chương sau (nếu có)
                if ($next) {
                    $chNext = $mysqli->query("SELECT images FROM chapters WHERE id=$next")->fetch_assoc();
                    if ($chNext && $chNext['images']) {
                        $imgs = json_decode($chNext['images'], true);
                        if ($imgs && is_array($imgs)) {
                            echo "<div style='display:none'>";
                            foreach ($imgs as $img) {
                                echo "<img src='".htmlspecialchars($img)."' loading='lazy'>";
                            }
                            echo "</div>";
                        }
                    }
                }
            }
        }
    }
}
echo '</div></main>
<script>
function toggleMenu() {
    let menu = document.getElementById("menu");
    menu.style.display = menu.style.display==="block" ? "none" : "block";
}
document.addEventListener("click", function(e) {
    const menu = document.getElementById("menu");
    const avatar = document.querySelector(".avatar");
    if (menu && !menu.contains(e.target) && e.target!==avatar) {
        menu.style.display = "none";
    }
});
function showReplyForm(id) {
    let f = document.getElementById("reply-form-"+id);
    if(f) f.style.display = (f.style.display==="block"?"none":"block");
}
</script>
</body>
</html>';
?>