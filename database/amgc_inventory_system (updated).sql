-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 23, 2026 at 07:03 AM
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
  `branch_id` int(11) DEFAULT NULL,
  `full_address` text DEFAULT NULL,
  `delivery_instructions` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customer_id`, `customer_name`, `customer_code`, `contact_person`, `email`, `phone_number`, `address`, `city`, `longitude`, `latitude`, `credit_limit`, `credit_used`, `status`, `created_at`, `updated_at`, `branch_id`, `full_address`, `delivery_instructions`) VALUES
(1, 'Customer ABC Corp', 'CUST001', 'John Doe', 'john@abccorp.com', '02-1111-1111', '100 Business Ave', 'Manila', 121.036376, 13.88217100, 50000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-02-12 06:21:58', 2, NULL, NULL),
(2, 'Customer XYZ Ltd', 'CUST002', 'Jane Smith', 'jane@xyzltd.com', '02-2222-2222', '200 Trade Street', 'Quezon City', 121.036376, 13.88217100, 75000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-02-12 06:22:03', 2, NULL, NULL),
(3, 'Customer DEF Inc', 'CUST003', 'Bob Johnson', 'bob@definc.com', '02-3333-3333', '300 Commerce Rd', 'Makati', 121.036376, 13.88217100, 100000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-02-12 06:25:03', 1, NULL, NULL),
(4, 'Van Exel Bathan', 'CUST004', 'Van Exel Bathan', 'vanbathan576@gmail.com', '09989798098', 'Calumala, Latag, Alitagtag', 'Sta. Teresita', 121.036423, 13.88223100, 0.00, 0.00, 'active', '2026-02-11 03:52:56', '2026-02-12 06:22:12', 1, NULL, NULL),
(5, 'Ross Andrei Dolor', 'CUST-202602-0001', 'Ross', 'ross@gmail.com', '09987654322', 'Calumala, Latag, Alitagtag', 'Alitagtag', 121.036427, 13.88223100, 0.00, 0.00, 'active', '2026-02-12 02:36:39', '2026-02-12 06:22:17', 1, NULL, NULL),
(6, 'Jill Anuran', 'CUST-202602-0002', 'Jill', 'jill@gmail.com', '09897675567', 'Latag, Taal, Batangas', 'Taal', 121.036395, 13.88219400, 0.00, 0.00, 'active', '2026-02-12 23:58:02', '2026-02-12 23:58:02', 1, NULL, NULL),
(7, 'Mark Llorin', 'CUST-202602-0003', 'Laurence', 'llorin@gmail.com', '09123456738', 'Calumala, Sta. Teresita, Batangas', 'Sta. Teresita', 120.978868, 13.88895200, 0.00, 0.00, 'active', '2026-02-19 09:11:53', '2026-02-19 09:11:53', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `delivery_id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `so_id` int(11) NOT NULL,
  `pick_list_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `stop_sequence` int(11) DEFAULT NULL,
  `delivery_date` datetime DEFAULT NULL,
  `delivery_status` enum('pending','in-transit','delivered','rejected','partial','rescheduled') DEFAULT 'pending',
  `signed_by` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `rejection_photo` varchar(250) DEFAULT NULL,
  `rejection_reason` varchar(250) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deliveries`
--

