-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 16, 2026 at 04:00 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `amgc_inventory_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `branch_id` int(11) NOT NULL,
  `branch_name` varchar(100) NOT NULL,
  `branch_code` varchar(20) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`branch_id`, `branch_name`, `branch_code`, `address`, `city`, `contact_number`, `manager_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Main Branch', 'BR001', '123 Main Street', 'Manila', '02-1234-5678', 2, 'active', '2026-02-10 01:38:25', '2026-02-10 01:38:25'),
(2, 'Branch North', 'BR002', '456 North Avenue', 'Quezon City', '02-2345-6789', 2, 'active', '2026-02-10 01:38:25', '2026-02-10 01:38:25'),
(3, 'Branch South', 'BR003', '789 South Road', 'Makati', '02-3456-7890', 2, 'active', '2026-02-10 01:38:25', '2026-02-10 01:38:25');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_code` varchar(50) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT 0.00,
  `credit_used` decimal(12,2) DEFAULT 0.00,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customer_id`, `customer_name`, `customer_code`, `contact_person`, `email`, `phone_number`, `address`, `city`, `longitude`, `latitude`, `credit_limit`, `credit_used`, `status`, `created_at`, `updated_at`, `branch_id`) VALUES
(1, 'Customer ABC Corp', 'CUST001', 'John Doe', 'john@abccorp.com', '02-1111-1111', '100 Business Ave', 'Manila', 121.036376, 13.88217100, 50000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-02-12 06:21:58', 2),
(2, 'Customer XYZ Ltd', 'CUST002', 'Jane Smith', 'jane@xyzltd.com', '02-2222-2222', '200 Trade Street', 'Quezon City', 121.036376, 13.88217100, 75000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-02-12 06:22:03', 2),
(3, 'Customer DEF Inc', 'CUST003', 'Bob Johnson', 'bob@definc.com', '02-3333-3333', '300 Commerce Rd', 'Makati', 121.036376, 13.88217100, 100000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-02-12 06:25:03', 1),
(4, 'Van Exel Bathan', 'CUST004', 'Van Exel Bathan', 'vanbathan576@gmail.com', '09989798098', 'Calumala, Latag, Alitagtag', 'Sta. Teresita', 121.036423, 13.88223100, 0.00, 0.00, 'active', '2026-02-11 03:52:56', '2026-02-12 06:22:12', 1),
(5, 'Ross Andrei Dolor', 'CUST-202602-0001', 'Ross', 'ross@gmail.com', '09987654322', 'Calumala, Latag, Alitagtag', 'Alitagtag', 121.036427, 13.88223100, 0.00, 0.00, 'active', '2026-02-12 02:36:39', '2026-02-12 06:22:17', 1),
(6, 'Jill Anuran', 'CUST-202602-0002', 'Jill', 'jill@gmail.com', '09897675567', 'Latag, Taal, Batangas', 'Taal', 121.036395, 13.88219400, 0.00, 0.00, 'active', '2026-02-12 23:58:02', '2026-02-12 23:58:02', 1);

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `delivery_id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `so_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `stop_sequence` int(11) DEFAULT NULL,
  `delivery_date` datetime DEFAULT NULL,
  `delivery_status` enum('pending','delivered','rejected','partial','rescheduled') DEFAULT 'pending',
  `signed_by` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `driver_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `driver_name` varchar(100) NOT NULL,
  `license_number` varchar(50) NOT NULL,
  `license_expiry` date DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `vehicle_type` varchar(50) DEFAULT NULL,
  `vehicle_plate_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive','on-leave') DEFAULT 'active',
  `branch_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`driver_id`, `user_id`, `driver_name`, `license_number`, `license_expiry`, `contact_number`, `vehicle_type`, `vehicle_plate_number`, `status`, `branch_id`, `created_at`, `updated_at`) VALUES
(1, NULL, 'Juan Santos', 'DL-123456', '2026-12-31', '09-1234-5678', 'Van', 'ABC-1234', 'active', 1, '2026-02-10 01:38:25', '2026-02-10 01:38:25'),
(2, NULL, 'Maria Cruz', 'DL-789012', '2025-06-30', '09-2345-6789', 'Truck', 'XYZ-5678', 'active', 1, '2026-02-10 01:38:25', '2026-02-10 01:38:25'),
(3, NULL, 'Pedro Reyes', 'DL-345678', '2026-03-15', '09-3456-7890', 'Van', 'PQR-9012', 'active', 2, '2026-02-10 01:38:25', '2026-02-10 01:38:25');

-- --------------------------------------------------------

--
-- Table structure for table `driver_tracking`
--

CREATE TABLE `driver_tracking` (
  `tracking_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `location_timestamp` datetime NOT NULL,
  `speed_kmh` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_on_hand` int(11) DEFAULT 0,
  `quantity_reserved` int(11) DEFAULT 0,
  `quantity_available` int(11) GENERATED ALWAYS AS (`quantity_on_hand` - `quantity_reserved`) STORED,
  `last_counted_date` date DEFAULT NULL,
  `last_updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`inventory_id`, `branch_id`, `item_id`, `quantity_on_hand`, `quantity_reserved`, `last_counted_date`, `last_updated_by`, `updated_at`) VALUES
(1, 1, 1, 150, 20, NULL, NULL, '2026-02-10 01:38:25'),
(2, 1, 2, 80, 10, NULL, NULL, '2026-02-10 01:38:25'),
(3, 2, 1, 120, 15, NULL, NULL, '2026-02-10 01:38:25'),
(4, 2, 3, 45, 5, NULL, NULL, '2026-02-10 01:38:25'),
(5, 3, 2, 95, 25, NULL, NULL, '2026-02-10 01:38:25');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_transactions`
--

CREATE TABLE `inventory_transactions` (
  `transaction_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `transaction_type` enum('in','out','adjustment','return') NOT NULL,
  `quantity_changed` int(11) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `so_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`invoice_id`, `invoice_number`, `so_id`, `customer_id`, `branch_id`, `invoice_date`, `due_date`, `total_amount`, `status`, `created_at`, `updated_at`) VALUES
(10, 'INV-20260216-00026', 26, 6, 1, '2026-02-16', '2026-03-18', 1700.00, 'pending', '2026-02-16 01:44:55', '2026-02-16 01:44:55'),
(11, 'INV-20260216-00025', 25, 2, 1, '2026-02-16', '2026-03-18', 250.00, 'pending', '2026-02-16 01:51:59', '2026-02-16 01:51:59'),
(12, 'INV-20260216-00024', 24, 4, 1, '2026-02-16', '2026-03-18', 225.00, 'pending', '2026-02-16 01:53:36', '2026-02-16 01:53:36');

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `item_id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `stock` int(11) NOT NULL,
  `unit_type` enum('case','inner-pack','piece','box','carton') DEFAULT 'piece',
  `unit_price` decimal(10,2) NOT NULL,
  `reorder_level` int(11) DEFAULT 50,
  `status` enum('active','inactive','discontinued') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`item_id`, `item_code`, `item_name`, `description`, `category`, `stock`, `unit_type`, `unit_price`, `reorder_level`, `status`, `created_at`, `updated_at`, `branch_id`) VALUES
(1, 'ITEM001', 'Product A', NULL, 'Category 1', 290, 'piece', 100.00, 50, 'active', '2026-02-10 01:38:25', '2026-02-16 01:54:13', 1),
(2, 'ITEM002', 'Product B', NULL, 'Category 1', 145, 'piece', 150.00, 30, 'active', '2026-02-10 01:38:25', '2026-02-16 01:47:10', 1),
(3, 'ITEM003', 'Product C', NULL, 'Category 2', 145, 'piece', 200.00, 25, 'active', '2026-02-10 01:38:25', '2026-02-12 06:21:22', 2),
(4, 'ITEM004', 'Product D', NULL, 'Category 2', 155, 'piece', 75.00, 100, 'active', '2026-02-10 01:38:25', '2026-02-12 06:21:27', 2),
(5, 'ITEM005', 'Product E', NULL, 'Category 3', 118, 'piece', 250.00, 20, 'active', '2026-02-10 01:38:25', '2026-02-16 01:47:15', 1);

-- --------------------------------------------------------

--
-- Table structure for table `pick_lists`
--

CREATE TABLE `pick_lists` (
  `pick_list_id` int(11) NOT NULL,
  `pick_list_number` varchar(50) NOT NULL,
  `so_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `pick_date` date DEFAULT NULL,
  `pick_status` enum('open','in-progress','completed','cancelled') DEFAULT 'open',
  `picked_by` int(11) DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pick_lists`
--

INSERT INTO `pick_lists` (`pick_list_id`, `pick_list_number`, `so_id`, `branch_id`, `driver_id`, `pick_date`, `pick_status`, `picked_by`, `verified_by`, `created_at`, `updated_at`) VALUES
(3, 'B01-PL-20260213-5573', 26, 1, 1, '2026-02-13', 'cancelled', NULL, NULL, '2026-02-13 03:14:01', '2026-02-16 01:54:13'),
(15, 'PL-20260216-00026', 26, 1, NULL, NULL, 'cancelled', NULL, NULL, '2026-02-16 01:44:55', '2026-02-16 01:47:21'),
(16, 'PL-20260216-00025', 25, 1, NULL, NULL, 'open', NULL, NULL, '2026-02-16 01:51:59', '2026-02-16 01:51:59'),
(18, 'PL-20260216-00024', 24, 1, NULL, NULL, 'open', NULL, NULL, '2026-02-16 01:53:36', '2026-02-16 01:53:36');

-- --------------------------------------------------------

--
-- Table structure for table `pick_list_items`
--

CREATE TABLE `pick_list_items` (
  `pick_item_id` int(11) NOT NULL,
  `pick_list_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_to_pick` int(11) NOT NULL,
  `quantity_picked` int(11) DEFAULT 0,
  `location_bin` varchar(50) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pick_list_items`
--

INSERT INTO `pick_list_items` (`pick_item_id`, `pick_list_id`, `item_id`, `quantity_to_pick`, `quantity_picked`, `location_bin`, `branch_id`) VALUES
(34, 16, 5, 1, 0, NULL, NULL),
(35, 18, 4, 3, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL,
  `po_number` varchar(50) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `order_date` datetime NOT NULL,
  `expected_delivery` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `po_status` enum('draft','submitted','approved','received','cancelled') DEFAULT 'draft',
  `supplier_name` varchar(100) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `po_item_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_ordered` int(11) NOT NULL,
  `quantity_received` int(11) DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) GENERATED ALWAYS AS (`quantity_ordered` * `unit_price`) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rmr_approvals`
--

CREATE TABLE `rmr_approvals` (
  `approval_id` int(11) NOT NULL,
  `rmr_id` int(11) NOT NULL,
  `approved_amount` decimal(12,2) DEFAULT 0.00,
  `approval_notes` text DEFAULT NULL,
  `approved_by` int(11) NOT NULL,
  `approved_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rmr_requests`
--

CREATE TABLE `rmr_requests` (
  `rmr_id` int(11) NOT NULL,
  `rmr_number` varchar(50) NOT NULL,
  `so_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `return_quantity` int(11) NOT NULL,
  `return_reason` enum('damaged','expired','wrong-item','quality','overstock','other') NOT NULL,
  `reason_details` text DEFAULT NULL,
  `rmr_status` enum('pending','processing','approved','rejected','resolved') DEFAULT 'pending',
  `received_date` datetime DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `inspector_name` varchar(100) DEFAULT NULL,
  `inspection_type` enum('visual','functional','lab','sample') DEFAULT NULL,
  `disposition_type` enum('credit','refund','replacement','disposal','return-to-supplier') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rmr_requests`
--

INSERT INTO `rmr_requests` (`rmr_id`, `rmr_number`, `so_id`, `customer_id`, `item_id`, `return_quantity`, `return_reason`, `reason_details`, `rmr_status`, `received_date`, `received_by`, `inspector_name`, `inspection_type`, `disposition_type`, `created_at`, `updated_at`, `branch_id`) VALUES
(6, 'RMR-20260212-1770861909', 25, 2, 5, 1, 'damaged', 'Return via sales interface', 'pending', NULL, NULL, NULL, NULL, NULL, '2026-02-12 02:05:09', '2026-02-12 06:28:13', 1);

-- --------------------------------------------------------

--
-- Table structure for table `sales_orders`
--

CREATE TABLE `sales_orders` (
  `so_id` int(11) NOT NULL,
  `so_number` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `order_date` datetime NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `order_status` enum('pending','confirmed','processing','ready','delivered','cancelled') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_orders`
--

INSERT INTO `sales_orders` (`so_id`, `so_number`, `customer_id`, `branch_id`, `order_date`, `delivery_date`, `total_amount`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES
(14, 'SO-20260211-0655817', 1, 1, '0000-00-00 00:00:00', NULL, 100.00, 'pending', 5, '2026-02-11 03:30:55', '2026-02-11 03:30:55'),
(15, 'SO-20260211-0655504', 1, 1, '0000-00-00 00:00:00', NULL, 100.00, 'pending', 5, '2026-02-11 03:30:55', '2026-02-11 03:30:55'),
(16, 'SO-20260211-0758571', 3, 1, '0000-00-00 00:00:00', NULL, 400.00, 'pending', 5, '2026-02-11 03:32:38', '2026-02-11 03:32:38'),
(17, 'SO-20260211-0758716', 3, 1, '0000-00-00 00:00:00', NULL, 400.00, 'pending', 5, '2026-02-11 03:32:38', '2026-02-13 05:58:14'),
(18, 'SO-20260211-2358681', 4, 1, '2026-02-11 04:59:18', NULL, 450.00, 'pending', 5, '2026-02-11 03:59:18', '2026-02-11 03:59:18'),
(19, 'SO-20260211-6603841', 3, 1, '2026-02-11 06:10:03', NULL, 200.00, 'pending', 5, '2026-02-11 05:10:03', '2026-02-11 05:10:03'),
(20, 'SO-20260211-6701691', 2, 1, '2026-02-11 06:11:41', NULL, 800.00, 'pending', 5, '2026-02-11 05:11:41', '2026-02-11 05:11:41'),
(21, 'SO-20260211-7094558', 4, 1, '2026-02-11 06:18:14', NULL, 75.00, 'pending', 5, '2026-02-11 05:18:14', '2026-02-11 05:18:14'),
(22, 'SO-20260211-7130609', 2, 1, '2026-02-11 06:18:50', NULL, 325.00, 'pending', 5, '2026-02-11 05:18:50', '2026-02-11 05:18:50'),
(23, 'SO-20260211-7592571', 2, 1, '2026-02-11 06:26:32', NULL, 300.00, 'pending', 5, '2026-02-11 05:26:32', '2026-02-11 05:26:32'),
(24, 'SO-20260211-8541533', 4, 1, '2026-02-11 00:00:00', NULL, 225.00, 'confirmed', 5, '2026-02-11 05:42:21', '2026-02-16 01:53:36'),
(25, 'SO-20260211-9607250', 2, 1, '2026-02-11 00:00:00', NULL, 250.00, 'confirmed', 5, '2026-02-11 06:00:07', '2026-02-16 01:51:59');

-- --------------------------------------------------------

--
-- Table structure for table `sales_order_items`
--

CREATE TABLE `sales_order_items` (
  `so_item_id` int(11) NOT NULL,
  `so_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_ordered` int(11) NOT NULL,
  `quantity_delivered` int(11) DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) GENERATED ALWAYS AS (`quantity_ordered` * `unit_price`) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_order_items`
--

INSERT INTO `sales_order_items` (`so_item_id`, `so_id`, `item_id`, `quantity_ordered`, `quantity_delivered`, `unit_price`) VALUES
(1, 14, 1, 1, 0, 100.00),
(2, 15, 1, 1, 0, 100.00),
(3, 16, 1, 4, 0, 100.00),
(4, 17, 1, 4, 0, 100.00),
(5, 18, 2, 3, 0, 150.00),
(6, 19, 3, 1, 0, 200.00),
(7, 20, 3, 4, 0, 200.00),
(8, 21, 4, 1, 0, 75.00),
(9, 22, 4, 1, 0, 75.00),
(10, 22, 5, 1, 0, 250.00),
(11, 23, 2, 2, 0, 150.00),
(12, 24, 4, 3, 0, 75.00),
(13, 25, 5, 1, 0, 250.00),
(14, 26, 1, 2, 0, 100.00),
(15, 26, 2, 5, 0, 150.00),
(16, 26, 5, 3, 0, 250.00);

-- --------------------------------------------------------

--
-- Table structure for table `sales_reports`
--

CREATE TABLE `sales_reports` (
  `report_id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `branch_id` int(11) NOT NULL,
  `total_sales` decimal(12,2) DEFAULT 0.00,
  `total_orders` int(11) DEFAULT 0,
  `total_items_sold` int(11) DEFAULT 0,
  `average_order_value` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trip_tickets`
--

CREATE TABLE `trip_tickets` (
  `trip_id` int(11) NOT NULL,
  `trip_number` varchar(50) NOT NULL,
  `so_id` int(11) NOT NULL,
  `picklist_id` int(11) DEFAULT NULL,
  `driver_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `trip_date` date NOT NULL,
  `trip_status` enum('planned','in-progress','completed','delayed','cancelled') DEFAULT 'planned',
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `total_stops` int(11) DEFAULT NULL,
  `total_delivered` int(11) DEFAULT 0,
  `total_failed` int(11) DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trip_tickets`
--

INSERT INTO `trip_tickets` (`trip_id`, `trip_number`, `so_id`, `picklist_id`, `driver_id`, `branch_id`, `trip_date`, `trip_status`, `start_time`, `end_time`, `total_stops`, `total_delivered`, `total_failed`, `remarks`, `created_by`, `created_at`, `updated_at`) VALUES
(7, 'TT-20260216-00025', 25, 16, 1, 1, '2026-02-16', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-16 01:51:59', '2026-02-16 01:51:59'),
(8, 'TT-20260216-00024', 24, 18, 1, 1, '2026-02-16', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-16 01:53:36', '2026-02-16 01:53:36');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `role` enum('admin','branch_admin','warehouse','delivery','sales','global') NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `email`, `password_hash`, `first_name`, `last_name`, `role`, `department`, `status`, `created_at`, `updated_at`, `branch_id`) VALUES
(1, 'admin@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Admin', 'User', 'admin', 'Management', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1),
(2, 'branch1@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Branch', 'Manager', 'branch_admin', 'Branch 1', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1),
(3, 'warehouse@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Warehouse', 'Manager', 'warehouse', 'Warehouse', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1),
(4, 'delivery@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Delivery', 'Manager', 'delivery', 'Delivery', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1),
(5, 'sales@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Sales', 'Officer', 'sales', 'Sales', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1),
(6, 'vanbathan576@gmail.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Van Exel', 'Bathan', 'branch_admin', 'Branch 2', 'active', '2026-02-10 02:22:15', '2026-02-13 05:18:32', 2),
(7, 'ross@gmail.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Ross Andrei', 'Dolor', 'branch_admin', 'Calaca Branch', 'active', '2026-02-12 07:59:58', '2026-02-12 07:59:58', 2);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_inventory_status`
-- (See below for the actual view)
--
CREATE TABLE `vw_inventory_status` (
`branch_name` varchar(100)
,`item_code` varchar(50)
,`item_name` varchar(150)
,`quantity_on_hand` int(11)
,`quantity_reserved` int(11)
,`quantity_available` int(11)
,`reorder_level` int(11)
,`stock_status` varchar(14)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_sales_order_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_sales_order_summary` (
`so_number` varchar(50)
,`customer_name` varchar(100)
,`branch_name` varchar(100)
,`order_date` datetime
,`total_amount` decimal(12,2)
,`order_status` enum('pending','confirmed','processing','ready','delivered','cancelled')
,`total_items` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_trip_status`
-- (See below for the actual view)
--
CREATE TABLE `vw_trip_status` (
`trip_number` varchar(50)
,`driver_name` varchar(100)
,`branch_name` varchar(100)
,`trip_date` date
,`trip_status` enum('planned','in-progress','completed','delayed','cancelled')
,`total_stops` int(11)
,`total_delivered` int(11)
,`total_failed` int(11)
,`completion_percentage` decimal(16,2)
);

-- --------------------------------------------------------

--
-- Structure for view `vw_inventory_status`
--
DROP TABLE IF EXISTS `vw_inventory_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_inventory_status`  AS SELECT `b`.`branch_name` AS `branch_name`, `i`.`item_code` AS `item_code`, `i`.`item_name` AS `item_name`, `inv`.`quantity_on_hand` AS `quantity_on_hand`, `inv`.`quantity_reserved` AS `quantity_reserved`, `inv`.`quantity_available` AS `quantity_available`, `i`.`reorder_level` AS `reorder_level`, CASE WHEN `inv`.`quantity_available` <= `i`.`reorder_level` THEN 'Low Stock' WHEN `inv`.`quantity_available` > `i`.`reorder_level` * 2 THEN 'Adequate Stock' ELSE 'Normal Stock' END AS `stock_status` FROM ((`inventory` `inv` join `branches` `b` on(`inv`.`branch_id` = `b`.`branch_id`)) join `items` `i` on(`inv`.`item_id` = `i`.`item_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_sales_order_summary`
--
DROP TABLE IF EXISTS `vw_sales_order_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_sales_order_summary`  AS SELECT `so`.`so_number` AS `so_number`, `c`.`customer_name` AS `customer_name`, `b`.`branch_name` AS `branch_name`, `so`.`order_date` AS `order_date`, `so`.`total_amount` AS `total_amount`, `so`.`order_status` AS `order_status`, count(`soi`.`so_item_id`) AS `total_items` FROM (((`sales_orders` `so` join `customers` `c` on(`so`.`customer_id` = `c`.`customer_id`)) join `branches` `b` on(`so`.`branch_id` = `b`.`branch_id`)) left join `sales_order_items` `soi` on(`so`.`so_id` = `soi`.`so_id`)) GROUP BY `so`.`so_id` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_trip_status`
--
DROP TABLE IF EXISTS `vw_trip_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_trip_status`  AS SELECT `tt`.`trip_number` AS `trip_number`, `d`.`driver_name` AS `driver_name`, `b`.`branch_name` AS `branch_name`, `tt`.`trip_date` AS `trip_date`, `tt`.`trip_status` AS `trip_status`, `tt`.`total_stops` AS `total_stops`, `tt`.`total_delivered` AS `total_delivered`, `tt`.`total_failed` AS `total_failed`, round(`tt`.`total_delivered` / nullif(`tt`.`total_stops`,0) * 100,2) AS `completion_percentage` FROM ((`trip_tickets` `tt` join `drivers` `d` on(`tt`.`driver_id` = `d`.`driver_id`)) join `branches` `b` on(`tt`.`branch_id` = `b`.`branch_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`branch_id`),
  ADD UNIQUE KEY `branch_code` (`branch_code`),
  ADD KEY `manager_id` (`manager_id`),
  ADD KEY `idx_branch_code` (`branch_code`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD UNIQUE KEY `customer_code` (`customer_code`),
  ADD KEY `idx_customer_code` (`customer_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`delivery_id`),
  ADD KEY `so_id` (`so_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_trip_id` (`trip_id`),
  ADD KEY `idx_delivery_status` (`delivery_status`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`driver_id`),
  ADD UNIQUE KEY `license_number` (`license_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `idx_driver_name` (`driver_name`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `driver_tracking`
--
ALTER TABLE `driver_tracking`
  ADD PRIMARY KEY (`tracking_id`),
  ADD KEY `trip_id` (`trip_id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_location_timestamp` (`location_timestamp`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`),
  ADD UNIQUE KEY `unique_branch_item` (`branch_id`,`item_id`),
  ADD KEY `last_updated_by` (`last_updated_by`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_quantity_available` (`quantity_available`);

--
-- Indexes for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_transaction_type` (`transaction_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `unique_invoice_number` (`invoice_number`),
  ADD KEY `idx_so_id` (`so_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_branch_id` (`branch_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`item_id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `idx_item_code` (`item_code`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `pick_lists`
--
ALTER TABLE `pick_lists`
  ADD PRIMARY KEY (`pick_list_id`),
  ADD UNIQUE KEY `pick_list_number` (`pick_list_number`),
  ADD KEY `so_id` (`so_id`),
  ADD KEY `picked_by` (`picked_by`),
  ADD KEY `verified_by` (`verified_by`),
  ADD KEY `idx_pick_list_number` (`pick_list_number`),
  ADD KEY `idx_pick_status` (`pick_status`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `fk_picklist_branch` (`branch_id`);

--
-- Indexes for table `pick_list_items`
--
ALTER TABLE `pick_list_items`
  ADD PRIMARY KEY (`pick_item_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `idx_pick_list_id` (`pick_list_id`),
  ADD KEY `fk_picklistitem_branch` (`branch_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`),
  ADD UNIQUE KEY `po_number` (`po_number`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_po_number` (`po_number`),
  ADD KEY `idx_po_status` (`po_status`),
  ADD KEY `fk_purchase_orders_branch` (`branch_id`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`po_item_id`),
  ADD KEY `idx_po_id` (`po_id`),
  ADD KEY `idx_item_id` (`item_id`);

--
-- Indexes for table `rmr_approvals`
--
ALTER TABLE `rmr_approvals`
  ADD PRIMARY KEY (`approval_id`),
  ADD KEY `idx_rmr_id` (`rmr_id`),
  ADD KEY `idx_approved_by` (`approved_by`),
  ADD KEY `idx_approved_at` (`approved_at`);

--
-- Indexes for table `rmr_requests`
--
ALTER TABLE `rmr_requests`
  ADD PRIMARY KEY (`rmr_id`),
  ADD UNIQUE KEY `rmr_number` (`rmr_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `received_by` (`received_by`),
  ADD KEY `idx_rmr_number` (`rmr_number`),
  ADD KEY `idx_rmr_status` (`rmr_status`),
  ADD KEY `fk_rmr_sales_order` (`so_id`),
  ADD KEY `fk_rmr_branch` (`branch_id`);

--
-- Indexes for table `sales_orders`
--
ALTER TABLE `sales_orders`
  ADD PRIMARY KEY (`so_id`),
  ADD UNIQUE KEY `so_number` (`so_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_so_number` (`so_number`),
  ADD KEY `idx_order_status` (`order_status`),
  ADD KEY `idx_order_date` (`order_date`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  ADD PRIMARY KEY (`so_item_id`),
  ADD KEY `idx_so_id` (`so_id`),
  ADD KEY `idx_item_id` (`item_id`);

--
-- Indexes for table `sales_reports`
--
ALTER TABLE `sales_reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `idx_report_date` (`report_date`),
  ADD KEY `idx_branch_id` (`branch_id`);

--
-- Indexes for table `trip_tickets`
--
ALTER TABLE `trip_tickets`
  ADD PRIMARY KEY (`trip_id`),
  ADD UNIQUE KEY `trip_number` (`trip_number`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_trip_number` (`trip_number`),
  ADD KEY `idx_trip_status` (`trip_status`),
  ADD KEY `idx_trip_date` (`trip_date`),
  ADD KEY `fk_trip_tickets_branch` (`branch_id`),
  ADD KEY `idx_so_id` (`so_id`),
  ADD KEY `idx_picklist_id` (`picklist_id`),
  ADD KEY `fk_trip_ticket_driver` (`driver_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `branch_id` (`branch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `delivery_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `driver_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `driver_tracking`
--
ALTER TABLE `driver_tracking`
  MODIFY `tracking_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `pick_lists`
--
ALTER TABLE `pick_lists`
  MODIFY `pick_list_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `pick_list_items`
--
ALTER TABLE `pick_list_items`
  MODIFY `pick_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `po_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rmr_approvals`
--
ALTER TABLE `rmr_approvals`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rmr_requests`
--
ALTER TABLE `rmr_requests`
  MODIFY `rmr_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sales_orders`
--
ALTER TABLE `sales_orders`
  MODIFY `so_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  MODIFY `so_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `sales_reports`
--
ALTER TABLE `sales_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trip_tickets`
--
ALTER TABLE `trip_tickets`
  MODIFY `trip_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `branches`
--
ALTER TABLE `branches`
  ADD CONSTRAINT `branches_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `customers_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`trip_id`) REFERENCES `trip_tickets` (`trip_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `deliveries_ibfk_2` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`),
  ADD CONSTRAINT `deliveries_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`),
  ADD CONSTRAINT `deliveries_ibfk_4` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `drivers`
--
ALTER TABLE `drivers`
  ADD CONSTRAINT `drivers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `drivers_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `driver_tracking`
--
ALTER TABLE `driver_tracking`
  ADD CONSTRAINT `driver_tracking_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`),
  ADD CONSTRAINT `driver_tracking_ibfk_2` FOREIGN KEY (`trip_id`) REFERENCES `trip_tickets` (`trip_id`);

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  ADD CONSTRAINT `inventory_ibfk_3` FOREIGN KEY (`last_updated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `inventory_transactions_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `inventory_transactions_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  ADD CONSTRAINT `inventory_transactions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoice_sales_order` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_so_id` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`),
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`),
  ADD CONSTRAINT `invoices_ibfk_3` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`);

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `pick_lists`
--
ALTER TABLE `pick_lists`
  ADD CONSTRAINT `fk_picklist_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `pick_lists_ibfk_1` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`),
  ADD CONSTRAINT `pick_lists_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `pick_lists_ibfk_3` FOREIGN KEY (`picked_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `pick_lists_ibfk_4` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `pick_lists_ibfk_5` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`);

--
-- Constraints for table `pick_list_items`
--
ALTER TABLE `pick_list_items`
  ADD CONSTRAINT `fk_picklistitem_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `pick_list_items_ibfk_1` FOREIGN KEY (`pick_list_id`) REFERENCES `pick_lists` (`pick_list_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pick_list_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`);

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_purchase_orders_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `purchase_order_items_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_order_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`);

--
-- Constraints for table `rmr_approvals`
--
ALTER TABLE `rmr_approvals`
  ADD CONSTRAINT `fk_rmr_approvals_rmr` FOREIGN KEY (`rmr_id`) REFERENCES `rmr_requests` (`rmr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rmr_approvals_user` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `rmr_requests`
--
ALTER TABLE `rmr_requests`
  ADD CONSTRAINT `fk_rmr_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rmr_sales_order` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `rmr_requests_ibfk_1` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`),
  ADD CONSTRAINT `rmr_requests_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`),
  ADD CONSTRAINT `rmr_requests_ibfk_3` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  ADD CONSTRAINT `rmr_requests_ibfk_4` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `rmr_requests_ibfk_5` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `rmr_requests_ibfk_6` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `sales_orders`
--
ALTER TABLE `sales_orders`
  ADD CONSTRAINT `fk_sales_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `sales_orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`),
  ADD CONSTRAINT `sales_orders_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `sales_orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `sales_orders_ibfk_4` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `sales_orders_ibfk_5` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  ADD CONSTRAINT `sales_order_items_ibfk_1` FOREIGN KEY (`so_id`) REFERENCES `sales_orders` (`so_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_order_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`);

--
-- Constraints for table `sales_reports`
--
ALTER TABLE `sales_reports`
  ADD CONSTRAINT `sales_reports_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `trip_tickets`
--
ALTER TABLE `trip_tickets`
  ADD CONSTRAINT `fk_trip_ticket_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_trip_ticket_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_trip_tickets_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `trip_tickets_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`),
  ADD CONSTRAINT `trip_tickets_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `trip_tickets_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `trip_tickets_ibfk_4` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
