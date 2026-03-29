<?php
/**
 * All Deals List
 * CRM Dashboard Advanced
 */
?>

<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
        <input type="text" id="deals-search" placeholder="Search deals..." 
               class="form-control text-sm w-64" onkeyup="if(event.key==='Enter')loadDealsList()">
        <select id="deals-stage-filter" class="form-control text-sm" onchange="loadDealsList()">
            <option value="">All Stages</option>
            <option value="lead">Lead</option>
            <option value="qualified">Qualified</option>
            <option value="proposal">Proposal</option>
            <option value="negotiation">Negotiation</option>
            <option value="closed_won">Closed Won</option>
            <option value="closed_lost">Closed Lost</option>
        </select>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openAddDealModal()">
        <i class="bi bi-plus-lg"></i> Add Deal
    </button>
</div>

<div class="section-card">
    <div class="section-body p-0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Deal</th>
                    <th>Customer</th>
                    <th>Value</th>
                    <th>Stage</th>
                    <th>Probability</th>
                    <th>Expected Close</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="all-deals-table">
                <tr>
                    <td colspan="7" class="text-center py-4 text-gray-400">Loading deals...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function loadDealsList() {
    const filters = {
        stage: document.getElementById('deals-stage-filter')?.value || '',
        search: document.getElementById('deals-search')?.value || '',
        limit: 50
    };
    
    crmApi('deals', filters).then(result => {
        if (result.success) {
            renderAllDealsTable(result.data.deals);
        }
    });
}

function renderAllDealsTable(deals) {
    const tbody = document.getElementById('all-deals-table');
    
    if (!deals || deals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-gray-400">No deals found</td></tr>';
        return;
    }
    
    const stageBadges = {
        'lead': 'badge-gray',
        'qualified': 'badge-blue',
        'proposal': 'badge-purple',
        'negotiation': 'badge-yellow',
        'closed_won': 'badge-green',
        'closed_lost': 'badge-red'
    };
    
    tbody.innerHTML = deals.map(d => `
        <tr>
            <td class="font-medium">${d.title}</td>
            <td>${d.customer_name || 'Unknown'}</td>
            <td class="font-mono">฿${parseFloat(d.value || 0).toLocaleString()}</td>
            <td><span class="${stageBadges[d.stage] || 'badge-gray'}">${d.stage}</span></td>
            <td>${d.probability || 0}%</td>
            <td class="text-gray-500">${d.expected_close || '-'}</td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="viewDeal(${d.id})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

function viewDeal(dealId) {
    alert('View deal: ' + dealId);
}

// Load on section show
document.addEventListener('DOMContentLoaded', function() {
    // Deals data is loaded when section is shown
});
</script>
