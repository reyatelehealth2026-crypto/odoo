<?php
/**
 * CRM Dashboard API
 * 
 * Provides endpoints for the advanced CRM dashboard
 * 
 * @version 1.0.0
 * @created 2026-03-29
 */

ob_start();

// Error handling
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level() > 0) ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'error'   => 'PHP fatal: ' . $err['message'],
        ]);
    }
});

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CRMDashboardService.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $crmService = new CRMDashboardService($db);

    // Parse input
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_GET;
    }

    $action = trim((string) ($input['action'] ?? ''));
    
    if ($action === '') {
        echo json_encode(['success' => false, 'error' => 'Action required']);
        exit;
    }

    $result = ['success' => false, 'error' => 'Unknown action'];

    switch ($action) {
        // Executive Overview
        case 'overview':
            $result = [
                'success' => true,
                'data' => $crmService->getExecutiveOverview()
            ];
            break;

        // Sales Pipeline
        case 'pipeline':
            $result = [
                'success' => true,
                'data' => $crmService->getPipelineData()
            ];
            break;

        case 'deal_move':
            $result = $crmService->moveDeal(
                (int)($input['deal_id'] ?? 0),
                $input['stage'] ?? ''
            );
            break;

        case 'deal_create':
            $result = $crmService->createDeal($input);
            break;

        case 'deal_update':
            $result = $crmService->updateDeal(
                (int)($input['deal_id'] ?? 0),
                $input
            );
            break;

        case 'deal_delete':
            $result = $crmService->deleteDeal((int)($input['deal_id'] ?? 0));
            break;

        // Service Center
        case 'tickets':
            $result = [
                'success' => true,
                'data' => $crmService->getTickets([
                    'status' => $input['status'] ?? null,
                    'priority' => $input['priority'] ?? null,
                    'assigned_to' => $input['assigned_to'] ?? null,
                    'limit' => (int)($input['limit'] ?? 50),
                    'offset' => (int)($input['offset'] ?? 0)
                ])
            ];
            break;

        case 'ticket_create':
            $result = $crmService->createTicket($input);
            break;

        case 'ticket_update':
            $result = $crmService->updateTicket(
                (int)($input['ticket_id'] ?? 0),
                $input
            );
            break;

        case 'ticket_interaction':
            $result = $crmService->addTicketInteraction($input);
            break;

        case 'ticket_stats':
            $result = [
                'success' => true,
                'data' => $crmService->getTicketStats()
            ];
            break;

        // Marketing Hub
        case 'campaigns':
            $result = [
                'success' => true,
                'data' => $crmService->getCampaigns([
                    'status' => $input['status'] ?? null,
                    'limit' => (int)($input['limit'] ?? 20)
                ])
            ];
            break;

        case 'campaign_stats':
            $result = [
                'success' => true,
                'data' => $crmService->getCampaignStats((int)($input['campaign_id'] ?? 0))
            ];
            break;

        case 'segments':
            $result = [
                'success' => true,
                'data' => $crmService->getSegments()
            ];
            break;

        case 'segment_customers':
            $result = [
                'success' => true,
                'data' => $crmService->getSegmentCustomers((int)($input['segment_id'] ?? 0))
            ];
            break;

        // Analytics
        case 'analytics_revenue':
            $result = [
                'success' => true,
                'data' => $crmService->getRevenueAnalytics(
                    $input['period'] ?? '30d'
                )
            ];
            break;

        case 'analytics_sales_team':
            $result = [
                'success' => true,
                'data' => $crmService->getSalesTeamAnalytics()
            ];
            break;

        case 'analytics_customer_lifecycle':
            $result = [
                'success' => true,
                'data' => $crmService->getCustomerLifecycleAnalytics()
            ];
            break;

        case 'activities':
            $result = [
                'success' => true,
                'data' => $crmService->getRecentActivities((int)($input['limit'] ?? 20))
            ];
            break;

        // Customers
        case 'customers':
            $result = [
                'success' => true,
                'data' => $crmService->getCustomers([
                    'search' => $input['search'] ?? '',
                    'tag_id' => $input['tag_id'] ?? null,
                    'segment_id' => $input['segment_id'] ?? null,
                    'has_deals' => $input['has_deals'] ?? null,
                    'has_tickets' => $input['has_tickets'] ?? null,
                    'limit' => (int)($input['limit'] ?? 50),
                    'offset' => (int)($input['offset'] ?? 0)
                ])
            ];
            break;

        case 'customer_360':
            $result = [
                'success' => true,
                'data' => $crmService->getCustomer360((int)($input['customer_id'] ?? 0))
            ];
            break;

        case 'customer_timeline':
            $result = [
                'success' => true,
                'data' => $crmService->getCustomerTimeline(
                    (int)($input['customer_id'] ?? 0),
                    (int)($input['limit'] ?? 50)
                )
            ];
            break;

        case 'customer_deals':
            $result = [
                'success' => true,
                'data' => $crmService->getCustomerDeals((int)($input['customer_id'] ?? 0))
            ];
            break;

        case 'customer_tickets':
            $result = [
                'success' => true,
                'data' => $crmService->getCustomerTickets((int)($input['customer_id'] ?? 0))
            ];
            break;

        // Deals List
        case 'deals':
            $result = [
                'success' => true,
                'data' => $crmService->getDealsList([
                    'stage' => $input['stage'] ?? null,
                    'assigned_to' => $input['assigned_to'] ?? null,
                    'search' => $input['search'] ?? '',
                    'sort_by' => $input['sort_by'] ?? 'created_at',
                    'sort_order' => $input['sort_order'] ?? 'DESC',
                    'limit' => (int)($input['limit'] ?? 50),
                    'offset' => (int)($input['offset'] ?? 0)
                ])
            ];
            break;

        // Quick Search
        case 'quick_search':
            $result = [
                'success' => true,
                'data' => $crmService->quickSearch($input['query'] ?? '')
            ];
            break;

        // Reports
        case 'report_sales':
            $result = [
                'success' => true,
                'data' => $crmService->generateSalesReport([
                    'start_date' => $input['start_date'] ?? null,
                    'end_date' => $input['end_date'] ?? null,
                    'group_by' => $input['group_by'] ?? 'day'
                ])
            ];
            break;

        case 'report_customers':
            $result = [
                'success' => true,
                'data' => $crmService->generateCustomerReport([
                    'start_date' => $input['start_date'] ?? null,
                    'end_date' => $input['end_date'] ?? null
                ])
            ];
            break;

        // Health check
        case 'health':
            $result = [
                'success' => true,
                'data' => [
                    'status' => 'ok',
                    'service' => 'crm-dashboard-api',
                    'timestamp' => date('c')
                ]
            ];
            break;
    }

    // Add execution time
    $result['_meta'] = [
        'duration_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2),
        'timestamp' => date('c')
    ];

    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (ob_get_level() > 0) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        '_meta' => [
            'timestamp' => date('c')
        ]
    ]);
}
