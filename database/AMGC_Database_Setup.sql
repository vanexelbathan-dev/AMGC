-- =====================================================
-- AMGC (A. Macalindong Group of Companies)
-- Database Setup Script
-- =====================================================
-- IMPORTANT: Import this file into your phpMyAdmin or MySQL client
-- 1. Open phpMyAdmin and select "Import" tab
-- 2. Choose this file (AMGC_Database_Setup.sql)
-- 3. Click "Go" to execute
-- Alternative: mysql -u root -p < AMGC_Database_Setup.sql
-- =====================================================

-- Create Database
CREATE DATABASE IF NOT EXISTS amgc_inventory_system;
USE amgc_inventory_system;

-- =====================================================
-- 1. USERS TABLE - Authentication & User Management
-- =====================================================
CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    role ENUM('admin', 'branch_admin', 'warehouse', 'delivery', 'sales', 'global') NOT NULL,
    department VARCHAR(100),
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status)
);

-- =====================================================
-- 2. BRANCHES TABLE - Branch Information
-- =====================================================
CREATE TABLE branches (
    branch_id INT PRIMARY KEY AUTO_INCREMENT,
    branch_name VARCHAR(100) NOT NULL,
    branch_code VARCHAR(20) UNIQUE NOT NULL,
    address VARCHAR(255),
    city VARCHAR(50),
    contact_number VARCHAR(20),
    manager_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(user_id),
    INDEX idx_branch_code (branch_code),
    INDEX idx_status (status)
);

-- =====================================================
-- 3. ITEMS/PRODUCTS TABLE - Product Catalog
-- =====================================================
CREATE TABLE items (
    item_id INT PRIMARY KEY AUTO_INCREMENT,
    item_code VARCHAR(50) UNIQUE NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    unit_type ENUM('case', 'inner-pack', 'piece', 'box', 'carton') DEFAULT 'piece',
    unit_price DECIMAL(10, 2) NOT NULL,
    reorder_level INT DEFAULT 50,
    status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_item_code (item_code),
    INDEX idx_category (category),
    INDEX idx_status (status)
);

-- =====================================================
-- 4. INVENTORY TABLE - Stock Tracking by Location
-- =====================================================
CREATE TABLE inventory (
    inventory_id INT PRIMARY KEY AUTO_INCREMENT,
    branch_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity_on_hand INT DEFAULT 0,
    quantity_reserved INT DEFAULT 0,
    quantity_available INT GENERATED ALWAYS AS (quantity_on_hand - quantity_reserved) STORED,
    last_counted_date DATE,
    last_updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    FOREIGN KEY (last_updated_by) REFERENCES users(user_id),
    UNIQUE KEY unique_branch_item (branch_id, item_id),
    INDEX idx_item_id (item_id),
    INDEX idx_quantity_available (quantity_available)
);

-- =====================================================
-- 5. CUSTOMERS TABLE - Customer Information
-- =====================================================
CREATE TABLE customers (
    customer_id INT PRIMARY KEY AUTO_INCREMENT,
    customer_name VARCHAR(100) NOT NULL,
    customer_code VARCHAR(50) UNIQUE NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone_number VARCHAR(20),
    address VARCHAR(255),
    city VARCHAR(50),
    credit_limit DECIMAL(12, 2) DEFAULT 0.00,
    credit_used DECIMAL(12, 2) DEFAULT 0.00,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_code (customer_code),
    INDEX idx_status (status)
);

-- =====================================================
-- 6. DRIVERS TABLE - Driver Information
-- =====================================================
CREATE TABLE drivers (
    driver_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    driver_name VARCHAR(100) NOT NULL,
    license_number VARCHAR(50) UNIQUE NOT NULL,
    license_expiry DATE,
    contact_number VARCHAR(20),
    vehicle_type VARCHAR(50),
    vehicle_plate_number VARCHAR(50),
    status ENUM('active', 'inactive', 'on-leave') DEFAULT 'active',
    branch_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
    INDEX idx_driver_name (driver_name),
    INDEX idx_status (status)
);

-- =====================================================
-- 7. SALES ORDERS TABLE
-- =====================================================
CREATE TABLE sales_orders (
    so_id INT PRIMARY KEY AUTO_INCREMENT,
    so_number VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    branch_id INT NOT NULL,
    order_date DATETIME NOT NULL,
    delivery_date DATE,
    total_amount DECIMAL(12, 2) NOT NULL,
    order_status ENUM('pending', 'confirmed', 'processing', 'ready', 'delivered', 'cancelled') DEFAULT 'pending',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_so_number (so_number),
    INDEX idx_order_status (order_status),
    INDEX idx_order_date (order_date)
);

