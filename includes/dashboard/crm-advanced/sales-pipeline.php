<?php
/**
 * Sales Pipeline - Kanban View
 * CRM Dashboard Advanced
 */
?>

<!-- Pipeline Controls -->
<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
        <div class="text-sm text-gray-500">
            Total Pipeline: <span class="font-semibold text-gray-800" id="pipeline-total-value">฿0</span>
            (<span id="pipeline-total-deals">0</span> deals)
        </div>
        <div class="flex items-center gap-2 text-sm">
            <span class="badge badge-green">Win Rate: <span id="pipeline-win-rate">0%</span></span>
        </div>
    </div>
    
    <div class="flex items-center gap-2">
        <select id="pipeline-filter-salesperson" class="form-control text-sm py-1.5 px-3" onchange="loadPipelineData()">
            <option value="">All Salespeople</option>
            <!-- Populated dynamically -->
        </select>
        
        <select id="pipeline-filter-source" class="form-control text-sm py-1.5 px-3" onchange="loadPipelineData()">
            <option value="">All Sources</option>
            <option value="manual">Manual</option>
            <option value="website">Website</option>
            <option value="referral">Referral</option>
            <option value="line">LINE</option>
        </select>
        
        <button class="btn btn-primary btn-sm" onclick="openAddDealModal()">
            <i class="bi bi-plus-lg"></i> Add Deal
        </button>
    </div>
</div>

<!-- Kanban Board -->
<div class="overflow-x-auto pb-4">
    <div class="flex gap-4 min-w-max" id="kanban-board">
        <!-- Columns loaded dynamically -->
    </div>
</div>

<!-- Deal Detail Modal -->
<div id="dealDetailModal" class="modal-backdrop" style="display: none;" onclick="if(event.target === this) closeDealDetail()">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="font-semibold">Deal Details</h3>
            <button onclick="closeDealDetail()" class="text-gray-400 hover:text-gray-600">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body" id="deal-detail-content">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>

<!-- Add Deal Modal -->
<div id="addDealModal" class="modal-backdrop" style="display: none;" onclick="if(event.target === this) closeAddDealModal()">
    <div class="modal-container" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="font-semibold">Add New Deal</h3>
            <button onclick="closeAddDealModal()" class="text-gray-400 hover:text-gray-600">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="addDealForm" onsubmit="submitAddDeal(event)">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                        <select name="customer_id" required class="form-control w-full">
                            <option value="">Select customer...</option>
                            <!-- Populated dynamically -->
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Deal Title</label>
                        <input type="text" name="title" required class="form-control w-full" placeholder="e.g., Enterprise Software Package">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Value (฿)</label>
                            <input type="number" name="value" required class="form-control w-full" placeholder="50000">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Stage</label>
                            <select name="stage" class="form-control w-full">
                                <option value="lead">New Lead</option>
                                <option value="qualified">Qualified</option>
                                <option value="proposal">Proposal</option>
                                <option value="negotiation">Negotiation</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Probability (%)</label>
                            <input type="number" name="probability" min="0" max="100" value="20" class="form-control w-full">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Expected Close</label>
                            <input type="date" name="expected_close" class="form-control w-full">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="3" class="form-control w-full" placeholder="Additional details about this deal..."></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
                        <select name="source" class="form-control w-full">
                            <option value="manual">Manual Entry</option>
                            <option value="website">Website</option>
                            <option value="referral">Referral</option>
                            <option value="line">LINE</option>
                            <option value="phone">Phone</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex gap-2 mt-6">
                    <button type="button" onclick="closeAddDealModal()" class="btn btn-secondary flex-1">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Create Deal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let draggedDealId = null;
let pipelineData = null;

function loadPipelineData() {
    crmApi('pipeline').then(result => {
        if (result.success) {
            pipelineData = result.data;
            renderKanbanBoard(result.data);
            updatePipelineSummary(result.data);
        }
    });
}

