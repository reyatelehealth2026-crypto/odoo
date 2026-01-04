// Testing Checklist Application
let testData = {};

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    testData = initializeTestData();
    renderCategories();      // Render first
    loadSavedData();         // Then load saved data
    setupEventListeners();
    updateStats();
});

// Render all categories
function renderCategories() {
    const container = document.getElementById('categories-container');
    container.innerHTML = '';
    
    testCategories.forEach(category => {
        const categoryEl = createCategoryElement(category);
        container.appendChild(categoryEl);
    });
}

// Create category element
function createCategoryElement(category) {
    const section = document.createElement('div');
    section.className = 'category-section';
    section.dataset.category = category.id;
    
    const totalTests = category.tests.length;
    
    section.innerHTML = `
        <div class="category-header">
            <div>
                <div class="category-title">${category.icon} ${category.title}</div>
                <small style="color: #666;">${totalTests} test cases</small>
            </div>
            <div class="category-progress">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 0%"></div>
                </div>
                <span class="progress-text">0/${totalTests}</span>
            </div>
        </div>
        <div class="tests-container"></div>
    `;
    
    const testsContainer = section.querySelector('.tests-container');
    category.tests.forEach(test => {
        const testEl = createTestElement(test);
        testsContainer.appendChild(testEl);
    });
    
    return section;
}

// Create test case element
function createTestElement(test) {
    const testCase = document.createElement('div');
    testCase.className = 'test-case';
    testCase.dataset.testId = test.id;
    
    const stepsHtml = test.steps.map((step, i) => `<li>${step}</li>`).join('');
    
    testCase.innerHTML = `
        <div class="test-case-header">
            <div class="test-title">${test.id}: ${test.title}</div>
            <div class="test-controls">
                <button class="status-btn btn-pass" onclick="setStatus('${test.id}', 'passed')">✓ Pass</button>
                <button class="status-btn btn-fail" onclick="setStatus('${test.id}', 'failed')">✗ Fail</button>
                <button class="status-btn btn-skip" onclick="setStatus('${test.id}', 'skipped')">⏭ Skip</button>
                <button class="collapse-btn" onclick="toggleDetails(this)">▼ Details</button>
            </div>
        </div>
        <div class="test-case-body">
            <div class="test-details">
                <strong>Prerequisites:</strong> ${test.prerequisites}
                <div class="test-steps">
                    <strong>Steps:</strong>
                    <ol>${stepsHtml}</ol>
                </div>
                <div class="test-expected">
                    <strong>Expected Result:</strong> ${test.expected}
                </div>
                <div class="test-files">
                    📁 Related Files: ${test.files}
                </div>
            </div>
            <div class="notes-section">
                <textarea class="notes-textarea" placeholder="Add notes or observations..." data-test-id="${test.id}"></textarea>
            </div>
        </div>
    `;
    
    return testCase;
}

// Set test status
function setStatus(testId, status) {
    testData[testId].status = status;
    
    const testCase = document.querySelector(`[data-test-id="${testId}"]`);
    testCase.classList.remove('status-passed', 'status-failed', 'status-skipped');
    
    if (status !== 'pending') {
        testCase.classList.add(`status-${status}`);
    }
    
    updateStats();
    updateCategoryProgress(testCase.closest('.category-section'));
    saveToLocalStorage();
}

// Toggle test details
function toggleDetails(btn) {
    const testCase = btn.closest('.test-case');
    const body = testCase.querySelector('.test-case-body');
    
    if (body.classList.contains('expanded')) {
        body.classList.remove('expanded');
        btn.textContent = '▼ Details';
    } else {
        body.classList.add('expanded');
        btn.textContent = '▲ Hide';
    }
}

// Update statistics
function updateStats() {
    const total = Object.keys(testData).length;
    let passed = 0, failed = 0, skipped = 0;
    
    Object.values(testData).forEach(test => {
        if (test.status === 'passed') passed++;
        else if (test.status === 'failed') failed++;
        else if (test.status === 'skipped') skipped++;
    });
    
    const completed = passed + failed + skipped;
    const completionRate = total > 0 ? Math.round((completed / total) * 100) : 0;
    
    document.getElementById('total-tests').textContent = total;
    document.getElementById('passed-tests').textContent = passed;
    document.getElementById('failed-tests').textContent = failed;
    document.getElementById('skipped-tests').textContent = skipped;
    document.getElementById('completion-rate').textContent = completionRate + '%';
}

// Update category progress
function updateCategoryProgress(category) {
    const testCases = category.querySelectorAll('.test-case');
    const total = testCases.length;
    let completed = 0;
    
    testCases.forEach(testCase => {
        if (testCase.classList.contains('status-passed') || 
            testCase.classList.contains('status-failed') || 
            testCase.classList.contains('status-skipped')) {
            completed++;
        }
    });
    
    const percentage = total > 0 ? (completed / total) * 100 : 0;
    const progressFill = category.querySelector('.progress-fill');
    const progressText = category.querySelector('.progress-text');
    
    progressFill.style.width = percentage + '%';
    progressText.textContent = `${completed}/${total}`;
}