INSERT INTO `deliveries` (`delivery_id`, `trip_id`, `so_id`, `pick_list_id`, `customer_id`, `stop_sequence`, `delivery_date`, `delivery_status`, `signed_by`, `remarks`, `created_at`, `updated_at`, `branch_id`, `driver_id`, `rejection_photo`, `rejection_reason`) VALUES
(1, 10, 23, NULL, 2, NULL, NULL, 'in-transit', NULL, NULL, '2026-02-18 03:42:55', '2026-02-18 05:35:58', 1, 2, NULL, ''),
(2, 7, 25, NULL, 2, 1, '2026-02-19 10:27:00', 'rejected', NULL, '\n[2026-02-19 03:27:41] REJECTED by Juan Santos: REASON: Wrong Items. DETAILS: Delivery rejected for order SO-20260211-9607250 to Customer XYZ Ltd. PROPOSED ACTION: Return to Warehouse RETRY DATE: 2026-02-21 [PHOTO: rejected_2_1771468061.jpg]', '2026-02-18 05:31:23', '2026-02-19 02:27:41', 1, 1, 'rejected_2_1771468061.jpg', 'Wrong Items'),
(3, 11, 22, NULL, 2, 1, '2026-02-19 01:54:00', 'rejected', NULL, '\n[2026-02-19 01:55:37] REJECTED by Juan Santos: REASON: Damaged Package. DETAILS: Delivery rejected for order SO-20260211-7130609 to Customer XYZ Ltd. PROPOSED ACTION: Return to Warehouse RETRY DATE: 2026-02-21 [PHOTO: rejected_3_1771462537.jpg]', '2026-02-18 05:31:23', '2026-02-19 00:55:37', 1, 1, NULL, ''),
(4, 12, 21, NULL, 4, 1, '2026-02-19 08:53:00', 'delivered', 'Van', '\n==================================================\nDELIVERY COMPLETED: 2026-02-19 01:54:13\nCompleted by User ID: 8\nSigned by: Van\nDelivery Date: 2026-02-19 08:53:00\nProof Photo: 2026-02/1771462453_69965f3584b78.jpg\n==================================================', '2026-02-18 05:31:23', '2026-02-19 00:54:13', 1, 1, NULL, '');

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
(3, NULL, 'Pedro Reyes', 'DL-345678', '2026-03-15', '09-3456-7890', 'Van', 'PQR-9012', 'active', 2, '2026-02-10 01:38:25', '2026-02-10 01:38:25'),
(8, NULL, 'Llorin', 'DL-9867375', '2027-02-23', '09878761234', 'Truck', 'DBM-3109', 'active', 1, '2026-02-23 05:53:10', '2026-02-23 05:53:10');

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
(1, 1, 1, 150, 18, NULL, NULL, '2026-02-16 06:26:51'),
(2, 1, 2, 80, 6, NULL, NULL, '2026-02-18 03:42:03'),
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

--
-- Dumping data for table `inventory_transactions`
--

INSERT INTO `inventory_transactions` (`transaction_id`, `branch_id`, `item_id`, `transaction_type`, `quantity_changed`, `reference_type`, `reference_id`, `created_by`, `created_at`) VALUES
(0, 1, 4, 'out', 1, 'sales_order', 21, 8, '2026-02-19 00:54:13');

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
(12, 'INV-20260216-00024', 24, 4, 1, '2026-02-16', '2026-03-18', 225.00, 'pending', '2026-02-16 01:53:36', '2026-02-16 01:53:36'),
(13, 'INV-20260216-00027', 27, 6, 1, '2026-02-16', '2026-03-18', 1000.00, 'pending', '2026-02-16 06:24:51', '2026-02-16 06:24:51'),
(14, 'INV-20260216-00023', 23, 2, 1, '2026-02-16', '2026-03-18', 300.00, 'pending', '2026-02-16 06:34:52', '2026-02-16 06:34:52'),
(15, 'INV-20260218-00022', 22, 2, 1, '2026-02-18', '2026-03-20', 325.00, 'pending', '2026-02-18 03:55:27', '2026-02-18 03:55:27'),
(16, 'INV-20260218-00021', 21, 4, 1, '2026-02-18', '2026-03-20', 75.00, 'pending', '2026-02-18 05:20:23', '2026-02-18 05:20:23');

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
  `price_case` decimal(10,2) DEFAULT NULL,
  `price_inner_pack` decimal(10,2) DEFAULT NULL,
  `price_box` decimal(10,2) DEFAULT NULL,
  `price_carton` decimal(10,2) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT 50,
  `status` enum('active','inactive','discontinued') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`item_id`, `item_code`, `item_name`, `description`, `category`, `stock`, `unit_type`, `unit_price`, `price_case`, `price_inner_pack`, `price_box`, `price_carton`, `reorder_level`, `status`, `created_at`, `updated_at`, `branch_id`) VALUES
