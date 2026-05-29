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
            $where_clause .= " AND so.branch_id = " . intval($user_branch_id);
        } elseif ($branch_filter > 0 && $view_all_branches) {
            $where_clause .= " AND so.branch_id = " . intval($branch_filter);
        }
        
        // Add date filter
        if ($period == 'monthly' && !empty($date)) {
            $where_clause .= " AND YEAR(so.order_date) = " . intval($year) . " AND MONTH(so.order_date) = " . intval($month);
        } elseif ($period == 'daily' && !empty($date)) {
            $where_clause .= " AND DATE(so.order_date) = '" . $conn->real_escape_string($date) . "'";
        }
        
        // Add status filter (has location or not)
        if ($status_filter == 'has_location') {
            $where_clause .= " AND so.agent_location IS NOT NULL AND so.agent_location != ''";
        } elseif ($status_filter == 'no_location') {
            $where_clause .= " AND (so.agent_location IS NULL OR so.agent_location = '')";
        }
        
        // Get verification data with customer coordinates
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
                                b.branch_name as branch
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
            'with_customer_location' => 0,
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
                $has_customer_location = ($row['latitude'] !== null && $row['longitude'] !== null);
                
                $stats['total_orders']++;
                
                if ($has_location) {
                    $stats['with_location']++;
                    
                    // Calculate distance if both locations available
                    if ($has_customer_location) {
                        $stats['with_customer_location']++;
                        $distance = calculateDistance($agent_lat, $agent_lng, (float)$row['latitude'], (float)$row['longitude']);
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
                    'customer_address' => $row['address'] ?? '',
                    'customer_lat' => $row['latitude'] ? (float)$row['latitude'] : null,
                    'customer_lng' => $row['longitude'] ? (float)$row['longitude'] : null,
                    'agent_name' => $row['agent_name'],
                    'agent_lat' => $agent_lat,
                    'agent_lng' => $agent_lng,
                    'branch' => $row['branch'],
                    'has_location' => $has_location,
                    'has_customer_location' => $has_customer_location
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Global - Location Verification</title>
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

        /* ===== FILTER CARD STYLES ===== */
        .form-card {
            background: white;
            border-radius: clamp(14px, 3vw, 20px);
            padding: clamp(0.8rem, 3vw, 1.5rem);
            box-shadow: 0 8px 20px -5px rgba(4, 120, 87, 0.12);
            border: 1px solid rgba(68, 211, 78, 0.2);
            margin-bottom: clamp(1rem, 2vw, 1.5rem);
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-card h5 {
            color: #047857;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: clamp(1rem, 4vw, 1.3rem);
            margin-bottom: clamp(0.5rem, 2vw, 1rem);
            padding-bottom: clamp(0.3rem, 1.5vw, 0.5rem);
            border-bottom: 2px solid rgba(68, 211, 78, 0.2);
            width: 100%;
        }

        .form-card h5 i {
            color: #047857;
            background: rgba(68, 211, 78, 0.1);
            padding: clamp(0.3rem, 1.5vw, 0.5rem);
            border-radius: clamp(6px, 2vw, 10px);
            font-size: clamp(0.9rem, 3.5vw, 1.2rem);
        }

        .form-label {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: clamp(0.2rem, 1vw, 0.4rem);
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: clamp(0.75rem, 3vw, 0.9rem);
        }

        .form-label i {
            color: #047857;
            font-size: clamp(0.8rem, 3.5vw, 1rem);
        }

        .form-select, 
        .form-control {
            border: 2px solid #e5e7eb;
            border-radius: clamp(6px, 2vw, 10px);
            padding: clamp(0.35rem, 2vw, 0.7rem) clamp(0.7rem, 3vw, 1rem);
            font-size: clamp(0.75rem, 3.5vw, 0.95rem);
            height: auto;
            min-height: clamp(32px, 7vw, 42px);
            width: 100%;
            background-color: white;
            transition: all 0.2s ease;
            line-height: 1.4;
            box-sizing: border-box;
        }

        .form-select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23047857' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right clamp(0.5rem, 2vw, 0.75rem) center;
            background-size: clamp(10px, 2.5vw, 14px) clamp(8px, 2vw, 12px);
            padding-right: clamp(1.8rem, 6vw, 2.2rem);
            appearance: none;
        }

        .form-select:focus, 
        .form-control:focus {
            border-color: #047857;
            box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15);
            outline: none;
        }

        /* Filter Toggle */
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .filter-toggle-btn {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: #047857;
            cursor: pointer;
            padding: 0 8px;
            transition: transform 0.3s ease;
        }

        .filter-toggle-btn:hover {
            color: #44D34E;
        }

        .filter-content {
            transition: all 0.3s ease;
        }

        .filter-content.collapsed {
            display: none;
        }

        /* ===== ROW CLICKABLE STYLES ===== */
        .custom-table tbody tr {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .custom-table tbody tr:hover {
            background-color: rgba(68, 211, 78, 0.05);
            transform: scale(1.01);
        }

        /* ===== DESKTOP VIEW - Normal table ===== */
        @media (min-width: 769px) {
            .custom-table td:last-child {
                text-align: center;
            }
        }

        /* ===== MOBILE VIEW - Card style ===== */
        @media (max-width: 768px) {
            .custom-table thead {
                display: none;
            }
            .custom-table,
            .custom-table tbody,
            .custom-table tr,
            .custom-table td {
                display: block;
                width: 100%;
            }
            .custom-table tbody tr {
                background: white;
                border-radius: 12px;
                margin-bottom: 10px;
                padding: 12px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.06);
                border: 1px solid #e9ecef;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            
            /* Hide original table cells in mobile */
            .custom-table tbody tr td {
                display: none;
            }
            
            /* Left side content - stacked */
            .custom-table tbody tr .mobile-card-left {
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            
            /* SO Number - green, bold */
            .mobile-so-number {
                font-size: clamp(0.85rem, 3.5vw, 1rem);
                font-weight: 600;
                color: #047857;
                margin-bottom: 2px;
            }
            
            /* Date - small */
            .mobile-date {
                font-size: 0.7rem;
                color: #6c757d;
                margin-top: -2px;
                margin-bottom: 4px;
            }
            
            /* Customer Name - medium weight */
            .mobile-customer-name {
                font-size: clamp(0.8rem, 3.2vw, 0.95rem);
                font-weight: 500;
                color: #212529;
            }
            
            /* Address - gray */
            .mobile-address {
                font-size: 0.75rem;
                color: #6c757d;
                line-height: 1.3;
            }
            
            /* Agent Name */
            .mobile-agent-name {
                font-size: 0.75rem;
                color: #0d6efd;
                margin-top: 2px;
            }
            
            /* Agent Location */
            .mobile-agent-location {
                font-size: 0.7rem;
                color: #6c757d;
            }
            
            /* Status badge */
            .mobile-status {
                display: inline-block;
                margin-top: 4px;
            }
            .mobile-status .badge-captured,
            .mobile-status .badge-no-location {
                font-size: 0.7rem;
                padding: 3px 8px;
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
            
            /* Right side - View Map Icon */
            .mobile-view-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                margin-left: 12px;
                color: #0d6efd;
                background: #e7f1ff;
                width: clamp(40px, 10vw, 48px);
                height: clamp(40px, 10vw, 48px);
                border-radius: 10px;
                font-size: clamp(1.1rem, 5vw, 1.3rem);
            }
        }

        .table-header h5 {
            margin: 0;
            font-weight: 600;
            color: #1e293b;
        }

        .table-container {
            overflow-x: auto;
        }

        .custom-table {
            width: 100%;
            margin-bottom: 0;
        }

        .custom-table th {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            padding: 0.75rem;
            border-bottom: 2px solid #e9ecef;
        }

        .custom-table td {
            padding: 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }

        .btn-geocode {
            background: #6c757d;
            border: none;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            cursor: pointer;
            margin-left: 5px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .btn-geocode:hover {
            background: #5a6268;
        }

        /* Map Modal */
        .map-modal .modal-dialog {
            max-width: 90%;
            width: 900px;
        }

        .map-container {
            height: 450px;
            width: 100%;
            border-radius: 8px;
            margin-top: 15px;
        }

        .location-info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
        }

        .info-row {
            display: flex;
            margin-bottom: 8px;
        }

        .info-label {
            font-weight: 600;
            width: 100px;
            color: #6c757d;
        }

        .info-value {
            flex: 1;
            color: #2c3e50;
        }

        .distance-badge {
            display: inline-block;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 10px;
        }

        .distance-close {
            background: #dcfce7;
            color: #166534;
        }

        .distance-moderate {
            background: #fef9c3;
            color: #854d0e;
        }

        .distance-far {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Navbar Top */
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .page-title {
            flex: 1;
        }

        .page-title h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
        }

        .page-title p {
            margin: 3px 0 0 0;
            color: #666;
            font-size: 12px;
        }

        /* Mobile Profile Modal */
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
            border: 4px solid #d1fae5;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #profileModal .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }

        #profileModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }

        #profileModal .modal-header .modal-title {
            color: white;
            font-weight: 600;
        }

        #profileModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        #profileModal .modal-body {
            padding: 2rem;
            background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%);
        }

        #profileModal .branch-info {
            background: #d1fae5;
            color: #047857;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 500;
        }

        #profileModal .btn-danger {
            background: linear-gradient(135deg, #dc3545, #f87171);
            border: none;
            padding: 1rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        /* Mobile Nav */
        .mobile-nav .nav-link.logout-btn {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn i {
            color: #dc3545;
        }

        /* Responsive Grid for Filter */
        @media (max-width: 767px) {
            .form-card .row > .col-12,
            .form-card .row > .col-sm-6,
            .form-card .row > .col-md-3 {
                flex: 0 0 100% !important;
                max-width: 100% !important;
            }
        }

        @media (min-width: 768px) {
            .form-card .row > .col-md-3 {
                flex: 0 0 25%;
                max-width: 25%;
            }
        }
    </style>
</head>
<body>
    <!-- MAIN APPLICATION -->
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
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
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
            <div id="reportsContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Agent Location Verification</h2>
                        <p>Track and verify sales agent locations during order placement</p>
                    </div>
                </div>

                <!-- Stat Cards -->
                <div class="row stat-card-row g-1 g-sm-2">
                    <div class="col">
                        <div class="stat-card total">
                            <i class="bi bi-receipt"></i>
                            <div class="stat-content">
                                <div class="stat-value" id="totalOrders">0</div>
                                <div class="stat-label">Total Orders</div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="stat-card sales">
                            <i class="bi bi-check-circle-fill"></i>
                            <div class="stat-content">
                                <div class="stat-value" id="withLocation">0</div>
                                <div class="stat-label">With Location</div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="stat-card complete">
                            <i class="bi bi-x-circle-fill"></i>
                            <div class="stat-content">
                                <div class="stat-value" id="noLocation">0</div>
                                <div class="stat-label">No Location</div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="stat-card inventory">
                            <i class="bi bi-geo-alt-fill"></i>
                            <div class="stat-content">
                                <div class="stat-value" id="within1km">0</div>
                                <div class="stat-label">Within 1km</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="filter-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-funnel"></i> Filter Reports
                                </h5>
                                <button class="filter-toggle-btn" id="toggleFilterBtn" onclick="toggleFilter()">
                                    <i class="bi bi-chevron-down" id="filterIcon"></i>
                                </button>
                            </div>
                            <div class="filter-content" id="filterContent">
                                <div class="row mt-3 g-3">
                                    <div class="col-12 col-sm-6 col-md-3">
                                        <label class="form-label">
                                            <i class="bi bi-calendar-range"></i> Period
                                        </label>
                                        <select class="form-select" id="periodFilter">
                                            <option value="monthly" selected>Monthly</option>
                                            <option value="daily">Daily</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-sm-6 col-md-3">
                                        <label class="form-label" id="dateLabel">
                                            <i class="bi bi-calendar-month"></i> Month
                                        </label>
                                        <input type="month" class="form-control" id="dateFilter" value="<?php echo date('Y-m'); ?>">
                                    </div>
                                    <div class="col-12 col-sm-6 col-md-3">
                                        <label class="form-label">
                                            <i class="bi bi-building"></i> Branch
                                        </label>
                                        <select class="form-select" id="branchFilter">
                                            <option value="0">All Branches</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-sm-6 col-md-3">
                                        <label class="form-label">
                                            <i class="bi bi-geo-alt"></i> Location Status
                                        </label>
                                        <select class="form-select" id="statusFilter">
                                            <option value="all">All Orders</option>
                                            <option value="has_location">With Location</option>
                                            <option value="no_location">No Location</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Table -->
                <div class="data-table">
                    <div class="table-header">
                        <h5><i class="bi bi-table"></i> Agent Location Records</h5>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>SO Number</th>
                                    <th>Customer</th>
                                    <th>Customer Address</th>
                                    <th>Sales Agent</th>
                                    <th>Agent Location</th>
                                    <th>Verification</th>
                                </tr>
                            </thead>
                            <tbody id="verificationsTable">
                                <tr>
                                    <td colspan="6" class="text-center py-4">Loading location data...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="sales_reports.php">
                    <i class="bi bi-graph-up"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="branch_records.php">
                    <i class="bi bi-file-text"></i>
                    <span>Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="all_items.php">
                    <i class="bi bi-box"></i>
                    <span>Items</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="location_verification.php">
                    <i class="bi bi-geo-alt-fill"></i>
                    <span>Location</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="drivers.php">
                    <i class="bi bi-people"></i>
                    <span>Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="driver_tracking.php">
                    <i class="bi bi-geo-alt"></i>
                    <span>Tracking</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
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
                    <div class="location-info-card">
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
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3">
                        <?php echo $user_initials; ?>
                    </div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                    <div class="branch-info mb-3">
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
        let debounceTimer = null;
        let currentVerifications = null;

        // ================= SIDEBAR FUNCTIONS =================
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

        function closeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.remove('active');
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            }
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                overlay?.remove();
                sidebar.classList.remove('active');
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }

        // ================= MOBILE NAVIGATION =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
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
                if (!dateFilter.value || dateFilter.value === '') {
                    dateFilter.value = '<?php echo date('Y-m'); ?>';
                }
            } else {
                dateLabel.innerHTML = '<i class="bi bi-calendar-day"></i> Date';
                dateFilter.type = 'date';
                if (!dateFilter.value || dateFilter.value === '') {
                    dateFilter.value = '<?php echo date('Y-m-d'); ?>';
                }
            }
            loadVerifications();
        }

        // ================= GEOCODING FUNCTION =================
        async function geocodeCustomer(customerId, address) {
            Swal.fire({
                title: 'Geocoding Address',
                text: 'Please wait while we locate the address...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            try {
                const formData = new FormData();
                formData.append('action', 'geocode_customer');
                formData.append('customer_id', customerId);
                formData.append('address', address);
                
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: 'Customer location has been geocoded.',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    loadVerifications();
                } else {
                    Swal.fire('Error', data.message || 'Could not geocode address', 'error');
                }
            } catch (error) {
                console.error('Geocode error:', error);
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            }
        }

        // ================= TABLE FUNCTIONS =================
        function updateTable(verifications) {
            currentVerifications = verifications;
            const tbody = document.getElementById('verificationsTable');
            
            if (!verifications || verifications.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">No location data available for the selected filters</td></tr>';
                return;
            }
            
            tbody.innerHTML = '';
            
            verifications.forEach(order => {
                const hasLocation = order.has_location;
                const locationBadge = hasLocation ? 
                    '<span class="badge-captured"><i class="bi bi-check-circle"></i> Captured</span>' : 
                    '<span class="badge-no-location"><i class="bi bi-x-circle"></i> No Location</span>';
                
                const agentLocationDisplay = hasLocation ? 
                    `${order.agent_lat.toFixed(6)}, ${order.agent_lng.toFixed(6)}<br><small class="text-muted">${escapeHtml(order.branch)}</small>` : 
                    'Not captured';
                
                const customerAddress = order.customer_address || '';
                const geocodeButton = (!order.customer_lat && customerAddress !== '') ? 
                    `<button class="btn-geocode" onclick="geocodeCustomer(${order.customer_id}, '${escapeHtml(customerAddress).replace(/'/g, "\\'")}')">
                        <i class="bi bi-geo-alt"></i> Get Location
                    </button>` : '';
                
                const agentLat = order.agent_lat !== null ? order.agent_lat : 'null';
                const agentLng = order.agent_lng !== null ? order.agent_lng : 'null';
                const customerLat = order.customer_lat !== null ? order.customer_lat : 'null';
                const customerLng = order.customer_lng !== null ? order.customer_lng : 'null';
                
                const row = tbody.insertRow();
                
                // Make row clickable to open map
                row.style.cursor = 'pointer';
                row.addEventListener('click', function(e) {
                    // Prevent click if clicking on geocode button
                    if (e.target.classList && e.target.classList.contains('btn-geocode')) {
                        return;
                    }
                    showLocationMap(
                        escapeHtml(order.so_number),
                        escapeHtml(order.agent_name),
                        escapeHtml(order.customer_name),
                        escapeHtml(customerAddress),
                        agentLat, agentLng,
                        customerLat, customerLng
                    );
                });
                
                // Add data attributes for mobile view
                row.setAttribute('data-so-number', escapeHtml(order.so_number));
                row.setAttribute('data-order-date', order.order_date);
                row.setAttribute('data-customer-name', escapeHtml(order.customer_name));
                row.setAttribute('data-customer-address', escapeHtml(customerAddress));
                row.setAttribute('data-agent-name', escapeHtml(order.agent_name));
                row.setAttribute('data-agent-location', agentLocationDisplay);
                row.setAttribute('data-has-location', hasLocation);
                row.setAttribute('data-location-badge', locationBadge);
                row.setAttribute('data-agent-lat', agentLat);
                row.setAttribute('data-agent-lng', agentLng);
                row.setAttribute('data-customer-lat', customerLat);
                row.setAttribute('data-customer-lng', customerLng);
                row.setAttribute('data-customer-id', order.customer_id);
                
                // Add cells (no action column)
                const cell1 = row.insertCell(0);
                cell1.innerHTML = `<strong>${escapeHtml(order.so_number)}</strong><br><small class="text-muted">${formatDate(order.order_date)}</small>`;
                
                const cell2 = row.insertCell(1);
                cell2.innerHTML = escapeHtml(order.customer_name);
                
                const cell3 = row.insertCell(2);
                const displayAddress = customerAddress.length > 60 ? customerAddress.substring(0, 60) + '...' : customerAddress;
                cell3.innerHTML = `${escapeHtml(displayAddress)} ${geocodeButton}`;
                
                const cell4 = row.insertCell(3);
                cell4.innerHTML = escapeHtml(order.agent_name);
                
                const cell5 = row.insertCell(4);
                cell5.innerHTML = agentLocationDisplay;
                
                const cell6 = row.insertCell(5);
                cell6.className = 'text-center';
                cell6.innerHTML = locationBadge;
            });
            
            // Add mobile card structure if on mobile
            if (window.innerWidth <= 768) {
                addMobileCardStructure();
            }
        }

        function addMobileCardStructure() {
            const rows = document.querySelectorAll('#verificationsTable tr');
            rows.forEach(row => {
                if (row.querySelector('.mobile-card-left')) return;
                
                const soNumber = row.getAttribute('data-so-number') || '';
                const orderDate = row.getAttribute('data-order-date') || '';
                const customerName = row.getAttribute('data-customer-name') || '';
                const customerAddress = row.getAttribute('data-customer-address') || '';
                const agentName = row.getAttribute('data-agent-name') || '';
                const agentLocation = row.getAttribute('data-agent-location') || '';
                const locationBadge = row.getAttribute('data-location-badge') || '';
                const agentLat = row.getAttribute('data-agent-lat');
                const agentLng = row.getAttribute('data-agent-lng');
                const customerLat = row.getAttribute('data-customer-lat');
                const customerLng = row.getAttribute('data-customer-lng');
                const customerId = row.getAttribute('data-customer-id');
                
                // Clear all existing cells
                while (row.firstChild) {
                    row.removeChild(row.firstChild);
                }
                
                const leftDiv = document.createElement('div');
                leftDiv.className = 'mobile-card-left';
                const displayAddress = customerAddress.length > 80 ? customerAddress.substring(0, 80) + '...' : customerAddress;
                
                // Add geocode button for mobile if needed
                let geocodeButtonHtml = '';
                if ((customerLat === 'null' || customerLat === null) && customerAddress !== '') {
                    geocodeButtonHtml = `<button class="btn-geocode" onclick="event.stopPropagation(); geocodeCustomer(${customerId}, '${escapeHtml(customerAddress).replace(/'/g, "\\'")}')" style="margin-top: 5px; font-size: 10px;">
                        <i class="bi bi-geo-alt"></i> Get Location
                    </button>`;
                }
                
                leftDiv.innerHTML = `
                    <div class="mobile-so-number">${escapeHtml(soNumber)}</div>
                    <div class="mobile-date">${formatDate(orderDate)}</div>
                    <div class="mobile-customer-name">${escapeHtml(customerName)}</div>
                    <div class="mobile-address">${escapeHtml(displayAddress)}</div>
                    ${geocodeButtonHtml}
                    <div class="mobile-agent-name"><i class="bi bi-person-badge"></i> ${escapeHtml(agentName)}</div>
                    <div class="mobile-agent-location"><i class="bi bi-geo-alt"></i> ${agentLocation}</div>
                    <div class="mobile-status">${locationBadge}</div>
                `;
                
                const rightDiv = document.createElement('div');
                rightDiv.className = 'mobile-view-icon';
                rightDiv.innerHTML = '<i class="bi bi-geo-alt-fill"></i>';
                rightDiv.addEventListener('click', (e) => {
                    e.stopPropagation();
                    showLocationMap(
                        soNumber,
                        agentName,
                        customerName,
                        customerAddress,
                        agentLat, agentLng,
                        customerLat, customerLng
                    );
                });
                
                row.appendChild(leftDiv);
                row.appendChild(rightDiv);
                
                // Make row clickable for mobile (except when clicking on geocode button or view icon)
                row.addEventListener('click', (e) => {
                    if (e.target.classList && e.target.classList.contains('btn-geocode')) {
                        return;
                    }
                    if (e.target.closest('.mobile-view-icon')) {
                        return;
                    }
                    showLocationMap(
                        soNumber,
                        agentName,
                        customerName,
                        customerAddress,
                        agentLat, agentLng,
                        customerLat, customerLng
                    );
                });
            });
        }

        // ================= MAIN FUNCTION =================
        function loadVerifications() {
            if (debounceTimer) clearTimeout(debounceTimer);
            
            debounceTimer = setTimeout(() => {
                const period = document.getElementById('periodFilter').value;
                const date = document.getElementById('dateFilter').value;
                const branchFilter = document.getElementById('branchFilter').value;
                const statusFilter = document.getElementById('statusFilter').value;
                
                // Show loading state
                const tbody = document.getElementById('verificationsTable');
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Loading...</span></div><br>Loading location data...</td></tr>';
                
                const params = new URLSearchParams({
                    ajax: 'get_verifications',
                    period: period,
                    date: date,
                    branch_id: branchFilter,
                    status: statusFilter
                });
                
                // Use the current page path
                const currentPath = window.location.pathname;
                
                fetch(currentPath + '?' + params.toString())
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            updateStats(data.stats);
                            updateBranchesDropdown(data.branches, data.filters.branch_filter);
                            updateTable(data.verifications);
                        } else {
                            console.error('Error loading data:', data);
                            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">Error loading location data. Please try again.</td></tr>';
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">Network error. Please refresh the page and try again.</td></tr>';
                    });
            }, 300);
        }

        function initializeFilters() {
            const periodFilter = document.getElementById('periodFilter');
            const dateFilter = document.getElementById('dateFilter');
            const branchFilter = document.getElementById('branchFilter');
            const statusFilter = document.getElementById('statusFilter');
            
            if (periodFilter) {
                periodFilter.addEventListener('change', function() {
                    toggleDateInput();
                });
            }
            
            if (dateFilter) {
                dateFilter.addEventListener('change', function() {
                    loadVerifications();
                });
            }
            
            if (branchFilter) {
                branchFilter.addEventListener('change', function() {
                    loadVerifications();
                });
            }
            
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    loadVerifications();
                });
            }
        }

        function updateStats(stats) {
            document.getElementById('totalOrders').textContent = stats.total_orders.toLocaleString();
            document.getElementById('withLocation').textContent = stats.with_location.toLocaleString();
            document.getElementById('noLocation').textContent = stats.no_location.toLocaleString();
            document.getElementById('within1km').textContent = stats.verified_within_1km.toLocaleString();
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

        function showLocationMap(soNumber, agentName, customerName, customerAddress, agentLat, agentLng, customerLat, customerLng) {
            const modal = new bootstrap.Modal(document.getElementById('locationMapModal'));
            
            document.getElementById('mapSoNumber').textContent = soNumber;
            document.getElementById('mapAgentName').textContent = agentName;
            document.getElementById('mapCustomerName').textContent = customerName;
            document.getElementById('mapCustomerAddress').textContent = customerAddress;
            
            modal.show();
            
            setTimeout(() => {
                const mapContainer = document.getElementById('mapContainer');
                if (currentMap) {
                    currentMap.remove();
                    currentMap = null;
                }
                
                const agentLatNum = (agentLat !== 'null' && agentLat !== null && !isNaN(parseFloat(agentLat))) ? parseFloat(agentLat) : null;
                const agentLngNum = (agentLng !== 'null' && agentLng !== null && !isNaN(parseFloat(agentLng))) ? parseFloat(agentLng) : null;
                const customerLatNum = (customerLat !== 'null' && customerLat !== null && !isNaN(parseFloat(customerLat))) ? parseFloat(customerLat) : null;
                const customerLngNum = (customerLng !== 'null' && customerLng !== null && !isNaN(parseFloat(customerLng))) ? parseFloat(customerLng) : null;
                
                let hasAgentLocation = (agentLatNum !== null && agentLngNum !== null);
                let hasCustomerLocation = (customerLatNum !== null && customerLngNum !== null);
                
                let center = [14.5995, 120.9842];
                if (hasAgentLocation) center = [agentLatNum, agentLngNum];
                else if (hasCustomerLocation) center = [customerLatNum, customerLngNum];
                
                currentMap = L.map(mapContainer).setView(center, 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(currentMap);
                
                let distance = null;
                
                if (hasAgentLocation) {
                    const agentIcon = L.divIcon({
                        className: 'custom-marker',
                        html: '<div style="background-color: #2E7D32; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.2);"><i class="bi bi-person-check-fill" style="color: white; font-size: 18px;"></i></div>',
                        iconSize: [36, 36],
                        popupAnchor: [0, -18]
                    });
                    L.marker([agentLatNum, agentLngNum], { icon: agentIcon })
                        .bindPopup(`<strong>📍 Sales Agent: ${agentName}</strong><br>Location where order was placed<br><small>${agentLatNum.toFixed(6)}, ${agentLngNum.toFixed(6)}</small>`)
                        .addTo(currentMap);
                }
                
                if (hasCustomerLocation) {
                    const customerIcon = L.divIcon({
                        className: 'custom-marker',
                        html: '<div style="background-color: #dc3545; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.2);"><i class="bi bi-house-door-fill" style="color: white; font-size: 18px;"></i></div>',
                        iconSize: [36, 36],
                        popupAnchor: [0, -18]
                    });
                    L.marker([customerLatNum, customerLngNum], { icon: customerIcon })
                        .bindPopup(`<strong>🏠 Customer: ${customerName}</strong><br>${customerAddress}<br><small>${customerLatNum.toFixed(6)}, ${customerLngNum.toFixed(6)}</small>`)
                        .addTo(currentMap);
                    
                    if (hasAgentLocation) {
                        distance = calculateDistance(agentLatNum, agentLngNum, customerLatNum, customerLngNum);
                        L.polyline([[agentLatNum, agentLngNum], [customerLatNum, customerLngNum]], {
                            color: '#ff9800',
                            weight: 3,
                            opacity: 0.7,
                            dashArray: '5, 10'
                        }).addTo(currentMap);
                        
                        const bounds = L.latLngBounds([[agentLatNum, agentLngNum], [customerLatNum, customerLngNum]]);
                        currentMap.fitBounds(bounds, { padding: [50, 50] });
                    } else {
                        currentMap.setView([customerLatNum, customerLngNum], 14);
                    }
                } else if (hasAgentLocation) {
                    currentMap.setView([agentLatNum, agentLngNum], 14);
                }
                
                const distanceInfo = document.getElementById('mapDistanceInfo');
                if (hasAgentLocation && hasCustomerLocation && distance !== null) {
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
                } else if (hasAgentLocation && !hasCustomerLocation) {
                    distanceInfo.innerHTML = `<div class="distance-badge distance-moderate"><i class="bi bi-info-circle"></i> Customer location not geocoded yet.<br><small>Click "Get Location" in the table to geocode customer address.</small></div>`;
                } else if (!hasAgentLocation && hasCustomerLocation) {
                    distanceInfo.innerHTML = `<div class="distance-badge distance-far"><i class="bi bi-exclamation-triangle"></i> Agent location was not captured during order placement.</div>`;
                } else {
                    distanceInfo.innerHTML = `<div class="distance-badge"><i class="bi bi-x-circle"></i> No location data available for this order.</div>`;
                }
                
                setTimeout(() => currentMap?.invalidateSize(), 100);
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
            initMobileNav();
            
            const savedFilterState = localStorage.getItem('locationFilterHidden');
            if (savedFilterState === 'true') {
                const filterContent = document.getElementById('filterContent');
                const filterIcon = document.getElementById('filterIcon');
                if (filterContent) filterContent.classList.add('collapsed');
                if (filterIcon) filterIcon.style.transform = 'rotate(-90deg)';
            }
            
            // Initialize filters with proper event handlers
            initializeFilters();
            
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) closeMobileSidebar();
                });
            });
            
            // Improved resize handler
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    handleSidebarResize();
                    initMobileNav();
                    
                    // Rebuild table based on current window width
                    if (currentVerifications && currentVerifications.length > 0) {
                        if (window.innerWidth <= 768) {
                            updateTable(currentVerifications);
                        } else {
                            updateTable(currentVerifications);
                        }
                    }
                }, 150);
            });
            
            // Load initial data
            loadVerifications();
        });
    </script>
</body>
</html>