// Load saved data from localStorage
function loadSavedData() {
    const saved = localStorage.getItem('testingChecklistData');
    console.log('Loading saved data:', saved ? 'Found' : 'Not found');
    if (saved) {
        try {
            const savedData = JSON.parse(saved);
            // Merge saved data with initialized data
            Object.keys(savedData).forEach(testId => {
                if (testData[testId]) {
                    testData[testId] = savedData[testId];
                }
            });
            console.log('Loaded test data:', testData);
            applyLoadedData();
        } catch (e) {
            console.error('Error parsing saved data:', e);
        }
    }
}

// Apply loaded data to UI
function applyLoadedData() {
    console.log('Applying loaded data to UI...');
    Object.keys(testData).forEach(testId => {
        const data = testData[testId];
        const testCase = document.querySelector(`[data-test-id="${testId}"]`);
        
        if (!testCase) {
            console.log('Test case not found:', testId);
            return;
        }
        
        // Remove existing status classes
        testCase.classList.remove('status-passed', 'status-failed', 'status-skipped');
        
        if (data.status && data.status !== 'pending') {
            testCase.classList.add(`status-${data.status}`);
            console.log(`Applied status ${data.status} to ${testId}`);
        }
        
        if (data.notes) {
            const textarea = testCase.querySelector('.notes-textarea');
            if (textarea) textarea.value = data.notes;
        }
    });
    
    // Update all category progress bars
    document.querySelectorAll('.category-section').forEach(updateCategoryProgress);
    
    // Update stats
    updateStats();
}

// Save to localStorage
function saveToLocalStorage() {
    // Save notes from textareas
    document.querySelectorAll('.notes-textarea').forEach(textarea => {
        const testId = textarea.dataset.testId;
        if (testData[testId]) {
            testData[testId].notes = textarea.value;
        }
    });
    
    localStorage.setItem('testingChecklistData', JSON.stringify(testData));
}

// Save results to server
function saveResults() {
    saveToLocalStorage();
    
    fetch('api/testing-results.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            testData: testData,
            timestamp: new Date().toISOString()
        })
    })
    .then(response => {
        // Check if response is ok and has content
        if (!response.ok) {
            throw new Error('Server error: ' + response.status);
        }
        return response.text();
    })
    .then(text => {
        // Try to parse JSON, handle empty response
        if (!text || text.trim() === '') {
            // Empty response - data saved locally
            alert('✅ บันทึกใน localStorage สำเร็จ!');
            return;
        }
        try {
            const data = JSON.parse(text);
            if (data.success) {
                const storage = data.storage === 'database' ? 'Database' : 'localStorage';
                alert(`✅ บันทึกสำเร็จ! (${storage})`);
            } else {
                throw new Error(data.error || 'Save failed');
            }
        } catch (e) {
            // JSON parse error - still saved locally
            alert('✅ บันทึกใน localStorage สำเร็จ!');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        // Data is already saved to localStorage
        alert('✅ บันทึกใน localStorage สำเร็จ! (Server ไม่พร้อม)');
    });
}

// Export results as JSON
function exportResults() {
    const results = {
        exportDate: new Date().toISOString(),
        testData: testData,
        summary: {
            total: Object.keys(testData).length,
            passed: Object.values(testData).filter(t => t.status === 'passed').length,
            failed: Object.values(testData).filter(t => t.status === 'failed').length,
            skipped: Object.values(testData).filter(t => t.status === 'skipped').length
        }
    };
    
    const blob = new Blob([JSON.stringify(results, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `testing-results-${new Date().toISOString().split('T')[0]}.json`;
    a.click();
    URL.revokeObjectURL(url);
}

// Print report
function printReport() {
    // Expand all test details before printing
    document.querySelectorAll('.test-case-body').forEach(body => {
        body.classList.add('expanded');
    });
    
    window.print();
}

// Setup event listeners
function setupEventListeners() {
    // Filter buttons
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const filter = this.dataset.filter;
            filterTests(filter);
        });
    });
    
    // Auto-save notes
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('notes-textarea')) {
            saveToLocalStorage();
        }
    });
}

// Filter tests
function filterTests(filter) {
    document.querySelectorAll('.test-case').forEach(testCase => {
        if (filter === 'all') {
            testCase.style.display = 'block';
        } else if (filter === 'pending') {
            const hasStatus = testCase.classList.contains('status-passed') || 
                            testCase.classList.contains('status-failed') || 
                            testCase.classList.contains('status-skipped');
            testCase.style.display = hasStatus ? 'none' : 'block';
        } else {
            testCase.style.display = testCase.classList.contains(`status-${filter}`) ? 'block' : 'none';
        }
    });
}
