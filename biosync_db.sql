-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 02, 2026 at 02:13 AM
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
-- Database: `biosync_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` int(11) NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `current_state` varchar(50) DEFAULT '0',
  `unit` varchar(10) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `devices`
--

INSERT INTO `devices` (`id`, `device_name`, `current_state`, `unit`) VALUES
(1, 'Temperature Node 1', '26', '°C'),
(2, 'Humidity Node 1', '80', '%'),
(3, 'Ventilation Fan', '0', ''),
(4, 'Water Pump', '0', ''),
(5, 'System Alert Indicator', '0', '');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `message` text NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'info',
  `icon_class` varchar(60) DEFAULT 'fa-bell',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `title`, `description`, `message`, `type`, `icon_class`, `is_read`, `created_at`) VALUES
(1, 'System Online', 'Bio-Sync monitoring system started successfully.', 'System Online', 'info', 'fa-circle-check', 1, '2026-06-01 07:01:18'),
(2, 'Sensor Data Received', 'Latest temperature and humidity readings logged.', 'Sensor Data Received', 'info', 'fa-microchip', 0, '2026-06-01 08:31:18'),
(3, 'High Temperature', 'Temperature exceeded 30°C threshold. Fan activated.', 'High Temperature Alert', 'alert', 'fa-triangle-exclamation', 0, '2026-06-01 08:51:18'),
(4, 'Low Humidity', 'Humidity dropped below 85%. Water pump activated.', 'Low Humidity Alert', 'alert', 'fa-droplet', 0, '2026-06-01 08:56:18'),
(48, 'High Humidity', 'Humidity exceeded 90%. Ventilation activated.', 'Humidity exceeded 90%. Ventilation activated.', 'alert', 'fa-droplet', 0, '2026-06-02 06:23:48');

-- --------------------------------------------------------

--
-- Table structure for table `sensor_logs`
--

