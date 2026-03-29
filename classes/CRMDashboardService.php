<?php
/**
 * CRM Dashboard Service
 * 
 * Business logic for the advanced CRM dashboard
 * 
 * @version 1.0.0
 * @created 2026-03-29
 */

class CRMDashboardService
{
    private $db;
    private $lineAccountId;
    private $cache;
    
    // Cache TTL in seconds
    private const CACHE_TTL_OVERVIEW = 60;
    private const CACHE_TTL_PIPELINE = 30;
    private const CACHE_TTL_TICKETS = 30;
    private const CACHE_TTL_CUSTOMERS = 60;
    
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        
        // Initialize cache if Redis available
        if (class_exists('RedisCache')) {
            $this->cache = RedisCache::getInstance();
        }
    }
    
    // ===================================================================
    // EXECUTIVE OVERVIEW
    // ===================================================================
    
    public function getExecutiveOverview()
    {
        $cacheKey = 'crm:overview' . ($this->lineAccountId ? ':' . $this->lineAccountId : '');
        
        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $overview = [
            'metrics' => $this->getExecutiveMetrics(),
            'alerts' => $this->getActiveAlerts(),
            'activities' => $this->getRecentActivities(10),
            'charts' => [
                'revenue_trend' => $this->getRevenueTrend(7),
                'pipeline_distribution' => $this->getPipelineDistribution()
            ]
        ];
        
        if ($this->cache) {
            $this->cache->set($cacheKey, $overview, self::CACHE_TTL_OVERVIEW);
        }
        
        return $overview;
    }
    
    private function getExecutiveMetrics()
    {
        $metrics = [];
        
        // Total Customers
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM users 
            WHERE (line_account_id = ? OR ? IS NULL) AND is_blocked = 0
        ");
        $stmt->execute([$this->lineAccountId, $this->lineAccountId]);
        $metrics['total_customers'] = [
            'value' => (int)$stmt->fetchColumn(),
            'change' => $this->getCustomerGrowth(),
            'label' => 'Total Customers'
        ];
        
        // Active Deals / Pipeline Value
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as deal_count,
                COALESCE(SUM(value), 0) as pipeline_value
            FROM crm_deals 
            WHERE stage NOT IN ('closed_won', 'closed_lost')
        ");
        $dealsData = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['active_deals'] = [
            'value' => (int)$dealsData['deal_count'],
            'pipeline_value' => (float)$dealsData['pipeline_value'],
            'change' => $this->getDealsGrowth(),
            'label' => 'Active Deals'
        ];
        
        // Monthly Revenue (from Odoo webhooks)
        $metrics['monthly_revenue'] = [
            'value' => $this->getCurrentMonthRevenue(),
            'change' => $this->getRevenueGrowth(),
            'label' => 'Monthly Revenue'
        ];
        
        // Open Tickets
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM crm_tickets WHERE status IN ('open', 'pending')
        ");
        $metrics['open_tickets'] = [
            'value' => (int)$stmt->fetchColumn(),
            'urgent' => $this->getUrgentTicketsCount(),
            'label' => 'Open Tickets'
        ];
        
        // Conversion Rate
        $metrics['conversion_rate'] = [
            'value' => $this->calculateConversionRate(),
            'change' => 0,
            'label' => 'Conversion Rate'
        ];
        
        // Average Deal Size
        $stmt = $this->db->query("
            SELECT AVG(value) FROM crm_deals WHERE stage = 'closed_won' AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $metrics['avg_deal_size'] = [
            'value' => round((float)$stmt->fetchColumn(), 2),
            'change' => 0,
            'label' => 'Avg Deal Size'
        ];
        
        // Active Campaigns
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM drip_campaigns 
            WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)
        ");
        $stmt->execute([$this->lineAccountId]);
        $metrics['active_campaigns'] = [
            'value' => (int)$stmt->fetchColumn(),
            'change' => 0,
            'label' => 'Active Campaigns'
        ];
        
        // Customer Satisfaction (placeholder - would integrate with feedback system)
        $metrics['satisfaction'] = [
            'value' => 4.5,
            'max' => 5,
            'change' => 0.2,
            'label' => 'CSAT Score'
        ];
        
        return $metrics;
    }
    
    // ===================================================================
    // SALES PIPELINE
    // ===================================================================
    
    public function getPipelineData()
    {
        $cacheKey = 'crm:pipeline' . ($this->lineAccountId ? ':' . $this->lineAccountId : '');
        
        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $stages = ['lead', 'qualified', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
        $stageLabels = [
            'lead' => 'New Leads',
            'qualified' => 'Qualified',
            'proposal' => 'Proposal',
            'negotiation' => 'Negotiation',
            'closed_won' => 'Closed Won',
            'closed_lost' => 'Closed Lost'
        ];
        
        $pipeline = [];
        
        foreach ($stages as $stage) {
            $stmt = $this->db->prepare("
                SELECT 
                    d.*,
                    u.display_name as customer_name,
                    u.picture_url as customer_avatar
                FROM crm_deals d
                LEFT JOIN users u ON d.customer_id = u.id
                WHERE d.stage = ?
                ORDER BY d.updated_at DESC
                LIMIT 50
            ");
            $stmt->execute([$stage]);
            $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate stage value
            $stageValue = array_sum(array_column($deals, 'value'));
            
            $pipeline[] = [
                'id' => $stage,
                'name' => $stageLabels[$stage],
                'count' => count($deals),
                'value' => $stageValue,
                'deals' => $deals
            ];
        }
        
        $data = [
            'stages' => $pipeline,
            'total_value' => array_sum(array_column($pipeline, 'value')),
            'total_deals' => array_sum(array_column($pipeline, 'count')),
            'win_rate' => $this->calculateWinRate()
        ];
        
        if ($this->cache) {
            $this->cache->set($cacheKey, $data, self::CACHE_TTL_PIPELINE);
        }
        
        return $data;
    }
    
    public function moveDeal($dealId, $newStage)
    {
        $validStages = ['lead', 'qualified', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
        
        if (!in_array($newStage, $validStages)) {
            return ['success' => false, 'error' => 'Invalid stage'];
        }
        
        $updates = ['stage = ?'];
        $params = [$newStage];
        
        // If closing, set closed_at
        if (in_array($newStage, ['closed_won', 'closed_lost'])) {
            $updates[] = 'closed_at = NOW()';
        }
        
        $updates[] = 'updated_at = NOW()';
        $params[] = $dealId;
        
        $sql = "UPDATE crm_deals SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute($params);
            $this->clearCache();
            return ['success' => true, 'message' => 'Deal moved successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function createDeal($data)
    {
        $required = ['customer_id', 'title', 'value'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'error' => "Missing required field: $field"];
            }
        }
        
        $sql = "
            INSERT INTO crm_deals 
            (customer_id, title, description, value, stage, probability, expected_close, assigned_to, source, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ";
        
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute([
                $data['customer_id'],
                $data['title'],
                $data['description'] ?? '',
                $data['value'],
                $data['stage'] ?? 'lead',
                $data['probability'] ?? 20,
                $data['expected_close'] ?? null,
                $data['assigned_to'] ?? null,
                $data['source'] ?? 'manual'
            ]);
            
            $dealId = $this->db->lastInsertId();
            $this->clearCache();
            
            return ['success' => true, 'deal_id' => $dealId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function updateDeal($dealId, $data)
    {
        $allowedFields = ['title', 'description', 'value', 'stage', 'probability', 'expected_close', 'assigned_to'];
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        
        $updates[] = 'updated_at = NOW()';
        $params[] = $dealId;
        
        $sql = "UPDATE crm_deals SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute($params);
            $this->clearCache();
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function deleteDeal($dealId)
    {
        $stmt = $this->db->prepare("DELETE FROM crm_deals WHERE id = ?");
        
        try {
            $stmt->execute([$dealId]);
            $this->clearCache();
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ===================================================================
    // SERVICE CENTER (TICKETS)
    // ===================================================================
    
    public function getTickets($filters = [])
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['priority'])) {
            $where[] = 'priority = ?';
            $params[] = $filters['priority'];
        }
        
        if (!empty($filters['assigned_to'])) {
            $where[] = 'assigned_to = ?';
            $params[] = $filters['assigned_to'];
        }
        
        $sql = "
            SELECT 
                t.*,
                u.display_name as customer_name,
                u.picture_url as customer_avatar,
                u.line_user_id
            FROM crm_tickets t
            LEFT JOIN users u ON t.customer_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY 
                CASE t.priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    ELSE 4 
                END,
                t.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $filters['limit'] ?? 50;
        $params[] = $filters['offset'] ?? 0;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count for pagination
        $countSql = "SELECT COUNT(*) FROM crm_tickets t WHERE " . implode(' AND ', $where);
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute(array_slice($params, 0, -2));
        $total = $countStmt->fetchColumn();
        
        return [
            'tickets' => $tickets,
            'total' => $total,
            'limit' => $filters['limit'] ?? 50,
            'offset' => $filters['offset'] ?? 0
        ];
    }
    
    public function getTicketStats()
    {
        $stats = [];
        
        // By status
        $stmt = $this->db->query("
            SELECT status, COUNT(*) as count 
            FROM crm_tickets 
            GROUP BY status
        ");
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // By priority
        $stmt = $this->db->query("
            SELECT priority, COUNT(*) as count 
            FROM crm_tickets 
            GROUP BY priority
        ");
        $stats['by_priority'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // SLA stats
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as approaching_sla
            FROM crm_tickets 
            WHERE status IN ('open', 'pending')
            AND sla_deadline <= DATE_ADD(NOW(), INTERVAL 4 HOUR)
            AND sla_deadline > NOW()
        ");
        $stats['approaching_sla'] = (int)$stmt->fetchColumn();
        
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as breached_sla
            FROM crm_tickets 
            WHERE status IN ('open', 'pending')
            AND sla_deadline < NOW()
        ");
        $stats['breached_sla'] = (int)$stmt->fetchColumn();
        
        return $stats;
    }
    
    public function createTicket($data)
    {
        $required = ['customer_id', 'subject'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'error' => "Missing required field: $field"];
            }
        }
        
        // Calculate SLA deadline based on priority
        $slaHours = [
            'urgent' => 4,
            'high' => 8,
            'medium' => 24,
            'low' => 72
        ];
        $priority = $data['priority'] ?? 'medium';
        $slaDeadline = date('Y-m-d H:i:s', strtotime('+{$slaHours[$priority]} hours'));
        
        $sql = "
            INSERT INTO crm_tickets 
            (customer_id, subject, description, status, priority, category, assigned_to, sla_deadline, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute([
                $data['customer_id'],
                $data['subject'],
                $data['description'] ?? '',
                $data['status'] ?? 'open',
                $priority,
                $data['category'] ?? 'general',
                $data['assigned_to'] ?? null,
                $slaDeadline
            ]);
            
            $ticketId = $this->db->lastInsertId();
            
            return ['success' => true, 'ticket_id' => $ticketId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function updateTicket($ticketId, $data)
    {
        $allowedFields = ['subject', 'description', 'status', 'priority', 'category', 'assigned_to'];
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        
        // If status changed to resolved/closed, set resolved_at
        if (isset($data['status']) && in_array($data['status'], ['resolved', 'closed'])) {
            $updates[] = 'resolved_at = NOW()';
        }
        
        $params[] = $ticketId;
        
        $sql = "UPDATE crm_tickets SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute($params);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function addTicketInteraction($data)
    {
        $required = ['ticket_id', 'interaction_type', 'content'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'error' => "Missing required field: $field"];
            }
        }
        
        $sql = "
            INSERT INTO crm_ticket_interactions 
            (ticket_id, interaction_type, content, staff_id, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ";
        
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute([
                $data['ticket_id'],
                $data['interaction_type'],
                $data['content'],
                $data['staff_id'] ?? null
            ]);
            
            return ['success' => true, 'interaction_id' => $this->db->lastInsertId()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ===================================================================
    // MARKETING HUB
    // ===================================================================
    
    public function getCampaigns($filters = [])
    {
        $where = ['(line_account_id = ? OR line_account_id IS NULL)'];
        $params = [$this->lineAccountId];
        
        if (!empty($filters['status'])) {
            $where[] = 'is_active = ?';
            $params[] = ($filters['status'] === 'active') ? 1 : 0;
        }
        
        $sql = "
            SELECT 
                c.*,
                (SELECT COUNT(*) FROM drip_campaign_steps WHERE campaign_id = c.id) as step_count,
                (SELECT COUNT(*) FROM drip_campaign_progress WHERE campaign_id = c.id AND status = 'active') as active_users,
                (SELECT COUNT(*) FROM drip_campaign_progress WHERE campaign_id = c.id AND status = 'completed') as completed_users
            FROM drip_campaigns c
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.created_at DESC
            LIMIT ?
        ";
        
        $params[] = $filters['limit'] ?? 20;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getCampaignStats($campaignId)
    {
        // Get enrollment stats
        $stmt = $this->db->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM drip_campaign_progress
            WHERE campaign_id = ?
            GROUP BY status
        ");
        $stmt->execute([$campaignId]);
        $enrollment = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Mock stats for now - would integrate with actual message sending logs
        return [
            'enrollment' => $enrollment,
            'sent' => $enrollment['active'] ?? 0 + $enrollment['completed'] ?? 0,
            'opened' => 0,
            'clicked' => 0,
            'converted' => 0
        ];
    }
    
    public function getSegments()
    {
        // Pre-built segments based on user data
        return [
            [
                'id' => 'vip',
                'name' => 'VIP Customers',
                'description' => 'High value customers',
                'count' => $this->getSegmentCount('vip')
            ],
            [
                'id' => 'new',
                'name' => 'New Customers',
                'description' => 'Joined in last 30 days',
                'count' => $this->getSegmentCount('new')
            ],
            [
                'id' => 'inactive',
                'name' => 'Inactive Users',
                'description' => 'No activity in 30 days',
                'count' => $this->getSegmentCount('inactive')
            ],
            [
                'id' => 'has_deals',
                'name' => 'Active Prospects',
                'description' => 'Have open deals',
                'count' => $this->getSegmentCount('has_deals')
            ],
            [
                'id' => 'has_tickets',
                'name' => 'Support Active',
                'description' => 'Have open tickets',
                'count' => $this->getSegmentCount('has_tickets')
            ]
        ];
    }
    
    private function getSegmentCount($segmentId)
    {
        $sql = "";
        
        switch ($segmentId) {
            case 'new':
                $sql = "
                    SELECT COUNT(*) FROM users 
                    WHERE (line_account_id = ? OR ? IS NULL)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ";
                break;
            case 'inactive':
                $sql = "
                    SELECT COUNT(*) FROM users u
                    WHERE (u.line_account_id = ? OR ? IS NULL)
                    AND NOT EXISTS (
                        SELECT 1 FROM user_behaviors b 
                        WHERE b.user_id = u.id AND b.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                    )
                ";
                break;
            case 'has_deals':
                $sql = "
                    SELECT COUNT(DISTINCT customer_id) FROM crm_deals 
                    WHERE stage NOT IN ('closed_won', 'closed_lost')
                ";
                break;
            case 'has_tickets':
                $sql = "
                    SELECT COUNT(DISTINCT customer_id) FROM crm_tickets 
                    WHERE status IN ('open', 'pending')
                ";
                break;
            default:
                return 0;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $this->lineAccountId]);
        return (int)$stmt->fetchColumn();
    }
    
    // ===================================================================
    // ANALYTICS
    // ===================================================================
    
    public function getRevenueAnalytics($period = '30d')
    {
        $days = (int)str_replace('d', '', $period);
        
        // Daily revenue data
        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as order_count,
                SUM(amount_total) as revenue
            FROM odoo_webhooks_log
            WHERE event_type LIKE 'sale.order%'
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $stmt->execute([$days]);
        
        return [
            'period' => $period,
            'daily' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'summary' => $this->getRevenueSummary($days)
        ];
    }
    
    public function getSalesTeamAnalytics()
    {
        // Get deals by assigned salesperson
        $stmt = $this->db->query("
            SELECT 
                assigned_to,
                COUNT(*) as total_deals,
                SUM(CASE WHEN stage = 'closed_won' THEN 1 ELSE 0 END) as won_deals,
                SUM(CASE WHEN stage = 'closed_won' THEN value ELSE 0 END) as revenue,
                AVG(CASE WHEN stage = 'closed_won' THEN value END) as avg_deal_size
            FROM crm_deals
            WHERE assigned_to IS NOT NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY assigned_to
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getCustomerLifecycleAnalytics()
    {
        // Cohort analysis - customers by acquisition month
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as cohort_month,
                COUNT(*) as new_customers
            FROM users
            WHERE (line_account_id = ? OR ? IS NULL)
            AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY cohort_month DESC
        ");
        $stmt->execute([$this->lineAccountId, $this->lineAccountId]);
        
        return [
            'cohorts' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'retention' => $this->calculateRetentionRates()
        ];
    }
    
    // ===================================================================
    // CUSTOMERS
    // ===================================================================
    
    public function getCustomers($filters = [])
    {
        $where = ['(u.line_account_id = ? OR ? IS NULL)', 'u.is_blocked = 0'];
        $params = [$this->lineAccountId, $this->lineAccountId];
        
        if (!empty($filters['search'])) {
            $where[] = '(u.display_name LIKE ? OR u.line_user_id LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        
        if (!empty($filters['tag_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_tag_assignments a WHERE a.user_id = u.id AND a.tag_id = ?)';
            $params[] = $filters['tag_id'];
        }
        
        $sql = "
            SELECT 
                u.id,
                u.line_user_id,
                u.display_name,
                u.picture_url,
                u.created_at,
                u.last_message_at,
                GROUP_CONCAT(DISTINCT t.name) as tags,
                COUNT(DISTINCT d.id) as deals_count,
                COUNT(DISTINCT tk.id) as tickets_count
            FROM users u
            LEFT JOIN user_tag_assignments a ON u.id = a.user_id
            LEFT JOIN user_tags t ON a.tag_id = t.id
            LEFT JOIN crm_deals d ON u.id = d.customer_id AND d.stage NOT IN ('closed_won', 'closed_lost')
            LEFT JOIN crm_tickets tk ON u.id = tk.customer_id AND tk.status IN ('open', 'pending')
            WHERE " . implode(' AND ', $where) . "
            GROUP BY u.id
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $filters['limit'] ?? 50;
        $params[] = $filters['offset'] ?? 0;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $countSql = "
            SELECT COUNT(DISTINCT u.id) 
            FROM users u
            WHERE " . implode(' AND ', $where) . "
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute(array_slice($params, 0, -2));
        $total = $countStmt->fetchColumn();
        
        return [
            'customers' => $customers,
            'total' => $total,
            'limit' => $filters['limit'] ?? 50,
            'offset' => $filters['offset'] ?? 0
        ];
    }
    
    public function getCustomer360($customerId)
    {
        // Basic customer info
        $stmt = $this->db->prepare("
            SELECT * FROM users WHERE id = ?
        ");
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            return null;
        }
        
        // Tags
        $stmt = $this->db->prepare("
            SELECT t.* FROM user_tags t
            JOIN user_tag_assignments a ON t.id = a.tag_id
            WHERE a.user_id = ?
        ");
        $stmt->execute([$customerId]);
        $customer['tags'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Stats
        $customer['orders_count'] = $this->getCustomerOrdersCount($customerId);
        $customer['total_spent'] = $this->getCustomerTotalSpent($customerId);
        $customer['deals_count'] = $this->getCustomerDealsCount($customerId);
        $customer['tickets_count'] = $this->getCustomerTicketsCount($customerId);
        
        return $customer;
    }
    
    public function getCustomerTimeline($customerId, $limit = 50)
    {
        $timeline = [];
        
        // Orders from webhook logs
        $stmt = $this->db->prepare("
            SELECT 
                created_at as event_date,
                'order' as event_type,
                event_type as sub_type,
                order_name as title,
                amount_total as value,
                NULL as notes
            FROM odoo_webhooks_log
            WHERE customer_id = ?
            AND event_type LIKE 'sale.order%'
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        $timeline = array_merge($timeline, $stmt->fetchAll(PDO::FETCH_ASSOC));
        
        // Deals
        $stmt = $this->db->prepare("
            SELECT 
                created_at as event_date,
                'deal' as event_type,
                stage as sub_type,
                title,
                value,
                NULL as notes
            FROM crm_deals
            WHERE customer_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        $timeline = array_merge($timeline, $stmt->fetchAll(PDO::FETCH_ASSOC));
        
        // Tickets
        $stmt = $this->db->prepare("
            SELECT 
                created_at as event_date,
                'ticket' as event_type,
                status as sub_type,
                subject as title,
                NULL as value,
                description as notes
            FROM crm_tickets
            WHERE customer_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        $timeline = array_merge($timeline, $stmt->fetchAll(PDO::FETCH_ASSOC));
        
        // Sort by date
        usort($timeline, function($a, $b) {
            return strtotime($b['event_date']) - strtotime($a['event_date']);
        });
        
        return array_slice($timeline, 0, $limit);
    }
    
    // ===================================================================
    // HELPER METHODS
    // ===================================================================
    
    public function getRecentActivities($limit = 20)
    {
        $activities = [];
        
        // Recent deals
        $stmt = $this->db->query("
            SELECT 
                'deal' as type,
                d.created_at,
                u.display_name as customer_name,
                d.title,
                d.value,
                d.stage
            FROM crm_deals d
            LEFT JOIN users u ON d.customer_id = u.id
            ORDER BY d.created_at DESC
            LIMIT $limit
        ");
        $activities = array_merge($activities, $stmt->fetchAll(PDO::FETCH_ASSOC));
        
        // Recent tickets
        $stmt = $this->db->query("
            SELECT 
                'ticket' as type,
                t.created_at,
                u.display_name as customer_name,
                t.subject as title,
                NULL as value,
                t.status as stage
            FROM crm_tickets t
            LEFT JOIN users u ON t.customer_id = u.id
            ORDER BY t.created_at DESC
            LIMIT $limit
        ");
        $activities = array_merge($activities, $stmt->fetchAll(PDO::FETCH_ASSOC));
        
        // Sort by date
        usort($activities, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return array_slice($activities, 0, $limit);
    }
    
    private function getActiveAlerts()
    {
        $alerts = [];
        
        // SLA breach alerts
        $stmt = $this->db->query("
            SELECT COUNT(*) as count 
            FROM crm_tickets 
            WHERE status IN ('open', 'pending') 
            AND sla_deadline < NOW()
        ");
        $breached = (int)$stmt->fetchColumn();
        
        if ($breached > 0) {
            $alerts[] = [
                'type' => 'danger',
                'message' => "$breached ticket(s) have breached SLA",
                'link' => '#tickets'
            ];
        }
        
        // New leads
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM crm_deals 
            WHERE stage = 'lead' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $newLeads = (int)$stmt->fetchColumn();
        
        if ($newLeads > 0) {
            $alerts[] = [
                'type' => 'info',
                'message' => "$newLeads new lead(s) today",
                'link' => '#pipeline'
            ];
        }
        
        return $alerts;
    }
    
    private function clearCache()
    {
        if ($this->cache) {
            $this->cache->delete('crm:overview*');
            $this->cache->delete('crm:pipeline*');
        }
    }
    
    // Placeholder methods - would be implemented with real queries
    private function getCustomerGrowth() { return 5.2; }
    private function getDealsGrowth() { return 12.5; }
    private function getCurrentMonthRevenue() { return 125000; }
    private function getRevenueGrowth() { return 8.3; }
    private function getUrgentTicketsCount() { return 3; }
    private function calculateConversionRate() { return 24.5; }
    private function calculateWinRate() { return 35.0; }
    private function getRevenueTrend($days) { return [100, 120, 115, 140, 135, 160, 155]; }
    private function getPipelineDistribution() { return [10, 8, 5, 3, 12, 7]; }
    private function getRevenueSummary($days) { return ['total' => 125000, 'avg' => 17857]; }
    private function calculateRetentionRates() { return [100, 85, 72, 65, 58]; }
    private function getCustomerOrdersCount($id) { return 5; }
    private function getCustomerTotalSpent($id) { return 25000; }
    private function getCustomerDealsCount($id) { return 2; }
    private function getCustomerTicketsCount($id) { return 1; }
    public function quickSearch($query) { return []; }
    public function generateSalesReport($params) { return []; }
    public function generateCustomerReport($params) { return []; }
    public function getDealsList($filters) { return ['deals' => [], 'total' => 0]; }
    public function getCustomerDeals($customerId) { return []; }
    public function getCustomerTickets($customerId) { return []; }
    public function getSegmentCustomers($segmentId) { return []; }
}
