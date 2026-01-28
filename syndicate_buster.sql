-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 27, 2026 at 06:06 PM
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
-- Database: `syndicate_buster`
--

-- --------------------------------------------------------

--
-- Table structure for table `appeals`
--

CREATE TABLE `appeals` (
  `appeal_id` int(11) NOT NULL,
  `violation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `appeal_reason` text NOT NULL,
  `supporting_docs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Paths to uploaded documents' CHECK (json_valid(`supporting_docs`)),
  `appeal_date` date NOT NULL,
  `status` enum('Submitted','Under_Review','Approved','Rejected','Withdrawn') DEFAULT 'Submitted',
  `reviewed_by` int(11) DEFAULT NULL,
  `review_date` date DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `batch_id` int(11) NOT NULL,
  `commodity_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `initial_quantity` decimal(10,2) NOT NULL CHECK (`initial_quantity` > 0),
  `current_quantity` decimal(10,2) NOT NULL CHECK (`current_quantity` >= 0),
  `reserved_quantity` decimal(10,2) DEFAULT 0.00,
  `selling_price` decimal(10,2) DEFAULT 0.00,
  `production_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `batch_status` enum('Active','Sold','Expired') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `parent_batch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `batches`
--

INSERT INTO `batches` (`batch_id`, `commodity_id`, `owner_id`, `initial_quantity`, `current_quantity`, `reserved_quantity`, `selling_price`, `production_date`, `expiry_date`, `batch_status`, `created_at`, `parent_batch_id`) VALUES
(1, 2, 1, 300.00, 300.00, 0.00, 0.00, '2026-01-12', '2026-02-12', 'Active', '2026-01-22 14:39:06', NULL),
(2, 3, 2, 1000.00, 600.00, 0.00, 0.00, '2026-01-15', '2026-03-15', 'Active', '2026-01-22 14:39:06', NULL),
(3, 4, 2, 200.00, 0.00, 0.00, 0.00, '2026-01-05', '2026-01-25', 'Sold', '2026-01-22 14:39:06', NULL),
(4, 5, 1, 750.00, 750.00, 0.00, 0.00, '2026-01-20', '2026-03-20', 'Active', '2026-01-22 14:39:06', NULL),
(22, 2, 1, 1000.00, 1000.00, 0.00, 0.00, '0000-00-00', NULL, 'Active', '2026-01-22 14:36:02', NULL),
(23, 6, 1, 20.00, 20.00, 0.00, 0.00, '2026-01-26', '2026-01-30', 'Active', '2026-01-25 19:52:03', 2),
(27, 5, 1, 2000.00, 2000.00, 0.00, 45.00, '2026-01-20', '2027-01-20', 'Active', '2026-01-26 22:05:02', NULL),
(28, 6, 1, 100.00, 100.00, 0.00, 85.00, '2026-01-27', '2026-02-03', 'Active', '2026-01-26 22:05:02', NULL),
(29, 2, 7, 300.00, 300.00, 0.00, 38.00, '2026-01-12', '2026-03-12', 'Active', '2026-01-26 22:05:02', 1),
(30, 7, 6, 500.00, 500.00, 0.00, 25.00, '2026-01-25', '2026-02-09', 'Active', '2026-01-26 22:05:02', NULL),
(31, 3, 11, 50.00, 20.00, 0.00, 65.00, '2026-01-15', '2026-01-25', 'Active', '2026-01-26 22:05:02', 2),
(32, 4, 2, 100.00, 100.00, 0.00, 40.00, '2025-12-20', '2026-01-20', 'Expired', '2026-01-26 22:05:02', NULL),
(33, 5, 7, 500.00, 0.00, 0.00, 55.00, '2026-01-10', '2027-01-10', 'Sold', '2026-01-26 22:05:02', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `batch_quality_checks`
--

CREATE TABLE `batch_quality_checks` (
  `check_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `check_date` date NOT NULL,
  `rot_percentage` decimal(5,2) DEFAULT NULL,
  `passed` tinyint(1) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batch_reservations`
--

CREATE TABLE `batch_reservations` (
  `reservation_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `status` enum('Reserved','Released','Converted') DEFAULT 'Reserved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `commodities`
--

CREATE TABLE `commodities` (
  `commodity_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `commodity_name` varchar(100) NOT NULL,
  `unit_type` enum('kg','gram','liter','piece','packet') NOT NULL DEFAULT 'kg',
  `perishable` tinyint(1) DEFAULT 1,
  `shelf_life_days` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('Active','Inactive','Banned') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `commodities`
--

INSERT INTO `commodities` (`commodity_id`, `category_id`, `commodity_name`, `unit_type`, `perishable`, `shelf_life_days`, `description`, `status`, `created_at`, `updated_at`) VALUES
(2, 1, 'Potato', 'kg', 1, 60, 'Fresh local potatoes', 'Active', '2026-01-22 12:58:54', '2026-01-22 12:58:54'),
(3, 1, 'Tomato', 'kg', 1, 10, 'Ripe red tomatoes', 'Active', '2026-01-22 12:58:54', '2026-01-22 12:58:54'),
(4, 2, 'Mango', 'kg', 1, 30, 'Imported manfo', 'Active', '2026-01-22 12:58:54', '2026-01-22 12:58:54'),
(5, 3, 'Rice', 'kg', 0, 365, 'Miniket rice', 'Active', '2026-01-22 12:58:54', '2026-01-22 12:58:54'),
(6, 4, 'Milk', 'liter', 1, 7, 'Pasteurized fresh milk', 'Active', '2026-01-22 12:58:54', '2026-01-22 12:58:54'),
(7, 2, 'Cucumber', 'kg', 1, 15, '', 'Active', '2026-01-25 19:53:31', '2026-01-25 19:53:31');

-- --------------------------------------------------------

--
-- Table structure for table `commodity_categories`
--

CREATE TABLE `commodity_categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `commodity_categories`
--

INSERT INTO `commodity_categories` (`category_id`, `category_name`, `description`) VALUES
(1, 'Local Fruits', 'Fresh fruits grown locally'),
(2, 'Vegetables', 'Fresh vegetables sold in markets'),
(3, 'Seasonal Fruits', 'Seasonal and imported fruits'),
(4, 'Grains', 'Rice, wheat, and other grains'),
(5, 'Dairy', 'Milk and dairy-based products'),
(6, 'Spices', 'Dry and powdered spices');

-- --------------------------------------------------------

--
-- Table structure for table `listings`
--

CREATE TABLE `listings` (
  `listing_id` int(11) NOT NULL,
  `listing_code` varchar(20) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `listing_quantity` decimal(10,2) NOT NULL,
  `remaining_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(10,2) NOT NULL,
  `listing_type` enum('fixed_price','negotiable') DEFAULT 'fixed_price',
  `notes` text DEFAULT NULL,
  `status` enum('Active','Partially_Filled','Completed','Cancelled','Expired') DEFAULT 'Active',
  `violation_flag` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cancelled_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `listings`
--

INSERT INTO `listings` (`listing_id`, `listing_code`, `batch_id`, `seller_id`, `listing_quantity`, `remaining_quantity`, `unit_price`, `listing_type`, `notes`, `status`, `violation_flag`, `created_at`, `updated_at`, `cancelled_at`) VALUES
(1, '4763CA19', 29, 7, 300.00, 300.00, 33.00, 'fixed_price', '', 'Cancelled', 0, '2026-01-27 14:58:22', '2026-01-27 15:14:32', '2026-01-27 15:14:32'),
(2, 'F8A5610D', 29, 7, 200.00, 200.00, 32.00, 'fixed_price', '', 'Cancelled', 0, '2026-01-27 15:14:45', '2026-01-27 15:15:16', '2026-01-27 15:15:16');

-- --------------------------------------------------------

--
-- Table structure for table `listing_offers`
--

CREATE TABLE `listing_offers` (
  `offer_id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `offered_price` decimal(10,2) NOT NULL,
  `offered_quantity` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('Pending','Accepted','Rejected','Countered','Expired') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('sale_offer','transaction_update','violation','system') DEFAULT 'system',
  `related_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `type`, `related_id`, `is_read`, `created_at`) VALUES
(1, 6, 'New Sale Offer Received', 'You have received a sale offer for 500 kg of Tomato at ৳50/unit from rahim. Please confirm or reject.', 'sale_offer', 4, 0, '2026-01-25 21:49:54'),
(2, 6, 'Direct Purchase Completed', 'You have purchased 200 kg of Tomato at ৳48/unit from rahim', 'sale_offer', 5, 0, '2026-01-25 21:52:43'),
(3, 2, 'Price Violation Detected', 'Your sale of Tomato at ৳50/kg has been flagged as violating the price cap of ৳60/kg. A fine of ৳5000 has been issued.', 'violation', 1, 0, '2026-01-26 13:38:34'),
(4, 2, 'Price Violation Detected', 'Your sale of Tomato at ৳48/kg has been flagged as violating the price cap of ৳60/kg. A fine of ৳2000 has been issued.', 'violation', 2, 0, '2026-01-26 13:38:34'),
(5, 1, 'Price Violation Reported', 'Your sale of Potato at ৳45/kg has been reported as potentially violating the price cap of ৳40.50/kg. This is under review.', 'violation', 3, 0, '2026-01-26 13:38:34');

-- --------------------------------------------------------

--
-- Table structure for table `penalties`
--

CREATE TABLE `penalties` (
  `penalty_id` int(11) NOT NULL,
  `violation_id` int(11) NOT NULL,
  `penalty_type` enum('FINE','SUSPENSION','WARNING') NOT NULL,
  `fine_amount` decimal(10,2) DEFAULT NULL,
  `suspension_days` int(11) DEFAULT NULL,
  `issued_by` int(11) NOT NULL,
  `issued_date` date NOT NULL,
  `status` enum('ISSUED','APPEALED','CANCELLED') DEFAULT 'ISSUED'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `penalties`
--

INSERT INTO `penalties` (`penalty_id`, `violation_id`, `penalty_type`, `fine_amount`, `suspension_days`, `issued_by`, `issued_date`, `status`) VALUES
(1, 1, 'FINE', 5000.00, NULL, 5, '2026-01-26', 'ISSUED'),
(2, 2, 'FINE', 2000.00, NULL, 5, '2026-01-26', 'ISSUED'),
(3, 1, 'WARNING', NULL, NULL, 5, '2026-01-26', 'ISSUED');

-- --------------------------------------------------------

--
-- Table structure for table `price_caps`
--

CREATE TABLE `price_caps` (
  `price_cap_id` int(11) NOT NULL,
  `commodity_id` int(11) NOT NULL,
  `max_price_per_unit` decimal(10,2) NOT NULL,
  `effective_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `region` varchar(50) DEFAULT 'National',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `price_caps`
--

INSERT INTO `price_caps` (`price_cap_id`, `commodity_id`, `max_price_per_unit`, `effective_date`, `expiry_date`, `region`, `created_at`, `updated_at`) VALUES
(6, 2, 40.50, '2026-01-22', '2026-01-30', 'National', '2026-01-22 13:08:29', '2026-01-27 14:57:15'),
(7, 3, 60.00, '2026-01-22', '2026-01-25', 'National', '2026-01-22 13:08:29', '2026-01-26 10:56:30'),
(8, 4, 55.75, '2026-01-22', '2026-02-20', 'National', '2026-01-22 13:08:29', '2026-01-22 13:08:29'),
(9, 5, 120.00, '2026-01-22', '2026-03-10', 'National', '2026-01-22 13:08:29', '2026-01-22 13:08:29'),
(10, 6, 90.00, '2026-01-22', '2026-03-05', 'National', '2026-01-22 13:08:29', '2026-01-22 13:08:29'),
(14, 3, 20.00, '2026-01-26', '2026-01-31', 'National', '2026-01-26 10:56:30', '2026-01-26 10:56:30');

-- --------------------------------------------------------

--
-- Table structure for table `price_cap_violations`
--

CREATE TABLE `price_cap_violations` (
  `pc_violation_id` int(11) NOT NULL,
  `violation_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `listing_id` int(11) DEFAULT NULL,
  `price_cap_id` int(11) NOT NULL,
  `reported_price` decimal(10,2) NOT NULL CHECK (`reported_price` > 0),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `price_cap_violations`
--

INSERT INTO `price_cap_violations` (`pc_violation_id`, `violation_id`, `transaction_id`, `listing_id`, `price_cap_id`, `reported_price`, `created_at`) VALUES
(1, 1, 4, NULL, 7, 50.00, '2026-01-26 13:38:34'),
(2, 2, 5, NULL, 7, 48.00, '2026-01-26 13:38:34'),
(3, 3, 1, NULL, 6, 45.00, '2026-01-26 13:38:34');

-- --------------------------------------------------------

--
-- Table structure for table `quality_standards`
--

CREATE TABLE `quality_standards` (
  `standard_id` int(11) NOT NULL,
  `commodity_id` int(11) NOT NULL,
  `parameter_name` varchar(100) NOT NULL,
  `min_value` decimal(10,2) DEFAULT NULL,
  `max_value` decimal(10,2) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`) VALUES
(5, 'Admin'),
(1, 'Farmer'),
(6, 'Inspector'),
(2, 'Middleman'),
(4, 'Retailer'),
(3, 'Wholesaler');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Completed','Cancelled','Refunded') DEFAULT 'Pending',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  `violation_flag` tinyint(1) DEFAULT 0,
  `total_amount` decimal(10,2) GENERATED ALWAYS AS (`unit_price` * `quantity`) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `batch_id`, `seller_id`, `buyer_id`, `unit_price`, `quantity`, `transaction_date`, `status`, `updated_at`, `notes`, `violation_flag`) VALUES
(1, 1, 1, 2, 45.00, 100.00, '2026-01-22 17:07:22', 'Pending', '2026-01-26 13:38:34', NULL, 1),
(2, 2, 1, 2, 60.00, 50.00, '2026-01-22 17:07:22', 'Pending', '2026-01-25 18:59:29', NULL, 0),
(3, 3, 2, 1, 55.75, 200.00, '2026-01-22 17:07:22', 'Pending', '2026-01-25 18:59:29', NULL, 0),
(4, 2, 2, 6, 50.00, 500.00, '2026-01-25 21:49:54', 'Pending', '2026-01-26 13:38:34', '', 1),
(5, 2, 2, 6, 48.00, 200.00, '2026-01-25 21:52:42', 'Completed', '2026-01-26 13:38:34', '', 1),
(6, 23, 1, 6, 6.00, 5.00, '2026-01-26 21:47:25', 'Completed', '2026-01-26 21:47:25', NULL, 0),
(7, 23, 1, 6, 6.00, 5.00, '2026-01-26 21:48:04', 'Completed', '2026-01-26 21:48:04', NULL, 0),
(8, 23, 1, 6, 6.00, 5.00, '2026-01-26 21:48:12', 'Completed', '2026-01-26 21:48:12', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `transaction_status_log`
--

CREATE TABLE `transaction_status_log` (
  `log_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_status_log`
--

INSERT INTO `transaction_status_log` (`log_id`, `transaction_id`, `old_status`, `new_status`, `changed_by`, `reason`, `created_at`) VALUES
(2, 4, NULL, 'Pending', 2, 'Transaction created as Sale Offer', '2026-01-25 21:49:54'),
(3, 5, NULL, 'Completed', 2, 'Transaction created as Direct Sale', '2026-01-25 21:52:42');

-- --------------------------------------------------------

--
-- Table structure for table `trust_score_log`
--

CREATE TABLE `trust_score_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `old_score` int(11) NOT NULL,
  `new_score` int(11) NOT NULL,
  `reason` text NOT NULL,
  `related_transaction_id` int(11) DEFAULT NULL,
  `related_listing_id` int(11) DEFAULT NULL,
  `related_violation_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trust_score_log`
--

INSERT INTO `trust_score_log` (`log_id`, `user_id`, `old_score`, `new_score`, `reason`, `related_transaction_id`, `related_listing_id`, `related_violation_id`) VALUES
(1, 1, 100, 90, 'Price cap violation - Potato sold at ৳45/kg exceeding cap of ৳40.50/kg', NULL, NULL, 3),
(2, 2, 100, 80, 'Multiple price cap violations - Tomato sales exceeding price caps', NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(11) NOT NULL CHECK (`phone` regexp '^[0-9]{11}$'),
  `password` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `address` text DEFAULT NULL,
  `location` varchar(100) NOT NULL,
  `trust_score` int(11) DEFAULT 100 CHECK (`trust_score` between 0 and 100),
  `account_status` enum('Active','Inactive','Suspended','Banned','Under_Review','Blacklisted') DEFAULT 'Active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `phone`, `password`, `role_id`, `address`, `location`, `trust_score`, `account_status`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'karim', 'karim@gmail.com', '01711111111', '$2y$10$ZBXvGmymVnzr8yF1BjnF8eQFXQIplAm1iGH9NCCXPzmEr/DcLuVRK', 1, 'Moulvibazar, sylhet', 'sylhet', 90, 'Active', '2026-01-26 20:15:37', '2026-01-22 10:51:29', '2026-01-26 14:15:37'),
(2, 'rahim', 'rarim@gmail.com', '01711111222', '$2y$10$/9nVMv.jERYIPa5GSthITuHkVn.AIPJL4YXhhH2MC4.5NE70V/Ujq', 2, 'Mirpur, Dhaka', 'Dhaka', 90, 'Active', '2026-01-26 16:20:35', '2026-01-22 10:55:22', '2026-01-26 13:38:34'),
(5, 'Rifat Hossain', 'rifat@gmail.com', '01989898989', '$2y$10$mPMtgOs.5BwzqqOkn715UOIhv1wuzXt5tq8mWCA17wCpONxB7y0jq', 5, 'chittagong', 'chittagong', 100, 'Active', '2026-01-27 23:02:25', '2026-01-23 12:17:45', '2026-01-27 17:02:25'),
(6, 'Alif', 'alif123@gmail.com', '01680522222', '$2y$10$a3sR/PCBvKkk3xOmryiYYedGi2UcqaZEZHNrobTjp3aK1hRo8FmfW', 2, 'cumilla', 'cumilla', 100, 'Active', '2026-01-27 03:05:33', '2026-01-23 12:19:05', '2026-01-26 21:05:33'),
(7, 'Mohona Amin', 'mohona@gmail.com', '01987654321', '$2y$10$P6xnjD57JXkIzqb/TiHq4ObZewGOfGlRObgZypjC9VWH7uCQUPSu6', 3, 'mirpur 1', 'Dhaka', 100, 'Active', '2026-01-27 22:45:46', '2026-01-26 07:57:16', '2026-01-27 16:45:46'),
(11, 'mahin', 'mahin@gmail.com', '01987878787', '$2y$10$fhLwkyqg04yu7ACXhcWd5.ARkHC2NRpCra1blr9CZj.RZEqT1/Pli', 4, '1/A', 'Dhaka', 100, 'Active', NULL, '2026-01-26 10:05:37', '2026-01-26 10:05:37');

-- --------------------------------------------------------

--
-- Table structure for table `user_suspensions`
--

CREATE TABLE `user_suspensions` (
  `suspension_id` int(11) NOT NULL,
  `penalty_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('ACTIVE','COMPLETED','REVOKED') DEFAULT 'ACTIVE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `violations`
--

CREATE TABLE `violations` (
  `violation_id` int(11) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `reported_user_id` int(11) NOT NULL,
  `violation_type` enum('PRICE_CAP','HOARDING','FRAUD','OTHER') NOT NULL,
  `description` text NOT NULL,
  `violation_date` date NOT NULL,
  `status` enum('PENDING','UNDER_REVIEW','CONFIRMED','REJECTED') DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `violations`
--

INSERT INTO `violations` (`violation_id`, `reporter_id`, `reported_user_id`, `violation_type`, `description`, `violation_date`, `status`, `created_at`) VALUES
(1, 5, 2, 'PRICE_CAP', 'Tomato sold at ৳50/kg exceeding cap of ৳60/kg', '2026-01-25', 'CONFIRMED', '2026-01-26 13:38:34'),
(2, 5, 2, 'PRICE_CAP', 'Tomato sold at ৳48/kg exceeding cap of ৳60/kg', '2026-01-25', 'CONFIRMED', '2026-01-26 13:38:34'),
(3, 5, 1, 'PRICE_CAP', 'Potato sold at ৳45/kg exceeding cap of ৳40.50/kg', '2026-01-22', 'PENDING', '2026-01-26 13:38:34'),
(4, 5, 2, 'HOARDING', 'Large quantity of Tomato stored without sale', '2026-01-20', 'UNDER_REVIEW', '2026-01-26 13:38:34'),
(5, 6, 1, 'PRICE_CAP', 'Rice sold above market price', '2026-01-23', 'PENDING', '2026-01-26 13:38:34');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appeals`
--
ALTER TABLE `appeals`
  ADD PRIMARY KEY (`appeal_id`),
  ADD KEY `violation_id` (`violation_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`batch_id`),
  ADD KEY `commodity_id` (`commodity_id`),
  ADD KEY `owner_id` (`owner_id`),
  ADD KEY `parent_batch_id` (`parent_batch_id`);

--
-- Indexes for table `batch_quality_checks`
--
ALTER TABLE `batch_quality_checks`
  ADD PRIMARY KEY (`check_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `inspector_id` (`inspector_id`);

--
-- Indexes for table `batch_reservations`
--
ALTER TABLE `batch_reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `idx_batch` (`batch_id`),
  ADD KEY `idx_listing` (`listing_id`);

--
-- Indexes for table `commodities`
--
ALTER TABLE `commodities`
  ADD PRIMARY KEY (`commodity_id`),
  ADD UNIQUE KEY `commodity_name` (`commodity_name`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `commodity_categories`
--
ALTER TABLE `commodity_categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `listings`
--
ALTER TABLE `listings`
  ADD PRIMARY KEY (`listing_id`),
  ADD UNIQUE KEY `listing_code` (`listing_code`),
  ADD KEY `idx_seller_status` (`seller_id`,`status`),
  ADD KEY `idx_batch` (`batch_id`);

--
-- Indexes for table `listing_offers`
--
ALTER TABLE `listing_offers`
  ADD PRIMARY KEY (`offer_id`),
  ADD KEY `idx_listing_status` (`listing_id`,`status`),
  ADD KEY `idx_buyer` (`buyer_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `penalties`
--
ALTER TABLE `penalties`
  ADD PRIMARY KEY (`penalty_id`),
  ADD KEY `violation_id` (`violation_id`),
  ADD KEY `issued_by` (`issued_by`);

--
-- Indexes for table `price_caps`
--
ALTER TABLE `price_caps`
  ADD PRIMARY KEY (`price_cap_id`),
  ADD KEY `commodity_id` (`commodity_id`);

--
-- Indexes for table `price_cap_violations`
--
ALTER TABLE `price_cap_violations`
  ADD PRIMARY KEY (`pc_violation_id`),
  ADD KEY `violation_id` (`violation_id`),
  ADD KEY `transaction_id` (`transaction_id`),
  ADD KEY `price_cap_id` (`price_cap_id`),
  ADD KEY `listing_id` (`listing_id`);

--
-- Indexes for table `quality_standards`
--
ALTER TABLE `quality_standards`
  ADD PRIMARY KEY (`standard_id`),
  ADD KEY `commodity_id` (`commodity_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `seller_id` (`seller_id`),
  ADD KEY `buyer_id` (`buyer_id`);

--
-- Indexes for table `transaction_status_log`
--
ALTER TABLE `transaction_status_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `transaction_id` (`transaction_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `trust_score_log`
--
ALTER TABLE `trust_score_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `related_transaction_id` (`related_transaction_id`),
  ADD KEY `related_violation_id` (`related_violation_id`),
  ADD KEY `related_listing_id` (`related_listing_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `user_suspensions`
--
ALTER TABLE `user_suspensions`
  ADD PRIMARY KEY (`suspension_id`),
  ADD KEY `penalty_id` (`penalty_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `violations`
--
ALTER TABLE `violations`
  ADD PRIMARY KEY (`violation_id`),
  ADD KEY `reporter_id` (`reporter_id`),
  ADD KEY `reported_user_id` (`reported_user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appeals`
--
ALTER TABLE `appeals`
  MODIFY `appeal_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `batch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `batch_quality_checks`
--
ALTER TABLE `batch_quality_checks`
  MODIFY `check_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batch_reservations`
--
ALTER TABLE `batch_reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `commodities`
--
ALTER TABLE `commodities`
  MODIFY `commodity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `commodity_categories`
--
ALTER TABLE `commodity_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `listings`
--
ALTER TABLE `listings`
  MODIFY `listing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `listing_offers`
--
ALTER TABLE `listing_offers`
  MODIFY `offer_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `penalties`
--
ALTER TABLE `penalties`
  MODIFY `penalty_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `price_caps`
--
ALTER TABLE `price_caps`
  MODIFY `price_cap_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `price_cap_violations`
--
ALTER TABLE `price_cap_violations`
  MODIFY `pc_violation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `quality_standards`
--
ALTER TABLE `quality_standards`
  MODIFY `standard_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `transaction_status_log`
--
ALTER TABLE `transaction_status_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `trust_score_log`
--
ALTER TABLE `trust_score_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `user_suspensions`
--
ALTER TABLE `user_suspensions`
  MODIFY `suspension_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `violations`
--
ALTER TABLE `violations`
  MODIFY `violation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appeals`
--
ALTER TABLE `appeals`
  ADD CONSTRAINT `appeals_ibfk_1` FOREIGN KEY (`violation_id`) REFERENCES `violations` (`violation_id`),
  ADD CONSTRAINT `appeals_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `appeals_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `batches`
--
ALTER TABLE `batches`
  ADD CONSTRAINT `batches_ibfk_1` FOREIGN KEY (`commodity_id`) REFERENCES `commodities` (`commodity_id`),
  ADD CONSTRAINT `batches_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `batches_ibfk_3` FOREIGN KEY (`parent_batch_id`) REFERENCES `batches` (`batch_id`);

--
-- Constraints for table `batch_quality_checks`
--
ALTER TABLE `batch_quality_checks`
  ADD CONSTRAINT `batch_quality_checks_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`),
  ADD CONSTRAINT `batch_quality_checks_ibfk_2` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `batch_reservations`
--
ALTER TABLE `batch_reservations`
  ADD CONSTRAINT `batch_reservations_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`),
  ADD CONSTRAINT `batch_reservations_ibfk_2` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`listing_id`) ON DELETE CASCADE;

--
-- Constraints for table `commodities`
--
ALTER TABLE `commodities`
  ADD CONSTRAINT `commodities_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `commodity_categories` (`category_id`);

--
-- Constraints for table `listings`
--
ALTER TABLE `listings`
  ADD CONSTRAINT `listings_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`),
  ADD CONSTRAINT `listings_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `listing_offers`
--
ALTER TABLE `listing_offers`
  ADD CONSTRAINT `listing_offers_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`listing_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `listing_offers_ibfk_2` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `penalties`
--
ALTER TABLE `penalties`
  ADD CONSTRAINT `penalties_ibfk_1` FOREIGN KEY (`violation_id`) REFERENCES `violations` (`violation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `penalties_ibfk_2` FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `price_caps`
--
ALTER TABLE `price_caps`
  ADD CONSTRAINT `price_caps_ibfk_1` FOREIGN KEY (`commodity_id`) REFERENCES `commodities` (`commodity_id`);

--
-- Constraints for table `price_cap_violations`
--
ALTER TABLE `price_cap_violations`
  ADD CONSTRAINT `price_cap_violations_ibfk_1` FOREIGN KEY (`violation_id`) REFERENCES `violations` (`violation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `price_cap_violations_ibfk_2` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `price_cap_violations_ibfk_3` FOREIGN KEY (`price_cap_id`) REFERENCES `price_caps` (`price_cap_id`),
  ADD CONSTRAINT `price_cap_violations_ibfk_4` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`listing_id`) ON DELETE CASCADE;

--
-- Constraints for table `quality_standards`
--
ALTER TABLE `quality_standards`
  ADD CONSTRAINT `quality_standards_ibfk_1` FOREIGN KEY (`commodity_id`) REFERENCES `commodities` (`commodity_id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`),
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `transaction_status_log`
--
ALTER TABLE `transaction_status_log`
  ADD CONSTRAINT `transaction_status_log_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transaction_status_log_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `trust_score_log`
--
ALTER TABLE `trust_score_log`
  ADD CONSTRAINT `trust_score_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `trust_score_log_ibfk_2` FOREIGN KEY (`related_transaction_id`) REFERENCES `transactions` (`transaction_id`),
  ADD CONSTRAINT `trust_score_log_ibfk_3` FOREIGN KEY (`related_violation_id`) REFERENCES `violations` (`violation_id`),
  ADD CONSTRAINT `trust_score_log_ibfk_4` FOREIGN KEY (`related_listing_id`) REFERENCES `listings` (`listing_id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`);

--
-- Constraints for table `user_suspensions`
--
ALTER TABLE `user_suspensions`
  ADD CONSTRAINT `user_suspensions_ibfk_1` FOREIGN KEY (`penalty_id`) REFERENCES `penalties` (`penalty_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_suspensions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `violations`
--
ALTER TABLE `violations`
  ADD CONSTRAINT `violations_ibfk_1` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `violations_ibfk_2` FOREIGN KEY (`reported_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
