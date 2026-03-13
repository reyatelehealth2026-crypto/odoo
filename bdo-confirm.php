<?php
declare(strict_types=1);

// Backward-compatible entry point for old BDO confirm links.
$query = $_GET;
if (empty($query['tab'])) {
    $query['tab'] = 'matching';
}

$target = 'odoo-dashboard.php';
if (!empty($query)) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 302);
exit;