(1, 'ITEM001', 'Product A', NULL, 'Category 1', 285, 'piece', 100.00, 1200.00, 600.00, 2400.00, 4800.00, 50, 'active', '2026-02-10 01:38:25', '2026-02-23 02:39:15', 1),
(2, 'ITEM002', 'Product B', NULL, 'Category 1', 139, 'piece', 150.00, 1800.00, 900.00, 3600.00, 7200.00, 30, 'active', '2026-02-10 01:38:25', '2026-02-19 06:22:27', 1),
(3, 'ITEM003', 'Product C', NULL, 'Category 2', 145, 'piece', 200.00, 2400.00, 1200.00, 4800.00, 9600.00, 25, 'active', '2026-02-10 01:38:25', '2026-02-19 06:22:27', 2),
(4, 'ITEM004', 'Product D', NULL, 'Category 2', 151, 'piece', 75.00, 900.00, 450.00, 1800.00, 3600.00, 100, 'active', '2026-02-10 01:38:25', '2026-02-19 06:22:27', 2),
(5, 'ITEM005', 'Product E', NULL, 'Category 3', 112, 'piece', 250.00, 3000.00, 1500.00, 6000.00, 12000.00, 20, 'active', '2026-02-10 01:38:25', '2026-02-19 06:22:27', 1);

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
  `pick_date` date DEFAULT current_timestamp(),
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
(15, 'PL-20260216-00026', 26, 1, NULL, '2026-02-16', 'cancelled', NULL, NULL, '2026-02-16 01:44:55', '2026-02-16 05:01:40'),
(16, 'PL-20260216-00025', 25, 1, 4, '2026-02-16', 'open', NULL, NULL, '2026-02-16 01:51:59', '2026-02-18 03:06:44'),
(18, 'PL-20260216-00024', 24, 1, NULL, '2026-02-16', 'completed', NULL, NULL, '2026-02-16 01:53:36', '2026-02-16 05:03:03'),
(20, 'PL-20260216-00027', 27, 1, NULL, '2026-02-16', 'completed', NULL, NULL, '2026-02-16 06:24:51', '2026-02-16 06:27:13'),
(21, 'PL-20260216-00023', 23, 1, 4, '2026-02-16', 'completed', NULL, NULL, '2026-02-16 06:34:52', '2026-02-18 03:42:03'),
(22, 'PL-20260218-00022', 22, 1, 1, '2026-02-18', 'completed', NULL, NULL, '2026-02-18 03:55:27', '2026-02-18 03:58:48'),
(23, 'PL-20260218-00021', 21, 1, 4, '2026-02-18', 'completed', NULL, NULL, '2026-02-18 05:20:23', '2026-02-18 05:22:10');

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
(34, 16, 5, 1, 1, 'Las Pinas', NULL),
(35, 18, 4, 3, 3, NULL, NULL),
(36, 20, 1, 2, 2, NULL, NULL),
(37, 20, 2, 2, 2, NULL, NULL),
(38, 20, 5, 2, 2, NULL, NULL),
(39, 21, 2, 2, 2, 'Las Pinas', NULL),
(40, 22, 4, 1, 0, 'No location data', NULL),
(41, 22, 5, 1, 1, NULL, NULL),
(42, 23, 4, 1, 1, 'No location data', NULL);

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

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`po_id`, `po_number`, `branch_id`, `order_date`, `expected_delivery`, `total_amount`, `po_status`, `supplier_name`, `created_by`, `created_at`, `updated_at`) VALUES
(3, 'PO-20260219-4794', 1, '2026-02-19 00:00:00', '2026-02-24', 2000.00, 'submitted', 'Mark Laurence Llorin', 2, '2026-02-19 06:10:53', '2026-02-19 06:10:53');

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

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`po_item_id`, `po_id`, `item_id`, `quantity_ordered`, `quantity_received`, `unit_price`) VALUES
(1, 1, 2, 7, 0, 150.00),
(2, 3, 1, 20, 0, 100.00);

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
  `delivery_id` int(10) UNSIGNED DEFAULT NULL,
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

