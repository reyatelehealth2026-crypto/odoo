<?php
/**
 * CRM Dashboard Advanced - Enterprise Data-Dense Interface
 * 
 * Comprehensive CRM combining Sales, Service, Marketing, and Analytics
 * 
 * @version 1.0.0
 * @created 2026-03-29
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';

// Initialize database
$db = Database::getInstance()->getConnection();

// Get current user info
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentBotId = $_SESSION['line_account_id'] ?? null;

// Load necessary classes
require_once __DIR__ . '/classes/CRMDashboardService.php';

$crmService = new CRMDashboardService($db, $currentBotId);

// Get initial overview data
$overview = $crmService->getExecutiveOverview();

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Dashboard Advanced - CNY ERP</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['IBM Plex Sans Thai', 'Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        primary: '#0f172a',
                        secondary: '#3b82f6',
                        success: '#10b981',
                        warning: '#f59e0b',
                        danger: '#ef4444',
                        surface: '#ffffff',
                        background: '#f8fafc',
                        border: '#e2e8f0',
                    }
                }
            }
        }
    </script>
    
    <style>
        /* Data-Dense Enterprise Styling */
        * { box-sizing: border-box; }
        
        body {
            font-family: 'IBM Plex Sans Thai', 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            font-size: 13px;
            line-height: 1.4;
        }
        
        /* Metric Cards */
        .metric-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 14px;
            transition: all 0.15s ease;
        }
        
        .metric-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .metric-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 22px;
            font-weight: 600;
            color: #0f172a;
            letter-spacing: -0.02em;
        }
        
        .metric-label {
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 4px;
        }
        
        .metric-change {
            font-size: 11px;
            font-family: 'JetBrains Mono', monospace;
        }
        
        .metric-change.positive { color: #10b981; }
        .metric-change.negative { color: #ef4444; }
        
        /* Section Cards */
        .section-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .section-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 14px;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .section-body {
            padding: 12px;
        }
        
        /* Data Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        .data-table th {
            background: #f1f5f9;
            font-weight: 600;
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #475569;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        
        .data-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        
        .data-table tr:hover td {
            background: #f8fafc;
        }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .badge-blue { background: #dbeafe; color: #1d4ed8; }
        .badge-green { background: #d1fae5; color: #047857; }
        .badge-yellow { background: #fef3c7; color: #b45309; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-gray { background: #f3f4f6; color: #4b5563; }
        .badge-purple { background: #ede9fe; color: #6d28d9; }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
        }
        
        .btn-primary {
            background: #0f172a;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1e293b;
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        /* Navigation */
        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            color: #64748b;
            text-decoration: none;
            border-radius: 6px;
            margin-bottom: 2px;
            font-size: 13px;
            transition: all 0.15s ease;
            cursor: pointer;
        }
        
        .nav-item:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        
        .nav-item.active {
            background: #0f172a;
            color: white;
        }
        
        /* Kanban Pipeline */
        .kanban-column {
            background: #f8fafc;
            border-radius: 6px;
            min-width: 240px;
            max-width: 280px;
        }
        
        .kanban-header {
            padding: 10px 12px;
            font-weight: 600;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .kanban-count {
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-family: 'JetBrains Mono', monospace;
        }
        
        .kanban-body {
            padding: 8px;
            min-height: 200px;
        }
        
        .deal-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 8px;
            cursor: grab;
            transition: all 0.15s ease;
        }
        
        .deal-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 2px 6px rgba(59,130,246,0.1);
        }
        
        .deal-card.dragging {
            opacity: 0.5;
            cursor: grabbing;
        }
        
        .deal-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }
        
        .deal-customer {
            font-size: 12px;
            color: #64748b;
        }
        
        .deal-probability {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #475569;
        }
        
        .kanban-drop-zone {
            min-height: 100px;
            border: 2px dashed transparent;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .kanban-drop-zone.drag-over {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.05);
        }
        
        /* Activity Feed */
        .activity-item {
            display: flex;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .activity-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
            min-width: 0;
        }
        
        .activity-time {
            font-size: 11px;
            color: #94a3b8;
            white-space: nowrap;
        }
        
        /* Sparkline SVG */
        .sparkline {
            width: 60px;
            height: 20px;
        }
        
        /* Scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Loading States */
        .loading-shimmer {
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-container {
            background: white;
            border-radius: 12px;
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-body {
            padding: 20px;
            overflow-y: auto;
            max-height: calc(90vh - 70px);
        }
        
        /* Hide sections by default */
        .section-panel {
            display: none;
        }
        
        .section-panel.active {
            display: block;
        }
    </style>
</head>
<body class="h-screen flex overflow-hidden">
    
    <!-- Sidebar -->
    <aside class="w-56 bg-white border-r border-gray-200 flex flex-col flex-shrink-0">
        <!-- Logo -->
        <div class="h-14 flex items-center px-4 border-b border-gray-200">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-primary rounded-lg flex items-center justify-center text-white">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <span class="font-bold text-sm">CRM Pro</span>
            </div>
        </div>
        
        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto p-2">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 px-3">Main</div>
            
            <a href="#" class="nav-item active" onclick="showSection('overview')">
                <i class="bi bi-grid"></i>
                <span>Executive Overview</span>
            </a>
            
            <a href="#" class="nav-item" onclick="showSection('pipeline')">
                <i class="bi bi-kanban"></i>
                <span>Sales Pipeline</span>
                <span class="ml-auto badge badge-blue">3</span>
            </a>
            
            <a href="#" class="nav-item" onclick="showSection('service')">
                <i class="bi bi-headset"></i>
                <span>Service Center</span>
                <span class="ml-auto badge badge-red">12</span>
            </a>
            
            <a href="#" class="nav-item" onclick="showSection('marketing')">
                <i class="bi bi-bullseye"></i>
                <span>Marketing Hub</span>
            </a>
            
            <a href="#" class="nav-item" onclick="showSection('analytics')">
                <i class="bi bi-bar-chart-line"></i>
                <span>Analytics Studio</span>
            </a>
            
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 mt-6 px-3">Data</div>
            
            <a href="#" class="nav-item" onclick="showSection('customers')">
                <i class="bi bi-people"></i>
                <span>Customers</span>
            </a>
            
            <a href="#" class="nav-item" onclick="showSection('deals')">
                <i class="bi bi-briefcase"></i>
                <span>All Deals</span>
            </a>
            
            <a href="#" class="nav-item" onclick="showSection('tickets')">
                <i class="bi bi-ticket"></i>
                <span>Tickets</span>
            </a>
            
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 mt-6 px-3">Tools</div>
            
            <a href="#" class="nav-item" onclick="showSection('reports')">
                <i class="bi bi-file-earmark-text"></i>
                <span>Reports</span>
            </a>
            
            <a href="dashboard.php" class="nav-item">
                <i class="bi bi-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
        </nav>
        
        <!-- User Profile -->
        <div class="p-3 border-t border-gray-200">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
                    <i class="bi bi-person text-gray-500"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium truncate"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
                    <p class="text-xs text-gray-500">Administrator</p>
                </div>
            </div>
        </div>
    </aside>
    
    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-hidden">
        <!-- Header -->
        <header class="h-14 bg-white border-b border-gray-200 flex items-center justify-between px-4 flex-shrink-0">
            <div class="flex items-center gap-4">
                <h1 id="page-title" class="font-semibold text-lg">Executive Overview</h1>
                <span id="last-updated" class="text-xs text-gray-400">Updated: Just now</span>
            </div>
            
            <div class="flex items-center gap-3">
                <!-- Search -->
                <div class="relative">
                    <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" 
                           placeholder="Search customers, deals, tickets..." 
                           class="pl-9 pr-4 py-1.5 text-sm border border-gray-200 rounded-md w-72 focus:outline-none focus:border-blue-500">
                </div>
                
                <!-- Quick Actions -->
                <button class="btn btn-primary btn-sm" onclick="openAddDealModal()">
                    <i class="bi bi-plus-lg"></i>
                    Add Deal
                </button>
                
                <button class="btn btn-secondary btn-sm" onclick="openCreateTicketModal()">
                    <i class="bi bi-ticket"></i>
                    New Ticket
                </button>
                
                <!-- Notifications -->
                <button class="relative p-2 text-gray-500 hover:text-gray-700">
                    <i class="bi bi-bell"></i>
                    <span class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                </button>
            </div>
        </header>
        
        <!-- Content Area -->
        <div class="flex-1 overflow-y-auto p-4">
            
            <!-- SECTION: Executive Overview -->
            <div id="section-overview" class="section-panel active">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/executive-overview.php'; ?>
            </div>
            
            <!-- SECTION: Sales Pipeline -->
            <div id="section-pipeline" class="section-panel">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/sales-pipeline.php'; ?>
            </div>
            
            <!-- SECTION: Service Center -->
            <div id="section-service" class="section-panel">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/service-center.php'; ?>
            </div>
            
            <!-- SECTION: Marketing Hub -->
            <div id="section-marketing" class="section-panel">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/marketing-hub.php'; ?>
            </div>
            
            <!-- SECTION: Analytics Studio -->
            <div id="section-analytics" class="section-panel">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/analytics-studio.php'; ?>
            </div>
            
            <!-- SECTION: Customers -->
            <div id="section-customers" class="section-panel">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/customers-list.php'; ?>
            </div>
            
            <!-- SECTION: All Deals -->
            <div id="section-deals" class="section-panel">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/deals-list.php'; ?>
            </div>
            
            <!-- SECTION: All Tickets -->
            <div id="section-tickets" class="section-panel">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/tickets-list.php'; ?>
            </div>
            
            <!-- SECTION: Reports -->
            <div id="section-reports" class="section-panel">
                <?php include __DIR__ . '/includes/dashboard/crm-advanced/reports.php'; ?>
            </div>
            
        </div>
    </main>
    
    <!-- Customer 360 Modal -->
    <div id="customer360Modal" class="modal-backdrop" style="display: none;" onclick="if(event.target === this) closeCustomer360()">
        <div class="modal-container">
            <div class="modal-header">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="bi bi-person text-blue-600"></i>
                    </div>
                    <div>
                        <h3 id="c360-name" class="font-semibold">Customer Name</h3>
                        <p id="c360-meta" class="text-sm text-gray-500">LINE ID: xxx | Customer Ref: xxx</p>
                    </div>
                </div>
                <button onclick="closeCustomer360()" class="text-gray-400 hover:text-gray-600">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="modal-body" id="c360-content">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
    
    <script>
        // Global state
        let currentSection = 'overview';
        let charts = {};
        
        // Section navigation
        function showSection(sectionId) {
            // Update nav active state
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            event.target.closest('.nav-item').classList.add('active');
            
            // Hide all sections
            document.querySelectorAll('.section-panel').forEach(panel => {
                panel.classList.remove('active');
            });
            
            // Show target section
            document.getElementById('section-' + sectionId).classList.add('active');
            
            // Update page title
            const titles = {
                'overview': 'Executive Overview',
                'pipeline': 'Sales Pipeline',
                'service': 'Service Center',
                'marketing': 'Marketing Hub',
                'analytics': 'Analytics Studio',
                'customers': 'Customers',
                'deals': 'All Deals',
                'tickets': 'Tickets',
                'reports': 'Reports'
            };
            document.getElementById('page-title').textContent = titles[sectionId] || 'CRM Dashboard';
            
            // Load section data
            loadSectionData(sectionId);
            
            currentSection = sectionId;
        }
        
        // Load data for each section
        function loadSectionData(sectionId) {
            switch(sectionId) {
                case 'overview':
                    loadExecutiveOverview();
                    break;
                case 'pipeline':
                    loadPipelineData();
                    break;
                case 'service':
                    loadServiceData();
                    break;
                case 'marketing':
                    loadMarketingData();
                    break;
                case 'analytics':
                    loadAnalyticsData();
                    break;
                case 'customers':
                    loadCustomersList();
                    break;
                case 'deals':
                    loadDealsList();
                    break;
                case 'tickets':
                    loadTicketsList();
                    break;
            }
        }
        
        // API helper
        async function crmApi(action, data = {}) {
            try {
                const response = await fetch('api/crm-dashboard-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action, ...data })
                });
                return await response.json();
            } catch (error) {
                console.error('API Error:', error);
                return { success: false, error: error.message };
            }
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadExecutiveOverview();
        });
        
        // Sparkline helper
        function createSparkline(elementId, data, color = '#3b82f6') {
            const canvas = document.getElementById(elementId);
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            const width = canvas.width = 60;
            const height = canvas.height = 20;
            
            const min = Math.min(...data);
            const max = Math.max(...data);
            const range = max - min || 1;
            
            ctx.strokeStyle = color;
            ctx.lineWidth = 2;
            ctx.beginPath();
            
            data.forEach((value, i) => {
                const x = (i / (data.length - 1)) * width;
                const y = height - ((value - min) / range) * height;
                
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            
            ctx.stroke();
        }
        
        // Modal functions
        function openCustomer360(customerId) {
            document.getElementById('customer360Modal').style.display = 'flex';
            loadCustomer360Data(customerId);
        }
        
        function closeCustomer360() {
            document.getElementById('customer360Modal').style.display = 'none';
        }
        
        async function loadCustomer360Data(customerId) {
            const result = await crmApi('customer_360', { customer_id: customerId });
            if (result.success) {
                // Populate modal
                document.getElementById('c360-name').textContent = result.data.name;
                document.getElementById('c360-meta').textContent = `LINE: ${result.data.line_id} | Ref: ${result.data.customer_ref}`;
                document.getElementById('c360-content').innerHTML = renderCustomer360Content(result.data);
            }
        }
        
        function renderCustomer360Content(data) {
            // Tabbed interface for customer 360
            return `
                <div class="flex gap-1 mb-4 border-b border-gray-200">
                    <button class="px-4 py-2 text-sm font-medium text-blue-600 border-b-2 border-blue-600">Profile</button>
                    <button class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">Orders (${data.orders_count || 0})</button>
                    <button class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">Timeline</button>
                    <button class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">Financial</button>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h4 class="font-semibold mb-3">Contact Info</h4>
                        <p class="text-sm text-gray-600 mb-1">Phone: ${data.phone || '-'}</p>
                        <p class="text-sm text-gray-600 mb-1">Email: ${data.email || '-'}</p>
                        <p class="text-sm text-gray-600">LINE: ${data.line_id || '-'}</p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h4 class="font-semibold mb-3">Customer Stats</h4>
                        <p class="text-sm text-gray-600 mb-1">Total Orders: ${data.orders_count || 0}</p>
                        <p class="text-sm text-gray-600 mb-1">Total Spent: ฿${(data.total_spent || 0).toLocaleString()}</p>
                        <p class="text-sm text-gray-600">Last Order: ${data.last_order || '-'}</p>
                    </div>
                </div>
            `;
        }
        
        // Load functions (placeholders - implemented in separate files)
        function loadExecutiveOverview() {
            // Implemented in executive-overview.php
        }
        
        function loadPipelineData() {
            // Implemented in sales-pipeline.php
        }
        
        function loadServiceData() {
            // Implemented in service-center.php
        }
        
        function loadMarketingData() {
            // Implemented in marketing-hub.php
        }
        
        function loadAnalyticsData() {
            // Implemented in analytics-studio.php
        }
        
        function loadCustomersList() {
            // Implemented in customers-list.php
        }
        
        function loadDealsList() {
            // Implemented in deals-list.php
        }
        
        function loadTicketsList() {
            // Implemented in tickets-list.php
        }
    </script>
    
</body>
</html>
