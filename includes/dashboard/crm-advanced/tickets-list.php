<?php
/**
 * All Tickets List
 * CRM Dashboard Advanced
 */
?>

<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
        <input type="text" id="all-tickets-search" placeholder="Search tickets..." 
               class="form-control text-sm w-64" onkeyup="if(event.key==='Enter')loadTicketsList()">
        <select id="all-tickets-status" class="form-control text-sm" onchange="loadTicketsList()">
            <option value="">All Status</option>
            <option value="open">Open</option>
            <option value="pending">Pending</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
        </select>
    </div>
</div>

<div class="section-card">
    <div class="section-body p-0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Customer</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="all-tickets-table">
                <tr>
                    <td colspan="7" class="text-center py-4 text-gray-400">Loading tickets...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function loadTicketsList() {
    const filters = {
        status: document.getElementById('all-tickets-status')?.value || '',
        limit: 50
    };
    
    crmApi('tickets', filters).then(result => {
        if (result.success) {
            renderAllTicketsTable(result.data.tickets);
        }
    });
}

function renderAllTicketsTable(tickets) {
    const tbody = document.getElementById('all-tickets-table');
    
    if (!tickets || tickets.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-gray-400">No tickets found</td></tr>';
        return;
    }
    
    const statusBadges = {
        'open': 'badge-blue',
        'pending': 'badge-yellow',
        'resolved': 'badge-green',
        'closed': 'badge-gray'
    };
    
    const priorityBadges = {
        'urgent': 'badge-red',
        'high': 'badge-red',
        'medium': 'badge-yellow',
        'low': 'badge-gray'
    };
    
    tbody.innerHTML = tickets.map(t => `
        <tr>
            <td class="font-mono text-xs">#${t.id}</td>
            <td>${t.customer_name || 'Unknown'}</td>
            <td class="font-medium">${t.subject}</td>
            <td><span class="${statusBadges[t.status] || 'badge-gray'}">${t.status}</span></td>
            <td><span class="${priorityBadges[t.priority] || 'badge-gray'}">${t.priority}</span></td>
            <td class="text-gray-500">${new Date(t.created_at).toLocaleDateString('th-TH')}</td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="viewTicket(${t.id})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

// Load on section show
document.addEventListener('DOMContentLoaded', function() {
    // Tickets data is loaded when section is shown
});
</script>