function renderKanbanBoard(data) {
    const container = document.getElementById('kanban-board');
    
    const stageColors = {
        'lead': 'border-t-4 border-gray-400',
        'qualified': 'border-t-4 border-blue-500',
        'proposal': 'border-t-4 border-purple-500',
        'negotiation': 'border-t-4 border-yellow-500',
        'closed_won': 'border-t-4 border-green-500',
        'closed_lost': 'border-t-4 border-red-500'
    };
    
    const stageBadges = {
        'lead': 'badge-gray',
        'qualified': 'badge-blue',
        'proposal': 'badge-purple',
        'negotiation': 'badge-yellow',
        'closed_won': 'badge-green',
        'closed_lost': 'badge-red'
    };
    
    container.innerHTML = data.stages.map(stage => `
        <div class="kanban-column ${stageColors[stage.id] || ''}">
            <div class="kanban-header">
                <span class="font-semibold">${stage.name}</span>
                <span class="kanban-count">${stage.count}</span>
            </div>
            <div class="kanban-body kanban-drop-zone" 
                 data-stage="${stage.id}"
                 ondragover="handleDragOver(event)"
                 ondragleave="handleDragLeave(event)"
                 ondrop="handleDrop(event, '${stage.id}')">
                ${stage.deals.map(deal => renderDealCard(deal, stageBadges)).join('')}
            </div>
        </div>
    `).join('');
}

function renderDealCard(deal, stageBadges) {
    return `
        <div class="deal-card ${stageBadges[deal.stage] || ''}" 
             draggable="true"
             data-deal-id="${deal.id}"
             ondragstart="handleDragStart(event, ${deal.id})"
             ondragend="handleDragEnd(event)"
             onclick="openDealDetail(${deal.id})">
            <div class="flex items-start justify-between mb-2">
                <span class="deal-value">฿${parseFloat(deal.value || 0).toLocaleString()}</span>
                <span class="deal-probability">${deal.probability || 0}%</span>
            </div>
            <h4 class="font-medium text-sm mb-1 truncate">${deal.title}</h4>
            <div class="flex items-center gap-2 mb-2">
                <img src="${deal.customer_avatar || 'https://via.placeholder.com/20'}" 
                     class="w-5 h-5 rounded-full">
                <span class="deal-customer truncate">${deal.customer_name || 'Unknown'}</span>
            </div>
            <div class="flex items-center justify-between text-xs text-gray-500">
                <span>${deal.expected_close ? formatDate(deal.expected_close) : 'No date'}</span>
                <span>${deal.source || 'manual'}</span>
            </div>
        </div>
    `;
}

function updatePipelineSummary(data) {
    document.getElementById('pipeline-total-value').textContent = '฿' + (data.total_value || 0).toLocaleString();
    document.getElementById('pipeline-total-deals').textContent = data.total_deals || 0;
    document.getElementById('pipeline-win-rate').textContent = (data.win_rate || 0).toFixed(1) + '%';
}

// Drag and Drop Handlers
function handleDragStart(event, dealId) {
    draggedDealId = dealId;
    event.target.classList.add('dragging');
    event.dataTransfer.effectAllowed = 'move';
}

function handleDragEnd(event) {
    event.target.classList.remove('dragging');
    document.querySelectorAll('.kanban-drop-zone').forEach(zone => {
        zone.classList.remove('drag-over');
    });
}

function handleDragOver(event) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    event.currentTarget.classList.add('drag-over');
}

function handleDragLeave(event) {
    event.currentTarget.classList.remove('drag-over');
}

function handleDrop(event, newStage) {
    event.preventDefault();
    event.currentTarget.classList.remove('drag-over');
    
    if (!draggedDealId) return;
    
    // Find the deal's current stage
    let currentStage = null;
    for (const stage of pipelineData.stages) {
        if (stage.deals.find(d => d.id == draggedDealId)) {
            currentStage = stage.id;
            break;
        }
    }
    
    // Only move if stage changed
    if (currentStage !== newStage) {
        moveDeal(draggedDealId, newStage);
    }
    
    draggedDealId = null;
}

function moveDeal(dealId, newStage) {
    crmApi('deal_move', { deal_id: dealId, stage: newStage }).then(result => {
        if (result.success) {
            // Reload pipeline to reflect changes
            loadPipelineData();
        } else {
            alert('Failed to move deal: ' + (result.error || 'Unknown error'));
        }
    });
}

