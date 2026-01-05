-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 05, 2026 at 12:36 PM
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
-- Database: `syndicatedb`
--

-- --------------------------------------------------------

--
-- Table structure for table `batche`
--

CREATE TABLE `batch` (
  `batch_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `current_owner_id` int(11) DEFAULT NULL,
  `commodity_id` int(11) DEFAULT NULL,
  `current_quantity` decimal(10,2) DEFAULT NULL,
  `harvest_date` date DEFAULT NULL,
  `qr_code_hash` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `commodities`
--

CREATE TABLE `commodities` (
  `commodity_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `unit_type` varchar(15) NOT NULL,
  `perishable` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `govt_price_cap`
--

CREATE TABLE `govt_price_cap` (
  `cap_id` int(11) NOT NULL,
  `commodity_id` int(11) DEFAULT NULL,
  `max_wholesale_price` decimal(10,2) DEFAULT NULL,
  `effective_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `seller_id` int(11) DEFAULT NULL,
  `buyer_id` int(11) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `transaction_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `location` varchar(50) DEFAULT NULL,
  `role` varchar(10) DEFAULT NULL CHECK (`role` in ('Farmer','Middleman','Wholesaler','Retailer','Admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `batche`
--
ALTER TABLE `batche`
  ADD PRIMARY KEY (`batch_id`),
  ADD KEY `current_owner_id` (`current_owner_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `commodities`
--
ALTER TABLE `commodities`
  ADD PRIMARY KEY (`commodity_id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `govt_price_cap`
--
ALTER TABLE `govt_price_cap`
  ADD PRIMARY KEY (`cap_id`),
  ADD KEY `commodity_id` (`commodity_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `seller_id` (`seller_id`),
  ADD KEY `buyer_id` (`buyer_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `batche`
--
ALTER TABLE `batche`
  MODIFY `batch_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `commodities`
--
ALTER TABLE `commodities`
  MODIFY `commodity_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `govt_price_cap`
--
ALTER TABLE `govt_price_cap`
  MODIFY `cap_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `batche`
--
ALTER TABLE `batche`
  ADD CONSTRAINT `batche_ibfk_1` FOREIGN KEY (`current_owner_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `batche_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `batche` (`batch_id`);

--
-- Constraints for table `govt_price_cap`
--
ALTER TABLE `govt_price_cap`
  ADD CONSTRAINT `govt_price_cap_ibfk_1` FOREIGN KEY (`commodity_id`) REFERENCES `commodities` (`commodity_id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batche` (`batch_id`),
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