CREATE TABLE `sensor_logs` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `recorded_value` decimal(10,2) NOT NULL,
  `recorded_at` datetime DEFAULT current_timestamp(),
  `stage` varchar(20) DEFAULT 'colonization'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sensor_logs`
--

-- Synthetic demo history: May 26-28 colonization, May 29-30 pinning, June 1-2 fruiting.
INSERT INTO `sensor_logs` (`id`, `device_id`, `recorded_value`, `recorded_at`, `stage`) VALUES
(33, 1, 30.00, '2026-05-27 23:02:46', 'colonization'),
(34, 2, 80.00, '2026-05-27 23:02:46', 'colonization'),
(35, 1, 30.00, '2026-05-27 23:15:33', 'colonization'),
(36, 2, 80.00, '2026-05-27 23:15:33', 'colonization'),
(37, 1, 30.00, '2026-05-27 23:20:08', 'colonization'),
(38, 1, 25.00, '2026-05-27 23:24:57', 'colonization'),
(39, 2, 70.00, '2026-05-27 23:24:57', 'colonization'),
(40, 1, 27.00, '2026-05-28 01:00:22', 'colonization'),
(41, 2, 88.00, '2026-05-28 01:00:22', 'colonization'),
(42, 1, 32.00, '2026-05-28 01:03:13', 'colonization'),
(43, 2, 84.00, '2026-05-28 01:03:13', 'colonization'),
(44, 1, 32.00, '2026-05-28 01:03:18', 'colonization'),
(45, 2, 84.00, '2026-05-28 01:03:18', 'colonization'),
(46, 1, 30.00, '2026-05-28 01:20:31', 'colonization'),
(47, 2, 80.00, '2026-05-28 01:20:31', 'colonization'),
(48, 1, 20.50, '2026-05-29 15:14:47', 'pinning'),
(49, 2, 88.00, '2026-05-29 15:14:47', 'pinning'),
(50, 1, 22.00, '2026-05-29 15:16:32', 'pinning'),
(51, 2, 90.00, '2026-05-29 15:16:32', 'pinning'),
(52, 1, 25.00, '2026-05-29 15:17:11', 'pinning'),
(53, 2, 80.00, '2026-05-29 15:17:11', 'pinning'),
(54, 1, 23.00, '2026-05-30 18:16:47', 'pinning'),
(55, 2, 97.00, '2026-05-30 18:16:47', 'pinning'),
(56, 1, 31.00, '2026-06-01 21:25:26', 'fruiting'),
(57, 2, 80.00, '2026-06-01 21:25:26', 'fruiting'),
(58, 1, 26.50, '2026-06-01 21:25:46', 'fruiting'),
(59, 2, 86.00, '2026-06-01 21:25:46', 'fruiting'),
(60, 1, 28.00, '2026-06-01 21:26:02', 'fruiting'),
(61, 2, 88.00, '2026-06-01 21:26:02', 'fruiting'),
(62, 1, 25.80, '2026-06-01 21:26:33', 'fruiting'),
(63, 2, 85.50, '2026-06-01 21:26:33', 'fruiting'),
(64, 1, 31.00, '2026-06-01 21:27:01', 'fruiting'),
(65, 2, 87.00, '2026-06-01 21:27:01', 'fruiting'),
(66, 1, 27.00, '2026-06-01 21:27:54', 'fruiting'),
(67, 2, 80.00, '2026-06-01 21:27:54', 'fruiting'),
(68, 1, 34.00, '2026-06-01 21:40:52', 'fruiting'),
(69, 2, 95.00, '2026-06-01 21:40:52', 'fruiting'),
(70, 1, 28.00, '2026-06-01 21:52:58', 'fruiting'),
(71, 2, 96.00, '2026-06-01 21:52:58', 'fruiting'),
(72, 1, 27.00, '2026-06-01 22:07:02', 'fruiting'),
(73, 2, 92.00, '2026-06-01 22:07:02', 'fruiting'),
(74, 1, 26.50, '2026-06-01 22:15:31', 'fruiting'),
(75, 2, 94.00, '2026-06-01 22:15:31', 'fruiting'),
(76, 1, 29.00, '2026-06-01 22:20:15', 'fruiting'),
(77, 2, 70.00, '2026-06-01 22:20:15', 'fruiting'),
(78, 1, 35.00, '2026-06-01 22:23:07', 'fruiting'),
(79, 2, 89.00, '2026-06-01 22:23:07', 'fruiting'),
(80, 1, 29.50, '2026-06-01 22:26:02', 'fruiting'),
(81, 2, 89.50, '2026-06-01 22:26:02', 'fruiting'),
(82, 1, 34.00, '2026-06-01 22:28:33', 'fruiting'),
(83, 2, 95.00, '2026-06-01 22:28:33', 'fruiting'),
(84, 1, 31.00, '2026-06-01 22:31:09', 'fruiting'),
(85, 2, 80.00, '2026-06-01 22:31:09', 'fruiting'),
(86, 1, 27.00, '2026-06-01 22:33:10', 'fruiting'),
(87, 2, 87.00, '2026-06-01 22:33:10', 'fruiting'),
(88, 1, 28.00, '2026-06-01 22:34:19', 'fruiting'),
(89, 2, 88.00, '2026-06-01 22:34:19', 'fruiting'),
(90, 1, 31.00, '2026-06-01 22:35:42', 'fruiting'),
(91, 2, 87.00, '2026-06-01 22:35:42', 'fruiting'),
(92, 1, 26.50, '2026-06-01 22:37:18', 'fruiting'),
(93, 2, 78.00, '2026-06-01 22:37:18', 'fruiting'),
(94, 1, 26.50, '2026-06-01 22:38:43', 'fruiting'),
(95, 2, 94.00, '2026-06-01 22:38:43', 'fruiting'),
(96, 1, 32.00, '2026-06-01 22:40:13', 'fruiting'),
(97, 2, 75.00, '2026-06-01 22:40:13', 'fruiting'),
(98, 1, 28.00, '2026-06-01 22:41:52', 'fruiting'),
(99, 2, 96.00, '2026-06-01 22:41:52', 'fruiting'),
(100, 1, 28.00, '2026-06-01 22:44:21', 'fruiting'),
(101, 2, 75.00, '2026-06-01 22:44:21', 'fruiting'),
(102, 1, 33.00, '2026-06-01 22:45:43', 'fruiting'),
(103, 2, 86.50, '2026-06-01 22:45:43', 'fruiting'),
(104, 1, 25.00, '2026-06-01 22:47:14', 'fruiting'),
(105, 2, 85.00, '2026-06-01 22:47:14', 'fruiting'),
(106, 1, 34.00, '2026-06-01 22:47:40', 'fruiting'),
(107, 2, 87.50, '2026-06-01 22:47:40', 'fruiting'),
(108, 1, 26.50, '2026-06-01 22:48:41', 'fruiting'),
(109, 2, 78.00, '2026-06-01 22:48:41', 'fruiting'),
(110, 1, 25.00, '2026-06-01 22:49:45', 'fruiting'),
(111, 2, 72.00, '2026-06-01 22:49:45', 'fruiting'),
(112, 1, 26.50, '2026-06-01 22:50:07', 'fruiting'),
(113, 2, 94.00, '2026-06-01 22:50:07', 'fruiting'),
(114, 1, 32.00, '2026-06-01 22:52:38', 'fruiting'),
(115, 2, 75.00, '2026-06-01 22:52:38', 'fruiting'),
(116, 1, 32.00, '2026-06-01 22:53:13', 'fruiting'),
(117, 2, 75.00, '2026-06-01 22:53:13', 'fruiting'),
(118, 1, 35.00, '2026-06-01 22:53:49', 'fruiting'),
(119, 2, 89.00, '2026-06-01 22:53:49', 'fruiting'),
(120, 1, 29.00, '2026-06-01 22:54:10', 'fruiting'),
(121, 2, 70.00, '2026-06-01 22:54:10', 'fruiting'),
(122, 1, 29.00, '2026-06-01 22:54:30', 'fruiting'),
(123, 2, 99.00, '2026-06-01 22:54:30', 'fruiting'),
(124, 1, 34.00, '2026-06-01 22:54:56', 'fruiting'),
(125, 2, 95.00, '2026-06-01 22:54:56', 'fruiting'),
(126, 1, 28.50, '2026-06-01 22:55:34', 'fruiting'),
(127, 2, 88.50, '2026-06-01 22:55:34', 'fruiting'),
(128, 1, 27.00, '2026-06-01 22:56:03', 'fruiting'),
(129, 2, 92.00, '2026-06-01 22:56:03', 'fruiting'),
(130, 1, 31.00, '2026-06-01 22:56:23', 'fruiting'),
(131, 2, 87.00, '2026-06-01 22:56:23', 'fruiting'),
(132, 1, 25.80, '2026-06-01 22:56:43', 'fruiting'),
(133, 2, 85.50, '2026-06-01 22:56:43', 'fruiting'),
(134, 1, 28.00, '2026-06-01 22:57:06', 'fruiting'),
(135, 2, 75.00, '2026-06-01 22:57:06', 'fruiting'),
(136, 1, 34.00, '2026-06-01 22:57:25', 'fruiting'),
(137, 2, 87.50, '2026-06-01 22:57:25', 'fruiting'),
(138, 1, 29.00, '2026-06-01 22:57:47', 'fruiting'),
(139, 2, 89.00, '2026-06-01 22:57:47', 'fruiting'),
(140, 1, 35.00, '2026-06-01 22:58:27', 'fruiting'),
(141, 2, 89.00, '2026-06-01 22:58:27', 'fruiting'),
(142, 1, 25.00, '2026-06-01 22:58:52', 'fruiting'),
(143, 2, 98.00, '2026-06-01 22:58:52', 'fruiting'),
(144, 1, 26.50, '2026-06-01 22:59:57', 'fruiting'),
(145, 2, 78.00, '2026-06-01 22:59:57', 'fruiting'),
(146, 1, 25.00, '2026-06-01 23:00:25', 'fruiting'),
(147, 2, 98.00, '2026-06-01 23:00:25', 'fruiting'),
(148, 1, 27.30, '2026-06-01 23:00:50', 'fruiting'),
(149, 2, 87.50, '2026-06-01 23:00:50', 'fruiting'),
(150, 1, 31.00, '2026-06-01 23:01:09', 'fruiting'),
(151, 2, 80.00, '2026-06-01 23:01:09', 'fruiting'),
(152, 1, 27.00, '2026-06-01 23:01:38', 'fruiting'),
(153, 2, 92.00, '2026-06-01 23:01:38', 'fruiting'),
(154, 1, 31.00, '2026-06-01 23:02:01', 'fruiting'),
(155, 2, 80.00, '2026-06-01 23:02:01', 'fruiting'),
(156, 1, 27.00, '2026-06-01 23:02:31', 'fruiting'),
(157, 2, 80.00, '2026-06-01 23:02:31', 'fruiting'),
(158, 1, 27.00, '2026-06-01 23:02:50', 'fruiting'),
(159, 2, 87.00, '2026-06-01 23:02:50', 'fruiting'),
(160, 1, 31.00, '2026-06-01 23:03:16', 'fruiting'),
(161, 2, 87.00, '2026-06-01 23:03:16', 'fruiting'),
(162, 1, 34.00, '2026-06-01 23:20:05', 'fruiting'),
(163, 2, 90.00, '2026-06-01 23:20:05', 'fruiting'),
(164, 1, 25.80, '2026-06-01 23:36:18', 'fruiting'),
(165, 2, 85.50, '2026-06-01 23:36:18', 'fruiting'),
(166, 1, 31.00, '2026-06-01 23:37:18', 'fruiting'),
(167, 2, 87.00, '2026-06-01 23:37:18', 'fruiting'),
(168, 1, 31.00, '2026-06-01 23:44:32', 'fruiting'),
(169, 2, 89.00, '2026-06-01 23:44:32', 'fruiting'),
(170, 1, 30.00, '2026-06-01 23:47:31', 'fruiting'),
(171, 2, 90.00, '2026-06-01 23:47:31', 'fruiting'),
(172, 1, 30.00, '2026-06-01 23:52:04', 'fruiting'),
(173, 2, 91.00, '2026-06-01 23:52:04', 'fruiting'),
(174, 1, 30.00, '2026-06-02 06:23:47', 'fruiting'),
(175, 2, 92.00, '2026-06-02 06:23:48', 'fruiting'),
(176, 1, 30.00, '2026-06-02 06:24:35', 'fruiting'),
(177, 2, 91.00, '2026-06-02 06:24:35', 'fruiting'),
(178, 1, 30.00, '2026-06-02 06:48:43', 'fruiting'),
(179, 2, 89.00, '2026-06-02 06:48:43', 'fruiting');

-- --------------------------------------------------------

--
-- Table structure for table `system_controls`
--

CREATE TABLE `system_controls` (
  `id` int(11) NOT NULL,
  `device_name` varchar(50) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'OFF',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_controls`
