<?php
/**
 * Reports Section
 * CRM Dashboard Advanced
 */
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
    <div class="metric-card cursor-pointer hover:border-blue-400" onclick="generateReport('sales')">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="bi bi-graph-up text-blue-600"></i>
            </div>
            <div>
                <div class="font-semibold">Sales Report</div>
                <div class="text-xs text-gray-500">Revenue & deals</div>
            </div>
        </div>
    </div>
    
    <div class="metric-card cursor-pointer hover:border-green-400" onclick="generateReport('customers')">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="bi bi-people text-green-600"></i>
            </div>
            <div>
                <div class="font-semibold">Customer Report</div>
                <div class="text-xs text-gray-500">Acquisition & retention</div>
            </div>
        </div>
    </div>
    
    <div class="metric-card cursor-pointer hover:border-purple-400" onclick="generateReport('team')">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="bi bi-briefcase text-purple-600"></i>
            </div>
            <div>
                <div class="font-semibold">Team Performance</div>
                <div class="text-xs text-gray-500">Individual metrics</div>
            </div>
        </div>
    </div>
</div>

<div class="section-card">
    <div class="section-header">
        <span>Generated Reports</span>
    </div>
    <div class="section-body">
        <div id="reports-list" class="space-y-2">
            <div class="text-center py-8 text-gray-400">
                <i class="bi bi-file-earmark-text text-4xl mb-2"></i>
                <p>Select a report type above to generate</p>
            </div>
        </div>
    </div>
</div>

<script>
function generateReport(type) {
    const container = document.getElementById('reports-list');
    container.innerHTML = '<div class="text-center py-4"><i class="bi bi-arrow-repeat spin"></i> Generating...</div>';
    
    // Simulate report generation
    setTimeout(() => {
        container.innerHTML = `
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-3">
                    <i class="bi bi-file-earmark-pdf text-red-500 text-xl"></i>
                    <div>
                        <p class="font-medium">${type.charAt(0).toUpperCase() + type.slice(1)} Report</p>
                        <p class="text-xs text-gray-500">Generated ${new Date().toLocaleString('th-TH')}</p>
                    </div>
                </div>
                <button class="btn btn-sm btn-primary">
                    <i class="bi bi-download"></i> Download
                </button>
            </div>
        `;
    }, 1000);
}
</script>
