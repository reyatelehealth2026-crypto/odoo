<?php
/**
 * Marketing Hub - Campaigns & Segments
 * CRM Dashboard Advanced
 */
?>

<!-- Marketing Stats -->
<div class="grid grid-cols-4 gap-3 mb-4">
    <div class="metric-card">
        <div class="metric-label">Active Campaigns</div>
        <div class="metric-value text-purple-600" id="marketing-active">--</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Total Sent</div>
        <div class="metric-value" id="marketing-sent">--</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Open Rate</div>
        <div class="metric-value text-blue-600" id="marketing-open">--%</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Conversion</div>
        <div class="metric-value text-green-600" id="marketing-conversion">--%</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Campaigns -->
    <div class="lg:col-span-2">
        <div class="section-card">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-send text-purple-600"></i>
                    <span>Drip Campaigns</span>
                </div>
                <a href="drip-campaigns.php" class="text-xs text-blue-600 hover:underline">Manage All</a>
            </div>
            <div class="section-body p-0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Status</th>
                            <th>Active Users</th>
                            <th>Steps</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="campaigns-table">
                        <tr>
                            <td colspan="5" class="text-center py-4 text-gray-400">Loading campaigns...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Segments -->
    <div>
        <div class="section-card">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-people-fill text-blue-600"></i>
                    <span>Segments</span>
                </div>
            </div>
            <div class="section-body">
                <div id="segments-list" class="space-y-2">
                    <div class="text-center py-4 text-gray-400">Loading...</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Broadcast -->
        <div class="section-card mt-4">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-broadcast text-green-600"></i>
                    <span>Quick Broadcast</span>
                </div>
            </div>
            <div class="section-body">
                <a href="broadcast.php" class="btn btn-primary w-full">
                    <i class="bi bi-megaphone"></i> Send Broadcast
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function loadMarketingData() {
    crmApi('campaigns').then(result => {
        if (result.success) {
            renderCampaignsTable(result.data);
        }
    });
    
    crmApi('segments').then(result => {
        if (result.success) {
            renderSegmentsList(result.data);
        }
    });
}

function renderCampaignsTable(campaigns) {
    const tbody = document.getElementById('campaigns-table');
    
    if (!campaigns || campaigns.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-gray-400">No campaigns</td></tr>';
        return;
    }
    
    tbody.innerHTML = campaigns.map(c => `
        <tr>
            <td class="font-medium">${c.name}</td>
            <td>
                <span class="badge ${c.is_active ? 'badge-green' : 'badge-gray'}">
                    ${c.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td>${c.active_users || 0}</td>
            <td>${c.step_count || 0}</td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="viewCampaign(${c.id})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>
    `).join('');
    
    // Update stats
    document.getElementById('marketing-active').textContent = campaigns.filter(c => c.is_active).length;
}

function renderSegmentsList(segments) {
    const container = document.getElementById('segments-list');
    
    if (!segments || segments.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-gray-400">No segments</div>';
        return;
    }
    
    container.innerHTML = segments.map(s => `
        <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
            <div>
                <p class="font-medium text-sm">${s.name}</p>
                <p class="text-xs text-gray-500">${s.description || ''}</p>
            </div>
            <span class="badge badge-blue">${s.count || 0}</span>
        </div>
    `).join('');
}

function viewCampaign(campaignId) {
    window.location.href = 'drip-campaigns.php?id=' + campaignId;
}

// Load on section show
document.addEventListener('DOMContentLoaded', function() {
    // Marketing data is loaded when section is shown
});
</script>
