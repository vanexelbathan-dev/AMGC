<?php
/**
 * Template Helper Functions
 * Reusable functions for generating common UI elements
 */

/**
 * Generate page header with user info and logout
 */
function renderPageHeader($title, $description) {
    ?>
    <div class="navbar-top">
        <div class="page-title">
            <h2><i class="bi bi-<?php echo getPageIcon($title); ?> me-2"></i><?php echo htmlspecialchars($title); ?></h2>
            <p><?php echo htmlspecialchars($description); ?></p>
        </div>
        
        <div class="user-info-top">
            <div class="user-profile-top">
                <div class="user-avatar-top"><?php echo substr(getUserName(), 0, 2); ?></div>
                <div class="user-details-top">
                    <span class="user-name-top"><?php echo getUserName(); ?></span>
                    <span class="user-role-top"><?php echo ucfirst(str_replace('_', ' ', getUserRole())); ?></span>
                </div>
            </div>
            
            <button class="logout-btn-top" onclick="window.location.href='../logout.php'">
                <i class="bi bi-box-arrow-right"></i> Logout
            </button>
        </div>
    </div>
    <?php
}

/**
 * Render alert messages
 */
function renderAlerts($success = '', $error = '') {
    if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif;
    if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif;
}

/**
 * Render stat cards
 */
function renderStatCard($icon, $value, $label, $color = 'customers') {
    ?>
    <div class="col-md-4 mb-3">
        <div class="stat-card <?php echo $color; ?>">
            <div class="stat-icon">
                <i class="bi bi-<?php echo $icon; ?>"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo htmlspecialchars($value); ?></div>
                <div class="stat-label"><?php echo htmlspecialchars($label); ?></div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Get icon based on page title
 */
function getPageIcon($title) {
    $icons = [
        'Customer' => 'people',
        'Inventory' => 'boxes',
        'Orders' => 'bag',
        'Delivery' => 'truck',
        'Driver' => 'person-circle',
        'Items' => 'boxes',
        'Reports' => 'graph-up',
        'Trip' => 'geo-alt',
        'Warehouse' => 'building'
    ];
    
    foreach ($icons as $key => $icon) {
        if (stripos($title, $key) !== false) {
            return $icon;
        }
    }
    return 'dashboard';
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}

/**
 * Format date
 */
function formatDate($date, $format = 'M d, Y') {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    return date($format, strtotime($date));
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status) {
    $badges = [
        'active' => 'bg-success',
        'pending' => 'bg-warning',
        'inactive' => 'bg-secondary',
        'suspended' => 'bg-danger',
        'completed' => 'bg-success',
        'cancelled' => 'bg-danger',
        'ready' => 'bg-info',
        'processing' => 'bg-warning',
        'confirmed' => 'bg-success',
        'delivered' => 'bg-success',
        'rejected' => 'bg-danger',
        'on-leave' => 'bg-warning'
    ];
    
    $badgeClass = $badges[strtolower($status)] ?? 'bg-secondary';
    return '<span class="badge ' . $badgeClass . '">' . ucfirst($status) . '</span>';
}

/**
 * Render pagination
 */
function renderPagination($current_page, $total_pages) {
    if ($total_pages <= 1) return;
    ?>
    <nav aria-label="Page navigation">
        <ul class="pagination justify-content-center">
            <?php if ($current_page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=1">First</a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $current_page - 1; ?>">Previous</a>
                </li>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $current_page + 1; ?>">Next</a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $total_pages; ?>">Last</a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php
}

/**
 * Get base URL for current directory depth
 */
function getBaseUrl($depth = 1) {
    $base = '../';
    for ($i = 1; $i < $depth; $i++) {
        $base .= '../';
    }
    return $base;
}
?>
