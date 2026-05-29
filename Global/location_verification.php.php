<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get user info for display
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] ?? 'Quality Control';
$user_role = $_SESSION['role'] ?? 'global';
$view_all_branches = $_SESSION['view_all_branches'] ?? true;
$user_branch_id = $_SESSION['branch_id'] ?? 0;

// Get user's branch name for display
$branch_name = 'All Branches';
if (!$view_all_branches && $user_branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $user_branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
}

// Get initials for avatar
$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $user_initials .= strtoupper(substr($part, 0, 1));
        }
    }
}
if (empty($user_initials)) {
    $user_initials = 'AD';
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] == 'get_verifications') {
        // Get filter parameters
        $period = isset($_GET['period']) ? $_GET['period'] : 'monthly';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m');
        $branch_filter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
        $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
        
        // Parse date
        $year = substr($date, 0, 4);
        $month = substr($date, 5, 2);
        
        // Build WHERE clause
        $where_clause = " WHERE so.order_status != 'cancelled'";
        
        // Add branch filter
        if (!$view_all_branches && $user_branch_id > 0) {
            $where_clause .= " AND so.branch_id = $user_branch_id";
        } elseif ($branch_filter > 0 && $view_all_branches) {
            $where_clause .= " AND so.branch_id = $branch_filter";
        }
        
        // Add date filter
        if ($period == 'monthly' && !empty($date)) {
            $where_clause .= " AND YEAR(so.order_date) = $year AND MONTH(so.order_date) = $month";
        } elseif ($period == 'daily' && !empty($date)) {
            $where_clause .= " AND DATE(so.order_date) = '$date'";
        }
        
        // Add status filter (has location or not)
        if ($status_filter == 'has_location') {
            $where_clause .= " AND so.agent_location IS NOT NULL AND so.agent_location != ''";
        } elseif ($status_filter == 'no_location') {
            $where_clause .= " AND (so.agent_location IS NULL OR so.agent_location = '')";
        }
        
        // Get verification data
        $verification_sql = "SELECT 
                                so.so_id,
                                so.so_number,
                                so.order_date,
                                so.agent_location,
                                c.customer_id,
                                c.customer_name,
                                c.address,
                                c.latitude,
                                c.longitude,
                                CONCAT(u.first_name, ' ', u.last_name) as agent_name,
                                b.branch_name as branch,
                                b.branch_id as branch_id
                             FROM sales_orders so
                             INNER JOIN customers c ON so.customer_id = c.customer_id
                             INNER JOIN users u ON so.created_by = u.user_id
                             INNER JOIN branches b ON so.branch_id = b.branch_id
                             $where_clause
                             ORDER BY so.order_date DESC
                             LIMIT 200";
        
        $result = $conn->query($verification_sql);
        $verifications = [];
        $stats = [
            'total_orders' => 0,
            'with_location' => 0,
            'no_location' => 0,
            'verified_within_1km' => 0,
            'verified_within_5km' => 0,
            'verified_far' => 0
        ];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // Parse agent location
                $agent_coords = explode(',', $row['agent_location']);
                $agent_lat = isset($agent_coords[0]) ? (float)$agent_coords[0] : null;
                $agent_lng = isset($agent_coords[1]) ? (float)$agent_coords[1] : null;
                
                $has_location = ($agent_lat !== null && $agent_lng !== null);
                $stats['total_orders']++;
                
                if ($has_location) {
                    $stats['with_location']++;
                    
                    // Calculate distance to customer if customer has coordinates
                    if ($row['latitude'] && $row['longitude']) {
                        $distance = calculateDistance($agent_lat, $agent_lng, $row['latitude'], $row['longitude']);
                        if ($distance <= 1) {
                            $stats['verified_within_1km']++;
                        } elseif ($distance <= 5) {
                            $stats['verified_within_5km']++;
                        } else {
                            $stats['verified_far']++;
                        }
                    }
                } else {
                    $stats['no_location']++;
                }
                
                $verifications[] = [
                    'so_id' => $row['so_id'],
                    'so_number' => $row['so_number'],
                    'order_date' => $row['order_date'],
                    'customer_id' => $row['customer_id'],
                    'customer_name' => $row['customer_name'],
                    'customer_address' => $row['address'],
                    'customer_lat' => $row['latitude'] ? (float)$row['latitude'] : null,
                    'customer_lng' => $row['longitude'] ? (float)$row['longitude'] : null,
                    'agent_name' => $row['agent_name'],
                    'agent_lat' => $agent_lat,
                    'agent_lng' => $agent_lng,
                    'branch' => $row['branch'],
                    'branch_id' => $row['branch_id'],
                    'has_location' => $has_location
                ];
            }
        }
        
        // Get branches for filter dropdown
        $branches_sql = "SELECT branch_id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
        $branches_result = $conn->query($branches_sql);
        $branches = [];
        if ($branches_result) {
            while ($row = $branches_result->fetch_assoc()) {
                $branches[] = $row;
            }
        }
        
        echo json_encode([
            'success' => true,
            'verifications' => $verifications,
            'stats' => $stats,
            'branches' => $branches,
            'filters' => [
                'period' => $period,
                'date' => $date,
                'branch_filter' => $branch_filter,
                'status_filter' => $status_filter
            ]
        ]);
        exit;
    }
    
    if (isset($_POST['action']) && $_POST['action'] == 'geocode_customer') {
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        
        if (empty($address)) {
            echo json_encode(['success' => false, 'message' => 'Address is required']);
            exit;
        }
        
        // Use OpenStreetMap Nominatim API
        $encoded_address = urlencode($address . ', Philippines');
        $url = "https://nominatim.openstreetmap.org/search?q={$encoded_address}&format=json&limit=1";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'AMGC Sales System/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200 && $response) {
            $data = json_decode($response, true);
            if (!empty($data)) {
                $lat = (float)$data[0]['lat'];
                $lng = (float)$data[0]['lon'];
                
                // Update customer with coordinates
                if ($customer_id > 0) {
                    $update_sql = "UPDATE customers SET latitude = ?, longitude = ? WHERE customer_id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param('ddi', $lat, $lng, $customer_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
                
                echo json_encode(['success' => true, 'latitude' => $lat, 'longitude' => $lng]);
                exit;
            }
        }
        
        echo json_encode(['success' => false, 'message' => 'Could not geocode address']);
        exit;
    }
}

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $R = 6371; // Earth's radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Location Verification - Global</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/global.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Leaflet CSS for Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-green: #2E7D32;
            --dark-green: #1B5E20;
            --light-green: #e8f5e9;
            --warning-orange: #ff9800;
            --danger-red: #f44336;
            --info-blue: #2196f3;
            --light-gray: #f8f9fa;
            --border-gray: #e9ecef;
            --text-dark: #2c3e50;
            --text-muted: #6c757d;
        }

        /* Main Content */
        .main-content {
            transition: margin-left 0.3s ease;
            padding: 20px;
        }

        /* Stat Cards Row */
        .stat-cards-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border: 1px solid var(--border-gray);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .stat-icon.total {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .stat-icon.with-location {
            background: linear-gradient(135deg, #2E7D32 0%, #4caf50 100%);
            color: white;
        }

        .stat-icon.no-location {
            background: linear-gradient(135deg, #f44336 0%, #ff9800 100%);
            color: white;
        }

        .stat-icon.verified {
            background: linear-gradient(135deg, #2196f3 0%, #03a9f4 100%);
            color: white;
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 5px;
        }

        .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid var(--border-gray);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            margin-bottom: 15px;
        }

        .filter-header h5 {
            margin: 0;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-header h5 i {
            color: var(--primary-green);
            font-size: 20px;
        }

        .filter-toggle-btn {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--text-muted);
            cursor: pointer;
            transition: transform 0.3s;
            padding: 5px;
        }

        .filter-toggle-btn:hover {
            color: var(--primary-green);
        }

        .filter-content {
            transition: all 0.3s ease;
        }

        .filter-content.collapsed {
            display: none;
        }

        /* Form Controls */
        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-dark);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label i {
            color: var(--primary-green);
            font-size: 14px;
        }

        .form-select, .form-control {
            border: 1px solid var(--border-gray);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-select:focus, .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        /* Data Table */
        .data-table {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            border: 1px solid var(--border-gray);
        }

        .table-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-gray);
            background: var(--light-gray);
        }

        .table-header h5 {
            margin: 0;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-header h5 i {
            color: var(--primary-green);
        }

        .custom-table {
            margin-bottom: 0;
        }

        .custom-table th {
            background: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-gray);
        }

        .custom-table td {
            padding: 14px 16px;
            font-size: 13px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-gray);
        }

        .custom-table tr:hover {
            background: var(--light-gray);
        }

        /* Badges */
        .location-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #e8f5e9;
            color: #2E7D32;
        }

        .badge-danger {
            background: #ffebee;
            color: #f44336;
        }

        .badge-warning {
            background: #fff3e0;
            color: #ff9800;
        }

        .badge-info {
            background: #e3f2fd;
            color: #2196f3;
        }

        /* Buttons */
        .btn-view-map {
            background: var(--info-blue);
            border: none;
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-view-map:hover {
            background: #0b5ed7;
            transform: scale(1.02);
        }

        .btn-geocode {
            background: #6c757d;
            border: none;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
            margin-top: 5px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-geocode:hover {
            background: #5a6268;
        }

        .btn-refresh {
            background: var(--primary-green);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-refresh:hover {
            background: var(--dark-green);
        }

        /* Map Modal */
        .map-modal .modal-dialog {
            max-width: 90%;
            width: 900px;
        }

        .map-container {
            height: 450px;
            width: 100%;
            border-radius: 12px;
            margin-top: 15px;
        }

        .location-info-card {
            background: var(--light-gray);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 15px;
        }

        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .info-label {
            font-weight: 600;
            width: 120px;
            color: var(--text-muted);
        }

        .info-value {
            flex: 1;
            color: var(--text-dark);
        }

        .distance-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 10px;
        }

        .distance-close {
            background: #e8f5e9;
            color: #2E7D32;
        }

        .distance-moderate {
            background: #fff3e0;
            color: #ff9800;
        }

        .distance-far {
            background: #ffebee;
            color: #f44336;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 12px;
            }
            
            .stat-cards-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .stat-card {
                padding: 12px;
            }
            
            .stat-icon {
                width: 45px;
                height: 45px;
                font-size: 20px;
            }
            
            .stat-value {
                font-size: 20px;
            }
            
            .stat-label {
                font-size: 11px;
            }
            
            .custom-table th, .custom-table td {
                padding: 10px 12px;
                font-size: 11px;
            }
            
            .map-container {
                height: 350px;
            }
        }

        @media (max-width: 480px) {
            .stat-cards-row {
                grid-template-columns: 1fr;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                width: auto;
                margin-bottom: 3px;
            }
        }

        /* Loading State */
        .loading-state {
            text-align: center;
            padding: 50px;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-gray);
            border-top-color: var(--primary-green);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Sidebar Styles (matching global.css) */
        .sidebar {
            width: 250px;
            background: white;
            border-right: 1px solid var(--border-gray);
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar.collapsed .nav-text,
        .sidebar.collapsed .logout-text,
        .sidebar.collapsed .user-details-sidebar {
            display: none;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-gray);
        }

        .sidebar-header h3 {
            margin: 0;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
        }

        .desktop-toggle-btn {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-muted);
            padding: 0;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .sidebar-menu .nav-link {
            padding: 12px 20px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
        }

        .sidebar-menu .nav-link:hover {
            background: var(--light-gray);
            color: var(--primary-green);
        }

        .sidebar-menu .nav-link.active {
            background: var(--light-green);
            color: var(--primary-green);
            border-right: 3px solid var(--primary-green);
        }

        .sidebar-menu .nav-link i {
            font-size: 18px;
            width: 24px;
        }

        .sidebar-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px;
            border-top: 1px solid var(--border-gray);
        }

        .user-profile-sidebar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .user-avatar-sidebar {
            width: 40px;
            height: 40px;
            background: var(--primary-green);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .user-name-sidebar {
            font-weight: 600;
            font-size: 14px;
        }

        .logout-btn-sidebar {
            width: 100%;
            padding: 10px;
            background: none;
            border: 1px solid var(--border-gray);
            border-radius: 8px;
            color: var(--danger-red);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .logout-btn-sidebar:hover {
            background: #ffebee;
            border-color: var(--danger-red);
        }

        .mobile-toggle-btn {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
        }

        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .page-title h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .page-title p {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 13px;
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .mobile-toggle-btn {
                display: block;
            }
            
            .main-content {
                margin-left: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="sales_reports.php">
                            <i class="bi bi-graph-up"></i>
                            <span class="nav-text">Sales Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="branch_records.php">
                            <i class="bi bi-file-text"></i>
                            <span class="nav-text">Branch Records</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="all_items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">All Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="location_verification.php">
                            <i class="bi bi-geo-alt-fill"></i>
                            <span class="nav-text">Location Verification</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">User Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_tracking.php">
                            <i class="bi bi-geo-alt"></i>
                            <span class="nav-text">Driver Tracking</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                    </div>
                </div>
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Location Verification</h2>
                    <p>Track and verify sales agent locations during order placement</p>
                </div>
                <button class="btn-refresh" onclick="loadVerifications()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>

            <!-- Stat Cards -->
            <div class="stat-cards-row" id="statCards">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="statTotalOrders">0</div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon with-location">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="statWithLocation">0</div>
                        <div class="stat-label">With Location</div>
                        <div class="stat-sub" id="statWithLocationPercent">0%</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon no-location">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="statNoLocation">0</div>
                        <div class="stat-label">No Location</div>
                        <div class="stat-sub" id="statNoLocationPercent">0%</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon verified">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="statVerifiedWithin1km">0</div>
                        <div class="stat-label">Within 1km</div>
                        <div class="stat-sub">Verified on-site</div>
                    </div>
                </div>
            </div>

            <!-- Second Row of Stats -->
            <div class="stat-cards-row" id="statCardsRow2">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ff9800 0%, #ffc107 100%); color: white;">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="statWithin5km">0</div>
                        <div class="stat-label">Within 5km</div>
                        <div class="stat-sub">Nearby location</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f44336 0%, #e91e63 100%); color: white;">
                        <i class="bi bi-map"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="statFarAway">0</div>
                        <div class="stat-label">Far away (>5km)</div>
                        <div class="stat-sub">Needs verification</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #9c27b0 0%, #673ab7 100%); color: white;">
                        <i class="bi bi-building"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="statBranchesActive">0</div>
                        <div class="stat-label">Active Branches</div>
                        <div class="stat-sub">With location data</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #00bcd4 0%, #009688 100%); color: white;">
                        <i class="bi bi-person-check-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="statVerificationRate">0%</div>
                        <div class="stat-label">Verification Rate</div>
                        <div class="stat-sub">Orders with location</div>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <div class="filter-header" onclick="toggleFilter()">
                    <h5>
                        <i class="bi bi-funnel-fill"></i> Filter Orders
                    </h5>
                    <button class="filter-toggle-btn" id="filterToggleBtn">
                        <i class="bi bi-chevron-down" id="filterIcon"></i>
                    </button>
                </div>
                <div class="filter-content" id="filterContent">
                    <div class="row g-3">
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label">
                                <i class="bi bi-calendar-range"></i> Period
                            </label>
                            <select class="form-select" id="periodFilter" onchange="toggleDateInput()">
                                <option value="monthly">Monthly</option>
                                <option value="daily">Daily</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label" id="dateLabel">
                                <i class="bi bi-calendar-month"></i> Month
                            </label>
                            <input type="month" class="form-control" id="dateFilter" value="<?php echo date('Y-m'); ?>">
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label">
                                <i class="bi bi-building"></i> Branch
                            </label>
                            <select class="form-select" id="branchFilter">
                                <option value="0">All Branches</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label">
                                <i class="bi bi-geo-alt"></i> Location Status
                            </label>
                            <select class="form-select" id="statusFilter">
                                <option value="all">All Orders</option>
                                <option value="has_location">With Location</option>
                                <option value="no_location">No Location</option>
                            </select>
                        </div>
                        <div class="col-12 mt-3">
                            <button class="btn-refresh w-100" onclick="loadVerifications()">
                                <i class="bi bi-search"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="data-table">
                <div class="table-header">
                    <h5>
                        <i class="bi bi-table"></i> Order Location Records
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table custom-table">
                        <thead>
                            <tr>
                                <th>SO Number</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Address</th>
                                <th>Sales Agent</th>
                                <th>Branch</th>
                                <th>Location Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="verificationsTableBody">
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="loading-state">
                                        <div class="loading-spinner"></div>
                                        <p>Loading location data...</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Location Map Modal -->
    <div class="modal fade map-modal" id="locationMapModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-map"></i> Location Verification Map
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="location-info-card" id="mapLocationInfo">
                        <div class="info-row">
                            <span class="info-label">Order #:</span>
                            <span class="info-value" id="mapSoNumber">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Sales Agent:</span>
                            <span class="info-value" id="mapAgentName">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Customer:</span>
                            <span class="info-value" id="mapCustomerName">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Address:</span>
                            <span class="info-value" id="mapCustomerAddress">-</span>
                        </div>
                        <div id="mapDistanceInfo" class="mt-2"></div>
                    </div>
                    <div id="mapContainer" class="map-container"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-sidebar" style="width: 80px; height: 80px; font-size: 32px; margin: 0 auto 15px;">
                        <?php echo $user_initials; ?>
                    </div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                    <div class="mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables
        let currentMap = null;
        let isFilterCollapsed = false;

        // ================= SIDEBAR FUNCTIONS =================
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    overlay.style.position = 'fixed';
                    overlay.style.top = '0';
                    overlay.style.left = '0';
                    overlay.style.right = '0';
                    overlay.style.bottom = '0';
                    overlay.style.backgroundColor = 'rgba(0,0,0,0.5)';
                    overlay.style.zIndex = '999';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                document.querySelector('.main-content').style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
            }
        }

        function closeMobileSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) overlay.remove();
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (savedCollapsed) {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                    document.querySelector('.main-content').style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    document.querySelector('.main-content').style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelector('.main-content').style.marginLeft = '0';
            }
        }

        // ================= FILTER FUNCTIONS =================
        function toggleFilter() {
            const content = document.getElementById('filterContent');
            const icon = document.getElementById('filterIcon');
            
            if (content.classList.contains('collapsed')) {
                content.classList.remove('collapsed');
                icon.style.transform = 'rotate(0deg)';
                localStorage.setItem('locationFilterHidden', 'false');
            } else {
                content.classList.add('collapsed');
                icon.style.transform = 'rotate(-90deg)';
                localStorage.setItem('locationFilterHidden', 'true');
            }
        }

        function toggleDateInput() {
            const period = document.getElementById('periodFilter').value;
            const dateFilter = document.getElementById('dateFilter');
            const dateLabel = document.getElementById('dateLabel');
            
            if (period === 'monthly') {
                dateLabel.innerHTML = '<i class="bi bi-calendar-month"></i> Month';
                dateFilter.type = 'month';
                dateFilter.value = '<?php echo date('Y-m'); ?>';
            } else {
                dateLabel.innerHTML = '<i class="bi bi-calendar-day"></i> Date';
                dateFilter.type = 'date';
                dateFilter.value = '<?php echo date('Y-m-d'); ?>';
            }
            loadVerifications();
        }

        // ================= MAIN FUNCTION =================
        async function loadVerifications() {
            const period = document.getElementById('periodFilter').value;
            const date = document.getElementById('dateFilter').value;
            const branchFilter = document.getElementById('branchFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            
            const params = new URLSearchParams({
                ajax: 'get_verifications',
                period: period,
                date: date,
                branch_id: branchFilter,
                status: statusFilter
            });
            
            try {
                const response = await fetch('location_verification.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    updateStats(data.stats);
                    updateBranchesDropdown(data.branches, data.filters.branch_filter);
                    updateTable(data.verifications);
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire('Error', 'Failed to load verification data', 'error');
            }
        }

        function updateStats(stats) {
            document.getElementById('statTotalOrders').textContent = stats.total_orders.toLocaleString();
            document.getElementById('statWithLocation').textContent = stats.with_location.toLocaleString();
            document.getElementById('statNoLocation').textContent = stats.no_location.toLocaleString();
            document.getElementById('statVerifiedWithin1km').textContent = stats.verified_within_1km.toLocaleString();
            document.getElementById('statWithin5km').textContent = stats.verified_within_5km.toLocaleString();
            document.getElementById('statFarAway').textContent = stats.verified_far.toLocaleString();
            
            const withLocationPercent = stats.total_orders > 0 ? ((stats.with_location / stats.total_orders) * 100).toFixed(1) : 0;
            const noLocationPercent = stats.total_orders > 0 ? ((stats.no_location / stats.total_orders) * 100).toFixed(1) : 0;
            
            document.getElementById('statWithLocationPercent').textContent = withLocationPercent + '%';
            document.getElementById('statNoLocationPercent').textContent = noLocationPercent + '%';
            document.getElementById('statVerificationRate').textContent = withLocationPercent + '%';
        }

        function updateBranchesDropdown(branches, selectedBranchId) {
            const select = document.getElementById('branchFilter');
            if (select && branches && branches.length > 0) {
                let options = '<option value="0">All Branches</option>';
                branches.forEach(branch => {
                    const selected = selectedBranchId == branch.branch_id ? 'selected' : '';
                    options += `<option value="${branch.branch_id}" ${selected}>${escapeHtml(branch.branch_name)}</option>`;
                });
                select.innerHTML = options;
                document.getElementById('statBranchesActive').textContent = branches.length;
            }
        }

        function updateTable(verifications) {
            const tbody = document.getElementById('verificationsTableBody');
            
            if (!verifications || verifications.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>No location data found for the selected filters</p>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = verifications.map(order => {
                const hasLocation = order.has_location;
                const locationBadge = hasLocation ? 
                    '<span class="location-badge badge-success"><i class="bi bi-check-circle-fill"></i> Captured</span>' : 
                    '<span class="location-badge badge-danger"><i class="bi bi-x-circle-fill"></i> No Location</span>';
                
                const geocodeButton = (!order.customer_lat && order.customer_address) ? 
                    `<button class="btn-geocode" onclick="geocodeCustomer(${order.customer_id}, '${escapeHtml(order.customer_address).replace(/'/g, "\\'")}')">
                        <i class="bi bi-geo-alt"></i> Get Location
                    </button>` : '';
                
                return `
                    <tr>
                        <td><strong>${escapeHtml(order.so_number)}</strong><br><small class="text-muted">${formatDate(order.order_date)}</small></td>
                        <td>${escapeHtml(order.customer_name)}</td>
                        <td>
                            ${escapeHtml(order.customer_address.substring(0, 50))}${order.customer_address.length > 50 ? '...' : ''}
                            ${geocodeButton}
                        </td>
                        <td>${escapeHtml(order.agent_name)}</td>
                        <td><span class="badge bg-secondary">${escapeHtml(order.branch)}</span></td>
                        <td class="text-center">${locationBadge}</td>
                        <td>
                            <button class="btn-view-map" onclick="showLocationMap(${order.so_id}, '${escapeHtml(order.so_number)}', '${escapeHtml(order.agent_name)}', '${escapeHtml(order.customer_name)}', '${escapeHtml(order.customer_address)}', ${order.agent_lat || 'null'}, ${order.agent_lng || 'null'}, ${order.customer_lat || 'null'}, ${order.customer_lng || 'null'})">
                                <i class="bi bi-map"></i> View Map
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // ================= GEOCODING =================
        async function geocodeCustomer(customerId, address) {
            Swal.fire({
                title: 'Geocoding Address',
                text: 'Please wait while we locate the address...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            try {
                const formData = new FormData();
                formData.append('action', 'geocode_customer');
                formData.append('customer_id', customerId);
                formData.append('address', address);
                
                const response = await fetch('location_verification.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Success!', 'Customer location has been geocoded.', 'success');
                    loadVerifications();
                } else {
                    Swal.fire('Error', data.message || 'Could not geocode address', 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            }
        }

        // ================= MAP FUNCTIONS =================
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        }

        function showLocationMap(soId, soNumber, agentName, customerName, customerAddress, agentLat, agentLng, customerLat, customerLng) {
            const modal = new bootstrap.Modal(document.getElementById('locationMapModal'));
            
            document.getElementById('mapSoNumber').textContent = soNumber;
            document.getElementById('mapAgentName').textContent = agentName;
            document.getElementById('mapCustomerName').textContent = customerName;
            document.getElementById('mapCustomerAddress').textContent = customerAddress;
            
            modal.show();
            
            setTimeout(() => {
                const mapContainer = document.getElementById('mapContainer');
                if (currentMap) currentMap.remove();
                
                let center = [14.5995, 120.9842];
                let hasLocations = false;
                
                if (agentLat && agentLng) {
                    center = [agentLat, agentLng];
                    hasLocations = true;
                } else if (customerLat && customerLng) {
                    center = [customerLat, customerLng];
                    hasLocations = true;
                }
                
                currentMap = L.map(mapContainer).setView(center, 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(currentMap);
                
                let distance = null;
                
                if (agentLat && agentLng) {
                    const agentIcon = L.divIcon({
                        className: 'custom-marker',
                        html: '<div style="background-color: #2E7D32; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.2);"><i class="bi bi-person-check" style="color: white; font-size: 16px;"></i></div>',
                        iconSize: [32, 32],
                        popupAnchor: [0, -16]
                    });
                    L.marker([agentLat, agentLng], { icon: agentIcon })
                        .bindPopup(`<strong>Sales Agent: ${agentName}</strong><br>Order placed here`)
                        .addTo(currentMap);
                }
                
                if (customerLat && customerLng) {
                    const customerIcon = L.divIcon({
                        className: 'custom-marker',
                        html: '<div style="background-color: #dc3545; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.2);"><i class="bi bi-house-door" style="color: white; font-size: 16px;"></i></div>',
                        iconSize: [32, 32],
                        popupAnchor: [0, -16]
                    });
                    L.marker([customerLat, customerLng], { icon: customerIcon })
                        .bindPopup(`<strong>Customer: ${customerName}</strong><br>${customerAddress}`)
                        .addTo(currentMap);
                    
                    if (agentLat && agentLng) {
                        distance = calculateDistance(agentLat, agentLng, customerLat, customerLng);
                        const line = L.polyline([[agentLat, agentLng], [customerLat, customerLng]], {
                            color: '#ff9800',
                            weight: 3,
                            opacity: 0.7,
                            dashArray: '5, 10'
                        }).addTo(currentMap);
                        
                        const bounds = L.latLngBounds([[agentLat, agentLng], [customerLat, customerLng]]);
                        currentMap.fitBounds(bounds, { padding: [50, 50] });
                    } else {
                        currentMap.setView([customerLat, customerLng], 14);
                    }
                } else if (agentLat && agentLng) {
                    currentMap.setView([agentLat, agentLng], 14);
                }
                
                const distanceInfo = document.getElementById('mapDistanceInfo');
                if (agentLat && agentLng && customerLat && customerLng && distance !== null) {
                    let badgeClass = '', badgeText = '';
                    if (distance <= 1) {
                        badgeClass = 'distance-close';
                        badgeText = '✅ Verified: Agent was within 1km of customer location';
                    } else if (distance <= 5) {
                        badgeClass = 'distance-moderate';
                        badgeText = '⚠️ Agent was within 5km of customer location';
                    } else {
                        badgeClass = 'distance-far';
                        badgeText = '❌ Agent was far from customer location';
                    }
                    distanceInfo.innerHTML = `<div class="distance-badge ${badgeClass}"><i class="bi bi-arrow-left-right"></i> Distance: ${distance.toFixed(2)} km - ${badgeText}</div>`;
                } else if (agentLat && agentLng) {
                    distanceInfo.innerHTML = '<div class="distance-badge distance-moderate"><i class="bi bi-info-circle"></i> Customer location not geocoded yet. Click "Get Location" to geocode.</div>';
                } else if (customerLat && customerLng) {
                    distanceInfo.innerHTML = '<div class="distance-badge distance-far"><i class="bi bi-exclamation-triangle"></i> Agent location was not captured during order placement.</div>';
                } else {
                    distanceInfo.innerHTML = '<div class="distance-badge"><i class="bi bi-x-circle"></i> No location data available for this order.</div>';
                }
            }, 500);
        }

        // ================= UTILITIES =================
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-PH');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function logout() {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2E7D32',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        function confirmLogout() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
            if (modal) modal.hide();
            logout();
        }

        function showProfileModal() {
            new bootstrap.Modal(document.getElementById('profileModal')).show();
        }

        // ================= INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            
            const savedFilterState = localStorage.getItem('locationFilterHidden');
            if (savedFilterState === 'true') {
                document.getElementById('filterContent').classList.add('collapsed');
                document.getElementById('filterIcon').style.transform = 'rotate(-90deg)';
            }
            
            document.getElementById('mobileToggleBtn')?.addEventListener('click', toggleSidebar);
            document.getElementById('desktopToggleBtn')?.addEventListener('click', toggleSidebar);
            
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    const overlay = document.querySelector('.sidebar-overlay');
                    if (overlay) overlay.remove();
                    document.getElementById('sidebar').classList.remove('active');
                }
            });
            
            loadVerifications();
        });
    </script>
</body>
</html>