-- =====================================================
-- 8. SALES ORDER ITEMS TABLE
-- =====================================================
CREATE TABLE sales_order_items (
    so_item_id INT PRIMARY KEY AUTO_INCREMENT,
    so_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity_ordered INT NOT NULL,
    quantity_delivered INT DEFAULT 0,
    unit_price DECIMAL(10, 2) NOT NULL,
    line_total DECIMAL(12, 2) GENERATED ALWAYS AS (quantity_ordered * unit_price) STORED,
    FOREIGN KEY (so_id) REFERENCES sales_orders(so_id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    INDEX idx_so_id (so_id),
    INDEX idx_item_id (item_id)
);

-- =====================================================
-- 9. PURCHASE ORDERS TABLE
-- =====================================================
CREATE TABLE purchase_orders (
    po_id INT PRIMARY KEY AUTO_INCREMENT,
    po_number VARCHAR(50) UNIQUE NOT NULL,
    branch_id INT NOT NULL,
    order_date DATETIME NOT NULL,
    expected_delivery DATE,
    total_amount DECIMAL(12, 2) NOT NULL,
    po_status ENUM('draft', 'submitted', 'approved', 'received', 'cancelled') DEFAULT 'draft',
    supplier_name VARCHAR(100),
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_po_number (po_number),
    INDEX idx_po_status (po_status)
);

-- =====================================================
-- 10. PURCHASE ORDER ITEMS TABLE
-- =====================================================
CREATE TABLE purchase_order_items (
    po_item_id INT PRIMARY KEY AUTO_INCREMENT,
    po_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity_ordered INT NOT NULL,
    quantity_received INT DEFAULT 0,
    unit_price DECIMAL(10, 2) NOT NULL,
    line_total DECIMAL(12, 2) GENERATED ALWAYS AS (quantity_ordered * unit_price) STORED,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    INDEX idx_po_id (po_id),
    INDEX idx_item_id (item_id)
);

-- =====================================================
-- 11. TRIP TICKETS TABLE - Delivery Trips
-- =====================================================
CREATE TABLE trip_tickets (
    trip_id INT PRIMARY KEY AUTO_INCREMENT,
    trip_number VARCHAR(50) UNIQUE NOT NULL,
    driver_id INT NOT NULL,
    branch_id INT NOT NULL,
    trip_date DATE NOT NULL,
    trip_status ENUM('planned', 'in-progress', 'completed', 'delayed', 'cancelled') DEFAULT 'planned',
    start_time DATETIME,
    end_time DATETIME,
    total_stops INT,
    total_delivered INT DEFAULT 0,
    total_failed INT DEFAULT 0,
    remarks TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES drivers(driver_id),
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_trip_number (trip_number),
    INDEX idx_trip_status (trip_status),
    INDEX idx_trip_date (trip_date)
);

-- =====================================================
-- 12. TRIP STOPS/DELIVERIES TABLE
-- =====================================================
CREATE TABLE deliveries (
    delivery_id INT PRIMARY KEY AUTO_INCREMENT,
    trip_id INT NOT NULL,
    so_id INT NOT NULL,
    customer_id INT NOT NULL,
    stop_sequence INT,
    delivery_date DATETIME,
    delivery_status ENUM('pending', 'delivered', 'rejected', 'partial', 'rescheduled') DEFAULT 'pending',
    signed_by VARCHAR(100),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trip_tickets(trip_id) ON DELETE CASCADE,
    FOREIGN KEY (so_id) REFERENCES sales_orders(so_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    INDEX idx_trip_id (trip_id),
    INDEX idx_delivery_status (delivery_status)
);

-- =====================================================
-- 13. RETURNED MERCHANDISE REQUESTS (RMR) TABLE
-- =====================================================
CREATE TABLE rmr_requests (
    rmr_id INT PRIMARY KEY AUTO_INCREMENT,
    rmr_number VARCHAR(50) UNIQUE NOT NULL,
    so_id INT NOT NULL,
    customer_id INT NOT NULL,
    item_id INT NOT NULL,
    return_quantity INT NOT NULL,
    return_reason ENUM('damaged', 'expired', 'wrong-item', 'quality', 'overstock', 'other') NOT NULL,
    reason_details TEXT,
    rmr_status ENUM('pending', 'processing', 'approved', 'rejected', 'resolved') DEFAULT 'pending',
    received_date DATETIME,
    received_by INT,
    inspector_name VARCHAR(100),
    inspection_type ENUM('visual', 'functional', 'lab', 'sample'),
    disposition_type ENUM('credit', 'refund', 'replacement', 'disposal'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (so_id) REFERENCES sales_orders(so_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    FOREIGN KEY (received_by) REFERENCES users(user_id),
    INDEX idx_rmr_number (rmr_number),
    INDEX idx_rmr_status (rmr_status)
);

-- =====================================================
-- 14. PICK LIST/WAREHOUSE PICKING TABLE
-- =====================================================
CREATE TABLE pick_lists (
    pick_list_id INT PRIMARY KEY AUTO_INCREMENT,
    pick_list_number VARCHAR(50) UNIQUE NOT NULL,
    so_id INT NOT NULL,
    branch_id INT NOT NULL,
    pick_date DATE,
    pick_status ENUM('open', 'in-progress', 'completed', 'cancelled') DEFAULT 'open',
    picked_by INT,
    verified_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (so_id) REFERENCES sales_orders(so_id),
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
    FOREIGN KEY (picked_by) REFERENCES users(user_id),
    FOREIGN KEY (verified_by) REFERENCES users(user_id),
    INDEX idx_pick_list_number (pick_list_number),
    INDEX idx_pick_status (pick_status)
);

-- =====================================================
-- 15. PICK LIST ITEMS TABLE
-- =====================================================
CREATE TABLE pick_list_items (
    pick_item_id INT PRIMARY KEY AUTO_INCREMENT,
    pick_list_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity_to_pick INT NOT NULL,
    quantity_picked INT DEFAULT 0,
    location_bin VARCHAR(50),
    FOREIGN KEY (pick_list_id) REFERENCES pick_lists(pick_list_id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    INDEX idx_pick_list_id (pick_list_id)
);

-- =====================================================
-- 16. INVENTORY TRANSACTIONS LOG
-- =====================================================
CREATE TABLE inventory_transactions (
    transaction_id INT PRIMARY KEY AUTO_INCREMENT,
    branch_id INT NOT NULL,
    item_id INT NOT NULL,
    transaction_type ENUM('in', 'out', 'adjustment', 'return') NOT NULL,
    quantity_changed INT NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
    FOREIGN KEY (item_id) REFERENCES items(item_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_created_at (created_at)
);

-- =====================================================
-- 17. DRIVER TRACKING TABLE - Real-time Location
-- =====================================================
CREATE TABLE driver_tracking (
    tracking_id INT PRIMARY KEY AUTO_INCREMENT,
    driver_id INT NOT NULL,
    trip_id INT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    location_timestamp DATETIME NOT NULL,
    speed_kmh DECIMAL(5, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES drivers(driver_id),
    FOREIGN KEY (trip_id) REFERENCES trip_tickets(trip_id),
    INDEX idx_driver_id (driver_id),
    INDEX idx_location_timestamp (location_timestamp)
);

-- =====================================================
-- 18. SALES REPORTS SUMMARY TABLE
-- =====================================================
CREATE TABLE sales_reports (
    report_id INT PRIMARY KEY AUTO_INCREMENT,
    report_date DATE NOT NULL,
    branch_id INT NOT NULL,
    total_sales DECIMAL(12, 2) DEFAULT 0.00,
    total_orders INT DEFAULT 0,
    total_items_sold INT DEFAULT 0,
    average_order_value DECIMAL(12, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id),
    INDEX idx_report_date (report_date),
    INDEX idx_branch_id (branch_id)
);

-- =====================================================
-- SAMPLE DATA INSERTION
-- =====================================================

-- Insert Sample Users
INSERT INTO users (email, password_hash, first_name, last_name, role, department) VALUES
('admin@amgc.com', '$2y$10$demo_hash_123', 'Admin', 'User', 'admin', 'Management'),
('branch1@amgc.com', '$2y$10$demo_hash_124', 'Branch', 'Manager', 'branch_admin', 'Branch 1'),
('warehouse@amgc.com', '$2y$10$demo_hash_125', 'Warehouse', 'Manager', 'warehouse', 'Warehouse'),
('delivery@amgc.com', '$2y$10$demo_hash_126', 'Delivery', 'Manager', 'delivery', 'Delivery'),
('sales@amgc.com', '$2y$10$demo_hash_127', 'Sales', 'Officer', 'sales', 'Sales');

-- Insert Sample Branches
INSERT INTO branches (branch_name, branch_code, address, city, contact_number, manager_id) VALUES
('Main Branch', 'BR001', '123 Main Street', 'Manila', '02-1234-5678', 2),
('Branch North', 'BR002', '456 North Avenue', 'Quezon City', '02-2345-6789', 2),
('Branch South', 'BR003', '789 South Road', 'Makati', '02-3456-7890', 2);

-- Insert Sample Items
INSERT INTO items (item_code, item_name, category, unit_price, reorder_level) VALUES
('ITEM001', 'Product A', 'Category 1', 100.00, 50),
('ITEM002', 'Product B', 'Category 1', 150.00, 30),
('ITEM003', 'Product C', 'Category 2', 200.00, 25),
('ITEM004', 'Product D', 'Category 2', 75.00, 100),
('ITEM005', 'Product E', 'Category 3', 250.00, 20);

-- Insert Sample Customers
INSERT INTO customers (customer_name, customer_code, contact_person, email, phone_number, address, city, credit_limit) VALUES
('Customer ABC Corp', 'CUST001', 'John Doe', 'john@abccorp.com', '02-1111-1111', '100 Business Ave', 'Manila', 50000.00),
('Customer XYZ Ltd', 'CUST002', 'Jane Smith', 'jane@xyzltd.com', '02-2222-2222', '200 Trade Street', 'Quezon City', 75000.00),
('Customer DEF Inc', 'CUST003', 'Bob Johnson', 'bob@definc.com', '02-3333-3333', '300 Commerce Rd', 'Makati', 100000.00);

-- Insert Sample Inventory
INSERT INTO inventory (branch_id, item_id, quantity_on_hand, quantity_reserved) VALUES
(1, 1, 150, 20),
(1, 2, 80, 10),
(2, 1, 120, 15),
(2, 3, 45, 5),
(3, 2, 95, 25);

-- Insert Sample Drivers
INSERT INTO drivers (driver_name, license_number, license_expiry, contact_number, vehicle_type, vehicle_plate_number, branch_id) VALUES
('Juan Santos', 'DL-123456', '2026-12-31', '09-1234-5678', 'Van', 'ABC-1234', 1),
('Maria Cruz', 'DL-789012', '2025-06-30', '09-2345-6789', 'Truck', 'XYZ-5678', 1),
('Pedro Reyes', 'DL-345678', '2026-03-15', '09-3456-7890', 'Van', 'PQR-9012', 2);

-- =====================================================
-- CREATE VIEWS FOR REPORTING
-- =====================================================

-- View: Current Inventory Status
CREATE VIEW vw_inventory_status AS
SELECT 
    b.branch_name,
    i.item_code,
    i.item_name,
    inv.quantity_on_hand,
    inv.quantity_reserved,
    inv.quantity_available,
    i.reorder_level,
    CASE 
        WHEN inv.quantity_available <= i.reorder_level THEN 'Low Stock'
        WHEN inv.quantity_available > i.reorder_level * 2 THEN 'Adequate Stock'
        ELSE 'Normal Stock'
    END AS stock_status
FROM inventory inv
JOIN branches b ON inv.branch_id = b.branch_id
JOIN items i ON inv.item_id = i.item_id;

-- View: Sales Order Summary
CREATE VIEW vw_sales_order_summary AS
SELECT 
    so.so_number,
    c.customer_name,
    b.branch_name,
    so.order_date,
    so.total_amount,
    so.order_status,
    COUNT(soi.so_item_id) AS total_items
FROM sales_orders so
JOIN customers c ON so.customer_id = c.customer_id
JOIN branches b ON so.branch_id = b.branch_id
LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
GROUP BY so.so_id;

-- View: Trip Ticket Status
CREATE VIEW vw_trip_status AS
SELECT 
    tt.trip_number,
    d.driver_name,
    b.branch_name,
    tt.trip_date,
    tt.trip_status,
    tt.total_stops,
    tt.total_delivered,
    tt.total_failed,
    ROUND((tt.total_delivered / NULLIF(tt.total_stops, 0)) * 100, 2) AS completion_percentage
FROM trip_tickets tt
JOIN drivers d ON tt.driver_id = d.driver_id
JOIN branches b ON tt.branch_id = b.branch_id;

-- =====================================================
-- DATABASE SETUP COMPLETE
-- =====================================================
-- Next Steps:
-- 1. Update your PHP config files to connect to: amgc_inventory_system
-- 2. Default credentials in users table (update passwords before production)
-- 3. Run the sample data inserts to populate initial data
-- 4. Review and customize the schema for your specific needs
-- =====================================================