--

INSERT INTO `system_controls` (`id`, `device_name`, `status`, `updated_at`) VALUES
(1, 'pump', 'OFF', '2026-05-25 08:38:06'),
(2, 'fan', 'OFF', '2026-05-25 08:38:06');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('email_notifications', '1'),
('max_humidity', '90.0'),
('max_temperature', '30.0'),
('min_humidity', '85.0'),
('system_status', 'NORMAL'),
('temperature_unit', 'C'),

-- Stage system (merged from stage_migration.sql)
('current_stage', 'colonization'),
('stage_started_at', ''),
('stage_expected_days', '14'),

-- Colonization thresholds
('colonization_min_temp', '24'),
('colonization_max_temp', '28'),
('colonization_min_hum', '70'),
('colonization_max_hum', '85'),
('colonization_default_days', '14'),

-- Pinning Initiation thresholds
('pinning_min_temp', '18'),
('pinning_max_temp', '24'),
('pinning_min_hum', '85'),
('pinning_max_hum', '95'),
('pinning_default_days', '7'),

-- Fruiting thresholds
('fruiting_min_temp', '24'),
('fruiting_max_temp', '28'),
('fruiting_min_hum', '80'),
('fruiting_max_hum', '95'),
('fruiting_default_days', '5'),

-- ML Algorithm
('active_ml_algorithm', 'Random Forest');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` varchar(20) DEFAULT 'active',
  `role` varchar(20) DEFAULT 'user',
  `phone` varchar(20) DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `password_hash`, `status`, `role`, `phone`, `avatar_url`) VALUES
(1, 'Admin User', 'admin', '$2y$10$ousWzcWmWRzJarjJeTcDCeJ1GnkKVgWWi6OoyCi4WpAAbcLX1Ugqa', 'Active', 'user', '+1234567890', NULL),
(2, 'jeviejohn calderon', 'jv', '$2y$10$ADX8kal7a7UC/iLCtVqEQeKbI5aiZcpgKtf6U6x34yFzp6EdWIRAm', 'Active', 'admin', '+639265028114', NULL),
(3, 'Admin Austin', 'SPECTRE', '$2y$10$xyYlxr8ud/VsHiAsVMav5.pR1D3iC6Fp5Lx6TzI2lb6sq35R38r/u', 'Active', 'admin', '09065605274', NULL),
(4, 'Kent Erwin Camiling', 'kent', '$2y$10$mqoSAz2yjNHq/MJ1a8cvAOpYJs4tG8Ke19YbMsJ.Mg.RdqyBb89CW', 'Active', 'user', '09151877379', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_name` (`device_name`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sensor_logs`
--
ALTER TABLE `sensor_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `device_id` (`device_id`),
  ADD KEY `device_recorded_idx` (`device_id`, `recorded_at`);

--
-- Indexes for table `system_controls`
--
ALTER TABLE `system_controls`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_name` (`device_name`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `sensor_logs`
--
ALTER TABLE `sensor_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=180;

--
-- AUTO_INCREMENT for table `system_controls`
--
ALTER TABLE `system_controls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `sensor_logs`
--
ALTER TABLE `sensor_logs`
  ADD CONSTRAINT `sensor_logs_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
