<?php
/**
 * Customers List
 * CRM Dashboard Advanced
 */
?>

<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
        <input type="text" id="customer-search" placeholder="Search customers..." 
               class="form-control text-sm w-64" onkeyup="if(event.key==='Enter')loadCustomersList()">
        <select id="customer-tag-filter" class="form-control text-sm" onchange="loadCustomersList()">
            <option value="">All Tags</option>
        </select>
    </div>
    <button class="btn btn-primary btn-sm" onclick="showSection('customers')">
        <i class="bi bi-arrow-clockwise"></i> Refresh
    </button>
</div>

<div class="section-card">
    <div class="section-body p-0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Tags</th>
                    <th>Deals</th>
                    <th>Tickets</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="customers-table">
                <tr>
                    <td colspan="7" class="text-center py-4 text-gray-400">Loading customers...</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="section-body border-t">
        <div class="flex items-center justify-between">
            <span class="text-sm text-gray-500" id="customers-pagination-info">Loading...</span>
            <div class="flex gap-2">
                <button class="btn btn-sm btn-secondary" onclick="prevCustomersPage()">Previous</button>
                <button class="btn btn-sm btn-secondary" onclick="nextCustomersPage()">Next</button>
            </div>
        </div>
    </div>
</div>

<script>
let customersOffset = 0;
const customersLimit = 50;

function loadCustomersList() {
    const filters = {
        search: document.getElementById('customer-search')?.value || '',
        tag_id: document.getElementById('customer-tag-filter')?.value || '',
        limit: customersLimit,
        offset: customersOffset
    };
    
    crmApi('customers', filters).then(result => {
        if (result.success) {
            renderCustomersTable(result.data.customers, result.data.total);
        }
    });
}

function renderCustomersTable(customers, total) {
    const tbody = document.getElementById('customers-table');
    
    if (!customers || customers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-gray-400">No customers found</td></tr>';
        return;
    }
    
    tbody.innerHTML = customers.map(c => `
        <tr>
            <td>
                <div class="flex items-center gap-2">
                    <img src="${c.picture_url || 'https://via.placeholder.com/32'}" class="w-8 h-8 rounded-full">
                    <div>
                        <p class="font-medium text-sm">${c.display_name || 'Unknown'}</p>
                        <p class="text-xs text-gray-500">${c.line_user_id || ''}</p>
                    </div>
                </div>
            </td>
            <td class="text-sm">${c.phone || '-'}</td>
            <td>
                ${c.tags ? c.tags.split(', ').map(t => `<span class="badge badge-blue text-xs">${t}</span>`).join(' ') : '-'}
            </td>
            <td>
                <span class="${c.deals_count > 0 ? 'badge badge-purple' : 'badge badge-gray'}">${c.deals_count || 0}</span>
            </td>
            <td>
                <span class="${c.tickets_count > 0 ? 'badge badge-yellow' : 'badge badge-gray'}">${c.tickets_count || 0}</span>
            </td>
            <td class="text-sm text-gray-500">${formatDate(c.created_at)}</td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="openCustomer360(${c.id})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>
    `).join('');
    
    document.getElementById('customers-pagination-info').textContent = 
        `Showing ${customersOffset + 1}-${Math.min(customersOffset + customers.length, total)} of ${total}`;
}

function prevCustomersPage() {
    if (customersOffset >= customersLimit) {
        customersOffset -= customersLimit;
        loadCustomersList();
    }
}

function nextCustomersPage() {
    customersOffset += customersLimit;
    loadCustomersList();
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' });
}

// Load on section show
document.addEventListener('DOMContentLoaded', function() {
    // Customers data is loaded when section is shown
});
</script>
