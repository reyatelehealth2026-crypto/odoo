<?php
session_start();

// Try to load config, but don't fail if not available
$useAuth = false;
if (file_exists('config/config.php')) {
    @include_once 'config/config.php';
    if (defined('DB_HOST') && file_exists('config/database.php')) {
        require_once 'config/database.php';
        if (file_exists('includes/auth_check.php')) {
            require_once 'includes/auth_check.php';
            $useAuth = true;
        }
    }
}

$pageTitle = "System Testing Checklist";

// Try to include header, or use standalone header
if ($useAuth && file_exists('includes/header.php')) {
    include 'includes/header.php';
} else {
    // Standalone header
    ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    </style>
</head>
<body>
    <?php
}
?>

<link rel="stylesheet" href="assets/css/testing-checklist.css">

<div class="testing-container">
    <div class="test-header">
        <h1>🧪 System Testing Checklist</h1>
        <p>Comprehensive testing for Telepharmacy & E-commerce Platform</p>
        <div class="test-stats">
            <div class="stat-card">
                <span class="stat-number" id="total-tests">0</span>
                <span class="stat-label">Total Tests</span>
            </div>
            <div class="stat-card">
                <span class="stat-number" id="passed-tests">0</span>
                <span class="stat-label">✅ Passed</span>
            </div>
            <div class="stat-card">
                <span class="stat-number" id="failed-tests">0</span>
                <span class="stat-label">❌ Failed</span>
            </div>
            <div class="stat-card">
                <span class="stat-number" id="skipped-tests">0</span>
                <span class="stat-label">⏭️ Skipped</span>
            </div>
            <div class="stat-card">
                <span class="stat-number" id="completion-rate">0%</span>
                <span class="stat-label">Completion</span>
            </div>
        </div>
    </div>

    <div class="filter-section">
        <strong>Filter:</strong>
        <button class="filter-btn active" data-filter="all">All</button>
        <button class="filter-btn" data-filter="pending">Pending</button>
        <button class="filter-btn" data-filter="passed">Passed</button>
        <button class="filter-btn" data-filter="failed">Failed</button>
        <button class="filter-btn" data-filter="skipped">Skipped</button>
        <div style="flex: 1"></div>
        <button class="btn-primary" onclick="saveResults()">💾 Save Results</button>
        <button class="btn-primary" onclick="exportResults()">📥 Export JSON</button>
        <button class="btn-primary" onclick="printReport()">🖨️ Print Report</button>
    </div>

    <div id="categories-container"></div>
</div>

<script src="assets/js/testing-checklist-data.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/testing-checklist.js?v=<?php echo time(); ?>"></script>

<?php 
if ($useAuth && file_exists('includes/footer.php')) {
    include 'includes/footer.php';
} else {
    // Standalone footer
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}
?>