INSERT INTO `rmr_requests` (`rmr_id`, `delivery_id`, `rmr_number`, `so_id`, `customer_id`, `item_id`, `return_quantity`, `return_reason`, `reason_details`, `rmr_status`, `received_date`, `received_by`, `inspector_name`, `inspection_type`, `disposition_type`, `created_at`, `updated_at`, `branch_id`) VALUES
(6, NULL, 'RMR-20260212-1770861909', 25, 2, 5, 1, 'damaged', 'Return via sales interface', 'pending', NULL, NULL, NULL, NULL, NULL, '2026-02-12 02:05:09', '2026-02-12 06:28:13', 1);

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
(21, 'SO-20260211-7094558', 4, 1, '2026-02-11 00:00:00', NULL, 75.00, 'delivered', 5, '2026-02-11 05:18:14', '2026-02-19 00:54:13'),
(22, 'SO-20260211-7130609', 2, 1, '2026-02-11 00:00:00', NULL, 325.00, 'cancelled', 5, '2026-02-11 05:18:50', '2026-02-19 00:55:37'),
(23, 'SO-20260211-7592571', 2, 1, '2026-02-11 00:00:00', NULL, 300.00, 'ready', 5, '2026-02-11 05:26:32', '2026-02-18 03:42:03'),
(24, 'SO-20260211-8541533', 4, 1, '2026-02-11 00:00:00', NULL, 225.00, 'delivered', 5, '2026-02-11 05:42:21', '2026-02-16 07:50:49'),
(25, 'SO-20260211-9607250', 2, 1, '2026-02-11 00:00:00', NULL, 250.00, 'cancelled', 5, '2026-02-11 06:00:07', '2026-02-19 02:27:41'),
(27, 'SO-20260216-2976391', 6, 1, '2026-02-16 00:00:00', NULL, 1000.00, 'delivered', 5, '2026-02-16 06:22:56', '2026-02-16 07:50:45'),
(28, 'SO-20260223-4355326', 5, 1, '2026-02-23 03:39:15', NULL, 100.00, 'pending', 5, '2026-02-23 02:39:15', '2026-02-23 02:39:15');

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
(16, 26, 5, 3, 0, 250.00),
(17, 27, 1, 2, 0, 100.00),
(18, 27, 2, 2, 0, 150.00),
(19, 27, 5, 2, 0, 250.00),
(20, 28, 1, 1, 0, 100.00);

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `photo_1` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trip_tickets`
--

INSERT INTO `trip_tickets` (`trip_id`, `trip_number`, `so_id`, `picklist_id`, `driver_id`, `branch_id`, `trip_date`, `trip_status`, `start_time`, `end_time`, `total_stops`, `total_delivered`, `total_failed`, `remarks`, `created_by`, `created_at`, `updated_at`, `photo_1`) VALUES
(7, 'TT-20260216-00025', 25, 16, 1, 1, '2026-02-16', 'delayed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-16 01:51:59', '2026-02-19 02:27:41', NULL),
(8, 'TT-20260216-00024', 24, 18, 1, 1, '2026-02-16', 'completed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-16 01:53:36', '2026-02-16 07:50:49', NULL),
(9, 'TT-20260216-00027', 27, 20, 4, 1, '2026-02-16', 'completed', NULL, NULL, 3, 6, 0, '0', 2, '2026-02-16 06:24:51', '2026-02-18 05:50:21', NULL),
(10, 'TT-20260216-00023', 23, 21, 1, 1, '2026-02-16', 'planned', NULL, NULL, 1, 0, 2, '0', 2, '2026-02-16 06:34:52', '2026-02-16 07:48:01', NULL),
(11, 'TT-20260218-00022', 22, 22, 1, 1, '2026-02-18', 'delayed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-18 03:55:27', '2026-02-19 00:55:37', NULL),
(12, 'TT-20260218-00021', 21, 23, 1, 1, '2026-02-18', 'completed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-18 05:20:23', '2026-02-19 02:26:38', NULL);

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
  `branch_id` int(11) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `category` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `email`, `password_hash`, `first_name`, `last_name`, `role`, `department`, `status`, `created_at`, `updated_at`, `branch_id`, `driver_id`, `contact_number`, `category`) VALUES
(1, 'admin@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Admin', 'User', 'admin', 'Management', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1, NULL, NULL, NULL),
(2, 'branch1@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Branch', 'Manager', 'branch_admin', 'Branch 1', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1, NULL, NULL, NULL),
(3, 'warehouse@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Warehouse', 'Manager', 'warehouse', 'Warehouse', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1, NULL, NULL, NULL),
(4, 'delivery@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Delivery', 'Manager', 'delivery', 'Delivery', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1, NULL, NULL, NULL),
(5, 'sales@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Sales', 'Officer', 'sales', 'Sales', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1, NULL, NULL, NULL),
(6, 'vanbathan576@gmail.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Van Exel', 'Bathan', 'branch_admin', 'Branch 2', 'active', '2026-02-10 02:22:15', '2026-02-13 05:18:32', 2, NULL, NULL, NULL),
(7, 'ross@gmail.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Ross Andrei', 'Dolor', 'branch_admin', 'Calaca Branch', 'active', '2026-02-12 07:59:58', '2026-02-12 07:59:58', 2, NULL, NULL, NULL),
(8, 'juan.santos@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Juan', 'Santos', 'delivery', 'Delivery', 'active', '2026-02-18 03:52:23', '2026-02-18 03:52:23', 1, 1, NULL, NULL),
(10, 'pedro.reyes@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Pedro', 'Reyes', 'delivery', 'Delivery', 'active', '2026-02-18 03:52:23', '2026-02-18 03:52:23', 2, 3, NULL, NULL),
(15, 'rdolor@gmail.com', '$2y$10$fphxKrwxmtkTPSMK3rdii.yHb0gAE2hThRx/qWc1hSDgQXbPh7lqW', 'Dolor', 'Ross', 'sales', NULL, 'active', '2026-02-23 05:52:33', '2026-02-23 05:52:33', 1, NULL, '09296072494', NULL),
(16, 'llorin@amgc.com', '$2y$10$XlsVXoxHqjgRv2A2uKnk.OnPkkI2HbapH/aXsWd4GsXI75RpuCJbW', 'Llorin', 'llorin', 'delivery', NULL, 'active', '2026-02-23 05:53:10', '2026-02-23 05:53:10', 1, 8, '09878761234', NULL),
(17, 'rossandrei706@gmail.com', '$2y$10$4x2.XLlULjprb39LxpeM3eO8ticPP96fSlZMFlHWPruZkxNcJozda', 'Ross Andrei', 'Dolor', 'warehouse', NULL, 'active', '2026-02-23 06:02:01', '2026-02-23 06:02:33', 1, NULL, '09296072494', 'Cement');

-- --------------------------------------------------------

--
-- Table structure for table `vw_sales_order_summary`
--

CREATE TABLE `vw_sales_order_summary` (
  `so_number` varchar(50) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `order_date` datetime DEFAULT NULL,
  `total_amount` decimal(12,2) DEFAULT NULL,
  `order_status` enum('pending','confirmed','processing','ready','delivered','cancelled') DEFAULT NULL,
  `total_items` bigint(21) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vw_trip_status`