function openDealDetail(dealId) {
    // Find deal in current data
    let deal = null;
    for (const stage of pipelineData.stages) {
        deal = stage.deals.find(d => d.id == dealId);
        if (deal) break;
    }
    
    if (!deal) return;
    
    const modal = document.getElementById('dealDetailModal');
    const content = document.getElementById('deal-detail-content');
    
    const stageLabels = {
        'lead': 'New Lead',
        'qualified': 'Qualified',
        'proposal': 'Proposal',
        'negotiation': 'Negotiation',
        'closed_won': 'Closed Won',
        'closed_lost': 'Closed Lost'
    };
    
    const stageBadges = {
        'lead': 'badge-gray',
        'qualified': 'badge-blue',
        'proposal': 'badge-purple',
        'negotiation': 'badge-yellow',
        'closed_won': 'badge-green',
        'closed_lost': 'badge-red'
    };
    
    content.innerHTML = `
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div class="bg-gray-50 p-3 rounded-lg">
                <label class="text-xs text-gray-500 uppercase">Deal Value</label>
                <p class="text-xl font-mono font-semibold">฿${parseFloat(deal.value || 0).toLocaleString()}</p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg">
                <label class="text-xs text-gray-500 uppercase">Probability</label>
                <p class="text-xl font-semibold">${deal.probability || 0}%</p>
            </div>
        </div>
        
        <div class="mb-4">
            <label class="text-xs text-gray-500 uppercase">Stage</label>
            <p class="mt-1">
                <span class="${stageBadges[deal.stage] || 'badge-gray'}">
                    ${stageLabels[deal.stage] || deal.stage}
                </span>
            </p>
        </div>
        
        <div class="mb-4">
            <label class="text-xs text-gray-500 uppercase">Customer</label>
            <div class="flex items-center gap-2 mt-1">
                <img src="${deal.customer_avatar || 'https://via.placeholder.com/32'}" class="w-8 h-8 rounded-full">
                <span class="font-medium">${deal.customer_name || 'Unknown'}</span>
            </div>
        </div>
        
        <div class="mb-4">
            <label class="text-xs text-gray-500 uppercase">Expected Close</label>
            <p class="font-medium">${deal.expected_close || 'Not set'}</p>
        </div>
        
        ${deal.description ? `
            <div class="mb-4">
                <label class="text-xs text-gray-500 uppercase">Description</label>
                <p class="mt-1 text-gray-700">${deal.description}</p>
            </div>
        ` : ''}
        
        <div class="flex gap-2 mt-6">
            <button onclick="editDeal(${deal.id})" class="btn btn-primary flex-1">
                <i class="bi bi-pencil"></i> Edit Deal
            </button>
            ${deal.stage !== 'closed_won' && deal.stage !== 'closed_lost' ? `
                <button onclick="closeDeal(${deal.id}, 'won')" class="btn btn-secondary flex-1">
                    <i class="bi bi-check-lg"></i> Mark Won
                </button>
                <button onclick="closeDeal(${deal.id}, 'lost')" class="btn btn-secondary flex-1 text-red-600">
                    <i class="bi bi-x-lg"></i> Mark Lost
                </button>
            ` : ''}
        </div>
    `;
    
    modal.style.display = 'flex';
}

function closeDealDetail() {
    document.getElementById('dealDetailModal').style.display = 'none';
}

function openAddDealModal() {
    // Load customers for dropdown
    crmApi('customers', { limit: 100 }).then(result => {
        if (result.success) {
            const select = document.querySelector('#addDealForm select[name="customer_id"]');
            select.innerHTML = '<option value="">Select customer...</option>' +
                result.data.customers.map(c => 
                    `<option value="${c.id}">${c.display_name || c.line_user_id}</option>`
                ).join('');
        }
    });
    
    document.getElementById('addDealModal').style.display = 'flex';
}

function closeAddDealModal() {
    document.getElementById('addDealModal').style.display = 'none';
    document.getElementById('addDealForm').reset();
}

function submitAddDeal(event) {
    event.preventDefault();
    
    const form = event.target;
    const data = {
        customer_id: form.customer_id.value,
        title: form.title.value,
        value: parseFloat(form.value.value) || 0,
        stage: form.stage.value,
        probability: parseInt(form.probability.value) || 20,
        expected_close: form.expected_close.value || null,
        description: form.description.value,
        source: form.source.value
    };
    
    crmApi('deal_create', data).then(result => {
        if (result.success) {
            closeAddDealModal();
            loadPipelineData();
        } else {
            alert('Failed to create deal: ' + (result.error || 'Unknown error'));
        }
    });
}

function closeDeal(dealId, outcome) {
    const stage = outcome === 'won' ? 'closed_won' : 'closed_lost';
    
    if (confirm(`Are you sure you want to mark this deal as ${outcome}?`)) {
        crmApi('deal_move', { deal_id: dealId, stage: stage }).then(result => {
            if (result.success) {
                closeDealDetail();
                loadPipelineData();
            }
        });
    }
}

function editDeal(dealId) {
    // Would open edit modal
    alert('Edit functionality - deal ID: ' + dealId);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

// Load on section show
document.addEventListener('DOMContentLoaded', function() {
    // Pipeline data is loaded when section is shown
});
</script>
