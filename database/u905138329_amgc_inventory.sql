-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Apr 21, 2026 at 12:58 AM
-- Server version: 11.8.6-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u905138329_amgc_inventory`
--

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `branch_id` int(11) NOT NULL,
  `branch_name` varchar(100) NOT NULL,
  `branch_code` varchar(20) NOT NULL,
  `business_unit` varchar(100) NOT NULL,
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

INSERT INTO `branches` (`branch_id`, `branch_name`, `branch_code`, `business_unit`, `address`, `city`, `contact_number`, `manager_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Main Branch', 'BR001', '', 'San Felipe, Cuenca', 'Batangas', '02-1234-5678', 2, 'active', '2026-02-10 01:38:25', '2026-03-03 06:53:24'),
(2, 'Branch North', 'BR002', '', 'Lipa', 'Batangas', '02-2345-6789', 2, 'active', '2026-02-10 01:38:25', '2026-03-03 06:53:45'),
(3, 'Calaca Branch', 'BR003', '', 'Calaca', 'Batangas', '02-3456-7890', 2, 'active', '2026-02-10 01:38:25', '2026-04-16 01:08:51'),
(4, 'Gumaca', 'BR004', '', 'Gumaca, Quezon', 'Quezon', '09768654564', 2, 'active', '2026-04-14 01:25:11', '2026-04-14 01:25:11'),
(5, 'Cuenca', 'BR005', '', 'San Felipe, Cuenca, Batangas', 'Batangas', '09564567843', 2, 'active', '2026-04-15 07:24:04', '2026-04-15 07:24:04'),
(7, 'Cement', 'BR006', '', 'San Felipe, Cuenca, Batangas', 'Batangas', '09768654569', 2, 'active', '2026-04-15 07:40:10', '2026-04-15 07:40:10'),
(8, 'Lipa Grocery', 'BR007', '', 'Lipa, Batangas', 'Lipa', '09123456789', 2, 'active', '2026-04-16 04:08:01', '2026-04-16 04:08:28'),
(9, 'Ibaan', 'BR008', '', 'Ibaan, Batangas', 'Batangas', '09768654560', 2, 'active', '2026-04-16 04:13:25', '2026-04-16 04:14:45'),
(10, 'Las Piñas', 'BR009', '', 'Las Piñas', 'Las Piñas', '09768654566', 2, 'active', '2026-04-17 05:17:18', '2026-04-17 05:17:30'),
(11, 'Lucena', 'BR010', 'Cement', NULL, NULL, NULL, NULL, 'active', '2026-04-20 05:32:54', '2026-04-20 05:32:54');

-- --------------------------------------------------------

--
-- Table structure for table `credit_discount_attachments`
--

CREATE TABLE `credit_discount_attachments` (
  `attachment_id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `credit_discount_attachments`
--

INSERT INTO `credit_discount_attachments` (`attachment_id`, `request_id`, `file_name`, `original_file_name`, `file_path`, `file_size`, `file_type`, `uploaded_by`, `uploaded_at`) VALUES
(1, 9, '1775788748_9_0_storeimage.jpg', 'store image.jpg', '../uploads/credit_discount_attachments/1775788748_9_0_storeimage.jpg', 2230674, 'image/jpeg', 5, '2026-04-10 02:39:08'),
(2, 9, '1775788748_9_1_royalmismocase.jpg', 'royal mismocase.jpg', '../uploads/credit_discount_attachments/1775788748_9_1_royalmismocase.jpg', 5605, 'image/jpeg', 5, '2026-04-10 02:39:08');

-- --------------------------------------------------------

--
-- Table structure for table `credit_discount_requests`
--

CREATE TABLE `credit_discount_requests` (
  `request_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `request_type` enum('credit','discount','both','credit_terms') NOT NULL,
  `requested_credit_limit` decimal(12,2) DEFAULT NULL,
  `requested_discount_percent` decimal(5,2) DEFAULT NULL,
  `credit_terms_days` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `effective_from` datetime DEFAULT NULL,
  `effective_until` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `credit_discount_requests`
--

INSERT INTO `credit_discount_requests` (`request_id`, `customer_id`, `agent_id`, `request_type`, `requested_credit_limit`, `requested_discount_percent`, `credit_terms_days`, `reason`, `status`, `admin_notes`, `effective_from`, `effective_until`, `created_at`, `updated_at`, `approved_at`, `approved_by`) VALUES
(1, 4, 5, 'discount', NULL, 10.00, NULL, 'Matagal ng customer', 'approved', '', '2026-03-19 09:07:19', '2026-04-18 09:07:19', '2026-03-17 07:19:39', '2026-03-25 05:31:27', '2026-03-19 09:07:19', 2),
(2, 5, 5, 'discount', NULL, 5.00, NULL, 'Matagal ng Customer', 'approved', 'okay', '2026-03-19 09:09:25', '2026-04-18 09:09:25', '2026-03-19 01:09:07', '2026-03-25 05:31:27', '2026-03-19 09:09:25', 2),
(3, 3, 5, 'discount', NULL, 5.00, NULL, 'discount', 'approved', 'okay', '2026-03-23 11:02:41', '2026-04-22 11:02:41', '2026-03-23 03:00:50', '2026-03-25 05:31:27', '2026-03-23 11:02:41', 2),
(4, 16, 5, 'discount', NULL, 5.00, NULL, 'discount', 'approved', 'okay', '2026-03-23 14:34:34', '2026-04-22 14:34:34', '2026-03-23 06:14:21', '2026-03-25 05:31:27', '2026-03-23 14:34:34', 2),
(5, 16, 5, 'credit_terms', 50000.00, NULL, 30, 'discount', 'approved', 'okay', '2026-03-23 14:33:11', '2026-04-22 14:33:11', '2026-03-23 06:23:48', '2026-03-25 05:31:27', '2026-03-23 14:33:11', 2),
(6, 6, 5, 'both', 50000.00, 5.00, 30, 'Matagal na daw syang customer', 'approved', '', '2026-03-25 01:31:45', '2026-04-14 01:31:45', '2026-03-23 06:24:31', '2026-03-25 05:31:44', '2026-03-25 13:31:44', 2),
(7, 14, 5, 'discount', NULL, 3.00, NULL, '...', 'approved', '1 Day', '2026-03-26 02:04:17', '2026-03-27 02:04:17', '2026-03-26 00:57:39', '2026-03-26 06:04:17', '2026-03-26 14:04:17', 2),
(8, 19, 5, 'discount', NULL, 5.00, NULL, 'Matagal na customer', 'pending', NULL, NULL, NULL, '2026-03-30 00:19:21', '2026-03-30 00:19:21', NULL, NULL),
(9, 21, 5, 'discount', NULL, 5.00, NULL, '...', 'pending', NULL, NULL, NULL, '2026-04-10 02:39:08', '2026-04-10 02:39:08', NULL, NULL),
(10, 29, 38, 'discount', NULL, 10.00, NULL, 'Customer request', 'pending', NULL, NULL, NULL, '2026-04-15 09:11:49', '2026-04-15 09:11:49', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `store_name` varchar(200) DEFAULT NULL,
  `customer_code` varchar(50) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `price_level` varchar(100) NOT NULL DEFAULT 'Standard',
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT 0.00,
  `credit_used` decimal(12,2) DEFAULT 0.00,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `branch_id` int(11) DEFAULT NULL,
  `full_address` text DEFAULT NULL,
  `delivery_instructions` text DEFAULT NULL,
  `city_code` varchar(20) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `store_photo` varchar(500) DEFAULT NULL,
  `store_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customer_id`, `customer_name`, `store_name`, `customer_code`, `contact_person`, `price_level`, `email`, `phone_number`, `address`, `city`, `region`, `province`, `barangay`, `longitude`, `latitude`, `credit_limit`, `credit_used`, `status`, `created_at`, `updated_at`, `branch_id`, `full_address`, `delivery_instructions`, `city_code`, `created_by`, `store_photo`, `store_image`) VALUES
(1, 'Customer ABC Corp', NULL, 'CUST001', 'John Doe', 'Standard', 'john@abccorp.com', '02-1111-1111', '100 Business Ave', 'Manila', NULL, NULL, NULL, 121.036376, 13.88217100, 50000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-04-16 04:48:40', 2, NULL, NULL, NULL, 5, NULL, NULL),
(2, 'Customer XYZ Ltd', NULL, 'CUST002', 'Jane Smith', 'Standard', 'jane@xyzltd.com', '02-2222-2222', '200 Trade Street', 'Quezon City', NULL, NULL, NULL, 121.036376, 13.88217100, 75000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-04-16 04:48:38', 2, NULL, NULL, NULL, 5, NULL, NULL),
(3, 'Customer DEF Inc', NULL, 'CUST003', 'Bob Johnson', 'Standard', 'bob@definc.com', '02-3333-3333', '300 Commerce Rd', 'Makati', NULL, NULL, NULL, 121.036376, 13.88217100, 100000.00, 0.00, 'active', '2026-02-10 01:38:25', '2026-04-16 04:48:33', 1, NULL, NULL, NULL, 5, NULL, NULL),
(4, 'Van Exel Bathan', NULL, 'CUST004', 'Van Exel Bathan', 'Standard', 'vanbathan576@gmail.com', '09989798098', 'Calumala, Latag, Alitagtag', 'Sta. Teresita', NULL, NULL, NULL, 121.036423, 13.88223100, 0.00, 0.00, 'active', '2026-02-11 03:52:56', '2026-04-16 04:48:31', 1, NULL, NULL, NULL, 5, NULL, NULL),
(5, 'Ross Andrei Dolor', NULL, 'CUST-202602-0001', 'Ross', 'Standard', 'ross@gmail.com', '09987654322', 'Calumala, Latag, Alitagtag', 'Alitagtag', NULL, NULL, NULL, 121.036427, 13.88223100, 0.00, 0.00, 'active', '2026-02-12 02:36:39', '2026-04-16 04:48:29', 1, NULL, NULL, NULL, 5, NULL, NULL),
(6, 'Jill Anuran', NULL, 'CUST-202602-0002', 'Jill', 'Standard', 'jill@gmail.com', '09897675567', 'Latag, Taal, Batangas', 'Taal', NULL, NULL, NULL, 121.036395, 13.88219400, 0.00, 665000.00, 'active', '2026-02-12 23:58:02', '2026-04-20 05:25:14', 1, NULL, NULL, NULL, 5, NULL, NULL),
(7, 'Mark Llorin', NULL, 'CUST-202602-0003', 'Laurence', 'Standard', 'llorin@gmail.com', '09123456738', 'Calumala, Sta. Teresita, Batangas', 'Sta. Teresita', NULL, NULL, NULL, 120.978868, 13.88895200, 0.00, 0.00, 'active', '2026-02-19 09:11:53', '2026-04-16 04:48:25', 1, NULL, NULL, NULL, 5, NULL, NULL),
(14, 'Chris', NULL, 'CUST-202603-0001', 'Denden', 'Standard', 'chris@gmail.com', '09546137854', 'San Felipe, Cuenca Batangas', 'Batangas', NULL, NULL, NULL, 121.036190, 13.88239000, 0.00, 0.00, 'active', '2026-03-02 03:09:35', '2026-04-16 04:48:23', 1, NULL, NULL, NULL, 5, NULL, NULL),
(15, 'Christhoper', NULL, 'CUST-202603-0002', 'Denden', 'Standard', 'chris@gmail.com', '09546137854', 'San Felipe, Cuenca Batangas', 'Batangas', '', '', 'San Felipe', 121.036190, 13.88239000, 0.00, 0.00, 'active', '2026-03-02 05:09:49', '2026-04-16 04:48:21', 1, 'San Felipe, Batangas', NULL, '', 5, NULL, NULL),
(16, 'Exel', '', 'CUST-202603-0003', 'Van', 'Standard', 'exel@gmail.com', '09456132584', 'Calumala, Santa Teresita, Batangas, Region IV-A', 'Batangas', NULL, NULL, NULL, 120.979265, 13.88898400, 0.00, 95000.00, 'active', '2026-03-02 12:11:18', '2026-04-20 05:58:44', 1, NULL, NULL, '', 5, NULL, ''),
(17, 'Marinelle Macalindong', NULL, 'CUST-202603-0004', 'Marinelle Macalindong', 'Standard', 'marinellemacalindong@buonomarket.com', '09171545870', 'Macalindong Trading', 'San Jose', NULL, NULL, NULL, 121.136430, 13.81738700, 0.00, 0.00, 'active', '2026-03-03 12:12:05', '2026-04-16 04:48:17', 1, NULL, NULL, NULL, 5, NULL, NULL),
(18, 'Renz', NULL, 'CUST-202603-0005', 'Renz', 'Standard', 'renz@gmail.com', '09456132589', NULL, 'Balayan', 'Region IV-A', 'Batangas', 'Sambat', 120.710600, 13.95030000, 0.00, 0.00, 'active', '2026-03-16 05:57:24', '2026-04-16 04:48:14', 1, 'Sambat, Balayan, Batangas, Region IV-A', NULL, '041003000', 5, NULL, NULL),
(19, 'Daniel Padilla', '', 'CUST-202603-0006', 'Kathryn Bernardo', 'Standard', 'danielpadilla@gmail.com', '09547812365', 'Santa Cruz, Alitagtag, Batangas, Region IV-A', 'Alitagtag', 'Region IV-A', 'Batangas', 'Santa Cruz', 120.978869, 13.88887100, 0.00, 0.00, 'active', '2026-03-26 12:08:49', '2026-04-16 04:48:12', 1, 'Santa Cruz, Alitagtag, Batangas, Region IV-A', NULL, '041002000', 5, NULL, ''),
(20, 'John', NULL, 'CUST-202604-0001', 'Nestor', 'Standard', 'john@gmail.com', '09879342321', NULL, 'Calamba', 'Region IV-A', 'Laguna', '', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-08 06:31:42', '2026-04-16 04:48:10', 1, 'Calamba, Laguna, Region IV-A', NULL, '', 5, NULL, NULL),
(21, 'Andrei Dolor', 'Mini Store', 'CUST-202604-0002', 'Jill Anuran', 'Standard', 'andrei@gmail.com', '09546137850', 'Santa Cruz, Alitagtag, Batangas, Region IV-A', 'Alitagtag', 'Region IV-A', 'Batangas', 'Santa Cruz', 120.994663, 13.86339000, 0.00, 0.00, 'active', '2026-04-09 13:12:39', '2026-04-16 04:48:08', 1, 'Santa Cruz, Alitagtag, Batangas, Region IV-A', NULL, '041002000', 5, NULL, 'store_69d7a5c777f75.jpg'),
(22, 'Jj', 'JJ Store', 'CUST-202604-0003', 'Jj', 'Wholesale', '', '09456789787', 'San Felipe, Cuenca, Batangas, Region IV-A', 'Cuenca', 'Region IV-A', 'Batangas', 'San Felipe', 121.036417, 13.88221900, 0.00, 0.00, 'active', '2026-04-13 06:11:17', '2026-04-16 05:44:30', 1, NULL, NULL, '041009000', 5, NULL, 'store_69e0773e0d4ee.png'),
(23, 'Marlon enriquez', 'Marlon store', 'CUST-202604-0004', 'Marlon', 'Standard', 'm@gmail.com', '09927864556', 'Lopez, Lopez, Quezon, Region IV-A', 'Lopez', 'Region IV-A', 'Quezon', 'Lopez', 122.135792, 13.91175500, 0.00, 0.00, 'active', '2026-04-14 04:53:45', '2026-04-16 04:47:29', 4, NULL, NULL, '045622000', 25, NULL, ''),
(24, 'Jerry Tan', 'JELO', 'CUST-202604-0005', 'Jerry Tan', 'Standard', 'a@gmail.com', '09437061375', 'Lopez, Gumaca, Quezon, Region IV-A', 'Gumaca', 'Region IV-A', 'Quezon', 'Lopez', 122.128652, 13.91440200, 0.00, 0.00, 'active', '2026-04-14 05:27:17', '2026-04-16 04:47:16', 4, NULL, NULL, '045619000', 25, NULL, ''),
(25, 'Nestie', 'Nestie Store', 'CUST-202604-0006', 'Nestor', 'Standard', '', '09546137812', 'Tadlac, Alitagtag, Batangas, Region IV-A', 'Alitagtag', 'Region IV-A', 'Batangas', 'Tadlac', 121.009587, 13.87736900, 0.00, 0.00, 'active', '2026-04-15 01:41:30', '2026-04-15 01:41:30', 1, NULL, NULL, '041002000', NULL, NULL, 'store_69deecca53dcf.jpg'),
(26, 'Emilia Hipolitos', 'Hipolitos', 'CUST-202604-0007', 'Ofel', 'Standard', '', '', 'Marawoy, Lipa, Batangas, Region IV-A', 'Lipa', 'Region IV-A', 'Batangas', 'Marawoy', 121.036150, 13.88261100, 0.00, 0.00, 'active', '2026-04-15 08:25:29', '2026-04-16 04:45:49', 7, NULL, NULL, '', 37, NULL, ''),
(27, 'Holgado hw', 'Holgado hw', 'CUST-202604-0008', 'Justin', 'Standard', '', '09985702858', 'Mahabang Lodlod, Taal, Batangas, Region IV-A', 'Taal', 'Region IV-A', 'Batangas', 'Mahabang Lodlod', 121.036272, 13.88249600, 0.00, 0.00, 'active', '2026-04-15 08:26:14', '2026-04-16 04:46:30', 7, NULL, NULL, '041029000', 37, NULL, ''),
(28, 'Lucinda Cuenca', 'Tito&lucy', 'CUST-202604-0009', 'Lucy', 'Retail', '', '926123456', 'Lipa, Lipa, Batangas, Region IV-A', 'Lipa', 'Region IV-A', 'Batangas', 'Lipa', 121.036166, 13.88259800, 0.00, 0.00, 'active', '2026-04-15 08:56:12', '2026-04-16 04:44:53', 5, NULL, NULL, '', 28, NULL, ''),
(29, 'Jonathan de castro', 'Jonzle', 'CUST-202604-0010', 'Jonathan de castro', 'Retail', '', '+639382925286', 'San Felipe, Cuenca, Batangas, Region IV-A', 'Cuenca', 'Region IV-A', 'Batangas', 'San Felipe', 121.036146, 13.88261000, 0.00, 0.00, 'active', '2026-04-15 09:00:53', '2026-04-16 04:44:38', 5, NULL, NULL, '041009000', 27, NULL, ''),
(30, 'Goldsar', 'Goldsar', 'CUST-202604-0011', 'Nonna Arlene  Malabag Robles', 'Standard', '', '+639206205946', 'San felipe, Cuenca, Batangas, Region IV-A', 'Cuenca', 'Region IV-A', 'Batangas', 'San felipe', 120.985502, 14.59103500, 0.00, 0.00, 'active', '2026-04-16 00:29:31', '2026-04-16 04:44:11', 7, NULL, NULL, '041009000', 37, NULL, 'store_69e02d6b582e6.jpg'),
(31, 'One 10 hardware', 'One 10 hardware', 'CUST-202604-0012', 'Pepeng liaw', 'Standard', '', '+639459622666', 'San Felipe, Cuenca, Batangas, Region IV-A', 'Cuenca', 'Region IV-A', 'Batangas', 'San Felipe', 121.046410, 13.89631300, 0.00, 0.00, 'active', '2026-04-16 00:42:09', '2026-04-16 04:44:14', 7, NULL, NULL, '041009000', 37, NULL, 'store_69e03061a3428.jpg'),
(32, 'OIC CONSTRUCT CORPORATION', 'OIC Construction corporation', 'CUST-202604-0013', 'Jenny Contreros', 'Standard', '', '+639988511072', 'Labac, Cuenca, Batangas, Region IV-A', 'Cuenca', 'Region IV-A', 'Batangas', 'Labac', 121.060688, 13.90158800, 0.00, 0.00, 'active', '2026-04-16 01:06:32', '2026-04-16 04:44:16', 7, NULL, NULL, '041009000', 37, NULL, 'store_69e03618e3708.jpg'),
(33, 'CBP', 'Cbp', 'CUST-202604-0014', 'Cayetano Gervacio', 'Standard', '', '+639159547571', 'Labac, Cuenca, Batangas, Region IV-A', 'Cuenca', 'Region IV-A', 'Batangas', 'Labac', 121.060317, 13.90151100, 0.00, 0.00, 'active', '2026-04-16 01:23:55', '2026-04-16 04:43:34', 7, NULL, NULL, '041009000', 37, NULL, 'store_69e03a2bb21a9.jpg'),
(34, 'Wils trading', 'Wils trading', 'CUST-202604-0015', 'Willy Lim', 'Standard', '', '+639660853758', 'Pinagtungulan, Lipa, Batangas, Region IV-A', 'Lipa', 'Region IV-A', 'Batangas', 'Pinagtungulan', 121.094432, 13.92653300, 0.00, 0.00, 'active', '2026-04-16 01:38:33', '2026-04-16 04:43:30', 7, NULL, NULL, '', 37, NULL, 'store_69e03d990e57c.jpg'),
(35, 'Nida macalindong', 'Macalindong minimart', 'CUST-202604-0016', 'Tita emma', 'Standard', '', '09082544052', 'Barangay 4 POB, Calaca, Batangas, Region IV-A', 'Calaca', 'Region IV-A', 'Batangas', 'Barangay 4 POB', 120.803407, 13.92591500, 0.00, 0.00, 'active', '2026-04-16 01:45:22', '2026-04-16 04:43:17', 3, NULL, NULL, '041007000', 26, NULL, ''),
(36, 'Felipe Maramot', 'Riding Tide HW', 'CUST-202604-0017', 'Rochiel Ann Andaya', 'Standard', '', '09054251904', 'Santa Maria, Bauan, Batangas, Region IV-A', 'Bauan', 'Region IV-A', 'Batangas', 'Santa Maria', 120.964800, 13.77511500, 0.00, 0.00, 'active', '2026-04-16 01:52:09', '2026-04-16 04:42:46', 7, NULL, NULL, '041006000', 37, NULL, ''),
(37, 'IG ANDAL', 'IG ANDAL', 'CUST-202604-0018', 'IRENEO ANDAL', 'Standard', '', '+639660853758', 'San Jose (Pob.), San Jose, Batangas, Region IV-A', 'San Jose', 'Region IV-A', 'Batangas', 'San Jose (Pob.)', 121.089097, 13.88460800, 0.00, 0.00, 'active', '2026-04-16 02:13:33', '2026-04-16 04:42:24', 7, NULL, NULL, '168506000', 37, NULL, 'store_69e045cda2437.jpg'),
(38, 'Jacob hardware', 'Jacob hardware', 'CUST-202604-0019', 'Nestor mercado', 'Standard', '', '+639285560286', 'Mahayahay, San Jose, Batangas, Region IV-A', 'San Jose', 'Region IV-A', 'Batangas', 'Mahayahay', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-16 02:39:46', '2026-04-16 04:42:21', 7, NULL, NULL, '168506000', 37, NULL, 'store_69e04bf29a747.jpg'),
(39, 'Md medina hardware', 'Md medina hardware', 'CUST-202604-0020', 'Mark dexter medina', 'Standard', '', '+639171294097', 'San salvador, Lipa, Batangas, Region IV-A', 'Lipa', 'Region IV-A', 'Batangas', 'San salvador', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-16 03:43:27', '2026-04-16 04:42:18', 7, NULL, NULL, '', 37, NULL, 'store_69e05adfe9cee.jpg'),
(40, 'Nestiee', 'Nestoristore', 'CUST-202604-0021', 'Nestori', 'Standard', '', '09546137231', 'Camastilisan, Calaca, Batangas, Region IV-A', 'Calaca', 'Region IV-A', 'Batangas', 'Camastilisan', 120.803518, 13.92587900, 0.00, 0.00, 'active', '2026-04-16 04:29:47', '2026-04-16 04:36:18', 5, NULL, NULL, '041007000', 38, NULL, ''),
(41, 'Lino lunar', 'Gloria store', 'CUST-202604-0022', 'Karen carlom', 'Standard', '', '09154622425', 'Barangay 4 (Pob.), Calaca, Batangas, Region IV-A', 'Calaca', 'Region IV-A', 'Batangas', 'Barangay 4 (Pob.)', 120.811617, 13.92952800, 0.00, 0.00, 'active', '2026-04-16 05:02:17', '2026-04-16 05:02:17', 3, NULL, NULL, '041007000', 26, NULL, 'store_69e06d5976d26.jpg'),
(42, 'Nemesia de roxas', 'De Roxas 2', 'CUST-202604-0023', 'Nemesia de roxas', 'Standard', '', '09674313270', 'Barangay 4 (Pob.), Calaca, Batangas, Region IV-A', 'Calaca', 'Region IV-A', 'Batangas', 'Barangay 4 (Pob.)', 120.811589, 13.92950700, 0.00, 0.00, 'active', '2026-04-16 05:15:35', '2026-04-16 05:16:47', 3, NULL, NULL, '041007000', 26, NULL, 'store_69e070bf12793.jpg'),
(43, 'Nemesia de roxas', 'De roxas1', 'CUST-202604-0024', 'Nemesia de roxas', 'Standard', '', '09674313270', 'Barangay 4 (Pob.), Calaca, Batangas, Region IV-A', 'Calaca', 'Region IV-A', 'Batangas', 'Barangay 4 (Pob.)', 120.811569, 13.92963700, 0.00, 0.00, 'active', '2026-04-16 05:22:22', '2026-04-16 05:22:22', 3, NULL, NULL, '041007000', 26, NULL, 'store_69e0720e13c9f.jpg'),
(44, 'Jill', 'JILL NESTORE', 'CUST-202604-0025', 'Nestor', 'Standard', '', '09451876452', 'Latag, Taal, Batangas, Region IV-A', 'Taal', 'Region IV-A', 'Batangas', 'Latag', 120.803381, 13.92589800, 0.00, 0.00, 'active', '2026-04-16 05:47:04', '2026-04-16 05:47:04', 1, NULL, NULL, '041029000', 5, NULL, 'store_69e077d879fbd.jpg'),
(45, 'Happy Sand Trading', 'Happy sand trading', 'CUST-202604-0026', 'Jimmy dy', 'Standard', '', '+639175309157', 'San Vicente, Santo Tomas, Batangas, Region IV-A', 'Santo Tomas', 'Region IV-A', 'Batangas', 'San Vicente', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 03:23:00', '2026-04-17 03:23:00', 7, NULL, NULL, '112318000', 37, NULL, 'store_69e1a7947612e.jpg'),
(46, 'Hardwareman trading', 'Hardwareman', 'CUST-202604-0027', '', 'Standard', '', '+639958195659', 'San Vicente, Santo Tomas, Batangas, Region IV-A', 'Santo Tomas', 'Region IV-A', 'Batangas', 'San Vicente', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 03:33:22', '2026-04-17 03:33:22', 7, NULL, NULL, '112318000', 37, NULL, 'store_69e1aa02b927e.jpg'),
(47, 'Edman hardware', 'Edman hardware', 'CUST-202604-0028', 'Herman manarin', 'Standard', '', '+639752345097', 'Tibal-og (Pob.), Santo Tomas, Batangas, Region IV-A', 'Santo Tomas', 'Region IV-A', 'Batangas', 'Tibal-og (Pob.)', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 03:37:21', '2026-04-17 03:48:20', 7, NULL, NULL, '112318000', 37, NULL, 'store_69e1ad84d6892.jpg'),
(48, 'Dyc hardware', 'Dyc hardware', 'CUST-202604-0029', 'Shiela Go', 'Standard', '', '+639778538988', 'San Miguel, Santo Tomas, Batangas, Region IV-A', 'Santo Tomas', 'Region IV-A', 'Batangas', 'San Miguel', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 04:17:34', '2026-04-17 04:17:34', 7, NULL, NULL, '112318000', 37, NULL, 'store_69e1b45e9ac73.jpg'),
(49, 'Lucky yeung construction supply', 'Lucky yeung', 'CUST-202604-0030', 'Carlos yang', 'Standard', '', '+639178659858', 'San Jose, Santo Tomas, Batangas, Region IV-A', 'Santo Tomas', 'Region IV-A', 'Batangas', 'San Jose', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 04:41:19', '2026-04-17 04:41:19', 7, NULL, NULL, '112318000', 37, NULL, 'store_69e1b9efedf18.jpg'),
(50, 'Go pipes', 'Go pipes', 'CUST-202604-0031', '', 'Standard', '', '+639178020388', 'San Jose, Santo Tomas, Batangas, Region IV-A', 'Santo Tomas', 'Region IV-A', 'Batangas', 'San Jose', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 04:47:48', '2026-04-17 04:47:48', 7, NULL, NULL, '112318000', 37, NULL, 'store_69e1bb747c95b.jpg'),
(51, 'Catina construction', 'Catina construction', 'CUST-202604-0032', 'Jeonalyn diaz', 'Standard', '', '+639083572897', 'Magwawa, Santo Tomas, Batangas, Region IV-A', 'Santo Tomas', 'Region IV-A', 'Batangas', 'Magwawa', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 04:53:40', '2026-04-17 04:53:40', 7, NULL, NULL, '112318000', 37, NULL, 'store_69e1bcd4ea37c.jpg'),
(52, 'Best south hardware', 'Best south', 'CUST-202604-0033', 'Analyn Gusman', 'Standard', '', '', 'Bangon, Tanauan, Batangas, Region IV-A', 'Tanauan', 'Region IV-A', 'Batangas', 'Bangon', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 05:05:10', '2026-04-17 05:05:10', 7, NULL, NULL, '083748000', 37, NULL, 'store_69e1bf865b74f.jpg'),
(53, 'Superman Construction', 'Superman Construction', 'CUST-202604-0034', '', 'Standard', '', '+639178307197', 'Tugop, Tanauan, Batangas, Region IV-A', 'Tanauan', 'Region IV-A', 'Batangas', 'Tugop', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 05:15:10', '2026-04-17 05:15:10', 7, NULL, NULL, '083748000', 37, NULL, 'store_69e1c1de2bda6.jpg'),
(54, 'Lgw hardware', 'Lgw', 'CUST-202604-0035', 'Liza sy', 'Standard', '', '+639173382333', 'Hilagpad, Tanauan, Batangas, Region IV-A', 'Tanauan', 'Region IV-A', 'Batangas', 'Hilagpad', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 05:22:53', '2026-04-17 05:23:17', 7, NULL, NULL, '083748000', 37, NULL, 'store_69e1c3ad5cf02.jpg'),
(56, 'Lt  Lodlod', 'Lt lodlod', 'CUST-202604-0037', 'Marianne', 'Standard', '', '+639279643441', 'Lodlod, Lipa, Batangas, Region IV-A', 'Lipa', 'Region IV-A', 'Batangas', 'Lodlod', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 06:26:04', '2026-04-17 06:26:04', 7, NULL, NULL, '', 37, NULL, 'store_69e1d27c4384e.jpg'),
(57, 'Jolo hardware', 'Jolo hardware', 'CUST-202604-0038', 'Jolo robredo', 'Standard', '', '+639565900583', 'Lodlod, Lipa, Batangas, Region IV-A', 'Lipa', 'Region IV-A', 'Batangas', 'Lodlod', 121.132053, 13.92568200, 0.00, 0.00, 'active', '2026-04-17 06:39:20', '2026-04-17 06:39:20', 7, NULL, NULL, '', 37, NULL, 'store_69e1d598e6e0e.jpg'),
(58, 'Gina', 'Gina store', 'CUST-202604-0039', 'Gina', 'Wholesale', '', '09158761292', 'Batong Malake, Los Baños, Laguna, Region IV-A', 'Los Baños', 'Region IV-A', 'Laguna', 'Batong Malake', 120.993180, 14.41616700, 0.00, 0.00, 'active', '2026-04-17 06:39:24', '2026-04-17 06:39:24', 10, NULL, NULL, '043411000', 46, NULL, ''),
(59, 'Pernia hardware', '', 'CUST-202604-0040', 'Louie Pernia', 'Standard', '', '+639603437812', 'Pangao, Lipa, Batangas, Region IV-A', 'Lipa', 'Region IV-A', 'Batangas', 'Pangao', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-17 06:51:05', '2026-04-17 06:51:05', 7, NULL, NULL, '', 37, NULL, 'store_69e1d8591faba.jpg'),
(60, 'Pare Jay Hardware', 'Pare Jay Hardware', 'CUST-202604-0041', 'Jay', 'Standard', '', '+63976888872', 'San Felipe, Cuenca, Batangas, Region IV-A', 'Cuenca', 'Region IV-A', 'Batangas', 'San Felipe', 121.045977, 13.89535700, 0.00, 0.00, 'active', '2026-04-17 07:48:20', '2026-04-17 07:48:20', 7, NULL, NULL, '041009000', 37, NULL, 'store_69e1e5c4c89c6.jpg'),
(61, 'Christopher', 'Chris strore', 'CUST-202604-0042', 'Christopher', 'Wholesale', '', '09285901042', 'Batong Malake, Los Baños, Laguna, Region IV-A', 'Los Baños', 'Region IV-A', 'Laguna', 'Batong Malake', 121.240740, 14.17961400, 0.00, 0.00, 'active', '2026-04-18 01:57:08', '2026-04-18 01:57:08', 10, NULL, NULL, '043411000', 46, NULL, 'store_69e2e4f45e8a4.jpg'),
(62, 'Jaena', 'Jaena store', 'CUST-202604-0043', 'Jaena', 'Wholesale', '', '09179739297', 'Batong Malake, Los Baños, Laguna, Region IV-A', 'Los Baños', 'Region IV-A', 'Laguna', 'Batong Malake', 121.240762, 14.17962800, 0.00, 0.00, 'active', '2026-04-18 02:04:55', '2026-04-18 02:04:55', 10, NULL, NULL, '043411000', 46, NULL, 'store_69e2e6c70b36a.jpg'),
(63, 'Susan', 'Ast Sandie mart', 'CUST-202604-0044', 'Susan', 'Wholesale', '', '09179446830', 'Batong Malake, Los Baños, Laguna, Region IV-A', 'Los Baños', 'Region IV-A', 'Laguna', 'Batong Malake', 121.240422, 14.18043700, 0.00, 0.00, 'active', '2026-04-18 02:17:18', '2026-04-18 03:00:40', 10, NULL, NULL, '043411000', 46, NULL, 'store_69e2e9ae37168.jpg'),
(64, 'Michelle de castro', 'Gildwyn store', 'CUST-202604-0045', 'Michelle', 'Standard', '', '09460328689', 'Inosloban, Lipa, Batangas, Region IV-A', 'Lipa', 'Region IV-A', 'Batangas', 'Inosloban', 121.168245, 13.97891900, 0.00, 0.00, 'active', '2026-04-18 02:37:22', '2026-04-18 02:37:22', 5, NULL, NULL, '', 27, NULL, 'store_69e2ee62533d8.jpg'),
(65, 'Reyvl builders', 'Reyvyl  builders', 'CUST-202604-0046', 'Reynan jay limbo', 'Standard', '', '+639338139586', 'San Jose (Pob.), San Jose, Batangas, Region IV-A', 'San Jose', 'Region IV-A', 'Batangas', 'San Jose (Pob.)', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-18 02:45:15', '2026-04-18 02:45:15', 7, NULL, NULL, '168506000', 37, NULL, 'store_69e2f03bc4736.jpg'),
(66, 'Gina', 'Redd mart', 'CUST-202604-0047', 'Gina', 'Wholesale', '', '0926 046 5623', 'Batong Malake, Los Baños, Laguna, Region IV-A', 'Los Baños', 'Region IV-A', 'Laguna', 'Batong Malake', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-18 02:45:36', '2026-04-18 02:45:36', 10, NULL, NULL, '043411000', 46, NULL, 'store_69e2f05040907.jpg'),
(67, 'Assension', 'Assension store', 'CUST-202604-0048', 'Filipina', 'Wholesale', '', '+639176545310', 'Baybayin (Pob.), Los Baños, Laguna, Region IV-A', 'Los Baños', 'Region IV-A', 'Laguna', 'Baybayin (Pob.)', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-18 02:57:53', '2026-04-18 02:57:53', 10, NULL, NULL, '043411000', 46, NULL, 'store_69e2f331453de.jpg'),
(68, 'Gina', 'Gina bay 1', 'CUST-202604-0049', 'Gina', 'Wholesale', '', '09158761292', 'Santo Domingo, Bay, Laguna, Region IV-A', 'Bay', 'Region IV-A', 'Laguna', 'Santo Domingo', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-18 03:22:03', '2026-04-18 03:22:03', 10, NULL, NULL, '043402000', 46, NULL, 'store_69e2f8db619ad.jpg'),
(69, 'Gina', 'Bay Gina 2', 'CUST-202604-0050', 'Gina', 'Wholesale', '', '09158761292', 'San nikkolas, Bay, Laguna, Region IV-A', 'Bay', 'Region IV-A', 'Laguna', 'San nikkolas', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-18 03:37:29', '2026-04-18 03:39:28', 10, NULL, NULL, '043402000', 46, NULL, 'store_69e2fc7959eae.jpg'),
(70, 'Buddy', 'Jack mart', 'CUST-202604-0051', 'Buddy', 'Wholesale', '', '09989848875', 'Buton, San Pablo, Laguna, Region IV-A', 'San Pablo', 'Region IV-A', 'Laguna', 'Buton', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-18 04:16:34', '2026-04-18 04:16:34', 10, NULL, NULL, '097325000', 46, NULL, 'store_69e305a2db63e.jpg'),
(71, 'Rml hardware', 'RML HARDWARE', 'CUST-202604-0052', 'MARVIN V LIM', 'Standard', '', '+639179061777', 'Bawi, Padre Garcia, Batangas, Region IV-A', 'Padre Garcia', 'Region IV-A', 'Batangas', 'Bawi', 120.984200, 14.59950000, 0.00, 0.00, 'active', '2026-04-18 06:08:52', '2026-04-18 06:08:52', 7, NULL, NULL, '041020000', 37, NULL, 'store_69e31ff498a00.jpg'),
(72, 'Walk-in Customer', NULL, 'WALKIN-001', NULL, 'Standard', 'walkin@example.com', 'N/A', 'Walk-in Customer - No fixed address', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 'active', '2026-04-20 12:45:11', '2026-04-20 12:45:11', 1, NULL, NULL, NULL, 2, NULL, NULL),
(73, 'Walk-in Customer', NULL, 'WALKIN-001', NULL, 'Standard', 'walkin@example.com', 'N/A', 'Walk-in Customer - No fixed address', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 'active', '2026-04-20 12:59:01', '2026-04-20 12:59:01', 5, NULL, NULL, NULL, 35, NULL, NULL);

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
(4, 12, 21, NULL, 4, 1, '2026-02-19 08:53:00', 'delivered', 'Van', '\n==================================================\nDELIVERY COMPLETED: 2026-02-19 01:54:13\nCompleted by User ID: 8\nSigned by: Van\nDelivery Date: 2026-02-19 08:53:00\nProof Photo: 2026-02/1771462453_69965f3584b78.jpg\n==================================================', '2026-02-18 05:31:23', '2026-02-19 00:54:13', 1, 1, NULL, ''),
(5, 13, 30, NULL, 4, 1, NULL, 'in-transit', NULL, '', '2026-03-05 02:05:16', '2026-03-30 05:39:54', 1, 1, NULL, ''),
(6, 14, 35, NULL, 4, 1, '2026-03-09 09:08:00', 'delivered', 'Van', '\n==================================================\nDELIVERY COMPLETED: 2026-03-08 20:56:23\nCompleted by User ID: 16\nSigned by: Van\nDelivery Date: 2026-03-09 08:55:00\nProof Photo: 2026-03/1773017783_69ae1ab78c91a.jpg\n==================================================\n==================================================\nDELIVERY COMPLETED: 2026-03-08 21:02:36\nCompleted by User ID: 16\nSigned by: Van\nDelivery Date: 2026-03-09 09:02:00\nProof Photo: 2026-03/1773018156_69ae1c2c8c8e3.jpg\n==================================================\n==================================================\nDELIVERY COMPLETED: 2026-03-08 21:08:21\nCompleted by User ID: 16\nSigned by: Van\nDelivery Date: 2026-03-09 09:08:00\nProof Photo: 2026-03/1773018501_69ae1d858332e.jpg\n==================================================', '2026-03-06 01:11:14', '2026-03-09 01:08:21', 1, 8, NULL, ''),
(7, 15, 36, NULL, 5, 1, NULL, 'in-transit', NULL, 'Items claimed from warehouse at 3/24/2026, 2:32:07 PM', '2026-03-06 01:45:30', '2026-03-24 06:32:08', 1, 8, NULL, ''),
(8, 16, 37, NULL, 14, 1, NULL, 'in-transit', NULL, NULL, '2026-03-06 07:59:08', '2026-03-09 01:29:52', 1, 8, NULL, ''),
(9, 19, 41, NULL, 5, 1, '2026-04-07 10:47:00', 'delivered', 'van', '\n==================================================\nDELIVERY COMPLETED: 2026-04-07 02:48:00\nCompleted by User ID: 8\nSigned by: van\nDelivery Date: 2026-04-07 10:47:00\nProof Photo: 2026-04/1775530080_69d470609fdce.png\n==================================================', '2026-03-10 05:18:40', '2026-04-07 02:48:00', 1, 1, NULL, ''),
(10, 22, 43, NULL, 17, 1, NULL, 'in-transit', NULL, 'Items claimed from warehouse at 3/31/2026, 9:01:20 AM', '2026-03-10 05:26:50', '2026-03-31 01:01:20', 1, 1, NULL, ''),
(11, 17, 38, NULL, 6, 1, NULL, 'pending', NULL, NULL, '2026-03-24 06:31:57', '2026-03-24 06:31:57', 1, 8, NULL, ''),
(12, 18, 39, NULL, 5, 1, NULL, 'pending', NULL, NULL, '2026-03-24 06:31:57', '2026-03-24 06:31:57', 1, 8, NULL, ''),
(13, 20, 42, NULL, 7, 1, NULL, 'in-transit', NULL, 'Items claimed from warehouse at 3/24/2026, 2:34:23 PM', '2026-03-24 06:31:57', '2026-03-24 06:34:23', 1, 8, NULL, ''),
(14, 21, 40, NULL, 4, 1, NULL, 'pending', NULL, NULL, '2026-03-24 06:31:57', '2026-03-24 06:31:57', 1, 8, NULL, ''),
(15, 23, 29, NULL, 17, 1, NULL, 'pending', NULL, NULL, '2026-03-24 06:31:57', '2026-03-24 06:31:57', 1, 8, NULL, ''),
(16, 24, 44, NULL, 4, 1, '2026-04-07 07:41:00', 'delivered', 'ross', 'Items claimed from warehouse at 3/31/2026, 9:54:22 PM\n==================================================\nDELIVERY COMPLETED: 2026-04-06 23:41:54\nCompleted by User ID: 8\nSigned by: ross\nDelivery Date: 2026-04-07 07:41:00\nProof Photo: 2026-04/1775518914_69d444c2ad325.png\n==================================================', '2026-03-31 13:53:55', '2026-04-06 23:41:54', 1, 1, NULL, ''),
(17, 25, 58, NULL, 21, 1, NULL, 'pending', NULL, NULL, '2026-04-10 00:33:08', '2026-04-10 00:33:08', 1, 1, NULL, ''),
(18, 26, 57, NULL, 3, 1, NULL, 'pending', NULL, NULL, '2026-04-10 00:33:08', '2026-04-10 00:33:08', 1, 1, NULL, ''),
(19, 27, 59, NULL, 21, 1, NULL, 'pending', NULL, NULL, '2026-04-10 00:33:08', '2026-04-10 00:33:08', 1, 1, NULL, ''),
(20, 28, 63, NULL, 23, 1, '2026-04-14 13:14:00', 'delivered', 'Jill', '\n==================================================\nDELIVERY COMPLETED: 2026-04-14 05:14:42\nCompleted by User ID: 34\nSigned by: Jill\nDelivery Date: 2026-04-14 13:14:00\nProof Photo: 2026-04/1776143682_69ddcd4265445.jpeg\n==================================================', '2026-04-14 05:11:40', '2026-04-14 05:14:42', 4, 11, NULL, '');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_latitude` decimal(10,8) DEFAULT NULL,
  `last_longitude` decimal(11,8) DEFAULT NULL,
  `last_location_update` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`driver_id`, `user_id`, `driver_name`, `license_number`, `license_expiry`, `contact_number`, `vehicle_type`, `vehicle_plate_number`, `status`, `branch_id`, `created_at`, `updated_at`, `last_latitude`, `last_longitude`, `last_location_update`) VALUES
(1, NULL, 'Juan Santos', 'DL-123456', '2026-12-31', '09-1234-5678', 'Van', 'ABC-1234', 'active', 1, '2026-02-10 01:38:25', '2026-04-15 03:11:14', 13.88219238, 121.03628757, '2026-04-15 11:11:14'),
(3, NULL, 'Pedro Reyes', 'DL-345678', '2026-03-15', '09-3456-7890', 'Van', 'PQR-9012', 'active', 2, '2026-02-10 01:38:25', '2026-03-09 07:59:00', 13.88243410, 121.03614985, '2026-03-09 15:59:00'),
(8, NULL, 'Llorin', 'DL-9867375', '2027-02-23', '09878761234', 'Truck', 'DBM-3109', 'active', 1, '2026-02-23 05:53:10', '2026-03-24 06:38:57', 13.88236123, 121.03631983, '2026-03-24 14:38:57'),
(10, NULL, 'Andrei Dolor', 'DA-20-24-001235', NULL, '09876785647', 'Truck', 'ROS-0716', 'active', 1, '2026-03-06 02:50:10', '2026-03-27 03:42:48', 13.88227332, 121.03615209, '2026-03-27 11:42:48'),
(11, NULL, 'John John', '1110', '2031-06-14', '09756475864', 'Car', 'ABC - 1234', 'active', 4, '2026-04-14 05:07:27', '2026-04-14 13:16:11', 13.86366085, 120.99466755, '2026-04-14 21:16:11'),
(12, NULL, 'Benidict', 'no2', '2026-08-31', '09193990531', 'Van', 'CBB 5456', 'active', 3, '2026-04-16 01:16:53', '2026-04-16 01:16:53', NULL, NULL, NULL),
(13, NULL, 'MARK LALAGUNA', '123456', '2027-05-17', '09179263362', 'Truck', 'CAR6518', 'active', 10, '2026-04-17 06:30:38', '2026-04-17 06:30:38', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `driver_locations`
--

CREATE TABLE `driver_locations` (
  `id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `accuracy` decimal(8,2) DEFAULT NULL,
  `speed` decimal(8,2) DEFAULT NULL,
  `heading` decimal(8,2) DEFAULT NULL,
  `last_update` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(4) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `driver_locations`
--

INSERT INTO `driver_locations` (`id`, `driver_id`, `driver_name`, `trip_id`, `latitude`, `longitude`, `accuracy`, `speed`, `heading`, `last_update`, `is_active`) VALUES
(27, 1, 'Juan Santos', 13, 13.88236550, 121.03632350, 212.00, 0.00, 0.00, '2026-03-09 07:51:15', 1),
(41, 8, 'Llorin', 16, 13.88197333, 121.03610349, 149.00, 0.00, 0.00, '2026-03-09 01:58:53', 1),
(42, 3, 'Pedro Reyes', NULL, 13.88221009, 121.03641476, 89.00, 0.00, 0.00, '2026-03-03 07:40:21', 0),
(43, 10, 'Andrei Dolor', NULL, 13.88221217, 121.03641635, 93.00, 0.00, 0.00, '2026-03-06 02:54:38', 0);

-- --------------------------------------------------------

--
-- Table structure for table `driver_sessions`
--

CREATE TABLE `driver_sessions` (
  `session_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `session_token` varchar(255) DEFAULT NULL,
  `shift_start` datetime NOT NULL,
  `shift_end` datetime DEFAULT NULL,
  `last_heartbeat` datetime DEFAULT NULL,
  `last_location_update` datetime DEFAULT NULL,
  `current_latitude` decimal(10,8) DEFAULT NULL,
  `current_longitude` decimal(11,8) DEFAULT NULL,
  `is_online` tinyint(4) DEFAULT 0,
  `gps_active` tinyint(4) DEFAULT 0,
  `total_distance` decimal(10,2) DEFAULT 0.00,
  `trip_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `driver_sessions`
--

INSERT INTO `driver_sessions` (`session_id`, `driver_id`, `session_token`, `shift_start`, `shift_end`, `last_heartbeat`, `last_location_update`, `current_latitude`, `current_longitude`, `is_online`, `gps_active`, `total_distance`, `trip_id`, `created_at`, `updated_at`) VALUES
(1, 8, '4a1af48974b25610783dbf2fccbb5070_1772518040', '2026-03-03 14:07:20', '2026-03-03 15:10:36', '2026-03-03 15:09:46', NULL, 13.88221485, 121.03641485, 0, 0, 0.00, NULL, '2026-03-03 06:07:20', '2026-03-03 07:10:36'),
(2, 1, '4a1af48974b25610783dbf2fccbb5070_1772519410', '2026-03-03 14:30:10', '2026-03-03 14:48:11', NULL, NULL, NULL, NULL, 0, 0, 0.00, 10, '2026-03-03 06:30:10', '2026-03-03 06:48:11'),
(3, 1, '5ef6b4ffcb467dfa83c82ae1a172bcb4_1772520492', '2026-03-03 14:48:11', '2026-03-03 14:48:40', NULL, NULL, NULL, NULL, 0, 0, 0.00, 10, '2026-03-03 06:48:11', '2026-03-03 06:48:40'),
(4, 1, '5ef6b4ffcb467dfa83c82ae1a172bcb4_1772520551', '2026-03-03 14:49:10', '2026-03-03 14:49:12', NULL, NULL, NULL, NULL, 0, 0, 0.00, 10, '2026-03-03 06:49:10', '2026-03-03 06:49:12'),
(5, 1, '4a1af48974b25610783dbf2fccbb5070_1772520622', '2026-03-03 14:50:21', '2026-03-03 15:04:36', NULL, NULL, NULL, NULL, 0, 0, 0.00, 10, '2026-03-03 06:50:21', '2026-03-03 07:04:36'),
(6, 1, '5ef6b4ffcb467dfa83c82ae1a172bcb4_1772521493', '2026-03-03 15:04:53', '2026-03-03 15:05:26', NULL, NULL, NULL, NULL, 0, 0, 0.00, 10, '2026-03-03 07:04:53', '2026-03-03 07:05:26'),
(7, 1, '5ef6b4ffcb467dfa83c82ae1a172bcb4_1772521528', '2026-03-03 15:05:28', '2026-03-03 15:07:52', '2026-03-03 15:07:50', NULL, 13.88221217, 121.03641635, 0, 0, 0.00, 10, '2026-03-03 07:05:28', '2026-03-03 07:07:52'),
(8, 1, '4a1af48974b25610783dbf2fccbb5070_1772521674', '2026-03-03 15:07:54', '2026-03-03 15:10:15', '2026-03-03 15:10:11', NULL, 13.88223469, 121.03643347, 0, 0, 0.00, 10, '2026-03-03 07:07:54', '2026-03-03 07:10:15'),
(9, 1, '5ef6b4ffcb467dfa83c82ae1a172bcb4_1772521825', '2026-03-03 15:10:24', '2026-03-03 15:50:44', '2026-03-03 15:50:27', NULL, 13.88221316, 121.03641748, 0, 0, 0.00, 10, '2026-03-03 07:10:24', '2026-03-03 07:50:44'),
(10, 8, '1641714668d8476474de3e2715444921_1772521920', '2026-03-03 15:12:00', '2026-03-03 15:12:15', '2026-03-03 15:12:04', NULL, 13.88221552, 121.03641430, 0, 0, 0.00, NULL, '2026-03-03 07:12:00', '2026-03-03 07:12:15'),
(11, 8, '1641714668d8476474de3e2715444921_1772522666', '2026-03-03 15:24:26', '2026-03-03 15:24:27', NULL, NULL, NULL, NULL, 0, 0, 0.00, NULL, '2026-03-03 07:24:26', '2026-03-03 07:24:27'),
(12, 8, '1641714668d8476474de3e2715444921_1772522667', '2026-03-03 15:24:27', '2026-03-03 15:36:53', '2026-03-03 15:36:45', NULL, 13.88221009, 121.03641476, 0, 0, 0.00, NULL, '2026-03-03 07:24:27', '2026-03-03 07:36:53'),
(13, 3, '19de192417784708de848671115991eb_1772523609', '2026-03-03 15:40:08', '2026-03-03 15:40:09', NULL, NULL, NULL, NULL, 0, 0, 0.00, NULL, '2026-03-03 07:40:08', '2026-03-03 07:40:09'),
(14, 3, '19de192417784708de848671115991eb_1772523609', '2026-03-03 15:40:09', '2026-03-03 15:40:21', '2026-03-03 15:40:11', NULL, 13.88221009, 121.03641476, 0, 0, 0.00, NULL, '2026-03-03 07:40:09', '2026-03-03 07:40:21'),
(15, 1, 'a508bba87db277c0db2e73e0f3a11e26_1772524249', '2026-03-03 15:50:48', '2026-03-03 15:51:38', '2026-03-03 15:51:34', NULL, 13.88221009, 121.03641476, 0, 0, 0.00, 10, '2026-03-03 07:50:48', '2026-03-03 07:51:38'),
(16, 1, 'a508bba87db277c0db2e73e0f3a11e26_1772524299', '2026-03-03 15:51:39', '2026-03-03 20:35:34', '2026-03-03 20:25:07', NULL, 13.81736572, 121.13638543, 0, 0, 0.00, 10, '2026-03-03 07:51:39', '2026-03-03 12:35:34'),
(17, 1, 'af6cab6ace4bc0eaf9e192416d243437_1772541334', '2026-03-03 20:35:34', '2026-03-03 20:35:38', NULL, NULL, NULL, NULL, 0, 0, 0.00, 10, '2026-03-03 12:35:34', '2026-03-03 12:35:38'),
(18, 1, 'af6cab6ace4bc0eaf9e192416d243437_1772541337', '2026-03-03 20:35:38', '2026-03-05 10:06:23', '2026-03-05 10:05:20', NULL, 13.88221217, 121.03641635, 0, 0, 0.00, 10, '2026-03-03 12:35:38', '2026-03-05 02:06:23'),
(19, 1, 'bf0a5f3d5aff91ac3d893188425aba29_1772698001', '2026-03-05 16:06:42', '2026-03-09 08:39:19', '2026-03-09 08:38:39', NULL, 13.88229686, 121.03629454, 0, 0, 0.00, 13, '2026-03-05 08:06:42', '2026-03-09 00:39:19'),
(20, 8, '93f48b65179b74871f34867b69de4a7a_1772759479', '2026-03-06 09:11:18', '2026-03-06 09:15:43', '2026-03-06 09:14:33', NULL, 13.88236550, 121.03632350, 0, 0, 0.00, 14, '2026-03-06 01:11:18', '2026-03-06 01:15:43'),
(21, 8, '93f48b65179b74871f34867b69de4a7a_1772759744', '2026-03-06 09:15:43', '2026-03-06 09:51:33', '2026-03-06 09:49:59', NULL, 13.88236550, 121.03632350, 0, 0, 0.00, 14, '2026-03-06 01:15:43', '2026-03-06 01:51:33'),
(22, 8, 'ff3ac87f1f5d332cd41f95c022fccda5_1772761894', '2026-03-06 09:51:34', '2026-03-09 09:12:16', '2026-03-09 09:12:11', NULL, 13.88229686, 121.03629454, 0, 0, 0.00, 14, '2026-03-06 01:51:34', '2026-03-09 01:12:16'),
(23, 10, '5c0f23c57148b0ca4a2ee4d939f43150_1772765658', '2026-03-06 10:54:18', '2026-03-06 10:54:19', NULL, NULL, NULL, NULL, 0, 0, 0.00, NULL, '2026-03-06 02:54:18', '2026-03-06 02:54:19'),
(24, 10, '5c0f23c57148b0ca4a2ee4d939f43150_1772765659', '2026-03-06 10:54:19', '2026-03-06 10:54:38', '2026-03-06 10:54:24', NULL, 13.88221217, 121.03641635, 0, 0, 0.00, NULL, '2026-03-06 02:54:19', '2026-03-06 02:54:38'),
(25, 8, '554c988dc7fd63e4efddcd4d89d74811_1773019770', '2026-03-09 09:29:30', '2026-03-09 09:29:37', '2026-03-09 09:29:33', NULL, 13.88236550, 121.03632350, 0, 0, 0.00, 16, '2026-03-09 01:29:30', '2026-03-09 01:29:37'),
(26, 8, '554c988dc7fd63e4efddcd4d89d74811_1773019778', '2026-03-09 09:29:38', NULL, '2026-03-24 14:38:57', NULL, 13.88236123, 121.03631983, 1, 1, 0.00, 16, '2026-03-09 01:29:38', '2026-03-24 06:38:57'),
(27, 1, '8ebfacf12cf280cc96dc1b89fcb20392_1773033556', '2026-03-09 13:19:16', '2026-03-09 15:26:09', '2026-03-09 15:25:12', NULL, 13.88236550, 121.03632350, 0, 0, 0.00, 13, '2026-03-09 05:19:16', '2026-03-09 07:26:09'),
(28, 1, 'e5760310712e49cf63ef6674270d4e46_1773041632', '2026-03-09 15:33:52', '2026-04-10 08:33:42', '2026-04-07 11:13:17', NULL, 13.88213981, 121.03624026, 0, 0, 0.00, 13, '2026-03-09 07:33:52', '2026-04-10 00:33:42'),
(29, 3, '827cdcae8fbbd96fa10a266450dfb278_1773043071', '2026-03-09 15:57:51', NULL, '2026-03-09 15:59:00', NULL, 13.88243410, 121.03614985, 1, 1, 0.00, NULL, '2026-03-09 07:57:51', '2026-03-09 07:59:00'),
(30, 10, 'bdab8d5ee5ce999fbf27cfa03a286262_1774582934', '2026-03-27 11:42:14', NULL, '2026-03-27 11:42:48', NULL, 13.88227332, 121.03615209, 1, 1, 0.00, NULL, '2026-03-27 03:42:14', '2026-03-27 03:42:48'),
(31, 1, 'nta6m7d94be0j9ned3nirebfup_1775781222', '2026-04-10 08:33:42', '2026-04-10 08:34:00', NULL, NULL, NULL, NULL, 0, 0, 0.00, 25, '2026-04-10 00:33:42', '2026-04-10 00:34:00'),
(32, 1, 'nta6m7d94be0j9ned3nirebfup_1775781240', '2026-04-10 08:34:00', '2026-04-10 08:34:39', NULL, NULL, NULL, NULL, 0, 0, 0.00, 25, '2026-04-10 00:34:00', '2026-04-10 00:34:39'),
(33, 1, 'nta6m7d94be0j9ned3nirebfup_1775781279', '2026-04-10 08:34:39', NULL, '2026-04-15 11:11:14', NULL, 13.88219238, 121.03628757, 1, 1, 0.00, 25, '2026-04-10 00:34:39', '2026-04-15 03:11:14'),
(34, 11, 'b12ui4vlgp31kgt8pj5tqa2rk7_1776143503', '2026-04-14 13:11:43', NULL, '2026-04-14 21:16:11', NULL, 13.86366085, 120.99466755, 1, 1, 0.00, 28, '2026-04-14 05:11:43', '2026-04-14 13:16:11');

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

--
-- Dumping data for table `driver_tracking`
--

INSERT INTO `driver_tracking` (`tracking_id`, `driver_id`, `trip_id`, `latitude`, `longitude`, `location_timestamp`, `speed_kmh`, `created_at`) VALUES
(1, 1, 13, 13.88236550, 121.03632350, '2026-03-09 15:33:55', 0.00, '2026-03-09 07:33:55'),
(2, 1, 13, 13.88236550, 121.03632350, '2026-03-09 15:34:05', 0.00, '2026-03-09 07:34:05'),
(3, 1, 13, 13.88236550, 121.03632350, '2026-03-09 15:35:09', 0.00, '2026-03-09 07:35:09'),
(4, 1, 13, 13.88236550, 121.03632350, '2026-03-09 15:36:08', 0.00, '2026-03-09 07:36:08'),
(5, 1, 13, 13.88236550, 121.03632350, '2026-03-09 15:52:11', 0.00, '2026-03-09 07:52:11'),
(6, 1, 13, 13.88236550, 121.03632350, '2026-03-09 15:53:14', 0.00, '2026-03-09 07:53:14'),
(7, 1, 13, 13.88243410, 121.03614985, '2026-03-09 15:54:16', 0.00, '2026-03-09 07:54:16'),
(8, 1, 13, 13.88243410, 121.03614985, '2026-03-09 15:55:12', 0.00, '2026-03-09 07:55:12'),
(9, 1, 13, 13.88243410, 121.03614985, '2026-03-09 15:56:14', 0.00, '2026-03-09 07:56:14'),
(10, 1, 13, 13.88243410, 121.03614985, '2026-03-09 15:57:15', 0.00, '2026-03-09 07:57:15'),
(11, 3, NULL, 13.88243410, 121.03614985, '2026-03-09 15:57:55', 0.00, '2026-03-09 07:57:55'),
(12, 1, 13, 13.88243410, 121.03614985, '2026-03-09 15:58:12', 0.00, '2026-03-09 07:58:12'),
(13, 3, NULL, 13.88243410, 121.03614985, '2026-03-09 15:59:00', 0.00, '2026-03-09 07:59:00'),
(14, 1, 13, 13.88236550, 121.03632350, '2026-03-10 07:43:55', 0.00, '2026-03-09 23:43:55'),
(15, 1, 13, 13.88243410, 121.03614985, '2026-03-10 07:44:57', 0.00, '2026-03-09 23:44:57'),
(16, 1, 13, 13.88243410, 121.03614985, '2026-03-10 07:45:59', 0.00, '2026-03-09 23:45:59'),
(17, 1, 13, 13.88243410, 121.03614985, '2026-03-10 07:46:58', 0.00, '2026-03-09 23:46:58'),
(18, 1, 13, 13.88243410, 121.03614985, '2026-03-10 07:47:57', 0.00, '2026-03-09 23:47:57'),
(19, 1, 13, 13.88236550, 121.03632350, '2026-03-10 07:49:00', 0.00, '2026-03-09 23:49:00'),
(20, 1, 13, 13.88236550, 121.03632350, '2026-03-10 07:49:56', 0.00, '2026-03-09 23:49:56'),
(21, 1, 13, 13.88236550, 121.03632350, '2026-03-10 07:51:00', 0.00, '2026-03-09 23:51:00'),
(22, 1, 13, 13.88236550, 121.03632350, '2026-03-10 07:51:59', 0.00, '2026-03-09 23:51:59'),
(23, 1, 13, 13.88236550, 121.03632350, '2026-03-10 07:53:02', 0.00, '2026-03-09 23:53:02'),
(24, 1, 13, 13.88243410, 121.03614985, '2026-03-10 13:24:59', 0.00, '2026-03-10 05:24:59'),
(25, 1, 13, 13.88243410, 121.03614985, '2026-03-10 13:26:03', 0.00, '2026-03-10 05:26:03'),
(26, 1, 13, 13.88243410, 121.03614985, '2026-03-10 13:26:55', 0.00, '2026-03-10 05:26:55'),
(27, 1, 13, 13.88243410, 121.03614985, '2026-03-10 13:37:26', 0.00, '2026-03-10 05:37:26'),
(28, 1, 13, 13.88243410, 121.03614985, '2026-03-10 13:47:29', 0.00, '2026-03-10 05:47:29'),
(29, 1, 13, 13.88235435, 121.03622107, '2026-03-10 13:54:12', 0.00, '2026-03-10 05:54:12'),
(30, 1, 13, 13.88235435, 121.03622107, '2026-03-10 13:55:19', 0.00, '2026-03-10 05:55:19'),
(31, 1, 13, 13.88235435, 121.03622107, '2026-03-10 13:56:14', 0.00, '2026-03-10 05:56:14'),
(32, 1, 13, 13.88225341, 121.03640248, '2026-03-10 14:55:19', 0.00, '2026-03-10 06:55:19'),
(33, 1, 13, 13.88224548, 121.03640462, '2026-03-10 14:58:21', 0.00, '2026-03-10 06:58:21'),
(34, 1, 13, 13.88235436, 121.03622106, '2026-03-10 15:01:33', 0.00, '2026-03-10 07:01:33'),
(35, 1, 13, 13.88235524, 121.03622241, '2026-03-10 15:02:35', 0.00, '2026-03-10 07:02:35'),
(36, 1, 13, 13.88231002, 121.03626673, '2026-03-10 15:03:35', 0.25, '2026-03-10 07:03:35'),
(37, 1, 13, 13.88225341, 121.03640248, '2026-03-10 15:16:42', 0.00, '2026-03-10 07:16:42'),
(38, 1, 13, 13.88223519, 121.03639727, '2026-03-10 15:23:41', 0.00, '2026-03-10 07:23:41'),
(39, 1, 13, 13.88224548, 121.03640462, '2026-03-10 15:30:38', 0.00, '2026-03-10 07:30:38'),
(40, 1, 13, 13.88225114, 121.03640083, '2026-03-10 15:34:30', 0.00, '2026-03-10 07:34:30'),
(41, 1, 13, 13.88223358, 121.03640237, '2026-03-10 15:37:28', 0.00, '2026-03-10 07:37:28'),
(42, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:16:35', 0.00, '2026-03-16 01:16:35'),
(43, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:16:35', 0.00, '2026-03-16 01:16:35'),
(44, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:16:35', 0.00, '2026-03-16 01:16:35'),
(45, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:16:35', 0.00, '2026-03-16 01:16:35'),
(46, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:22', 0.00, '2026-03-16 01:17:22'),
(47, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:41', 0.00, '2026-03-16 01:17:41'),
(48, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:41', 0.00, '2026-03-16 01:17:41'),
(49, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:41', 0.00, '2026-03-16 01:17:41'),
(50, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:42', 0.00, '2026-03-16 01:17:42'),
(51, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:44', 0.00, '2026-03-16 01:17:44'),
(52, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:44', 0.00, '2026-03-16 01:17:44'),
(53, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:44', 0.00, '2026-03-16 01:17:44'),
(54, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:44', 0.00, '2026-03-16 01:17:44'),
(55, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:49', 0.00, '2026-03-16 01:17:49'),
(56, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:49', 0.00, '2026-03-16 01:17:49'),
(57, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:49', 0.00, '2026-03-16 01:17:49'),
(58, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:17:49', 0.00, '2026-03-16 01:17:49'),
(59, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:07', 0.00, '2026-03-16 01:18:07'),
(60, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:07', 0.00, '2026-03-16 01:18:07'),
(61, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:07', 0.00, '2026-03-16 01:18:07'),
(62, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:07', 0.00, '2026-03-16 01:18:07'),
(63, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:10', 0.00, '2026-03-16 01:18:10'),
(64, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:10', 0.00, '2026-03-16 01:18:10'),
(65, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:10', 0.00, '2026-03-16 01:18:10'),
(66, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:10', 0.00, '2026-03-16 01:18:10'),
(67, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:21', 0.00, '2026-03-16 01:18:21'),
(68, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:22', 0.00, '2026-03-16 01:18:22'),
(69, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:22', 0.00, '2026-03-16 01:18:22'),
(70, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:23', 0.00, '2026-03-16 01:18:23'),
(71, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:23', 0.00, '2026-03-16 01:18:23'),
(72, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:28', 0.00, '2026-03-16 01:18:28'),
(73, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:28', 0.00, '2026-03-16 01:18:28'),
(74, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:18:28', 0.00, '2026-03-16 01:18:28'),
(75, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:18:28', 0.00, '2026-03-16 01:18:28'),
(76, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:19:39', 0.00, '2026-03-16 01:19:39'),
(77, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:20:42', 0.00, '2026-03-16 01:20:42'),
(78, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:21:45', 0.00, '2026-03-16 01:21:45'),
(79, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:22:43', 0.00, '2026-03-16 01:22:43'),
(80, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:23:39', 0.00, '2026-03-16 01:23:39'),
(81, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:24:45', 0.00, '2026-03-16 01:24:45'),
(82, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:25:39', 0.00, '2026-03-16 01:25:39'),
(83, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:26:42', 0.00, '2026-03-16 01:26:42'),
(84, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:27:40', 0.00, '2026-03-16 01:27:40'),
(85, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:28:46', 0.00, '2026-03-16 01:28:46'),
(86, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:29:46', 0.00, '2026-03-16 01:29:46'),
(87, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:30:48', 0.00, '2026-03-16 01:30:48'),
(88, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:31:49', 0.00, '2026-03-16 01:31:49'),
(89, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:32:49', 0.00, '2026-03-16 01:32:49'),
(90, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:33:50', 0.00, '2026-03-16 01:33:50'),
(91, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:34:51', 0.00, '2026-03-16 01:34:51'),
(92, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:35:52', 0.00, '2026-03-16 01:35:52'),
(93, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:36:55', 0.00, '2026-03-16 01:36:55'),
(94, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:37:50', 0.00, '2026-03-16 01:37:50'),
(95, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:38:57', 0.00, '2026-03-16 01:38:57'),
(96, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:39:56', 0.00, '2026-03-16 01:39:56'),
(97, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:40:57', 0.00, '2026-03-16 01:40:57'),
(98, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:41:58', 0.00, '2026-03-16 01:41:58'),
(99, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:43:01', 0.00, '2026-03-16 01:43:01'),
(100, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:44:00', 0.00, '2026-03-16 01:44:00'),
(101, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:45:01', 0.00, '2026-03-16 01:45:01'),
(102, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:46:02', 0.00, '2026-03-16 01:46:02'),
(103, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:47:04', 0.00, '2026-03-16 01:47:04'),
(104, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:48:05', 0.00, '2026-03-16 01:48:05'),
(105, 1, 13, 13.88243410, 121.03614985, '2026-03-16 09:49:05', 0.00, '2026-03-16 01:49:05'),
(106, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:50:06', 0.00, '2026-03-16 01:50:06'),
(107, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:51:09', 0.00, '2026-03-16 01:51:09'),
(108, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:52:09', 0.00, '2026-03-16 01:52:09'),
(109, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:53:11', 0.00, '2026-03-16 01:53:11'),
(110, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:54:10', 0.00, '2026-03-16 01:54:10'),
(111, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:55:13', 0.00, '2026-03-16 01:55:13'),
(112, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:56:12', 0.00, '2026-03-16 01:56:12'),
(113, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:57:13', 0.00, '2026-03-16 01:57:13'),
(114, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:58:14', 0.00, '2026-03-16 01:58:14'),
(115, 1, 13, 13.88236550, 121.03632350, '2026-03-16 09:59:16', 0.00, '2026-03-16 01:59:16'),
(116, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:00:17', 0.00, '2026-03-16 02:00:17'),
(117, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:01:15', 0.00, '2026-03-16 02:01:15'),
(118, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:02:16', 0.00, '2026-03-16 02:02:16'),
(119, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:03:15', 0.00, '2026-03-16 02:03:15'),
(120, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:04:16', 0.00, '2026-03-16 02:04:16'),
(121, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:05:17', 0.00, '2026-03-16 02:05:17'),
(122, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:06:16', 0.00, '2026-03-16 02:06:16'),
(123, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:07:17', 0.00, '2026-03-16 02:07:17'),
(124, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:08:16', 0.00, '2026-03-16 02:08:16'),
(125, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:09:15', 0.00, '2026-03-16 02:09:15'),
(126, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:10:17', 0.00, '2026-03-16 02:10:17'),
(127, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:11:11', 0.00, '2026-03-16 02:11:11'),
(128, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:12:15', 0.00, '2026-03-16 02:12:15'),
(129, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:13:16', 0.00, '2026-03-16 02:13:16'),
(130, 1, 13, 13.88231758, 121.03623477, '2026-03-16 10:14:16', 0.00, '2026-03-16 02:14:16'),
(131, 1, 13, 13.88231758, 121.03623477, '2026-03-16 10:15:15', 0.00, '2026-03-16 02:15:15'),
(132, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:16:15', 0.00, '2026-03-16 02:16:15'),
(133, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:17:16', 0.00, '2026-03-16 02:17:16'),
(134, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:18:15', 0.00, '2026-03-16 02:18:15'),
(135, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:19:17', 0.00, '2026-03-16 02:19:17'),
(136, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:20:15', 0.00, '2026-03-16 02:20:15'),
(137, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:21:16', 0.00, '2026-03-16 02:21:16'),
(138, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:22:15', 0.00, '2026-03-16 02:22:15'),
(139, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:23:15', 0.00, '2026-03-16 02:23:15'),
(140, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:24:11', 0.00, '2026-03-16 02:24:11'),
(141, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:25:16', 0.00, '2026-03-16 02:25:16'),
(142, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:26:14', 0.00, '2026-03-16 02:26:14'),
(143, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:27:16', 0.00, '2026-03-16 02:27:16'),
(144, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:28:15', 0.00, '2026-03-16 02:28:15'),
(145, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:29:16', 0.00, '2026-03-16 02:29:16'),
(146, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:30:16', 0.00, '2026-03-16 02:30:16'),
(147, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:31:16', 0.00, '2026-03-16 02:31:16'),
(148, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:32:16', 0.00, '2026-03-16 02:32:16'),
(149, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:33:15', 0.00, '2026-03-16 02:33:15'),
(150, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:34:16', 0.00, '2026-03-16 02:34:16'),
(151, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:35:16', 0.00, '2026-03-16 02:35:16'),
(152, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:36:43', 0.00, '2026-03-16 02:36:43'),
(153, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:37:45', 0.00, '2026-03-16 02:37:45'),
(154, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:38:43', 0.00, '2026-03-16 02:38:43'),
(155, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:39:46', 0.00, '2026-03-16 02:39:46'),
(156, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:40:45', 0.00, '2026-03-16 02:40:45'),
(157, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:41:46', 0.00, '2026-03-16 02:41:46'),
(158, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:42:43', 0.00, '2026-03-16 02:42:43'),
(159, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:43:45', 0.00, '2026-03-16 02:43:45'),
(160, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:44:51', 0.00, '2026-03-16 02:44:51'),
(161, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:45:50', 0.00, '2026-03-16 02:45:50'),
(162, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:46:48', 0.00, '2026-03-16 02:46:48'),
(163, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:47:49', 0.00, '2026-03-16 02:47:49'),
(164, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:48:50', 0.00, '2026-03-16 02:48:50'),
(165, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:49:51', 0.00, '2026-03-16 02:49:51'),
(166, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:50:56', 0.00, '2026-03-16 02:50:56'),
(167, 1, 13, 13.88243410, 121.03614985, '2026-03-16 10:51:57', 0.00, '2026-03-16 02:51:57'),
(168, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:52:58', 0.00, '2026-03-16 02:52:58'),
(169, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:53:54', 0.00, '2026-03-16 02:53:54'),
(170, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:54:56', 0.00, '2026-03-16 02:54:56'),
(171, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:56:01', 0.00, '2026-03-16 02:56:01'),
(172, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:57:03', 0.00, '2026-03-16 02:57:03'),
(173, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:58:03', 0.00, '2026-03-16 02:58:03'),
(174, 1, 13, 13.88236550, 121.03632350, '2026-03-16 10:58:59', 0.00, '2026-03-16 02:58:59'),
(175, 1, 13, 13.88236550, 121.03632350, '2026-03-16 11:00:01', 0.00, '2026-03-16 03:00:01'),
(176, 1, 13, 13.88236550, 121.03632350, '2026-03-16 11:01:02', 0.00, '2026-03-16 03:01:02'),
(177, 1, 13, 13.88236550, 121.03632350, '2026-03-16 11:02:03', 0.00, '2026-03-16 03:02:03'),
(178, 1, 13, 13.88236550, 121.03632350, '2026-03-16 11:03:07', 0.00, '2026-03-16 03:03:07'),
(179, 1, 13, 13.88243410, 121.03614985, '2026-03-16 11:04:04', 0.00, '2026-03-16 03:04:04'),
(180, 1, 13, 13.88243410, 121.03614985, '2026-03-16 11:05:10', 0.00, '2026-03-16 03:05:10'),
(181, 1, 13, 13.88236550, 121.03632350, '2026-03-16 11:06:11', 0.00, '2026-03-16 03:06:11'),
(182, 1, 13, 13.88236550, 121.03632350, '2026-03-16 11:07:12', 0.00, '2026-03-16 03:07:12'),
(183, 1, 13, 13.88243410, 121.03614985, '2026-03-16 11:08:13', 0.00, '2026-03-16 03:08:13'),
(184, 1, 13, 13.88243410, 121.03614985, '2026-03-16 11:09:14', 0.00, '2026-03-16 03:09:14'),
(185, 1, 13, 13.88243410, 121.03614985, '2026-03-16 11:09:45', 0.00, '2026-03-16 03:09:45'),
(186, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:44:32', 0.00, '2026-03-19 05:44:32'),
(187, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:44:32', 0.00, '2026-03-19 05:44:32'),
(188, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:44:32', 0.00, '2026-03-19 05:44:32'),
(189, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:44:33', 0.00, '2026-03-19 05:44:33'),
(190, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:44:34', 0.00, '2026-03-19 05:44:34'),
(191, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:44:34', 0.00, '2026-03-19 05:44:34'),
(192, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:44:34', 0.00, '2026-03-19 05:44:34'),
(193, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:44:34', 0.00, '2026-03-19 05:44:34'),
(194, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:45:33', 0.00, '2026-03-19 05:45:33'),
(195, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:45:33', 0.00, '2026-03-19 05:45:33'),
(196, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:45:33', 0.00, '2026-03-19 05:45:33'),
(197, 1, 13, 13.88236550, 121.03632350, '2026-03-19 13:45:33', 0.00, '2026-03-19 05:45:33'),
(198, 1, 13, 13.88243410, 121.03614985, '2026-03-19 13:45:49', 0.00, '2026-03-19 05:45:49'),
(199, 1, 13, 13.88243410, 121.03614985, '2026-03-19 13:45:49', 0.00, '2026-03-19 05:45:49'),
(200, 1, 13, 13.88243410, 121.03614985, '2026-03-19 13:45:49', 0.00, '2026-03-19 05:45:49'),
(201, 1, 13, 13.88243410, 121.03614985, '2026-03-19 13:45:54', 0.00, '2026-03-19 05:45:54'),
(202, 1, 13, 13.86359891, 120.99468923, '2026-03-20 19:29:09', 0.00, '2026-03-20 11:29:09'),
(203, 1, 13, 13.86359891, 120.99468923, '2026-03-20 19:29:09', 0.00, '2026-03-20 11:29:09'),
(204, 1, 13, 13.86359891, 120.99468923, '2026-03-20 19:29:10', 0.00, '2026-03-20 11:29:10'),
(205, 1, 13, 13.86359891, 120.99468923, '2026-03-20 19:29:10', 0.00, '2026-03-20 11:29:10'),
(206, 1, 13, 13.86359891, 120.99468923, '2026-03-20 19:29:42', 0.00, '2026-03-20 11:29:42'),
(207, 1, 13, 13.86359891, 120.99468923, '2026-03-20 19:29:42', 0.00, '2026-03-20 11:29:42'),
(208, 1, 13, 13.86359891, 120.99468923, '2026-03-20 19:29:42', 0.00, '2026-03-20 11:29:42'),
(209, 1, 13, 13.86360218, 120.99469155, '2026-03-20 19:29:45', 0.00, '2026-03-20 11:29:45'),
(210, 1, 13, 13.86361520, 120.99468382, '2026-03-20 19:31:29', 0.00, '2026-03-20 11:31:29'),
(211, 1, 13, 13.88236550, 121.03632350, '2026-03-23 11:17:14', 0.00, '2026-03-23 03:17:14'),
(212, 1, 13, 13.88236550, 121.03632350, '2026-03-23 11:17:14', 0.00, '2026-03-23 03:17:14'),
(213, 1, 13, 13.88236550, 121.03632350, '2026-03-23 11:17:14', 0.00, '2026-03-23 03:17:14'),
(214, 1, 13, 13.88236550, 121.03632350, '2026-03-23 11:17:14', 0.00, '2026-03-23 03:17:14'),
(215, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:09', 0.00, '2026-03-23 03:19:09'),
(216, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:09', 0.00, '2026-03-23 03:19:09'),
(217, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:19:09', 0.00, '2026-03-23 03:19:09'),
(218, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:19:09', 0.00, '2026-03-23 03:19:09'),
(219, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:11', 0.00, '2026-03-23 03:19:11'),
(220, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:14', 0.00, '2026-03-23 03:19:14'),
(221, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:15', 0.00, '2026-03-23 03:19:15'),
(222, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:20', 0.00, '2026-03-23 03:19:20'),
(223, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:21', 0.00, '2026-03-23 03:19:21'),
(224, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:26', 0.00, '2026-03-23 03:19:26'),
(225, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:31', 0.00, '2026-03-23 03:19:31'),
(226, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:36', 0.00, '2026-03-23 03:19:36'),
(227, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:37', 0.00, '2026-03-23 03:19:37'),
(228, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:50', 0.00, '2026-03-23 03:19:50'),
(229, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:50', 0.00, '2026-03-23 03:19:50'),
(230, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:50', 0.00, '2026-03-23 03:19:50'),
(231, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:19:50', 0.00, '2026-03-23 03:19:50'),
(232, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:50', 0.00, '2026-03-23 03:19:50'),
(233, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:19:56', 0.00, '2026-03-23 03:19:56'),
(234, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:20:11', 0.00, '2026-03-23 03:20:11'),
(235, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:20:26', 0.00, '2026-03-23 03:20:26'),
(236, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:20:41', 0.00, '2026-03-23 03:20:41'),
(237, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:20:56', 0.00, '2026-03-23 03:20:56'),
(238, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:21:11', 0.00, '2026-03-23 03:21:11'),
(239, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:21:26', 0.00, '2026-03-23 03:21:26'),
(240, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:21:41', 0.00, '2026-03-23 03:21:41'),
(241, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:21:56', 0.00, '2026-03-23 03:21:56'),
(242, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:22:11', 0.00, '2026-03-23 03:22:11'),
(243, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:22:26', 0.00, '2026-03-23 03:22:26'),
(244, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:22:41', 0.00, '2026-03-23 03:22:41'),
(245, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:22:56', 0.00, '2026-03-23 03:22:56'),
(246, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:23:11', 0.00, '2026-03-23 03:23:11'),
(247, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:23:26', 0.00, '2026-03-23 03:23:26'),
(248, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:23:40', 0.00, '2026-03-23 03:23:40'),
(249, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:23:45', 0.00, '2026-03-23 03:23:45'),
(250, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:23:51', 0.00, '2026-03-23 03:23:51'),
(251, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:23:57', 0.00, '2026-03-23 03:23:57'),
(252, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:04', 0.00, '2026-03-23 03:24:04'),
(253, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:09', 0.00, '2026-03-23 03:24:09'),
(254, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:15', 0.00, '2026-03-23 03:24:15'),
(255, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:21', 0.00, '2026-03-23 03:24:21'),
(256, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:51', 0.00, '2026-03-23 03:24:51'),
(257, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:51', 0.00, '2026-03-23 03:24:51'),
(258, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:51', 0.00, '2026-03-23 03:24:51'),
(259, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:52', 0.00, '2026-03-23 03:24:52'),
(260, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:52', 0.00, '2026-03-23 03:24:52'),
(261, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:57', 0.00, '2026-03-23 03:24:57'),
(262, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:57', 0.00, '2026-03-23 03:24:57'),
(263, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:24:58', 0.00, '2026-03-23 03:24:58'),
(264, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:02', 0.00, '2026-03-23 03:25:02'),
(265, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:03', 0.00, '2026-03-23 03:25:03'),
(266, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:09', 0.00, '2026-03-23 03:25:09'),
(267, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:09', 0.00, '2026-03-23 03:25:09'),
(268, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:15', 0.00, '2026-03-23 03:25:15'),
(269, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:15', 0.00, '2026-03-23 03:25:15'),
(270, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:21', 0.00, '2026-03-23 03:25:21'),
(271, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:21', 0.00, '2026-03-23 03:25:21'),
(272, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:27', 0.00, '2026-03-23 03:25:27'),
(273, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:28', 0.00, '2026-03-23 03:25:28'),
(274, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:33', 0.00, '2026-03-23 03:25:33'),
(275, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:34', 0.00, '2026-03-23 03:25:34'),
(276, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:39', 0.00, '2026-03-23 03:25:39'),
(277, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:40', 0.00, '2026-03-23 03:25:40'),
(278, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:45', 0.00, '2026-03-23 03:25:45'),
(279, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:46', 0.00, '2026-03-23 03:25:46'),
(280, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:51', 0.00, '2026-03-23 03:25:51'),
(281, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:52', 0.00, '2026-03-23 03:25:52'),
(282, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:57', 0.00, '2026-03-23 03:25:57'),
(283, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:25:58', 0.00, '2026-03-23 03:25:58'),
(284, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:03', 0.00, '2026-03-23 03:26:03'),
(285, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:04', 0.00, '2026-03-23 03:26:04'),
(286, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:09', 0.00, '2026-03-23 03:26:09'),
(287, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:11', 0.00, '2026-03-23 03:26:11'),
(288, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:17', 0.00, '2026-03-23 03:26:17'),
(289, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:17', 0.00, '2026-03-23 03:26:17'),
(290, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:37', 0.00, '2026-03-23 03:26:37'),
(291, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:37', 0.00, '2026-03-23 03:26:37'),
(292, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:37', 0.00, '2026-03-23 03:26:37'),
(293, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:37', 0.00, '2026-03-23 03:26:37'),
(294, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:37', 0.00, '2026-03-23 03:26:37'),
(295, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:43', 0.00, '2026-03-23 03:26:43'),
(296, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:54', 0.00, '2026-03-23 03:26:54'),
(297, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:54', 0.00, '2026-03-23 03:26:54'),
(298, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:54', 0.00, '2026-03-23 03:26:54'),
(299, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:54', 0.00, '2026-03-23 03:26:54'),
(300, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:26:54', 0.00, '2026-03-23 03:26:54'),
(301, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:27:00', 0.00, '2026-03-23 03:27:00'),
(302, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:27:07', 0.00, '2026-03-23 03:27:07'),
(303, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:27:13', 0.00, '2026-03-23 03:27:13'),
(304, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:29:02', 0.00, '2026-03-23 03:29:02'),
(305, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:29:02', 0.00, '2026-03-23 03:29:02'),
(306, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:29:02', 0.00, '2026-03-23 03:29:02'),
(307, 1, 13, 13.88239566, 121.03622301, '2026-03-23 11:29:02', 0.00, '2026-03-23 03:29:02'),
(308, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:29:02', 0.00, '2026-03-23 03:29:02'),
(309, 1, 13, 13.88239565, 121.03622301, '2026-03-23 11:29:02', 0.00, '2026-03-23 03:29:02'),
(310, 1, 13, 13.88239566, 121.03622301, '2026-03-23 11:50:35', 0.00, '2026-03-23 03:50:35'),
(311, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:50:35', 0.00, '2026-03-23 03:50:35'),
(312, 1, 13, 13.88239565, 121.03622302, '2026-03-23 11:50:35', 0.00, '2026-03-23 03:50:35'),
(313, 1, 13, 13.88239566, 121.03622301, '2026-03-23 11:50:35', 0.00, '2026-03-23 03:50:35'),
(314, 1, 13, 13.88236550, 121.03632350, '2026-03-23 14:53:08', 0.00, '2026-03-23 06:53:08'),
(315, 1, 13, 13.88236550, 121.03632350, '2026-03-23 14:53:08', 0.00, '2026-03-23 06:53:08'),
(316, 1, 13, 13.88236550, 121.03632350, '2026-03-23 14:53:08', 0.00, '2026-03-23 06:53:08'),
(317, 1, 13, 13.88236550, 121.03632350, '2026-03-23 14:53:08', 0.00, '2026-03-23 06:53:08'),
(318, 1, 13, 13.88895455, 120.97925400, '2026-03-23 17:27:30', 0.00, '2026-03-23 09:27:30'),
(319, 1, 13, 13.88900944, 120.97925960, '2026-03-23 17:27:30', 0.00, '2026-03-23 09:27:30'),
(320, 1, 13, 13.88900944, 120.97925960, '2026-03-23 17:27:31', 0.00, '2026-03-23 09:27:31'),
(321, 1, 13, 13.88895455, 120.97925400, '2026-03-23 17:27:31', 0.00, '2026-03-23 09:27:31'),
(322, 1, 13, 13.88895455, 120.97925400, '2026-03-23 17:27:31', 0.00, '2026-03-23 09:27:31'),
(323, 1, 13, 13.88895455, 120.97925400, '2026-03-23 17:27:31', 0.00, '2026-03-23 09:27:31'),
(324, 1, 13, 13.88900944, 120.97925960, '2026-03-23 17:27:31', 0.00, '2026-03-23 09:27:31'),
(325, 1, 13, 13.88897281, 120.97928905, '2026-03-23 17:27:39', 0.36, '2026-03-23 09:27:39'),
(326, 1, 13, 13.88897281, 120.97928905, '2026-03-23 17:27:39', 0.36, '2026-03-23 09:27:39'),
(327, 1, 13, 13.88897281, 120.97928905, '2026-03-23 17:27:39', 0.36, '2026-03-23 09:27:39'),
(328, 1, 13, 13.88897281, 120.97928905, '2026-03-23 17:27:39', 0.36, '2026-03-23 09:27:39'),
(329, 1, 13, 13.88897204, 120.97929507, '2026-03-23 17:27:39', 0.23, '2026-03-23 09:27:39'),
(330, 1, 13, 13.88896777, 120.97928558, '2026-03-23 17:27:39', 0.00, '2026-03-23 09:27:39'),
(331, 1, 13, 13.88896787, 120.97928557, '2026-03-23 17:27:39', 0.00, '2026-03-23 09:27:39'),
(332, 1, 13, 13.88896537, 120.97927959, '2026-03-23 17:27:45', 0.00, '2026-03-23 09:27:45'),
(333, 1, 13, 13.88896369, 120.97927559, '2026-03-23 17:27:51', 0.00, '2026-03-23 09:27:51'),
(334, 1, 13, 13.88896369, 120.97927559, '2026-03-23 17:27:53', 0.00, '2026-03-23 09:27:53'),
(335, 1, 13, 13.88896369, 120.97927559, '2026-03-23 17:27:53', 0.00, '2026-03-23 09:27:53'),
(336, 1, 13, 13.88896369, 120.97927559, '2026-03-23 17:27:53', 0.00, '2026-03-23 09:27:53'),
(337, 1, 13, 13.88896375, 120.97927558, '2026-03-23 17:27:53', 0.00, '2026-03-23 09:27:53'),
(338, 1, 13, 13.88236550, 121.03632350, '2026-03-24 08:33:38', 0.00, '2026-03-24 00:33:38'),
(339, 1, 13, 13.88236550, 121.03632350, '2026-03-24 08:33:38', 0.00, '2026-03-24 00:33:38'),
(340, 1, 13, 13.88236550, 121.03632350, '2026-03-24 08:33:38', 0.00, '2026-03-24 00:33:38'),
(341, 1, 13, 13.88236550, 121.03632350, '2026-03-24 08:33:38', 0.00, '2026-03-24 00:33:38'),
(342, 1, 13, 13.88236550, 121.03632350, '2026-03-24 08:34:52', 0.00, '2026-03-24 00:34:52'),
(343, 1, 13, 13.88236550, 121.03632350, '2026-03-24 08:34:52', 0.00, '2026-03-24 00:34:52'),
(344, 1, 13, 13.88236550, 121.03632350, '2026-03-24 08:34:52', 0.00, '2026-03-24 00:34:52'),
(345, 1, 13, 13.88236550, 121.03632350, '2026-03-24 08:34:52', 0.00, '2026-03-24 00:34:52'),
(346, 1, 13, 13.88236550, 121.03632350, '2026-03-24 09:23:42', 0.00, '2026-03-24 01:23:42'),
(347, 1, 13, 13.88236550, 121.03632350, '2026-03-24 09:23:42', 0.00, '2026-03-24 01:23:42'),
(348, 1, 13, 13.88236550, 121.03632350, '2026-03-24 09:23:42', 0.00, '2026-03-24 01:23:42'),
(349, 1, 13, 13.88236550, 121.03632350, '2026-03-24 09:23:42', 0.00, '2026-03-24 01:23:42'),
(350, 1, 13, 13.88239838, 121.03622190, '2026-03-24 09:24:28', 0.00, '2026-03-24 01:24:28'),
(351, 1, 13, 13.88239838, 121.03622190, '2026-03-24 09:24:28', 0.00, '2026-03-24 01:24:28'),
(352, 1, 13, 13.88239838, 121.03622190, '2026-03-24 09:24:28', 0.00, '2026-03-24 01:24:28'),
(353, 1, 13, 13.88239838, 121.03622190, '2026-03-24 09:24:28', 0.00, '2026-03-24 01:24:28'),
(354, 1, 13, 13.88239838, 121.03622190, '2026-03-24 09:24:28', 0.00, '2026-03-24 01:24:28'),
(355, 1, 13, 13.88239838, 121.03622189, '2026-03-24 09:24:28', 0.00, '2026-03-24 01:24:28'),
(356, 1, 13, 13.88213981, 121.03624026, '2026-03-24 10:51:50', 0.00, '2026-03-24 02:51:50'),
(357, 1, 13, 13.88213981, 121.03624026, '2026-03-24 10:51:50', 0.00, '2026-03-24 02:51:50'),
(358, 1, 13, 13.88213981, 121.03624026, '2026-03-24 10:51:50', 0.00, '2026-03-24 02:51:50'),
(359, 1, 13, 13.88213981, 121.03624026, '2026-03-24 10:51:50', 0.00, '2026-03-24 02:51:50'),
(360, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:17:35', 0.00, '2026-03-24 05:17:35'),
(361, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:17:35', 0.00, '2026-03-24 05:17:35'),
(362, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:17:35', 0.00, '2026-03-24 05:17:35'),
(363, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:17:36', 0.00, '2026-03-24 05:17:36'),
(364, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:17:46', 0.00, '2026-03-24 05:17:46'),
(365, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:17:46', 0.00, '2026-03-24 05:17:46'),
(366, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:17:46', 0.00, '2026-03-24 05:17:46'),
(367, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:17:48', 0.00, '2026-03-24 05:17:48'),
(368, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:18:25', 0.00, '2026-03-24 05:18:25'),
(369, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:18:25', 0.00, '2026-03-24 05:18:25'),
(370, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:18:25', 0.00, '2026-03-24 05:18:25'),
(371, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:18:26', 0.00, '2026-03-24 05:18:26'),
(372, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:18:29', 0.00, '2026-03-24 05:18:29'),
(373, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:18:29', 0.00, '2026-03-24 05:18:29'),
(374, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:18:29', 0.00, '2026-03-24 05:18:29'),
(375, 1, 13, 13.88213981, 121.03624026, '2026-03-24 13:18:29', 0.00, '2026-03-24 05:18:29'),
(376, 1, 13, 13.88215342, 121.03624403, '2026-03-24 13:19:39', 0.00, '2026-03-24 05:19:39'),
(377, 1, 13, 13.88236550, 121.03632350, '2026-03-24 13:25:28', 0.00, '2026-03-24 05:25:28'),
(378, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:27:02', 0.00, '2026-03-24 05:27:02'),
(379, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:27:02', 0.00, '2026-03-24 05:27:02'),
(380, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:27:02', 0.00, '2026-03-24 05:27:02'),
(381, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:27:02', 0.00, '2026-03-24 05:27:02'),
(382, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:28:53', 0.00, '2026-03-24 05:28:53'),
(383, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:28:55', 0.00, '2026-03-24 05:28:55'),
(384, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:28:55', 0.00, '2026-03-24 05:28:55'),
(385, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:28:55', 0.00, '2026-03-24 05:28:55'),
(386, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:28:55', 0.00, '2026-03-24 05:28:55'),
(387, 1, 13, 13.88236550, 121.03632350, '2026-03-24 13:29:37', 0.00, '2026-03-24 05:29:37'),
(388, 1, 13, 13.88236550, 121.03632350, '2026-03-24 13:34:19', 0.00, '2026-03-24 05:34:19'),
(389, 1, 13, 13.88236550, 121.03632350, '2026-03-24 13:34:21', 0.00, '2026-03-24 05:34:21'),
(390, 1, 13, 13.88236550, 121.03632350, '2026-03-24 13:34:21', 0.00, '2026-03-24 05:34:21'),
(391, 1, 13, 13.88236550, 121.03632350, '2026-03-24 13:34:21', 0.00, '2026-03-24 05:34:21'),
(392, 1, 13, 13.88236550, 121.03632350, '2026-03-24 13:34:21', 0.00, '2026-03-24 05:34:21'),
(393, 1, 13, 13.88236550, 121.03632350, '2026-03-24 13:38:03', 0.00, '2026-03-24 05:38:03'),
(394, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:39:28', 0.00, '2026-03-24 05:39:28'),
(395, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:39:44', 0.00, '2026-03-24 05:39:44'),
(396, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:39:44', 0.00, '2026-03-24 05:39:44'),
(397, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:39:44', 0.00, '2026-03-24 05:39:44'),
(398, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:39:46', 0.00, '2026-03-24 05:39:46'),
(399, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:40:10', 0.00, '2026-03-24 05:40:10'),
(400, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:40:10', 0.00, '2026-03-24 05:40:10'),
(401, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:40:10', 0.00, '2026-03-24 05:40:10'),
(402, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:40:11', 0.00, '2026-03-24 05:40:11'),
(403, 1, 13, 13.88236123, 121.03631983, '2026-03-24 13:41:01', 0.00, '2026-03-24 05:41:01'),
(404, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:05:38', 0.00, '2026-03-24 06:05:38'),
(405, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:05:38', 0.00, '2026-03-24 06:05:38'),
(406, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:05:38', 0.00, '2026-03-24 06:05:38'),
(407, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:05:38', 0.00, '2026-03-24 06:05:38'),
(408, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:21:21', 0.00, '2026-03-24 06:21:21'),
(409, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:21:23', 0.00, '2026-03-24 06:21:23'),
(410, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:21:23', 0.00, '2026-03-24 06:21:23'),
(411, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:21:23', 0.00, '2026-03-24 06:21:23'),
(412, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:21:23', 0.00, '2026-03-24 06:21:23'),
(413, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:21:45', 0.00, '2026-03-24 06:21:45'),
(414, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:30:47', 0.00, '2026-03-24 06:30:47'),
(415, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:31:16', 0.00, '2026-03-24 06:31:16'),
(416, 1, 13, 13.88236550, 121.03632350, '2026-03-24 14:31:39', 0.00, '2026-03-24 06:31:39'),
(417, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:31:59', 0.00, '2026-03-24 06:31:59'),
(418, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:31:59', 0.00, '2026-03-24 06:31:59'),
(419, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:31:59', 0.00, '2026-03-24 06:31:59'),
(420, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:02', 0.00, '2026-03-24 06:32:02'),
(421, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:15', 0.00, '2026-03-24 06:32:15'),
(422, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:15', 0.00, '2026-03-24 06:32:15'),
(423, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:15', 0.00, '2026-03-24 06:32:15'),
(424, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:15', 0.00, '2026-03-24 06:32:15'),
(425, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:31', 0.00, '2026-03-24 06:32:31'),
(426, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:31', 0.00, '2026-03-24 06:32:31'),
(427, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:31', 0.00, '2026-03-24 06:32:31'),
(428, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:32:31', 0.00, '2026-03-24 06:32:31'),
(429, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:34:09', 0.00, '2026-03-24 06:34:09'),
(430, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:34:09', 0.00, '2026-03-24 06:34:09'),
(431, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:34:09', 0.00, '2026-03-24 06:34:09'),
(432, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:34:09', 0.00, '2026-03-24 06:34:09'),
(433, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:36:41', 0.00, '2026-03-24 06:36:41'),
(434, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:36:41', 0.00, '2026-03-24 06:36:41'),
(435, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:36:42', 0.00, '2026-03-24 06:36:42'),
(436, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:36:42', 0.00, '2026-03-24 06:36:42'),
(437, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:37:06', 0.00, '2026-03-24 06:37:06'),
(438, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:37:06', 0.00, '2026-03-24 06:37:06'),
(439, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:37:06', 0.00, '2026-03-24 06:37:06'),
(440, 8, 16, 13.88236550, 121.03632350, '2026-03-24 14:37:09', 0.00, '2026-03-24 06:37:09'),
(441, 8, 16, 13.88236123, 121.03631983, '2026-03-24 14:38:25', 0.00, '2026-03-24 06:38:25'),
(442, 8, 16, 13.88236123, 121.03631983, '2026-03-24 14:38:25', 0.00, '2026-03-24 06:38:25'),
(443, 8, 16, 13.88236123, 121.03631983, '2026-03-24 14:38:26', 0.00, '2026-03-24 06:38:26'),
(444, 8, 16, 13.88236123, 121.03631983, '2026-03-24 14:38:26', 0.00, '2026-03-24 06:38:26'),
(445, 8, 16, 13.88236123, 121.03631983, '2026-03-24 14:38:57', 0.00, '2026-03-24 06:38:57'),
(446, 8, 16, 13.88236123, 121.03631983, '2026-03-24 14:38:57', 0.00, '2026-03-24 06:38:57'),
(447, 8, 16, 13.88236123, 121.03631983, '2026-03-24 14:38:57', 0.00, '2026-03-24 06:38:57'),
(448, 8, 16, 13.88236123, 121.03631983, '2026-03-24 14:38:57', 0.00, '2026-03-24 06:38:57'),
(449, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:26:48', 0.00, '2026-03-24 07:26:48'),
(450, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:26:48', 0.00, '2026-03-24 07:26:48'),
(451, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:26:49', 0.00, '2026-03-24 07:26:49'),
(452, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:26:49', 0.00, '2026-03-24 07:26:49'),
(453, 1, 13, 13.88213981, 121.03624026, '2026-03-24 15:30:13', 0.00, '2026-03-24 07:30:13'),
(454, 1, 13, 13.88213981, 121.03624026, '2026-03-24 15:30:16', 0.00, '2026-03-24 07:30:16'),
(455, 1, 13, 13.88213981, 121.03624026, '2026-03-24 15:30:16', 0.00, '2026-03-24 07:30:16'),
(456, 1, 13, 13.88213981, 121.03624026, '2026-03-24 15:30:16', 0.00, '2026-03-24 07:30:16'),
(457, 1, 13, 13.88213981, 121.03624026, '2026-03-24 15:30:17', 0.00, '2026-03-24 07:30:17'),
(458, 1, 13, 13.88213981, 121.03624026, '2026-03-24 15:30:50', 0.00, '2026-03-24 07:30:50'),
(459, 1, 13, 13.88213981, 121.03624026, '2026-03-24 15:31:15', 0.00, '2026-03-24 07:31:15'),
(460, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:46:35', 0.00, '2026-03-24 07:46:35'),
(461, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:46:44', 0.00, '2026-03-24 07:46:44'),
(462, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:46:44', 0.00, '2026-03-24 07:46:44'),
(463, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:46:44', 0.00, '2026-03-24 07:46:44'),
(464, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:46:45', 0.00, '2026-03-24 07:46:45'),
(465, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:47:20', 0.00, '2026-03-24 07:47:20'),
(466, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:47:20', 0.00, '2026-03-24 07:47:20'),
(467, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:47:20', 0.00, '2026-03-24 07:47:20'),
(468, 1, 13, 13.88236550, 121.03632350, '2026-03-24 15:47:20', 0.00, '2026-03-24 07:47:20'),
(469, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:44:46', 0.00, '2026-03-24 23:44:46'),
(470, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:44:46', 0.00, '2026-03-24 23:44:46'),
(471, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:44:47', 0.00, '2026-03-24 23:44:47'),
(472, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:44:48', 0.00, '2026-03-24 23:44:48'),
(473, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:45:08', 0.00, '2026-03-24 23:45:08'),
(474, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:45:08', 0.00, '2026-03-24 23:45:08'),
(475, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:45:08', 0.00, '2026-03-24 23:45:08'),
(476, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:45:08', 0.00, '2026-03-24 23:45:08'),
(477, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:45:58', 0.00, '2026-03-24 23:45:58'),
(478, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:45:58', 0.00, '2026-03-24 23:45:58'),
(479, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:45:58', 0.00, '2026-03-24 23:45:58'),
(480, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:45:58', 0.00, '2026-03-24 23:45:58'),
(481, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:46:14', 0.00, '2026-03-24 23:46:14'),
(482, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:46:14', 0.00, '2026-03-24 23:46:14'),
(483, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:46:14', 0.00, '2026-03-24 23:46:14'),
(484, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:46:15', 0.00, '2026-03-24 23:46:15'),
(485, 1, 13, 13.88213981, 121.03624026, '2026-03-25 07:46:20', 0.00, '2026-03-24 23:46:20'),
(486, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:48:30', 0.00, '2026-03-24 23:48:30'),
(487, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:54:42', 0.00, '2026-03-24 23:54:42'),
(488, 1, 13, 13.88236550, 121.03632350, '2026-03-25 07:57:02', 0.00, '2026-03-24 23:57:02'),
(489, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:12:32', 0.00, '2026-03-25 00:12:32'),
(490, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:13:31', 0.00, '2026-03-25 00:13:31'),
(491, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:13:43', 0.00, '2026-03-25 00:13:43'),
(492, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:16:17', 0.00, '2026-03-25 00:16:17'),
(493, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:16:19', 0.00, '2026-03-25 00:16:19'),
(494, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:16:19', 0.00, '2026-03-25 00:16:19'),
(495, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:16:19', 0.00, '2026-03-25 00:16:19'),
(496, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:16:19', 0.00, '2026-03-25 00:16:19'),
(497, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:16:48', 0.00, '2026-03-25 00:16:48'),
(498, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:17:12', 0.00, '2026-03-25 00:17:12'),
(499, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:17:15', 0.00, '2026-03-25 00:17:15'),
(500, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:17:15', 0.00, '2026-03-25 00:17:15'),
(501, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:17:15', 0.00, '2026-03-25 00:17:15'),
(502, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:17:15', 0.00, '2026-03-25 00:17:15'),
(503, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:34:02', 0.00, '2026-03-25 00:34:02'),
(504, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:34:22', 0.00, '2026-03-25 00:34:22'),
(505, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:34:22', 0.00, '2026-03-25 00:34:22'),
(506, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:34:22', 0.00, '2026-03-25 00:34:22'),
(507, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:34:22', 0.00, '2026-03-25 00:34:22'),
(508, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:39:35', 0.00, '2026-03-25 00:39:35'),
(509, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:55:01', 0.00, '2026-03-25 00:55:01'),
(510, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:55:21', 0.00, '2026-03-25 00:55:21'),
(511, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:55:25', 0.00, '2026-03-25 00:55:25'),
(512, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:55:25', 0.00, '2026-03-25 00:55:25'),
(513, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:55:25', 0.00, '2026-03-25 00:55:25'),
(514, 1, 13, 13.88236550, 121.03632350, '2026-03-25 08:55:27', 0.00, '2026-03-25 00:55:27'),
(515, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:56:12', 0.00, '2026-03-25 00:56:12'),
(516, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:56:55', 0.00, '2026-03-25 00:56:55'),
(517, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:57:05', 0.00, '2026-03-25 00:57:05'),
(518, 1, 13, 13.88213981, 121.03624026, '2026-03-25 08:57:35', 0.00, '2026-03-25 00:57:35'),
(519, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:01:34', 0.00, '2026-03-25 01:01:34'),
(520, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:13:38', 0.00, '2026-03-25 01:13:38'),
(521, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:13:40', 0.00, '2026-03-25 01:13:40'),
(522, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:13:40', 0.00, '2026-03-25 01:13:40'),
(523, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:13:40', 0.00, '2026-03-25 01:13:40'),
(524, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:13:40', 0.00, '2026-03-25 01:13:40'),
(525, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:14:38', 0.00, '2026-03-25 01:14:38'),
(526, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:14:42', 0.00, '2026-03-25 01:14:42'),
(527, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:15:21', 0.00, '2026-03-25 01:15:21'),
(528, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:15:52', 0.00, '2026-03-25 01:15:52'),
(529, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:16:23', 0.00, '2026-03-25 01:16:23'),
(530, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:16:25', 0.00, '2026-03-25 01:16:25'),
(531, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:16:25', 0.00, '2026-03-25 01:16:25'),
(532, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:16:25', 0.00, '2026-03-25 01:16:25'),
(533, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:16:25', 0.00, '2026-03-25 01:16:25'),
(534, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:27:56', 0.00, '2026-03-25 01:27:56'),
(535, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:28:08', 0.00, '2026-03-25 01:28:08'),
(536, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:28:10', 0.00, '2026-03-25 01:28:10'),
(537, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:28:10', 0.00, '2026-03-25 01:28:10'),
(538, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:28:10', 0.00, '2026-03-25 01:28:10'),
(539, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:28:11', 0.00, '2026-03-25 01:28:11'),
(540, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:28:58', 0.00, '2026-03-25 01:28:58'),
(541, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:29:03', 0.00, '2026-03-25 01:29:03'),
(542, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:29:03', 0.00, '2026-03-25 01:29:03'),
(543, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:29:03', 0.00, '2026-03-25 01:29:03'),
(544, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:29:06', 0.00, '2026-03-25 01:29:06'),
(545, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:30:57', 0.00, '2026-03-25 01:30:57'),
(546, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:30:59', 0.00, '2026-03-25 01:30:59'),
(547, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:30:59', 0.00, '2026-03-25 01:30:59'),
(548, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:30:59', 0.00, '2026-03-25 01:30:59');
INSERT INTO `driver_tracking` (`tracking_id`, `driver_id`, `trip_id`, `latitude`, `longitude`, `location_timestamp`, `speed_kmh`, `created_at`) VALUES
(549, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:31:00', 0.00, '2026-03-25 01:31:00'),
(550, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:32:24', 0.00, '2026-03-25 01:32:24'),
(551, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:34:28', 0.00, '2026-03-25 01:34:28'),
(552, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:34:30', 0.00, '2026-03-25 01:34:30'),
(553, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:34:30', 0.00, '2026-03-25 01:34:30'),
(554, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:34:30', 0.00, '2026-03-25 01:34:30'),
(555, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:34:30', 0.00, '2026-03-25 01:34:30'),
(556, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:34:48', 0.00, '2026-03-25 01:34:48'),
(557, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:35:11', 0.00, '2026-03-25 01:35:11'),
(558, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:35:14', 0.00, '2026-03-25 01:35:14'),
(559, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:35:14', 0.00, '2026-03-25 01:35:14'),
(560, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:35:14', 0.00, '2026-03-25 01:35:14'),
(561, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:35:15', 0.00, '2026-03-25 01:35:15'),
(562, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:50:25', 0.00, '2026-03-25 01:50:25'),
(563, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:50:27', 0.00, '2026-03-25 01:50:27'),
(564, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:50:27', 0.00, '2026-03-25 01:50:27'),
(565, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:50:27', 0.00, '2026-03-25 01:50:27'),
(566, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:50:28', 0.00, '2026-03-25 01:50:28'),
(567, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:51:41', 0.00, '2026-03-25 01:51:41'),
(568, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:51:43', 0.00, '2026-03-25 01:51:43'),
(569, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:51:43', 0.00, '2026-03-25 01:51:43'),
(570, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:51:43', 0.00, '2026-03-25 01:51:43'),
(571, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:51:43', 0.00, '2026-03-25 01:51:43'),
(572, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:52:36', 0.00, '2026-03-25 01:52:36'),
(573, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:52:36', 0.00, '2026-03-25 01:52:36'),
(574, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:52:36', 0.00, '2026-03-25 01:52:36'),
(575, 1, 13, 13.88236550, 121.03632350, '2026-03-25 09:52:37', 0.00, '2026-03-25 01:52:37'),
(576, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:01:53', 0.00, '2026-03-25 02:01:53'),
(577, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:01:59', 0.00, '2026-03-25 02:01:59'),
(578, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:01:59', 0.00, '2026-03-25 02:01:59'),
(579, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:01:59', 0.00, '2026-03-25 02:01:59'),
(580, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:02:00', 0.00, '2026-03-25 02:02:00'),
(581, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:02:24', 0.00, '2026-03-25 02:02:24'),
(582, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:02:24', 0.00, '2026-03-25 02:02:24'),
(583, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:02:24', 0.00, '2026-03-25 02:02:24'),
(584, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:02:26', 0.00, '2026-03-25 02:02:26'),
(585, 1, 13, 13.88236123, 121.03631983, '2026-03-25 10:02:34', 0.00, '2026-03-25 02:02:34'),
(586, 1, 13, 13.88236123, 121.03631983, '2026-03-25 10:02:53', 0.00, '2026-03-25 02:02:53'),
(587, 1, 13, 13.88236123, 121.03631983, '2026-03-25 10:02:53', 0.00, '2026-03-25 02:02:53'),
(588, 1, 13, 13.88236123, 121.03631983, '2026-03-25 10:02:53', 0.00, '2026-03-25 02:02:53'),
(589, 1, 13, 13.88236123, 121.03631983, '2026-03-25 10:02:53', 0.00, '2026-03-25 02:02:53'),
(590, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:33:45', 0.00, '2026-03-25 02:33:45'),
(591, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:33:45', 0.00, '2026-03-25 02:33:45'),
(592, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:33:45', 0.00, '2026-03-25 02:33:45'),
(593, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:33:46', 0.00, '2026-03-25 02:33:46'),
(594, 1, 13, 13.88213981, 121.03624026, '2026-03-25 10:34:05', 0.00, '2026-03-25 02:34:05'),
(595, 1, 13, 13.88215342, 121.03624403, '2026-03-25 10:34:27', 0.00, '2026-03-25 02:34:27'),
(596, 1, 13, 13.88215342, 121.03624403, '2026-03-25 10:34:40', 0.00, '2026-03-25 02:34:40'),
(597, 1, 13, 13.88215342, 121.03624403, '2026-03-25 10:34:40', 0.00, '2026-03-25 02:34:40'),
(598, 1, 13, 13.88215342, 121.03624403, '2026-03-25 10:34:40', 0.00, '2026-03-25 02:34:40'),
(599, 1, 13, 13.88215342, 121.03624403, '2026-03-25 10:34:41', 0.00, '2026-03-25 02:34:41'),
(600, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:39:18', 0.00, '2026-03-25 02:39:18'),
(601, 1, 13, 13.88236550, 121.03632350, '2026-03-25 10:39:30', 0.00, '2026-03-25 02:39:30'),
(602, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:00:46', 0.00, '2026-03-25 03:00:46'),
(603, 1, 13, 13.88236123, 121.03631983, '2026-03-25 11:01:04', 0.00, '2026-03-25 03:01:04'),
(604, 1, 13, 13.88236123, 121.03631983, '2026-03-25 11:01:06', 0.00, '2026-03-25 03:01:06'),
(605, 1, 13, 13.88236123, 121.03631983, '2026-03-25 11:01:06', 0.00, '2026-03-25 03:01:06'),
(606, 1, 13, 13.88236123, 121.03631983, '2026-03-25 11:01:06', 0.00, '2026-03-25 03:01:06'),
(607, 1, 13, 13.88236123, 121.03631983, '2026-03-25 11:01:06', 0.00, '2026-03-25 03:01:06'),
(608, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:38:51', 0.00, '2026-03-25 03:38:51'),
(609, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:38:54', 0.00, '2026-03-25 03:38:54'),
(610, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:38:54', 0.00, '2026-03-25 03:38:54'),
(611, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:38:54', 0.00, '2026-03-25 03:38:54'),
(612, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:38:55', 0.00, '2026-03-25 03:38:55'),
(613, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:38:57', 0.00, '2026-03-25 03:38:57'),
(614, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:47:54', 0.00, '2026-03-25 03:47:54'),
(615, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:48:40', 0.00, '2026-03-25 03:48:40'),
(616, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:48:43', 0.00, '2026-03-25 03:48:43'),
(617, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:48:43', 0.00, '2026-03-25 03:48:43'),
(618, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:48:43', 0.00, '2026-03-25 03:48:43'),
(619, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:48:44', 0.00, '2026-03-25 03:48:44'),
(620, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:48:45', 0.00, '2026-03-25 03:48:45'),
(621, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:49:24', 0.00, '2026-03-25 03:49:24'),
(622, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:49:25', 0.00, '2026-03-25 03:49:25'),
(623, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:49:25', 0.00, '2026-03-25 03:49:25'),
(624, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:49:25', 0.00, '2026-03-25 03:49:25'),
(625, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:49:25', 0.00, '2026-03-25 03:49:25'),
(626, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:49:50', 0.00, '2026-03-25 03:49:50'),
(627, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:51:11', 0.00, '2026-03-25 03:51:11'),
(628, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:51:35', 0.00, '2026-03-25 03:51:35'),
(629, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:51:35', 0.00, '2026-03-25 03:51:35'),
(630, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:51:35', 0.00, '2026-03-25 03:51:35'),
(631, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:51:37', 0.00, '2026-03-25 03:51:37'),
(632, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:52:49', 0.00, '2026-03-25 03:52:49'),
(633, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:52:49', 0.00, '2026-03-25 03:52:49'),
(634, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:52:49', 0.00, '2026-03-25 03:52:49'),
(635, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:52:49', 0.00, '2026-03-25 03:52:49'),
(636, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:54:02', 0.00, '2026-03-25 03:54:02'),
(637, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:55:16', 0.00, '2026-03-25 03:55:16'),
(638, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:55:19', 0.00, '2026-03-25 03:55:19'),
(639, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:55:23', 0.00, '2026-03-25 03:55:23'),
(640, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:55:23', 0.00, '2026-03-25 03:55:23'),
(641, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:55:23', 0.00, '2026-03-25 03:55:23'),
(642, 1, 13, 13.88236550, 121.03632350, '2026-03-25 11:55:24', 0.00, '2026-03-25 03:55:24'),
(643, 1, 13, 13.88215342, 121.03624403, '2026-03-25 11:55:30', 0.00, '2026-03-25 03:55:30'),
(644, 1, 13, 13.88215342, 121.03624403, '2026-03-25 11:56:58', 0.00, '2026-03-25 03:56:58'),
(645, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:57:39', 0.00, '2026-03-25 03:57:39'),
(646, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:57:39', 0.00, '2026-03-25 03:57:39'),
(647, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:57:39', 0.00, '2026-03-25 03:57:39'),
(648, 1, 13, 13.88213981, 121.03624026, '2026-03-25 11:57:39', 0.00, '2026-03-25 03:57:39'),
(649, 1, 13, 13.88236550, 121.03632350, '2026-03-25 12:00:02', 0.00, '2026-03-25 04:00:02'),
(650, 1, 13, 13.88236550, 121.03632350, '2026-03-25 12:00:02', 0.00, '2026-03-25 04:00:02'),
(651, 1, 13, 13.88236550, 121.03632350, '2026-03-25 12:00:03', 0.00, '2026-03-25 04:00:03'),
(652, 1, 13, 13.88236550, 121.03632350, '2026-03-25 12:00:04', 0.00, '2026-03-25 04:00:04'),
(653, 1, 13, 13.88213981, 121.03624026, '2026-03-25 12:00:16', 0.00, '2026-03-25 04:00:16'),
(654, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:02:04', 0.00, '2026-03-25 05:02:04'),
(655, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:02:07', 0.00, '2026-03-25 05:02:07'),
(656, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:02:12', 0.00, '2026-03-25 05:02:12'),
(657, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:04:34', 0.00, '2026-03-25 05:04:34'),
(658, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:04:34', 0.00, '2026-03-25 05:04:34'),
(659, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:04:34', 0.00, '2026-03-25 05:04:34'),
(660, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:04:34', 0.00, '2026-03-25 05:04:34'),
(661, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:06:05', 0.00, '2026-03-25 05:06:05'),
(662, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:06:05', 0.00, '2026-03-25 05:06:05'),
(663, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:06:05', 0.00, '2026-03-25 05:06:05'),
(664, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:06:05', 0.00, '2026-03-25 05:06:05'),
(665, 1, 13, 13.88215342, 121.03624403, '2026-03-25 13:06:18', 0.00, '2026-03-25 05:06:18'),
(666, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:07:09', 0.00, '2026-03-25 05:07:09'),
(667, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:07:09', 0.00, '2026-03-25 05:07:09'),
(668, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:07:09', 0.00, '2026-03-25 05:07:09'),
(669, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:07:10', 0.00, '2026-03-25 05:07:10'),
(670, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:07:40', 0.00, '2026-03-25 05:07:40'),
(671, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:07:40', 0.00, '2026-03-25 05:07:40'),
(672, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:07:40', 0.00, '2026-03-25 05:07:40'),
(673, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:07:40', 0.00, '2026-03-25 05:07:40'),
(674, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:08:45', 0.00, '2026-03-25 05:08:45'),
(675, 1, 13, 13.88239944, 121.03622534, '2026-03-25 13:08:46', 0.00, '2026-03-25 05:08:46'),
(676, 1, 13, 13.88239944, 121.03622534, '2026-03-25 13:08:46', 0.00, '2026-03-25 05:08:46'),
(677, 1, 13, 13.88239944, 121.03622534, '2026-03-25 13:08:46', 0.00, '2026-03-25 05:08:46'),
(678, 1, 13, 13.88239944, 121.03622534, '2026-03-25 13:08:47', 0.00, '2026-03-25 05:08:47'),
(679, 1, 13, 13.88239942, 121.03622535, '2026-03-25 13:08:47', 0.00, '2026-03-25 05:08:47'),
(680, 1, 13, 13.88239943, 121.03622534, '2026-03-25 13:08:47', 0.00, '2026-03-25 05:08:47'),
(681, 1, 13, 13.88239942, 121.03622535, '2026-03-25 13:08:53', 0.00, '2026-03-25 05:08:53'),
(682, 1, 13, 13.88239942, 121.03622535, '2026-03-25 13:08:53', 0.00, '2026-03-25 05:08:53'),
(683, 1, 13, 13.88239942, 121.03622535, '2026-03-25 13:08:53', 0.00, '2026-03-25 05:08:53'),
(684, 1, 13, 13.88239942, 121.03622535, '2026-03-25 13:09:00', 0.00, '2026-03-25 05:09:00'),
(685, 1, 13, 13.88239942, 121.03622535, '2026-03-25 13:09:00', 0.00, '2026-03-25 05:09:00'),
(686, 1, 13, 13.88239942, 121.03622535, '2026-03-25 13:09:00', 0.00, '2026-03-25 05:09:00'),
(687, 1, 13, 13.88239942, 121.03622535, '2026-03-25 13:09:00', 0.00, '2026-03-25 05:09:00'),
(688, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:10:12', 0.00, '2026-03-25 05:10:12'),
(689, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:12:02', 0.00, '2026-03-25 05:12:02'),
(690, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:12:02', 0.00, '2026-03-25 05:12:02'),
(691, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:12:02', 0.00, '2026-03-25 05:12:02'),
(692, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:12:02', 0.00, '2026-03-25 05:12:02'),
(693, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:12:08', 0.00, '2026-03-25 05:12:08'),
(694, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:30:03', 0.00, '2026-03-25 05:30:03'),
(695, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:30:03', 0.00, '2026-03-25 05:30:03'),
(696, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:30:03', 0.00, '2026-03-25 05:30:03'),
(697, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:30:03', 0.00, '2026-03-25 05:30:03'),
(698, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:30:12', 0.00, '2026-03-25 05:30:12'),
(699, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:35:28', 0.00, '2026-03-25 05:35:28'),
(700, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:36:23', 0.00, '2026-03-25 05:36:23'),
(701, 1, 13, 13.88213981, 121.03624026, '2026-03-25 13:38:47', 0.00, '2026-03-25 05:38:47'),
(702, 1, 13, 13.88236550, 121.03632350, '2026-03-25 13:42:49', 0.00, '2026-03-25 05:42:49'),
(703, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:43:41', 0.00, '2026-03-25 05:43:41'),
(704, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:43:41', 0.00, '2026-03-25 05:43:41'),
(705, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:43:42', 0.00, '2026-03-25 05:43:42'),
(706, 1, 13, 13.88236123, 121.03631983, '2026-03-25 13:43:42', 0.00, '2026-03-25 05:43:42'),
(707, 1, 13, 13.88236550, 121.03632350, '2026-03-26 08:08:31', 0.00, '2026-03-26 00:08:31'),
(708, 1, 13, 13.88236550, 121.03632350, '2026-03-26 08:08:31', 0.00, '2026-03-26 00:08:31'),
(709, 1, 13, 13.88236550, 121.03632350, '2026-03-26 08:08:31', 0.00, '2026-03-26 00:08:31'),
(710, 1, 13, 13.88236550, 121.03632350, '2026-03-26 08:08:32', 0.00, '2026-03-26 00:08:32'),
(711, 1, 13, 13.88213981, 121.03624026, '2026-03-26 08:09:28', 0.00, '2026-03-26 00:09:28'),
(712, 1, 13, 13.88236550, 121.03632350, '2026-03-26 08:12:07', 0.00, '2026-03-26 00:12:07'),
(713, 1, 13, 13.88236550, 121.03632350, '2026-03-26 08:14:38', 0.00, '2026-03-26 00:14:38'),
(714, 1, 13, 13.88224970, 121.03639918, '2026-03-26 08:25:50', 0.00, '2026-03-26 00:25:50'),
(715, 1, 13, 13.88224970, 121.03639918, '2026-03-26 08:25:50', 0.00, '2026-03-26 00:25:50'),
(716, 1, 13, 13.88224970, 121.03639918, '2026-03-26 08:25:50', 0.00, '2026-03-26 00:25:50'),
(717, 1, 13, 13.88224970, 121.03639918, '2026-03-26 08:25:50', 0.00, '2026-03-26 00:25:50'),
(718, 1, 13, 13.88236550, 121.03632350, '2026-03-26 08:33:38', 0.00, '2026-03-26 00:33:38'),
(719, 1, 13, 13.88224970, 121.03639918, '2026-03-26 08:41:35', 0.00, '2026-03-26 00:41:35'),
(720, 1, 13, 13.88224970, 121.03639918, '2026-03-26 08:41:35', 0.00, '2026-03-26 00:41:35'),
(721, 1, 13, 13.88224970, 121.03639918, '2026-03-26 08:41:35', 0.00, '2026-03-26 00:41:35'),
(722, 1, 13, 13.88224428, 121.03640325, '2026-03-26 08:41:38', 0.00, '2026-03-26 00:41:38'),
(723, 1, 13, 13.88236123, 121.03631983, '2026-03-26 08:44:13', 0.00, '2026-03-26 00:44:13'),
(724, 1, 13, 13.88236123, 121.03631983, '2026-03-26 08:44:35', 0.00, '2026-03-26 00:44:35'),
(725, 1, 13, 13.88236123, 121.03631983, '2026-03-26 08:44:35', 0.00, '2026-03-26 00:44:35'),
(726, 1, 13, 13.88236123, 121.03631983, '2026-03-26 08:44:35', 0.00, '2026-03-26 00:44:35'),
(727, 1, 13, 13.88236123, 121.03631983, '2026-03-26 08:44:35', 0.00, '2026-03-26 00:44:35'),
(728, 1, 13, 13.88215342, 121.03624403, '2026-03-26 08:46:32', 0.00, '2026-03-26 00:46:32'),
(729, 1, 13, 13.88226898, 121.03641424, '2026-03-26 08:49:40', 0.00, '2026-03-26 00:49:40'),
(730, 1, 13, 13.88226898, 121.03641424, '2026-03-26 08:49:40', 0.00, '2026-03-26 00:49:40'),
(731, 1, 13, 13.88226898, 121.03641424, '2026-03-26 08:49:40', 0.00, '2026-03-26 00:49:40'),
(732, 1, 13, 13.88226898, 121.03641424, '2026-03-26 08:49:40', 0.00, '2026-03-26 00:49:40'),
(733, 1, 13, 13.88228647, 121.03638196, '2026-03-26 08:55:56', 0.00, '2026-03-26 00:55:56'),
(734, 1, 13, 13.88228647, 121.03638196, '2026-03-26 08:55:56', 0.00, '2026-03-26 00:55:56'),
(735, 1, 13, 13.88228647, 121.03638196, '2026-03-26 08:55:56', 0.00, '2026-03-26 00:55:56'),
(736, 1, 13, 13.88228647, 121.03638196, '2026-03-26 08:55:56', 0.00, '2026-03-26 00:55:56'),
(737, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:03:43', 0.00, '2026-03-26 01:03:43'),
(738, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:03:59', 0.00, '2026-03-26 01:03:59'),
(739, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:04:02', 0.00, '2026-03-26 01:04:02'),
(740, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:04:02', 0.00, '2026-03-26 01:04:02'),
(741, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:04:02', 0.00, '2026-03-26 01:04:02'),
(742, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:04:02', 0.00, '2026-03-26 01:04:02'),
(743, 1, 13, 13.88213981, 121.03624026, '2026-03-26 09:05:51', 0.00, '2026-03-26 01:05:51'),
(744, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:28:45', 0.00, '2026-03-26 01:28:45'),
(745, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:28:47', 0.00, '2026-03-26 01:28:47'),
(746, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:28:47', 0.00, '2026-03-26 01:28:47'),
(747, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:28:47', 0.00, '2026-03-26 01:28:47'),
(748, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:28:47', 0.00, '2026-03-26 01:28:47'),
(749, 1, 13, 13.88236123, 121.03631983, '2026-03-26 09:28:49', 0.00, '2026-03-26 01:28:49'),
(750, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:39:54', 0.00, '2026-03-26 01:39:54'),
(751, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:44:16', 0.00, '2026-03-26 01:44:16'),
(752, 1, 13, 13.88236550, 121.03632350, '2026-03-26 09:45:19', 0.00, '2026-03-26 01:45:19'),
(753, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:00:58', 0.00, '2026-03-26 02:00:58'),
(754, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:00:58', 0.00, '2026-03-26 02:00:58'),
(755, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:00:58', 0.00, '2026-03-26 02:00:58'),
(756, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:00:58', 0.00, '2026-03-26 02:00:58'),
(757, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:25:35', 0.00, '2026-03-26 02:25:35'),
(758, 1, 13, 13.88213981, 121.03624026, '2026-03-26 10:30:48', 0.00, '2026-03-26 02:30:48'),
(759, 1, 13, 13.88213981, 121.03624026, '2026-03-26 10:31:04', 0.00, '2026-03-26 02:31:04'),
(760, 1, 13, 13.88213981, 121.03624026, '2026-03-26 10:31:08', 0.00, '2026-03-26 02:31:08'),
(761, 1, 13, 13.88213981, 121.03624026, '2026-03-26 10:31:08', 0.00, '2026-03-26 02:31:08'),
(762, 1, 13, 13.88213981, 121.03624026, '2026-03-26 10:31:08', 0.00, '2026-03-26 02:31:08'),
(763, 1, 13, 13.88213981, 121.03624026, '2026-03-26 10:31:09', 0.00, '2026-03-26 02:31:09'),
(764, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:31:56', 0.00, '2026-03-26 02:31:56'),
(765, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:51:02', 0.00, '2026-03-26 02:51:02'),
(766, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:51:02', 0.00, '2026-03-26 02:51:02'),
(767, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:51:02', 0.00, '2026-03-26 02:51:02'),
(768, 1, 13, 13.88236550, 121.03632350, '2026-03-26 10:51:02', 0.00, '2026-03-26 02:51:02'),
(769, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:08:18', 0.00, '2026-03-26 03:08:18'),
(770, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:09:00', 0.00, '2026-03-26 03:09:00'),
(771, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:09:00', 0.00, '2026-03-26 03:09:00'),
(772, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:09:01', 0.00, '2026-03-26 03:09:01'),
(773, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:09:15', 0.00, '2026-03-26 03:09:15'),
(774, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:09:17', 0.00, '2026-03-26 03:09:17'),
(775, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:09:17', 0.00, '2026-03-26 03:09:17'),
(776, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:09:17', 0.00, '2026-03-26 03:09:17'),
(777, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:09:17', 0.00, '2026-03-26 03:09:17'),
(778, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:10:20', 0.00, '2026-03-26 03:10:20'),
(779, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:20:29', 0.00, '2026-03-26 03:20:29'),
(780, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:20:29', 0.00, '2026-03-26 03:20:29'),
(781, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:20:29', 0.00, '2026-03-26 03:20:29'),
(782, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:20:29', 0.00, '2026-03-26 03:20:29'),
(783, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:21:12', 0.00, '2026-03-26 03:21:12'),
(784, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:21:19', 0.00, '2026-03-26 03:21:19'),
(785, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:21:39', 0.00, '2026-03-26 03:21:39'),
(786, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:23:13', 0.00, '2026-03-26 03:23:13'),
(787, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:23:47', 0.00, '2026-03-26 03:23:47'),
(788, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:23:47', 0.00, '2026-03-26 03:23:47'),
(789, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:23:47', 0.00, '2026-03-26 03:23:47'),
(790, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:23:47', 0.00, '2026-03-26 03:23:47'),
(791, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:24:58', 0.00, '2026-03-26 03:24:58'),
(792, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:27:56', 0.00, '2026-03-26 03:27:56'),
(793, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:28:00', 0.00, '2026-03-26 03:28:00'),
(794, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:28:00', 0.00, '2026-03-26 03:28:00'),
(795, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:28:00', 0.00, '2026-03-26 03:28:00'),
(796, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:28:00', 0.00, '2026-03-26 03:28:00'),
(797, 1, 13, 13.88225114, 121.03640083, '2026-03-26 11:28:49', 0.00, '2026-03-26 03:28:49'),
(798, 1, 13, 13.88225114, 121.03640083, '2026-03-26 11:28:49', 0.00, '2026-03-26 03:28:49'),
(799, 1, 13, 13.88225114, 121.03640083, '2026-03-26 11:28:49', 0.00, '2026-03-26 03:28:49'),
(800, 1, 13, 13.88225114, 121.03640083, '2026-03-26 11:28:49', 0.00, '2026-03-26 03:28:49'),
(801, 1, 13, 13.88224548, 121.03640462, '2026-03-26 11:29:17', 0.00, '2026-03-26 03:29:17'),
(802, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:29:50', 0.00, '2026-03-26 03:29:50'),
(803, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:30:07', 0.00, '2026-03-26 03:30:07'),
(804, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:30:09', 0.00, '2026-03-26 03:30:09'),
(805, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:30:09', 0.00, '2026-03-26 03:30:09'),
(806, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:30:09', 0.00, '2026-03-26 03:30:09'),
(807, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:30:09', 0.00, '2026-03-26 03:30:09'),
(808, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:30:51', 0.00, '2026-03-26 03:30:51'),
(809, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:31:56', 0.00, '2026-03-26 03:31:56'),
(810, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:32:11', 0.00, '2026-03-26 03:32:11'),
(811, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:32:15', 0.00, '2026-03-26 03:32:15'),
(812, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:32:15', 0.00, '2026-03-26 03:32:15'),
(813, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:32:15', 0.00, '2026-03-26 03:32:15'),
(814, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:32:19', 0.00, '2026-03-26 03:32:19'),
(815, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:32:57', 0.00, '2026-03-26 03:32:57'),
(816, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:34', 0.00, '2026-03-26 03:33:34'),
(817, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:34', 0.00, '2026-03-26 03:33:34'),
(818, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:34', 0.00, '2026-03-26 03:33:34'),
(819, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:33:34', 0.00, '2026-03-26 03:33:34'),
(820, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:33:34', 0.00, '2026-03-26 03:33:34'),
(821, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:34', 0.00, '2026-03-26 03:33:34'),
(822, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:39', 0.00, '2026-03-26 03:33:39'),
(823, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:40', 0.00, '2026-03-26 03:33:40'),
(824, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:45', 0.00, '2026-03-26 03:33:45'),
(825, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:46', 0.00, '2026-03-26 03:33:46'),
(826, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:51', 0.00, '2026-03-26 03:33:51'),
(827, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:52', 0.00, '2026-03-26 03:33:52'),
(828, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:58', 0.00, '2026-03-26 03:33:58'),
(829, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:33:58', 0.00, '2026-03-26 03:33:58'),
(830, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:34:04', 0.00, '2026-03-26 03:34:04'),
(831, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:34:04', 0.00, '2026-03-26 03:34:04'),
(832, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:34:10', 0.00, '2026-03-26 03:34:10'),
(833, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:34:10', 0.00, '2026-03-26 03:34:10'),
(834, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:34:16', 0.00, '2026-03-26 03:34:16'),
(835, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:34:16', 0.00, '2026-03-26 03:34:16'),
(836, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:34:22', 0.00, '2026-03-26 03:34:22'),
(837, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:34:23', 0.00, '2026-03-26 03:34:23'),
(838, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:34:32', 0.00, '2026-03-26 03:34:32'),
(839, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:34:40', 0.00, '2026-03-26 03:34:40'),
(840, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:36:11', 0.00, '2026-03-26 03:36:11'),
(841, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:36:11', 0.00, '2026-03-26 03:36:11'),
(842, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:36:11', 0.00, '2026-03-26 03:36:11'),
(843, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:36:11', 0.00, '2026-03-26 03:36:11'),
(844, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:36:16', 0.00, '2026-03-26 03:36:16'),
(845, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:36:22', 0.00, '2026-03-26 03:36:22'),
(846, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:36:28', 0.00, '2026-03-26 03:36:28'),
(847, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:36:34', 0.00, '2026-03-26 03:36:34'),
(848, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:36:58', 0.00, '2026-03-26 03:36:58'),
(849, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:37:12', 0.00, '2026-03-26 03:37:12'),
(850, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:37:16', 0.00, '2026-03-26 03:37:16'),
(851, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:37:16', 0.00, '2026-03-26 03:37:16'),
(852, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:37:16', 0.00, '2026-03-26 03:37:16'),
(853, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:37:17', 0.00, '2026-03-26 03:37:17'),
(854, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:44', 0.00, '2026-03-26 03:37:44'),
(855, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:45', 0.00, '2026-03-26 03:37:45'),
(856, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:45', 0.00, '2026-03-26 03:37:45'),
(857, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:45', 0.00, '2026-03-26 03:37:45'),
(858, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:45', 0.00, '2026-03-26 03:37:45'),
(859, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:51', 0.00, '2026-03-26 03:37:51'),
(860, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:51', 0.00, '2026-03-26 03:37:51'),
(861, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:51', 0.00, '2026-03-26 03:37:51'),
(862, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:51', 0.00, '2026-03-26 03:37:51'),
(863, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:51', 0.00, '2026-03-26 03:37:51'),
(864, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:37:51', 0.00, '2026-03-26 03:37:51'),
(865, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:51', 0.00, '2026-03-26 03:37:51'),
(866, 1, 13, 13.88239675, 121.03621692, '2026-03-26 11:37:57', 0.00, '2026-03-26 03:37:57'),
(867, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:39:45', 0.00, '2026-03-26 03:39:45'),
(868, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:39:46', 0.00, '2026-03-26 03:39:46'),
(869, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:39:46', 0.00, '2026-03-26 03:39:46'),
(870, 1, 13, 13.88239675, 121.03621691, '2026-03-26 11:39:46', 0.00, '2026-03-26 03:39:46'),
(871, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:41:38', 0.00, '2026-03-26 03:41:38'),
(872, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:41:38', 0.00, '2026-03-26 03:41:38'),
(873, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:41:38', 0.00, '2026-03-26 03:41:38'),
(874, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:41:39', 0.00, '2026-03-26 03:41:39'),
(875, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:44:41', 0.00, '2026-03-26 03:44:41'),
(876, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:44:41', 0.00, '2026-03-26 03:44:41'),
(877, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:44:41', 0.00, '2026-03-26 03:44:41'),
(878, 1, 13, 13.88236550, 121.03632350, '2026-03-26 11:44:42', 0.00, '2026-03-26 03:44:42'),
(879, 1, 13, 13.88239672, 121.03621691, '2026-03-26 11:47:12', 0.00, '2026-03-26 03:47:12'),
(880, 1, 13, 13.88239672, 121.03621691, '2026-03-26 11:47:13', 0.00, '2026-03-26 03:47:13'),
(881, 1, 13, 13.88239672, 121.03621691, '2026-03-26 11:47:13', 0.00, '2026-03-26 03:47:13'),
(882, 1, 13, 13.88239672, 121.03621691, '2026-03-26 11:47:13', 0.00, '2026-03-26 03:47:13'),
(883, 1, 13, 13.88239673, 121.03621691, '2026-03-26 11:47:17', 0.00, '2026-03-26 03:47:17'),
(884, 1, 13, 13.88239674, 121.03621691, '2026-03-26 11:47:44', 0.00, '2026-03-26 03:47:44'),
(885, 1, 13, 13.88239674, 121.03621691, '2026-03-26 11:47:45', 0.00, '2026-03-26 03:47:45'),
(886, 1, 13, 13.88239674, 121.03621691, '2026-03-26 11:47:45', 0.00, '2026-03-26 03:47:45'),
(887, 1, 13, 13.88239674, 121.03621691, '2026-03-26 11:47:45', 0.00, '2026-03-26 03:47:45'),
(888, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:48:05', 0.00, '2026-03-26 03:48:05'),
(889, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:48:05', 0.00, '2026-03-26 03:48:05'),
(890, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:48:05', 0.00, '2026-03-26 03:48:05'),
(891, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:48:10', 0.00, '2026-03-26 03:48:10'),
(892, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:48:17', 0.00, '2026-03-26 03:48:17'),
(893, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:48:17', 0.00, '2026-03-26 03:48:17'),
(894, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:48:17', 0.00, '2026-03-26 03:48:17'),
(895, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:48:17', 0.00, '2026-03-26 03:48:17'),
(896, 1, 13, 13.88228647, 121.03638196, '2026-03-26 11:50:28', 0.00, '2026-03-26 03:50:28'),
(897, 1, 13, 13.88228647, 121.03638196, '2026-03-26 11:50:28', 0.00, '2026-03-26 03:50:28'),
(898, 1, 13, 13.88228647, 121.03638196, '2026-03-26 11:50:29', 0.00, '2026-03-26 03:50:29'),
(899, 1, 13, 13.88228647, 121.03638196, '2026-03-26 11:50:29', 0.00, '2026-03-26 03:50:29'),
(900, 1, 13, 13.88224970, 121.03639918, '2026-03-26 11:50:37', 0.00, '2026-03-26 03:50:37'),
(901, 1, 13, 13.88224240, 121.03640189, '2026-03-26 11:50:50', 0.00, '2026-03-26 03:50:50'),
(902, 1, 13, 13.88224986, 121.03641329, '2026-03-26 11:51:02', 0.00, '2026-03-26 03:51:02'),
(903, 1, 13, 13.88224091, 121.03641386, '2026-03-26 11:51:41', 0.00, '2026-03-26 03:51:41'),
(904, 1, 13, 13.88224956, 121.03641308, '2026-03-26 11:52:47', 0.00, '2026-03-26 03:52:47'),
(905, 1, 13, 13.88224314, 121.03640330, '2026-03-26 11:53:12', 0.00, '2026-03-26 03:53:12'),
(906, 1, 13, 13.88224314, 121.03640330, '2026-03-26 11:53:23', 0.00, '2026-03-26 03:53:23'),
(907, 1, 13, 13.88224314, 121.03640330, '2026-03-26 11:53:23', 0.00, '2026-03-26 03:53:23'),
(908, 1, 13, 13.88224314, 121.03640330, '2026-03-26 11:53:23', 0.00, '2026-03-26 03:53:23'),
(909, 1, 13, 13.88224314, 121.03640330, '2026-03-26 11:53:25', 0.00, '2026-03-26 03:53:25'),
(910, 1, 13, 13.88224314, 121.03640330, '2026-03-26 11:53:37', 0.00, '2026-03-26 03:53:37'),
(911, 1, 13, 13.88224314, 121.03640330, '2026-03-26 11:53:37', 0.00, '2026-03-26 03:53:37'),
(912, 1, 13, 13.88224314, 121.03640330, '2026-03-26 11:53:37', 0.00, '2026-03-26 03:53:37'),
(913, 1, 13, 13.88226327, 121.03639161, '2026-03-26 11:53:39', 0.00, '2026-03-26 03:53:39'),
(914, 1, 13, 13.88224897, 121.03639509, '2026-03-26 11:55:48', 0.00, '2026-03-26 03:55:48'),
(915, 1, 13, 13.88224897, 121.03639509, '2026-03-26 11:55:48', 0.00, '2026-03-26 03:55:48'),
(916, 1, 13, 13.88224897, 121.03639509, '2026-03-26 11:55:48', 0.00, '2026-03-26 03:55:48'),
(917, 1, 13, 13.88224897, 121.03639509, '2026-03-26 11:55:52', 0.00, '2026-03-26 03:55:52'),
(918, 1, 13, 13.88224897, 121.03639509, '2026-03-26 11:55:52', 0.00, '2026-03-26 03:55:52'),
(919, 1, 13, 13.88224897, 121.03639509, '2026-03-26 11:55:52', 0.00, '2026-03-26 03:55:52'),
(920, 1, 13, 13.88224897, 121.03639509, '2026-03-26 11:55:52', 0.00, '2026-03-26 03:55:52'),
(921, 1, 13, 13.88224240, 121.03640189, '2026-03-26 11:56:30', 0.00, '2026-03-26 03:56:30'),
(922, 1, 13, 13.88224240, 121.03640189, '2026-03-26 11:57:48', 0.00, '2026-03-26 03:57:48'),
(923, 1, 13, 13.88224240, 121.03640189, '2026-03-26 11:57:48', 0.00, '2026-03-26 03:57:48'),
(924, 1, 13, 13.88224240, 121.03640189, '2026-03-26 11:57:48', 0.00, '2026-03-26 03:57:48'),
(925, 1, 13, 13.88224240, 121.03640189, '2026-03-26 11:57:50', 0.00, '2026-03-26 03:57:50'),
(926, 1, 13, 13.88224240, 121.03640189, '2026-03-26 11:58:51', 0.00, '2026-03-26 03:58:51'),
(927, 1, 13, 13.88239141, 121.03621389, '2026-03-26 12:40:42', 0.00, '2026-03-26 04:40:42'),
(928, 1, 13, 13.88239141, 121.03621389, '2026-03-26 12:40:43', 0.00, '2026-03-26 04:40:43'),
(929, 1, 13, 13.88239141, 121.03621389, '2026-03-26 12:40:43', 0.00, '2026-03-26 04:40:43'),
(930, 1, 13, 13.88239141, 121.03621389, '2026-03-26 12:40:43', 0.00, '2026-03-26 04:40:43'),
(931, 1, 13, 13.88239140, 121.03621389, '2026-03-26 12:40:43', 0.00, '2026-03-26 04:40:43'),
(932, 1, 13, 13.88239141, 121.03621389, '2026-03-26 12:40:43', 0.00, '2026-03-26 04:40:43'),
(933, 1, 13, 13.88224240, 121.03640189, '2026-03-26 13:00:47', 0.00, '2026-03-26 05:00:47'),
(934, 1, 13, 13.88224240, 121.03640189, '2026-03-26 13:01:55', 0.00, '2026-03-26 05:01:55'),
(935, 1, 13, 13.88224897, 121.03639509, '2026-03-26 13:03:01', 0.00, '2026-03-26 05:03:01'),
(936, 1, 13, 13.88224240, 121.03640189, '2026-03-26 13:03:54', 0.00, '2026-03-26 05:03:54'),
(937, 1, 13, 13.88223524, 121.03640673, '2026-03-26 13:04:19', 0.00, '2026-03-26 05:04:19'),
(938, 1, 13, 13.88236550, 121.03632350, '2026-03-26 13:04:44', 0.00, '2026-03-26 05:04:44'),
(939, 1, 13, 13.88213981, 121.03624026, '2026-03-26 13:06:40', 0.00, '2026-03-26 05:06:40'),
(940, 1, 13, 13.88224897, 121.03639509, '2026-03-26 13:08:01', 0.00, '2026-03-26 05:08:01'),
(941, 1, 13, 13.88224240, 121.03640189, '2026-03-26 13:08:28', 0.00, '2026-03-26 05:08:28'),
(942, 1, 13, 13.88236550, 121.03632350, '2026-03-26 13:09:57', 0.00, '2026-03-26 05:09:57'),
(943, 1, 13, 13.88236550, 121.03632350, '2026-03-26 14:01:02', 0.00, '2026-03-26 06:01:02'),
(944, 1, 13, 13.88236550, 121.03632350, '2026-03-26 14:01:02', 0.00, '2026-03-26 06:01:02'),
(945, 1, 13, 13.88236550, 121.03632350, '2026-03-26 14:01:02', 0.00, '2026-03-26 06:01:02'),
(946, 1, 13, 13.88236550, 121.03632350, '2026-03-26 14:01:04', 0.00, '2026-03-26 06:01:04'),
(947, 1, 13, 13.88236123, 121.03631983, '2026-03-26 14:01:10', 0.00, '2026-03-26 06:01:10'),
(948, 1, 13, 13.88213981, 121.03624026, '2026-03-26 14:10:21', 0.00, '2026-03-26 06:10:21'),
(949, 1, 13, 13.88236550, 121.03632350, '2026-03-26 14:12:29', 0.00, '2026-03-26 06:12:29'),
(950, 1, 13, 13.88213981, 121.03624026, '2026-03-26 14:15:07', 0.00, '2026-03-26 06:15:07'),
(951, 1, 13, 13.88236550, 121.03632350, '2026-03-26 14:17:05', 0.00, '2026-03-26 06:17:05'),
(952, 1, 13, 13.88213981, 121.03624026, '2026-03-26 14:18:11', 0.00, '2026-03-26 06:18:11'),
(953, 1, 13, 13.88236550, 121.03632350, '2026-03-26 14:20:20', 0.00, '2026-03-26 06:20:20'),
(954, 1, 13, 13.88213981, 121.03624026, '2026-03-26 14:20:25', 0.00, '2026-03-26 06:20:25'),
(955, 1, 13, 13.88224240, 121.03640189, '2026-03-26 14:33:33', 0.00, '2026-03-26 06:33:33'),
(956, 1, 13, 13.88224240, 121.03640189, '2026-03-26 14:33:33', 0.00, '2026-03-26 06:33:33'),
(957, 1, 13, 13.88224240, 121.03640189, '2026-03-26 14:33:33', 0.00, '2026-03-26 06:33:33'),
(958, 1, 13, 13.88224240, 121.03640189, '2026-03-26 14:33:34', 0.00, '2026-03-26 06:33:34'),
(959, 1, 13, 13.88225114, 121.03640083, '2026-03-26 14:33:49', 0.00, '2026-03-26 06:33:49'),
(960, 1, 13, 13.88224240, 121.03640189, '2026-03-26 14:34:28', 0.00, '2026-03-26 06:34:28'),
(961, 1, 13, 13.88224897, 121.03639509, '2026-03-26 14:37:06', 0.00, '2026-03-26 06:37:06'),
(962, 1, 13, 13.88224240, 121.03640189, '2026-03-26 14:37:31', 0.00, '2026-03-26 06:37:31'),
(963, 1, 13, 13.86328880, 120.99471691, '2026-03-26 20:53:00', 0.00, '2026-03-26 12:53:00'),
(964, 1, 13, 13.86328880, 120.99471691, '2026-03-26 20:53:00', 0.00, '2026-03-26 12:53:00'),
(965, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:53:00', 0.00, '2026-03-26 12:53:00'),
(966, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:53:00', 0.00, '2026-03-26 12:53:00'),
(967, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:53:00', 0.00, '2026-03-26 12:53:00'),
(968, 1, 13, 13.86328880, 120.99471691, '2026-03-26 20:53:00', 0.00, '2026-03-26 12:53:00'),
(969, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:53:06', 0.00, '2026-03-26 12:53:06'),
(970, 1, 13, 13.86328880, 120.99471689, '2026-03-26 20:53:12', 0.00, '2026-03-26 12:53:12'),
(971, 1, 13, 13.86328879, 120.99471687, '2026-03-26 20:53:18', 0.00, '2026-03-26 12:53:18'),
(972, 1, 13, 13.86328879, 120.99471687, '2026-03-26 20:53:23', 0.00, '2026-03-26 12:53:23'),
(973, 1, 13, 13.86328879, 120.99471687, '2026-03-26 20:53:23', 0.00, '2026-03-26 12:53:23'),
(974, 1, 13, 13.86328879, 120.99471687, '2026-03-26 20:53:23', 0.00, '2026-03-26 12:53:23'),
(975, 1, 13, 13.86328879, 120.99471687, '2026-03-26 20:53:23', 0.00, '2026-03-26 12:53:23'),
(976, 1, 13, 13.86328879, 120.99471687, '2026-03-26 20:53:23', 0.00, '2026-03-26 12:53:23'),
(977, 1, 13, 13.86328879, 120.99471687, '2026-03-26 20:53:23', 0.00, '2026-03-26 12:53:23'),
(978, 1, 13, 13.86328881, 120.99471692, '2026-03-26 20:53:24', 0.00, '2026-03-26 12:53:24'),
(979, 1, 13, 13.86328880, 120.99471690, '2026-03-26 20:54:07', 0.00, '2026-03-26 12:54:07'),
(980, 1, 13, 13.86328880, 120.99471690, '2026-03-26 20:54:07', 0.00, '2026-03-26 12:54:07'),
(981, 1, 13, 13.86328880, 120.99471690, '2026-03-26 20:54:07', 0.00, '2026-03-26 12:54:07'),
(982, 1, 13, 13.86328879, 120.99471688, '2026-03-26 20:54:08', 0.00, '2026-03-26 12:54:08'),
(983, 1, 13, 13.86328879, 120.99471688, '2026-03-26 20:54:08', 0.00, '2026-03-26 12:54:08'),
(984, 1, 13, 13.86328881, 120.99471692, '2026-03-26 20:54:08', 0.00, '2026-03-26 12:54:08'),
(985, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:13', 0.00, '2026-03-26 12:54:13'),
(986, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:19', 0.00, '2026-03-26 12:54:19'),
(987, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:25', 0.00, '2026-03-26 12:54:25'),
(988, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:31', 0.00, '2026-03-26 12:54:31'),
(989, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:31', 0.00, '2026-03-26 12:54:31'),
(990, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:37', 0.00, '2026-03-26 12:54:37'),
(991, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:37', 0.00, '2026-03-26 12:54:37'),
(992, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:41', 0.00, '2026-03-26 12:54:41'),
(993, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:42', 0.00, '2026-03-26 12:54:42'),
(994, 1, 13, 13.86329828, 120.99475340, '2026-03-26 20:54:42', 0.00, '2026-03-26 12:54:42'),
(995, 1, 13, 13.86327722, 120.99472028, '2026-03-26 20:54:47', 0.99, '2026-03-26 12:54:47'),
(996, 1, 13, 13.86326921, 120.99472398, '2026-03-26 20:54:48', 1.06, '2026-03-26 12:54:48'),
(997, 1, 13, 13.86327680, 120.99470804, '2026-03-26 20:54:48', 1.06, '2026-03-26 12:54:48'),
(998, 1, 13, 13.86327683, 120.99470797, '2026-03-26 20:54:49', 1.06, '2026-03-26 12:54:49'),
(999, 1, 13, 13.86327512, 120.99470651, '2026-03-26 20:54:49', 1.03, '2026-03-26 12:54:49'),
(1000, 1, 13, 13.86326785, 120.99470727, '2026-03-26 20:54:50', 1.04, '2026-03-26 12:54:50'),
(1001, 1, 13, 13.86326697, 120.99470712, '2026-03-26 20:54:51', 1.04, '2026-03-26 12:54:51'),
(1002, 1, 13, 13.86326414, 120.99470588, '2026-03-26 20:54:52', 1.07, '2026-03-26 12:54:52'),
(1003, 1, 13, 13.86326688, 120.99469711, '2026-03-26 20:54:53', 1.09, '2026-03-26 12:54:53'),
(1004, 1, 13, 13.86326677, 120.99469704, '2026-03-26 20:54:53', 1.09, '2026-03-26 12:54:53'),
(1005, 1, 13, 13.86326461, 120.99469583, '2026-03-26 20:54:54', 1.09, '2026-03-26 12:54:54'),
(1006, 1, 13, 13.86326457, 120.99469578, '2026-03-26 20:54:54', 1.09, '2026-03-26 12:54:54'),
(1007, 1, 13, 13.86326457, 120.99469578, '2026-03-26 20:55:00', 0.00, '2026-03-26 12:55:00'),
(1008, 1, 13, 13.86326457, 120.99469578, '2026-03-26 20:55:00', 0.00, '2026-03-26 12:55:00'),
(1009, 1, 13, 13.86326457, 120.99469578, '2026-03-26 20:55:06', 0.00, '2026-03-26 12:55:06'),
(1010, 1, 13, 13.86326459, 120.99469580, '2026-03-26 20:55:06', 0.00, '2026-03-26 12:55:06'),
(1011, 1, 13, 13.86326656, 120.99469766, '2026-03-26 20:55:12', 0.00, '2026-03-26 12:55:12'),
(1012, 1, 13, 13.86326656, 120.99469766, '2026-03-26 20:55:12', 0.00, '2026-03-26 12:55:12'),
(1013, 1, 13, 13.86326656, 120.99469766, '2026-03-26 20:55:13', 0.00, '2026-03-26 12:55:13'),
(1014, 1, 13, 13.86326459, 120.99469579, '2026-03-26 20:55:15', 0.00, '2026-03-26 12:55:15'),
(1015, 1, 13, 13.86326425, 120.99469563, '2026-03-26 20:55:16', 1.42, '2026-03-26 12:55:16'),
(1016, 1, 13, 13.86326107, 120.99469042, '2026-03-26 20:55:17', 1.42, '2026-03-26 12:55:17'),
(1017, 1, 13, 13.86326100, 120.99469026, '2026-03-26 20:55:18', 0.00, '2026-03-26 12:55:18'),
(1018, 1, 13, 13.86326089, 120.99469016, '2026-03-26 20:55:18', 0.00, '2026-03-26 12:55:18'),
(1019, 1, 13, 13.86326087, 120.99468972, '2026-03-26 20:55:19', 1.24, '2026-03-26 12:55:19'),
(1020, 1, 13, 13.86326097, 120.99468995, '2026-03-26 20:55:19', 1.24, '2026-03-26 12:55:19'),
(1021, 1, 13, 13.86326102, 120.99468980, '2026-03-26 20:55:20', 1.25, '2026-03-26 12:55:20'),
(1022, 1, 13, 13.86326189, 120.99468556, '2026-03-26 20:55:21', 1.26, '2026-03-26 12:55:21'),
(1023, 1, 13, 13.86326224, 120.99468454, '2026-03-26 20:55:22', 1.26, '2026-03-26 12:55:22'),
(1024, 1, 13, 13.86326252, 120.99468331, '2026-03-26 20:55:23', 1.28, '2026-03-26 12:55:23'),
(1025, 1, 13, 13.86326590, 120.99467653, '2026-03-26 20:55:24', 1.38, '2026-03-26 12:55:24'),
(1026, 1, 13, 13.86326578, 120.99467639, '2026-03-26 20:55:25', 1.38, '2026-03-26 12:55:25'),
(1027, 1, 13, 13.86326915, 120.99466053, '2026-03-26 20:55:25', 0.00, '2026-03-26 12:55:25'),
(1028, 1, 13, 13.86327468, 120.99468357, '2026-03-26 20:55:26', 1.24, '2026-03-26 12:55:26'),
(1029, 1, 13, 13.86327399, 120.99468582, '2026-03-26 20:55:27', 0.00, '2026-03-26 12:55:27'),
(1030, 1, 13, 13.86327121, 120.99468615, '2026-03-26 20:55:28', 0.00, '2026-03-26 12:55:28'),
(1031, 1, 13, 13.86326784, 120.99468701, '2026-03-26 20:55:29', 0.00, '2026-03-26 12:55:29'),
(1032, 1, 13, 13.86326703, 120.99468723, '2026-03-26 20:55:30', 0.00, '2026-03-26 12:55:30'),
(1033, 1, 13, 13.86326643, 120.99468736, '2026-03-26 20:55:31', 0.00, '2026-03-26 12:55:31'),
(1034, 1, 13, 13.86326620, 120.99468729, '2026-03-26 20:55:32', 0.00, '2026-03-26 12:55:32'),
(1035, 1, 13, 13.86326038, 120.99468849, '2026-03-26 20:55:33', 0.00, '2026-03-26 12:55:33'),
(1036, 1, 13, 13.86325655, 120.99468928, '2026-03-26 20:55:34', 0.00, '2026-03-26 12:55:34'),
(1037, 1, 13, 13.86325394, 120.99468981, '2026-03-26 20:55:35', 0.00, '2026-03-26 12:55:35'),
(1038, 1, 13, 13.86325180, 120.99468542, '2026-03-26 20:55:36', 0.00, '2026-03-26 12:55:36'),
(1039, 1, 13, 13.86325106, 120.99468561, '2026-03-26 20:55:37', 0.00, '2026-03-26 12:55:37'),
(1040, 1, 13, 13.86325076, 120.99468546, '2026-03-26 20:55:38', 0.00, '2026-03-26 12:55:38'),
(1041, 1, 13, 13.86325028, 120.99468897, '2026-03-26 20:55:39', 0.49, '2026-03-26 12:55:39'),
(1042, 1, 13, 13.86328300, 120.99471245, '2026-03-26 21:01:17', 0.00, '2026-03-26 13:01:17'),
(1043, 1, 13, 13.86329828, 120.99475340, '2026-03-26 21:01:17', 0.00, '2026-03-26 13:01:17'),
(1044, 1, 13, 13.86329828, 120.99475340, '2026-03-26 21:01:17', 0.00, '2026-03-26 13:01:17'),
(1045, 1, 13, 13.86329828, 120.99475340, '2026-03-26 21:01:17', 0.00, '2026-03-26 13:01:17'),
(1046, 1, 13, 13.86329828, 120.99475340, '2026-03-26 21:01:17', 0.00, '2026-03-26 13:01:17'),
(1047, 1, 13, 13.88226261, 121.03639715, '2026-03-27 07:36:32', 0.00, '2026-03-26 23:36:32'),
(1048, 1, 13, 13.88226261, 121.03639715, '2026-03-27 07:36:32', 0.00, '2026-03-26 23:36:32'),
(1049, 1, 13, 13.88226261, 121.03639715, '2026-03-27 07:36:32', 0.00, '2026-03-26 23:36:32'),
(1050, 1, 13, 13.88226261, 121.03639715, '2026-03-27 07:36:32', 0.00, '2026-03-26 23:36:32'),
(1051, 1, 13, 13.88225114, 121.03640083, '2026-03-27 07:36:57', 0.00, '2026-03-26 23:36:57'),
(1052, 1, 13, 13.88225114, 121.03640083, '2026-03-27 07:37:25', 0.00, '2026-03-26 23:37:25'),
(1053, 1, 13, 13.88230894, 121.03633935, '2026-03-27 08:22:47', 0.00, '2026-03-27 00:22:47'),
(1054, 1, 13, 13.88229303, 121.03634922, '2026-03-27 08:22:59', 0.00, '2026-03-27 00:22:59'),
(1055, 1, 13, 13.88228207, 121.03635760, '2026-03-27 08:24:31', 0.00, '2026-03-27 00:24:31'),
(1056, 1, 13, 13.88228040, 121.03633973, '2026-03-27 08:24:56', 0.00, '2026-03-27 00:24:56'),
(1057, 1, 13, 13.88226128, 121.03636481, '2026-03-27 08:25:22', 0.00, '2026-03-27 00:25:22'),
(1058, 1, 13, 13.88223524, 121.03640673, '2026-03-27 08:25:59', 0.00, '2026-03-27 00:25:59'),
(1059, 1, 13, 13.88223865, 121.03640573, '2026-03-27 08:27:15', 0.00, '2026-03-27 00:27:15'),
(1060, 1, 13, 13.88224970, 121.03639918, '2026-03-27 08:27:28', 0.00, '2026-03-27 00:27:28'),
(1061, 1, 13, 13.88226261, 121.03639715, '2026-03-27 08:28:06', 0.00, '2026-03-27 00:28:06'),
(1062, 1, 13, 13.88224970, 121.03639918, '2026-03-27 08:29:23', 0.00, '2026-03-27 00:29:23'),
(1063, 1, 13, 13.88224240, 121.03640189, '2026-03-27 08:30:01', 0.00, '2026-03-27 00:30:01'),
(1064, 1, 13, 13.88225114, 121.03640083, '2026-03-27 08:31:28', 0.00, '2026-03-27 00:31:28'),
(1065, 1, 13, 13.88224548, 121.03640462, '2026-03-27 08:32:58', 0.00, '2026-03-27 00:32:58'),
(1066, 1, 13, 13.88223356, 121.03640831, '2026-03-27 08:35:17', 0.00, '2026-03-27 00:35:17'),
(1067, 1, 13, 13.88223542, 121.03640595, '2026-03-27 08:35:40', 0.00, '2026-03-27 00:35:40'),
(1068, 1, 13, 13.88224240, 121.03640189, '2026-03-27 08:38:25', 0.00, '2026-03-27 00:38:25'),
(1069, 1, 13, 13.88224897, 121.03639509, '2026-03-27 08:41:35', 0.00, '2026-03-27 00:41:35'),
(1070, 1, 13, 13.88224240, 121.03640189, '2026-03-27 08:41:47', 0.00, '2026-03-27 00:41:47'),
(1071, 1, 13, 13.88224970, 121.03639918, '2026-03-27 08:41:59', 0.00, '2026-03-27 00:41:59'),
(1072, 1, 13, 13.88228040, 121.03633973, '2026-03-27 08:42:39', 0.00, '2026-03-27 00:42:39'),
(1073, 1, 13, 13.88224240, 121.03640189, '2026-03-27 08:44:45', 0.00, '2026-03-27 00:44:45'),
(1074, 1, 13, 13.88224986, 121.03641329, '2026-03-27 08:47:03', 0.00, '2026-03-27 00:47:03'),
(1075, 1, 13, 13.88228207, 121.03635760, '2026-03-27 08:48:57', 0.00, '2026-03-27 00:48:57'),
(1076, 1, 13, 13.88228040, 121.03633973, '2026-03-27 08:49:09', 0.00, '2026-03-27 00:49:09'),
(1077, 1, 13, 13.88229295, 121.03632687, '2026-03-27 08:50:39', 0.00, '2026-03-27 00:50:39'),
(1078, 1, 13, 13.88224970, 121.03639918, '2026-03-27 08:51:04', 0.00, '2026-03-27 00:51:04'),
(1079, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:21:28', 0.00, '2026-03-27 01:21:28'),
(1080, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:21:30', 0.00, '2026-03-27 01:21:30'),
(1081, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:21:30', 0.00, '2026-03-27 01:21:30'),
(1082, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:21:30', 0.00, '2026-03-27 01:21:30'),
(1083, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:21:30', 0.00, '2026-03-27 01:21:30'),
(1084, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:23:52', 0.00, '2026-03-27 01:23:52'),
(1085, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:24:04', 0.00, '2026-03-27 01:24:04'),
(1086, 1, 13, 13.88224091, 121.03641386, '2026-03-27 09:25:08', 0.00, '2026-03-27 01:25:08'),
(1087, 1, 13, 13.88226261, 121.03639715, '2026-03-27 09:28:36', 0.00, '2026-03-27 01:28:36'),
(1088, 1, 13, 13.88225719, 121.03641300, '2026-03-27 09:28:48', 0.00, '2026-03-27 01:28:48'),
(1089, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:30:31', 0.00, '2026-03-27 01:30:31'),
(1090, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:32:09', 0.00, '2026-03-27 01:32:09'),
(1091, 1, 13, 13.88225719, 121.03641300, '2026-03-27 09:32:33', 0.00, '2026-03-27 01:32:33'),
(1092, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:32:57', 0.00, '2026-03-27 01:32:57'),
(1093, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:33:12', 0.00, '2026-03-27 01:33:12'),
(1094, 1, 13, 13.88225114, 121.03640083, '2026-03-27 09:33:24', 0.00, '2026-03-27 01:33:24');
INSERT INTO `driver_tracking` (`tracking_id`, `driver_id`, `trip_id`, `latitude`, `longitude`, `location_timestamp`, `speed_kmh`, `created_at`) VALUES
(1095, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:34:17', 0.00, '2026-03-27 01:34:17'),
(1096, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:36:08', 0.00, '2026-03-27 01:36:08'),
(1097, 1, 13, 13.88225839, 121.03641437, '2026-03-27 09:37:11', 0.00, '2026-03-27 01:37:11'),
(1098, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:37:24', 0.00, '2026-03-27 01:37:24'),
(1099, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:38:13', 0.00, '2026-03-27 01:38:13'),
(1100, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:38:52', 0.00, '2026-03-27 01:38:52'),
(1101, 1, 13, 13.88225718, 121.03641074, '2026-03-27 09:39:04', 0.00, '2026-03-27 01:39:04'),
(1102, 1, 13, 13.88224986, 121.03641329, '2026-03-27 09:40:34', 0.00, '2026-03-27 01:40:34'),
(1103, 1, 13, 13.88225839, 121.03641437, '2026-03-27 09:42:01', 0.00, '2026-03-27 01:42:01'),
(1104, 1, 13, 13.88225114, 121.03640083, '2026-03-27 09:42:13', 0.00, '2026-03-27 01:42:13'),
(1105, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:42:38', 0.00, '2026-03-27 01:42:38'),
(1106, 1, 13, 13.88224897, 121.03639509, '2026-03-27 09:42:51', 0.00, '2026-03-27 01:42:51'),
(1107, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:43:41', 0.00, '2026-03-27 01:43:41'),
(1108, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:43:43', 0.00, '2026-03-27 01:43:43'),
(1109, 1, 13, 13.88224897, 121.03639509, '2026-03-27 09:49:33', 0.00, '2026-03-27 01:49:33'),
(1110, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:50:12', 0.00, '2026-03-27 01:50:12'),
(1111, 1, 13, 13.88225114, 121.03640083, '2026-03-27 09:53:46', 0.00, '2026-03-27 01:53:46'),
(1112, 1, 13, 13.88226327, 121.03639161, '2026-03-27 09:53:57', 0.00, '2026-03-27 01:53:57'),
(1113, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:56:26', 0.00, '2026-03-27 01:56:26'),
(1114, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:56:42', 0.00, '2026-03-27 01:56:42'),
(1115, 1, 13, 13.88226327, 121.03639161, '2026-03-27 09:57:15', 0.00, '2026-03-27 01:57:15'),
(1116, 1, 13, 13.88224240, 121.03640189, '2026-03-27 09:57:28', 0.00, '2026-03-27 01:57:28'),
(1117, 1, 13, 13.88224897, 121.03639509, '2026-03-27 09:59:33', 0.00, '2026-03-27 01:59:33'),
(1118, 1, 13, 13.88224240, 121.03640189, '2026-03-27 10:00:36', 0.00, '2026-03-27 02:00:36'),
(1119, 1, 13, 13.88225114, 121.03640083, '2026-03-27 10:01:37', 0.00, '2026-03-27 02:01:37'),
(1120, 1, 13, 13.88225114, 121.03640083, '2026-03-27 10:02:20', 0.00, '2026-03-27 02:02:20'),
(1121, 1, 13, 13.88226327, 121.03639161, '2026-03-27 10:02:41', 0.00, '2026-03-27 02:02:41'),
(1122, 1, 13, 13.88231738, 121.03629772, '2026-03-27 10:02:52', 0.00, '2026-03-27 02:02:52'),
(1123, 1, 13, 13.88229415, 121.03632823, '2026-03-27 10:03:43', 0.00, '2026-03-27 02:03:43'),
(1124, 1, 13, 13.88228040, 121.03633973, '2026-03-27 10:04:07', 0.00, '2026-03-27 02:04:07'),
(1125, 1, 13, 13.88227245, 121.03635034, '2026-03-27 10:07:20', 0.00, '2026-03-27 02:07:20'),
(1126, 1, 13, 13.88229603, 121.03632960, '2026-03-27 10:07:31', 0.00, '2026-03-27 02:07:31'),
(1127, 1, 13, 13.88228303, 121.03634207, '2026-03-27 10:07:56', 0.00, '2026-03-27 02:07:56'),
(1128, 1, 13, 13.88225114, 121.03640083, '2026-03-27 10:09:24', 0.00, '2026-03-27 02:09:24'),
(1129, 1, 13, 13.88226261, 121.03639715, '2026-03-27 10:10:00', 0.00, '2026-03-27 02:10:00'),
(1130, 1, 13, 13.88226261, 121.03639715, '2026-03-27 10:14:57', 0.00, '2026-03-27 02:14:57'),
(1131, 1, 13, 13.88228040, 121.03633973, '2026-03-27 10:15:59', 0.00, '2026-03-27 02:15:59'),
(1132, 1, 13, 13.88228207, 121.03635760, '2026-03-27 10:16:11', 0.00, '2026-03-27 02:16:11'),
(1133, 1, 13, 13.88227483, 121.03636499, '2026-03-27 10:16:24', 0.00, '2026-03-27 02:16:24'),
(1134, 1, 13, 13.88223884, 121.03640497, '2026-03-27 10:18:16', 0.00, '2026-03-27 02:18:16'),
(1135, 1, 13, 13.88227245, 121.03635034, '2026-03-27 10:18:52', 0.00, '2026-03-27 02:18:52'),
(1136, 1, 13, 13.88228040, 121.03633973, '2026-03-27 10:19:30', 0.00, '2026-03-27 02:19:30'),
(1137, 1, 13, 13.88229415, 121.03632823, '2026-03-27 10:19:42', 0.00, '2026-03-27 02:19:42'),
(1138, 1, 13, 13.88228303, 121.03634207, '2026-03-27 10:19:54', 0.00, '2026-03-27 02:19:54'),
(1139, 1, 13, 13.88227245, 121.03635034, '2026-03-27 10:20:07', 0.00, '2026-03-27 02:20:07'),
(1140, 1, 13, 13.88223884, 121.03640497, '2026-03-27 10:20:56', 0.00, '2026-03-27 02:20:56'),
(1141, 1, 13, 13.88223884, 121.03640497, '2026-03-27 10:21:52', 0.00, '2026-03-27 02:21:52'),
(1142, 1, 13, 13.88226327, 121.03639161, '2026-03-27 10:22:15', 0.00, '2026-03-27 02:22:15'),
(1143, 1, 13, 13.88228647, 121.03638196, '2026-03-27 10:23:05', 0.00, '2026-03-27 02:23:05'),
(1144, 1, 13, 13.88229906, 121.03632082, '2026-03-27 10:23:28', 0.00, '2026-03-27 02:23:28'),
(1145, 1, 13, 13.88228364, 121.03633423, '2026-03-27 10:23:41', 0.00, '2026-03-27 02:23:41'),
(1146, 1, 13, 13.88228364, 121.03633423, '2026-03-27 10:24:17', 0.00, '2026-03-27 02:24:17'),
(1147, 1, 13, 13.88228364, 121.03633423, '2026-03-27 10:25:06', 0.00, '2026-03-27 02:25:06'),
(1148, 1, 13, 13.88227245, 121.03635034, '2026-03-27 10:25:19', 0.00, '2026-03-27 02:25:19'),
(1149, 1, 13, 13.88227245, 121.03635034, '2026-03-27 10:26:53', 0.00, '2026-03-27 02:26:53'),
(1150, 1, 13, 13.88228364, 121.03633423, '2026-03-27 10:27:39', 0.00, '2026-03-27 02:27:39'),
(1151, 1, 13, 13.88231580, 121.03629591, '2026-03-27 10:30:32', 0.00, '2026-03-27 02:30:32'),
(1152, 1, 13, 13.88231580, 121.03629591, '2026-03-27 10:31:44', 0.00, '2026-03-27 02:31:44'),
(1153, 1, 13, 13.88229777, 121.03631934, '2026-03-27 10:32:21', 0.00, '2026-03-27 02:32:21'),
(1154, 1, 13, 13.88229603, 121.03632960, '2026-03-27 10:33:48', 0.00, '2026-03-27 02:33:48'),
(1155, 1, 13, 13.88229603, 121.03632960, '2026-03-27 10:34:14', 0.00, '2026-03-27 02:34:14'),
(1156, 1, 13, 13.88225341, 121.03640248, '2026-03-27 10:34:43', 0.00, '2026-03-27 02:34:43'),
(1157, 1, 13, 13.88224428, 121.03640325, '2026-03-27 10:38:12', 0.00, '2026-03-27 02:38:12'),
(1158, 1, 13, 13.88233693, 121.03631241, '2026-03-27 10:54:58', 0.00, '2026-03-27 02:54:58'),
(1159, 1, 13, 13.88233693, 121.03631241, '2026-03-27 10:54:58', 0.00, '2026-03-27 02:54:58'),
(1160, 1, 13, 13.88233693, 121.03631241, '2026-03-27 10:54:58', 0.00, '2026-03-27 02:54:58'),
(1161, 1, 13, 13.88233693, 121.03631241, '2026-03-27 10:54:58', 0.00, '2026-03-27 02:54:58'),
(1162, 1, 13, 13.88224970, 121.03639918, '2026-03-27 11:38:58', 0.00, '2026-03-27 03:38:58'),
(1163, 1, 13, 13.88224970, 121.03639918, '2026-03-27 11:38:58', 0.00, '2026-03-27 03:38:58'),
(1164, 1, 13, 13.88224970, 121.03639918, '2026-03-27 11:38:58', 0.00, '2026-03-27 03:38:58'),
(1165, 1, 13, 13.88224970, 121.03639918, '2026-03-27 11:38:58', 0.00, '2026-03-27 03:38:58'),
(1166, 1, 13, 13.88224428, 121.03640325, '2026-03-27 11:39:12', 0.00, '2026-03-27 03:39:12'),
(1167, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:41:28', 0.00, '2026-03-27 03:41:28'),
(1168, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:42:07', 0.00, '2026-03-27 03:42:07'),
(1169, 10, NULL, 13.88225764, 121.03615379, '2026-03-27 11:42:20', 0.00, '2026-03-27 03:42:20'),
(1170, 10, NULL, 13.88225764, 121.03615379, '2026-03-27 11:42:20', 0.00, '2026-03-27 03:42:20'),
(1171, 10, NULL, 13.88225764, 121.03615379, '2026-03-27 11:42:20', 0.00, '2026-03-27 03:42:20'),
(1172, 10, NULL, 13.88225764, 121.03615379, '2026-03-27 11:42:20', 0.00, '2026-03-27 03:42:20'),
(1173, 10, NULL, 13.88227332, 121.03615209, '2026-03-27 11:42:48', 0.00, '2026-03-27 03:42:48'),
(1174, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:45:08', 0.00, '2026-03-27 03:45:08'),
(1175, 1, 13, 13.88224970, 121.03639918, '2026-03-27 11:47:41', 0.00, '2026-03-27 03:47:41'),
(1176, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:47:53', 0.00, '2026-03-27 03:47:53'),
(1177, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:49:48', 0.00, '2026-03-27 03:49:48'),
(1178, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:50:13', 0.00, '2026-03-27 03:50:13'),
(1179, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:50:13', 0.00, '2026-03-27 03:50:13'),
(1180, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:50:13', 0.00, '2026-03-27 03:50:13'),
(1181, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:50:15', 0.00, '2026-03-27 03:50:15'),
(1182, 1, 13, 13.88228040, 121.03633973, '2026-03-27 11:50:28', 0.00, '2026-03-27 03:50:28'),
(1183, 1, 13, 13.88228207, 121.03635760, '2026-03-27 11:50:54', 0.00, '2026-03-27 03:50:54'),
(1184, 1, 13, 13.88229303, 121.03634922, '2026-03-27 11:53:15', 0.00, '2026-03-27 03:53:15'),
(1185, 1, 13, 13.88229295, 121.03632687, '2026-03-27 11:53:27', 0.00, '2026-03-27 03:53:27'),
(1186, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:53:40', 0.00, '2026-03-27 03:53:40'),
(1187, 1, 13, 13.88223524, 121.03640673, '2026-03-27 11:54:58', 0.00, '2026-03-27 03:54:58'),
(1188, 1, 13, 13.88224240, 121.03640189, '2026-03-27 11:57:04', 0.00, '2026-03-27 03:57:04'),
(1189, 1, 13, 13.88224970, 121.03639918, '2026-03-27 13:05:47', 0.00, '2026-03-27 05:05:47'),
(1190, 1, 13, 13.88224970, 121.03639918, '2026-03-27 13:06:10', 0.00, '2026-03-27 05:06:10'),
(1191, 1, 13, 13.88228347, 121.03635862, '2026-03-27 13:06:24', 0.00, '2026-03-27 05:06:24'),
(1192, 1, 13, 13.88228347, 121.03635862, '2026-03-27 13:06:32', 0.00, '2026-03-27 05:06:32'),
(1193, 1, 13, 13.88226038, 121.03637894, '2026-03-27 13:06:52', 0.00, '2026-03-27 05:06:52'),
(1194, 1, 13, 13.88226038, 121.03637894, '2026-03-27 13:08:20', 0.00, '2026-03-27 05:08:20'),
(1195, 1, 13, 13.88226038, 121.03637894, '2026-03-27 13:08:26', 0.00, '2026-03-27 05:08:26'),
(1196, 1, 13, 13.88226128, 121.03636481, '2026-03-27 13:08:30', 0.00, '2026-03-27 05:08:30'),
(1197, 1, 13, 13.88226128, 121.03636481, '2026-03-27 13:08:37', 0.00, '2026-03-27 05:08:37'),
(1198, 1, 13, 13.88228040, 121.03633973, '2026-03-27 13:08:56', 0.00, '2026-03-27 05:08:56'),
(1199, 1, 13, 13.88228207, 121.03635760, '2026-03-27 13:09:23', 0.00, '2026-03-27 05:09:23'),
(1200, 1, 13, 13.88229303, 121.03634922, '2026-03-27 13:09:37', 0.00, '2026-03-27 05:09:37'),
(1201, 1, 13, 13.88225114, 121.03640083, '2026-03-27 14:55:45', 0.00, '2026-03-27 06:55:45'),
(1202, 1, 13, 13.88225114, 121.03640083, '2026-03-27 14:55:45', 0.00, '2026-03-27 06:55:45'),
(1203, 1, 13, 13.88225114, 121.03640083, '2026-03-27 14:55:45', 0.00, '2026-03-27 06:55:45'),
(1204, 1, 13, 13.88225114, 121.03640083, '2026-03-27 14:55:46', 0.00, '2026-03-27 06:55:46'),
(1205, 1, 13, 13.88225114, 121.03640083, '2026-03-27 14:56:20', 0.00, '2026-03-27 06:56:20'),
(1206, 1, 13, 13.88224240, 121.03640189, '2026-03-27 14:56:48', 0.00, '2026-03-27 06:56:48'),
(1207, 1, 13, 13.88224897, 121.03639509, '2026-03-27 14:58:08', 0.00, '2026-03-27 06:58:08'),
(1208, 1, 13, 13.88223764, 121.03640377, '2026-03-27 14:59:16', 0.00, '2026-03-27 06:59:16'),
(1209, 1, 13, 13.88226327, 121.03639161, '2026-03-27 15:01:17', 0.00, '2026-03-27 07:01:17'),
(1210, 1, 13, 13.88236550, 121.03632350, '2026-03-30 08:41:02', 0.00, '2026-03-30 00:41:02'),
(1211, 1, 13, 13.88236550, 121.03632350, '2026-03-30 08:41:02', 0.00, '2026-03-30 00:41:02'),
(1212, 1, 13, 13.88236550, 121.03632350, '2026-03-30 08:41:02', 0.00, '2026-03-30 00:41:02'),
(1213, 1, 13, 13.88236550, 121.03632350, '2026-03-30 08:41:02', 0.00, '2026-03-30 00:41:02'),
(1214, 1, 13, 13.88213981, 121.03624026, '2026-03-30 08:41:49', 0.00, '2026-03-30 00:41:49'),
(1215, 1, 13, 13.88213981, 121.03624026, '2026-03-30 08:42:46', 0.00, '2026-03-30 00:42:46'),
(1216, 1, 13, 13.88236550, 121.03632350, '2026-03-30 10:57:35', 0.00, '2026-03-30 02:57:35'),
(1217, 1, 13, 13.88236550, 121.03632350, '2026-03-30 10:57:35', 0.00, '2026-03-30 02:57:35'),
(1218, 1, 13, 13.88236550, 121.03632350, '2026-03-30 10:57:35', 0.00, '2026-03-30 02:57:35'),
(1219, 1, 13, 13.88236550, 121.03632350, '2026-03-30 10:57:35', 0.00, '2026-03-30 02:57:35'),
(1220, 1, 13, 13.88236550, 121.03632350, '2026-03-30 11:26:58', 0.00, '2026-03-30 03:26:58'),
(1221, 1, 13, 13.88236550, 121.03632350, '2026-03-30 11:27:32', 0.00, '2026-03-30 03:27:32'),
(1222, 1, 13, 13.88236550, 121.03632350, '2026-03-30 11:28:48', 0.00, '2026-03-30 03:28:48'),
(1223, 1, 13, 13.88224970, 121.03639918, '2026-03-30 13:30:56', 0.00, '2026-03-30 05:30:56'),
(1224, 1, 13, 13.88224970, 121.03639918, '2026-03-30 13:30:56', 0.00, '2026-03-30 05:30:56'),
(1225, 1, 13, 13.88224970, 121.03639918, '2026-03-30 13:30:56', 0.00, '2026-03-30 05:30:56'),
(1226, 1, 13, 13.88224970, 121.03639918, '2026-03-30 13:30:56', 0.00, '2026-03-30 05:30:56'),
(1227, 1, 13, 13.88229295, 121.03632687, '2026-03-30 13:31:33', 0.00, '2026-03-30 05:31:33'),
(1228, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:32:49', 0.00, '2026-03-30 05:32:49'),
(1229, 1, 13, 13.88226327, 121.03639161, '2026-03-30 13:35:27', 0.00, '2026-03-30 05:35:27'),
(1230, 1, 13, 13.88226327, 121.03639161, '2026-03-30 13:35:32', 0.00, '2026-03-30 05:35:32'),
(1231, 1, 13, 13.88226327, 121.03639161, '2026-03-30 13:35:37', 0.00, '2026-03-30 05:35:37'),
(1232, 1, 13, 13.88226327, 121.03639161, '2026-03-30 13:35:44', 0.00, '2026-03-30 05:35:44'),
(1233, 1, 13, 13.88226327, 121.03639161, '2026-03-30 13:35:48', 0.00, '2026-03-30 05:35:48'),
(1234, 1, 13, 13.88226327, 121.03639161, '2026-03-30 13:35:52', 0.00, '2026-03-30 05:35:52'),
(1235, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:36:38', 0.00, '2026-03-30 05:36:38'),
(1236, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:36:51', 0.00, '2026-03-30 05:36:51'),
(1237, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:36:52', 0.00, '2026-03-30 05:36:52'),
(1238, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:37:09', 0.00, '2026-03-30 05:37:09'),
(1239, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:37:38', 0.00, '2026-03-30 05:37:38'),
(1240, 1, 13, 13.88229415, 121.03632823, '2026-03-30 13:38:34', 0.00, '2026-03-30 05:38:34'),
(1241, 1, 13, 13.88229415, 121.03632823, '2026-03-30 13:38:42', 0.00, '2026-03-30 05:38:42'),
(1242, 1, 13, 13.88231738, 121.03629772, '2026-03-30 13:39:07', 0.00, '2026-03-30 05:39:07'),
(1243, 1, 13, 13.88229574, 121.03631787, '2026-03-30 13:39:22', 0.00, '2026-03-30 05:39:22'),
(1244, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:39:48', 0.00, '2026-03-30 05:39:48'),
(1245, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:39:57', 0.00, '2026-03-30 05:39:57'),
(1246, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:39:57', 0.00, '2026-03-30 05:39:57'),
(1247, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:39:57', 0.00, '2026-03-30 05:39:57'),
(1248, 1, 13, 13.88228040, 121.03633973, '2026-03-30 13:39:57', 0.00, '2026-03-30 05:39:57'),
(1249, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:32', 0.00, '2026-03-30 05:40:32'),
(1250, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:36', 0.00, '2026-03-30 05:40:36'),
(1251, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:36', 0.00, '2026-03-30 05:40:36'),
(1252, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:36', 0.00, '2026-03-30 05:40:36'),
(1253, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:36', 0.00, '2026-03-30 05:40:36'),
(1254, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:55', 0.00, '2026-03-30 05:40:55'),
(1255, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:55', 0.00, '2026-03-30 05:40:55'),
(1256, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:55', 0.00, '2026-03-30 05:40:55'),
(1257, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:40:59', 0.00, '2026-03-30 05:40:59'),
(1258, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:41:27', 0.00, '2026-03-30 05:41:27'),
(1259, 1, 13, 13.88224240, 121.03640189, '2026-03-30 13:41:37', 0.00, '2026-03-30 05:41:37'),
(1260, 1, 13, 13.88224897, 121.03639509, '2026-03-30 13:42:02', 0.00, '2026-03-30 05:42:02'),
(1261, 1, 13, 13.88223950, 121.03640669, '2026-03-30 13:43:02', 0.00, '2026-03-30 05:43:02'),
(1262, 1, 13, 13.88223524, 121.03640673, '2026-03-30 13:43:15', 0.00, '2026-03-30 05:43:15'),
(1263, 1, 13, 13.88223524, 121.03640673, '2026-03-30 13:43:15', 0.00, '2026-03-30 05:43:15'),
(1264, 1, 13, 13.88223524, 121.03640673, '2026-03-30 13:43:15', 0.00, '2026-03-30 05:43:15'),
(1265, 1, 13, 13.88223524, 121.03640673, '2026-03-30 13:43:15', 0.00, '2026-03-30 05:43:15'),
(1266, 1, 13, 13.88223524, 121.03640673, '2026-03-30 13:43:58', 0.00, '2026-03-30 05:43:58'),
(1267, 1, 13, 13.88226128, 121.03636481, '2026-03-30 13:44:15', 0.00, '2026-03-30 05:44:15'),
(1268, 1, 13, 13.88226128, 121.03636481, '2026-03-30 13:44:33', 0.00, '2026-03-30 05:44:33'),
(1269, 1, 13, 13.88226128, 121.03636481, '2026-03-30 13:44:33', 0.00, '2026-03-30 05:44:33'),
(1270, 1, 13, 13.88226128, 121.03636481, '2026-03-30 13:44:34', 0.00, '2026-03-30 05:44:34'),
(1271, 1, 13, 13.88226128, 121.03636481, '2026-03-30 13:44:34', 0.00, '2026-03-30 05:44:34'),
(1272, 1, 13, 13.88226128, 121.03636481, '2026-03-30 13:45:05', 0.00, '2026-03-30 05:45:05'),
(1273, 1, 13, 13.88226128, 121.03636481, '2026-03-30 13:45:44', 0.00, '2026-03-30 05:45:44'),
(1274, 1, 13, 13.88221962, 121.03641884, '2026-03-31 09:02:50', 0.00, '2026-03-31 01:02:50'),
(1275, 1, 13, 13.88221962, 121.03641884, '2026-03-31 09:02:50', 0.00, '2026-03-31 01:02:50'),
(1276, 1, 13, 13.88221962, 121.03641884, '2026-03-31 09:02:50', 0.00, '2026-03-31 01:02:50'),
(1277, 1, 13, 13.88221962, 121.03641884, '2026-03-31 09:02:50', 0.00, '2026-03-31 01:02:50'),
(1278, 1, 13, 13.88231212, 121.03631034, '2026-03-31 14:42:14', 0.00, '2026-03-31 06:42:14'),
(1279, 1, 13, 13.88231212, 121.03631034, '2026-03-31 14:42:14', 0.00, '2026-03-31 06:42:14'),
(1280, 1, 13, 13.88231212, 121.03631034, '2026-03-31 14:42:14', 0.00, '2026-03-31 06:42:14'),
(1281, 1, 13, 13.88231212, 121.03631034, '2026-03-31 14:42:15', 0.00, '2026-03-31 06:42:15'),
(1282, 1, 13, 13.88226078, 121.03636311, '2026-03-31 15:05:21', 0.00, '2026-03-31 07:05:21'),
(1283, 1, 13, 13.88226078, 121.03636311, '2026-03-31 15:05:21', 0.00, '2026-03-31 07:05:21'),
(1284, 1, 13, 13.88226078, 121.03636311, '2026-03-31 15:05:21', 0.00, '2026-03-31 07:05:21'),
(1285, 1, 13, 13.88226078, 121.03636311, '2026-03-31 15:05:21', 0.00, '2026-03-31 07:05:21'),
(1286, 1, 13, 13.88224595, 121.03637296, '2026-03-31 15:05:34', 0.00, '2026-03-31 07:05:34'),
(1287, 1, 13, 13.88224595, 121.03637296, '2026-03-31 15:06:53', 0.00, '2026-03-31 07:06:53'),
(1288, 1, 13, 13.88225114, 121.03640083, '2026-03-31 15:19:24', 0.00, '2026-03-31 07:19:24'),
(1289, 1, 13, 13.88223884, 121.03640497, '2026-03-31 15:19:50', 0.00, '2026-03-31 07:19:50'),
(1290, 1, 13, 13.88224580, 121.03641453, '2026-03-31 15:20:29', 0.00, '2026-03-31 07:20:29'),
(1291, 1, 13, 13.88224580, 121.03641453, '2026-03-31 15:21:54', 0.00, '2026-03-31 07:21:54'),
(1292, 1, 13, 13.88224580, 121.03641453, '2026-03-31 15:21:57', 0.00, '2026-03-31 07:21:57'),
(1293, 1, 13, 13.88226261, 121.03639715, '2026-03-31 15:25:05', 0.00, '2026-03-31 07:25:05'),
(1294, 1, 13, 13.88223884, 121.03640497, '2026-03-31 15:25:30', 0.00, '2026-03-31 07:25:30'),
(1295, 1, 13, 13.88223356, 121.03640831, '2026-03-31 15:27:53', 0.00, '2026-03-31 07:27:53'),
(1296, 1, 13, 13.88223524, 121.03640673, '2026-03-31 15:28:06', 0.00, '2026-03-31 07:28:06'),
(1297, 1, 13, 13.88223524, 121.03640673, '2026-03-31 15:28:41', 0.00, '2026-03-31 07:28:41'),
(1298, 1, 13, 13.88223524, 121.03640673, '2026-03-31 15:28:52', 0.00, '2026-03-31 07:28:52'),
(1299, 1, 13, 13.88223884, 121.03640497, '2026-03-31 15:30:52', 0.00, '2026-03-31 07:30:52'),
(1300, 1, 13, 13.88226327, 121.03639161, '2026-03-31 15:34:54', 0.00, '2026-03-31 07:34:54'),
(1301, 1, 13, 13.88223884, 121.03640497, '2026-03-31 15:35:06', 0.00, '2026-03-31 07:35:06'),
(1302, 1, 13, 13.88223884, 121.03640497, '2026-03-31 15:36:07', 0.00, '2026-03-31 07:36:07'),
(1303, 1, 13, 13.88224548, 121.03640462, '2026-03-31 15:39:35', 0.00, '2026-03-31 07:39:35'),
(1304, 1, 13, 13.81729909, 121.13630355, '2026-03-31 21:53:59', 0.00, '2026-03-31 13:53:59'),
(1305, 1, 13, 13.81729909, 121.13630355, '2026-03-31 21:53:59', 0.00, '2026-03-31 13:53:59'),
(1306, 1, 13, 13.81729909, 121.13630355, '2026-03-31 21:53:59', 0.00, '2026-03-31 13:53:59'),
(1307, 1, 13, 13.81729909, 121.13630355, '2026-03-31 21:54:00', 0.00, '2026-03-31 13:54:00'),
(1308, 1, 13, 13.81729639, 121.13631616, '2026-03-31 21:54:13', 0.00, '2026-03-31 13:54:13'),
(1309, 1, 13, 13.81729639, 121.13631616, '2026-03-31 21:54:22', 0.00, '2026-03-31 13:54:22'),
(1310, 1, 13, 13.81729639, 121.13631616, '2026-03-31 21:54:22', 0.00, '2026-03-31 13:54:22'),
(1311, 1, 13, 13.81729639, 121.13631616, '2026-03-31 21:54:22', 0.00, '2026-03-31 13:54:22'),
(1312, 1, 13, 13.81729639, 121.13631616, '2026-03-31 21:54:22', 0.00, '2026-03-31 13:54:22'),
(1313, 1, 13, 13.81731531, 121.13638887, '2026-03-31 21:54:25', 0.00, '2026-03-31 13:54:25'),
(1314, 1, 13, 13.81731531, 121.13638887, '2026-03-31 21:55:33', 0.00, '2026-03-31 13:55:33'),
(1315, 1, 13, 13.81731531, 121.13638887, '2026-03-31 21:55:33', 0.00, '2026-03-31 13:55:33'),
(1316, 1, 13, 13.81731531, 121.13638887, '2026-03-31 21:55:34', 0.00, '2026-03-31 13:55:34'),
(1317, 1, 13, 13.81731531, 121.13638887, '2026-03-31 21:55:34', 0.00, '2026-03-31 13:55:34'),
(1318, 1, 13, 10.33713050, 125.13961980, '2026-03-31 22:15:23', 0.00, '2026-03-31 14:15:23'),
(1319, 1, 13, 10.33713050, 125.13961980, '2026-03-31 22:15:23', 0.00, '2026-03-31 14:15:23'),
(1320, 1, 13, 10.33713050, 125.13961980, '2026-03-31 22:15:23', 0.00, '2026-03-31 14:15:23'),
(1321, 1, 13, 13.88900494, 120.97926170, '2026-03-31 22:39:21', 0.00, '2026-03-31 14:39:21'),
(1322, 1, 13, 13.88896329, 120.97926375, '2026-03-31 22:39:21', 0.00, '2026-03-31 14:39:21'),
(1323, 1, 13, 13.88900494, 120.97926170, '2026-03-31 22:39:22', 0.00, '2026-03-31 14:39:22'),
(1324, 1, 13, 13.88900494, 120.97926170, '2026-03-31 22:39:22', 0.00, '2026-03-31 14:39:22'),
(1325, 1, 13, 13.88900494, 120.97926170, '2026-03-31 22:39:22', 0.00, '2026-03-31 14:39:22'),
(1326, 1, 13, 13.88900494, 120.97926170, '2026-03-31 22:39:22', 0.00, '2026-03-31 14:39:22'),
(1327, 1, 13, 13.88236123, 121.03631983, '2026-04-01 07:19:32', 0.00, '2026-03-31 23:19:32'),
(1328, 1, 13, 13.88236123, 121.03631983, '2026-04-01 07:19:32', 0.00, '2026-03-31 23:19:32'),
(1329, 1, 13, 13.88236123, 121.03631983, '2026-04-01 07:19:32', 0.00, '2026-03-31 23:19:32'),
(1330, 1, 13, 13.88236123, 121.03631983, '2026-04-01 07:19:32', 0.00, '2026-03-31 23:19:32'),
(1331, 1, 13, 13.88239712, 121.03616089, '2026-04-01 07:19:58', 0.00, '2026-03-31 23:19:58'),
(1332, 1, 13, 13.88243410, 121.03614985, '2026-04-01 07:25:01', 0.00, '2026-03-31 23:25:01'),
(1333, 1, 13, 13.88242743, 121.03615902, '2026-04-01 07:25:13', 0.00, '2026-03-31 23:25:13'),
(1334, 1, 13, 13.88239712, 121.03616089, '2026-04-01 07:25:46', 0.00, '2026-03-31 23:25:46'),
(1335, 1, 13, 13.88231424, 121.03630587, '2026-04-01 07:28:34', 0.00, '2026-03-31 23:28:34'),
(1336, 1, 13, 13.88239712, 121.03616089, '2026-04-01 07:30:58', 0.00, '2026-03-31 23:30:58'),
(1337, 1, 13, 13.88242743, 121.03615902, '2026-04-01 07:33:09', 0.00, '2026-03-31 23:33:09'),
(1338, 1, 13, 13.88227332, 121.03615209, '2026-04-01 07:34:49', 0.00, '2026-03-31 23:34:49'),
(1339, 1, 13, 13.88227332, 121.03615209, '2026-04-01 07:35:23', 0.00, '2026-03-31 23:35:23'),
(1340, 1, 13, 13.88227332, 121.03615209, '2026-04-01 07:35:39', 0.00, '2026-03-31 23:35:39'),
(1341, 1, 13, 13.88236646, 121.03627117, '2026-04-01 13:46:43', 0.00, '2026-04-01 05:46:43'),
(1342, 1, 13, 13.88236646, 121.03627117, '2026-04-01 13:46:43', 0.00, '2026-04-01 05:46:43'),
(1343, 1, 13, 13.88236645, 121.03627117, '2026-04-01 13:46:43', 0.00, '2026-04-01 05:46:43'),
(1344, 1, 13, 13.88236645, 121.03627117, '2026-04-01 13:46:43', 0.00, '2026-04-01 05:46:43'),
(1345, 1, 13, 13.88236646, 121.03627117, '2026-04-01 13:46:43', 0.00, '2026-04-01 05:46:43'),
(1346, 1, 13, 13.88223100, 121.03642300, '2026-04-06 14:13:05', 0.00, '2026-04-06 06:13:05'),
(1347, 1, 13, 13.88223100, 121.03642300, '2026-04-06 14:13:05', 0.00, '2026-04-06 06:13:05'),
(1348, 1, 13, 13.88223100, 121.03642300, '2026-04-06 14:13:05', 0.00, '2026-04-06 06:13:05'),
(1349, 1, 13, 13.88223100, 121.03642300, '2026-04-06 14:13:05', 0.00, '2026-04-06 06:13:05'),
(1350, 1, 13, 13.88223100, 121.03642300, '2026-04-06 14:13:11', 0.00, '2026-04-06 06:13:11'),
(1351, 1, 13, 13.88223100, 121.03642300, '2026-04-06 14:13:11', 0.00, '2026-04-06 06:13:11'),
(1352, 1, 13, 13.88223100, 121.03642300, '2026-04-06 14:13:11', 0.00, '2026-04-06 06:13:11'),
(1353, 1, 13, 13.88223100, 121.03642300, '2026-04-06 14:13:11', 0.00, '2026-04-06 06:13:11'),
(1354, 1, 13, 13.88221409, 121.03641496, '2026-04-06 14:13:20', 0.00, '2026-04-06 06:13:20'),
(1355, 1, 13, 13.88212556, 121.03634659, '2026-04-06 14:13:34', 0.00, '2026-04-06 06:13:34'),
(1356, 1, 13, 13.88212556, 121.03634659, '2026-04-06 14:13:52', 0.00, '2026-04-06 06:13:52'),
(1357, 1, 13, 13.88215342, 121.03624403, '2026-04-06 15:52:41', 0.00, '2026-04-06 07:52:41'),
(1358, 1, 13, 13.88215342, 121.03624403, '2026-04-06 15:52:41', 0.00, '2026-04-06 07:52:41'),
(1359, 1, 13, 13.88215342, 121.03624403, '2026-04-06 15:52:41', 0.00, '2026-04-06 07:52:41'),
(1360, 1, 13, 13.88215342, 121.03624403, '2026-04-06 15:52:41', 0.00, '2026-04-06 07:52:41'),
(1361, 1, 13, 13.86323113, 120.99473771, '2026-04-06 20:26:15', 0.00, '2026-04-06 12:26:15'),
(1362, 1, 13, 13.86328369, 120.99473755, '2026-04-06 20:26:15', 0.00, '2026-04-06 12:26:15'),
(1363, 1, 13, 13.86328369, 120.99473755, '2026-04-06 20:26:15', 0.00, '2026-04-06 12:26:15'),
(1364, 1, 13, 13.86328369, 120.99473755, '2026-04-06 20:26:15', 0.00, '2026-04-06 12:26:15'),
(1365, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:06', 0.00, '2026-04-06 23:04:06'),
(1366, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:06', 0.00, '2026-04-06 23:04:06'),
(1367, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:06', 0.00, '2026-04-06 23:04:06'),
(1368, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:06', 0.00, '2026-04-06 23:04:06'),
(1369, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:11', 0.00, '2026-04-06 23:04:11'),
(1370, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:11', 0.00, '2026-04-06 23:04:11'),
(1371, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:11', 0.00, '2026-04-06 23:04:11'),
(1372, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:11', 0.00, '2026-04-06 23:04:11'),
(1373, 1, 13, 13.88215342, 121.03624403, '2026-04-07 07:04:33', 0.00, '2026-04-06 23:04:33'),
(1374, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:04:59', 0.00, '2026-04-06 23:04:59'),
(1375, 1, 13, 13.88212437, 121.03623666, '2026-04-07 07:13:06', 0.00, '2026-04-06 23:13:06'),
(1376, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:14:08', 0.00, '2026-04-06 23:14:08'),
(1377, 1, 13, 13.88215342, 121.03624403, '2026-04-07 07:15:35', 0.00, '2026-04-06 23:15:35'),
(1378, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:15:59', 0.00, '2026-04-06 23:15:59'),
(1379, 1, 13, 13.88212437, 121.03623666, '2026-04-07 07:16:12', 0.00, '2026-04-06 23:16:12'),
(1380, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:17:15', 0.00, '2026-04-06 23:17:15'),
(1381, 1, 13, 13.88231424, 121.03630587, '2026-04-07 07:18:42', 0.00, '2026-04-06 23:18:42'),
(1382, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:19:17', 0.00, '2026-04-06 23:19:17'),
(1383, 1, 13, 13.88231424, 121.03630587, '2026-04-07 07:21:25', 0.00, '2026-04-06 23:21:25'),
(1384, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:22:04', 0.00, '2026-04-06 23:22:04'),
(1385, 1, 13, 13.88231424, 121.03630587, '2026-04-07 07:29:00', 0.00, '2026-04-06 23:29:00'),
(1386, 1, 13, 13.88231424, 121.03630587, '2026-04-07 07:29:05', 0.00, '2026-04-06 23:29:05'),
(1387, 1, 13, 13.88231424, 121.03630587, '2026-04-07 07:29:47', 0.00, '2026-04-06 23:29:47'),
(1388, 1, 13, 13.88231234, 121.03630742, '2026-04-07 07:32:36', 0.00, '2026-04-06 23:32:36'),
(1389, 1, 13, 13.88212437, 121.03623666, '2026-04-07 07:32:49', 0.00, '2026-04-06 23:32:49'),
(1390, 1, 13, 13.88231234, 121.03630742, '2026-04-07 07:34:58', 0.00, '2026-04-06 23:34:58'),
(1391, 1, 13, 13.88231234, 121.03630742, '2026-04-07 07:36:17', 0.00, '2026-04-06 23:36:17'),
(1392, 1, 13, 13.88231234, 121.03630742, '2026-04-07 07:36:43', 0.00, '2026-04-06 23:36:43'),
(1393, 1, 13, 13.88212437, 121.03623666, '2026-04-07 07:36:56', 0.00, '2026-04-06 23:36:56'),
(1394, 1, 13, 13.88236550, 121.03632350, '2026-04-07 07:39:41', 0.00, '2026-04-06 23:39:41'),
(1395, 1, 13, 13.88236550, 121.03632350, '2026-04-07 07:40:04', 0.00, '2026-04-06 23:40:04'),
(1396, 1, 13, 13.88236550, 121.03632350, '2026-04-07 07:40:25', 0.00, '2026-04-06 23:40:25'),
(1397, 1, 13, 13.88236550, 121.03632350, '2026-04-07 07:40:32', 0.00, '2026-04-06 23:40:32'),
(1398, 1, 13, 13.88236550, 121.03632350, '2026-04-07 07:41:00', 0.00, '2026-04-06 23:41:00'),
(1399, 1, 13, 13.88236550, 121.03632350, '2026-04-07 07:41:00', 0.00, '2026-04-06 23:41:00'),
(1400, 1, 13, 13.88236550, 121.03632350, '2026-04-07 07:41:00', 0.00, '2026-04-06 23:41:00'),
(1401, 1, 13, 13.88231234, 121.03630742, '2026-04-07 07:41:02', 0.00, '2026-04-06 23:41:02'),
(1402, 1, 13, 13.88212437, 121.03623666, '2026-04-07 07:41:14', 0.00, '2026-04-06 23:41:14'),
(1403, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:41:39', 0.00, '2026-04-06 23:41:39'),
(1404, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:41:54', 0.00, '2026-04-06 23:41:54'),
(1405, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:41:54', 0.00, '2026-04-06 23:41:54'),
(1406, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:41:54', 0.00, '2026-04-06 23:41:54'),
(1407, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:41:55', 0.00, '2026-04-06 23:41:55'),
(1408, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:42:27', 0.00, '2026-04-06 23:42:27'),
(1409, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:42:34', 0.00, '2026-04-06 23:42:34'),
(1410, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:42:43', 0.00, '2026-04-06 23:42:43'),
(1411, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:42:53', 0.00, '2026-04-06 23:42:53'),
(1412, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:42:53', 0.00, '2026-04-06 23:42:53'),
(1413, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:42:53', 0.00, '2026-04-06 23:42:53'),
(1414, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:42:53', 0.00, '2026-04-06 23:42:53'),
(1415, 1, 13, 13.88213810, 121.03624042, '2026-04-07 07:42:56', 0.00, '2026-04-06 23:42:56'),
(1416, 1, 13, 13.88236123, 121.03631983, '2026-04-07 07:43:17', 0.00, '2026-04-06 23:43:17'),
(1417, 1, 13, 13.88236550, 121.03632350, '2026-04-07 07:43:41', 0.00, '2026-04-06 23:43:41'),
(1418, 1, 13, 13.88213810, 121.03624042, '2026-04-07 10:41:51', 0.00, '2026-04-07 02:41:51'),
(1419, 1, 13, 13.88213810, 121.03624042, '2026-04-07 10:41:51', 0.00, '2026-04-07 02:41:51'),
(1420, 1, 13, 13.88213810, 121.03624042, '2026-04-07 10:41:51', 0.00, '2026-04-07 02:41:51'),
(1421, 1, 13, 13.88213810, 121.03624042, '2026-04-07 10:41:51', 0.00, '2026-04-07 02:41:51'),
(1422, 1, 13, 13.88236550, 121.03632350, '2026-04-07 10:46:05', 0.00, '2026-04-07 02:46:05'),
(1423, 1, 13, 13.88236550, 121.03632350, '2026-04-07 10:46:08', 0.00, '2026-04-07 02:46:08'),
(1424, 1, 13, 13.88236550, 121.03632350, '2026-04-07 10:46:19', 0.00, '2026-04-07 02:46:19'),
(1425, 1, 13, 13.88231234, 121.03630742, '2026-04-07 10:46:43', 0.00, '2026-04-07 02:46:43'),
(1426, 1, 13, 13.88231234, 121.03630742, '2026-04-07 10:47:06', 0.00, '2026-04-07 02:47:06'),
(1427, 1, 13, 13.88231424, 121.03630587, '2026-04-07 10:47:47', 0.00, '2026-04-07 02:47:47'),
(1428, 1, 13, 13.88231424, 121.03630587, '2026-04-07 10:48:00', 0.00, '2026-04-07 02:48:00'),
(1429, 1, 13, 13.88231424, 121.03630587, '2026-04-07 10:48:00', 0.00, '2026-04-07 02:48:00'),
(1430, 1, 13, 13.88231424, 121.03630587, '2026-04-07 10:48:00', 0.00, '2026-04-07 02:48:00'),
(1431, 1, 13, 13.88213981, 121.03624026, '2026-04-07 11:13:17', 0.00, '2026-04-07 03:13:17'),
(1432, 1, 25, 13.88221782, 121.03630307, '2026-04-13 08:35:23', 0.00, '2026-04-13 00:35:23'),
(1433, 1, 25, 13.88221782, 121.03630307, '2026-04-13 08:35:23', 0.00, '2026-04-13 00:35:23'),
(1434, 1, 25, 13.88221782, 121.03630307, '2026-04-13 08:35:23', 0.00, '2026-04-13 00:35:23'),
(1435, 1, 25, 13.88221782, 121.03630307, '2026-04-13 08:35:23', 0.00, '2026-04-13 00:35:23'),
(1436, 1, 25, 13.88219374, 121.03628784, '2026-04-13 08:35:36', 0.00, '2026-04-13 00:35:36'),
(1437, 1, 25, 13.88218418, 121.03628437, '2026-04-13 08:35:48', 0.00, '2026-04-13 00:35:48'),
(1438, 1, 25, 13.88213291, 121.03627430, '2026-04-13 08:36:54', 0.00, '2026-04-13 00:36:54'),
(1439, 1, 25, 13.88213847, 121.03627654, '2026-04-13 08:37:47', 0.00, '2026-04-13 00:37:47'),
(1440, 1, 25, 13.88214807, 121.03628455, '2026-04-13 08:38:00', 0.00, '2026-04-13 00:38:00'),
(1441, 1, 25, 13.88220946, 121.03629608, '2026-04-13 08:42:54', 0.00, '2026-04-13 00:42:54'),
(1442, 1, 25, 13.88220170, 121.03629415, '2026-04-13 08:43:07', 0.00, '2026-04-13 00:43:07'),
(1443, 1, 25, 13.88213852, 121.03627874, '2026-04-13 08:43:31', 0.00, '2026-04-13 00:43:31'),
(1444, 1, 25, 13.88214225, 121.03627968, '2026-04-13 08:45:13', 0.00, '2026-04-13 00:45:13'),
(1445, 1, 25, 13.88215807, 121.03629035, '2026-04-13 08:45:51', 0.00, '2026-04-13 00:45:51'),
(1446, 1, 25, 13.88214807, 121.03628455, '2026-04-13 08:46:03', 0.00, '2026-04-13 00:46:03'),
(1447, 1, 25, 13.88213291, 121.03627430, '2026-04-13 08:47:07', 0.00, '2026-04-13 00:47:07'),
(1448, 1, 25, 13.88214225, 121.03627968, '2026-04-13 08:48:24', 0.00, '2026-04-13 00:48:24'),
(1449, 1, 25, 13.88225886, 121.03644698, '2026-04-13 08:48:40', 0.00, '2026-04-13 00:48:40'),
(1450, 1, 25, 13.88225886, 121.03644698, '2026-04-13 08:48:40', 0.00, '2026-04-13 00:48:40'),
(1451, 1, 25, 13.88225886, 121.03644698, '2026-04-13 08:48:40', 0.00, '2026-04-13 00:48:40'),
(1452, 1, 25, 13.88225886, 121.03644698, '2026-04-13 08:48:40', 0.00, '2026-04-13 00:48:40'),
(1453, 1, 25, 13.88231000, 121.03649100, '2026-04-13 09:23:33', 0.00, '2026-04-13 01:23:33'),
(1454, 1, 25, 13.88224244, 121.03644173, '2026-04-13 09:23:35', 0.00, '2026-04-13 01:23:35'),
(1455, 1, 25, 13.88224244, 121.03644173, '2026-04-13 09:24:10', 0.00, '2026-04-13 01:24:10'),
(1456, 1, 25, 13.88215200, 121.03636512, '2026-04-13 09:30:01', 0.00, '2026-04-13 01:30:01'),
(1457, 1, 25, 13.88215200, 121.03636512, '2026-04-13 09:30:05', 0.00, '2026-04-13 01:30:05'),
(1458, 1, 25, 13.88215200, 121.03636512, '2026-04-13 09:30:13', 0.00, '2026-04-13 01:30:13'),
(1459, 1, 25, 13.88231000, 121.03649100, '2026-04-13 09:40:25', 0.00, '2026-04-13 01:40:25'),
(1460, 1, 25, 13.88231000, 121.03649100, '2026-04-13 09:40:25', 0.00, '2026-04-13 01:40:25'),
(1461, 1, 25, 13.88231000, 121.03649100, '2026-04-13 09:40:25', 0.00, '2026-04-13 01:40:25'),
(1462, 1, 25, 13.88231000, 121.03649100, '2026-04-13 09:40:25', 0.00, '2026-04-13 01:40:25'),
(1463, 1, 25, 13.88225886, 121.03644698, '2026-04-13 09:40:38', 0.00, '2026-04-13 01:40:38'),
(1464, 1, 25, 13.88215200, 121.03636512, '2026-04-13 09:42:24', 0.00, '2026-04-13 01:42:24'),
(1465, 1, 25, 13.88214198, 121.03635742, '2026-04-13 09:42:45', 0.00, '2026-04-13 01:42:45'),
(1466, 1, 25, 13.88215365, 121.03636862, '2026-04-13 09:43:19', 0.00, '2026-04-13 01:43:19'),
(1467, 1, 25, 13.88222449, 121.03642341, '2026-04-13 09:44:27', 0.00, '2026-04-13 01:44:27'),
(1468, 1, 25, 13.88215933, 121.03636619, '2026-04-13 09:45:18', 0.00, '2026-04-13 01:45:18'),
(1469, 1, 25, 13.88214990, 121.03635471, '2026-04-13 09:45:33', 0.00, '2026-04-13 01:45:33'),
(1470, 1, 25, 13.88231000, 121.03649100, '2026-04-13 11:08:37', 0.00, '2026-04-13 03:08:37'),
(1471, 1, 25, 13.88231000, 121.03649100, '2026-04-13 11:08:37', 0.00, '2026-04-13 03:08:37'),
(1472, 1, 25, 13.88231000, 121.03649100, '2026-04-13 11:08:37', 0.00, '2026-04-13 03:08:37'),
(1473, 1, 25, 13.88231000, 121.03649100, '2026-04-13 11:08:37', 0.00, '2026-04-13 03:08:37'),
(1474, 1, 25, 13.88206690, 121.03630211, '2026-04-13 11:09:34', 0.00, '2026-04-13 03:09:34'),
(1475, 1, 25, 13.88211268, 121.03633982, '2026-04-13 11:10:14', 0.00, '2026-04-13 03:10:14'),
(1476, 1, 25, 13.88211268, 121.03633982, '2026-04-13 11:10:46', 0.00, '2026-04-13 03:10:46'),
(1477, 1, 25, 13.88215365, 121.03636862, '2026-04-13 11:10:51', 0.00, '2026-04-13 03:10:51'),
(1478, 1, 25, 13.88215200, 121.03636512, '2026-04-13 11:12:51', 0.00, '2026-04-13 03:12:51'),
(1479, 1, 25, 13.88216156, 121.03637477, '2026-04-13 11:13:30', 0.00, '2026-04-13 03:13:30'),
(1480, 1, 25, 13.88216156, 121.03637477, '2026-04-13 11:14:58', 0.00, '2026-04-13 03:14:58'),
(1481, 1, 25, 13.88216156, 121.03637477, '2026-04-13 11:14:58', 0.00, '2026-04-13 03:14:58'),
(1482, 1, 25, 13.88216156, 121.03637477, '2026-04-13 11:14:58', 0.00, '2026-04-13 03:14:58'),
(1483, 1, 25, 13.88215365, 121.03636862, '2026-04-13 11:15:00', 0.00, '2026-04-13 03:15:00'),
(1484, 1, 25, 13.88222449, 121.03642341, '2026-04-13 11:16:00', 0.00, '2026-04-13 03:16:00'),
(1485, 1, 25, 13.88223925, 121.03643193, '2026-04-13 11:16:46', 0.00, '2026-04-13 03:16:46'),
(1486, 1, 25, 13.88222449, 121.03642341, '2026-04-13 11:16:59', 0.00, '2026-04-13 03:16:59'),
(1487, 1, 25, 13.88223735, 121.03643340, '2026-04-13 11:17:13', 0.00, '2026-04-13 03:17:13'),
(1488, 1, 25, 13.88222449, 121.03642341, '2026-04-13 11:17:41', 0.00, '2026-04-13 03:17:41'),
(1489, 1, 25, 13.88222449, 121.03642341, '2026-04-13 11:18:11', 0.00, '2026-04-13 03:18:11'),
(1490, 1, 25, 13.88231000, 121.03649100, '2026-04-13 11:24:59', 0.00, '2026-04-13 03:24:59'),
(1491, 1, 25, 13.88224244, 121.03644173, '2026-04-13 11:25:19', 0.00, '2026-04-13 03:25:19'),
(1492, 1, 25, 13.88223735, 121.03643340, '2026-04-13 11:26:27', 0.00, '2026-04-13 03:26:27'),
(1493, 1, 25, 13.88225886, 121.03644698, '2026-04-13 11:27:18', 0.00, '2026-04-13 03:27:18'),
(1494, 1, 25, 13.88223735, 121.03643340, '2026-04-13 11:28:06', 0.00, '2026-04-13 03:28:06'),
(1495, 1, 25, 13.88225886, 121.03644698, '2026-04-13 11:30:18', 0.00, '2026-04-13 03:30:18'),
(1496, 1, 25, 13.88226303, 121.03643796, '2026-04-13 11:37:59', 0.00, '2026-04-13 03:37:59'),
(1497, 1, 25, 13.88225886, 121.03644698, '2026-04-13 11:57:41', 0.00, '2026-04-13 03:57:41'),
(1498, 1, 25, 13.88224244, 121.03644173, '2026-04-13 11:57:53', 0.00, '2026-04-13 03:57:53'),
(1499, 1, 25, 13.88212280, 121.03634785, '2026-04-13 11:58:06', 0.00, '2026-04-13 03:58:06'),
(1500, 1, 25, 13.88215365, 121.03636862, '2026-04-13 11:58:46', 0.00, '2026-04-13 03:58:46'),
(1501, 1, 25, 13.88223735, 121.03643340, '2026-04-13 13:01:55', 0.00, '2026-04-13 05:01:55'),
(1502, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:44', 0.00, '2026-04-14 05:11:44'),
(1503, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:44', 0.00, '2026-04-14 05:11:44'),
(1504, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:44', 0.00, '2026-04-14 05:11:44'),
(1505, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:44', 0.00, '2026-04-14 05:11:44'),
(1506, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:44', 0.00, '2026-04-14 05:11:44'),
(1507, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:50', 0.00, '2026-04-14 05:11:50'),
(1508, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:50', 0.00, '2026-04-14 05:11:50'),
(1509, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:56', 0.00, '2026-04-14 05:11:56'),
(1510, 11, 28, 13.91149812, 122.13556390, '2026-04-14 13:11:56', 0.00, '2026-04-14 05:11:56'),
(1511, 11, 28, 13.91149835, 122.13556397, '2026-04-14 13:11:57', 0.00, '2026-04-14 05:11:57'),
(1512, 11, 28, 13.91149898, 122.13556301, '2026-04-14 13:11:58', 0.00, '2026-04-14 05:11:58'),
(1513, 11, 28, 13.91149735, 122.13556859, '2026-04-14 13:11:59', 0.00, '2026-04-14 05:11:59'),
(1514, 11, 28, 13.91149740, 122.13556960, '2026-04-14 13:12:00', 0.00, '2026-04-14 05:12:00'),
(1515, 11, 28, 13.91149623, 122.13557496, '2026-04-14 13:12:01', 0.00, '2026-04-14 05:12:01'),
(1516, 11, 28, 13.91149757, 122.13557736, '2026-04-14 13:12:01', 0.00, '2026-04-14 05:12:01'),
(1517, 11, 28, 13.91149754, 122.13557826, '2026-04-14 13:12:02', 0.00, '2026-04-14 05:12:02'),
(1518, 11, 28, 13.91149754, 122.13557826, '2026-04-14 13:12:02', 0.00, '2026-04-14 05:12:02'),
(1519, 11, 28, 13.91151883, 122.13560405, '2026-04-14 13:12:03', 0.00, '2026-04-14 05:12:03'),
(1520, 11, 28, 13.91158217, 122.13562979, '2026-04-14 13:12:04', 2.76, '2026-04-14 05:12:04'),
(1521, 11, 28, 13.91159429, 122.13562503, '2026-04-14 13:12:05', 2.76, '2026-04-14 05:12:05'),
(1522, 11, 28, 13.91160445, 122.13562752, '2026-04-14 13:12:06', 0.18, '2026-04-14 05:12:06'),
(1523, 11, 28, 13.91160247, 122.13562809, '2026-04-14 13:12:07', 0.34, '2026-04-14 05:12:07'),
(1524, 11, 28, 13.91160179, 122.13561998, '2026-04-14 13:12:08', 0.43, '2026-04-14 05:12:08'),
(1525, 11, 28, 13.91159884, 122.13560194, '2026-04-14 13:12:09', 0.43, '2026-04-14 05:12:09'),
(1526, 11, 28, 13.91160448, 122.13559597, '2026-04-14 13:12:10', 0.68, '2026-04-14 05:12:10'),
(1527, 11, 28, 13.91160435, 122.13559252, '2026-04-14 13:12:11', 0.66, '2026-04-14 05:12:11'),
(1528, 11, 28, 13.91160345, 122.13559030, '2026-04-14 13:12:12', 0.68, '2026-04-14 05:12:12'),
(1529, 11, 28, 13.91160251, 122.13558856, '2026-04-14 13:12:13', 1.33, '2026-04-14 05:12:13'),
(1530, 11, 28, 13.91160226, 122.13558596, '2026-04-14 13:12:14', 2.45, '2026-04-14 05:12:14'),
(1531, 11, 28, 13.91160104, 122.13558411, '2026-04-14 13:12:15', 2.35, '2026-04-14 05:12:15'),
(1532, 11, 28, 13.91160081, 122.13558330, '2026-04-14 13:12:16', 2.45, '2026-04-14 05:12:16'),
(1533, 11, 28, 13.91160034, 122.13558117, '2026-04-14 13:12:18', 1.74, '2026-04-14 05:12:18'),
(1534, 11, 28, 13.91159996, 122.13557940, '2026-04-14 13:12:18', 1.74, '2026-04-14 05:12:18'),
(1535, 11, 28, 13.91159866, 122.13557373, '2026-04-14 13:12:19', 1.29, '2026-04-14 05:12:19'),
(1536, 11, 28, 13.91159777, 122.13557139, '2026-04-14 13:12:20', 1.16, '2026-04-14 05:12:20'),
(1537, 11, 28, 13.91159607, 122.13556627, '2026-04-14 13:12:21', 0.85, '2026-04-14 05:12:21'),
(1538, 11, 28, 13.91159526, 122.13556303, '2026-04-14 13:12:22', 0.85, '2026-04-14 05:12:22'),
(1539, 11, 28, 13.91159328, 122.13555465, '2026-04-14 13:12:23', 0.52, '2026-04-14 05:12:23'),
(1540, 11, 28, 13.91159328, 122.13555380, '2026-04-14 13:12:24', 0.52, '2026-04-14 05:12:24'),
(1541, 11, 28, 13.91159186, 122.13554595, '2026-04-14 13:12:25', 0.51, '2026-04-14 05:12:25'),
(1542, 11, 28, 13.91159057, 122.13553697, '2026-04-14 13:12:26', 0.77, '2026-04-14 05:12:26'),
(1543, 11, 28, 13.91158588, 122.13551693, '2026-04-14 13:12:27', 0.77, '2026-04-14 05:12:27'),
(1544, 11, 28, 13.91160082, 122.13554532, '2026-04-14 13:12:28', 1.12, '2026-04-14 05:12:28'),
(1545, 11, 28, 13.91160216, 122.13554639, '2026-04-14 13:12:29', 0.79, '2026-04-14 05:12:29'),
(1546, 11, 28, 13.91161104, 122.13555486, '2026-04-14 13:12:30', 2.17, '2026-04-14 05:12:30'),
(1547, 11, 28, 13.91161814, 122.13556274, '2026-04-14 13:12:31', 1.57, '2026-04-14 05:12:31'),
(1548, 11, 28, 13.91162738, 122.13557873, '2026-04-14 13:12:32', 1.57, '2026-04-14 05:12:32'),
(1549, 11, 28, 13.91162060, 122.13558137, '2026-04-14 13:12:33', 1.05, '2026-04-14 05:12:33'),
(1550, 11, 28, 13.91162207, 122.13558287, '2026-04-14 13:12:34', 1.05, '2026-04-14 05:12:34'),
(1551, 11, 28, 13.91162306, 122.13558389, '2026-04-14 13:12:35', 0.00, '2026-04-14 05:12:35'),
(1552, 11, 28, 13.91162310, 122.13558409, '2026-04-14 13:12:36', 0.80, '2026-04-14 05:12:36'),
(1553, 11, 28, 13.91162339, 122.13558466, '2026-04-14 13:12:37', 1.39, '2026-04-14 05:12:37'),
(1554, 11, 28, 13.91162338, 122.13558456, '2026-04-14 13:12:38', 1.39, '2026-04-14 05:12:38'),
(1555, 11, 28, 13.91162304, 122.13558090, '2026-04-14 13:12:39', 0.44, '2026-04-14 05:12:39'),
(1556, 11, 28, 13.91162074, 122.13557930, '2026-04-14 13:12:40', 0.90, '2026-04-14 05:12:40'),
(1557, 11, 28, 13.91161930, 122.13557693, '2026-04-14 13:12:41', 1.35, '2026-04-14 05:12:41'),
(1558, 11, 28, 13.91161390, 122.13556839, '2026-04-14 13:12:42', 1.47, '2026-04-14 05:12:42'),
(1559, 11, 28, 13.91161216, 122.13556734, '2026-04-14 13:12:43', 1.72, '2026-04-14 05:12:43'),
(1560, 11, 28, 13.91160944, 122.13556389, '2026-04-14 13:12:44', 0.24, '2026-04-14 05:12:44'),
(1561, 11, 28, 13.91160874, 122.13556250, '2026-04-14 13:12:45', 0.42, '2026-04-14 05:12:45'),
(1562, 11, 28, 13.91160350, 122.13555589, '2026-04-14 13:12:46', 1.25, '2026-04-14 05:12:46'),
(1563, 11, 28, 13.91160305, 122.13555538, '2026-04-14 13:12:47', 1.25, '2026-04-14 05:12:47'),
(1564, 11, 28, 13.91160259, 122.13555486, '2026-04-14 13:12:48', 1.25, '2026-04-14 05:12:48'),
(1565, 11, 28, 13.91160214, 122.13555434, '2026-04-14 13:12:49', 1.25, '2026-04-14 05:12:49'),
(1566, 11, 28, 13.91160671, 122.13556075, '2026-04-14 13:12:50', 0.96, '2026-04-14 05:12:50'),
(1567, 11, 28, 13.91160682, 122.13556100, '2026-04-14 13:12:51', 0.96, '2026-04-14 05:12:51'),
(1568, 11, 28, 13.91160695, 122.13556088, '2026-04-14 13:13:01', 0.00, '2026-04-14 05:13:01'),
(1569, 11, 28, 13.91160222, 122.13556474, '2026-04-14 13:13:07', 0.00, '2026-04-14 05:13:07'),
(1570, 11, 28, 13.91160220, 122.13556476, '2026-04-14 13:13:07', 0.00, '2026-04-14 05:13:07'),
(1571, 11, 28, 13.91160185, 122.13556505, '2026-04-14 13:13:13', 0.00, '2026-04-14 05:13:13'),
(1572, 11, 28, 13.91160185, 122.13556505, '2026-04-14 13:13:19', 0.00, '2026-04-14 05:13:19'),
(1573, 11, 28, 13.91160185, 122.13556505, '2026-04-14 13:13:21', 0.00, '2026-04-14 05:13:21'),
(1574, 11, 28, 13.91160185, 122.13556505, '2026-04-14 13:13:24', 0.00, '2026-04-14 05:13:24'),
(1575, 11, 28, 13.91159916, 122.13556724, '2026-04-14 13:13:25', 0.00, '2026-04-14 05:13:25'),
(1576, 11, 28, 13.91159916, 122.13556724, '2026-04-14 13:13:25', 0.00, '2026-04-14 05:13:25'),
(1577, 11, 28, 13.91159216, 122.13557296, '2026-04-14 13:13:31', 0.00, '2026-04-14 05:13:31'),
(1578, 11, 28, 13.91159216, 122.13557296, '2026-04-14 13:13:31', 0.00, '2026-04-14 05:13:31'),
(1579, 11, 28, 13.91159215, 122.13557296, '2026-04-14 13:13:31', 0.00, '2026-04-14 05:13:31'),
(1580, 11, 28, 13.91159495, 122.13555743, '2026-04-14 13:13:34', 0.90, '2026-04-14 05:13:34'),
(1581, 11, 28, 13.91159499, 122.13555654, '2026-04-14 13:13:35', 0.91, '2026-04-14 05:13:35'),
(1582, 11, 28, 13.91159530, 122.13555517, '2026-04-14 13:13:36', 0.00, '2026-04-14 05:13:36'),
(1583, 11, 28, 13.91159719, 122.13554505, '2026-04-14 13:13:37', 0.91, '2026-04-14 05:13:37'),
(1584, 11, 28, 13.91159735, 122.13554469, '2026-04-14 13:13:37', 0.91, '2026-04-14 05:13:37'),
(1585, 11, 28, 13.91159735, 122.13554469, '2026-04-14 13:13:37', 0.91, '2026-04-14 05:13:37'),
(1586, 11, 28, 13.91159551, 122.13555408, '2026-04-14 13:13:38', 0.29, '2026-04-14 05:13:38'),
(1587, 11, 28, 13.91159545, 122.13555442, '2026-04-14 13:13:39', 0.29, '2026-04-14 05:13:39'),
(1588, 11, 28, 13.91159535, 122.13555459, '2026-04-14 13:13:40', 0.29, '2026-04-14 05:13:40'),
(1589, 11, 28, 13.91159528, 122.13555495, '2026-04-14 13:13:41', 0.29, '2026-04-14 05:13:41'),
(1590, 11, 28, 13.91159514, 122.13555550, '2026-04-14 13:13:42', 0.29, '2026-04-14 05:13:42'),
(1591, 11, 28, 13.91159502, 122.13555599, '2026-04-14 13:13:43', 0.00, '2026-04-14 05:13:43'),
(1592, 11, 28, 13.91159519, 122.13555554, '2026-04-14 13:13:43', 0.00, '2026-04-14 05:13:43'),
(1593, 11, 28, 13.91159524, 122.13555543, '2026-04-14 13:13:43', 0.00, '2026-04-14 05:13:43'),
(1594, 11, 28, 13.91159519, 122.13555552, '2026-04-14 13:13:44', 0.00, '2026-04-14 05:13:44'),
(1595, 11, 28, 13.91159258, 122.13557566, '2026-04-14 13:13:45', 0.00, '2026-04-14 05:13:45'),
(1596, 11, 28, 13.91160554, 122.13541728, '2026-04-14 13:13:46', 0.25, '2026-04-14 05:13:46'),
(1597, 11, 28, 13.91160830, 122.13547732, '2026-04-14 13:13:47', 0.26, '2026-04-14 05:13:47'),
(1598, 11, 28, 13.91161355, 122.13548799, '2026-04-14 13:13:48', 0.23, '2026-04-14 05:13:48'),
(1599, 11, 28, 13.91161492, 122.13550685, '2026-04-14 13:13:49', 0.18, '2026-04-14 05:13:49'),
(1600, 11, 28, 13.91160655, 122.13552641, '2026-04-14 13:13:49', 0.18, '2026-04-14 05:13:49'),
(1601, 11, 28, 13.91160373, 122.13553302, '2026-04-14 13:13:49', 0.18, '2026-04-14 05:13:49'),
(1602, 11, 28, 13.91160045, 122.13554025, '2026-04-14 13:13:50', 0.54, '2026-04-14 05:13:50'),
(1603, 11, 28, 13.91159711, 122.13554476, '2026-04-14 13:13:51', 0.59, '2026-04-14 05:13:51'),
(1604, 11, 28, 13.91159234, 122.13554268, '2026-04-14 13:13:52', 0.76, '2026-04-14 05:13:52'),
(1605, 11, 28, 13.91165050, 122.13534028, '2026-04-14 13:13:53', 1.00, '2026-04-14 05:13:53'),
(1606, 11, 28, 13.91159720, 122.13547755, '2026-04-14 13:13:54', 0.78, '2026-04-14 05:13:54'),
(1607, 11, 28, 13.91159694, 122.13548268, '2026-04-14 13:13:55', 0.78, '2026-04-14 05:13:55'),
(1608, 11, 28, 13.91159290, 122.13549474, '2026-04-14 13:13:55', 0.54, '2026-04-14 05:13:55'),
(1609, 11, 28, 13.91159366, 122.13551020, '2026-04-14 13:13:56', 0.29, '2026-04-14 05:13:56'),
(1610, 11, 28, 13.91159419, 122.13551091, '2026-04-14 13:13:57', 0.32, '2026-04-14 05:13:57'),
(1611, 11, 28, 13.91159528, 122.13552008, '2026-04-14 13:13:58', 0.30, '2026-04-14 05:13:58'),
(1612, 11, 28, 13.91159528, 122.13552008, '2026-04-14 13:14:03', 0.30, '2026-04-14 05:14:03'),
(1613, 11, 28, 13.91159528, 122.13552008, '2026-04-14 13:14:03', 0.30, '2026-04-14 05:14:03'),
(1614, 11, 28, 13.91159528, 122.13552008, '2026-04-14 13:14:03', 0.30, '2026-04-14 05:14:03'),
(1615, 11, 28, 13.91159528, 122.13552008, '2026-04-14 13:14:03', 0.30, '2026-04-14 05:14:03'),
(1616, 11, 28, 13.91159528, 122.13552008, '2026-04-14 13:14:03', 0.00, '2026-04-14 05:14:03'),
(1617, 11, 28, 13.91159369, 122.13553137, '2026-04-14 13:14:03', 0.00, '2026-04-14 05:14:03'),
(1618, 11, 28, 13.91159369, 122.13553137, '2026-04-14 13:14:03', 0.00, '2026-04-14 05:14:03'),
(1619, 11, 28, 13.91159278, 122.13553787, '2026-04-14 13:14:09', 0.00, '2026-04-14 05:14:09'),
(1620, 11, 28, 13.91159161, 122.13554624, '2026-04-14 13:14:16', 0.00, '2026-04-14 05:14:16'),
(1621, 11, 28, 13.91159068, 122.13555281, '2026-04-14 13:14:22', 0.00, '2026-04-14 05:14:22'),
(1622, 11, 28, 13.91159028, 122.13555567, '2026-04-14 13:14:28', 0.00, '2026-04-14 05:14:28'),
(1623, 11, 28, 13.91159006, 122.13555728, '2026-04-14 13:14:34', 0.00, '2026-04-14 05:14:34'),
(1624, 11, 28, 13.91158991, 122.13555831, '2026-04-14 13:14:40', 0.00, '2026-04-14 05:14:40'),
(1625, 11, 28, 13.91158991, 122.13555831, '2026-04-14 13:14:42', 0.00, '2026-04-14 05:14:42'),
(1626, 11, 28, 13.91158991, 122.13555831, '2026-04-14 13:14:42', 0.00, '2026-04-14 05:14:42'),
(1627, 11, 28, 13.91158991, 122.13555831, '2026-04-14 13:14:42', 0.00, '2026-04-14 05:14:42'),
(1628, 11, 28, 13.91158991, 122.13555831, '2026-04-14 13:14:42', 0.00, '2026-04-14 05:14:42'),
(1629, 11, 28, 13.91158991, 122.13555831, '2026-04-14 13:14:42', 0.00, '2026-04-14 05:14:42'),
(1630, 11, 28, 13.91158991, 122.13555831, '2026-04-14 13:14:42', 0.00, '2026-04-14 05:14:42'),
(1631, 11, 28, 13.91158991, 122.13555831, '2026-04-14 13:14:42', 0.00, '2026-04-14 05:14:42'),
(1632, 11, 28, 13.91158969, 122.13555989, '2026-04-14 13:14:48', 0.00, '2026-04-14 05:14:48'),
(1633, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:25', 0.00, '2026-04-14 05:39:25'),
(1634, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:25', 0.00, '2026-04-14 05:39:25');
INSERT INTO `driver_tracking` (`tracking_id`, `driver_id`, `trip_id`, `latitude`, `longitude`, `location_timestamp`, `speed_kmh`, `created_at`) VALUES
(1635, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:25', 0.00, '2026-04-14 05:39:25'),
(1636, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:25', 0.00, '2026-04-14 05:39:25'),
(1637, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:26', 0.00, '2026-04-14 05:39:26'),
(1638, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:26', 0.00, '2026-04-14 05:39:26'),
(1639, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:26', 0.00, '2026-04-14 05:39:26'),
(1640, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:26', 0.00, '2026-04-14 05:39:26'),
(1641, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:26', 0.00, '2026-04-14 05:39:26'),
(1642, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:26', 0.00, '2026-04-14 05:39:26'),
(1643, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:26', 0.00, '2026-04-14 05:39:26'),
(1644, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:39:32', 0.00, '2026-04-14 05:39:32'),
(1645, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:49:05', 0.00, '2026-04-14 05:49:05'),
(1646, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:49:05', 0.00, '2026-04-14 05:49:05'),
(1647, 11, 28, 13.91158926, 122.13556293, '2026-04-14 13:49:05', 0.00, '2026-04-14 05:49:05'),
(1648, 11, 28, 13.86363588, 120.99474369, '2026-04-14 21:15:19', 0.00, '2026-04-14 13:15:19'),
(1649, 11, 28, 13.86363588, 120.99474369, '2026-04-14 21:15:19', 0.00, '2026-04-14 13:15:19'),
(1650, 11, 28, 13.86366427, 120.99465135, '2026-04-14 21:15:58', 0.00, '2026-04-14 13:15:58'),
(1651, 11, 28, 13.86366085, 120.99466755, '2026-04-14 21:16:11', 0.00, '2026-04-14 13:16:11'),
(1652, 1, 25, 13.88220805, 121.03629580, '2026-04-15 09:30:16', 0.00, '2026-04-15 01:30:16'),
(1653, 1, 25, 13.88220805, 121.03629580, '2026-04-15 09:30:16', 0.00, '2026-04-15 01:30:16'),
(1654, 1, 25, 13.88220805, 121.03629580, '2026-04-15 09:30:16', 0.00, '2026-04-15 01:30:16'),
(1655, 1, 25, 13.88220805, 121.03629580, '2026-04-15 09:30:16', 0.00, '2026-04-15 01:30:16'),
(1656, 1, 25, 13.88220805, 121.03629580, '2026-04-15 09:30:38', 0.00, '2026-04-15 01:30:38'),
(1657, 1, 25, 13.88220805, 121.03629580, '2026-04-15 09:30:38', 0.00, '2026-04-15 01:30:38'),
(1658, 1, 25, 13.88220805, 121.03629580, '2026-04-15 09:30:38', 0.00, '2026-04-15 01:30:38'),
(1659, 1, 25, 13.88220805, 121.03629580, '2026-04-15 09:30:41', 0.00, '2026-04-15 01:30:41'),
(1660, 1, 25, 13.88223365, 121.03631194, '2026-04-15 09:47:17', 0.00, '2026-04-15 01:47:17'),
(1661, 1, 25, 13.88223365, 121.03631194, '2026-04-15 09:47:17', 0.00, '2026-04-15 01:47:17'),
(1662, 1, 25, 13.88223365, 121.03631194, '2026-04-15 09:47:17', 0.00, '2026-04-15 01:47:17'),
(1663, 1, 25, 13.88223365, 121.03631194, '2026-04-15 09:47:17', 0.00, '2026-04-15 01:47:17'),
(1664, 1, 25, 13.88222183, 121.03630729, '2026-04-15 09:48:49', 0.00, '2026-04-15 01:48:49'),
(1665, 1, 25, 13.88215706, 121.03629015, '2026-04-15 09:49:01', 0.00, '2026-04-15 01:49:01'),
(1666, 1, 25, 13.88214126, 121.03627948, '2026-04-15 10:15:02', 0.00, '2026-04-15 02:15:02'),
(1667, 1, 25, 13.88214126, 121.03627948, '2026-04-15 10:15:02', 0.00, '2026-04-15 02:15:02'),
(1668, 1, 25, 13.88214126, 121.03627948, '2026-04-15 10:15:02', 0.00, '2026-04-15 02:15:02'),
(1669, 1, 25, 13.88214126, 121.03627948, '2026-04-15 10:15:02', 0.00, '2026-04-15 02:15:02'),
(1670, 1, 25, 13.88213196, 121.03627411, '2026-04-15 10:15:14', 0.00, '2026-04-15 02:15:14'),
(1671, 1, 25, 13.88223365, 121.03631194, '2026-04-15 10:24:36', 0.00, '2026-04-15 02:24:36'),
(1672, 1, 25, 13.88223365, 121.03631194, '2026-04-15 10:24:36', 0.00, '2026-04-15 02:24:36'),
(1673, 1, 25, 13.88223365, 121.03631194, '2026-04-15 10:24:36', 0.00, '2026-04-15 02:24:36'),
(1674, 1, 25, 13.88223365, 121.03631194, '2026-04-15 10:24:36', 0.00, '2026-04-15 02:24:36'),
(1675, 1, 25, 13.88216361, 121.03629296, '2026-04-15 10:26:35', 0.00, '2026-04-15 02:26:35'),
(1676, 1, 25, 13.88216361, 121.03629296, '2026-04-15 10:26:57', 0.00, '2026-04-15 02:26:57'),
(1677, 1, 25, 13.88216361, 121.03629296, '2026-04-15 10:26:57', 0.00, '2026-04-15 02:26:57'),
(1678, 1, 25, 13.88216361, 121.03629296, '2026-04-15 10:26:57', 0.00, '2026-04-15 02:26:57'),
(1679, 1, 25, 13.88216361, 121.03629296, '2026-04-15 10:26:57', 0.00, '2026-04-15 02:26:57'),
(1680, 1, 25, 13.88215318, 121.03628693, '2026-04-15 10:27:53', 0.00, '2026-04-15 02:27:53'),
(1681, 1, 25, 13.88220805, 121.03629580, '2026-04-15 10:43:13', 0.00, '2026-04-15 02:43:13'),
(1682, 1, 25, 13.88220805, 121.03629580, '2026-04-15 10:43:13', 0.00, '2026-04-15 02:43:13'),
(1683, 1, 25, 13.88220805, 121.03629580, '2026-04-15 10:43:13', 0.00, '2026-04-15 02:43:13'),
(1684, 1, 25, 13.88221641, 121.03630279, '2026-04-15 10:55:52', 0.00, '2026-04-15 02:55:52'),
(1685, 1, 25, 13.88221641, 121.03630279, '2026-04-15 10:55:52', 0.00, '2026-04-15 02:55:52'),
(1686, 1, 25, 13.88221641, 121.03630279, '2026-04-15 10:55:52', 0.00, '2026-04-15 02:55:52'),
(1687, 1, 25, 13.88221641, 121.03630279, '2026-04-15 10:55:52', 0.00, '2026-04-15 02:55:52'),
(1688, 1, 25, 13.88214126, 121.03627948, '2026-04-15 11:00:36', 0.00, '2026-04-15 03:00:36'),
(1689, 1, 25, 13.88214126, 121.03627948, '2026-04-15 11:00:36', 0.00, '2026-04-15 03:00:36'),
(1690, 1, 25, 13.88214126, 121.03627948, '2026-04-15 11:00:36', 0.00, '2026-04-15 03:00:36'),
(1691, 1, 25, 13.88214126, 121.03627948, '2026-04-15 11:00:36', 0.00, '2026-04-15 03:00:36'),
(1692, 1, 25, 13.88214126, 121.03627948, '2026-04-15 11:00:46', 0.00, '2026-04-15 03:00:46'),
(1693, 1, 25, 13.88214126, 121.03627948, '2026-04-15 11:00:46', 0.00, '2026-04-15 03:00:46'),
(1694, 1, 25, 13.88214126, 121.03627948, '2026-04-15 11:00:46', 0.00, '2026-04-15 03:00:46'),
(1695, 1, 25, 13.88214126, 121.03627948, '2026-04-15 11:00:50', 0.00, '2026-04-15 03:00:50'),
(1696, 1, 25, 13.88220805, 121.03629580, '2026-04-15 11:06:03', 0.00, '2026-04-15 03:06:03'),
(1697, 1, 25, 13.88220805, 121.03629580, '2026-04-15 11:06:03', 0.00, '2026-04-15 03:06:03'),
(1698, 1, 25, 13.88220805, 121.03629580, '2026-04-15 11:06:03', 0.00, '2026-04-15 03:06:03'),
(1699, 1, 25, 13.88220805, 121.03629580, '2026-04-15 11:06:03', 0.00, '2026-04-15 03:06:03'),
(1700, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:06:16', 0.00, '2026-04-15 03:06:16'),
(1701, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:07:40', 0.00, '2026-04-15 03:07:40'),
(1702, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:07:40', 0.00, '2026-04-15 03:07:40'),
(1703, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:07:40', 0.00, '2026-04-15 03:07:40'),
(1704, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:07:41', 0.00, '2026-04-15 03:07:41'),
(1705, 1, 25, 13.88220566, 121.03629864, '2026-04-15 11:08:15', 0.00, '2026-04-15 03:08:15'),
(1706, 1, 25, 13.88220566, 121.03629864, '2026-04-15 11:08:15', 0.00, '2026-04-15 03:08:15'),
(1707, 1, 25, 13.88220566, 121.03629864, '2026-04-15 11:08:15', 0.00, '2026-04-15 03:08:15'),
(1708, 1, 25, 13.88220566, 121.03629864, '2026-04-15 11:08:15', 0.00, '2026-04-15 03:08:15'),
(1709, 1, 25, 13.88218287, 121.03628411, '2026-04-15 11:08:27', 0.00, '2026-04-15 03:08:27'),
(1710, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:08:40', 0.00, '2026-04-15 03:08:40'),
(1711, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:08:47', 0.00, '2026-04-15 03:08:47'),
(1712, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:08:47', 0.00, '2026-04-15 03:08:47'),
(1713, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:08:47', 0.00, '2026-04-15 03:08:47'),
(1714, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:08:47', 0.00, '2026-04-15 03:08:47'),
(1715, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:09:26', 0.00, '2026-04-15 03:09:26'),
(1716, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:09:26', 0.00, '2026-04-15 03:09:26'),
(1717, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:09:26', 0.00, '2026-04-15 03:09:26'),
(1718, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:09:30', 0.00, '2026-04-15 03:09:30'),
(1719, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:09:40', 0.00, '2026-04-15 03:09:40'),
(1720, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:09:40', 0.00, '2026-04-15 03:09:40'),
(1721, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:09:40', 0.00, '2026-04-15 03:09:40'),
(1722, 1, 25, 13.88213196, 121.03627411, '2026-04-15 11:09:42', 0.00, '2026-04-15 03:09:42'),
(1723, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:11:14', 0.00, '2026-04-15 03:11:14'),
(1724, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:11:14', 0.00, '2026-04-15 03:11:14'),
(1725, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:11:14', 0.00, '2026-04-15 03:11:14'),
(1726, 1, 25, 13.88219238, 121.03628757, '2026-04-15 11:11:14', 0.00, '2026-04-15 03:11:14');

-- --------------------------------------------------------

--
-- Table structure for table `instructor_shifts`
--

CREATE TABLE `instructor_shifts` (
  `id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL,
  `shift_start` datetime NOT NULL,
  `shift_end` datetime DEFAULT NULL,
  `gps_active` tinyint(1) DEFAULT 0,
  `status` enum('active','ended','paused') DEFAULT 'active',
  `total_distance` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
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
(1, 1, 1, 169, -89, NULL, 3, '2026-03-10 01:32:15'),
(2, 1, 2, 80, -7, NULL, NULL, '2026-03-10 01:27:05'),
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
(0, 1, 4, 'out', 1, 'sales_order', 21, 8, '2026-02-19 00:54:13'),
(0, 1, 1, 'in', 20, 'purchase_order', 3, 3, '2026-02-24 00:09:28'),
(0, 1, 5, 'out', 5, 'sales_order', 30, 2, '2026-03-04 05:58:50'),
(0, 1, 1, 'out', 1, 'sales_order', 35, 2, '2026-03-06 00:49:10'),
(0, 1, 1, 'out', 1, 'pick_list', 25, 18, '2026-03-06 01:10:38'),
(0, 1, 1, 'out', 1, 'sales_order', 36, 2, '2026-03-06 01:44:13'),
(0, 1, 1, 'out', 1, 'pick_list', 26, 18, '2026-03-06 01:45:09'),
(0, 1, 1, 'out', 100, 'sales_order', 37, 2, '2026-03-06 07:57:48'),
(0, 1, 1, 'out', 100, 'pick_list', 27, 18, '2026-03-06 07:58:39'),
(0, 1, 1, 'out', 1, 'sales_order', 35, 16, '2026-03-09 00:56:24'),
(0, 1, 1, 'out', 1, 'sales_order', 35, 16, '2026-03-09 01:02:36'),
(0, 1, 1, 'out', 1, 'sales_order', 35, 16, '2026-03-09 01:08:21'),
(0, 1, 1, 'out', 2, 'sales_order', 38, 2, '2026-03-09 06:45:31'),
(0, 1, 2, 'out', 13, 'sales_order', 38, 2, '2026-03-09 06:45:31'),
(0, 1, 5, 'out', 25, 'sales_order', 38, 2, '2026-03-09 06:45:31'),
(0, 1, 6, 'out', 4, 'sales_order', 39, 2, '2026-03-09 23:45:51'),
(0, 1, 6, 'out', 1, 'sales_order', 41, 2, '2026-03-10 00:26:17'),
(0, 1, 6, 'out', 1, 'sales_order', 42, 2, '2026-03-10 00:44:46'),
(0, 1, 6, 'out', 1, 'pick_list', 31, 17, '2026-03-10 00:46:43'),
(0, 1, 6, 'out', 4, 'pick_list', 29, 17, '2026-03-10 00:47:15'),
(0, 1, 6, 'out', 2, 'sales_order', 40, 2, '2026-03-10 01:17:33'),
(0, 1, 6, 'out', 2, 'pick_list', 32, 17, '2026-03-10 01:25:50'),
(0, 1, 6, 'out', 1, 'pick_list', 30, 17, '2026-03-10 01:26:13'),
(0, 1, 2, 'out', 13, 'pick_list', 28, 17, '2026-03-10 01:27:05'),
(0, 1, 1, 'out', 2, 'pick_list', 28, 18, '2026-03-10 01:32:15'),
(0, 1, 5, 'out', 25, 'pick_list', 28, 3, '2026-03-10 01:33:02'),
(0, 1, 6, 'out', 5, 'sales_order', 43, 2, '2026-03-10 05:25:18'),
(0, 1, 6, 'out', 5, 'pick_list', 33, 17, '2026-03-10 05:26:38'),
(0, 1, 1, 'out', 10, 'sales_order', 29, 2, '2026-03-11 02:37:57'),
(0, 1, 2, 'out', 5, 'sales_order', 29, 2, '2026-03-11 02:37:57'),
(0, 1, 5, 'out', 12, 'sales_order', 29, 2, '2026-03-11 02:37:57'),
(0, 1, 6, 'out', 1, 'sales_order', 44, 2, '2026-03-31 13:47:21'),
(0, 1, 6, 'out', 1, 'sales_order', 44, 8, '2026-04-06 23:41:54'),
(0, 1, 6, 'out', 1, 'sales_order', 41, 8, '2026-04-07 02:48:00'),
(0, 1, 10, 'out', 2, 'sales_order', 58, 2, '2026-04-10 00:16:37'),
(0, 1, 10, 'out', 2, 'sales_order', 57, 2, '2026-04-10 00:17:37'),
(0, 1, 1, 'out', 1, 'sales_order', 59, 2, '2026-04-10 00:22:24'),
(0, 1, 10, 'out', 5, 'sales_order', 59, 2, '2026-04-10 00:22:24'),
(0, 4, 56, 'out', 3, 'sales_order', 63, 32, '2026-04-14 05:11:03'),
(0, 4, 56, 'out', 3, 'sales_order', 63, 34, '2026-04-14 05:14:42'),
(0, 3, 86, 'out', 50, 'sales_order', 70, 39, '2026-04-16 02:09:28'),
(0, 1, 10, 'out', 2, 'sales_order', 56, 2, '2026-04-16 11:48:59'),
(0, 10, 94, 'out', 100, 'sales_order', 75, 45, '2026-04-17 06:33:03'),
(0, 10, 95, 'out', 50, 'sales_order', 75, 45, '2026-04-17 06:33:03'),
(0, 1, 10, 'out', 500, 'sales_order', 79, 2, '2026-04-20 05:01:43'),
(0, 1, 1, 'out', 500, 'sales_order', 79, 2, '2026-04-20 05:01:43'),
(0, 1, 10, 'out', 500, 'sales_order', 79, 2, '2026-04-20 05:25:14'),
(0, 1, 1, 'out', 500, 'sales_order', 79, 2, '2026-04-20 05:25:14'),
(0, 1, 8, 'out', 5000, 'sales_order', 80, 2, '2026-04-20 05:58:44');

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
  `status` enum('pending','paid','cancelled','overdue') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`invoice_id`, `invoice_number`, `so_id`, `customer_id`, `branch_id`, `invoice_date`, `due_date`, `total_amount`, `status`, `created_at`, `updated_at`, `paid_at`, `paid_by`) VALUES
(10, 'INV-20260216-00026', 26, 6, 1, '2026-02-16', '2026-03-18', 1700.00, 'overdue', '2026-02-16 01:44:55', '2026-04-20 06:26:14', NULL, NULL),
(11, 'INV-20260216-00025', 25, 2, 1, '2026-02-16', '2026-03-18', 250.00, 'overdue', '2026-02-16 01:51:59', '2026-04-20 06:26:14', NULL, NULL),
(12, 'INV-20260216-00024', 24, 4, 1, '2026-02-16', '2026-03-18', 225.00, 'overdue', '2026-02-16 01:53:36', '2026-04-20 06:26:14', NULL, NULL),
(13, 'INV-20260216-00027', 27, 6, 1, '2026-02-16', '2026-03-18', 1000.00, 'overdue', '2026-02-16 06:24:51', '2026-04-20 06:26:14', NULL, NULL),
(15, 'INV-20260218-00022', 22, 2, 1, '2026-02-18', '2026-03-20', 325.00, 'overdue', '2026-02-18 03:55:27', '2026-04-20 06:26:14', NULL, NULL),
(16, 'INV-20260218-00021', 21, 4, 1, '2026-02-18', '2026-03-20', 75.00, 'paid', '2026-02-18 05:20:23', '2026-03-10 00:19:53', '2026-02-19 08:53:00', 8),
(17, 'INV-20260304-00030', 30, 4, 1, '2026-03-04', '2026-04-03', 1250.00, 'overdue', '2026-03-04 05:58:50', '2026-04-20 06:26:14', NULL, NULL),
(18, 'INV-20260305-00035', 35, 4, 1, '2026-03-05', '2026-04-04', 600.00, 'paid', '2026-03-06 00:49:10', '2026-03-10 00:19:53', '2026-03-09 09:08:00', 8),
(19, 'INV-20260305-00036', 36, 5, 1, '2026-03-05', '2026-04-04', 100.00, 'overdue', '2026-03-06 01:44:13', '2026-04-20 06:26:14', NULL, NULL),
(20, 'INV-20260306-00037', 37, 14, 1, '2026-03-06', '2026-04-05', 10000.00, 'overdue', '2026-03-06 07:57:48', '2026-04-20 06:26:14', NULL, NULL),
(21, 'INV-20260309-00038', 38, 6, 1, '2026-03-09', '2026-04-08', 10600.00, 'overdue', '2026-03-09 06:45:31', '2026-04-20 06:26:14', NULL, NULL),
(22, 'INV-20260309-00039', 39, 5, 1, '2026-03-09', '2026-04-08', 1920.00, 'overdue', '2026-03-09 23:45:51', '2026-04-20 06:26:14', NULL, NULL),
(23, 'INV-20260309-00041', 41, 5, 1, '2026-03-09', '2026-04-08', 480.00, 'paid', '2026-03-10 00:26:17', '2026-04-07 02:48:00', NULL, NULL),
(24, 'INV-20260309-00042', 42, 7, 1, '2026-03-09', '2026-04-08', 480.00, 'overdue', '2026-03-10 00:44:46', '2026-04-20 06:26:14', NULL, NULL),
(25, 'INV-20260309-00040', 40, 4, 1, '2026-03-09', '2026-04-08', 480.00, 'overdue', '2026-03-10 01:17:33', '2026-04-20 06:26:14', NULL, NULL),
(26, 'INV-20260310-00043', 43, 17, 1, '2026-03-10', '2026-04-09', 200.00, 'overdue', '2026-03-10 05:25:18', '2026-04-20 06:26:14', NULL, NULL),
(27, 'INV-20260310-00029', 29, 17, 1, '2026-03-10', '2026-04-09', 4750.00, 'overdue', '2026-03-11 02:37:57', '2026-04-20 06:26:14', NULL, NULL),
(28, 'INV-20260331-00044', 44, 4, 1, '2026-03-31', '2026-04-30', 240.00, 'paid', '2026-03-31 13:47:21', '2026-04-06 23:41:54', NULL, NULL),
(29, 'INV-20260410-00058', 58, 21, 1, '2026-04-10', '2026-05-10', 16800.00, 'pending', '2026-04-10 00:16:37', '2026-04-10 00:16:37', NULL, NULL),
(30, 'INV-20260410-00057', 57, 3, 1, '2026-04-10', '2026-05-10', 16800.00, 'pending', '2026-04-10 00:17:37', '2026-04-10 00:17:37', NULL, NULL),
(31, 'INV-20260410-00059', 59, 21, 1, '2026-04-10', '2026-05-10', 10150.00, 'pending', '2026-04-10 00:22:24', '2026-04-10 00:22:24', NULL, NULL),
(32, 'INV-20260414-00063', 63, 23, 4, '2026-04-14', '2026-05-14', 750.00, 'paid', '2026-04-14 05:11:03', '2026-04-14 05:14:42', NULL, NULL),
(33, 'INV-20260416-00070', 70, 35, 3, '2026-04-16', '2026-05-16', 18900.00, 'pending', '2026-04-16 02:09:28', '2026-04-16 02:09:28', NULL, NULL),
(34, 'INV-20260416-00056', 56, 3, 1, '2026-04-16', '2026-05-16', 16800.00, 'pending', '2026-04-16 11:48:59', '2026-04-16 11:48:59', NULL, NULL),
(35, 'INV-20260417-00075', 75, 55, 10, '2026-04-17', '2026-05-17', 56706.00, 'pending', '2026-04-17 06:33:03', '2026-04-17 06:33:03', NULL, NULL),
(36, 'INV-20260420-00079', 79, 6, 1, '2026-04-20', '2026-05-20', 332500.00, 'pending', '2026-04-20 05:01:43', '2026-04-20 05:01:43', NULL, NULL),
(37, 'INV-20260420-00079', 79, 6, 1, '2026-04-20', '2026-05-20', 332500.00, 'pending', '2026-04-20 05:25:14', '2026-04-20 05:25:14', NULL, NULL),
(38, 'INV-20260420-00080', 80, 16, 1, '2026-04-20', '2026-05-20', 95000.00, 'pending', '2026-04-20 05:58:44', '2026-04-20 05:58:44', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `item_id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `product_image_url` longtext DEFAULT NULL,
  `unit_conversions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `stock` int(11) DEFAULT NULL,
  `base_stock` int(11) DEFAULT 0,
  `base_unit_type` varchar(50) DEFAULT 'Piece',
  `unit_type` varchar(50) DEFAULT NULL,
  `default_unit_type_id` int(11) DEFAULT NULL,
  `default_uom_id` int(11) DEFAULT NULL,
  `smallest_uom_id` int(11) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `price_case` decimal(10,2) DEFAULT NULL,
  `price_inner_pack` decimal(10,2) DEFAULT NULL,
  `price_box` decimal(10,2) DEFAULT NULL,
  `price_carton` decimal(10,2) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `stock_in_default_uom` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`item_id`, `item_code`, `item_name`, `description`, `product_image_url`, `unit_conversions`, `category`, `stock`, `base_stock`, `base_unit_type`, `unit_type`, `default_unit_type_id`, `default_uom_id`, `smallest_uom_id`, `unit_price`, `price_case`, `price_inner_pack`, `price_box`, `price_carton`, `reorder_level`, `status`, `created_at`, `created_by`, `updated_at`, `updated_by`, `branch_id`, `stock_in_default_uom`) VALUES
(1, 'ITEM001', 'Product A', 'high-performance, durable building material designed for diverse construction applications, ranging from infrastructure to residential projects', 'item_1772756693_69aa1ed5710d7.jpg', NULL, 'Cement', 32372, 0, 'Piece', 'Bag', NULL, 6, 1, 350.00, 4200.00, 2100.00, 8400.00, 16800.00, 50, 'active', '2026-02-10 01:38:25', NULL, '2026-04-20 05:25:14', NULL, 1, 33976.00),
(2, 'ITEM002', 'Product B', '', NULL, NULL, 'Oil', 90001, 0, 'Piece', 'piece', NULL, 1, 1, 150.00, 1800.00, 900.00, 3600.00, 7200.00, 30, 'active', '2026-02-10 01:38:25', NULL, '2026-04-11 05:23:06', NULL, 1, 90001.00),
(3, 'ITEM003', 'Product C', NULL, NULL, NULL, 'General', 145, 0, 'Piece', 'piece', NULL, 1, 1, 200.00, 2400.00, 1200.00, 4800.00, 9600.00, 25, 'active', '2026-02-10 01:38:25', NULL, '2026-04-11 05:23:06', NULL, 2, 145.00),
(4, 'ITEM004', 'Product D', NULL, NULL, NULL, 'General', 151, 0, 'Piece', 'piece', NULL, 1, 1, 75.00, 900.00, 450.00, 1800.00, 3600.00, 100, 'active', '2026-02-10 01:38:25', NULL, '2026-04-11 05:23:06', NULL, 2, 151.00),
(5, 'ITEM005', 'Product E', '', NULL, NULL, 'General', 30000, 0, 'Piece', 'Piece', NULL, 1, 1, 40.00, 480.00, 240.00, 960.00, 1920.00, 20, 'active', '2026-02-10 01:38:25', NULL, '2026-04-11 05:51:23', NULL, 1, 30000.00),
(6, 'ITEM006', '1 liter Oil', '', 'item_1772756764_69aa1f1c77688.jpg', NULL, 'Oil', 39919, 0, 'Piece', 'Piece', NULL, 1, 1, 120.00, 1440.00, 720.00, 2880.00, 5760.00, 50, 'active', '2026-03-06 00:26:03', NULL, '2026-04-20 13:02:44', NULL, 1, 39939.00),
(8, 'ITEM008', 'Coke Mismo', '', 'item_1775005933_69cc70ed66ce7.png', NULL, 'General', 34952, 0, 'Piece', 'Piece', NULL, 1, 1, 20.00, 240.00, 120.00, 480.00, 960.00, 1000, 'active', '2026-04-01 01:12:13', NULL, '2026-04-20 05:58:44', NULL, 1, 39952.00),
(9, 'ITEM009', 'Mountain Dew', '', 'item_1775011794_69cc87d28f572.jpg', NULL, 'General', 9956, 0, 'Piece', 'Piece', NULL, 1, 1, 20.00, 240.00, 120.00, 480.00, 960.00, 1000, 'active', '2026-04-01 02:49:54', NULL, '2026-04-11 05:23:06', NULL, 1, 9956.00),
(10, 'ITEM010', 'Holcim', ' high-performance, durable building material designed for diverse construction applications, ranging from infrastructure to residential projects', 'item_1775011927_69cc88579d398.png', NULL, 'Cement', 13300, 0, 'Piece', 'Bag', NULL, 6, 1, 320.00, 3840.00, 1920.00, 7680.00, 15360.00, 1000, 'active', '2026-04-01 02:52:07', NULL, '2026-04-20 07:44:15', NULL, 1, 13300.00),
(43, 'ITEM011', 'Sting', '', NULL, NULL, 'General', 10000, 0, 'Piece', 'Piece', NULL, NULL, NULL, 20.00, 240.00, 120.00, 480.00, 960.00, 1000, 'active', '2026-04-11 09:22:58', NULL, '2026-04-11 09:22:58', NULL, 1, 0.00),
(44, 'ITEM012', 'Royal', 'softdrinks', NULL, NULL, 'General', 96000, 0, 'Piece', 'Piece', NULL, NULL, NULL, 20.00, 240.00, 120.00, 480.00, 960.00, 96, 'active', '2026-04-12 11:31:34', NULL, '2026-04-12 13:38:08', NULL, 1, 0.00),
(45, 'ITEM013', 'Sprite', 'Softdrinks', NULL, NULL, 'General', 96000, 0, 'Piece', 'Piece', NULL, NULL, NULL, 20.00, 240.00, 120.00, 480.00, 960.00, 96, 'active', '2026-04-12 12:11:22', 2, '2026-04-12 13:38:56', NULL, 1, 0.00),
(46, 'P58', 'Palm 58', 'Palm 58', NULL, NULL, 'Oil', 663, 0, 'Piece', 'Dozen', NULL, NULL, NULL, 375.00, 4500.00, 2250.00, 9000.00, 18000.00, 50, 'active', '2026-04-14 01:45:26', 32, '2026-04-14 05:32:26', NULL, 4, 0.00),
(47, 'P.1LTR', 'PALM 1LTR', 'PALM 1LTR', NULL, NULL, 'Oil', 491, 0, 'Piece', 'Piece', NULL, NULL, NULL, 100.00, 1200.00, 600.00, 2400.00, 4800.00, 50, 'active', '2026-04-14 01:53:11', 32, '2026-04-14 03:09:35', NULL, 4, 0.00),
(48, 'palm 1.3 kls', 'palm 1.3', 'palm 1.3', NULL, NULL, 'Oil', 293, 0, 'Piece', 'Piece', NULL, NULL, NULL, 140.00, 1680.00, 840.00, 3360.00, 6720.00, 50, 'active', '2026-04-14 01:59:01', 32, '2026-04-14 03:06:14', NULL, 4, 0.00),
(49, 'palm c 16 kls', 'palm 16 kls', 'palm 16 kls', NULL, NULL, 'Oil', 41, 0, 'Piece', 'container', NULL, NULL, NULL, 1551.00, 18612.00, 9306.00, 37224.00, 74448.00, 10, 'active', '2026-04-14 02:07:55', 32, '2026-04-14 02:07:55', NULL, 4, 0.00),
(50, '16.5 kls palm', 'palm 16.5', 'palm 16.5', NULL, NULL, 'Oil', 30, 0, 'Piece', 'container', NULL, NULL, NULL, 1604.00, 19248.00, 9624.00, 38496.00, 76992.00, 50, 'active', '2026-04-14 02:12:03', 32, '2026-04-14 02:16:25', NULL, 4, 0.00),
(51, 'Palm 16.75 kls', 'palm 16.75', 'palm 16.75', NULL, NULL, 'Oil', 71, 0, 'Piece', 'container', NULL, NULL, NULL, 1606.00, 19272.00, 9636.00, 38544.00, 77088.00, 50, 'active', '2026-04-14 02:15:07', 32, '2026-04-14 05:32:26', NULL, 4, 0.00),
(52, 'Palm 17 kls', 'Palm 17 kls', 'Palm 17 kls', NULL, NULL, 'Oil', 84, 0, 'Piece', 'container', NULL, NULL, NULL, 1580.00, 18960.00, 9480.00, 37920.00, 75840.00, 50, 'active', '2026-04-14 02:18:45', 32, '2026-04-14 02:18:45', NULL, 4, 0.00),
(53, 'C58', 'COCONUT 58', 'COCONUT 58', NULL, NULL, 'Oil', 502, 0, 'Piece', 'Dozen', NULL, NULL, NULL, 560.00, 6720.00, 3360.00, 13440.00, 26880.00, 50, 'active', '2026-04-14 02:22:20', 32, '2026-04-14 05:32:26', NULL, 4, 0.00),
(54, 'C.1LTR', 'C.1LTR', 'C.1LTR COCONUT', NULL, NULL, 'Oil', 675, 0, 'Piece', 'Piece', NULL, NULL, NULL, 150.00, 1800.00, 900.00, 3600.00, 7200.00, 50, 'active', '2026-04-14 02:26:26', 32, '2026-04-14 02:26:26', NULL, 4, 0.00),
(55, 'P. PER KILO', 'P.PER KILO', 'P.PER KILO', NULL, NULL, 'Oil', 0, 0, 'Piece', 'KLS.', NULL, NULL, NULL, 98.00, 1176.00, 588.00, 2352.00, 4704.00, 0, 'active', '2026-04-14 02:28:25', 32, '2026-04-14 02:28:25', NULL, 4, 0.00),
(56, 'C. 1.3', 'C. 1.3 KLS', 'C. 1.3 KLS COCONUT', NULL, NULL, 'Oil', 144, 0, 'Piece', 'Piece', NULL, NULL, NULL, 202.00, 2424.00, 1212.00, 4848.00, 9696.00, 50, 'active', '2026-04-14 02:34:17', 32, '2026-04-14 05:11:03', NULL, 4, 0.00),
(57, 'C. 16 KLS', 'C.16 KLS', 'C.16 KLS ', NULL, NULL, 'Oil', 64, 0, 'Piece', 'container', NULL, NULL, NULL, 2388.00, 28656.00, 14328.00, 57312.00, 114624.00, 50, 'active', '2026-04-14 02:40:48', 32, '2026-04-14 02:40:48', NULL, 4, 0.00),
(58, 'C. 16.75', 'C.16.75 KLS', 'COCONUT 16.75', NULL, NULL, 'Oil', 105, 0, 'Piece', 'container', NULL, NULL, NULL, 2494.00, 29928.00, 14964.00, 59856.00, 119712.00, 50, 'active', '2026-04-14 02:45:23', 32, '2026-04-14 02:45:23', NULL, 4, 0.00),
(59, 'C.16.5', 'C.16.5', 'C.16.5 COCONUT', NULL, NULL, 'Oil', 50, 0, 'Piece', 'container', NULL, NULL, NULL, 2457.00, 29484.00, 14742.00, 58968.00, 117936.00, 10, 'active', '2026-04-14 02:59:48', 32, '2026-04-14 02:59:48', NULL, 4, 0.00),
(60, 'C.17 KLS', 'C.17 KLS', 'C.17 KLS', NULL, NULL, 'Oil', 0, 0, 'Piece', 'container', NULL, NULL, NULL, 2531.00, 30372.00, 15186.00, 60744.00, 121488.00, 0, 'active', '2026-04-14 03:02:37', 32, '2026-04-14 03:02:37', NULL, 4, 0.00),
(61, 'C. KLS', 'C. KLS', 'C. KLS', NULL, NULL, 'Oil', 0, 0, 'Piece', 'KLS.', NULL, NULL, NULL, 148.00, 1776.00, 888.00, 3552.00, 7104.00, 0, 'active', '2026-04-14 03:12:37', 32, '2026-04-14 03:12:37', NULL, 4, 0.00),
(62, 'FM 11', 'FM-11', 'FM-11', NULL, NULL, 'MARGARINE', 185, 0, 'Piece', 'PAIL', NULL, NULL, NULL, 1060.00, 12720.00, 6360.00, 25440.00, 50880.00, 0, 'active', '2026-04-14 03:18:15', 32, '2026-04-14 05:32:26', NULL, 4, 0.00),
(63, 'FL-11', 'FL -11', 'FL -11', NULL, NULL, 'MARGARINE', 144, 0, 'Piece', 'PAIL', NULL, NULL, NULL, 1051.00, 12612.00, 6306.00, 25224.00, 50448.00, 0, 'active', '2026-04-14 03:19:46', 32, '2026-04-17 01:37:02', NULL, 4, 0.00),
(64, 'FM -40', 'FM -40', 'FM -40', NULL, NULL, 'MARGARINE', 140, 0, 'Piece', 'DRM', NULL, NULL, NULL, 3462.00, 41544.00, 20772.00, 83088.00, 166176.00, 0, 'active', '2026-04-14 03:21:54', 32, '2026-04-14 05:32:26', NULL, 4, 0.00),
(65, 'FL -40', 'FL -40', 'FL -40', NULL, NULL, 'MARGARINE', 153, 0, 'Piece', 'DRM', NULL, NULL, NULL, 3427.00, 41124.00, 20562.00, 82248.00, 164496.00, 0, 'active', '2026-04-14 03:23:17', 32, '2026-04-17 01:22:53', NULL, 4, 0.00),
(66, 'HXECO', 'Holcim Excel Ecoplanet', 'Holcim Excel Ecoplanet', NULL, NULL, 'Holcim', -2582, 0, 'Piece', 'Bag', NULL, NULL, NULL, 205.00, 2460.00, 1230.00, 4920.00, 9840.00, 0, 'active', '2026-04-15 07:52:12', 36, '2026-04-17 07:49:36', NULL, 7, 0.00),
(67, 'HT1T', 'Holcim Type 1 T', 'Holcim Type 1 T', NULL, NULL, 'Holcim', 0, 0, 'Piece', 'Bag', NULL, NULL, NULL, 1.00, 12.00, 6.00, 24.00, 48.00, 0, 'active', '2026-04-15 07:53:19', 36, '2026-04-15 07:53:19', NULL, 7, 0.00),
(68, 'HPT1', 'Holcim Premium Type 1', 'Holcim Premium Type 1', NULL, NULL, 'Holcim', 0, 0, 'Piece', 'Bag', NULL, NULL, NULL, 1.00, 12.00, 6.00, 24.00, 48.00, 0, 'active', '2026-04-15 07:53:56', 36, '2026-04-15 07:53:56', NULL, 7, 0.00),
(69, 'HSKMCT', 'Holcim Skimcoat', 'Holcim Skimcoat', NULL, NULL, 'Holcim', 0, 0, 'Piece', 'Bag', NULL, NULL, NULL, 400.00, 4800.00, 2400.00, 9600.00, 19200.00, 0, 'active', '2026-04-15 07:54:36', 36, '2026-04-15 07:54:36', NULL, 7, 0.00),
(70, 'HTLADHSV', 'Holcim Tile Adhesive', 'Holcim Tile Adhesive', NULL, NULL, 'Holcim', 0, 0, 'Piece', 'Bag', NULL, NULL, NULL, 235.00, 2820.00, 1410.00, 5640.00, 11280.00, 0, 'active', '2026-04-15 07:55:44', 36, '2026-04-15 07:55:44', NULL, 7, 0.00),
(71, 'CBRCT1', 'Cohaco Baraco Type 1', 'Cohaco Baraco Type 1', NULL, NULL, 'Cohaco', 0, 0, 'Piece', 'Bag', NULL, NULL, NULL, 1.00, 12.00, 6.00, 24.00, 48.00, 0, 'active', '2026-04-15 08:04:39', 36, '2026-04-15 08:04:39', NULL, 7, 0.00),
(72, 'CUNOT1', 'Cohaco Uno Type 1', 'Cohaco Uno Type 1', NULL, NULL, 'Cohaco', 0, 0, 'Piece', 'Bag', NULL, NULL, NULL, 205.00, 2460.00, 1230.00, 4920.00, 9840.00, 0, 'active', '2026-04-15 08:05:44', 36, '2026-04-15 08:05:44', NULL, 7, 0.00),
(73, 'P57.5', 'PALM 57.5', 'PALM 57.5', NULL, NULL, 'Oil', -500, 0, 'Piece', 'DOZEN', NULL, NULL, NULL, 360.00, 4320.00, 2160.00, 8640.00, 17280.00, 0, 'active', '2026-04-15 08:51:02', 35, '2026-04-15 08:57:23', NULL, 5, 0.00),
(75, 'P1.5', 'PALM 1.5', 'PALM 1.5', NULL, NULL, 'Oil', -14, 0, 'Piece', 'PC', NULL, NULL, NULL, 138.00, 1656.00, 828.00, 3312.00, 6624.00, 0, 'active', '2026-04-15 08:59:24', 35, '2026-04-15 09:05:05', NULL, 5, 0.00),
(76, 'FM40', 'FREETO MARGARINE 40KLS', 'FREETO MARGARINE 40KLS', NULL, NULL, 'MARGARINE', 0, 0, 'Piece', 'PIECE', NULL, NULL, NULL, 3392.00, 40704.00, 20352.00, 81408.00, 162816.00, 10, 'active', '2026-04-15 09:01:14', 35, '2026-04-16 01:20:28', NULL, 5, 0.00),
(77, 'FL40', 'FREETO LARD 40KLS', 'FREETO LARD 40KLS', NULL, NULL, 'LARD', 90, 0, 'Piece', 'PC', NULL, NULL, NULL, 3357.00, 40284.00, 20142.00, 80568.00, 161136.00, 0, 'active', '2026-04-16 01:15:04', 35, '2026-04-16 01:15:04', NULL, 5, 0.00),
(78, 'FCAN', 'FREETO CAN 16KLS', 'FREETO CAN 16KLS', NULL, NULL, 'FREETO CAN', 0, 0, 'Piece', 'PC', NULL, NULL, NULL, 1642.00, 19704.00, 9852.00, 39408.00, 78816.00, 0, 'active', '2026-04-16 01:17:34', 35, '2026-04-16 01:17:34', NULL, 5, 0.00),
(79, 'FM11', 'FREETO MARGARINE 11KLS', 'FREETO MARGARINE 11KLS', NULL, NULL, 'MARGARINE', 0, 0, 'Piece', 'PC', NULL, NULL, NULL, 1090.00, 13080.00, 6540.00, 26160.00, 52320.00, 0, 'active', '2026-04-16 01:19:15', 35, '2026-04-16 01:19:15', NULL, 5, 0.00),
(80, 'FL11', 'FREETO LARD 11KLS', 'FREETO LARD 11KLS', NULL, NULL, 'LARD', 114, 0, 'Piece', 'PC', NULL, NULL, NULL, 1081.00, 12972.00, 6486.00, 25944.00, 51888.00, 0, 'active', '2026-04-16 01:23:52', 35, '2026-04-16 01:23:52', NULL, 5, 0.00),
(81, 'P53', 'PALM 53 (PUNO)', 'PALM 53 (PUNO)', NULL, NULL, 'Oil', 0, 0, 'Piece', 'DOZEN', NULL, NULL, NULL, 405.00, 4860.00, 2430.00, 9720.00, 19440.00, 0, 'active', '2026-04-16 01:27:15', 35, '2026-04-16 01:27:15', NULL, 5, 0.00),
(82, 'P52', 'Palm Oil 52', 'Palm Oil 52', NULL, NULL, 'Oil', 1934, 0, 'Piece', 'Dozen', NULL, NULL, NULL, 388.00, 4656.00, 2328.00, 9312.00, 18624.00, 100, 'active', '2026-04-16 01:33:24', 39, '2026-04-16 01:33:24', NULL, 3, 0.00),
(83, 'P1.5x7', 'Palm 1.5x7', 'Palm 1.5x7', NULL, NULL, 'Oil', 942, 0, 'Piece', 'x7', NULL, NULL, NULL, 960.00, 11520.00, 5760.00, 23040.00, 46080.00, 100, 'active', '2026-04-16 01:38:05', 39, '2026-04-16 05:23:04', NULL, 3, 0.00),
(84, 'P52.5', 'Palm 52.5', 'Palm 52.5', NULL, NULL, 'Oil', 310, 0, 'Piece', 'Dozen', NULL, NULL, NULL, 393.00, 4716.00, 2358.00, 9432.00, 18864.00, 100, 'active', '2026-04-16 01:41:14', 39, '2026-04-16 01:41:14', NULL, 3, 0.00),
(85, 'P55', 'Palm 55', 'Palm 55', NULL, NULL, 'Oil', 100, 0, 'Piece', 'Dozen', NULL, NULL, NULL, 383.00, 4596.00, 2298.00, 9192.00, 18384.00, 50, 'active', '2026-04-16 01:43:57', 39, '2026-04-16 01:43:57', NULL, 3, 0.00),
(86, 'P56', 'Palm 56', 'Palm 56', NULL, NULL, 'Oil', 97, 0, 'Piece', 'Dozen', NULL, NULL, NULL, 377.00, 4524.00, 2262.00, 9048.00, 18096.00, 100, 'active', '2026-04-16 01:45:18', 39, '2026-04-16 05:23:04', NULL, 3, 0.00),
(87, 'P57', 'Palm 57', 'Palm 57', NULL, NULL, 'Oil', 177, 0, 'Piece', 'Dozen', NULL, NULL, NULL, 374.00, 4488.00, 2244.00, 8976.00, 17952.00, 50, 'active', '2026-04-16 01:46:26', 39, '2026-04-16 01:46:26', NULL, 3, 0.00),
(88, 'P1.3x7', 'Palm 1.3x7', 'Palm 1.3x7', NULL, NULL, 'Oil', 150, 0, 'Piece', 'Bundle', NULL, NULL, NULL, 899.00, 10788.00, 5394.00, 21576.00, 43152.00, 30, 'active', '2026-04-16 01:53:07', 39, '2026-04-16 01:53:07', NULL, 3, 0.00),
(89, 'P17', 'Palm 17kgs', 'Palm 17kgs', NULL, NULL, 'Oil', 244, 0, 'Piece', 'piece', NULL, NULL, NULL, 1560.00, 18720.00, 9360.00, 37440.00, 74880.00, 50, 'active', '2026-04-16 01:54:34', 39, '2026-04-16 01:54:34', NULL, 3, 0.00),
(90, 'P62.5', 'PALM 62.5', 'PALM 62.5', NULL, NULL, 'Oil', 0, 0, 'Piece', 'DOZEN', NULL, NULL, NULL, 348.00, 4176.00, 2088.00, 8352.00, 16704.00, 0, 'active', '2026-04-16 01:54:36', 35, '2026-04-16 01:54:36', NULL, 5, 0.00),
(91, 'P1.3', 'PALM 1.3', 'PALM 1.3', NULL, NULL, 'Oil', 0, 0, 'Piece', 'PC', NULL, NULL, NULL, 133.00, 1596.00, 798.00, 3192.00, 6384.00, 0, 'active', '2026-04-16 01:56:58', 35, '2026-04-16 01:56:58', NULL, 5, 0.00),
(92, 'P58', 'Palm 58', 'Palm 58', NULL, NULL, 'Oil', 318, 0, 'Piece', 'Dozen', NULL, NULL, NULL, 362.00, 4344.00, 2172.00, 8688.00, 17376.00, 99, 'active', '2026-04-16 02:01:12', 39, '2026-04-16 02:01:12', NULL, 3, 0.00),
(94, 'P52', 'Palm 52', 'Palm 52', NULL, NULL, 'Oil', 200, 0, 'Piece', 'dozen', NULL, NULL, NULL, 375.88, 4510.56, 2255.28, 9021.12, 18042.24, 50, 'active', '2026-04-17 05:53:29', 45, '2026-04-17 06:33:03', NULL, 10, 0.00),
(95, 'P52.5', 'PALM 52.5', 'PALM 52.5', NULL, NULL, 'Oil', 0, 0, 'Piece', 'dozen', 30, NULL, NULL, 374.12, 4489.44, 2244.72, 8978.88, 17957.76, 50, 'active', '2026-04-17 05:57:13', 45, '2026-04-20 03:08:39', NULL, 10, 0.00),
(96, 'P53', 'PALM 53', 'PALM 53', NULL, NULL, 'Oil', 100, 0, 'Piece', 'dozen', 30, NULL, NULL, 368.84, 4426.08, 2213.04, 8852.16, 17704.32, 50, 'active', '2026-04-17 05:58:37', 45, '2026-04-20 03:09:09', NULL, 10, 0.00),
(97, 'P55', 'PALM 55', 'PALM 55', NULL, NULL, 'Oil', 100, 0, 'Piece', 'dozen', 30, NULL, NULL, 363.55, 4362.60, 2181.30, 8725.20, 17450.40, 50, 'active', '2026-04-17 06:05:54', 45, '2026-04-20 03:09:45', NULL, 10, 0.00),
(98, 'P56', 'PALM 56', 'PALM 56', NULL, NULL, 'Oil', 100, 0, 'Piece', 'dozen', 30, NULL, NULL, 354.74, 4256.88, 2128.44, 8513.76, 17027.52, 50, 'active', '2026-04-17 06:07:19', 45, '2026-04-20 03:10:23', NULL, 10, 0.00),
(99, 'P57', 'PALM 57', 'PALM 57', NULL, NULL, 'OIL', 100, 0, 'Piece', 'dozen', 30, NULL, NULL, 352.10, 4225.20, 2112.60, 8450.40, 16900.80, 50, 'active', '2026-04-17 06:08:19', 45, '2026-04-20 03:10:56', NULL, 10, 0.00),
(100, 'P58', 'PALM 58', 'PALM 58', NULL, NULL, 'OIL', 100, 0, 'Piece', 'dozen', 30, NULL, NULL, 362.00, 4344.00, 2172.00, 8688.00, 17376.00, 50, 'active', '2026-04-17 06:09:45', 45, '2026-04-20 03:11:42', NULL, 10, 0.00),
(101, 'P61', 'PALM 61', 'PALM  61', NULL, NULL, 'Oil', 100, 0, 'Piece', 'dozen', 30, NULL, NULL, 331.83, 3981.96, 1990.98, 7963.92, 15927.84, 50, 'active', '2026-04-17 06:16:48', 45, '2026-04-20 03:06:23', NULL, 10, 0.00),
(102, 'P63', 'PALM 63', 'PALM 63', NULL, NULL, 'OIL', 100, 0, 'Piece', 'dozen', 30, NULL, NULL, 315.10, 3781.20, 1890.60, 7562.40, 15124.80, 50, 'active', '2026-04-17 06:20:28', 45, '2026-04-20 03:04:29', NULL, 10, 0.00),
(103, 'P1.3X6', 'PALM 1.3X6', 'PALM 1.3X6', NULL, NULL, 'Oil', 100, 0, 'Piece', 'BUNDLE', 31, NULL, NULL, 729.17, 8750.04, 4375.02, 17500.08, 35000.16, 50, 'active', '2026-04-17 06:23:52', 45, '2026-04-20 02:08:50', NULL, 10, 0.00),
(104, 'P1.3X10', 'PALM 1.3X10', 'PALM 1.3X10', NULL, NULL, 'Oil', 100, 0, 'Piece', 'BUNDLE', 31, NULL, NULL, 1241.67, 14900.04, 7450.02, 29800.08, 59600.16, 50, 'active', '2026-04-17 06:25:49', 45, '2026-04-20 02:07:24', NULL, 10, 0.00),
(105, 'P1.5X10 REG', 'PALM 1.5X10 REGULAR', 'PALM 1.5X10 REGULAR', NULL, NULL, 'Oil', 100, 0, 'Piece', 'BUNDLE', 31, NULL, NULL, 1214.08, 14568.96, 7284.48, 29137.92, 58275.84, 50, 'active', '2026-04-17 06:38:51', 45, '2026-04-20 03:07:29', NULL, 10, 0.00),
(106, 'P1.5X10 FULL', 'PALM 1.5X10 FULL', 'PALM 1.5X10 FULL', NULL, NULL, 'Oil', 100, 0, 'Piece', 'BUNDLE', NULL, NULL, NULL, 1293.37, 15520.44, 7760.22, 31040.88, 62081.76, 50, 'active', '2026-04-17 06:41:09', 45, '2026-04-17 06:52:38', NULL, 10, 0.00),
(107, 'P1.5X10 ORDINARY', 'PALM 1.5X10 (13.64)', 'PALM 1.5X10 (13.64)', NULL, NULL, 'Oil', 100, 0, 'Piece', 'BUNDLE', NULL, NULL, NULL, 1261.65, 15139.80, 7569.90, 30279.60, 60559.20, 50, 'active', '2026-04-17 06:43:40', 45, '2026-04-17 06:50:42', NULL, 10, 0.00),
(108, 'P17', 'PALM CONTAINER 17KLS.', 'PALM CONTAINER 17KLS.', NULL, NULL, 'Oil', 300, 0, 'Piece', 'piece', NULL, NULL, NULL, 1503.17, 18038.04, 9019.02, 36076.08, 72152.16, 100, 'active', '2026-04-17 06:45:58', 45, '2026-04-17 06:45:58', NULL, 10, 0.00),
(112, 'Palm molive', 'Palm molive', 'Palm molive', NULL, NULL, 'General', 1200, 0, 'Piece', 'Piece', 1, NULL, NULL, 6.00, 72.00, 36.00, 144.00, 288.00, 20, 'active', '2026-04-20 07:37:25', 2, '2026-04-20 07:37:25', NULL, 1, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `item_images`
--

CREATE TABLE `item_images` (
  `image_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `image_order` int(11) DEFAULT 0,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_images`
--

INSERT INTO `item_images` (`image_id`, `item_id`, `image_path`, `image_order`, `is_primary`, `created_at`) VALUES
(1, 14, 'item_14_1775634532_0.jpg', 0, 1, '2026-04-08 07:48:52'),
(2, 14, 'item_14_1775634532_1.webp', 1, 0, '2026-04-08 07:48:52'),
(3, 15, 'item_15_1775653696_0.jpg', 0, 1, '2026-04-08 13:08:16'),
(4, 15, 'item_15_1775653696_1.png', 1, 0, '2026-04-08 13:08:16'),
(5, 16, 'item_16_1775776884_0.jpg', 0, 1, '2026-04-09 23:21:24'),
(6, 16, 'item_16_1775776884_1.png', 1, 0, '2026-04-09 23:21:24'),
(7, 17, 'item_17_1775778191_0.jpg', 0, 1, '2026-04-09 23:43:11'),
(8, 17, 'item_17_1775778191_1.png', 1, 0, '2026-04-09 23:43:11'),
(9, 18, 'item_18_1775791710_0.jpg', 0, 1, '2026-04-10 03:28:30'),
(10, 18, 'item_18_1775791710_1.webp', 1, 0, '2026-04-10 03:28:30'),
(11, 19, 'item_19_1775799922_0.jpg', 0, 1, '2026-04-10 05:45:22'),
(12, 19, 'item_19_1775799922_1.png', 1, 0, '2026-04-10 05:45:22'),
(13, 20, 'item_20_1775802495_0.jpg', 0, 1, '2026-04-10 06:28:15'),
(14, 20, 'item_20_1775802495_1.webp', 1, 0, '2026-04-10 06:28:15'),
(15, 21, 'item_21_1775807306_0.jpg', 0, 1, '2026-04-10 07:48:26'),
(16, 21, 'item_21_1775807306_1.png', 1, 0, '2026-04-10 07:48:26'),
(17, 22, 'item_22_1775807670_0.jpg', 0, 1, '2026-04-10 07:54:30'),
(18, 22, 'item_22_1775807670_1.png', 1, 0, '2026-04-10 07:54:30'),
(19, 23, 'item_23_1775827448_0.jpg', 0, 1, '2026-04-10 13:24:08'),
(20, 23, 'item_23_1775827448_1.png', 1, 0, '2026-04-10 13:24:08'),
(21, 24, 'item_24_1775828943_0.jpg', 0, 1, '2026-04-10 13:49:03'),
(22, 24, 'item_24_1775828943_1.webp', 1, 0, '2026-04-10 13:49:03'),
(23, 26, 'item_26_1775830166_0.jpg', 0, 1, '2026-04-10 14:09:26'),
(24, 26, 'item_26_1775830166_1.webp', 1, 0, '2026-04-10 14:09:26'),
(25, 27, 'item_27_1775830834_0.jpg', 0, 1, '2026-04-10 14:20:34'),
(26, 27, 'item_27_1775830834_1.webp', 1, 0, '2026-04-10 14:20:34'),
(27, 28, 'item_28_1775831771_0.jpg', 0, 1, '2026-04-10 14:36:11'),
(28, 28, 'item_28_1775831771_1.webp', 1, 0, '2026-04-10 14:36:11'),
(29, 29, 'item_29_1775832259_0.webp', 0, 1, '2026-04-10 14:44:19'),
(30, 30, 'item_30_1775836131_0.jpg', 0, 1, '2026-04-10 15:48:51'),
(31, 30, 'item_30_1775836131_1.png', 1, 0, '2026-04-10 15:48:51'),
(32, 31, 'item_31_1775837528_0.jpg', 0, 1, '2026-04-10 16:12:08'),
(33, 31, 'item_31_1775837528_1.webp', 1, 0, '2026-04-10 16:12:08'),
(34, 32, 'item_32_1775841047_0.jpg', 0, 1, '2026-04-10 17:10:47'),
(35, 32, 'item_32_1775841047_1.png', 1, 0, '2026-04-10 17:10:47'),
(36, 10, 'item_10_1775913450_0.jpg', 0, 1, '2026-04-11 13:17:30'),
(37, 10, 'item_10_1775913450_1.jpg', 1, 0, '2026-04-11 13:17:30'),
(38, 44, 'item_44_1775993494_0.jpg', 0, 1, '2026-04-12 11:31:34'),
(39, 44, 'item_44_1775993494_1.png', 1, 0, '2026-04-12 11:31:34'),
(40, 45, 'item_45_1775995882_0.jpg', 0, 1, '2026-04-12 12:11:22'),
(41, 45, 'item_45_1775995882_1.webp', 1, 0, '2026-04-12 12:11:22'),
(42, 46, 'item_46_1776131126_0.jpg', 0, 1, '2026-04-14 01:45:26');

-- --------------------------------------------------------

--
-- Table structure for table `item_unit_pricing`
--

CREATE TABLE `item_unit_pricing` (
  `pricing_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `unit_type_id` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `unit_quantity` int(10) DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `effective_until` date DEFAULT NULL,
  `price_level` varchar(50) DEFAULT 'Standard',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_unit_pricing`
--

INSERT INTO `item_unit_pricing` (`pricing_id`, `item_id`, `unit_type_id`, `unit_price`, `unit_quantity`, `effective_date`, `effective_until`, `price_level`, `created_at`, `updated_at`) VALUES
(17, 9, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-07 01:57:44', '2026-04-07 01:57:44'),
(18, 9, 2, 240.00, 12, NULL, NULL, 'Standard', '2026-04-07 01:57:44', '2026-04-07 01:57:44'),
(19, 12, 1, 0.00, 1, NULL, NULL, 'Standard', '2026-04-08 05:23:26', '2026-04-08 05:23:26'),
(20, 14, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-08 07:48:52', '2026-04-08 07:48:52'),
(21, 15, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-08 13:08:16', '2026-04-08 13:08:16'),
(22, 15, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-08 13:08:16', '2026-04-08 13:08:16'),
(23, 16, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-09 23:21:24', '2026-04-09 23:21:24'),
(24, 16, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-09 23:21:24', '2026-04-09 23:21:24'),
(25, 17, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-09 23:43:11', '2026-04-09 23:43:11'),
(28, 18, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 05:42:32', '2026-04-10 05:42:32'),
(29, 18, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 05:42:32', '2026-04-10 05:42:32'),
(32, 19, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 05:45:22', '2026-04-10 05:45:22'),
(33, 19, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 05:45:22', '2026-04-10 05:45:22'),
(34, 20, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 06:28:15', '2026-04-10 06:28:15'),
(35, 20, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 06:28:15', '2026-04-10 06:28:15'),
(36, 21, 6, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 07:48:26', '2026-04-10 07:48:26'),
(37, 21, 7, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 07:48:26', '2026-04-10 07:48:26'),
(38, 22, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 07:54:30', '2026-04-10 07:54:30'),
(39, 22, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 07:54:30', '2026-04-10 07:54:30'),
(40, 23, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 13:24:08', '2026-04-10 13:24:08'),
(41, 23, 5, 240.00, 24, NULL, NULL, 'Standard', '2026-04-10 13:24:08', '2026-04-10 14:08:01'),
(42, 24, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 13:49:03', '2026-04-10 13:49:03'),
(43, 24, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 13:49:03', '2026-04-10 13:49:03'),
(44, 25, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 13:56:45', '2026-04-10 13:56:45'),
(45, 25, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 13:56:45', '2026-04-10 13:56:45'),
(46, 26, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 14:09:26', '2026-04-10 14:09:26'),
(47, 26, 5, 240.00, 24, NULL, NULL, 'Standard', '2026-04-10 14:09:26', '2026-04-10 14:13:31'),
(48, 27, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 14:20:34', '2026-04-10 14:20:34'),
(49, 27, 5, 240.00, 24, NULL, NULL, 'Standard', '2026-04-10 14:20:34', '2026-04-10 14:21:27'),
(50, 28, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 14:36:11', '2026-04-10 14:36:11'),
(51, 28, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 14:36:11', '2026-04-10 14:36:11'),
(52, 29, 3, 350.00, 1, NULL, NULL, 'Standard', '2026-04-10 14:44:19', '2026-04-10 14:44:19'),
(53, 29, 4, 8400.00, 1, NULL, NULL, 'Standard', '2026-04-10 14:44:19', '2026-04-10 14:44:19'),
(56, 30, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 15:49:58', '2026-04-10 15:49:58'),
(57, 30, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 15:49:58', '2026-04-10 15:49:58'),
(60, 31, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 16:12:59', '2026-04-10 16:12:59'),
(61, 31, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 16:12:59', '2026-04-10 16:12:59'),
(62, 32, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-10 17:10:47', '2026-04-10 17:10:47'),
(63, 32, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-10 17:10:47', '2026-04-10 17:10:47'),
(73, 36, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:27:47', '2026-04-11 03:27:47'),
(74, 36, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:27:47', '2026-04-11 03:27:47'),
(75, 37, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:28:55', '2026-04-11 03:28:55'),
(76, 37, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:28:55', '2026-04-11 03:28:55'),
(77, 38, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:31:27', '2026-04-11 03:31:27'),
(78, 38, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:31:27', '2026-04-11 03:31:27'),
(79, 39, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:36:49', '2026-04-11 03:36:49'),
(80, 39, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:36:49', '2026-04-11 03:36:49'),
(81, 40, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:42:53', '2026-04-11 03:42:53'),
(82, 40, 5, 240.00, 1, NULL, NULL, 'Standard', '2026-04-11 03:42:53', '2026-04-11 03:42:53'),
(89, 5, 1, 40.00, 1, NULL, NULL, 'Standard', '2026-04-11 05:51:23', '2026-04-11 05:51:23'),
(90, 5, 8, 240.00, 1, NULL, NULL, 'Standard', '2026-04-11 05:51:23', '2026-04-11 05:51:23'),
(91, 5, 5, 480.00, 1, NULL, NULL, 'Standard', '2026-04-11 05:51:23', '2026-04-11 05:51:23'),
(92, 5, 9, 960.00, 1, NULL, NULL, 'Standard', '2026-04-11 05:51:23', '2026-04-11 05:51:23'),
(97, 8, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-11 05:54:18', '2026-04-11 05:54:18'),
(98, 8, 2, 240.00, 1, NULL, NULL, 'Standard', '2026-04-11 05:54:18', '2026-04-11 05:54:18'),
(103, 6, 1, 120.00, 1, NULL, NULL, 'Standard', '2026-04-11 09:12:37', '2026-04-11 09:12:37'),
(104, 6, 8, 720.00, 1, NULL, NULL, 'Standard', '2026-04-11 09:12:37', '2026-04-11 09:12:37'),
(105, 6, 10, 1440.00, 1, NULL, NULL, 'Standard', '2026-04-11 09:12:37', '2026-04-11 09:12:37'),
(106, 6, 9, 2880.00, 1, NULL, NULL, 'Standard', '2026-04-11 09:12:37', '2026-04-11 09:12:37'),
(107, 43, 1, 20.00, 1, NULL, NULL, 'Standard', '2026-04-11 09:22:58', '2026-04-11 09:22:58'),
(108, 43, 2, 240.00, 1, NULL, NULL, 'Standard', '2026-04-11 09:22:58', '2026-04-11 09:22:58'),
(127, 44, 1, 20.00, 1, '2026-04-12', NULL, 'Standard', '2026-04-12 13:38:08', '2026-04-12 13:38:08'),
(128, 44, 2, 240.00, 1, '2026-04-12', NULL, 'Standard', '2026-04-12 13:38:08', '2026-04-12 13:38:08'),
(129, 45, 1, 20.00, 1, '2026-04-13', NULL, 'Standard', '2026-04-12 13:38:56', '2026-04-12 13:38:56'),
(130, 45, 2, 240.00, 1, '2026-04-13', NULL, 'Standard', '2026-04-12 13:38:56', '2026-04-12 13:38:56'),
(131, 1, 3, 350.00, 1, '2026-04-13', NULL, 'Standard', '2026-04-13 01:07:26', '2026-04-13 03:40:08'),
(132, 1, 4, 8400.00, 1, '2026-04-13', NULL, 'Standard', '2026-04-13 01:07:26', '2026-04-13 03:40:08'),
(163, 10, 3, 350.00, 1, NULL, NULL, 'Standard', '2026-04-13 03:10:50', '2026-04-17 01:47:29'),
(164, 10, 3, 300.00, 1, NULL, NULL, 'Wholesale', '2026-04-13 03:10:50', '2026-04-17 01:47:29'),
(165, 10, 3, 320.00, 1, NULL, NULL, 'Retail', '2026-04-13 03:10:50', '2026-04-17 01:47:29'),
(166, 10, 4, 8400.00, 1, NULL, NULL, 'Standard', '2026-04-13 03:10:50', '2026-04-17 01:47:29'),
(167, 10, 4, 7500.00, 1, NULL, NULL, 'Wholesale', '2026-04-13 03:10:50', '2026-04-17 01:47:29'),
(168, 10, 4, 8000.00, 1, NULL, NULL, 'Retail', '2026-04-13 03:10:50', '2026-04-17 01:47:29'),
(182, 1, 3, 300.00, 1, NULL, NULL, 'Wholesale', '2026-04-13 03:40:08', '2026-04-13 03:40:08'),
(184, 1, 4, 7000.00, 1, NULL, NULL, 'Wholesale', '2026-04-13 03:40:08', '2026-04-13 03:40:08'),
(191, 46, 11, 375.00, 1, '2026-04-14', NULL, 'Standard', '2026-04-14 01:45:26', '2026-04-14 01:46:08'),
(193, 47, 12, 100.00, 1, NULL, NULL, 'Standard', '2026-04-14 01:53:11', '2026-04-14 03:09:35'),
(194, 47, 13, 1000.00, 1, NULL, NULL, 'Standard', '2026-04-14 01:53:11', '2026-04-14 03:09:35'),
(197, 48, 12, 140.00, 1, NULL, NULL, 'Standard', '2026-04-14 01:59:01', '2026-04-14 03:06:14'),
(198, 48, 14, 1400.00, 1, NULL, NULL, 'Standard', '2026-04-14 01:59:01', '2026-04-14 03:06:14'),
(199, 49, 15, 1551.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:07:55', '2026-04-14 02:07:55'),
(200, 49, 15, 1541.00, 1, NULL, NULL, 'Walk In', '2026-04-14 02:07:55', '2026-04-17 01:40:56'),
(201, 50, 15, 1604.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:12:03', '2026-04-14 02:16:25'),
(202, 51, 15, 1606.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:15:07', '2026-04-14 02:15:07'),
(203, 51, 15, 1623.00, 1, NULL, NULL, 'Walk In', '2026-04-14 02:15:07', '2026-04-17 01:40:53'),
(205, 52, 15, 1580.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:18:45', '2026-04-14 02:18:45'),
(206, 52, 15, 1654.00, 1, NULL, NULL, 'Walk In', '2026-04-14 02:18:45', '2026-04-17 01:40:50'),
(207, 53, 11, 560.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:22:20', '2026-04-14 02:22:20'),
(208, 53, 11, 574.00, 1, NULL, NULL, 'Walk In', '2026-04-14 02:22:20', '2026-04-17 01:40:47'),
(209, 54, 12, 150.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:26:26', '2026-04-14 02:26:26'),
(210, 54, 12, 150.00, 1, NULL, NULL, 'Walk In', '2026-04-14 02:26:26', '2026-04-17 01:40:44'),
(211, 55, 16, 98.00, 1, NULL, NULL, 'Walk In', '2026-04-14 02:28:25', '2026-04-17 01:40:41'),
(212, 56, 12, 202.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:34:17', '2026-04-14 03:07:51'),
(213, 56, 12, 207.00, 1, NULL, NULL, 'Walk In', '2026-04-14 02:34:17', '2026-04-17 01:40:39'),
(214, 57, 15, 2405.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:40:48', '2026-04-14 02:40:48'),
(215, 58, 15, 2494.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:45:23', '2026-04-14 02:45:23'),
(216, 58, 15, 2551.00, 1, NULL, NULL, 'Walk In', '2026-04-14 02:45:23', '2026-04-17 01:40:35'),
(217, 59, 15, 2513.00, 1, NULL, NULL, 'Standard', '2026-04-14 02:59:48', '2026-04-14 02:59:48'),
(218, 60, 15, 2531.00, 1, NULL, NULL, 'Standard', '2026-04-14 03:02:37', '2026-04-14 03:02:37'),
(219, 60, 15, 2589.00, 1, NULL, NULL, 'Walk In', '2026-04-14 03:02:37', '2026-04-17 01:40:32'),
(225, 47, 12, 102.00, 1, NULL, NULL, 'Walk In', '2026-04-14 03:09:35', '2026-04-17 01:40:25'),
(227, 47, 13, 1020.00, 1, NULL, NULL, 'Walk In', '2026-04-14 03:09:35', '2026-04-17 01:40:21'),
(228, 61, 16, 148.00, 1, NULL, NULL, 'Standard', '2026-04-14 03:12:37', '2026-04-14 03:12:37'),
(229, 61, 16, 153.00, 1, NULL, NULL, 'Walk In', '2026-04-14 03:12:37', '2026-04-17 01:40:07'),
(230, 62, 17, 1060.00, 1, NULL, NULL, 'Standard', '2026-04-14 03:18:15', '2026-04-14 03:18:15'),
(231, 63, 17, 1051.00, 1, NULL, NULL, 'Standard', '2026-04-14 03:19:46', '2026-04-17 01:37:02'),
(232, 64, 18, 3462.00, 1, NULL, NULL, 'Standard', '2026-04-14 03:21:54', '2026-04-14 03:21:54'),
(233, 65, 18, 3427.00, 1, NULL, NULL, 'Standard', '2026-04-14 03:23:17', '2026-04-14 03:23:17'),
(234, 66, 19, 205.00, 1, NULL, NULL, 'Standard', '2026-04-15 07:52:12', '2026-04-15 07:52:12'),
(235, 67, 19, 1.00, 1, NULL, NULL, 'Standard', '2026-04-15 07:53:19', '2026-04-15 07:53:19'),
(236, 68, 19, 1.00, 1, NULL, NULL, 'Standard', '2026-04-15 07:53:56', '2026-04-15 07:53:56'),
(237, 69, 19, 400.00, 1, NULL, NULL, 'Standard', '2026-04-15 07:54:36', '2026-04-15 07:54:36'),
(238, 70, 19, 235.00, 1, NULL, NULL, 'Standard', '2026-04-15 07:55:44', '2026-04-15 07:55:44'),
(239, 71, 19, 1.00, 1, NULL, NULL, 'Standard', '2026-04-15 08:04:39', '2026-04-15 08:04:39'),
(240, 72, 19, 205.00, 1, NULL, NULL, 'Standard', '2026-04-15 08:05:44', '2026-04-15 08:05:44'),
(241, 73, 20, 360.00, 1, NULL, NULL, 'Standard', '2026-04-15 08:51:02', '2026-04-15 08:51:02'),
(242, 73, 20, 377.00, 1, NULL, NULL, 'Walk In', '2026-04-15 08:51:02', '2026-04-17 01:40:00'),
(244, 75, 21, 138.00, 1, NULL, NULL, 'Walk In', '2026-04-15 08:59:24', '2026-04-17 01:39:55'),
(245, 75, 21, 137.14, 1, NULL, NULL, 'Standard', '2026-04-15 08:59:24', '2026-04-15 08:59:24'),
(246, 75, 22, 988.00, 1, NULL, NULL, 'Walk In', '2026-04-15 08:59:24', '2026-04-17 01:39:48'),
(247, 75, 22, 960.00, 1, NULL, NULL, 'Standard', '2026-04-15 08:59:24', '2026-04-15 08:59:24'),
(248, 75, 23, 1254.00, 1, NULL, NULL, 'Walk In', '2026-04-15 08:59:24', '2026-04-17 01:39:44'),
(249, 75, 23, 1371.00, 1, NULL, NULL, 'Standard', '2026-04-15 08:59:24', '2026-04-15 08:59:24'),
(250, 76, 24, 3392.00, 1, NULL, NULL, 'Standard', '2026-04-15 09:01:14', '2026-04-16 01:10:49'),
(252, 77, 21, 3357.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:15:04', '2026-04-16 01:15:04'),
(253, 78, 21, 1642.00, 1, NULL, NULL, 'Walk In', '2026-04-16 01:17:34', '2026-04-17 01:39:32'),
(254, 79, 21, 1090.00, 1, NULL, NULL, 'Walk In', '2026-04-16 01:19:15', '2026-04-17 01:39:26'),
(255, 76, 24, 3392.00, 1, NULL, NULL, 'Walk In', '2026-04-16 01:20:28', '2026-04-17 01:39:22'),
(256, 80, 21, 1081.00, 1, NULL, NULL, 'Walk In', '2026-04-16 01:23:52', '2026-04-17 01:39:19'),
(257, 81, 20, 405.00, 1, NULL, NULL, 'Walk In', '2026-04-16 01:27:15', '2026-04-17 01:39:15'),
(258, 82, 25, 388.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:33:24', '2026-04-16 01:33:24'),
(259, 82, 25, 400.00, 1, NULL, NULL, 'Walk In', '2026-04-16 01:33:24', '2026-04-16 01:33:24'),
(260, 83, 26, 960.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:38:05', '2026-04-16 01:38:05'),
(261, 83, 26, 980.00, 1, NULL, NULL, 'Walk In', '2026-04-16 01:38:05', '2026-04-16 01:38:05'),
(262, 84, 25, 393.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:41:14', '2026-04-16 01:41:14'),
(263, 85, 25, 383.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:43:57', '2026-04-16 01:43:57'),
(264, 86, 25, 377.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:45:18', '2026-04-16 01:45:18'),
(265, 87, 25, 374.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:46:26', '2026-04-16 01:46:26'),
(266, 88, 27, 899.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:53:07', '2026-04-16 01:53:07'),
(267, 89, 28, 1560.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:54:34', '2026-04-16 01:54:34'),
(268, 90, 20, 348.00, 1, NULL, NULL, 'Walk In', '2026-04-16 01:54:36', '2026-04-17 01:39:08'),
(269, 91, 21, 133.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:56:58', '2026-04-16 01:56:58'),
(270, 91, 22, 879.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:56:58', '2026-04-16 01:56:58'),
(271, 91, 23, 1239.00, 1, NULL, NULL, 'Standard', '2026-04-16 01:56:58', '2026-04-16 01:56:58'),
(272, 92, 25, 362.00, 1, NULL, NULL, 'Standard', '2026-04-16 02:01:12', '2026-04-16 02:01:12'),
(273, 93, 1, 120.00, 1, NULL, NULL, 'Standard', '2026-04-16 09:41:41', '2026-04-16 09:41:41'),
(275, 63, 17, 1051.00, 1, NULL, NULL, 'Walk In', '2026-04-17 01:37:02', '2026-04-17 01:37:02'),
(279, 10, 3, 360.00, 1, NULL, NULL, 'Walk In', '2026-04-17 01:47:29', '2026-04-17 01:47:29'),
(283, 10, 4, 9000.00, 1, NULL, NULL, 'Walk In', '2026-04-17 01:47:29', '2026-04-17 01:47:29'),
(284, 94, 29, 31.32, 1, NULL, NULL, 'Standard', '2026-04-17 05:53:29', '2026-04-17 06:32:42'),
(285, 94, 29, 32.25, 1, NULL, NULL, 'walk in', '2026-04-17 05:53:29', '2026-04-17 06:32:42'),
(286, 94, 30, 375.88, 1, NULL, NULL, 'Standard', '2026-04-17 05:53:29', '2026-04-17 06:32:42'),
(287, 94, 30, 387.00, 1, NULL, NULL, 'walk in', '2026-04-17 05:53:29', '2026-04-17 06:32:42'),
(288, 95, 29, 31.16, 1, NULL, NULL, 'Standard', '2026-04-17 05:57:13', '2026-04-20 03:08:39'),
(289, 95, 30, 374.12, 1, NULL, NULL, 'Standard', '2026-04-17 05:57:13', '2026-04-20 03:08:39'),
(290, 96, 29, 30.74, 1, NULL, NULL, 'Standard', '2026-04-17 05:58:37', '2026-04-20 03:09:09'),
(291, 96, 30, 368.84, 1, NULL, NULL, 'Standard', '2026-04-17 05:58:37', '2026-04-20 03:09:09'),
(292, 97, 29, 30.30, 1, NULL, NULL, 'Standard', '2026-04-17 06:05:54', '2026-04-20 03:09:45'),
(293, 97, 29, 31.66, 1, NULL, NULL, 'walk in', '2026-04-17 06:05:54', '2026-04-20 03:09:45'),
(294, 97, 30, 363.55, 1, NULL, NULL, 'Standard', '2026-04-17 06:05:54', '2026-04-20 03:09:45'),
(295, 97, 30, 380.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:05:54', '2026-04-20 03:09:45'),
(296, 98, 29, 29.56, 1, NULL, NULL, 'Standard', '2026-04-17 06:07:19', '2026-04-20 03:10:23'),
(297, 98, 30, 354.74, 1, NULL, NULL, 'Standard', '2026-04-17 06:07:19', '2026-04-20 03:10:23'),
(298, 99, 29, 29.34, 1, NULL, NULL, 'Standard', '2026-04-17 06:08:19', '2026-04-20 03:10:56'),
(299, 99, 30, 352.10, 1, NULL, NULL, 'Standard', '2026-04-17 06:08:19', '2026-04-20 03:10:56'),
(300, 100, 29, 30.16, 1, NULL, NULL, 'Standard', '2026-04-17 06:09:45', '2026-04-20 03:11:42'),
(301, 100, 30, 362.00, 1, NULL, NULL, 'Standard', '2026-04-17 06:09:45', '2026-04-20 03:11:42'),
(302, 101, 29, 27.65, 1, NULL, NULL, 'Standard', '2026-04-17 06:16:48', '2026-04-20 03:06:23'),
(303, 101, 29, 28.83, 1, NULL, NULL, 'walk in', '2026-04-17 06:16:48', '2026-04-20 03:06:23'),
(304, 101, 30, 331.83, 1, NULL, NULL, 'Standard', '2026-04-17 06:16:48', '2026-04-20 03:06:23'),
(305, 101, 30, 346.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:16:48', '2026-04-20 03:06:23'),
(306, 102, 29, 26.26, 1, NULL, NULL, 'Standard', '2026-04-17 06:20:28', '2026-04-20 03:04:29'),
(307, 102, 30, 315.10, 1, NULL, NULL, 'Standard', '2026-04-17 06:20:28', '2026-04-20 03:04:29'),
(308, 103, 29, 121.53, 1, NULL, NULL, 'Standard', '2026-04-17 06:23:52', '2026-04-20 02:08:50'),
(309, 103, 31, 729.17, 1, NULL, NULL, 'Standard', '2026-04-17 06:23:52', '2026-04-20 02:08:50'),
(310, 104, 29, 121.47, 1, NULL, NULL, 'Standard', '2026-04-17 06:25:49', '2026-04-20 02:07:24'),
(311, 104, 29, 131.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:25:49', '2026-04-20 02:07:24'),
(312, 104, 31, 1241.67, 1, NULL, NULL, 'Standard', '2026-04-17 06:25:49', '2026-04-20 02:07:24'),
(313, 104, 31, 1310.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:25:49', '2026-04-20 02:07:24'),
(318, 105, 29, 121.41, 1, NULL, NULL, 'Standard', '2026-04-17 06:38:51', '2026-04-20 03:07:29'),
(319, 105, 29, 132.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:38:51', '2026-04-20 03:07:29'),
(320, 105, 31, 1214.08, 1, NULL, NULL, 'Standard', '2026-04-17 06:38:51', '2026-04-20 03:07:29'),
(321, 105, 31, 1320.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:38:51', '2026-04-20 03:07:29'),
(322, 106, 29, 129.34, 1, NULL, NULL, 'Standard', '2026-04-17 06:41:09', '2026-04-17 06:52:38'),
(323, 106, 29, 139.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:41:09', '2026-04-17 06:52:38'),
(324, 106, 31, 1293.37, 1, NULL, NULL, 'Standard', '2026-04-17 06:41:09', '2026-04-17 06:52:38'),
(325, 106, 31, 1390.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:41:09', '2026-04-17 06:52:38'),
(326, 107, 29, 126.17, 1, NULL, NULL, 'Standard', '2026-04-17 06:43:40', '2026-04-17 06:50:42'),
(327, 107, 31, 1261.65, 1, NULL, NULL, 'Standard', '2026-04-17 06:43:40', '2026-04-17 06:50:42'),
(328, 108, 29, 1503.17, 1, NULL, NULL, 'Standard', '2026-04-17 06:45:58', '2026-04-17 06:45:58'),
(329, 108, 29, 1560.00, 1, NULL, NULL, 'walk in', '2026-04-17 06:45:58', '2026-04-17 06:45:58'),
(338, 109, 1, 6.00, 1, NULL, NULL, 'Standard', '2026-04-20 00:32:16', '2026-04-20 00:32:16'),
(339, 109, 32, 30.00, 6, NULL, NULL, 'Standard', '2026-04-20 00:32:16', '2026-04-20 00:32:16'),
(340, 110, 1, 6.00, 1, NULL, NULL, 'Standard', '2026-04-20 00:37:30', '2026-04-20 00:37:30'),
(341, 110, 32, 30.00, 6, NULL, NULL, 'Standard', '2026-04-20 00:37:30', '2026-04-20 00:37:30'),
(384, 111, 1, 6.00, 1, NULL, NULL, 'Standard', '2026-04-20 05:37:50', '2026-04-20 05:37:50'),
(385, 111, 32, 30.00, 6, NULL, NULL, 'Standard', '2026-04-20 05:37:50', '2026-04-20 05:37:50'),
(386, 112, 1, 6.00, 1, NULL, NULL, 'Standard', '2026-04-20 07:37:25', '2026-04-20 07:37:25'),
(387, 112, 32, 30.00, 6, NULL, NULL, 'Standard', '2026-04-20 07:37:25', '2026-04-20 07:37:25');

-- --------------------------------------------------------

--
-- Table structure for table `item_unit_types`
--

CREATE TABLE `item_unit_types` (
  `unit_type_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `unit_type_name` varchar(100) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `smallest_pack_quantity` int(11) DEFAULT 1,
  `is_default_uom` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `pick_status` enum('pending','open','in-progress','completed','in-transit','partial','delivered','cancelled') DEFAULT 'pending',
  `picked_by` int(11) DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `picked_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pick_lists`
--

INSERT INTO `pick_lists` (`pick_list_id`, `pick_list_number`, `so_id`, `branch_id`, `driver_id`, `pick_date`, `pick_status`, `picked_by`, `verified_by`, `created_at`, `updated_at`, `picked_at`, `verified_at`) VALUES
(3, 'B01-PL-20260213-5573', 26, 1, 1, '2026-02-13', 'cancelled', NULL, NULL, '2026-02-13 03:14:01', '2026-02-16 01:54:13', NULL, NULL),
(15, 'PL-20260216-00026', 26, 1, NULL, '2026-02-16', 'cancelled', NULL, NULL, '2026-02-16 01:44:55', '2026-02-16 05:01:40', NULL, NULL),
(16, 'PL-20260216-00025', 25, 1, 4, '2026-02-16', 'open', NULL, NULL, '2026-02-16 01:51:59', '2026-03-24 06:25:29', NULL, NULL),
(18, 'PL-20260216-00024', 24, 1, NULL, '2026-02-16', 'completed', NULL, NULL, '2026-02-16 01:53:36', '2026-02-16 05:03:03', NULL, NULL),
(20, 'PL-20260216-00027', 27, 1, NULL, '2026-02-16', 'completed', NULL, NULL, '2026-02-16 06:24:51', '2026-02-16 06:27:13', NULL, NULL),
(22, 'PL-20260218-00022', 22, 1, 1, '2026-02-18', 'completed', NULL, NULL, '2026-02-18 03:55:27', '2026-02-18 03:58:48', NULL, NULL),
(23, 'PL-20260218-00021', 21, 1, 4, '2026-02-18', 'completed', NULL, NULL, '2026-02-18 05:20:23', '2026-02-18 05:22:10', NULL, NULL),
(24, 'PL-20260304-00030', 30, 1, 1, '2026-03-04', 'in-transit', NULL, NULL, '2026-03-04 05:58:50', '2026-03-30 05:39:54', NULL, NULL),
(25, 'PL-20260305-00035', 35, 1, 8, '2026-03-06', 'completed', NULL, NULL, '2026-03-06 00:49:10', '2026-03-09 01:08:21', NULL, NULL),
(26, 'PL-20260305-00036', 36, 1, 8, '2026-03-06', 'in-transit', NULL, NULL, '2026-03-06 01:44:13', '2026-03-24 06:32:08', NULL, NULL),
(27, 'PL-20260306-00037', 37, 1, 8, '2026-03-06', 'completed', NULL, NULL, '2026-03-06 07:57:48', '2026-03-06 07:58:39', NULL, NULL),
(28, 'PL-20260309-00038', 38, 1, 8, '2026-03-09', 'completed', NULL, NULL, '2026-03-09 06:45:31', '2026-03-10 01:33:02', NULL, NULL),
(29, 'PL-20260309-00039', 39, 1, 8, '2026-03-10', 'completed', NULL, NULL, '2026-03-09 23:45:51', '2026-03-10 00:47:15', NULL, NULL),
(30, 'PL-20260309-00041', 41, 1, 1, '2026-03-10', 'completed', NULL, NULL, '2026-03-10 00:26:17', '2026-04-07 02:48:00', NULL, NULL),
(31, 'PL-20260309-00042', 42, 1, 8, '2026-03-10', 'in-transit', NULL, NULL, '2026-03-10 00:44:46', '2026-03-24 06:34:23', NULL, NULL),
(32, 'PL-20260309-00040', 40, 1, 8, '2026-03-10', 'completed', NULL, NULL, '2026-03-10 01:17:33', '2026-04-07 02:45:39', NULL, NULL),
(33, 'PL-20260310-00043', 43, 1, 1, '2026-03-10', 'completed', NULL, NULL, '2026-03-10 05:25:17', '2026-04-06 23:42:47', NULL, NULL),
(34, 'PL-20260310-00029', 29, 1, 8, '2026-03-11', 'completed', NULL, NULL, '2026-03-11 02:37:57', '2026-03-31 02:03:28', NULL, NULL),
(35, 'PL-20260331-00044', 44, 1, 1, '2026-03-31', 'completed', NULL, NULL, '2026-03-31 13:47:21', '2026-04-06 23:41:54', NULL, NULL),
(36, 'PL-20260410-00058', 58, 1, 1, '2026-04-10', 'open', NULL, NULL, '2026-04-10 00:16:37', '2026-04-10 00:16:37', NULL, NULL),
(37, 'PL-20260410-00057', 57, 1, 1, '2026-04-10', 'open', NULL, NULL, '2026-04-10 00:17:37', '2026-04-10 00:17:37', NULL, NULL),
(38, 'PL-20260410-00059', 59, 1, 1, '2026-04-10', 'open', NULL, NULL, '2026-04-10 00:22:24', '2026-04-10 00:22:24', NULL, NULL),
(39, 'PL-20260414-00063', 63, 4, 11, '2026-04-14', 'completed', NULL, NULL, '2026-04-14 05:11:03', '2026-04-14 05:14:42', NULL, NULL),
(40, 'PL-20260416-00070', 70, 3, 12, '2026-04-16', 'open', NULL, NULL, '2026-04-16 02:09:28', '2026-04-16 02:09:28', NULL, NULL),
(41, 'PL-20260416-00056', 56, 1, 10, '2026-04-16', 'open', NULL, NULL, '2026-04-16 11:48:59', '2026-04-16 11:48:59', NULL, NULL),
(42, 'PL-20260417-00075', 75, 10, 13, '2026-04-17', 'open', NULL, NULL, '2026-04-17 06:33:03', '2026-04-17 06:33:03', NULL, NULL),
(43, 'PL-20260420-00079', 79, 1, 8, '2026-04-20', 'open', NULL, NULL, '2026-04-20 05:01:43', '2026-04-20 05:01:43', NULL, NULL),
(44, 'PL-20260420-00079', 79, 1, 8, '2026-04-20', 'open', NULL, NULL, '2026-04-20 05:25:14', '2026-04-20 05:25:14', NULL, NULL),
(45, 'PL-20260420-00080', 80, 1, 10, '2026-04-20', 'open', NULL, NULL, '2026-04-20 05:58:44', '2026-04-20 05:58:44', NULL, NULL);

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
(42, 23, 4, 1, 0, 'No location data', NULL),
(43, 24, 5, 5, 0, NULL, NULL),
(44, 25, 1, 1, 1, NULL, NULL),
(45, 26, 1, 1, 1, NULL, NULL),
(46, 27, 1, 100, 100, NULL, NULL),
(47, 28, 1, 2, 2, NULL, NULL),
(48, 28, 2, 13, 13, NULL, NULL),
(49, 28, 5, 25, 25, NULL, NULL),
(50, 29, 6, 4, 4, NULL, NULL),
(51, 30, 6, 1, 1, NULL, NULL),
(52, 31, 6, 1, 1, NULL, NULL),
(53, 32, 6, 2, 2, NULL, NULL),
(54, 33, 6, 5, 5, NULL, NULL),
(55, 34, 1, 10, 0, NULL, NULL),
(56, 34, 2, 5, 0, NULL, NULL),
(57, 34, 5, 12, 0, NULL, NULL),
(58, 35, 6, 1, 0, NULL, NULL),
(59, 36, 10, 2, 0, NULL, NULL),
(60, 37, 10, 2, 0, NULL, NULL),
(61, 38, 1, 1, 0, NULL, NULL),
(62, 38, 10, 5, 0, NULL, NULL),
(63, 39, 56, 3, 0, NULL, NULL),
(64, 40, 86, 50, 0, NULL, NULL),
(65, 41, 10, 2, 0, NULL, NULL),
(66, 42, 94, 100, 0, NULL, NULL),
(67, 42, 95, 50, 0, NULL, NULL),
(72, 45, 8, 5000, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `image_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `image_order` int(11) DEFAULT 0,
  `is_primary` tinyint(4) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `product_images`
--

INSERT INTO `product_images` (`image_id`, `item_id`, `image_path`, `image_order`, `is_primary`, `created_at`) VALUES
(1, 12, 'item_1775625806_69d5e64e77b31_0.jpg', 0, 1, '2026-04-08 05:23:26'),
(2, 12, 'item_1775625806_69d5e64e77d89_1.webp', 1, 0, '2026-04-08 05:23:26'),
(3, 12, 'item_1775625806_69d5e64e77e82_2.jpg', 2, 0, '2026-04-08 05:23:26'),
(4, 12, 'item_1775625806_69d5e64e77fa7_3.webp', 3, 0, '2026-04-08 05:23:26');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `supplier_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`po_id`, `po_number`, `branch_id`, `order_date`, `expected_delivery`, `total_amount`, `po_status`, `supplier_name`, `created_by`, `created_at`, `updated_at`, `supplier_id`) VALUES
(3, 'PO-20260219-4794', 1, '2026-02-19 00:00:00', '2026-02-24', 2000.00, 'received', 'Mark Laurence Llorin', 2, '2026-02-19 06:10:53', '2026-03-04 00:30:29', NULL),
(4, 'PO-20260317-7456', 1, '2026-03-17 00:00:00', '2026-03-22', 87.40, 'submitted', 'Jill Anuran', 2, '2026-03-17 05:47:24', '2026-03-18 03:22:15', 1),
(5, 'PO-20260317-9960', 1, '2026-03-17 00:00:00', '2026-03-21', 89.30, 'submitted', 'Jill Anuran', 2, '2026-03-17 06:33:02', '2026-03-18 03:21:51', 1),
(6, 'PO-20260318-6367', 1, '2026-03-19 00:00:00', '2026-03-21', 39.20, 'draft', 'Jill Anuran', 2, '2026-03-19 01:58:10', '2026-03-19 01:58:23', 1),
(7, 'PO-20260324-1604', 1, '2026-03-25 00:00:00', '2026-03-25', 2679.00, 'received', 'Jill Anuran', 2, '2026-03-25 00:57:26', '2026-03-25 01:28:08', 1),
(8, 'PO-20260324-2034', 1, '2026-03-25 00:00:00', '2026-03-26', 95.00, 'received', 'Jill Anuran', 2, '2026-03-25 01:29:40', '2026-03-25 01:30:07', 1);

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
  `line_total` decimal(12,2) GENERATED ALWAYS AS (`quantity_ordered` * `unit_price`) STORED,
  `discount_type` enum('percentage','fixed') DEFAULT NULL,
  `discount_value` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`po_item_id`, `po_id`, `item_id`, `quantity_ordered`, `quantity_received`, `unit_price`, `discount_type`, `discount_value`) VALUES
(1, 1, 2, 7, 0, 150.00, NULL, 0.00),
(2, 3, 1, 20, 20, 100.00, NULL, 0.00),
(3, 4, 1, 1, 0, 95.00, NULL, 0.00),
(4, 5, 1, 1, 0, 95.00, NULL, 0.00),
(5, 6, 6, 1, 0, 40.00, NULL, 0.00),
(6, 7, 2, 1, 1, 1800.00, 'percentage', 5.00),
(7, 7, 1, 1, 1, 1200.00, 'percentage', 5.00),
(8, 8, 1, 1, 1, 100.00, 'percentage', 5.00);

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
(6, NULL, 'RMR-20260212-1770861909', 25, 2, 5, 1, 'damaged', 'Return via sales interface', 'pending', NULL, NULL, NULL, NULL, NULL, '2026-02-12 02:05:09', '2026-02-12 06:28:13', 1),
(7, NULL, 'RMR-20260416-1776304928', 70, 35, 86, 1, 'damaged', 'Return via sales interface', 'pending', NULL, NULL, NULL, NULL, NULL, '2026-04-16 02:02:08', '2026-04-16 02:02:08', 3);

-- --------------------------------------------------------

--
-- Table structure for table `rsr_reports`
--

CREATE TABLE `rsr_reports` (
  `rsr_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `rsr_date` date NOT NULL,
  `store_name` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` varchar(50) NOT NULL,
  `remarks` text DEFAULT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rsr_reports`
--

INSERT INTO `rsr_reports` (`rsr_id`, `customer_id`, `rsr_date`, `store_name`, `address`, `status`, `remarks`, `reported_by`, `created_at`) VALUES
(2, 40, '2026-04-16', 'Nestoristore', 'Camastilisan, Calaca, Batangas, Region IV-A', 'no_order', 'Nandine nga ako, walang tao', 38, '2026-04-16 06:09:34');

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
  `agent_location` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_orders`
--

INSERT INTO `sales_orders` (`so_id`, `so_number`, `customer_id`, `branch_id`, `order_date`, `delivery_date`, `total_amount`, `order_status`, `agent_location`, `created_by`, `created_at`, `updated_at`, `confirmed_at`, `confirmed_by`) VALUES
(15, 'SO-20260211-0655504', 1, 1, '0000-00-00 00:00:00', NULL, 100.00, 'pending', '13.8823655,121.0363235\r\n', 5, '2026-02-11 03:30:55', '2026-03-23 01:28:25', NULL, NULL),
(16, 'SO-20260211-0758571', 3, 1, '0000-00-00 00:00:00', NULL, 400.00, 'pending', '13.8823655,121.0363235\r\n', 5, '2026-02-11 03:32:38', '2026-03-23 01:28:23', NULL, NULL),
(17, 'SO-20260211-0758716', 3, 1, '0000-00-00 00:00:00', NULL, 400.00, 'pending', '13.8823655,121.0363235\r\n', 5, '2026-02-11 03:32:38', '2026-03-23 01:28:21', NULL, NULL),
(18, 'SO-20260211-2358681', 4, 1, '2026-02-11 04:59:18', NULL, 450.00, 'pending', '13.8823655,121.0363235\r\n', 5, '2026-02-11 03:59:18', '2026-03-23 01:28:19', NULL, NULL),
(21, 'SO-20260211-7094558', 4, 1, '2026-02-11 00:00:00', NULL, 75.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-02-11 05:18:14', '2026-03-23 01:28:11', NULL, NULL),
(22, 'SO-20260211-7130609', 2, 1, '2026-02-11 00:00:00', NULL, 325.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-02-11 05:18:50', '2026-03-23 01:28:09', NULL, NULL),
(23, 'SO-20260211-7592571', 2, 1, '2026-02-11 00:00:00', NULL, 300.00, 'ready', '13.8823655,121.0363235\r\n', 5, '2026-02-11 05:26:32', '2026-03-23 01:28:06', NULL, NULL),
(24, 'SO-20260211-8541533', 4, 1, '2026-02-11 00:00:00', NULL, 225.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-02-11 05:42:21', '2026-03-23 01:28:04', NULL, NULL),
(25, 'SO-20260211-9607250', 2, 1, '2026-02-11 00:00:00', NULL, 250.00, 'cancelled', '13.8823655,121.0363235\r\n', 5, '2026-02-11 06:00:07', '2026-03-23 01:28:00', NULL, NULL),
(27, 'SO-20260216-2976391', 6, 1, '2026-02-16 00:00:00', NULL, 1000.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-02-16 06:22:56', '2026-03-23 01:27:58', NULL, NULL),
(28, 'SO-20260223-4355326', 5, 1, '2026-02-23 03:39:15', NULL, 100.00, 'pending', '13.8823655,121.0363235\r\n', 5, '2026-02-23 02:39:15', '2026-03-23 01:27:55', NULL, NULL),
(29, 'SO-20260303-0119456', 17, 1, '2026-03-03 07:15:19', NULL, 4750.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-03-02 16:00:00', '2026-03-31 02:03:28', NULL, NULL),
(30, 'SO-20260304-3806363', 4, 1, '2026-03-04 00:56:46', NULL, 1250.00, '', '13.8823655,121.0363235\r\n', 5, '2026-03-03 16:00:00', '2026-03-30 05:39:54', NULL, NULL),
(35, 'SO-20260305-6995526', 4, 1, '2026-03-05 02:49:55', NULL, 600.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-03-04 16:00:00', '2026-03-23 01:27:47', NULL, NULL),
(36, 'SO-20260305-1290538', 5, 1, '2026-03-05 20:41:30', NULL, 100.00, '', '13.8823655,121.0363235\r\n', 5, '2026-03-05 16:00:00', '2026-03-24 06:32:08', NULL, NULL),
(37, 'SO-20260306-3801570', 14, 1, '2026-03-06 02:56:41', NULL, 10000.00, 'ready', '13.8823655,121.0363235\r\n', 5, '2026-03-05 16:00:00', '2026-03-23 01:27:42', NULL, NULL),
(38, 'SO-20260306-9596660', 6, 1, '2026-03-06 04:33:16', NULL, 10600.00, 'ready', '13.8823655,121.0363235\r\n', 5, '2026-03-05 16:00:00', '2026-03-23 01:27:38', NULL, NULL),
(39, 'SO-20260308-7855239', 5, 1, '2026-03-08 20:57:35', NULL, 1920.00, 'ready', '13.8823655,121.0363235\r\n', 5, '2026-03-08 16:00:00', '2026-03-23 01:27:35', NULL, NULL),
(40, 'SO-20260308-9861194', 4, 1, '2026-03-08 21:31:01', NULL, 480.00, 'ready', '13.8823655,121.0363235\r\n', 5, '2026-03-08 16:00:00', '2026-04-07 02:45:39', NULL, NULL),
(41, 'SO-20260309-2352896', 5, 1, '2026-03-09 20:25:52', NULL, 480.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-03-09 16:00:00', '2026-04-07 02:48:00', NULL, NULL),
(42, 'SO-20260309-3428248', 7, 1, '2026-03-09 20:43:48', NULL, 480.00, '', '13.8823655,121.0363235\r\n', 5, '2026-03-09 16:00:00', '2026-03-24 06:34:23', NULL, NULL),
(43, 'SO-20260310-0249159', 17, 1, '2026-03-10 01:24:09', NULL, 200.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-03-09 16:00:00', '2026-04-06 23:42:47', NULL, NULL),
(44, 'SO-20260311-9917287', 4, 1, '2026-03-11 02:18:37', NULL, 240.00, 'delivered', '13.8823655,121.0363235\r\n', 5, '2026-03-10 16:00:00', '2026-04-06 23:41:54', NULL, NULL),
(45, 'SO-20260318-0652837', 5, 1, '2026-03-18 23:24:12', NULL, 456.00, 'pending', '13.8823655,121.0363235\r\n', 5, '2026-03-19 03:24:12', '2026-03-23 01:27:06', NULL, NULL),
(46, 'SO-20260322-9041822', 5, 1, '2026-03-22 21:24:01', NULL, 1824.00, 'pending', '13.8823655,121.0363235', 5, '2026-03-23 01:24:02', '2026-03-23 01:24:02', NULL, NULL),
(47, 'SO-20260331-8798910', 19, 1, '2026-03-31 21:59:58', NULL, 350.00, 'pending', '13.882231,121.036423', 5, '2026-04-01 01:59:57', '2026-04-01 01:59:57', NULL, NULL),
(49, 'SO-20260407-1058258', 6, 1, '2026-04-07 00:17:38', NULL, 16625.00, 'delivered', '13.882404,121.036347', 5, '2026-04-06 16:00:00', '2026-04-20 03:31:33', NULL, NULL),
(50, 'SO-20260407-6057941', 19, 1, '2026-04-07 07:14:17', NULL, 350.00, 'pending', NULL, 5, '2026-04-07 07:14:17', '2026-04-07 07:14:17', NULL, NULL),
(51, 'SO-20260408-8459194', 19, 1, '2026-04-08 03:20:59', NULL, 480.00, 'pending', NULL, 5, '2026-04-08 03:20:59', '2026-04-08 03:20:59', NULL, NULL),
(52, 'SO-20260408-8687541', 19, 1, '2026-04-08 03:24:47', NULL, 480.00, 'pending', NULL, 5, '2026-04-08 03:24:47', '2026-04-08 03:24:47', NULL, NULL),
(53, 'SO-20260408-9014134', 19, 1, '2026-04-08 03:30:14', NULL, 400.00, 'pending', NULL, 5, '2026-04-08 03:30:14', '2026-04-08 03:30:14', NULL, NULL),
(56, 'SO-20260409-8930241', 3, 1, '2026-04-09 23:55:30', NULL, 16800.00, 'confirmed', NULL, 5, '2026-04-09 16:00:00', '2026-04-16 11:48:59', NULL, NULL),
(57, 'SO-20260409-8955546', 3, 1, '2026-04-09 23:55:55', NULL, 16800.00, 'confirmed', NULL, 5, '2026-04-09 16:00:00', '2026-04-10 00:17:37', NULL, NULL),
(58, 'SO-20260410-9260158', 21, 1, '2026-04-10 00:01:00', NULL, 16800.00, 'confirmed', NULL, 5, '2026-04-09 16:00:00', '2026-04-10 00:16:37', NULL, NULL),
(59, 'SO-20260410-0516951', 21, 1, '2026-04-10 00:21:56', NULL, 10150.00, 'confirmed', NULL, 5, '2026-04-09 16:00:00', '2026-04-10 00:22:24', NULL, NULL),
(60, 'SO-20260410-1040681', 21, 1, '2026-04-10 00:30:40', NULL, 480.00, 'pending', NULL, 5, '2026-04-10 00:30:40', '2026-04-10 00:30:40', NULL, NULL),
(61, 'SO-20260413-5878251', 16, 1, '2026-04-13 04:51:18', NULL, 7980.00, 'pending', '13.882209458890188,121.0362960824429', 5, '2026-04-13 04:51:18', '2026-04-13 04:51:18', NULL, NULL),
(63, 'SO-20260414-2538280', 23, 4, '2026-04-14 04:55:38', NULL, 750.00, 'delivered', '13.9117545,122.1357924', 33, '2026-04-13 16:00:00', '2026-04-14 05:14:42', NULL, NULL),
(64, 'SO-20260414-4746901', 24, 4, '2026-04-14 05:32:26', NULL, 190530.00, 'pending', '13.9144024,122.1286519', 33, '2026-04-14 05:32:26', '2026-04-14 05:32:26', NULL, NULL),
(65, 'SO-20260415-1644825', 27, 7, '2026-04-15 08:27:24', NULL, 137280.00, 'pending', '13.882495891703197,121.03627166679563', 21, '2026-04-15 08:27:24', '2026-04-15 08:27:24', NULL, NULL),
(66, 'SO-20260415-1659507', 27, 7, '2026-04-15 08:27:39', NULL, 416.00, 'pending', '13.8826107,121.0361504', 37, '2026-04-15 08:27:39', '2026-04-15 08:27:39', NULL, NULL),
(67, 'SO-20260415-3443930', 28, 5, '2026-04-15 08:57:23', NULL, 180000.00, 'pending', '13.8825895,121.0361847', 28, '2026-04-15 08:57:23', '2026-04-15 08:57:23', NULL, NULL),
(68, 'SO-20260415-3905720', 29, 5, '2026-04-15 09:05:05', NULL, 8740.00, 'pending', NULL, 27, '2026-04-15 09:05:05', '2026-04-15 09:05:05', NULL, NULL),
(69, 'SO-20260415-4148560', 29, 5, '2026-04-15 09:09:08', NULL, 339200.00, 'pending', '13.8825902,121.0361731', 38, '2026-04-15 09:09:08', '2026-04-15 09:09:08', NULL, NULL),
(70, 'SO-20260416-4006430', 35, 3, '2026-04-16 01:46:46', NULL, 18900.00, 'confirmed', '13.9259457,120.8034032', 26, '2026-04-15 16:00:00', '2026-04-16 02:09:28', NULL, NULL),
(71, 'SO-20260416-5854875', 41, 3, '2026-04-16 05:04:14', NULL, 1355.00, 'pending', '13.9294797,120.8115985', 26, '2026-04-16 05:04:14', '2026-04-16 05:04:14', NULL, NULL),
(72, 'SO-20260416-6572273', 42, 3, '2026-04-16 05:16:12', NULL, 978.00, 'pending', NULL, 26, '2026-04-16 05:16:12', '2026-04-16 05:16:12', NULL, NULL),
(73, 'SO-20260416-6984872', 43, 3, '2026-04-16 05:23:04', NULL, 2710.00, 'pending', '13.929637,120.8115693', 26, '2026-04-16 05:23:04', '2026-04-16 05:23:04', NULL, NULL),
(74, 'SO-20260416-8390998', 44, 1, '2026-04-16 11:19:50', NULL, 2820.00, 'pending', '13.888853000000001,120.978951', 5, '2026-04-16 11:19:50', '2026-04-16 11:19:50', NULL, NULL),
(75, 'SO-20260417-7103285', 55, 10, '2026-04-17 06:25:03', NULL, 56706.00, 'confirmed', '14.4161667,120.9931796', 46, '2026-04-16 16:00:00', '2026-04-17 06:33:03', NULL, NULL),
(76, 'SO-20260417-7295426', 56, 7, '2026-04-17 06:28:15', NULL, 149760.00, 'pending', '13.9256883,121.132207', 37, '2026-04-17 06:28:15', '2026-04-17 06:28:15', NULL, NULL),
(77, 'SO-20260417-2176169', 60, 7, '2026-04-17 07:49:36', NULL, 249600.00, 'pending', '13.8953573,121.0459768', 37, '2026-04-17 07:49:36', '2026-04-17 07:49:36', NULL, NULL),
(80, 'SO-20260420-4629421', 16, 1, '2026-04-20 05:57:09', NULL, 95000.00, 'confirmed', '13.882222089836677,121.03630443839927', 5, '2026-04-19 16:00:00', '2026-04-20 05:58:44', NULL, NULL),
(88, 'SO-20260420-1055587', 16, 1, '2026-04-20 07:44:15', NULL, 1662500.00, 'pending', '13.88223079245571,121.03630897113622', 5, '2026-04-20 07:44:15', '2026-04-20 07:44:15', NULL, NULL),
(89, 'SO-20260420-0164967', 72, 1, '2026-04-20 13:02:44', NULL, 2400.00, 'pending', NULL, 2, '2026-04-20 13:02:44', '2026-04-20 13:02:44', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sales_order_items`
--

CREATE TABLE `sales_order_items` (
  `so_item_id` int(11) NOT NULL,
  `so_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `unit_type` varchar(50) DEFAULT 'piece',
  `quantity_ordered` int(11) NOT NULL,
  `quantity_delivered` int(11) DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) GENERATED ALWAYS AS (`quantity_ordered` * `unit_price`) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_order_items`
--

INSERT INTO `sales_order_items` (`so_item_id`, `so_id`, `item_id`, `unit_type`, `quantity_ordered`, `quantity_delivered`, `unit_price`) VALUES
(2, 15, 1, 'piece', 1, 0, 100.00),
(3, 16, 1, 'piece', 4, 0, 100.00),
(4, 17, 1, 'piece', 4, 0, 100.00),
(5, 18, 2, 'piece', 3, 0, 150.00),
(8, 21, 4, 'piece', 1, 0, 75.00),
(9, 22, 4, 'piece', 1, 0, 75.00),
(10, 22, 5, 'piece', 1, 0, 250.00),
(11, 23, 2, 'piece', 2, 0, 150.00),
(12, 24, 4, 'piece', 3, 0, 75.00),
(13, 25, 5, 'piece', 1, 0, 250.00),
(14, 26, 1, 'piece', 2, 0, 100.00),
(15, 26, 2, 'piece', 5, 0, 150.00),
(16, 26, 5, 'piece', 3, 0, 250.00),
(17, 27, 1, 'piece', 2, 0, 100.00),
(18, 27, 2, 'piece', 2, 0, 150.00),
(19, 27, 5, 'piece', 2, 0, 250.00),
(20, 28, 1, 'piece', 1, 0, 100.00),
(21, 29, 1, 'piece', 10, 0, 100.00),
(22, 29, 2, 'piece', 5, 0, 150.00),
(23, 29, 5, 'piece', 12, 0, 250.00),
(24, 30, 5, 'piece', 5, 0, 250.00),
(25, 35, 1, 'inner-pack', 1, 0, 600.00),
(26, 36, 1, 'piece', 1, 0, 100.00),
(27, 37, 1, 'piece', 100, 0, 100.00),
(28, 38, 1, 'case', 2, 0, 1200.00),
(29, 38, 2, 'piece', 13, 0, 150.00),
(30, 38, 5, 'piece', 25, 0, 250.00),
(31, 39, 6, 'case', 4, 0, 480.00),
(32, 40, 6, 'inner-pack', 2, 0, 240.00),
(33, 41, 6, 'case', 1, 0, 480.00),
(34, 42, 6, 'case', 1, 0, 480.00),
(35, 43, 6, 'piece', 5, 0, 40.00),
(36, 44, 6, 'inner-pack', 1, 0, 240.00),
(37, 45, 6, 'case', 1, 0, 480.00),
(38, 46, 6, 'box', 2, 0, 960.00),
(39, 47, 7, 'Bag', 1, 0, 350.00),
(40, 48, 10, 'Bag', 2, 0, 350.00),
(41, 49, 10, 'Tonner', 2, 0, 8750.00),
(42, 50, 10, 'Bag', 1, 0, 350.00),
(43, 51, 8, 'Bundle', 2, 0, 240.00),
(44, 52, 8, 'Bundle', 2, 0, 240.00),
(45, 53, 9, 'Piece', 20, 0, 20.00),
(48, 56, 10, 'Tonner', 2, 0, 8400.00),
(49, 57, 10, 'Tonner', 2, 0, 8400.00),
(50, 58, 10, 'Tonner', 2, 0, 8400.00),
(51, 59, 1, 'Tonner', 1, 0, 8400.00),
(52, 59, 10, 'Bag', 5, 0, 350.00),
(53, 60, 9, 'Bundle', 2, 0, 240.00),
(54, 61, 10, 'Tonner', 1, 0, 8400.00),
(56, 63, 56, 'Piece', 3, 0, 250.00),
(57, 64, 65, 'DRM', 15, 0, 3427.00),
(58, 64, 64, 'DRM', 10, 0, 3462.00),
(59, 64, 63, 'PAIL', 5, 0, 1051.00),
(60, 64, 62, 'PAIL', 15, 0, 1060.00),
(61, 64, 46, 'Dozen', 50, 0, 375.00),
(62, 64, 53, 'Dozen', 58, 0, 560.00),
(63, 64, 51, 'container', 20, 0, 1606.00),
(64, 65, 66, 'Bag', 660, 0, 208.00),
(65, 66, 66, 'Bag', 2, 0, 208.00),
(66, 67, 73, 'DOZEN', 500, 0, 360.00),
(67, 68, 76, 'PIECE', 2, 0, 3400.00),
(68, 68, 75, 'BY 7', 2, 0, 970.00),
(69, 69, 76, 'PIECE', 100, 0, 3392.00),
(70, 70, 86, 'Dozen', 50, 0, 378.00),
(71, 71, 86, 'Dozen', 1, 0, 377.00),
(72, 71, 83, 'x7', 1, 0, 978.00),
(73, 72, 83, 'x7', 1, 0, 978.00),
(74, 73, 83, 'x7', 2, 0, 978.00),
(75, 73, 86, 'Dozen', 2, 0, 377.00),
(76, 74, 10, 'Bag', 4, 0, 355.00),
(77, 74, 1, 'Bag', 4, 0, 350.00),
(78, 75, 94, 'dozen', 100, 0, 380.00),
(79, 75, 95, 'dozen', 50, 0, 374.12),
(80, 76, 66, 'Bag', 720, 0, 208.00),
(81, 77, 66, 'Bag', 1200, 0, 208.00),
(87, 80, 8, 'Piece', 5000, 0, 20.00),
(95, 88, 10, 'Bag', 5000, 0, 350.00),
(96, 89, 6, 'piece', 20, 0, 120.00);

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
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `supplier_code` varchar(50) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `mobile_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `payment_terms` varchar(100) DEFAULT 'Net 30',
  `credit_limit` decimal(12,2) DEFAULT 0.00,
  `website` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive','pending') DEFAULT 'active',
  `branch_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `region_code` varchar(50) DEFAULT NULL,
  `region_name` varchar(255) DEFAULT NULL,
  `province_code` varchar(50) DEFAULT NULL,
  `province_name` varchar(255) DEFAULT NULL,
  `city_code` varchar(50) DEFAULT NULL,
  `city_name` varchar(255) DEFAULT NULL,
  `barangay_code` varchar(50) DEFAULT NULL,
  `barangay_name` varchar(255) DEFAULT NULL,
  `street_address` text DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `barangay` varchar(255) DEFAULT NULL,
  `full_address` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `vat_classification` enum('VAT Registered','Non-VAT','Zero Rated','Exempt') DEFAULT 'VAT Registered'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_code`, `supplier_name`, `contact_person`, `email`, `phone_number`, `mobile_number`, `address`, `city`, `province`, `zip_code`, `tax_id`, `payment_terms`, `credit_limit`, `website`, `notes`, `status`, `branch_id`, `created_by`, `created_at`, `updated_at`, `region_code`, `region_name`, `province_code`, `province_name`, `city_code`, `city_name`, `barangay_code`, `barangay_name`, `street_address`, `region`, `barangay`, `full_address`, `latitude`, `longitude`, `vat_classification`) VALUES
(1, 'SUP-202603-0001', 'Jill Anuran', 'John Nestor Anuran', 'jill@gmail.com', '09758465732', '09758465738', NULL, 'Taal', 'Batangas', NULL, '1000-000-1001', 'Net 30', 50000.00, '', '', 'active', 1, 2, '2026-03-17 01:33:40', '2026-03-17 01:33:40', NULL, NULL, NULL, NULL, '041029000', NULL, NULL, NULL, 'Latag, Taal, Batangas', 'Region IV-A', 'Latag', 'Latag, Taal, Batangas, Latag, Taal, Batangas, Region IV-A', 13.89196700, 120.92855300, 'VAT Registered'),
(2, 'SUP-202604-0001', 'JOY DEROXAS', 'JOY DE ROXAS', '', '0926 4222222', '0926422222', NULL, 'Cuenca', 'Batangas', NULL, '', 'Net 30', 0.00, '', '', 'active', 4, 32, '2026-04-14 04:50:39', '2026-04-14 04:50:39', NULL, NULL, NULL, NULL, '041009000', NULL, NULL, NULL, '', 'Region IV-A', 'San Felipe', 'San Felipe, Cuenca, Batangas, Region IV-A', NULL, NULL, 'VAT Registered');

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
  `photo_1` varchar(250) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trip_tickets`
--

INSERT INTO `trip_tickets` (`trip_id`, `trip_number`, `so_id`, `picklist_id`, `driver_id`, `branch_id`, `trip_date`, `trip_status`, `start_time`, `end_time`, `total_stops`, `total_delivered`, `total_failed`, `remarks`, `created_by`, `created_at`, `updated_at`, `photo_1`, `assigned_at`, `assigned_by`) VALUES
(7, 'TT-20260216-00025', 25, 16, 1, 1, '2026-02-16', 'delayed', NULL, NULL, 1, 1, 0, '0', 2, '2026-02-16 01:51:59', '2026-03-04 03:10:41', NULL, NULL, NULL),
(8, 'TT-20260216-00024', 24, 18, 1, 1, '2026-02-16', 'completed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-16 01:53:36', '2026-02-16 07:50:49', NULL, NULL, NULL),
(9, 'TT-20260216-00027', 27, 20, 4, 1, '2026-02-16', 'completed', NULL, NULL, 3, 6, 0, '0', 2, '2026-02-16 06:24:51', '2026-02-18 05:50:21', NULL, NULL, NULL),
(11, 'TT-20260218-00022', 22, 22, 1, 1, '2026-02-18', 'completed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-18 03:55:27', '2026-03-04 02:53:49', NULL, NULL, NULL),
(12, 'TT-20260218-00021', 21, 23, 1, 1, '2026-02-18', 'completed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-02-18 05:20:23', '2026-02-19 02:26:38', NULL, NULL, NULL),
(13, 'TT-20260304-00030', 30, 24, 1, 1, '2026-03-04', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-04 05:58:50', '2026-03-04 05:58:50', NULL, NULL, NULL),
(14, 'TT-20260305-00035', 35, 25, 8, 1, '2026-03-05', 'completed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-06 00:49:10', '2026-03-09 01:08:21', NULL, NULL, NULL),
(15, 'TT-20260305-00036', 36, 26, 8, 1, '2026-03-05', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-06 01:44:13', '2026-03-06 01:44:13', NULL, NULL, NULL),
(16, 'TT-20260306-00037', 37, 27, 8, 1, '2026-03-06', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-06 07:57:48', '2026-03-06 07:57:48', NULL, NULL, NULL),
(17, 'TT-20260309-00038', 38, 28, 8, 1, '2026-03-09', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-09 06:45:31', '2026-03-09 06:45:31', NULL, NULL, NULL),
(18, 'TT-20260309-00039', 39, 29, 8, 1, '2026-03-09', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-09 23:45:51', '2026-03-09 23:45:51', NULL, NULL, NULL),
(19, 'TT-20260309-00041', 41, 30, 1, 1, '2026-03-09', 'completed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-10 00:26:17', '2026-04-07 02:48:00', NULL, NULL, NULL),
(20, 'TT-20260309-00042', 42, 31, 8, 1, '2026-03-09', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-10 00:44:46', '2026-03-10 00:44:46', NULL, NULL, NULL),
(21, 'TT-20260309-00040', 40, 32, 8, 1, '2026-03-09', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-10 01:17:33', '2026-03-10 01:17:33', NULL, NULL, NULL),
(22, 'TT-20260310-00043', 43, 33, 1, 1, '2026-03-10', 'planned', NULL, NULL, 1, 5, 0, '0', 2, '2026-03-10 05:25:18', '2026-04-06 23:43:10', NULL, NULL, NULL),
(23, 'TT-20260310-00029', 29, 34, 8, 1, '2026-03-10', 'completed', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-03-11 02:37:57', '2026-03-31 02:03:28', NULL, NULL, NULL),
(24, 'TT-20260331-00044', 44, 35, 1, 1, '2026-03-31', 'completed', NULL, NULL, 1, 0, 1, '0', 2, '2026-03-31 13:47:21', '2026-04-06 23:41:54', NULL, NULL, NULL),
(25, 'TT-20260410-00058', 58, 36, 1, 1, '2026-04-10', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-04-10 00:16:37', '2026-04-10 00:16:37', NULL, NULL, NULL),
(26, 'TT-20260410-00057', 57, 37, 1, 1, '2026-04-10', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-04-10 00:17:37', '2026-04-10 00:17:37', NULL, NULL, NULL),
(27, 'TT-20260410-00059', 59, 38, 1, 1, '2026-04-10', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-04-10 00:22:24', '2026-04-10 00:22:24', NULL, NULL, NULL),
(28, 'TT-20260414-00063', 63, 39, 11, 4, '2026-04-14', 'completed', NULL, NULL, NULL, 0, 0, NULL, 32, '2026-04-14 05:11:03', '2026-04-14 05:14:42', NULL, NULL, NULL),
(29, 'TT-20260416-00070', 70, 40, 12, 3, '2026-04-16', 'planned', NULL, NULL, NULL, 0, 0, NULL, 39, '2026-04-16 02:09:28', '2026-04-16 02:09:28', NULL, NULL, NULL),
(30, 'TT-20260416-00056', 56, 41, 10, 1, '2026-04-16', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-04-16 11:48:59', '2026-04-16 11:48:59', NULL, NULL, NULL),
(31, 'TT-20260417-00075', 75, 42, 13, 10, '2026-04-17', 'planned', NULL, NULL, NULL, 0, 0, NULL, 45, '2026-04-17 06:33:03', '2026-04-17 06:33:03', NULL, NULL, NULL),
(34, 'TT-20260420-00080', 80, 45, 10, 1, '2026-04-20', 'planned', NULL, NULL, NULL, 0, 0, NULL, 2, '2026-04-20 05:58:44', '2026-04-20 05:58:44', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `unit_types`
--

CREATE TABLE `unit_types` (
  `unit_type_id` int(11) NOT NULL,
  `unit_type_name` varchar(100) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `quantity_smallest_pack` int(11) DEFAULT 1,
  `is_default_uom` tinyint(4) DEFAULT 0,
  `description` text DEFAULT NULL,
  `multiplier` decimal(10,2) DEFAULT 1.00,
  `branch_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unit_types`
--

INSERT INTO `unit_types` (`unit_type_id`, `unit_type_name`, `barcode`, `quantity_smallest_pack`, `is_default_uom`, `description`, `multiplier`, `branch_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Piece', '', 1, 1, NULL, 1.00, 1, 'active', '2026-04-01 01:12:13', '2026-04-20 07:37:25'),
(2, 'Bundle', '', 12, 0, NULL, 1.00, 1, 'active', '2026-04-01 01:12:13', '2026-04-12 13:38:56'),
(3, 'Bag', '', 1, 1, NULL, 1.00, 1, 'active', '2026-04-01 01:52:06', '2026-04-13 01:18:02'),
(4, 'Tonner', '', 25, 0, NULL, 1.00, 1, 'active', '2026-04-01 01:52:06', '2026-04-13 01:18:02'),
(5, 'Case', '', 12, 0, NULL, 1.00, 1, 'active', '2026-04-08 13:08:16', '2026-04-11 05:51:23'),
(6, 'Piece', '', 1, 1, NULL, 1.00, 0, 'active', '2026-04-10 07:48:26', '2026-04-10 07:48:26'),
(7, 'Case', '', 12, 0, NULL, 1.00, 0, 'active', '2026-04-10 07:48:26', '2026-04-11 06:25:48'),
(8, 'Inner Pack', '', 6, 0, NULL, 1.00, 1, 'active', '2026-04-11 05:51:23', '2026-04-11 09:12:37'),
(9, 'Carton', '', 24, 0, NULL, 1.00, 1, 'active', '2026-04-11 05:51:23', '2026-04-11 09:12:37'),
(10, 'Box', '', 12, 0, NULL, 1.00, 1, 'active', '2026-04-11 09:12:37', '2026-04-11 09:12:37'),
(11, 'Dozen', '', 1, 1, NULL, 1.00, 4, 'active', '2026-04-14 01:45:26', '2026-04-14 02:22:20'),
(12, 'Piece', '', 1, 1, NULL, 1.00, 4, 'active', '2026-04-14 01:53:11', '2026-04-14 02:34:17'),
(13, 'By /10', '', 10, 0, NULL, 1.00, 4, 'active', '2026-04-14 01:53:11', '2026-04-14 01:53:11'),
(14, 'by 10', '', 10, 0, NULL, 1.00, 4, 'active', '2026-04-14 01:59:01', '2026-04-14 01:59:01'),
(15, 'container', '', 1, 1, NULL, 1.00, 4, 'active', '2026-04-14 02:07:55', '2026-04-14 03:02:37'),
(16, 'KLS.', '', 1, 1, NULL, 1.00, 4, 'active', '2026-04-14 02:28:25', '2026-04-14 03:12:37'),
(17, 'PAIL', '', 1, 1, NULL, 1.00, 4, 'active', '2026-04-14 03:18:15', '2026-04-14 03:19:46'),
(18, 'DRM', '', 1, 1, NULL, 1.00, 4, 'active', '2026-04-14 03:21:54', '2026-04-14 03:23:17'),
(19, 'Bag', '', 1, 1, NULL, 1.00, 7, 'active', '2026-04-15 07:52:12', '2026-04-15 08:05:44'),
(20, 'DOZEN', '', 1, 1, NULL, 1.00, 5, 'active', '2026-04-15 08:51:02', '2026-04-16 01:54:36'),
(21, 'PC', '', 1, 0, NULL, 1.00, 5, 'active', '2026-04-15 08:59:24', '2026-04-16 01:56:58'),
(22, 'BY 7', '', 1, 0, NULL, 1.00, 5, 'active', '2026-04-15 08:59:24', '2026-04-16 01:56:58'),
(23, 'BY 10', '', 1, 1, NULL, 1.00, 5, 'active', '2026-04-15 08:59:24', '2026-04-16 01:56:58'),
(24, 'PIECE', '', 1, 1, NULL, 1.00, 5, 'active', '2026-04-15 09:01:14', '2026-04-15 09:01:14'),
(25, 'Dozen', '', 1, 1, NULL, 1.00, 3, 'active', '2026-04-16 01:33:24', '2026-04-16 02:01:12'),
(26, 'x7', '', 1, 1, NULL, 1.00, 3, 'active', '2026-04-16 01:38:05', '2026-04-16 01:38:05'),
(27, 'Bundle', '', 1, 1, NULL, 1.00, 3, 'active', '2026-04-16 01:53:07', '2026-04-16 01:53:07'),
(28, 'piece', '', 1, 1, NULL, 1.00, 3, 'active', '2026-04-16 01:54:34', '2026-04-16 01:54:34'),
(29, 'piece', '', 1, 0, NULL, 1.00, 10, 'active', '2026-04-17 05:53:29', '2026-04-20 03:11:42'),
(30, 'dozen', '', 12, 1, NULL, 1.00, 10, 'active', '2026-04-17 05:53:29', '2026-04-20 03:11:42'),
(31, 'BUNDLE', '', 10, 1, NULL, 1.00, 10, 'active', '2026-04-17 06:23:52', '2026-04-20 03:07:29'),
(32, 'By6', '', 6, 0, NULL, 1.00, 1, 'active', '2026-04-20 00:32:16', '2026-04-20 07:37:25');

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
  `category` enum('Oil','Cement','General') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `email`, `password_hash`, `first_name`, `last_name`, `role`, `department`, `status`, `created_at`, `updated_at`, `branch_id`, `driver_id`, `contact_number`, `category`) VALUES
(1, 'admin@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Admin', 'User', 'admin', 'Management', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1, NULL, NULL, NULL),
(2, 'branch1@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Branch', 'Manager', 'branch_admin', 'Branch 1', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1, NULL, NULL, NULL),
(3, 'warehouse@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Warehouse', 'Manager', 'warehouse', 'Warehouse', 'active', '2026-02-10 01:38:25', '2026-03-05 01:00:39', 1, NULL, NULL, 'General'),
(5, 'sales@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Sales', 'Officer', 'sales', 'Sales', 'active', '2026-02-10 01:38:25', '2026-02-11 03:12:51', 1, NULL, NULL, NULL),
(6, 'vanbathan576@gmail.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Van Exel', 'Bathan', 'branch_admin', 'Branch 2', 'active', '2026-02-10 02:22:15', '2026-02-13 05:18:32', 2, NULL, NULL, NULL),
(7, 'ross@gmail.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Ross Andrei', 'Dolor', 'branch_admin', 'Calaca Branch', 'active', '2026-02-12 07:59:58', '2026-02-12 07:59:58', 2, NULL, NULL, NULL),
(8, 'juan.santos@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Juan', 'Santos', 'delivery', 'Delivery', 'active', '2026-02-18 03:52:23', '2026-02-18 03:52:23', 1, 1, NULL, NULL),
(10, 'pedro.reyes@amgc.com', '$2y$10$l6UbK0Nw645/SuS6i7tMW.FFcoIK5E8dDDh5vpKzRHba1ETkI5kIS', 'Pedro', 'Reyes', 'delivery', 'Delivery', 'active', '2026-02-18 03:52:23', '2026-02-18 03:52:23', 2, 3, NULL, NULL),
(15, 'rdolor@gmail.com', '$2y$10$fphxKrwxmtkTPSMK3rdii.yHb0gAE2hThRx/qWc1hSDgQXbPh7lqW', 'Dolor', 'Ross', 'sales', NULL, 'active', '2026-02-23 05:52:33', '2026-02-23 05:52:33', 1, NULL, '09296072494', NULL),
(16, 'llorin@amgc.com', '$2y$10$XlsVXoxHqjgRv2A2uKnk.OnPkkI2HbapH/aXsWd4GsXI75RpuCJbW', 'Llorin', 'llorin', 'delivery', NULL, 'active', '2026-02-23 05:53:10', '2026-02-23 05:53:10', 1, 8, '09878761234', NULL),
(17, 'rossandrei706@gmail.com', '$2y$10$r79Hgg2CmwkT0N0Xh3haAOof9k5pEbPTrmg.oJPDBvO1wR4L/AdkK', 'Ross Andrei', 'Dolor', 'warehouse', NULL, 'active', '2026-02-23 06:02:01', '2026-04-15 06:20:49', 1, NULL, '09296072494', 'Oil'),
(18, 'serranomarkangelo706@gmail.com', '$2y$10$F78VD.SN78dkX13OqKuoH.CDk59AsXKAuH//pIR7y5z.lgHTInPuO', 'Mark Angelo', 'Serrano', 'warehouse', NULL, 'active', '2026-02-23 07:25:15', '2026-03-06 01:03:14', 1, NULL, '09296072493', 'Cement'),
(19, 'dolor@amgc.com', '$2y$10$WVP6KhoOta6ghx6BrA3.AeKOAD1TPWzYPDlf7Mj5Aj6.SAHj6W6RK', 'Andrei', 'Dolor', 'delivery', NULL, 'active', '2026-03-06 02:50:10', '2026-03-06 02:50:10', 1, 10, '09876785647', NULL),
(20, 'erickson@amgc.com', '$2y$10$v1A9GBjOnsM0Mg.DOF/hvO9OVbu6ByoQbSBpPKBVpyU0Bc2NQmNFq', 'Erickson', 'Raymundo', 'sales', NULL, 'active', '2026-03-09 00:34:32', '2026-04-15 08:22:05', 7, NULL, '09623946181', NULL),
(21, 'alvic@amgc.com', '$2y$10$20oIXqKXxCLkp87u6pbbZ.A0c4WcEmLqC5mucSMfmygBbSWZYALLO', 'Alvic', 'Mallari', 'sales', NULL, 'active', '2026-04-07 02:44:32', '2026-04-15 07:41:55', 7, NULL, NULL, NULL),
(22, 'carlos@amgc.com', '$2y$10$H.7PqeXvDfnXs93/aLuHHeeZT77.coS8ty81BQs13IZ4TgVM.j1Hu', 'Carlos', 'Macalindong', 'sales', NULL, 'active', '2026-04-07 02:45:57', '2026-04-07 02:45:57', 1, NULL, NULL, NULL),
(23, 'harold@amgc.com', '$2y$10$oflCB/a4s8O2Vl9n4oGa5OBLBSU7vc9IqAKmk0.qnlkn/fZFIe1GK', 'Harold', 'Macalindong', 'sales', NULL, 'active', '2026-04-07 02:47:31', '2026-04-07 02:47:31', 1, NULL, NULL, NULL),
(24, 'jack@amgc.com', '$2y$10$OqxrycZjxmBcSYs7Lyjlxenbr/W5VfAN.dDoF8DCIUWSNzvxf6i42', 'Jack', 'Casalla', 'sales', NULL, 'active', '2026-04-07 02:48:16', '2026-04-07 02:48:16', 1, NULL, NULL, NULL),
(25, 'norwin@amgc.com', '$2y$10$GNmJZ37LB48fUhC.LEfnHu6ieT7CP5.yp5h/QFHhc.VCaseJb7bw6', 'Norwin', 'Magpantay', 'sales', NULL, 'active', '2026-04-07 02:48:48', '2026-04-07 02:48:48', 1, NULL, NULL, NULL),
(26, 'erika@amgc.com', '$2y$10$PCgN27KYVXZBCtmQP3.ZyOkNTsYcXk7l6ucPmLLxy.Kes3an9drB.', 'Erika', 'Baral', 'sales', NULL, 'active', '2026-04-07 02:49:29', '2026-04-16 01:09:23', 3, NULL, NULL, NULL),
(27, 'jonathan@amgc.com', '$2y$10$4DQ2VjhQyFc22lY/g0r2XOb8R/np.a5bYML/V3LVPtOJJSHSsdy8S', 'Jonathan', 'Castro', 'sales', NULL, 'active', '2026-04-07 02:50:14', '2026-04-15 08:36:10', 5, NULL, NULL, NULL),
(28, 'elizle@amgc.com', '$2y$10$vbUdJpBNCj3M/aisOoaQq.bhVN7dTbX0oL7ZGnoX.0o8jkCXbLhpO', 'Elizle', 'Torres', 'sales', NULL, 'active', '2026-04-07 02:52:01', '2026-04-15 08:37:45', 5, NULL, NULL, NULL),
(29, 'jr@amgc.com', '$2y$10$lEwx9JKH3E5IPDvYJym28.dv3ytPIXGmi8TxUQMqO5Q6dat/F8zyS', 'Jr', 'Macalindong', 'sales', NULL, 'active', '2026-04-07 02:57:06', '2026-04-07 02:57:06', 1, NULL, NULL, NULL),
(30, 'branch2@amgc.com', '$2y$10$AOFJOe4wC0gf0V6VRSN4XOs/hCIcef0BVRHbwlhTriRKCoXxiqbb6', 'North', 'Branch', 'branch_admin', NULL, 'active', '2026-04-10 14:28:12', '2026-04-10 14:28:12', 2, NULL, '09876567453', NULL),
(31, 'sales1@amgc.com', '$2y$10$61rJKsUMwN6kzrseOk0LpuPBYCT5oXe7UApQev9ZzIzAvCsB73jUq', 'North', 'Branch', 'sales', NULL, 'active', '2026-04-10 14:29:15', '2026-04-10 14:29:15', 2, NULL, '09876567450', NULL),
(32, 'gumaca@amgc.com', '$2y$10$5mzga8PAA6gow3QllCHyt.55XfXMDsamMbqYDv1t5H3dONW4w1zuq', 'Gumaca', 'Branch', 'branch_admin', NULL, 'active', '2026-04-14 01:25:54', '2026-04-14 01:25:54', 4, NULL, NULL, NULL),
(33, 'norwinmagpantay@amgc.com', '$2y$10$xt6NrF330nTEulG/nPgkbeDe042gTwm8ilu6Rr2zyo/SSPMy9NToa', 'Norwin', 'Magpantay', 'sales', NULL, 'active', '2026-04-14 01:34:37', '2026-04-14 01:34:37', 4, NULL, '09054010651', NULL),
(34, 'john@gmail.com', '$2y$10$Yvx4..NpSi4VIY/U9c1TqOfPJlajb4KQWLUzDdRExY8ymsvFoYFpW', 'John', 'John', 'delivery', NULL, 'active', '2026-04-14 05:07:27', '2026-04-14 05:07:27', 4, 11, '09756475864', NULL),
(35, 'cuenca@amgc.com', '$2y$10$uLBYcEjjekQ.AMrESqQ6qu8EIXlK0P48bKrDYPGgpG1XdxhnkLD5m', 'Cuenca', 'Branch', 'branch_admin', NULL, 'active', '2026-04-15 07:25:08', '2026-04-15 07:25:08', 5, NULL, '09464407160', NULL),
(36, 'cement@amgc.com', '$2y$10$0q3czcaGrhLtnDCVtw8ZNuwoU9tbAN14DU/Goko3tjx86LUYFbQIm', 'Cuenca Branch', 'Cement', 'branch_admin', NULL, 'active', '2026-04-15 07:41:32', '2026-04-15 07:41:32', 7, NULL, NULL, NULL),
(37, 'ronaldona@amgc.com', '$2y$10$QjbCEIFb3opZiNUEqwBKFeo3t57PcBWrwo7j1h1CQEMcyINCku5vu', 'Ronald', 'Ona', 'sales', NULL, 'active', '2026-04-15 08:16:54', '2026-04-15 08:16:54', 7, NULL, NULL, NULL),
(38, 'markalvarez@amgc.com', '$2y$10$jnYxQh0QEh9VB4MLRnxxTudYnNpf0d04bsARmDSMQYvhLXvmWzunO', 'Mark Anthony', 'Alvarez', 'sales', NULL, 'active', '2026-04-15 08:38:28', '2026-04-15 09:03:10', 5, NULL, NULL, NULL),
(39, 'calaca@amgc.com', '$2y$10$iiDaIvZrIxvIH8hId97woezfsBDWtsCrWN31OuhQMgu2RZfqlHc6C', 'Calaca', 'Branch', 'branch_admin', NULL, 'active', '2026-04-16 01:09:59', '2026-04-16 01:09:59', 3, NULL, NULL, NULL),
(40, 'benidict@amgc.com', '$2y$10$jUnovU5O58HyZ./0dHn8iOMfQ/elCN13oIVkBg7BNX1FVXqhHY5ym', 'Benidict', 'Baral', 'delivery', NULL, 'active', '2026-04-16 01:16:53', '2026-04-16 01:16:53', 3, 12, '09193990531', NULL),
(41, 'viaandal@amgc.com', '$2y$10$udSEbKi5WxE6LkY5UKPlbO0Q0t1kwcYwLyKGA3zcAXlUR6DaN7Pfu', 'Via', 'Andal', 'branch_admin', NULL, 'active', '2026-04-16 04:09:13', '2026-04-16 04:09:13', 8, NULL, '09971042747', NULL),
(42, 'kimyongco@amgc.com', '$2y$10$LCLnUh0zPcw3QnDsuLFG3u10layGloisMrrWnu9jsctMGDskpM7Vu', 'Kimberly', 'Yongco', 'branch_admin', NULL, 'active', '2026-04-16 04:15:00', '2026-04-16 04:15:00', 9, NULL, NULL, NULL),
(43, 'yheyeyongco@amgc.com', '$2y$10$5h0m0gYRWeijzqTbeiDTU.lrBASwlgBIA2p3Olcb3ykB8utlvTU2C', 'Jay Mark', 'Yongco', 'sales', NULL, 'active', '2026-04-16 04:15:32', '2026-04-16 04:15:32', 9, NULL, NULL, NULL),
(44, 'ryanmangubat@amgc.com', '$2y$10$dB.Hy6JUVtasa6K4/NpovO1ax/L7CZQ/N8DFtV8Gz1vZGFBn9Wd.C', 'Ryan', 'Mangubat', 'sales', NULL, 'active', '2026-04-16 04:16:12', '2026-04-16 04:16:12', 9, NULL, NULL, NULL),
(45, 'laspinas@amgc.com', '$2y$10$deL67JkXd3oDNZrveqVLF.tbtZ/5tHz3qEO/Iu9xfUNLzdZQpBYli', 'Las Piñas', 'Branch', 'branch_admin', NULL, 'active', '2026-04-17 05:18:43', '2026-04-17 05:18:43', 10, NULL, NULL, NULL),
(46, 'jrmacalindong@amgc.com', '$2y$10$mf8rpgrS8W7q8RbPVPSsEeU332gsQbxbx6Ma79andSxyobJKcQiuK', 'Rolando Jr.', 'Macalindong', 'sales', NULL, 'active', '2026-04-17 05:40:19', '2026-04-17 05:44:00', 10, NULL, '09179263362', NULL),
(47, 'mark18@amgc.com', '$2y$10$dwlbagx6mHs12gvq9/aN4OH16BZRH0YiW0AudJGp6EF1uc29Ka68K', 'MARK', 'LALAGUNA', 'delivery', NULL, 'active', '2026-04-17 06:30:38', '2026-04-17 06:30:38', 10, 13, '09179263362', NULL);

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
-- Indexes for table `credit_discount_attachments`
--
ALTER TABLE `credit_discount_attachments`
  ADD PRIMARY KEY (`attachment_id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `credit_discount_requests`
--
ALTER TABLE `credit_discount_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD KEY `idx_customers_created_by` (`created_by`);

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
-- Indexes for table `driver_locations`
--
ALTER TABLE `driver_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_driver` (`driver_id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_last_update` (`last_update`),
  ADD KEY `trip_id` (`trip_id`);

--
-- Indexes for table `driver_sessions`
--
ALTER TABLE `driver_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_online` (`is_online`),
  ADD KEY `idx_shift_start` (`shift_start`),
  ADD KEY `trip_id` (`trip_id`);

--
-- Indexes for table `driver_tracking`
--
ALTER TABLE `driver_tracking`
  ADD PRIMARY KEY (`tracking_id`);

--
-- Indexes for table `instructor_shifts`
--
ALTER TABLE `instructor_shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_instructor_id` (`instructor_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_shift_start` (`shift_start`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD KEY `paid_by` (`paid_by`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `fk_items_default_uom` (`default_uom_id`),
  ADD KEY `fk_items_smallest_uom` (`smallest_uom_id`),
  ADD KEY `fk_items_created_by` (`created_by`),
  ADD KEY `fk_items_updated_by` (`updated_by`);

--
-- Indexes for table `item_images`
--
ALTER TABLE `item_images`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `idx_item_id` (`item_id`);

--
-- Indexes for table `item_unit_pricing`
--
ALTER TABLE `item_unit_pricing`
  ADD PRIMARY KEY (`pricing_id`),
  ADD UNIQUE KEY `item_unit_price_level_unique` (`item_id`,`unit_type_id`,`price_level`),
  ADD KEY `idx_effective_date` (`effective_date`),
  ADD KEY `idx_price_level` (`price_level`);

--
-- Indexes for table `item_unit_types`
--
ALTER TABLE `item_unit_types`
  ADD PRIMARY KEY (`unit_type_id`),
  ADD KEY `idx_item_id` (`item_id`);

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
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`),
  ADD KEY `supplier_id` (`supplier_id`);

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
-- Indexes for table `rsr_reports`
--
ALTER TABLE `rsr_reports`
  ADD PRIMARY KEY (`rsr_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `reported_by` (`reported_by`);

--
-- Indexes for table `sales_orders`
--
ALTER TABLE `sales_orders`
  ADD PRIMARY KEY (`so_id`),
  ADD KEY `confirmed_by` (`confirmed_by`);

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
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_supplier_code` (`supplier_code`),
  ADD KEY `idx_supplier_name` (`supplier_name`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_region_code` (`region_code`),
  ADD KEY `idx_province_code` (`province_code`),
  ADD KEY `idx_city_code` (`city_code`),
  ADD KEY `idx_barangay_code` (`barangay_code`);

--
-- Indexes for table `trip_tickets`
--
ALTER TABLE `trip_tickets`
  ADD PRIMARY KEY (`trip_id`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `unit_types`
--
ALTER TABLE `unit_types`
  ADD PRIMARY KEY (`unit_type_id`),
  ADD UNIQUE KEY `unit_type_branch` (`unit_type_name`,`branch_id`);

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
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `credit_discount_attachments`
--
ALTER TABLE `credit_discount_attachments`
  MODIFY `attachment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `credit_discount_requests`
--
ALTER TABLE `credit_discount_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `delivery_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `driver_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `driver_locations`
--
ALTER TABLE `driver_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `driver_sessions`
--
ALTER TABLE `driver_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `driver_tracking`
--
ALTER TABLE `driver_tracking`
  MODIFY `tracking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1727;

--
-- AUTO_INCREMENT for table `instructor_shifts`
--
ALTER TABLE `instructor_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=113;

--
-- AUTO_INCREMENT for table `item_images`
--
ALTER TABLE `item_images`
  MODIFY `image_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `item_unit_pricing`
--
ALTER TABLE `item_unit_pricing`
  MODIFY `pricing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=388;

--
-- AUTO_INCREMENT for table `item_unit_types`
--
ALTER TABLE `item_unit_types`
  MODIFY `unit_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `pick_lists`
--
ALTER TABLE `pick_lists`
  MODIFY `pick_list_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `pick_list_items`
--
ALTER TABLE `pick_list_items`
  MODIFY `pick_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `image_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `po_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `rmr_approvals`
--
ALTER TABLE `rmr_approvals`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rmr_requests`
--
ALTER TABLE `rmr_requests`
  MODIFY `rmr_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `rsr_reports`
--
ALTER TABLE `rsr_reports`
  MODIFY `rsr_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sales_orders`
--
ALTER TABLE `sales_orders`
  MODIFY `so_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  MODIFY `so_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `sales_reports`
--
ALTER TABLE `sales_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `trip_tickets`
--
ALTER TABLE `trip_tickets`
  MODIFY `trip_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `unit_types`
--
ALTER TABLE `unit_types`
  MODIFY `unit_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `credit_discount_attachments`
--
ALTER TABLE `credit_discount_attachments`
  ADD CONSTRAINT `credit_discount_attachments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `credit_discount_requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `credit_discount_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `credit_discount_requests`
--
ALTER TABLE `credit_discount_requests`
  ADD CONSTRAINT `credit_discount_requests_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`),
  ADD CONSTRAINT `credit_discount_requests_ibfk_2` FOREIGN KEY (`agent_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `credit_discount_requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`paid_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `fk_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`);

--
-- Constraints for table `rsr_reports`
--
ALTER TABLE `rsr_reports`
  ADD CONSTRAINT `rsr_reports_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rsr_reports_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `sales_orders`
--
ALTER TABLE `sales_orders`
  ADD CONSTRAINT `sales_orders_ibfk_1` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `suppliers_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `trip_tickets`
--
ALTER TABLE `trip_tickets`
  ADD CONSTRAINT `trip_tickets_ibfk_1` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
