-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 16, 2026 at 07:23 AM
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
-- Database: `espdemo2`
--

-- --------------------------------------------------------

--
-- Table structure for table `esp_devices`
--

CREATE TABLE `esp_devices` (
  `device_id` varchar(32) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `description` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
--
-- Dumping data for table `esp_devices`
--

INSERT INTO `esp_devices` (`device_id`, `token_hash`, `enabled`, `description`, `created_at`) VALUES
('ESP0001', 'be9419146e280425c8b3b1b832562435e74a0646a3e20a8945dcd96fe2a7c91b', 1, 'Authorized ESP8266', '2026-10-13 05:25:59'),
('ESP0002', '4a2c569a33555e2cdf6f73c665444c5bc98e773d9b271ce788f1dbd5b15c919f', 1, 'ESP0002 Datalogger', '2026-10-14 06:50:53'),
('ESP0003', '91ff4ebf04556af6095902782b50019db0b2352e08abed29c1551c29c288e032', 1, 'ESP0003 Datalogger', '2026-10-14 06:53:07'),
('ESP0004', 'd5cd0f33ef2e2def4585a272ed42f044d0c7bd0905f54f1b9e3c6f2a1ab4be69', 1, 'ESP0004 Datalogger', '2026-10-14 07:02:16'),
('ESP0005', 'be9419146e280425c8b3b1b832562435e74a0646a3e20a8945dcd96fe2a7c91b', 1, 'ESP0004 Datalogger', '2026-10-16 04:33:35');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `esp_devices`
--
ALTER TABLE `esp_devices`
  ADD PRIMARY KEY (`device_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
