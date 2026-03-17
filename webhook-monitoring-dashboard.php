<?php
/**
 * Webhook Monitoring Dashboard
 * 
 * Comprehensive webhook monitoring interface with statistics,
 * retry management, and performance metrics.
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/header.php';

$pageTitle = 'Webhook Monitoring Dashboard';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">🪝 Webhook Monitoring</h1>
                    <p class="text-muted mb-0">Monitor webhook events, performance, and manage retries</p>
                </div>
                <div>
                    <button class="btn btn-outline-primary btn-sm" onclick="refreshDashboard()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="showBulkRetryModal()">
                        <i class="fas fa-redo"></i> Bulk Retry
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4" id="statisticsCards">
        <div class="col-12">
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading webhook statistics...</p>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Webhook Activity (Last 24 Hours)</h5>
                </div>
                <div class="card-body">
                    <canvas id="activityChart" height="100"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Event Types Distribution</h5>
                </div>
                <div class="card-body">
                    <canvas id="eventTypesChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and Controls -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form id="filtersForm" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Webhook Type</label>
                            <select class="form-select" name="webhook_type" id="webhookTypeFilter">
                                <option value="">All Types</option>
                                <option value="odoo">Odoo</option>
                                <option value="line">LINE</option>
                                <option value="payment">Payment</option>
                                <option value="delivery">Delivery</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="PROCESSED">Processed</option>
                                <option value="FAILED">Failed</option>
                                <option value="RETRY">Retry</option>
                                <option value="DLQ">Dead Letter Queue</option>
                                <option value="DUPLICATE">Duplicate</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" name="date_from" id="dateFromFilter">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" name="date_to" id="dateToFilter">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" id="searchFilter" 
                                   placeholder="Webhook ID, Order ID, Customer...">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block w-100">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Webhook List -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Webhook Events</h5>
                    <div>
                        <span class="badge bg-secondary" id="totalCount">0</span>
                        <button class="btn btn-sm btn-outline-secondary ms-2" onclick="exportWebhooks()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="webhooksTable">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Event</th>
                                    <th>Status</th>
                                    <th>Customer</th>
                                    <th>Order/Invoice</th>
                                    <th>Processing Time</th>
                                    <th>Retries</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="webhooksTableBody">
                                <tr>
                                    <td colspan="10" class="text-center py-4">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="sr-only">Loading...</span>
                                        </div>
                                        <p class="mt-2 text-muted">Loading webhook events...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <nav aria-label="Webhook pagination">
                        <ul class="pagination pagination-sm mb-0" id="pagination">
                            <!-- Pagination will be generated by JavaScript -->
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Webhook Detail Modal -->
<div class="modal fade" id="webhookDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Webhook Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="webhookDetailContent">
                <!-- Content loaded by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="retryWebhookBtn" onclick="retryWebhook()">
                    <i class="fas fa-redo"></i> Retry
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Retry Modal -->
<div class="modal fade" id="bulkRetryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Retry Webhooks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="bulkRetryForm">
                    <div class="mb-3">
                        <label class="form-label">Webhook Type</label>
                        <select class="form-select" name="webhook_type">
                            <option value="">All Types</option>
                            <option value="odoo">Odoo</option>
                            <option value="line">LINE</option>
                            <option value="payment">Payment</option>
                            <option value="delivery">Delivery</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date Range</label>
                        <div class="row">
                            <div class="col-6">
                                <input type="date" class="form-control" name="date_from" placeholder="From">
                            </div>
                            <div class="col-6">
                                <input type="date" class="form-control" name="date_to" placeholder="To">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max Webhooks to Retry</label>
                        <input type="number" class="form-control" name="max_retries" value="100" min="1" max="500">
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        This will retry all failed and DLQ webhooks matching the criteria. Use with caution.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="executeBulkRetry()">
                    <i class="fas fa-redo"></i> Execute Bulk Retry
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Global variables
let currentPage = 0;
let currentFilters = {};
let currentWebhookId = null;
let activityChart = null;
let eventTypesChart = null;

// Initialize dashboard
document.addEventListener('DOMContentLoaded', function() {
    // Set default date filters (last 7 days)
    const today = new Date();
    const weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
    
    document.getElementById('dateFromFilter').value = weekAgo.toISOString().split('T')[0];
    document.getElementById('dateToFilter').value = today.toISOString().split('T')[0];
    
    // Load initial data
    loadStatistics();
    loadWebhooks();
    
    // Set up form submission
    document.getElementById('filtersForm').addEventListener('submit', function(e) {
        e.preventDefault();
        currentPage = 0;
        loadWebhooks();
    });
    
    // Auto-refresh every 30 seconds
    setInterval(function() {
        loadStatistics();
        if (currentPage === 0) {
            loadWebhooks();
        }
    }, 30000);
});

// Load statistics
async function loadStatistics() {
    try {
        const filters = getFilters();
        const response = await fetch('api/webhook-monitoring.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'statistics',
                ...filters
            })
        });
        
        const result = await response.json();
        if (result.success) {
            displayStatistics(result.data);
            updateCharts(result.data);
        } else {
            showError('Failed to load statistics: ' + result.error);
        }
    } catch (error) {
        showError('Error loading statistics: ' + error.message);
    }
}

// Display statistics cards
function displayStatistics(data) {
    const overall = data.overall;
    const successRate = overall.success_rate || 0;
    const avgProcessingTime = overall.avg_processing_time || 0;
    
    const html = `
        <div class="col-md-2">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">${formatNumber(overall.total)}</h4>
                            <p class="mb-0">Total Events</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-webhook fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">${formatNumber(overall.processed)}</h4>
                            <p class="mb-0">Processed</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-check-circle fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">${formatNumber(overall.failed)}</h4>
                            <p class="mb-0">Failed</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-times-circle fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">${formatNumber(overall.retry)}</h4>
                            <p class="mb-0">Retrying</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-redo fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">${successRate.toFixed(1)}%</h4>
                            <p class="mb-0">Success Rate</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-chart-line fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">${avgProcessingTime.toFixed(0)}ms</h4>
                            <p class="mb-0">Avg Time</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-clock fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('statisticsCards').innerHTML = html;
}
// Update charts
function updateCharts(data) {
    updateActivityChart(data.hourly_distribution);
    updateEventTypesChart(data.event_types);
}

// Update activity chart
function updateActivityChart(hourlyData) {
    const ctx = document.getElementById('activityChart').getContext('2d');
    
    if (activityChart) {
        activityChart.destroy();
    }
    
    const labels = [];
    const processedData = [];
    const failedData = [];
    
    // Fill in missing hours with zeros
    for (let i = 0; i < 24; i++) {
        labels.push(i + ':00');
        const hourData = hourlyData.find(h => parseInt(h.hour) === i);
        processedData.push(hourData ? parseInt(hourData.processed) : 0);
        failedData.push(hourData ? parseInt(hourData.failed) : 0);
    }
    
    activityChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Processed',
                data: processedData,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4
            }, {
                label: 'Failed',
                data: failedData,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// Update event types chart
function updateEventTypesChart(eventTypesData) {
    const ctx = document.getElementById('eventTypesChart').getContext('2d');
    
    if (eventTypesChart) {
        eventTypesChart.destroy();
    }
    
    const labels = eventTypesData.slice(0, 8).map(item => item.event_type);
    const data = eventTypesData.slice(0, 8).map(item => parseInt(item.count));
    const colors = [
        '#007bff', '#28a745', '#ffc107', '#dc3545', 
        '#6f42c1', '#fd7e14', '#20c997', '#6c757d'
    ];
    
    eventTypesChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Load webhooks list
async function loadWebhooks() {
    try {
        const filters = getFilters();
        const response = await fetch('api/webhook-monitoring.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'list',
                limit: 50,
                offset: currentPage * 50,
                ...filters
            })
        });
        
        const result = await response.json();
        if (result.success) {
            displayWebhooks(result.data);
        } else {
            showError('Failed to load webhooks: ' + result.error);
        }
    } catch (error) {
        showError('Error loading webhooks: ' + error.message);
    }
}

// Display webhooks table
function displayWebhooks(data) {
    const tbody = document.getElementById('webhooksTableBody');
    const totalCount = document.getElementById('totalCount');
    
    totalCount.textContent = formatNumber(data.total);
    
    if (data.webhooks.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No webhook events found</p>
                </td>
            </tr>
        `;
        return;
    }
    
    const rows = data.webhooks.map(webhook => {
        const statusBadge = getStatusBadge(webhook.status);
        const processingTime = webhook.actual_processing_time_ms 
            ? `${webhook.actual_processing_time_ms.toFixed(0)}ms` 
            : '-';
        const retryCount = webhook.retry_count || 0;
        const createdAt = new Date(webhook.created_at).toLocaleString();
        
        return `
            <tr>
                <td>
                    <code class="small">${webhook.id.substring(0, 12)}...</code>
                </td>
                <td>
                    <span class="badge bg-light text-dark">${webhook.webhook_type}</span>
                </td>
                <td>
                    <span class="small">${webhook.event_type}</span>
                </td>
                <td>${statusBadge}</td>
                <td>
                    ${webhook.customer_name ? `
                        <div class="small">
                            <strong>${webhook.customer_name}</strong><br>
                            <span class="text-muted">${webhook.customer_ref || ''}</span>
                        </div>
                    ` : '-'}
                </td>
                <td>
                    ${webhook.order_id ? `<span class="badge bg-info">${webhook.order_id}</span>` : ''}
                    ${webhook.invoice_id ? `<span class="badge bg-warning">${webhook.invoice_id}</span>` : ''}
                </td>
                <td>
                    <span class="small ${processingTime === '-' ? 'text-muted' : ''}">${processingTime}</span>
                </td>
                <td>
                    ${retryCount > 0 ? `<span class="badge bg-warning">${retryCount}</span>` : '-'}
                </td>
                <td>
                    <span class="small text-muted">${createdAt}</span>
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary btn-sm" onclick="showWebhookDetail('${webhook.id}')" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        ${webhook.status === 'FAILED' || webhook.status === 'DLQ' ? `
                            <button class="btn btn-outline-warning btn-sm" onclick="retryWebhookFromList('${webhook.id}')" title="Retry">
                                <i class="fas fa-redo"></i>
                            </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
    
    // Update pagination
    updatePagination(data.total, data.limit, data.offset);
}

// Get status badge HTML
function getStatusBadge(status) {
    const badges = {
        'PROCESSED': '<span class="badge bg-success">Processed</span>',
        'FAILED': '<span class="badge bg-danger">Failed</span>',
        'RETRY': '<span class="badge bg-warning">Retrying</span>',
        'DLQ': '<span class="badge bg-dark">DLQ</span>',
        'DUPLICATE': '<span class="badge bg-secondary">Duplicate</span>',
        'PROCESSING': '<span class="badge bg-info">Processing</span>',
        'RECEIVED': '<span class="badge bg-light text-dark">Received</span>'
    };
    return badges[status] || `<span class="badge bg-secondary">${status}</span>`;
}

// Update pagination
function updatePagination(total, limit, offset) {
    const pagination = document.getElementById('pagination');
    const totalPages = Math.ceil(total / limit);
    const currentPageNum = Math.floor(offset / limit) + 1;
    
    if (totalPages <= 1) {
        pagination.innerHTML = '';
        return;
    }
    
    let html = '';
    
    // Previous button
    if (currentPageNum > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${currentPageNum - 2})">Previous</a></li>`;
    }
    
    // Page numbers
    const startPage = Math.max(1, currentPageNum - 2);
    const endPage = Math.min(totalPages, currentPageNum + 2);
    
    for (let i = startPage; i <= endPage; i++) {
        const active = i === currentPageNum ? 'active' : '';
        html += `<li class="page-item ${active}"><a class="page-link" href="#" onclick="changePage(${i - 1})">${i}</a></li>`;
    }
    
    // Next button
    if (currentPageNum < totalPages) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${currentPageNum})">Next</a></li>`;
    }
    
    pagination.innerHTML = html;
}

// Change page
function changePage(page) {
    currentPage = page;
    loadWebhooks();
}

// Get current filters
function getFilters() {
    const form = document.getElementById('filtersForm');
    const formData = new FormData(form);
    const filters = {};
    
    for (let [key, value] of formData.entries()) {
        if (value.trim() !== '') {
            filters[key] = value.trim();
        }
    }
    
    return filters;
}

// Show webhook detail modal
async function showWebhookDetail(webhookId) {
    currentWebhookId = webhookId;
    
    try {
        const response = await fetch('api/webhook-monitoring.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'detail',
                webhook_id: webhookId
            })
        });
        
        const result = await response.json();
        if (result.success) {
            displayWebhookDetail(result.data);
            new bootstrap.Modal(document.getElementById('webhookDetailModal')).show();
        } else {
            showError('Failed to load webhook details: ' + result.error);
        }
    } catch (error) {
        showError('Error loading webhook details: ' + error.message);
    }
}

// Display webhook detail
function displayWebhookDetail(webhook) {
    const content = document.getElementById('webhookDetailContent');
    const retryBtn = document.getElementById('retryWebhookBtn');
    
    // Show/hide retry button
    if (webhook.status === 'FAILED' || webhook.status === 'DLQ') {
        retryBtn.style.display = 'inline-block';
    } else {
        retryBtn.style.display = 'none';
    }
    
    const html = `
        <div class="row">
            <div class="col-md-6">
                <h6>Basic Information</h6>
                <table class="table table-sm">
                    <tr><td><strong>ID:</strong></td><td><code>${webhook.id}</code></td></tr>
                    <tr><td><strong>Type:</strong></td><td><span class="badge bg-light text-dark">${webhook.webhook_type}</span></td></tr>
                    <tr><td><strong>Event:</strong></td><td>${webhook.event_type}</td></tr>
                    <tr><td><strong>Status:</strong></td><td>${getStatusBadge(webhook.status)}</td></tr>
                    <tr><td><strong>Retry Count:</strong></td><td>${webhook.retry_count || 0}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6>Timing Information</h6>
                <table class="table table-sm">
                    <tr><td><strong>Received:</strong></td><td>${webhook.received_at ? new Date(webhook.received_at).toLocaleString() : '-'}</td></tr>
                    <tr><td><strong>Processing Started:</strong></td><td>${webhook.processing_started_at ? new Date(webhook.processing_started_at).toLocaleString() : '-'}</td></tr>
                    <tr><td><strong>Completed:</strong></td><td>${webhook.processing_completed_at ? new Date(webhook.processing_completed_at).toLocaleString() : '-'}</td></tr>
                    <tr><td><strong>Processing Time:</strong></td><td>${webhook.actual_processing_time_ms ? webhook.actual_processing_time_ms.toFixed(2) + 'ms' : '-'}</td></tr>
                </table>
            </div>
        </div>
        
        ${webhook.error_message ? `
            <div class="row mt-3">
                <div class="col-12">
                    <h6>Error Information</h6>
                    <div class="alert alert-danger">
                        <strong>Error:</strong> ${webhook.error_message}
                        ${webhook.last_error_code ? `<br><strong>Code:</strong> ${webhook.last_error_code}` : ''}
                    </div>
                </div>
            </div>
        ` : ''}
        
        <div class="row mt-3">
            <div class="col-12">
                <h6>Payload</h6>
                <pre class="bg-light p-3 rounded" style="max-height: 300px; overflow-y: auto;"><code>${JSON.stringify(webhook.payload, null, 2)}</code></pre>
            </div>
        </div>
        
        ${webhook.metadata ? `
            <div class="row mt-3">
                <div class="col-12">
                    <h6>Metadata</h6>
                    <pre class="bg-light p-3 rounded" style="max-height: 200px; overflow-y: auto;"><code>${JSON.stringify(webhook.metadata, null, 2)}</code></pre>
                </div>
            </div>
        ` : ''}
    `;
    
    content.innerHTML = html;
}

// Retry webhook
async function retryWebhook() {
    if (!currentWebhookId) return;
    
    try {
        const response = await fetch('api/webhook-monitoring.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'retry',
                webhook_id: currentWebhookId
            })
        });
        
        const result = await response.json();
        if (result.success && result.data.success) {
            showSuccess('Webhook retry scheduled successfully');
            bootstrap.Modal.getInstance(document.getElementById('webhookDetailModal')).hide();
            loadWebhooks();
        } else {
            showError('Failed to retry webhook: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        showError('Error retrying webhook: ' + error.message);
    }
}

// Retry webhook from list
async function retryWebhookFromList(webhookId) {
    currentWebhookId = webhookId;
    await retryWebhook();
}

// Show bulk retry modal
function showBulkRetryModal() {
    new bootstrap.Modal(document.getElementById('bulkRetryModal')).show();
}

// Execute bulk retry
async function executeBulkRetry() {
    const form = document.getElementById('bulkRetryForm');
    const formData = new FormData(form);
    const filters = {};
    
    for (let [key, value] of formData.entries()) {
        if (value.trim() !== '') {
            filters[key] = value.trim();
        }
    }
    
    try {
        const response = await fetch('api/webhook-monitoring.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'bulk_retry',
                filters: filters
            })
        });
        
        const result = await response.json();
        if (result.success) {
            const data = result.data;
            showSuccess(`Bulk retry completed: ${data.retry_scheduled} webhooks scheduled for retry, ${data.failed_to_schedule} failed`);
            bootstrap.Modal.getInstance(document.getElementById('bulkRetryModal')).hide();
            loadWebhooks();
        } else {
            showError('Failed to execute bulk retry: ' + result.error);
        }
    } catch (error) {
        showError('Error executing bulk retry: ' + error.message);
    }
}

// Refresh dashboard
function refreshDashboard() {
    loadStatistics();
    loadWebhooks();
    showSuccess('Dashboard refreshed');
}

// Export webhooks
function exportWebhooks() {
    const filters = getFilters();
    const params = new URLSearchParams({
        action: 'list',
        limit: 10000,
        ...filters
    });
    
    window.open(`api/webhook-monitoring.php?${params.toString()}&export=csv`, '_blank');
}

// Utility functions
function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

function showSuccess(message) {
    // You can implement toast notifications here
    console.log('Success:', message);
}

function showError(message) {
    // You can implement toast notifications here
    console.error('Error:', message);
    alert('Error: ' + message);
}
</script>

<?php require_once 'includes/footer.php'; ?>