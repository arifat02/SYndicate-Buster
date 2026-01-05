-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 05, 2026 at 04:03 PM
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
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `Batch_ID` int(11) NOT NULL,
  `Parent_Batch_ID` int(11) DEFAULT NULL,
  `Current_Owner_ID` int(11) DEFAULT NULL,
  `Commodity_ID` int(11) DEFAULT NULL,
  `Current_Quantity` decimal(10,2) DEFAULT NULL,
  `Harvest_Date` date DEFAULT NULL,
  `QR_Code_Hash` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `commodities`
--

CREATE TABLE `commodities` (
  `Commodity_ID` int(11) NOT NULL,
  `Name` varchar(50) NOT NULL,
  `Unit_Type` varchar(10) NOT NULL,
  `Perishable` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `govt_price_cap`
--

CREATE TABLE `govt_price_cap` (
  `Cap_ID` int(11) NOT NULL,
  `Commodity_ID` int(11) DEFAULT NULL,
  `Max_Wholesale_Price` decimal(10,2) DEFAULT NULL,
  `Max_Retail_Price` decimal(10,2) DEFAULT NULL,
  `Effective_Date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `syndicate_blacklist`
--

CREATE TABLE `syndicate_blacklist` (
  `Flag_ID` int(11) NOT NULL,
  `User_ID` int(11) DEFAULT NULL,
  `Transaction_ID` int(11) DEFAULT NULL,
  `Violation_Type` varchar(100) DEFAULT NULL,
  `Penalty_Amount` decimal(10,2) DEFAULT NULL,
  `Flag_Date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `Transaction_ID` int(11) NOT NULL,
  `Batch_ID` int(11) DEFAULT NULL,
  `Seller_ID` int(11) DEFAULT NULL,
  `Buyer_ID` int(11) DEFAULT NULL,
  `Unit_Price` decimal(10,2) DEFAULT NULL,
  `Total_Amount` decimal(15,2) DEFAULT NULL,
  `Transaction_Date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `User_ID` int(11) NOT NULL,
  `Name` varchar(100) NOT NULL,
  `Phone` varchar(15) DEFAULT NULL,
  `Location_District` varchar(50) DEFAULT NULL,
  `Role` enum('Farmer','Middleman','Wholesaler','Retailer','Admin') NOT NULL,
  `Trust_Score` int(11) DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`Batch_ID`),
  ADD KEY `Current_Owner_ID` (`Current_Owner_ID`),
  ADD KEY `Commodity_ID` (`Commodity_ID`),
  ADD KEY `Parent_Batch_ID` (`Parent_Batch_ID`);

--
-- Indexes for table `commodities`
--
ALTER TABLE `commodities`
  ADD PRIMARY KEY (`Commodity_ID`),
  ADD UNIQUE KEY `Name` (`Name`);

--
-- Indexes for table `govt_price_cap`
--
ALTER TABLE `govt_price_cap`
  ADD PRIMARY KEY (`Cap_ID`),
  ADD KEY `Commodity_ID` (`Commodity_ID`);

--
-- Indexes for table `syndicate_blacklist`
--
ALTER TABLE `syndicate_blacklist`
  ADD PRIMARY KEY (`Flag_ID`),
  ADD KEY `User_ID` (`User_ID`),
  ADD KEY `Transaction_ID` (`Transaction_ID`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`Transaction_ID`),
  ADD KEY `Batch_ID` (`Batch_ID`),
  ADD KEY `Seller_ID` (`Seller_ID`),
  ADD KEY `Buyer_ID` (`Buyer_ID`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`User_ID`),
  ADD UNIQUE KEY `Phone` (`Phone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `Batch_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `commodities`
--
ALTER TABLE `commodities`
  MODIFY `Commodity_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `govt_price_cap`
--
ALTER TABLE `govt_price_cap`
  MODIFY `Cap_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `syndicate_blacklist`
--
ALTER TABLE `syndicate_blacklist`
  MODIFY `Flag_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `Transaction_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `User_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `batches`
--
ALTER TABLE `batches`
  ADD CONSTRAINT `batches_ibfk_1` FOREIGN KEY (`Current_Owner_ID`) REFERENCES `users` (`User_ID`),
  ADD CONSTRAINT `batches_ibfk_2` FOREIGN KEY (`Commodity_ID`) REFERENCES `commodities` (`Commodity_ID`),
  ADD CONSTRAINT `batches_ibfk_3` FOREIGN KEY (`Parent_Batch_ID`) REFERENCES `batches` (`Batch_ID`);

--
-- Constraints for table `govt_price_cap`
--
ALTER TABLE `govt_price_cap`
  ADD CONSTRAINT `govt_price_cap_ibfk_1` FOREIGN KEY (`Commodity_ID`) REFERENCES `commodities` (`Commodity_ID`);

--
-- Constraints for table `syndicate_blacklist`
--
ALTER TABLE `syndicate_blacklist`
  ADD CONSTRAINT `syndicate_blacklist_ibfk_1` FOREIGN KEY (`User_ID`) REFERENCES `users` (`User_ID`),
  ADD CONSTRAINT `syndicate_blacklist_ibfk_2` FOREIGN KEY (`Transaction_ID`) REFERENCES `transactions` (`Transaction_ID`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`Batch_ID`) REFERENCES `batches` (`Batch_ID`),
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`Seller_ID`) REFERENCES `users` (`User_ID`),
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`Buyer_ID`) REFERENCES `users` (`User_ID`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
