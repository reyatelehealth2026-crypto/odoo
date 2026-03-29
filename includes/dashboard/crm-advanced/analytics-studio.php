<?php
/**
 * Analytics Studio - Charts & Reports
 * CRM Dashboard Advanced
 */
?>

<!-- Analytics Filters -->
<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
        <select id="analytics-period" class="form-control text-sm" onchange="loadAnalyticsData()">
            <option value="7d">Last 7 Days</option>
            <option value="30d" selected>Last 30 Days</option>
            <option value="90d">Last 90 Days</option>
            <option value="1y">Last Year</option>
        </select>
    </div>
    
    <div class="flex items-center gap-2">
        <button class="btn btn-secondary btn-sm" onclick="exportReport()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Charts Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <div class="section-card">
        <div class="section-header">
            <div class="flex items-center gap-2">
                <i class="bi bi-graph-up text-blue-600"></i>
                <span>Revenue Trend</span>
            </div>
        </div>
        <div class="section-body">
            <canvas id="analyticsRevenueChart" height="250"></canvas>
        </div>
    </div>
    
    <div class="section-card">
        <div class="section-header">
            <div class="flex items-center gap-2">
                <i class="bi bi-kanban text-purple-600"></i>
                <span>Deals by Stage</span>
            </div>
        </div>
        <div class="section-body">
            <canvas id="analyticsDealsChart" height="250"></canvas>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="section-card">
        <div class="section-header">
            <div class="flex items-center gap-2">
                <i class="bi bi-people text-green-600"></i>
                <span>Customer Growth</span>
            </div>
        </div>
        <div class="section-body">
            <canvas id="analyticsCustomerChart" height="250"></canvas>
        </div>
    </div>
    
    <div class="section-card">
        <div class="section-header">
            <div class="flex items-center gap-2">
                <i class="bi bi-headset text-yellow-600"></i>
                <span>Ticket Resolution</span>
            </div>
        </div>
        <div class="section-body">
            <canvas id="analyticsTicketChart" height="250"></canvas>
        </div>
    </div>
</div>

<script>
let analyticsRevenueChart, analyticsDealsChart, analyticsCustomerChart, analyticsTicketChart;

function loadAnalyticsData() {
    const period = document.getElementById('analytics-period')?.value || '30d';
    
    crmApi('analytics_revenue', { period }).then(result => {
        if (result.success) {
            renderAnalyticsRevenueChart(result.data);
        }
    });
    
    crmApi('pipeline').then(result => {
        if (result.success) {
            renderAnalyticsDealsChart(result.data);
        }
    });
    
    // Customer growth (placeholder)
    renderAnalyticsCustomerChart();
    
    // Ticket resolution (placeholder)
    renderAnalyticsTicketChart();
}

function renderAnalyticsRevenueChart(data) {
    const ctx = document.getElementById('analyticsRevenueChart').getContext('2d');
    
    if (analyticsRevenueChart) {
        analyticsRevenueChart.destroy();
    }
    
    const labels = data.daily?.map(d => d.date) || [];
    const values = data.daily?.map(d => d.revenue) || [];
    
    analyticsRevenueChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue',
                data: values,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { ticks: { callback: value => '฿' + (value / 1000).toFixed(0) + 'k' } }
            }
        }
    });
}

function renderAnalyticsDealsChart(data) {
    const ctx = document.getElementById('analyticsDealsChart').getContext('2d');
    
    if (analyticsDealsChart) {
        analyticsDealsChart.destroy();
    }
    
    const stages = data.stages || [];
    const labels = stages.map(s => s.name);
    const values = stages.map(s => s.count);
    
    analyticsDealsChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Deals',
                data: values,
                backgroundColor: ['#94a3b8', '#3b82f6', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });
}

function renderAnalyticsCustomerChart() {
    const ctx = document.getElementById('analyticsCustomerChart').getContext('2d');
    
    if (analyticsCustomerChart) {
        analyticsCustomerChart.destroy();
    }
    
    // Mock data for now
    const labels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
    const values = [45, 52, 48, 61];
    
    analyticsCustomerChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'New Customers',
                data: values,
                backgroundColor: '#10b981'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });
}

function renderAnalyticsTicketChart() {
    const ctx = document.getElementById('analyticsTicketChart').getContext('2d');
    
    if (analyticsTicketChart) {
        analyticsTicketChart.destroy();
    }
    
    analyticsTicketChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Open', 'Pending', 'Resolved', 'Closed'],
            datasets: [{
                data: [12, 8, 45, 23],
                backgroundColor: ['#3b82f6', '#f59e0b', '#10b981', '#94a3b8']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
}

function exportReport() {
    alert('Export functionality - would generate PDF/Excel report');
}

// Load on section show
document.addEventListener('DOMContentLoaded', function() {
    // Analytics data is loaded when section is shown
});
</script>
