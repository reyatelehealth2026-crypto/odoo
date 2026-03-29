<?php
/**
 * Service Center - Ticket Management
 * CRM Dashboard Advanced
 */
?>

<!-- Service Stats -->
<div class="grid grid-cols-4 gap-3 mb-4">
    <div class="metric-card">
        <div class="metric-label">Open Tickets</div>
        <div class="metric-value text-blue-600" id="service-open">--</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">In Progress</div>
        <div class="metric-value text-yellow-600" id="service-pending">--</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">SLA Breach</div>
        <div class="metric-value text-red-600" id="service-breach">--</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Resolved Today</div>
        <div class="metric-value text-green-600" id="service-resolved">--</div>
    </div>
</div>

<!-- Ticket Filters -->
<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
        <select id="ticket-status-filter" class="form-control text-sm" onchange="loadServiceData()">
            <option value="">All Status</option>
            <option value="open">Open</option>
            <option value="pending">Pending</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
        </select>
        
        <select id="ticket-priority-filter" class="form-control text-sm" onchange="loadServiceData()">
            <option value="">All Priority</option>
            <option value="urgent">Urgent</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
        </select>
    </div>
    
    <button class="btn btn-primary btn-sm" onclick="openCreateTicketModal()">
        <i class="bi bi-plus-lg"></i> Create Ticket
    </button>
</div>

<!-- Tickets Table -->
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
                    <th>SLA</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tickets-table">
                <tr>
                    <td colspan="8" class="text-center py-4 text-gray-400">Loading tickets...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Ticket Modal -->
<div id="createTicketModal" class="modal-backdrop" style="display: none;" onclick="if(event.target === this) closeCreateTicketModal()">
    <div class="modal-container" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="font-semibold">Create Support Ticket</h3>
            <button onclick="closeCreateTicketModal()" class="text-gray-400 hover:text-gray-600">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="createTicketForm" onsubmit="submitCreateTicket(event)">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                        <select name="customer_id" required class="form-control w-full">
                            <option value="">Select customer...</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                        <input type="text" name="subject" required class="form-control w-full" placeholder="Brief description of the issue">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                            <select name="priority" class="form-control w-full">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <select name="category" class="form-control w-full">
                                <option value="general">General</option>
                                <option value="technical">Technical</option>
                                <option value="billing">Billing</option>
                                <option value="sales">Sales</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="4" class="form-control w-full" placeholder="Detailed description of the issue..."></textarea>
                    </div>
                </div>
                
                <div class="flex gap-2 mt-6">
                    <button type="button" onclick="closeCreateTicketModal()" class="btn btn-secondary flex-1">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Create Ticket</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function loadServiceData() {
    const filters = {
        status: document.getElementById('ticket-status-filter')?.value || '',
        priority: document.getElementById('ticket-priority-filter')?.value || '',
        limit: 50
    };
    
    crmApi('tickets', filters).then(result => {
        if (result.success) {
            renderTicketsTable(result.data.tickets);
        }
    });
    
    crmApi('ticket_stats').then(result => {
        if (result.success) {
            updateServiceStats(result.data);
        }
    });
}

function renderTicketsTable(tickets) {
    const tbody = document.getElementById('tickets-table');
    
    if (!tickets || tickets.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-gray-400">No tickets found</td></tr>';
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
    
    tbody.innerHTML = tickets.map(ticket => `
        <tr>
            <td class="font-mono text-xs">#${ticket.id}</td>
            <td>
                <div class="flex items-center gap-2">
                    <img src="${ticket.customer_avatar || 'https://via.placeholder.com/24'}" class="w-6 h-6 rounded-full">
                    <span class="text-sm">${ticket.customer_name || 'Unknown'}</span>
                </div>
            </td>
            <td class="font-medium text-sm">${ticket.subject}</td>
            <td><span class="${statusBadges[ticket.status] || 'badge-gray'}">${ticket.status}</span></td>
            <td><span class="${priorityBadges[ticket.priority] || 'badge-gray'}">${ticket.priority}</span></td>
            <td class="text-sm ${isSlaBreached(ticket) ? 'text-red-600 font-medium' : 'text-gray-500'}">
                ${formatSla(ticket.sla_deadline)}
            </td>
            <td class="text-sm text-gray-500">${formatDate(ticket.created_at)}</td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="viewTicket(${ticket.id})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

function updateServiceStats(stats) {
    document.getElementById('service-open').textContent = stats.by_status?.open || 0;
    document.getElementById('service-pending').textContent = stats.by_status?.pending || 0;
    document.getElementById('service-breach').textContent = (stats.breached_sla || 0) + (stats.approaching_sla || 0);
    document.getElementById('service-resolved').textContent = stats.by_status?.resolved || 0;
}

function isSlaBreached(ticket) {
    if (!ticket.sla_deadline) return false;
    return new Date(ticket.sla_deadline) < new Date();
}

function formatSla(slaDeadline) {
    if (!slaDeadline) return '-';
    const diff = new Date(slaDeadline) - new Date();
    if (diff < 0) return 'BREACHED';
    const hours = Math.floor(diff / 3600000);
    return hours + 'h left';
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

function openCreateTicketModal() {
    crmApi('customers', { limit: 100 }).then(result => {
        if (result.success) {
            const select = document.querySelector('#createTicketForm select[name="customer_id"]');
            select.innerHTML = '<option value="">Select customer...</option>' +
                result.data.customers.map(c => 
                    `<option value="${c.id}">${c.display_name || c.line_user_id}</option>`
                ).join('');
        }
    });
    
    document.getElementById('createTicketModal').style.display = 'flex';
}

function closeCreateTicketModal() {
    document.getElementById('createTicketModal').style.display = 'none';
    document.getElementById('createTicketForm').reset();
}

function submitCreateTicket(event) {
    event.preventDefault();
    
    const form = event.target;
    const data = {
        customer_id: form.customer_id.value,
        subject: form.subject.value,
        priority: form.priority.value,
        category: form.category.value,
        description: form.description.value
    };
    
    crmApi('ticket_create', data).then(result => {
        if (result.success) {
            closeCreateTicketModal();
            loadServiceData();
        } else {
            alert('Failed to create ticket: ' + (result.error || 'Unknown error'));
        }
    });
}

function viewTicket(ticketId) {
    alert('View ticket: ' + ticketId);
}

// Load on section show
document.addEventListener('DOMContentLoaded', function() {
    // Service data is loaded when section is shown
});
</script>
