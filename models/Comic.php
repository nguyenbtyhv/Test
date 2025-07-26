<?php
require_once 'config/database.php';
require_once 'config/security.php';

class Comic {
    private $db;
    private $connection;
    
    public function __construct() {
        $this->db = new Database();
        $this->connection = $this->db->getConnection();
    }
    
    public function getAll($page = 1, $limit = 20, $filters = []) {
        $offset = ($page - 1) * $limit;
        $where_conditions = ["status != 'deleted'"];
        $params = [];
        $types = '';
        
        // Apply filters
        if (!empty($filters['genre'])) {
            $where_conditions[] = "FIND_IN_SET(?, genres)";
            $params[] = $filters['genre'];
            $types .= 's';
        }
        
        if (!empty($filters['status'])) {
            $where_conditions[] = "status = ?";
            $params[] = $filters['status'];
            $types .= 's';
        }
        
        if (!empty($filters['search'])) {
            $where_conditions[] = "(title LIKE ? OR author LIKE ? OR description LIKE ?)";
            $search_term = "%{$filters['search']}%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $types .= 'sss';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Order by
        $order_by = "updated_at DESC";
        if (!empty($filters['sort'])) {
            switch ($filters['sort']) {
                case 'name':
                    $order_by = "title ASC";
                    break;
                case 'rating':
                    $order_by = "rating DESC";
                    break;
                case 'views':
                    $order_by = "views DESC";
                    break;
                case 'follows':
                    $order_by = "follows DESC";
                    break;
            }
        }
        
        $sql = "SELECT * FROM comics WHERE $where_clause ORDER BY $order_by LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        
        $stmt = $this->connection->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    public function getById($id) {
        $stmt = $this->connection->prepare("SELECT * FROM comics WHERE id = ? AND status != 'deleted'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    public function getPopular($limit = 10) {
        $stmt = $this->connection->prepare("
            SELECT * FROM comics 
            WHERE status != 'deleted' 
            ORDER BY (views * 0.3 + follows * 0.5 + rating * 0.2) DESC 
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    public function getRecentlyUpdated($limit = 20) {
        $stmt = $this->connection->prepare("
            SELECT c.*, MAX(ch.created_at) as last_chapter_date
            FROM comics c
            LEFT JOIN chapters ch ON c.id = ch.comic_id
            WHERE c.status != 'deleted'
            GROUP BY c.id
            ORDER BY last_chapter_date DESC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    public function create($data, $user_id) {
        try {
            // Validate data
            if (empty($data['title'])) {
                throw new Exception('Tên truyện không được để trống');
            }
            
            if (!empty($data['thumbnail']) && !Security::validateImageUrl($data['thumbnail'])) {
                throw new Exception('URL ảnh bìa không hợp lệ');
            }
            
            $stmt = $this->connection->prepare("
                INSERT INTO comics (title, description, thumbnail, author, status, genres, created_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->bind_param("ssssssi", 
                $data['title'],
                $data['description'] ?? '',
                $data['thumbnail'] ?? '',
                $data['author'] ?? '',
                $data['status'] ?? 'ongoing',
                $data['genres'] ?? '',
                $user_id
            );
            
            if ($stmt->execute()) {
                $comic_id = $this->connection->insert_id;
                Security::logSecurityEvent('comic_created', ['comic_id' => $comic_id, 'user_id' => $user_id]);
                return ['success' => true, 'comic_id' => $comic_id];
            } else {
                throw new Exception('Lỗi khi tạo truyện');
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function update($id, $data, $user_id) {
        try {
            // Check permissions
            $comic = $this->getById($id);
            if (!$comic) {
                throw new Exception('Không tìm thấy truyện');
            }
            
            $user = (new User())->getUserById($user_id);
            if ($comic['created_by'] != $user_id && $user['role'] !== 'admin') {
                throw new Exception('Bạn không có quyền chỉnh sửa truyện này');
            }
            
            $allowed_fields = ['title', 'description', 'thumbnail', 'author', 'status', 'genres'];
            $update_fields = [];
            $params = [];
            $types = '';
            
            foreach ($data as $field => $value) {
                if (in_array($field, $allowed_fields)) {
                    if ($field === 'thumbnail' && !empty($value) && !Security::validateImageUrl($value)) {
                        throw new Exception('URL ảnh bìa không hợp lệ');
                    }
                    
                    $update_fields[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
            }
            
            if (empty($update_fields)) {
                throw new Exception('Không có dữ liệu để cập nhật');
            }
            
            $update_fields[] = "updated_at = NOW()";
            
            $sql = "UPDATE comics SET " . implode(', ', $update_fields) . " WHERE id = ?";
            $params[] = $id;
            $types .= 'i';
            
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Cập nhật truyện thành công'];
            } else {
                throw new Exception('Lỗi khi cập nhật truyện');
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function delete($id, $user_id) {
        try {
            $comic = $this->getById($id);
            if (!$comic) {
                throw new Exception('Không tìm thấy truyện');
            }
            
            $user = (new User())->getUserById($user_id);
            if ($comic['created_by'] != $user_id && $user['role'] !== 'admin') {
                throw new Exception('Bạn không có quyền xóa truyện này');
            }
            
            $stmt = $this->connection->prepare("UPDATE comics SET status = 'deleted' WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                Security::logSecurityEvent('comic_deleted', ['comic_id' => $id, 'user_id' => $user_id]);
                return ['success' => true, 'message' => 'Xóa truyện thành công'];
            } else {
                throw new Exception('Lỗi khi xóa truyện');
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function incrementView($id) {
        $stmt = $this->connection->prepare("UPDATE comics SET views = views + 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    
    public function follow($comic_id, $user_id) {
        try {
            // Check if already following
            $stmt = $this->connection->prepare("SELECT id FROM user_favorites WHERE user_id = ? AND comic_id = ?");
            $stmt->bind_param("ii", $user_id, $comic_id);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                return ['success' => false, 'message' => 'Bạn đã theo dõi truyện này'];
            }
            
            $stmt = $this->connection->prepare("INSERT INTO user_favorites (user_id, comic_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $comic_id);
            
            if ($stmt->execute()) {
                // Update follow count
                $this->connection->query("UPDATE comics SET follows = follows + 1 WHERE id = $comic_id");
                return ['success' => true, 'message' => 'Đã theo dõi truyện'];
            } else {
                throw new Exception('Lỗi khi theo dõi truyện');
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function unfollow($comic_id, $user_id) {
        try {
            $stmt = $this->connection->prepare("DELETE FROM user_favorites WHERE user_id = ? AND comic_id = ?");
            $stmt->bind_param("ii", $user_id, $comic_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Update follow count
                $this->connection->query("UPDATE comics SET follows = GREATEST(follows - 1, 0) WHERE id = $comic_id");
                return ['success' => true, 'message' => 'Đã bỏ theo dõi truyện'];
            } else {
                return ['success' => false, 'message' => 'Bạn chưa theo dõi truyện này'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function rate($comic_id, $user_id, $rating) {
        try {
            if ($rating < 1 || $rating > 5) {
                throw new Exception('Đánh giá phải từ 1 đến 5 sao');
            }
            
            // Check if user already rated
            $stmt = $this->connection->prepare("SELECT rating FROM comic_ratings WHERE user_id = ? AND comic_id = ?");
            $stmt->bind_param("ii", $user_id, $comic_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if ($existing) {
                $stmt = $this->connection->prepare("UPDATE comic_ratings SET rating = ? WHERE user_id = ? AND comic_id = ?");
                $stmt->bind_param("iii", $rating, $user_id, $comic_id);
            } else {
                $stmt = $this->connection->prepare("INSERT INTO comic_ratings (user_id, comic_id, rating) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $user_id, $comic_id, $rating);
            }
            
            if ($stmt->execute()) {
                // Update average rating
                $this->updateAverageRating($comic_id);
                return ['success' => true, 'message' => 'Đã đánh giá truyện'];
            } else {
                throw new Exception('Lỗi khi đánh giá truyện');
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function updateAverageRating($comic_id) {
        $stmt = $this->connection->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as total_ratings 
            FROM comic_ratings 
            WHERE comic_id = ?
        ");
        $stmt->bind_param("i", $comic_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $stmt = $this->connection->prepare("UPDATE comics SET rating = ?, total_rating = ? WHERE id = ?");
        $avg_rating = round($result['avg_rating'], 2);
        $stmt->bind_param("dii", $avg_rating, $result['total_ratings'], $comic_id);
        $stmt->execute();
    }
    
    public function getUserFavorites($user_id, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        
        $stmt = $this->connection->prepare("
            SELECT c.*, uf.created_at as followed_at
            FROM comics c
            JOIN user_favorites uf ON c.id = uf.comic_id
            WHERE uf.user_id = ? AND c.status != 'deleted'
            ORDER BY uf.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("iii", $user_id, $limit, $offset);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    public function getGenres() {
        $genres = [
            'Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Horror',
            'Romance', 'Sci-Fi', 'Slice of Life', 'Sports', 'Supernatural',
            'Thriller', 'Martial Arts', 'School Life', 'Historical', 'Mystery'
        ];
        return $genres;
    }
    
    public function search($query, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        $search_term = "%$query%";
        
        $stmt = $this->connection->prepare("
            SELECT *, 
                   MATCH(title, author, description) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance
            FROM comics 
            WHERE (title LIKE ? OR author LIKE ? OR description LIKE ?) 
            AND status != 'deleted'
            ORDER BY relevance DESC, views DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->bind_param("ssssii", $query, $search_term, $search_term, $search_term, $limit, $offset);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
?>