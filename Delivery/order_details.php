<?php
// order_details.php
require_once "../config/database.php";
require_once "../config/session_handler.php";

// Check if user is logged in
if (!isset($_SESSION["user_id"]) || !isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    header("Location: ../login.php");
    exit();
}

$delivery_id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

if (!$delivery_id) {
    header("Location: fordelivery.php");
    exit();
}

// Get delivery details
$query = "
    SELECT 
        d.*,
        so.so_number,
        so.total_amount,
        so.order_date,
        c.customer_name,
        c.contact_person,
        c.phone_number,
        c.address,
        c.city,
        c.longitude,
        c.latitude,
        t.trip_number,
        t.trip_status as trip_status,
        dr.driver_name,
        dr.vehicle_plate_number,
        GROUP_CONCAT(CONCAT(i.item_name, \" (\", soi.quantity_ordered, \")\") SEPARATOR \", \") as items
    FROM deliveries d
    INNER JOIN sales_orders so ON d.so_id = so.so_id
    INNER JOIN customers c ON d.customer_id = c.customer_id
    LEFT JOIN trip_tickets t ON d.trip_id = t.trip_id
    LEFT JOIN drivers dr ON t.driver_id = dr.driver_id
    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
    LEFT JOIN items i ON soi.item_id = i.item_id
    WHERE d.delivery_id = ?
    GROUP BY d.delivery_id
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$result = $stmt->get_result();
$delivery = $result->fetch_assoc();

if (!$delivery) {
    header("Location: fordelivery.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Details - <?php echo $delivery["so_number"]; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .details-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .details-header {
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
            font-size: 0.9rem;
        }
        
        .info-value {
            font-size: 1.1rem;
            margin-bottom: 15px;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .location-map {
            height: 400px;
            width: 100%;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .btn-back {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="btn-back">
            <a href="fordelivery.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Deliveries
            </a>
        </div>
        
        <div class="details-card">
            <div class="details-header d-flex justify-content-between align-items-center">
                <h4>
                    <i class="bi bi-truck"></i> 
                    Delivery Details: <?php echo $delivery["so_number"]; ?>
                </h4>
                <span class="status-badge bg-<?php 
                    echo $delivery["delivery_status"] == "delivered" ? "success" : 
                        ($delivery["delivery_status"] == "pending" ? "warning" : 
                        ($delivery["delivery_status"] == "in-transit" ? "primary" : "secondary")); 
                ?> text-white">
                    <?php echo ucfirst(str_replace("-", " ", $delivery["delivery_status"])); ?>
                </span>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <h5 class="mb-3">Order Information</h5>
                    <div class="info-label">Order Number</div>
                    <div class="info-value"><?php echo $delivery["so_number"]; ?></div>
                    
                    <div class="info-label">Order Date</div>
                    <div class="info-value"><?php echo date("F j, Y", strtotime($delivery["order_date"])); ?></div>
                    
                    <div class="info-label">Total Amount</div>
                    <div class="info-value">₱<?php echo number_format($delivery["total_amount"], 2); ?></div>
                    
                    <div class="info-label">Items</div>
                    <div class="info-value"><?php echo $delivery["items"] ?? "No items listed"; ?></div>
                </div>
                
                <div class="col-md-6">
                    <h5 class="mb-3">Customer Information</h5>
                    <div class="info-label">Customer Name</div>
                    <div class="info-value"><?php echo $delivery["customer_name"]; ?></div>
                    
                    <div class="info-label">Contact Person</div>
                    <div class="info-value"><?php echo $delivery["contact_person"] ?? "N/A"; ?></div>
                    
                    <div class="info-label">Phone Number</div>
                    <div class="info-value"><?php echo $delivery["phone_number"] ?? "N/A"; ?></div>
                    
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo $delivery["address"] . ", " . $delivery["city"]; ?></div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-6">
                    <h5 class="mb-3">Delivery Information</h5>
                    <div class="info-label">Delivery ID</div>
                    <div class="info-value">#<?php echo $delivery["delivery_id"]; ?></div>
                    
                    <div class="info-label">Stop Sequence</div>
                    <div class="info-value">Stop #<?php echo $delivery["stop_sequence"] ?? "N/A"; ?></div>
                    
                    <div class="info-label">Scheduled Date</div>
                    <div class="info-value"><?php echo $delivery["delivery_date"] ? date("F j, Y h:i A", strtotime($delivery["delivery_date"])) : "Not scheduled"; ?></div>
                    
                    <?php if ($delivery["signed_by"]): ?>
                    <div class="info-label">Signed By</div>
                    <div class="info-value"><?php echo $delivery["signed_by"]; ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-6">
                    <h5 class="mb-3">Trip Information</h5>
                    <div class="info-label">Trip Number</div>
                    <div class="info-value"><?php echo $delivery["trip_number"] ?? "Not assigned"; ?></div>
                    
                    <div class="info-label">Driver</div>
                    <div class="info-value"><?php echo $delivery["driver_name"] ?? "Not assigned"; ?></div>
                    
                    <div class="info-label">Vehicle</div>
                    <div class="info-value"><?php echo $delivery["vehicle_plate_number"] ?? "Not assigned"; ?></div>
                </div>
            </div>
            
            <?php if (!empty($delivery["latitude"]) && !empty($delivery["longitude"])): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <h5 class="mb-3">Delivery Location</h5>
                    <div id="deliveryLocationMap" class="location-map"></div>
                    <p class="mt-2 text-muted">
                        <i class="bi bi-geo-alt"></i> 
                        Coordinates: <?php echo $delivery["latitude"]; ?>, <?php echo $delivery["longitude"]; ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($delivery["remarks"])): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <h5 class="mb-3">Remarks</h5>
                    <div class="p-3 bg-light rounded">
                        <?php echo nl2br($delivery["remarks"]); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <?php if (!empty($delivery["latitude"]) && !empty($delivery["longitude"])): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const map = L.map("deliveryLocationMap").setView([<?php echo $delivery["latitude"]; ?>, <?php echo $delivery["longitude"]; ?>], 15);
            
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                attribution: "© OpenStreetMap contributors"
            }).addTo(map);
            
            L.marker([<?php echo $delivery["latitude"]; ?>, <?php echo $delivery["longitude"]; ?>])
                .addTo(map)
                .bindPopup("<b><?php echo addslashes($delivery["customer_name"]); ?></b><br>Delivery Location")
                .openPopup();
        });
    </script>
    <?php endif; ?>
</body>
</html>