--

CREATE TABLE `vw_trip_status` (
  `trip_number` varchar(50) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `trip_date` date DEFAULT NULL,
  `trip_status` enum('planned','in-progress','completed','delayed','cancelled') DEFAULT NULL,
  `total_stops` int(11) DEFAULT NULL,
  `total_delivered` int(11) DEFAULT NULL,
  `total_failed` int(11) DEFAULT NULL,
  `completion_percentage` decimal(16,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`branch_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`delivery_id`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`driver_id`);

--
-- Indexes for table `driver_tracking`
--
ALTER TABLE `driver_tracking`
  ADD PRIMARY KEY (`tracking_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`item_id`);

--
-- Indexes for table `pick_lists`
--
ALTER TABLE `pick_lists`
  ADD PRIMARY KEY (`pick_list_id`);

--
-- Indexes for table `pick_list_items`
--
ALTER TABLE `pick_list_items`
  ADD PRIMARY KEY (`pick_item_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`po_item_id`);

--
-- Indexes for table `rmr_approvals`
--
ALTER TABLE `rmr_approvals`
  ADD PRIMARY KEY (`approval_id`);

--
-- Indexes for table `rmr_requests`
--
ALTER TABLE `rmr_requests`
  ADD PRIMARY KEY (`rmr_id`);

--
-- Indexes for table `sales_orders`
--
ALTER TABLE `sales_orders`
  ADD PRIMARY KEY (`so_id`);

--
-- Indexes for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  ADD PRIMARY KEY (`so_item_id`);

--
-- Indexes for table `sales_reports`
--
ALTER TABLE `sales_reports`
  ADD PRIMARY KEY (`report_id`);

--
-- Indexes for table `trip_tickets`
--
ALTER TABLE `trip_tickets`
  ADD PRIMARY KEY (`trip_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`);

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
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `delivery_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `driver_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `driver_tracking`
--
ALTER TABLE `driver_tracking`
  MODIFY `tracking_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `pick_lists`
--
ALTER TABLE `pick_lists`
  MODIFY `pick_list_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `pick_list_items`
--
ALTER TABLE `pick_list_items`
  MODIFY `pick_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `po_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
  MODIFY `so_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  MODIFY `so_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `sales_reports`
--
ALTER TABLE `sales_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trip_tickets`
--
ALTER TABLE `trip_tickets`
  MODIFY `trip_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
