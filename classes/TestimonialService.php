<?php
/**
 * TestimonialService - จัดการรีวิวจากลูกค้าสำหรับ Landing Page
 * 
 * Requirements: 5.1, 5.2, 5.5, 10.4
 */

class TestimonialService {
    private $db;
    private $lineAccountId;
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get approved testimonials
     * Requirements: 5.1 - Display testimonials section if reviews exist
     * 
     * @param int $limit Maximum number of testimonials
     * @return array
     */
    public function getApprovedTestimonials(int $limit = 10): array {
        $sql = "SELECT id, customer_name, customer_avatar, rating, review_text, source, created_at, approved_at
                FROM landing_testimonials 
                WHERE status = 'approved'";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        } else {
            $sql .= " AND line_account_id IS NULL";
        }
        
        $sql .= " ORDER BY approved_at DESC, id DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get testimonial structured data for JSON-LD (Review schema)
     * Requirements: 5.5 - Include Review Structured_Data
     * 
     * @return array JSON-LD structured data
     */
    public function getTestimonialStructuredData(): array {
        $testimonials = $this->getApprovedTestimonials();
        
        if (empty($testimonials)) {
            return [];
        }
        
        $reviews = [];
        foreach ($testimonials as $testimonial) {
            $reviews[] = [
                '@type' => 'Review',
                'author' => [
                    '@type' => 'Person',
                    'name' => $testimonial['customer_name']
                ],
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => (int)$testimonial['rating'],
                    'bestRating' => 5,
                    'worstRating' => 1
                ],
                'reviewBody' => $testimonial['review_text'],
                'datePublished' => date('Y-m-d', strtotime($testimonial['approved_at'] ?? $testimonial['created_at']))
            ];
        }
        
        return $reviews;
    }
    
    /**
     * Get average rating
     * Requirements: 5.2 - Show rating
     * 
     * @return float
     */
    public function getAverageRating(): float {
        $sql = "SELECT AVG(rating) FROM landing_testimonials WHERE status = 'approved'";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $avg = $stmt->fetchColumn();
        
        return $avg ? round((float)$avg, 1) : 0.0;
    }
    
    /**
     * Get total count of approved testimonials
     * 
     * @return int
     */
    public function getTotalCount(): int {
        $sql = "SELECT COUNT(*) FROM landing_testimonials WHERE status = 'approved'";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get single testimonial by ID
     * 
     * @param int $id Testimonial ID
     * @return array|null
     */
    public function getById(int $id): ?array {
        $sql = "SELECT * FROM landing_testimonials WHERE id = ?";
        $params = [$id];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Create new testimonial
     * Requirements: 10.4 - Allow managing customer reviews
     * 
     * @param array $data Testimonial data
     * @return int New testimonial ID
     */
    public function create(array $data): int {
        $customerName = trim($data['customer_name'] ?? '');
        $reviewText = trim($data['review_text'] ?? '');
        
        if (empty($customerName) || empty($reviewText)) {
            throw new InvalidArgumentException('Customer name and review text are required');
        }
        
        $rating = isset($data['rating']) ? (int)$data['rating'] : 5;
        // Ensure rating is between 1 and 5
        $rating = max(1, min(5, $rating));
        
        $customerAvatar = $data['customer_avatar'] ?? null;
        $source = $data['source'] ?? 'manual';
        $status = $data['status'] ?? 'pending';
        
        $stmt = $this->db->prepare("
            INSERT INTO landing_testimonials 
            (line_account_id, customer_name, customer_avatar, rating, review_text, source, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $customerName,
            $customerAvatar,
            $rating,
            $reviewText,
            $source,
            $status
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Update existing testimonial
     * 
     * @param int $id Testimonial ID
     * @param array $data Updated data
     * @return bool
     */
    public function update(int $id, array $data): bool {
        $testimonial = $this->getById($id);
        if (!$testimonial) {
            return false;
        }
        
        $fields = [];
        $params = [];
        
        if (isset($data['customer_name'])) {
            $customerName = trim($data['customer_name']);
            if (empty($customerName)) {
                throw new InvalidArgumentException('Customer name cannot be empty');
            }
            $fields[] = 'customer_name = ?';
            $params[] = $customerName;
        }
        
        if (isset($data['review_text'])) {
            $reviewText = trim($data['review_text']);
            if (empty($reviewText)) {
                throw new InvalidArgumentException('Review text cannot be empty');
            }
            $fields[] = 'review_text = ?';
            $params[] = $reviewText;
        }
        
        if (isset($data['rating'])) {
            $rating = max(1, min(5, (int)$data['rating']));
            $fields[] = 'rating = ?';
            $params[] = $rating;
        }
        
        if (array_key_exists('customer_avatar', $data)) {
            $fields[] = 'customer_avatar = ?';
            $params[] = $data['customer_avatar'];
        }
        
        if (isset($data['source'])) {
            $fields[] = 'source = ?';
            $params[] = $data['source'];
        }
        
        if (isset($data['status'])) {
            $fields[] = 'status = ?';
            $params[] = $data['status'];
            
            // Set approved_at if approving
            if ($data['status'] === 'approved' && $testimonial['status'] !== 'approved') {
                $fields[] = 'approved_at = NOW()';
            }
        }
        
        if (empty($fields)) {
            return true; // Nothing to update
        }
        
        $params[] = $id;
        $sql = "UPDATE landing_testimonials SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Delete testimonial
     * 
     * @param int $id Testimonial ID
     * @return bool
     */
    public function delete(int $id): bool {
        $testimonial = $this->getById($id);
        if (!$testimonial) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM landing_testimonials WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Approve testimonial
     * Requirements: 10.4 - Approval workflow
     * 
     * @param int $id Testimonial ID
     * @return bool
     */
    public function approve(int $id): bool {
        return $this->update($id, ['status' => 'approved']);
    }
    
    /**
     * Reject testimonial
     * Requirements: 10.4 - Approval workflow
     * 
     * @param int $id Testimonial ID
     * @return bool
     */
    public function reject(int $id): bool {
        return $this->update($id, ['status' => 'rejected']);
    }

    
    /**
     * Get all testimonials for admin (including pending/rejected)
     * 
     * @param string|null $status Filter by status
     * @return array
     */
    public function getAllForAdmin(?string $status = null): array {
        $sql = "SELECT * FROM landing_testimonials WHERE 1=1";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get pending testimonials count
     * 
     * @return int
     */
    public function getPendingCount(): int {
        $sql = "SELECT COUNT(*) FROM landing_testimonials WHERE status = 'pending'";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get rating distribution
     * 
     * @return array Rating counts by star
     */
    public function getRatingDistribution(): array {
        $sql = "SELECT rating, COUNT(*) as count 
                FROM landing_testimonials 
                WHERE status = 'approved'";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " GROUP BY rating ORDER BY rating DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Ensure all ratings 1-5 are present
        $distribution = [];
        for ($i = 5; $i >= 1; $i--) {
            $distribution[$i] = (int)($results[$i] ?? 0);
        }
        
        return $distribution;
    }
}
