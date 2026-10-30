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
-- Table structure for table `web_users`
--



USE espdemo2;

CREATE TABLE IF NOT EXISTS web_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    device_id VARCHAR(32) DEFAULT NULL,
    valid_from DATETIME DEFAULT NULL,
    valid_until DATETIME DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `web_users`
--

INSERT INTO `web_users` (`id`, `username`, `password_hash`, `role`, `created_at`, `enabled`, `device_id`, `valid_from`, `valid_until`) VALUES
(1, 'admin', '$2y$10$yiRl.xlteZi8AObV0YYtHeOIUkohnSQAe51HL3pPv1P.E95577CSq', 'admin', '2026-10-13 06:08:03', 1, NULL, NULL, NULL),
(13, 'ravi4', '$2y$10$SM4evHDwuhqiQp7bL.h3ee.T9yX0/WRp0owmL.azKEz/C9bsdR9VO', 'user', '2026-10-14 07:01:09', 1, 'ESP0004', '2026-10-14 18:34:00', '2026-10-15 22:35:00'),
(16, 'ravi2', '$2y$10$gKvWtHscIMAk5DtsEZzdouc79gidQp/ctRgkJFksqBo2nmCVl3Q.6', 'user', '2026-10-16 04:02:50', 1, 'ESP0001', '2026-10-15 09:32:00', '2026-10-16 23:38:00'),
(17, 'ravi5', '$2y$10$9G0gPnUcMXd4PZ42mEyGoedf83vNMG4DGbvkEzIUoUOYna4VvGqZm', 'user', '2026-10-16 04:36:25', 1, 'ESP0005', '2026-10-16 10:06:00', '2026-10-16 14:06:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `web_users`
--
ALTER TABLE `web_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `unique_web_device` (`device_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `web_users`
--
ALTER TABLE `web_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
