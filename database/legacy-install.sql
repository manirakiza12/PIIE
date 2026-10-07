-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Generation Time: Nov 12, 2024 at 06:31 AM
-- Server version: 8.0.35
-- PHP Version: 8.1.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ekattor8_2.2`
--

-- --------------------------------------------------------

--
-- Table structure for table `addons`
--

CREATE TABLE `addons` (
  `id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `features` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `version` float DEFAULT NULL,
  `purchase_code` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `unique_identifier` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Table structure for table `appraisals`
--

CREATE TABLE `appraisals` (
  `id` int NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `question` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `ans_type` varchar(255) DEFAULT NULL,
  `teacher_id` text,
  `class_id` int DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `status` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appraisal_submits`
--

CREATE TABLE `appraisal_submits` (
  `id` int NOT NULL,
  `answers` text,
  `teacher_id` text,
  `apprasial_id` int DEFAULT NULL,
  `student_id` int DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Table structure for table `admit_cards`
--

CREATE TABLE `admit_cards` (
  `id` int NOT NULL,
  `template` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `heading` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `exam_center` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `footer_text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `left_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `right_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sign` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `background_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `author` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `copies` int NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `timestamp` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `book_issues`
--

CREATE TABLE `book_issues` (
  `id` bigint UNSIGNED NOT NULL,
  `book_id` int NOT NULL,
  `class_id` int NOT NULL,
  `student_id` int NOT NULL,
  `issue_date` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` int NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `timestamp` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chats`
--

CREATE TABLE `chats` (
  `id` int NOT NULL,
  `message_thrade` int DEFAULT NULL,
  `reciver_id` int DEFAULT NULL,
  `sender_id` int DEFAULT NULL,
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `reply_id` int DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `read_status` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_rooms`
--

CREATE TABLE `class_rooms` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `currency`
--

CREATE TABLE `currency` (
  `id` int NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `symbol` varchar(255) DEFAULT NULL,
  `paypal_supported` int DEFAULT NULL,
  `stripe_supported` int DEFAULT NULL,
  `flutterwave_supported` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Dumping data for table `currency`
--

INSERT INTO `currency` (`id`, `name`, `code`, `symbol`, `paypal_supported`, `stripe_supported`, `flutterwave_supported`) VALUES
(1, 'US Dollar', 'USD', '$', 1, 1, 1),
(2, 'Albanian Lek', 'ALL', 'Lek', 0, 1, 0),
(3, 'Algerian Dinar', 'DZD', 'دج', 1, 1, 0),
(4, 'Angolan Kwanza', 'AOA', 'Kz', 1, 1, 0),
(5, 'Argentine Peso', 'ARS', '$', 1, 1, 1),
(6, 'Armenian Dram', 'AMD', '֏', 1, 1, 0),
(7, 'Aruban Florin', 'AWG', 'ƒ', 1, 1, 0),
(8, 'Australian Dollar', 'AUD', '$', 1, 1, 0),
(9, 'Azerbaijani Manat', 'AZN', 'm', 1, 1, 0),
(10, 'Bahamian Dollar', 'BSD', 'B$', 1, 1, 0),
(11, 'Bahraini Dinar', 'BHD', '.د.ب', 1, 1, 0),
(12, 'Bangladeshi Taka', 'BDT', '৳', 1, 1, 0),
(13, 'Barbadian Dollar', 'BBD', 'Bds$', 1, 1, 0),
(14, 'Belarusian Ruble', 'BYR', 'Br', 0, 0, 0),
(15, 'Belgian Franc', 'BEF', 'fr', 1, 1, 0),
(16, 'Belize Dollar', 'BZD', '$', 1, 1, 0),
(17, 'Bermudan Dollar', 'BMD', '$', 1, 1, 0),
(18, 'Bhutanese Ngultrum', 'BTN', 'Nu.', 1, 1, 0),
(19, 'Bitcoin', 'BTC', '฿', 1, 1, 0),
(20, 'Bolivian Boliviano', 'BOB', 'Bs.', 1, 1, 0),
(21, 'Bosnia', 'BAM', 'KM', 1, 1, 0),
(22, 'Botswanan Pula', 'BWP', 'P', 1, 1, 0),
(23, 'Brazilian Real', 'BRL', 'R$', 1, 1, 1),
(24, 'British Pound Sterling', 'GBP', '£', 1, 1, 1),
(25, 'Brunei Dollar', 'BND', 'B$', 1, 1, 0),
(26, 'Bulgarian Lev', 'BGN', 'Лв.', 1, 1, 0),
(27, 'Burundian Franc', 'BIF', 'FBu', 1, 1, 0),
(28, 'Cambodian Riel', 'KHR', 'KHR', 1, 1, 0),
(29, 'Canadian Dollar', 'CAD', '$', 1, 1, 1),
(30, 'Cape Verdean Escudo', 'CVE', '$', 1, 1, 1),
(31, 'Cayman Islands Dollar', 'KYD', '$', 1, 1, 0),
(32, 'CFA Franc BCEAO', 'XOF', 'CFA', 1, 1, 1),
(33, 'CFA Franc BEAC', 'XAF', 'FCFA', 1, 1, 1),
(34, 'CFP Franc', 'XPF', '₣', 1, 1, 0),
(35, 'Chilean Peso', 'CLP', '$', 1, 1, 1),
(36, 'Chinese Yuan', 'CNY', '¥', 1, 1, 0),
(37, 'Colombian Peso', 'COP', '$', 1, 1, 0),
(38, 'Comorian Franc', 'KMF', 'CF', 1, 1, 0),
(39, 'Congolese Franc', 'CDF', 'FC', 1, 1, 1),
(40, 'Costa Rican ColÃ³n', 'CRC', '₡', 1, 1, 0),
(41, 'Croatian Kuna', 'HRK', 'kn', 1, 1, 0),
(42, 'Cuban Convertible Peso', 'CUC', '$, CUC', 1, 1, 0),
(43, 'Czech Republic Koruna', 'CZK', 'Kč', 1, 1, 0),
(44, 'Danish Krone', 'DKK', 'Kr.', 1, 1, 0),
(45, 'Djiboutian Franc', 'DJF', 'Fdj', 1, 1, 0),
(46, 'Dominican Peso', 'DOP', '$', 1, 1, 0),
(47, 'East Caribbean Dollar', 'XCD', '$', 1, 1, 0),
(48, 'Egyptian Pound', 'EGP', 'ج.م', 1, 1, 1),
(49, 'Eritrean Nakfa', 'ERN', 'Nfk', 1, 1, 0),
(50, 'Estonian Kroon', 'EEK', 'kr', 1, 1, 0),
(51, 'Ethiopian Birr', 'ETB', 'Nkf', 1, 1, 0),
(52, 'Euro', 'EUR', '€', 1, 1, 1),
(53, 'Falkland Islands Pound', 'FKP', '£', 1, 1, 0),
(54, 'Fijian Dollar', 'FJD', 'FJ$', 1, 1, 0),
(55, 'Gambian Dalasi', 'GMD', 'D', 1, 1, 1),
(56, 'Georgian Lari', 'GEL', 'ლ', 1, 1, 0),
(57, 'German Mark', 'DEM', 'DM', 1, 1, 0),
(58, 'Ghanaian Cedi', 'GHS', 'GH₵', 1, 1, 1),
(59, 'Gibraltar Pound', 'GIP', '£', 1, 1, 0),
(60, 'Greek Drachma', 'GRD', '₯, Δρχ, Δρ', 1, 1, 0),
(61, 'Guatemalan Quetzal', 'GTQ', 'Q', 1, 1, 0),
(62, 'Guinean Franc', 'GNF', 'FG', 1, 1, 1),
(63, 'Guyanaese Dollar', 'GYD', '$', 1, 1, 0),
(64, 'Haitian Gourde', 'HTG', 'G', 1, 1, 0),
(65, 'Honduran Lempira', 'HNL', 'L', 1, 1, 0),
(66, 'Hong Kong Dollar', 'HKD', '$', 1, 1, 0),
(67, 'Hungarian Forint', 'HUF', 'Ft', 1, 1, 0),
(68, 'Icelandic KrÃ³na', 'ISK', 'kr', 1, 1, 0),
(69, 'Indian Rupee', 'INR', '₹', 1, 1, 0),
(70, 'Indonesian Rupiah', 'IDR', 'Rp', 1, 1, 0),
(71, 'Iranian Rial', 'IRR', '﷼', 1, 1, 0),
(72, 'Iraqi Dinar', 'IQD', 'د.ع', 1, 1, 0),
(73, 'Israeli New Sheqel', 'ILS', '₪', 1, 1, 0),
(74, 'Italian Lira', 'ITL', 'L,£', 1, 1, 0),
(75, 'Jamaican Dollar', 'JMD', 'J$', 1, 1, 0),
(76, 'Japanese Yen', 'JPY', '¥', 1, 1, 0),
(77, 'Jordanian Dinar', 'JOD', 'ا.د', 1, 1, 0),
(78, 'Kazakhstani Tenge', 'KZT', 'лв', 1, 1, 0),
(79, 'Kenyan Shilling', 'KES', 'KSh', 1, 1, 1),
(80, 'Kuwaiti Dinar', 'KWD', 'ك.د', 1, 1, 0),
(81, 'Kyrgystani Som', 'KGS', 'лв', 1, 1, 0),
(82, 'Laotian Kip', 'LAK', '₭', 1, 1, 0),
(83, 'Latvian Lats', 'LVL', 'Ls', 0, 0, 0),
(84, 'Lebanese Pound', 'LBP', '£', 1, 1, 0),
(85, 'Lesotho Loti', 'LSL', 'L', 1, 1, 0),
(86, 'Liberian Dollar', 'LRD', '$', 1, 1, 1),
(87, 'Libyan Dinar', 'LYD', 'د.ل', 1, 1, 0),
(88, 'Lithuanian Litas', 'LTL', 'Lt', 0, 0, 0),
(89, 'Macanese Pataca', 'MOP', '$', 1, 1, 0),
(90, 'Macedonian Denar', 'MKD', 'ден', 1, 1, 0),
(91, 'Malagasy Ariary', 'MGA', 'Ar', 1, 1, 0),
(92, 'Malawian Kwacha', 'MWK', 'MK', 1, 1, 1),
(93, 'Malaysian Ringgit', 'MYR', 'RM', 1, 1, 0),
(94, 'Maldivian Rufiyaa', 'MVR', 'Rf', 1, 1, 0),
(95, 'Mauritanian Ouguiya', 'MRO', 'MRU', 1, 1, 0),
(96, 'Mauritian Rupee', 'MUR', '₨', 1, 1, 0),
(97, 'Mexican Peso', 'MXN', '$', 1, 1, 0),
(98, 'Moldovan Leu', 'MDL', 'L', 1, 1, 0),
(99, 'Mongolian Tugrik', 'MNT', '₮', 1, 1, 0),
(100, 'Moroccan Dirham', 'MAD', 'MAD', 1, 1, 1),
(101, 'Mozambican Metical', 'MZM', 'MT', 1, 1, 0),
(102, 'Myanmar Kyat', 'MMK', 'K', 1, 1, 0),
(103, 'Namibian Dollar', 'NAD', '$', 1, 1, 0),
(104, 'Nepalese Rupee', 'NPR', '₨', 1, 1, 0),
(105, 'Netherlands Antillean Guilder', 'ANG', 'ƒ', 1, 1, 0),
(106, 'New Taiwan Dollar', 'TWD', '$', 1, 1, 0),
(107, 'New Zealand Dollar', 'NZD', '$', 1, 1, 0),
(108, 'Nicaraguan CÃ³rdoba', 'NIO', 'C$', 1, 1, 0),
(109, 'Nigerian Naira', 'NGN', '₦', 1, 1, 1),
(110, 'North Korean Won', 'KPW', '₩', 0, 0, 0),
(111, 'Norwegian Krone', 'NOK', 'kr', 1, 1, 0),
(112, 'Omani Rial', 'OMR', '.ع.ر', 0, 0, 0),
(113, 'Pakistani Rupee', 'PKR', '₨', 1, 1, 0),
(114, 'Panamanian Balboa', 'PAB', 'B/.', 1, 1, 0),
(115, 'Papua New Guinean Kina', 'PGK', 'K', 1, 1, 0),
(116, 'Paraguayan Guarani', 'PYG', '₲', 1, 1, 0),
(117, 'Peruvian Nuevo Sol', 'PEN', 'S/.', 1, 1, 0),
(118, 'Philippine Peso', 'PHP', '₱', 1, 1, 0),
(119, 'Polish Zloty', 'PLN', 'zł', 1, 1, 0),
(120, 'Qatari Rial', 'QAR', 'ق.ر', 1, 1, 0),
(121, 'Romanian Leu', 'RON', 'lei', 1, 1, 0),
(122, 'Russian Ruble', 'RUB', '₽', 1, 1, 0),
(123, 'Rwandan Franc', 'RWF', 'FRw', 1, 1, 1),
(124, 'Salvadoran ColÃ³n', 'SVC', '₡', 0, 0, 0),
(125, 'Samoan Tala', 'WST', 'SAT', 1, 1, 0),
(126, 'Saudi Riyal', 'SAR', '﷼', 1, 1, 0),
(127, 'Serbian Dinar', 'RSD', 'din', 1, 1, 0),
(128, 'Seychellois Rupee', 'SCR', 'SRe', 1, 1, 0),
(129, 'Sierra Leonean Leone', 'SLL', 'Le', 1, 1, 1),
(130, 'Singapore Dollar', 'SGD', '$', 1, 1, 0),
(131, 'Slovak Koruna', 'SKK', 'Sk', 1, 1, 0),
(132, 'Solomon Islands Dollar', 'SBD', 'Si$', 1, 1, 0),
(133, 'Somali Shilling', 'SOS', 'Sh.so.', 1, 1, 0),
(134, 'South African Rand', 'ZAR', 'R', 1, 1, 1),
(135, 'South Korean Won', 'KRW', '₩', 1, 1, 0),
(136, 'Special Drawing Rights', 'XDR', 'SDR', 1, 1, 0),
(137, 'Sri Lankan Rupee', 'LKR', 'Rs', 1, 1, 0),
(138, 'St. Helena Pound', 'SHP', '£', 1, 1, 0),
(139, 'Sudanese Pound', 'SDG', '.س.ج', 1, 1, 0),
(140, 'Surinamese Dollar', 'SRD', '$', 1, 1, 0),
(141, 'Swazi Lilangeni', 'SZL', 'E', 1, 1, 0),
(142, 'Swedish Krona', 'SEK', 'kr', 1, 1, 0),
(143, 'Swiss Franc', 'CHF', 'CHf', 1, 1, 0),
(144, 'Syrian Pound', 'SYP', 'LS', 0, 0, 0),
(145, 'São Tomé and Príncipe Dobra', 'STD', 'Db', 1, 1, 1),
(146, 'Tajikistani Somoni', 'TJS', 'SM', 1, 1, 0),
(147, 'Tanzanian Shilling', 'TZS', 'TSh', 1, 1, 1),
(148, 'Thai Baht', 'THB', '฿', 1, 1, 0),
(149, 'Tongan pa\'anga', 'TOP', '$', 1, 1, 0),
(150, 'Trinidad & Tobago Dollar', 'TTD', '$', 1, 1, 0),
(151, 'Tunisian Dinar', 'TND', 'ت.د', 1, 1, 0),
(152, 'Turkish Lira', 'TRY', '₺', 1, 1, 0),
(153, 'Turkmenistani Manat', 'TMT', 'T', 1, 1, 0),
(154, 'Ugandan Shilling', 'UGX', 'UGX', 1, 1, 1),
(155, 'Ukrainian Hryvnia', 'UAH', '₴', 1, 1, 0),
(156, 'United Arab Emirates Dirham', 'AED', 'إ.د', 1, 1, 0),
(157, 'Uruguayan Peso', 'UYU', '$', 1, 1, 0),
(158, 'Afghan Afghani', 'AFA', '؋', 1, 1, 0),
(159, 'Uzbekistan Som', 'UZS', 'лв', 1, 1, 0),
(160, 'Vanuatu Vatu', 'VUV', 'VT', 1, 1, 0),
(161, 'Venezuelan BolÃvar', 'VEF', 'Bs', 0, 0, 0),
(162, 'Vietnamese Dong', 'VND', '₫', 1, 1, 0),
(163, 'Yemeni Rial', 'YER', '﷼', 1, 1, 0),
(164, 'Zambian Kwacha', 'ZMK', 'ZK', 1, 1, 1),
(165, 'PesosColombian Peso', 'COP', '$', 0, 0, 1),
(166, 'SEPA', 'EUR', '€', 0, 0, 1),
(167, 'Mozambican Metical', 'MZN', 'MT', 0, 0, 1),
(168, 'Peruvian Sol', 'SOL', 'S/', 0, 0, 1),
(169, 'Zambian Kwacha', 'ZMW', 'ZK', 0, 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `daily_attendances`
--

CREATE TABLE `daily_attendances` (
  `id` bigint UNSIGNED NOT NULL,
  `class_id` bigint UNSIGNED DEFAULT NULL,
  `programme_id` bigint UNSIGNED DEFAULT NULL,
  `intake_session_id` bigint UNSIGNED DEFAULT NULL,
  `section_id` int DEFAULT NULL,
  `student_id` int NOT NULL,
  `status` int NOT NULL,
  `session_id` int DEFAULT NULL,
  `school_id` int NOT NULL,
  `timestamp` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollment`
--

CREATE TABLE `enrollment` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `class_id` int NOT NULL,
  `section_id` int NOT NULL,
  `school_id` int NOT NULL,
  `department_id` int DEFAULT NULL,
  `session_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exams`
--

CREATE TABLE `exams` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exam_category_id` int DEFAULT NULL,
  `exam_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `starting_time` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ending_time` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_marks` float NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `room_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_categories`
--

CREATE TABLE `exam_categories` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `timestamp` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint UNSIGNED NOT NULL,
  `expense_category_id` int NOT NULL,
  `date` int NOT NULL,
  `amount` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faq`
--

CREATE TABLE `faq` (
  `id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` longtext COLLATE utf8mb4_general_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faq`
--

INSERT INTO `faq` (`id`, `title`, `description`) VALUES
(1, 'What is Ekattor 8?', 'Ekattor 8 is a collection of programs designed to assist schools in administering their executive responsibilities on a day-to-day basis. Ekattor 8 is an updated version of Ekattor ERP (Enterprise Resource Planning). Also, Ekattor 8 is designed for SAAS (Software as a Service) projects.'),
(2, 'How can I get developed my customer features?', 'Custom features do not coming with product support. You can contact our support center and send us details about your requirement. If our schedule is open, we can give you a quotation and take your project according to the contract.'),
(5, 'Which license to choose for my client project?', 'If you use academy LMS for a commercial project of a client, you will be required extended license.'),
(6, 'How much time will I get developer support?', 'By default, you are entitled to developer support for 6 months from the date of your purchase. Later on anytime you can renew the support pack if you need developer support. If you don’t need any developer support, you don’t need to buy it.');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `feedback_text` text COLLATE utf8mb4_general_ci,
  `student_id` int DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `class_id` int DEFAULT NULL,
  `section_id` int DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `session_id` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `frontend_events`
--

CREATE TABLE `frontend_events` (
  `id` bigint UNSIGNED NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` int NOT NULL,
  `status` int NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `frontend_features`
--

CREATE TABLE `frontend_features` (
  `id` int NOT NULL,
  `title` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `icon` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `global_settings`
--

CREATE TABLE `global_settings` (
  `id` int NOT NULL,
  `key` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `value` longtext COLLATE utf8mb4_general_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `global_settings`
--

INSERT INTO `global_settings` (`id`, `key`, `value`) VALUES
(1, 'system_name', 'Ekattor School Manager'),
(2, 'system_title', 'Ekattor'),
(3, 'system_email', 'ekattor@example.com'),
(4, 'phone', '900500000'),
(5, 'address', '4333 Factoria Blvd SE, Bellevue, WA 98006'),
(6, 'purchase_code', NULL),
(7, 'system_currency', 'USD'),
(8, 'currency_position', 'left-space'),
(9, 'running_session', '1'),
(10, 'language', 'english'),
(11, 'payment_settings', '[]'),
(12, 'footer_text', 'By Creativeitem'),
(13, 'footer_link', 'http://creativeitem.com/'),
(14, 'version', '2.4.2'),
(15, 'fax', '1234567890'),
(16, 'timezone', 'Asia/Dhaka'),
(17, 'smtp_protocol', ''),
(18, 'smtp_crypto', ''),
(19, 'smtp_host', ''),
(20, 'smtp_port', ''),
(21, 'smtp_user', ''),
(22, 'smtp_pass', ''),
(28, 'offline', '{\"status\":\"1\"}'),
(29, 'light_logo', 'light-logo.png'),
(30, 'dark_logo', '16630508541.png'),
(31, 'favicon', 'favicon.png'),
(32, 'randCallRange', '30'),
(33, 'help_link', 'http://support.creativeitem.com/'),
(34, 'youtube_api_key', 'youtube-api-key'),
(35, 'vimeo_api_key', 'vimeo-api-key'),
(36, 'banner_title', 'Bringing Excellence To Students'),
(37, 'banner_subtitle', 'Empowering and inspiring all students to excel as life long learners'),
(38, 'facebook_link', 'https://www.facebook.com/CreativeitemApps'),
(39, 'twitter_link', 'https://twitter.com/creativeitem'),
(40, 'linkedin_link', 'https://www.linkedin.com/company/creativeitem'),
(41, 'instagram_link', 'http://www.instagram.com/creativeitem'),
(42, 'price_subtitle', 'Choose the best subscription plan for your school'),
(43, 'copyright_text', '2024 Academy, All rights reserved'),
(44, 'contact_email', 'ekattor@domain.com'),
(45, 'frontend_footer_text', 'Ekattor 8 is a collection of programs designed to assist schools in administering their executive responsibilities on a day-to-day basis. It is designed for SAAS (Software as a Service) projects.'),
(46, 'faq_subtitle', 'Frequently asked questions'),
(49, 'frontend_view', '1'),
(50, 'white_logo', 'white_logo.png'),
(51, 'navbar_title', 'Ekattor8'),
(53, 'email_title', 'Subscription'),
(54, 'email_details', 'Feel free to reach out to us anytime if you have questions or feedback. We value your input and strive to provide the best experience possible.'),
(55, 'warning_text', 'This email is from an automat'),
(56, 'email_logo', '16904374791.png'),
(57, 'socialLogo1', '16907191042.png'),
(58, 'socialLogo2', '16907191913.png'),
(59, 'socialLogo3', '16907194544.png'),
(60, 'paypal', '{\"status\":\"0\",\"mode\":\"test\",\"test_client_id\":\"snd_cl_id_xxxxxxxxxxxxx\",\"test_secret_key\":\"snd_cl_sid_xxxxxxxxxxxx\",\"live_client_id\":\"lv_cl_id_xxxxxxxxxxxxxxx\",\"live_secret_key\":\"lv_cl_sid_xxxxxxxxxxxxxx\"}'),
(61, 'stripe', '{\"status\":\"0\",\"mode\":\"test\",\"test_key\":\"pk_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"sk_test_xxxxxxxxxxxxxx\",\"public_live_key\":\"pk_live_xxxxxxxxxxxxxx\",\"secret_live_key\":\"sk_live_xxxxxxxxxxxxxx\"}'),
(62, 'razorpay', '{\"status\":\"0\",\"mode\":\"test\",\"test_key\":\"rzp_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"rzs_test_xxxxxxxxxxxxx\",\"live_key\":\"rzp_live_xxxxxxxxxxxxx\",\"live_secret_key\":\"rzs_live_xxxxxxxxxxxxx\",\"theme_color\":\"#00ffff\"}'),
(63, 'paytm', '{\"status\":\"0\",\"mode\":\"test\",\"test_merchant_id\":\"tm_id_xxxxxxxxxxxx\",\"test_merchant_key\":\"tm_key_xxxxxxxxxx\",\"live_merchant_id\":\"lv_mid_xxxxxxxxxxx\",\"live_merchant_key\":\"lv_key_xxxxxxxxxxx\",\"environment\":\"provide-a-environment\",\"merchant_website\":\"merchant-website\",\"channel\":\"provide-channel-type\",\"industry_type\":\"provide-industry-type\"}'),
(64, 'flutterwave', '{\"status\":\"0\",\"mode\":\"test\",\"test_key\":\"flwp_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"flws_test_xxxxxxxxxxxxx\",\"test_encryption_key\":\"flwe_test_xxxxxxxxxxxxx\",\"public_live_key\":\"flwp_live_xxxxxxxxxxxxxx\",\"secret_live_key\":\"flws_live_xxxxxxxxxxxxxx\",\"encryption_live_key\":\"flwe_live_xxxxxxxxxxxxxx\"}'),
(65, 'front_logo', '17001224705.png'),
(67, 'features_title', 'Features'),
(68, 'features_subtitle', 'Make your application more advanced with Ekattor 8'),
(81, 'off_pay_ins_text', 'You can make payments using your mobile banking number.'),
(82, 'off_pay_ins_file', ''),
(83, 'recaptcha_site_key', ''),
(84, 'recaptcha_secret_key', ''),
(85, 'recaptcha_switch_value', '');

-- --------------------------------------------------------

--
-- Table structure for table `gradebooks`
--

CREATE TABLE `gradebooks` (
  `id` int NOT NULL,
  `class_id` int NOT NULL,
  `section_id` int NOT NULL,
  `student_id` int NOT NULL,
  `exam_category_id` int NOT NULL,
  `marks` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `comment` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `timestamp` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grade_point` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mark_from` int NOT NULL,
  `mark_upto` int NOT NULL,
  `school_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `language`
--

CREATE TABLE `language` (
  `id` int NOT NULL,
  `name` varchar(50) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `phrase` varchar(300) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `translated` varchar(300) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Dumping data for table `language`
--

INSERT INTO `language` (`id`, `name`, `phrase`, `translated`) VALUES
(1, 'english', 'Dashboard', 'Dashboard'),
(2, 'english', 'Home', 'Home'),
(3, 'english', 'Schools', 'Schools'),
(4, 'english', 'Total Schools', 'Total Schools'),
(5, 'english', 'Subscription', 'Subscription'),
(6, 'english', 'Total Active Subscription', 'Total Active Subscription'),
(7, 'english', 'Subscription Payment', 'Subscription Payment'),
(8, 'english', 'Superadmin | Ekator 8', 'Superadmin | Ekator 8'),
(9, 'english', 'Close', 'Close'),
(10, 'english', 'School List', 'School List'),
(11, 'english', 'Create school', 'Create school'),
(12, 'english', 'Pending List', 'Pending List'),
(13, 'english', 'Package', 'Package'),
(14, 'english', 'Subscriptions', 'Subscriptions'),
(15, 'english', 'Subscription Report', 'Subscription Report'),
(16, 'english', 'Pending Request', 'Pending Request'),
(17, 'english', 'Confirmed Payment', 'Confirmed Payment'),
(18, 'english', 'Addons', 'Addons'),
(19, 'english', 'Settings', 'Settings'),
(20, 'english', 'System Settings', 'System Settings'),
(21, 'english', 'Session Manager', 'Session Manager'),
(22, 'english', 'Payment Settings', 'Payment Settings'),
(23, 'english', 'Smtp settings', 'Smtp settings'),
(24, 'english', 'About', 'About'),
(25, 'english', 'Superadmin', 'Superadmin'),
(26, 'english', 'My Account', 'My Account'),
(27, 'english', 'Change Password', 'Change Password'),
(28, 'english', 'Log out', 'Log out'),
(29, 'english', 'Loading...', 'Loading...'),
(30, 'english', 'Heads up!', 'Heads up!'),
(31, 'english', 'Are you sure?', 'Are you sure?'),
(32, 'english', 'Back', 'Back'),
(33, 'english', 'Continue', 'Continue'),
(34, 'english', 'You won\'t able to revert this!', 'You won\'t able to revert this!'),
(35, 'english', 'Yes', 'Yes'),
(36, 'english', 'Cancel', 'Cancel'),
(37, 'english', 'Add School', 'Add School'),
(38, 'english', 'Name', 'Name'),
(39, 'english', 'Address', 'Address'),
(40, 'english', 'Phone', 'Phone'),
(41, 'english', 'Info', 'Info'),
(42, 'english', 'Status', 'Status'),
(43, 'english', 'Action', 'Action'),
(44, 'english', 'Active', 'Active'),
(45, 'english', 'Actions', 'Actions'),
(46, 'english', 'Edit School', 'Edit School'),
(47, 'english', 'Edit', 'Edit'),
(48, 'english', 'Delete', 'Delete'),
(49, 'english', 'School Form', 'School Form'),
(50, 'english', 'Provide all the information required for your school.', 'Provide all the information required for your school.'),
(51, 'english', 'Also provide a admin information with email and passwoard.', 'Also provide a admin information with email and passwoard.'),
(52, 'english', 'So that admin can access the created school.', 'So that admin can access the created school.'),
(53, 'english', 'SCHOOL INFO', 'SCHOOL INFO'),
(54, 'english', 'School Name', 'School Name'),
(55, 'english', 'School Address', 'School Address'),
(56, 'english', 'School Email', 'School Email'),
(57, 'english', 'School Phone', 'School Phone'),
(58, 'english', 'ADMIN INFO', 'ADMIN INFO'),
(59, 'english', 'Gender', 'Gender'),
(60, 'english', 'Select a gender', 'Select a gender'),
(61, 'english', 'Male', 'Male'),
(62, 'english', 'Female', 'Female'),
(63, 'english', 'Blood group', 'Blood group'),
(64, 'english', 'Select a blood group', 'Select a blood group'),
(65, 'english', 'A+', 'A+'),
(66, 'english', 'A-', 'A-'),
(67, 'english', 'B+', 'B+'),
(68, 'english', 'B-', 'B-'),
(69, 'english', 'AB+', 'AB+'),
(70, 'english', 'AB-', 'AB-'),
(71, 'english', 'O+', 'O+'),
(72, 'english', 'O-', 'O-'),
(73, 'english', 'Admin Address', 'Admin Address'),
(74, 'english', 'Admin Phone Number', 'Admin Phone Number'),
(75, 'english', 'Photo', 'Photo'),
(76, 'english', 'Admin Email', 'Admin Email'),
(77, 'english', 'Admin Password', 'Admin Password'),
(78, 'english', 'Submit', 'Submit'),
(79, 'english', 'Pending School List', 'Pending School List'),
(80, 'english', 'No data found', 'No data found'),
(81, 'english', 'Packages', 'Packages'),
(82, 'english', 'Add Package', 'Add Package'),
(83, 'english', 'Price', 'Price'),
(84, 'english', 'Interval', 'Interval'),
(85, 'english', 'Preiod', 'Preiod'),
(86, 'english', 'Filter', 'Filter'),
(87, 'english', 'Export', 'Export'),
(88, 'english', 'PDF', 'PDF'),
(89, 'english', 'CSV', 'CSV'),
(90, 'english', 'Print', 'Print'),
(91, 'english', 'Paid By', 'Paid By'),
(92, 'english', 'Purchase Date', 'Purchase Date'),
(93, 'english', 'Expire Date', 'Expire Date'),
(94, 'english', 'Confirmed Request', 'Confirmed Request'),
(95, 'english', 'Payment For', 'Payment For'),
(96, 'english', 'Payment Document', 'Payment Document'),
(97, 'english', 'Approve', 'Approve'),
(98, 'english', 'Manage Addons', 'Manage Addons'),
(99, 'english', 'Install addon', 'Install addon'),
(100, 'english', 'Add new addon', 'Add new addon'),
(101, 'english', 'System Name', 'System Name'),
(102, 'english', 'System Title', 'System Title'),
(103, 'english', 'System Email', 'System Email'),
(104, 'english', 'Fax', 'Fax'),
(105, 'english', 'Timezone', 'Timezone'),
(106, 'english', 'Footer Text', 'Footer Text'),
(107, 'english', 'Footer Link', 'Footer Link'),
(108, 'english', 'PRODUCT UPDATE', 'PRODUCT UPDATE'),
(109, 'english', 'File', 'File'),
(110, 'english', 'Update', 'Update'),
(111, 'english', 'SYSTEM LOGO', 'SYSTEM LOGO'),
(112, 'english', 'Dark logo', 'Dark logo'),
(113, 'english', 'Light logo', 'Light logo'),
(114, 'english', 'Favicon', 'Favicon'),
(115, 'english', 'Update Logo', 'Update Logo'),
(116, 'english', 'Create Session', 'Create Session'),
(117, 'english', 'Add Session', 'Add Session'),
(118, 'english', 'Active session ', 'Active session '),
(119, 'english', 'Select a session', 'Select a session'),
(120, 'english', 'Activate', 'Activate'),
(121, 'english', 'Session title', 'Session title'),
(122, 'english', 'Options', 'Options'),
(123, 'english', 'Edit Session', 'Edit Session'),
(124, 'english', 'Global Currency', 'Global Currency'),
(125, 'english', 'Select system currency', 'Select system currency'),
(126, 'english', 'Currency Position', 'Currency Position'),
(127, 'english', 'Left', 'Left'),
(128, 'english', 'Right', 'Right'),
(129, 'english', 'Left with a space', 'Left with a space'),
(130, 'english', 'Right with a space', 'Right with a space'),
(131, 'english', 'Update Currency', 'Update Currency'),
(132, 'english', 'Protocol', 'Protocol'),
(133, 'english', 'Smtp crypto', 'Smtp crypto'),
(134, 'english', 'Smtp host', 'Smtp host'),
(135, 'english', 'Smtp port', 'Smtp port'),
(136, 'english', 'Smtp username', 'Smtp username'),
(137, 'english', 'Smtp password', 'Smtp password'),
(138, 'english', 'Save', 'Save'),
(139, 'english', 'Not found', 'Not found'),
(140, 'english', 'About this application', 'About this application'),
(141, 'english', 'Software version', 'Software version'),
(142, 'english', 'Check update', 'Check update'),
(143, 'english', 'PHP version', 'PHP version'),
(144, 'english', 'Curl enable', 'Curl enable'),
(145, 'english', 'Enabled', 'Enabled'),
(146, 'english', 'Purchase code', 'Purchase code'),
(147, 'english', 'Product license', 'Product license'),
(148, 'english', 'invalid', 'invalid'),
(149, 'english', 'Enter valid purchase code', 'Enter valid purchase code'),
(150, 'english', 'Customer support status', 'Customer support status'),
(151, 'english', 'Support expiry date', 'Support expiry date'),
(152, 'english', 'Customer name', 'Customer name'),
(153, 'english', 'Get customer support', 'Get customer support'),
(154, 'english', 'Customer support', 'Customer support'),
(155, 'english', 'Email', 'Email'),
(156, 'english', 'Password', 'Password'),
(157, 'english', 'Forgot password', 'Forgot password'),
(158, 'english', 'Help', 'Help'),
(159, 'english', 'Login', 'Login'),
(160, 'english', 'Total Student', 'Total Student'),
(161, 'english', 'Teacher', 'Teacher'),
(162, 'english', 'Total Teacher', 'Total Teacher'),
(163, 'english', 'Parents', 'Parents'),
(164, 'english', 'Total Parent', 'Total Parent'),
(165, 'english', 'Staff', 'Staff'),
(166, 'english', 'Total Staff', 'Total Staff'),
(167, 'english', 'Todays Attendance', 'Todays Attendance'),
(168, 'english', 'Go to Attendance', 'Go to Attendance'),
(169, 'english', 'Income Report', 'Income Report'),
(170, 'english', 'Year', 'Year'),
(171, 'english', 'Month', 'Month'),
(172, 'english', 'Week', 'Week'),
(173, 'english', 'Upcoming Events', 'Upcoming Events'),
(174, 'english', 'See all', 'See all'),
(175, 'english', 'Admin', 'Admin'),
(176, 'english', 'Users', 'Users'),
(177, 'english', 'Accountant', 'Accountant'),
(178, 'english', 'Librarian', 'Librarian'),
(179, 'english', 'Parent', 'Parent'),
(180, 'english', 'Student', 'Student'),
(181, 'english', 'Teacher Permission', 'Teacher Permission'),
(182, 'english', 'Admissions', 'Admissions'),
(183, 'english', 'Examination', 'Examination'),
(184, 'english', 'Exam Category', 'Exam Category'),
(185, 'english', 'Offline Exam', 'Offline Exam'),
(186, 'english', 'Marks', 'Marks'),
(187, 'english', 'Grades', 'Grades'),
(188, 'english', 'Promotion', 'Promotion'),
(189, 'english', 'Academic', 'Academic'),
(190, 'english', 'Daily Attendance', 'Daily Attendance'),
(191, 'english', 'Class List', 'Class List'),
(192, 'english', 'Class Routine', 'Class Routine'),
(193, 'english', 'Subjects', 'Subjects'),
(194, 'english', 'Gradebooks', 'Gradebooks'),
(195, 'english', 'Syllabus', 'Syllabus'),
(196, 'english', 'Class Room', 'Class Room'),
(197, 'english', 'Department', 'Department'),
(198, 'english', 'Accounting', 'Accounting'),
(199, 'english', 'Student Fee Manager', 'Student Fee Manager'),
(200, 'english', 'Offline Payment Request', 'Offline Payment Request'),
(201, 'english', 'Expense Manager', 'Expense Manager'),
(202, 'english', 'Expense Category', 'Expense Category'),
(203, 'english', 'Back Office', 'Back Office'),
(204, 'english', 'Book List Manager', 'Book List Manager'),
(205, 'english', 'Book Issue Report', 'Book Issue Report'),
(206, 'english', 'Noticeboard', 'Noticeboard'),
(207, 'english', 'Events', 'Events'),
(208, 'english', 'School Settings', 'School Settings'),
(209, 'english', 'School information', 'School information'),
(210, 'english', 'Update settings', 'Update settings'),
(211, 'english', 'Deactive', 'Deactive'),
(212, 'english', 'Session has been activated', 'Session has been activated'),
(213, 'english', 'Update session', 'Update session'),
(214, 'english', 'Admins', 'Admins'),
(215, 'english', 'Create Admin', 'Create Admin'),
(216, 'english', 'User Info', 'User Info'),
(217, 'english', 'Oprions', 'Oprions'),
(218, 'english', 'Edit Admin', 'Edit Admin'),
(219, 'english', 'Teachers', 'Teachers'),
(220, 'english', 'Create Teacher', 'Create Teacher'),
(221, 'english', 'Create Accountant', 'Create Accountant'),
(222, 'english', 'Edit Accountant', 'Edit Accountant'),
(223, 'english', 'Librarians', 'Librarians'),
(224, 'english', 'Create Librarian', 'Create Librarian'),
(225, 'english', 'Edit Librarian', 'Edit Librarian'),
(226, 'english', 'Create Parent', 'Create Parent'),
(227, 'english', 'Edit Parent', 'Edit Parent'),
(228, 'english', 'Students', 'Students'),
(229, 'english', 'Create Student', 'Create Student'),
(230, 'english', 'Generate id card', 'Generate id card'),
(231, 'english', 'Assigned Permission For Teacher', 'Assigned Permission For Teacher'),
(232, 'english', 'Select a class', 'Select a class'),
(233, 'english', 'First select a class', 'First select a class'),
(234, 'english', 'Please select a class and section', 'Please select a class and section'),
(235, 'english', 'Attendance', 'Attendance'),
(236, 'english', 'Permission updated successfully.', 'Permission updated successfully.'),
(237, 'english', 'Admission', 'Admission'),
(238, 'english', 'Bulk student admission', 'Bulk student admission'),
(239, 'english', 'Class', 'Class'),
(240, 'english', 'Section', 'Section'),
(241, 'english', 'Select section', 'Select section'),
(242, 'english', 'Birthday', 'Birthday'),
(243, 'english', 'Select gender', 'Select gender'),
(244, 'english', 'Others', 'Others'),
(245, 'english', 'Student profile image', 'Student profile image'),
(246, 'english', 'Add Student', 'Add Student'),
(247, 'english', 'Create Exam Category', 'Create Exam Category'),
(248, 'english', 'Add Exam Category', 'Add Exam Category'),
(249, 'english', 'Title', 'Title'),
(250, 'english', 'Class test', 'Class test'),
(251, 'english', 'Edit Exam Category', 'Edit Exam Category'),
(252, 'english', 'Midterm exam', 'Midterm exam'),
(253, 'english', 'Final exam', 'Final exam'),
(254, 'english', 'Admission exam', 'Admission exam'),
(255, 'english', 'Create Exam', 'Create Exam'),
(256, 'english', 'Add Exam', 'Add Exam'),
(257, 'english', 'Exam', 'Exam'),
(258, 'english', 'Starting Time', 'Starting Time'),
(259, 'english', 'Ending Time', 'Ending Time'),
(260, 'english', 'Total Marks', 'Total Marks'),
(261, 'english', 'Edit Exam', 'Edit Exam'),
(262, 'english', 'Manage Marks', 'Manage Marks'),
(263, 'english', 'Select category', 'Select category'),
(264, 'english', 'Select class', 'Select class'),
(265, 'english', 'Please select all the fields', 'Please select all the fields'),
(266, 'english', 'Examknation', 'Examknation'),
(267, 'english', 'Create Grade', 'Create Grade'),
(268, 'english', 'Add grade', 'Add grade'),
(269, 'english', 'Grade', 'Grade'),
(270, 'english', 'Grade Point', 'Grade Point'),
(271, 'english', 'Mark From', 'Mark From'),
(272, 'english', 'Mark Upto', 'Mark Upto'),
(273, 'english', 'Promotions', 'Promotions'),
(274, 'english', 'Current session', 'Current session'),
(275, 'english', 'Session from', 'Session from'),
(276, 'english', 'Next session', 'Next session'),
(277, 'english', 'Session to', 'Session to'),
(278, 'english', 'Promoting from', 'Promoting from'),
(279, 'english', 'Promoting to', 'Promoting to'),
(280, 'english', 'Manage promotion', 'Manage promotion'),
(281, 'english', 'Take Attendance', 'Take Attendance'),
(282, 'english', 'Select a month', 'Select a month'),
(283, 'english', 'January', 'January'),
(284, 'english', 'February', 'February'),
(285, 'english', 'March', 'March'),
(286, 'english', 'April', 'April'),
(287, 'english', 'May', 'May'),
(288, 'english', 'June', 'June'),
(289, 'english', 'July', 'July'),
(290, 'english', 'August', 'August'),
(291, 'english', 'September', 'September'),
(292, 'english', 'October', 'October'),
(293, 'english', 'November', 'November'),
(294, 'english', 'December', 'December'),
(295, 'english', 'Select a year', 'Select a year'),
(296, 'english', 'Please select in all fields !', 'Please select in all fields !'),
(297, 'english', 'Classes', 'Classes'),
(298, 'english', 'Create Class', 'Create Class'),
(299, 'english', 'Add class', 'Add class'),
(300, 'english', 'Edit Section', 'Edit Section'),
(301, 'english', 'Edit Class', 'Edit Class'),
(302, 'english', 'Routines', 'Routines'),
(303, 'english', 'Add class routine', 'Add class routine'),
(304, 'english', 'Create Subject', 'Create Subject'),
(305, 'english', 'Add subject', 'Add subject'),
(306, 'english', 'Edit Subject', 'Edit Subject'),
(307, 'english', 'Select a exam category', 'Select a exam category'),
(308, 'english', 'Create syllabus', 'Create syllabus'),
(309, 'english', 'Add syllabus', 'Add syllabus'),
(310, 'english', 'Class Rooms', 'Class Rooms'),
(311, 'english', 'Create Class Room', 'Create Class Room'),
(312, 'english', 'Add class room', 'Add class room'),
(313, 'english', 'Edit Class Room', 'Edit Class Room'),
(314, 'english', 'Departments', 'Departments'),
(315, 'english', 'Create Department', 'Create Department'),
(316, 'english', 'Add department', 'Add department'),
(317, 'english', 'Edit Department', 'Edit Department'),
(318, 'english', 'Add Single Invoice', 'Add Single Invoice'),
(319, 'english', 'Add Mass Invoice', 'Add Mass Invoice'),
(320, 'english', 'All class', 'All class'),
(321, 'english', 'All status', 'All status'),
(322, 'english', 'Paid', 'Paid'),
(323, 'english', 'Unpaid', 'Unpaid'),
(324, 'english', 'Invoice No', 'Invoice No'),
(325, 'english', 'Invoice Title', 'Invoice Title'),
(326, 'english', 'Total Amount', 'Total Amount'),
(327, 'english', 'Created at', 'Created at'),
(328, 'english', 'Paid Amount', 'Paid Amount'),
(329, 'english', 'Expense', 'Expense'),
(330, 'english', 'Create Expense', 'Create Expense'),
(331, 'english', 'Add New Expense', 'Add New Expense'),
(332, 'english', 'Create Expense Category', 'Create Expense Category'),
(333, 'english', 'Add Expense Category', 'Add Expense Category'),
(334, 'english', 'Option', 'Option'),
(335, 'english', 'Edit Expense Category', 'Edit Expense Category'),
(336, 'english', 'Book', 'Book'),
(337, 'english', 'Add book', 'Add book'),
(338, 'english', 'Book name', 'Book name'),
(339, 'english', 'Author', 'Author'),
(340, 'english', 'Copies', 'Copies'),
(341, 'english', 'Available copies', 'Available copies'),
(342, 'english', 'Edit Book', 'Edit Book'),
(343, 'english', 'Book Issue', 'Book Issue'),
(344, 'english', 'Issue Book', 'Issue Book'),
(345, 'english', 'Noticeboard calendar', 'Noticeboard calendar'),
(346, 'english', 'Add New Notice', 'Add New Notice'),
(347, 'english', 'Locales:', 'Locales:'),
(348, 'english', 'Current Plan', 'Current Plan'),
(349, 'english', 'Silver', 'Silver'),
(350, 'english', 'Monthly', 'Monthly'),
(351, 'english', 'Subscription Renew Date', 'Subscription Renew Date'),
(352, 'english', 'Amount To Be Charged', 'Amount To Be Charged'),
(353, 'english', 'Create Event', 'Create Event'),
(354, 'english', 'Event title', 'Event title'),
(355, 'english', 'Date', 'Date'),
(356, 'english', 'Update event', 'Update event'),
(357, 'english', 'Upload addons zip file', 'Upload addons zip file'),
(358, 'english', 'Verified', 'Verified'),
(359, 'english', 'Details info', 'Details info'),
(360, 'english', 'Phone Number', 'Phone Number'),
(361, 'english', 'Designation', 'Designation'),
(362, 'english', 'Save Changes', 'Save Changes'),
(363, 'english', 'Select a status', 'Select a status'),
(364, 'english', 'Update school', 'Update school'),
(365, 'english', 'Package price', 'Package price'),
(366, 'english', 'Package Type', 'Package Type'),
(367, 'english', 'Select a package type', 'Select a package type'),
(368, 'english', 'Trail', 'Trail'),
(369, 'english', 'Select a interval', 'Select a interval'),
(370, 'english', 'Days', 'Days'),
(371, 'english', 'Yearly', 'Yearly'),
(372, 'english', 'Interval Preiod', 'Interval Preiod'),
(373, 'english', 'Description', 'Description'),
(374, 'english', 'Create package', 'Create package'),
(375, 'english', 'Update package', 'Update package'),
(376, 'english', 'Invalid purchase code', 'Invalid purchase code'),
(377, 'english', 'Inactive', 'Inactive'),
(378, 'english', 'Save event', 'Save event'),
(379, 'english', 'Create', 'Create'),
(380, 'english', 'Select a department', 'Select a department'),
(381, 'english', 'One', 'One'),
(382, 'english', 'Two', 'Two'),
(383, 'english', 'Three', 'Three'),
(384, 'english', 'Four', 'Four'),
(385, 'english', 'Five', 'Five'),
(386, 'english', 'Six', 'Six'),
(387, 'english', 'Seven', 'Seven'),
(388, 'english', 'Eight', 'Eight'),
(389, 'english', 'Nine', 'Nine'),
(390, 'english', 'Ten', 'Ten'),
(391, 'english', 'Add students', 'Add students'),
(392, 'english', 'Create category', 'Create category'),
(393, 'english', 'Exam Name', 'Exam Name'),
(394, 'english', 'Select exam category name', 'Select exam category name'),
(395, 'english', 'Subject', 'Subject'),
(396, 'english', 'Starting date', 'Starting date'),
(397, 'english', 'Ending date', 'Ending date'),
(398, 'english', 'Student name', 'Student name'),
(399, 'english', 'Mark', 'Mark'),
(400, 'english', 'Comment', 'Comment'),
(401, 'english', 'Value has been updated successfully', 'Value has been updated successfully'),
(402, 'english', 'Required mark field', 'Required mark field'),
(403, 'english', 'Image', 'Image'),
(404, 'english', 'Enroll to', 'Enroll to'),
(405, 'english', 'Select a section', 'Select a section'),
(406, 'english', 'Attendance Report Of', 'Attendance Report Of'),
(407, 'english', 'Last Update at', 'Last Update at'),
(408, 'english', 'Time', 'Time'),
(409, 'english', 'Please select the required fields', 'Please select the required fields'),
(410, 'english', 'Saturday', 'Saturday'),
(411, 'english', 'Sunday', 'Sunday'),
(412, 'english', 'Monday', 'Monday'),
(413, 'english', 'Tuesday', 'Tuesday'),
(414, 'english', 'Wednesday', 'Wednesday'),
(415, 'english', 'Update subject', 'Update subject'),
(416, 'english', 'Select subject', 'Select subject'),
(417, 'english', 'Assign a teacher', 'Assign a teacher'),
(418, 'english', 'Select a class room', 'Select a class room'),
(419, 'english', 'Day', 'Day'),
(420, 'english', 'Select a day', 'Select a day'),
(421, 'english', 'Thursday', 'Thursday'),
(422, 'english', 'Friday', 'Friday'),
(423, 'english', 'Starting hour', 'Starting hour'),
(424, 'english', 'Starting minute', 'Starting minute'),
(425, 'english', 'Ending hour', 'Ending hour'),
(426, 'english', 'Ending minute', 'Ending minute'),
(427, 'english', 'Add routine', 'Add routine'),
(428, 'english', 'Edit class routine', 'Edit class routine'),
(429, 'english', 'Tittle', 'Tittle'),
(430, 'english', 'Upload syllabus', 'Upload syllabus'),
(431, 'english', 'Select student', 'Select student'),
(432, 'english', 'Select a student', 'Select a student'),
(433, 'english', 'Payment method', 'Payment method'),
(434, 'english', 'Select a payment method', 'Select a payment method'),
(435, 'english', 'Cash', 'Cash'),
(436, 'english', 'Paypal', 'Paypal'),
(437, 'english', 'Paytm', 'Paytm'),
(438, 'english', 'Razorpay', 'Razorpay'),
(439, 'english', 'Create invoice', 'Create invoice'),
(440, 'english', 'Payment date', 'Payment date'),
(441, 'english', 'Print invoice', 'Print invoice'),
(442, 'english', 'Edit Invoice', 'Edit Invoice'),
(443, 'english', 'Amount', 'Amount'),
(444, 'english', 'Select an expense category', 'Select an expense category'),
(445, 'english', 'Edit Expense', 'Edit Expense'),
(446, 'english', 'Issue date', 'Issue date'),
(447, 'english', 'Select book', 'Select book'),
(448, 'english', 'Id', 'Id'),
(449, 'english', 'Pending', 'Pending'),
(450, 'english', 'Update issued book', 'Update issued book'),
(451, 'english', 'Return this book', 'Return this book'),
(452, 'english', 'Notice title', 'Notice title'),
(453, 'english', 'Start date', 'Start date'),
(454, 'english', 'Setup additional date & time', 'Setup additional date & time'),
(455, 'english', 'Start time', 'Start time'),
(456, 'english', 'End date', 'End date'),
(457, 'english', 'End time', 'End time'),
(458, 'english', 'Notice', 'Notice'),
(459, 'english', 'Show on website', 'Show on website'),
(460, 'english', 'Show', 'Show'),
(461, 'english', 'Do not need to show', 'Do not need to show'),
(462, 'english', 'Upload notice photo', 'Upload notice photo'),
(463, 'english', 'Save notice', 'Save notice'),
(464, 'english', 'School Currency', 'School Currency'),
(465, 'english', 'Exam List', 'Exam List'),
(466, 'english', 'Profile', 'Profile'),
(467, 'english', ' Download', ' Download'),
(468, 'english', 'Select a subject', 'Select a subject'),
(469, 'english', 'Welcome, to', 'Welcome, to'),
(470, 'english', 'Fee Manager', 'Fee Manager'),
(471, 'english', 'List Of Books', 'List Of Books'),
(472, 'english', 'Issued Book', 'Issued Book'),
(473, 'english', 'Student Code', 'Student Code'),
(474, 'english', 'Candice Kennedy', 'Candice Kennedy'),
(475, 'english', 'English', 'English'),
(476, 'english', 'Natalie Ashley', 'Natalie Ashley'),
(477, 'english', 'Byron Chase', 'Byron Chase'),
(478, 'english', 'Rafael Hardy', 'Rafael Hardy'),
(479, 'english', 'Mathematics', 'Mathematics'),
(480, 'english', 'Aphrodite Shaffer', 'Aphrodite Shaffer'),
(481, 'english', 'Bangla', 'Bangla'),
(482, 'english', 'Fatima Phillips', 'Fatima Phillips'),
(483, 'english', 'Sydney Pearson', 'Sydney Pearson'),
(484, 'english', 'Drawing', 'Drawing'),
(485, 'english', 'Imani Cooper', 'Imani Cooper'),
(486, 'english', 'Ulric Spencer', 'Ulric Spencer'),
(487, 'english', 'Yoshio Gentry', 'Yoshio Gentry'),
(488, 'english', 'Attendance report', 'Attendance report'),
(489, 'english', 'Of', 'Of'),
(490, 'english', 'Last updated at', 'Last updated at'),
(491, 'english', 'View Marks', 'View Marks'),
(492, 'english', 'Subject name', 'Subject name'),
(493, 'english', 'Pay', 'Pay'),
(494, 'english', 'List Of Book', 'List Of Book'),
(495, 'english', 'Child', 'Child'),
(496, 'english', 'Teaches', 'Teaches'),
(498, 'english', 'Student List', 'Student List'),
(499, 'english', 'Id card', 'Id card'),
(500, 'english', 'Code', 'Code'),
(501, 'english', 'Not found', 'Not found'),
(4047, 'english', 'Subcription', 'Subcription'),
(4048, 'english', 'Expired Subscription', 'Expired Subscription'),
(4049, 'english', ' Pending Requests', ' Pending Requests'),
(4050, 'english', 'Website Settings', 'Website Settings'),
(4051, 'english', 'Manage Faq', 'Manage Faq'),
(4052, 'english', 'Language Settings', 'Language Settings'),
(4053, 'english', 'Visit Website', 'Visit Website'),
(4054, 'english', 'Heads up', 'Heads up'),
(4055, 'english', 'Are you sure', 'Are you sure'),
(4056, 'english', 'School Logo', 'School Logo'),
(4057, 'english', 'Admin List', 'Admin List'),
(4058, 'english', 'Archive ', 'Archive '),
(4059, 'english', 'Trial', 'Trial'),
(4060, 'english', 'Life Time', 'Life Time'),
(4061, 'english', 'Students Limit', 'Students Limit'),
(4062, 'english', 'Features', 'Features'),
(4063, 'english', 'Write Features', 'Write Features'),
(4064, 'english', 'Write service', 'Write service'),
(4065, 'english', 'Student Limit', 'Student Limit'),
(4066, 'english', 'Package List', 'Package List'),
(4067, 'english', 'Feature', 'Feature'),
(4068, 'english', 'Faq', 'Faq'),
(4069, 'english', 'Contact', 'Contact'),
(4070, 'english', 'Register', 'Register'),
(4071, 'english', 'School Register Form', 'School Register Form'),
(4072, 'english', 'Admin Name', 'Admin Name'),
(4073, 'english', 'User Account', 'User Account'),
(4074, 'english', 'Our Features', 'Our Features'),
(4075, 'english', 'Subscribe', 'Subscribe'),
(4076, 'english', 'Have Any Question', 'Have Any Question'),
(4077, 'english', 'Contact us with any questions', 'Contact us with any questions'),
(4078, 'english', 'Contact Us', 'Contact Us'),
(4079, 'english', 'Social Link', 'Social Link'),
(4080, 'english', 'Feedback', 'Feedback'),
(4081, 'english', 'Not Subscribed', 'Not Subscribed'),
(4082, 'english', 'You are not subscribed to any plan. Subscribe now.', 'You are not subscribed to any plan. Subscribe now.'),
(4083, 'english', 'Deliverable', 'Deliverable'),
(4084, 'english', 'Rate', 'Rate'),
(4085, 'english', 'Total', 'Total'),
(4086, 'english', 'All Rights Reserved', 'All Rights Reserved'),
(4087, 'english', 'Subscription Purchase Date', 'Subscription Purchase Date'),
(4088, 'english', 'Upgrade Subscribe ', 'Upgrade Subscribe '),
(4089, 'english', 'Department List', 'Department List'),
(4090, 'english', 'Filter Options', 'Filter Options'),
(4091, 'english', 'Apply', 'Apply'),
(4092, 'english', 'Excel upload', 'Excel upload'),
(4093, 'english', 'Add Parent', 'Add Parent'),
(4094, 'english', 'Parent Create', 'Parent Create'),
(4095, 'english', '', ''),
(4096, 'english', 'Account Status', 'Account Status'),
(4097, 'english', 'Enable', 'Enable'),
(4098, 'english', 'librarian Profile', 'librarian Profile'),
(4099, 'english', 'Documents', 'Documents'),
(4100, 'english', 'Disable', 'Disable'),
(4101, 'english', 'Librarian List', 'Librarian List'),
(4102, 'english', 'Teacher Profile', 'Teacher Profile'),
(4103, 'english', 'Teachers List', 'Teachers List'),
(4104, 'english', 'Email receipt title', 'Email receipt title'),
(4105, 'english', 'Email Details', 'Email Details'),
(4106, 'english', 'Remaining characters is', 'Remaining characters is'),
(4107, 'english', 'Warning Text', 'Warning Text'),
(4108, 'english', 'Social Link 1', 'Social Link 1'),
(4109, 'english', 'Social Link 2', 'Social Link 2'),
(4110, 'english', 'Social Link 3', 'Social Link 3'),
(4111, 'english', 'Social Logo-1', 'Social Logo-1'),
(4112, 'english', 'Social Logo-2', 'Social Logo-2'),
(4113, 'english', 'Social Logo-3', 'Social Logo-3'),
(4114, 'english', 'Email template Logo', 'Email template Logo'),
(4115, 'english', 'instruction', 'instruction'),
(4116, 'english', 'Images for email templates will only support if the application is hosted on a live server. Localhost will not support this.', 'Images for email templates will only support if the application is hosted on a live server. Localhost will not support this.'),
(4117, 'english', 'Student Profile', 'Student Profile'),
(4118, 'english', 'Showing', 'Showing'),
(4119, 'english', 'from', 'from'),
(4120, 'english', 'data', 'data'),
(4121, 'english', ' Students List', ' Students List'),
(4122, 'english', 'Blood', 'Blood'),
(4123, 'english', 'All Message', 'All Message'),
(4124, 'english', 'Message', 'Message'),
(4125, 'english', 'Read & Reply', 'Read & Reply'),
(4126, 'english', 'View & Reply', 'View & Reply'),
(4127, 'english', 'Show student list', 'Show student list'),
(4128, 'english', 'Update attendance', 'Update attendance'),
(4129, 'english', 'Present All', 'Present All'),
(4130, 'english', 'Absent All', 'Absent All'),
(4131, 'english', 'present', 'present'),
(4132, 'english', 'absent', 'absent'),
(4133, 'english', 'Number of child', 'Number of child'),
(4134, 'english', 'Parent Profile', 'Parent Profile'),
(4135, 'english', 'Parent List', 'Parent List'),
(4136, 'english', 'Payment', 'Payment'),
(4137, 'english', 'Make Payment', 'Make Payment'),
(4138, 'english', 'Payment Gateway', 'Payment Gateway'),
(4139, 'english', 'Offline', 'Offline'),
(4140, 'english', 'Addon', 'Addon'),
(4141, 'english', 'Invoice Summary', 'Invoice Summary'),
(4142, 'english', 'Grand Total', 'Grand Total'),
(4143, 'english', 'Document of your payment', 'Document of your payment'),
(4144, 'english', 'Submit payment document', 'Submit payment document'),
(4145, 'english', 'Admin will review your payment document and then approve the Payment.', 'Admin will review your payment document and then approve the Payment.'),
(4146, 'english', 'Pending Payment', 'Pending Payment'),
(4147, 'english', 'You payment request has been sent to Superadmin ', 'You payment request has been sent to Superadmin '),
(4148, 'english', 'Pending Requests', 'Pending Requests'),
(4149, 'english', 'To', 'To'),
(4150, 'english', 'Suspended', 'Suspended'),
(4151, 'english', 'Create Feedback', 'Create Feedback'),
(4152, 'english', 'Add New Feedback', 'Add New Feedback'),
(4153, 'english', 'Admin Profile', 'Admin Profile'),
(4154, 'english', 'Admin Permission', 'Admin Permission'),
(4155, 'english', 'Administrator', 'Administrator'),
(4156, 'english', ' Important Notice: Your Account Has Been Disabled', ' Important Notice: Your Account Has Been Disabled'),
(4157, 'english', 'We regret to inform you that your account with', 'We regret to inform you that your account with'),
(4158, 'english', ' has been disabled effective immediately', ' has been disabled effective immediately'),
(4159, 'english', 'What this means for you', 'What this means for you'),
(4160, 'english', 'You will no longer be able to access your account.', 'You will no longer be able to access your account.'),
(4161, 'english', 'All services and features associated with your account are now inaccessible.', 'All services and features associated with your account are now inaccessible.'),
(4162, 'english', 'Any active subscriptions or services have been terminated.', 'Any active subscriptions or services have been terminated.'),
(4163, 'english', 'Add Users', 'Add Users'),
(4164, 'english', 'Accountant Profile', 'Accountant Profile'),
(4165, 'english', 'Accountant List', 'Accountant List'),
(4166, 'english', 'Class & Section', 'Class & Section'),
(4167, 'english', 'Stripe', 'Stripe'),
(4168, 'english', 'Flutterwave', 'Flutterwave'),
(4169, 'english', 'Select expense category', 'Select expense category'),
(4170, 'english', 'Discount amount', 'Discount amount'),
(4171, 'english', 'this_course_has', 'this_course_has'),
(4172, 'english', 'discount', 'discount'),
(4173, 'english', 'This student has', 'This student has'),
(4174, 'english', 'Invoice', 'Invoice'),
(4175, 'english', 'Please find below the invoice', 'Please find below the invoice'),
(4176, 'english', 'Billing Address', 'Billing Address'),
(4177, 'english', 'Due Amount', 'Due Amount'),
(4178, 'english', 'Subtotal', 'Subtotal'),
(4179, 'english', 'Due', 'Due'),
(4180, 'english', 'Discount:', 'Discount:'),
(4181, 'english', 'Update invoice', 'Update invoice'),
(4182, 'english', 'Payment | Ekator 8', 'Payment | Ekator 8'),
(4183, 'english', 'Document', 'Document'),
(4184, 'english', 'Decline', 'Decline'),
(4185, 'english', 'Make Admit Card', 'Make Admit Card'),
(4186, 'english', 'Teacher list', 'Teacher list'),
(4187, 'english', 'Students list', 'Students list'),
(4188, 'english', 'Teacher Permission list', 'Teacher Permission list'),
(4189, 'english', 'Addmission', 'Addmission'),
(4190, 'english', 'Subject List', 'Subject List'),
(4191, 'english', 'Students Fee Manager', 'Students Fee Manager'),
(4192, 'english', 'Navigation Menu Settings', 'Navigation Menu Settings'),
(4193, 'english', 'Language list ', 'Language list '),
(4194, 'english', 'Add language', 'Add language'),
(4195, 'english', 'Language', 'Language'),
(4196, 'english', 'Edit phrase', 'Edit phrase'),
(4197, 'english', 'Delete language', 'Delete language'),
(4198, 'english', 'Add new language', 'Add new language'),
(4199, 'english', 'No special character or space is allowed', 'No special character or space is allowed'),
(4200, 'english', 'Valid examples', 'Valid examples'),
(4201, 'english', 'System default language can not be removed', 'System default language can not be removed'),
(4202, 'english', 'Phrase updated', 'Phrase updated'),
(4203, 'Bangla', 'Dashboard', 'ড্যাশবোর্ড'),
(4204, 'Bangla', 'Home', 'Home'),
(4205, 'Bangla', 'Schools', 'Schools'),
(4206, 'Bangla', 'Total Schools', 'Total Schools'),
(4207, 'Bangla', 'Subscription', 'Subscription'),
(4208, 'Bangla', 'Total Active Subscription', 'Total Active Subscription'),
(4209, 'Bangla', 'Subscription Payment', 'Subscription Payment'),
(4210, 'Bangla', 'Superadmin | Ekator 8', 'Superadmin | Ekator 8'),
(4211, 'Bangla', 'Close', 'Close'),
(4212, 'Bangla', 'School List', 'School List'),
(4213, 'Bangla', 'Create school', 'Create school'),
(4214, 'Bangla', 'Pending List', 'Pending List'),
(4215, 'Bangla', 'Package', 'Package'),
(4216, 'Bangla', 'Subscriptions', 'Subscriptions'),
(4217, 'Bangla', 'Subscription Report', 'Subscription Report'),
(4218, 'Bangla', 'Pending Request', 'Pending Request'),
(4219, 'Bangla', 'Confirmed Payment', 'Confirmed Payment'),
(4220, 'Bangla', 'Addons', 'Addons'),
(4221, 'Bangla', 'Settings', 'Settings'),
(4222, 'Bangla', 'System Settings', 'System Settings'),
(4223, 'Bangla', 'Session Manager', 'Session Manager'),
(4224, 'Bangla', 'Payment Settings', 'Payment Settings'),
(4225, 'Bangla', 'Smtp settings', 'Smtp settings'),
(4226, 'Bangla', 'About', 'About'),
(4227, 'Bangla', 'Superadmin', 'Superadmin'),
(4228, 'Bangla', 'My Account', 'My Account'),
(4229, 'Bangla', 'Change Password', 'Change Password'),
(4230, 'Bangla', 'Log out', 'Log out'),
(4231, 'Bangla', 'Loading...', 'Loading...'),
(4232, 'Bangla', 'Heads up!', 'Heads up!'),
(4233, 'Bangla', 'Are you sure?', 'Are you sure?'),
(4234, 'Bangla', 'Back', 'Back'),
(4235, 'Bangla', 'Continue', 'Continue'),
(4236, 'Bangla', 'You won\'t able to revert this!', 'You won\'t able to revert this!'),
(4237, 'Bangla', 'Yes', 'Yes'),
(4238, 'Bangla', 'Cancel', 'Cancel'),
(4239, 'Bangla', 'Add School', 'Add School'),
(4240, 'Bangla', 'Name', 'Name'),
(4241, 'Bangla', 'Address', 'Address'),
(4242, 'Bangla', 'Phone', 'Phone'),
(4243, 'Bangla', 'Info', 'Info'),
(4244, 'Bangla', 'Status', 'Status'),
(4245, 'Bangla', 'Action', 'Action'),
(4246, 'Bangla', 'Active', 'Active'),
(4247, 'Bangla', 'Actions', 'Actions'),
(4248, 'Bangla', 'Edit School', 'Edit School'),
(4249, 'Bangla', 'Edit', 'Edit'),
(4250, 'Bangla', 'Delete', 'Delete'),
(4251, 'Bangla', 'School Form', 'School Form'),
(4252, 'Bangla', 'Provide all the information required for your school.', 'Provide all the information required for your school.'),
(4253, 'Bangla', 'Also provide a admin information with email and passwoard.', 'Also provide a admin information with email and passwoard.'),
(4254, 'Bangla', 'So that admin can access the created school.', 'So that admin can access the created school.'),
(4255, 'Bangla', 'SCHOOL INFO', 'SCHOOL INFO'),
(4256, 'Bangla', 'School Name', 'School Name'),
(4257, 'Bangla', 'School Address', 'School Address'),
(4258, 'Bangla', 'School Email', 'School Email'),
(4259, 'Bangla', 'School Phone', 'School Phone'),
(4260, 'Bangla', 'ADMIN INFO', 'ADMIN INFO'),
(4261, 'Bangla', 'Gender', 'Gender'),
(4262, 'Bangla', 'Select a gender', 'Select a gender'),
(4263, 'Bangla', 'Male', 'Male'),
(4264, 'Bangla', 'Female', 'Female'),
(4265, 'Bangla', 'Blood group', 'Blood group'),
(4266, 'Bangla', 'Select a blood group', 'Select a blood group'),
(4267, 'Bangla', 'A+', 'A+'),
(4268, 'Bangla', 'A-', 'A-'),
(4269, 'Bangla', 'B+', 'B+'),
(4270, 'Bangla', 'B-', 'B-'),
(4271, 'Bangla', 'AB+', 'AB+'),
(4272, 'Bangla', 'AB-', 'AB-'),
(4273, 'Bangla', 'O+', 'O+'),
(4274, 'Bangla', 'O-', 'O-'),
(4275, 'Bangla', 'Admin Address', 'Admin Address'),
(4276, 'Bangla', 'Admin Phone Number', 'Admin Phone Number'),
(4277, 'Bangla', 'Photo', 'Photo'),
(4278, 'Bangla', 'Admin Email', 'Admin Email'),
(4279, 'Bangla', 'Admin Password', 'Admin Password'),
(4280, 'Bangla', 'Submit', 'Submit'),
(4281, 'Bangla', 'Pending School List', 'Pending School List'),
(4282, 'Bangla', 'No data found', 'No data found'),
(4283, 'Bangla', 'Packages', 'Packages'),
(4284, 'Bangla', 'Add Package', 'Add Package'),
(4285, 'Bangla', 'Price', 'Price'),
(4286, 'Bangla', 'Interval', 'Interval'),
(4287, 'Bangla', 'Preiod', 'Preiod'),
(4288, 'Bangla', 'Filter', 'Filter'),
(4289, 'Bangla', 'Export', 'Export'),
(4290, 'Bangla', 'PDF', 'PDF'),
(4291, 'Bangla', 'CSV', 'CSV'),
(4292, 'Bangla', 'Print', 'Print'),
(4293, 'Bangla', 'Paid By', 'Paid By'),
(4294, 'Bangla', 'Purchase Date', 'Purchase Date'),
(4295, 'Bangla', 'Expire Date', 'Expire Date'),
(4296, 'Bangla', 'Confirmed Request', 'Confirmed Request'),
(4297, 'Bangla', 'Payment For', 'Payment For'),
(4298, 'Bangla', 'Payment Document', 'Payment Document'),
(4299, 'Bangla', 'Approve', 'Approve'),
(4300, 'Bangla', 'Manage Addons', 'Manage Addons'),
(4301, 'Bangla', 'Install addon', 'Install addon'),
(4302, 'Bangla', 'Add new addon', 'Add new addon'),
(4303, 'Bangla', 'System Name', 'System Name'),
(4304, 'Bangla', 'System Title', 'System Title'),
(4305, 'Bangla', 'System Email', 'System Email'),
(4306, 'Bangla', 'Fax', 'Fax'),
(4307, 'Bangla', 'Timezone', 'Timezone'),
(4308, 'Bangla', 'Footer Text', 'Footer Text'),
(4309, 'Bangla', 'Footer Link', 'Footer Link'),
(4310, 'Bangla', 'PRODUCT UPDATE', 'PRODUCT UPDATE'),
(4311, 'Bangla', 'File', 'File'),
(4312, 'Bangla', 'Update', 'Update'),
(4313, 'Bangla', 'SYSTEM LOGO', 'SYSTEM LOGO'),
(4314, 'Bangla', 'Dark logo', 'Dark logo'),
(4315, 'Bangla', 'Light logo', 'Light logo'),
(4316, 'Bangla', 'Favicon', 'Favicon'),
(4317, 'Bangla', 'Update Logo', 'Update Logo'),
(4318, 'Bangla', 'Create Session', 'Create Session'),
(4319, 'Bangla', 'Add Session', 'Add Session'),
(4320, 'Bangla', 'Active session ', 'Active session '),
(4321, 'Bangla', 'Select a session', 'Select a session'),
(4322, 'Bangla', 'Activate', 'Activate'),
(4323, 'Bangla', 'Session title', 'Session title'),
(4324, 'Bangla', 'Options', 'Options'),
(4325, 'Bangla', 'Edit Session', 'Edit Session'),
(4326, 'Bangla', 'Global Currency', 'Global Currency'),
(4327, 'Bangla', 'Select system currency', 'Select system currency'),
(4328, 'Bangla', 'Currency Position', 'Currency Position'),
(4329, 'Bangla', 'Left', 'Left'),
(4330, 'Bangla', 'Right', 'Right'),
(4331, 'Bangla', 'Left with a space', 'Left with a space'),
(4332, 'Bangla', 'Right with a space', 'Right with a space'),
(4333, 'Bangla', 'Update Currency', 'Update Currency'),
(4334, 'Bangla', 'Protocol', 'Protocol'),
(4335, 'Bangla', 'Smtp crypto', 'Smtp crypto'),
(4336, 'Bangla', 'Smtp host', 'Smtp host'),
(4337, 'Bangla', 'Smtp port', 'Smtp port'),
(4338, 'Bangla', 'Smtp username', 'Smtp username'),
(4339, 'Bangla', 'Smtp password', 'Smtp password'),
(4340, 'Bangla', 'Save', 'Save'),
(4341, 'Bangla', 'Not found', 'Not found'),
(4342, 'Bangla', 'About this application', 'About this application'),
(4343, 'Bangla', 'Software version', 'Software version'),
(4344, 'Bangla', 'Check update', 'Check update'),
(4345, 'Bangla', 'PHP version', 'PHP version'),
(4346, 'Bangla', 'Curl enable', 'Curl enable'),
(4347, 'Bangla', 'Enabled', 'Enabled'),
(4348, 'Bangla', 'Purchase code', 'Purchase code'),
(4349, 'Bangla', 'Product license', 'Product license'),
(4350, 'Bangla', 'invalid', 'invalid'),
(4351, 'Bangla', 'Enter valid purchase code', 'Enter valid purchase code'),
(4352, 'Bangla', 'Customer support status', 'Customer support status'),
(4353, 'Bangla', 'Support expiry date', 'Support expiry date'),
(4354, 'Bangla', 'Customer name', 'Customer name'),
(4355, 'Bangla', 'Get customer support', 'Get customer support'),
(4356, 'Bangla', 'Customer support', 'Customer support'),
(4357, 'Bangla', 'Email', 'Email'),
(4358, 'Bangla', 'Password', 'Password'),
(4359, 'Bangla', 'Forgot password', 'Forgot password'),
(4360, 'Bangla', 'Help', 'Help'),
(4361, 'Bangla', 'Login', 'Login'),
(4362, 'Bangla', 'Total Student', 'Total Student'),
(4363, 'Bangla', 'Teacher', 'Teacher'),
(4364, 'Bangla', 'Total Teacher', 'Total Teacher'),
(4365, 'Bangla', 'Parents', 'Parents'),
(4366, 'Bangla', 'Total Parent', 'Total Parent'),
(4367, 'Bangla', 'Staff', 'Staff'),
(4368, 'Bangla', 'Total Staff', 'Total Staff'),
(4369, 'Bangla', 'Todays Attendance', 'Todays Attendance'),
(4370, 'Bangla', 'Go to Attendance', 'Go to Attendance'),
(4371, 'Bangla', 'Income Report', 'Income Report'),
(4372, 'Bangla', 'Year', 'Year'),
(4373, 'Bangla', 'Month', 'Month'),
(4374, 'Bangla', 'Week', 'Week'),
(4375, 'Bangla', 'Upcoming Events', 'Upcoming Events'),
(4376, 'Bangla', 'See all', 'See all'),
(4377, 'Bangla', 'Admin', 'Admin'),
(4378, 'Bangla', 'Users', 'Users'),
(4379, 'Bangla', 'Accountant', 'Accountant'),
(4380, 'Bangla', 'Librarian', 'Librarian'),
(4381, 'Bangla', 'Parent', 'Parent'),
(4382, 'Bangla', 'Student', 'Student'),
(4383, 'Bangla', 'Teacher Permission', 'Teacher Permission'),
(4384, 'Bangla', 'Admissions', 'Admissions'),
(4385, 'Bangla', 'Examination', 'Examination'),
(4386, 'Bangla', 'Exam Category', 'Exam Category'),
(4387, 'Bangla', 'Offline Exam', 'Offline Exam'),
(4388, 'Bangla', 'Marks', 'Marks'),
(4389, 'Bangla', 'Grades', 'Grades'),
(4390, 'Bangla', 'Promotion', 'Promotion'),
(4391, 'Bangla', 'Academic', 'Academic'),
(4392, 'Bangla', 'Daily Attendance', 'Daily Attendance'),
(4393, 'Bangla', 'Class List', 'Class List'),
(4394, 'Bangla', 'Class Routine', 'Class Routine'),
(4395, 'Bangla', 'Subjects', 'Subjects'),
(4396, 'Bangla', 'Gradebooks', 'Gradebooks'),
(4397, 'Bangla', 'Syllabus', 'Syllabus'),
(4398, 'Bangla', 'Class Room', 'Class Room'),
(4399, 'Bangla', 'Department', 'Department'),
(4400, 'Bangla', 'Accounting', 'Accounting'),
(4401, 'Bangla', 'Student Fee Manager', 'Student Fee Manager'),
(4402, 'Bangla', 'Offline Payment Request', 'Offline Payment Request'),
(4403, 'Bangla', 'Expense Manager', 'Expense Manager'),
(4404, 'Bangla', 'Expense Category', 'Expense Category'),
(4405, 'Bangla', 'Back Office', 'Back Office'),
(4406, 'Bangla', 'Book List Manager', 'Book List Manager'),
(4407, 'Bangla', 'Book Issue Report', 'Book Issue Report'),
(4408, 'Bangla', 'Noticeboard', 'Noticeboard'),
(4409, 'Bangla', 'Events', 'Events'),
(4410, 'Bangla', 'School Settings', 'School Settings'),
(4411, 'Bangla', 'School information', 'School information'),
(4412, 'Bangla', 'Update settings', 'Update settings'),
(4413, 'Bangla', 'Deactive', 'Deactive'),
(4414, 'Bangla', 'Session has been activated', 'Session has been activated'),
(4415, 'Bangla', 'Update session', 'Update session'),
(4416, 'Bangla', 'Admins', 'Admins'),
(4417, 'Bangla', 'Create Admin', 'Create Admin'),
(4418, 'Bangla', 'User Info', 'User Info'),
(4419, 'Bangla', 'Oprions', 'Oprions'),
(4420, 'Bangla', 'Edit Admin', 'Edit Admin'),
(4421, 'Bangla', 'Teachers', 'Teachers'),
(4422, 'Bangla', 'Create Teacher', 'Create Teacher'),
(4423, 'Bangla', 'Create Accountant', 'Create Accountant'),
(4424, 'Bangla', 'Edit Accountant', 'Edit Accountant'),
(4425, 'Bangla', 'Librarians', 'Librarians'),
(4426, 'Bangla', 'Create Librarian', 'Create Librarian'),
(4427, 'Bangla', 'Edit Librarian', 'Edit Librarian'),
(4428, 'Bangla', 'Create Parent', 'Create Parent'),
(4429, 'Bangla', 'Edit Parent', 'Edit Parent'),
(4430, 'Bangla', 'Students', 'Students'),
(4431, 'Bangla', 'Create Student', 'Create Student'),
(4432, 'Bangla', 'Generate id card', 'Generate id card'),
(4433, 'Bangla', 'Assigned Permission For Teacher', 'Assigned Permission For Teacher'),
(4434, 'Bangla', 'Select a class', 'Select a class'),
(4435, 'Bangla', 'First select a class', 'First select a class'),
(4436, 'Bangla', 'Please select a class and section', 'Please select a class and section'),
(4437, 'Bangla', 'Attendance', 'Attendance'),
(4438, 'Bangla', 'Permission updated successfully.', 'Permission updated successfully.'),
(4439, 'Bangla', 'Admission', 'Admission'),
(4440, 'Bangla', 'Bulk student admission', 'Bulk student admission'),
(4441, 'Bangla', 'Class', 'Class'),
(4442, 'Bangla', 'Section', 'Section'),
(4443, 'Bangla', 'Select section', 'Select section'),
(4444, 'Bangla', 'Birthday', 'Birthday'),
(4445, 'Bangla', 'Select gender', 'Select gender'),
(4446, 'Bangla', 'Others', 'Others'),
(4447, 'Bangla', 'Student profile image', 'Student profile image'),
(4448, 'Bangla', 'Add Student', 'Add Student'),
(4449, 'Bangla', 'Create Exam Category', 'Create Exam Category'),
(4450, 'Bangla', 'Add Exam Category', 'Add Exam Category'),
(4451, 'Bangla', 'Title', 'Title'),
(4452, 'Bangla', 'Class test', 'Class test'),
(4453, 'Bangla', 'Edit Exam Category', 'Edit Exam Category'),
(4454, 'Bangla', 'Midterm exam', 'Midterm exam'),
(4455, 'Bangla', 'Final exam', 'Final exam'),
(4456, 'Bangla', 'Admission exam', 'Admission exam'),
(4457, 'Bangla', 'Create Exam', 'Create Exam'),
(4458, 'Bangla', 'Add Exam', 'Add Exam'),
(4459, 'Bangla', 'Exam', 'Exam'),
(4460, 'Bangla', 'Starting Time', 'Starting Time'),
(4461, 'Bangla', 'Ending Time', 'Ending Time'),
(4462, 'Bangla', 'Total Marks', 'Total Marks'),
(4463, 'Bangla', 'Edit Exam', 'Edit Exam'),
(4464, 'Bangla', 'Manage Marks', 'Manage Marks'),
(4465, 'Bangla', 'Select category', 'Select category'),
(4466, 'Bangla', 'Select class', 'Select class'),
(4467, 'Bangla', 'Please select all the fields', 'Please select all the fields'),
(4468, 'Bangla', 'Examknation', 'Examknation'),
(4469, 'Bangla', 'Create Grade', 'Create Grade'),
(4470, 'Bangla', 'Add grade', 'Add grade'),
(4471, 'Bangla', 'Grade', 'Grade'),
(4472, 'Bangla', 'Grade Point', 'Grade Point'),
(4473, 'Bangla', 'Mark From', 'Mark From'),
(4474, 'Bangla', 'Mark Upto', 'Mark Upto'),
(4475, 'Bangla', 'Promotions', 'Promotions'),
(4476, 'Bangla', 'Current session', 'Current session'),
(4477, 'Bangla', 'Session from', 'Session from'),
(4478, 'Bangla', 'Next session', 'Next session'),
(4479, 'Bangla', 'Session to', 'Session to'),
(4480, 'Bangla', 'Promoting from', 'Promoting from'),
(4481, 'Bangla', 'Promoting to', 'Promoting to'),
(4482, 'Bangla', 'Manage promotion', 'Manage promotion'),
(4483, 'Bangla', 'Take Attendance', 'Take Attendance'),
(4484, 'Bangla', 'Select a month', 'Select a month'),
(4485, 'Bangla', 'January', 'January'),
(4486, 'Bangla', 'February', 'February'),
(4487, 'Bangla', 'March', 'March'),
(4488, 'Bangla', 'April', 'April'),
(4489, 'Bangla', 'May', 'May'),
(4490, 'Bangla', 'June', 'June'),
(4491, 'Bangla', 'July', 'July'),
(4492, 'Bangla', 'August', 'August'),
(4493, 'Bangla', 'September', 'September'),
(4494, 'Bangla', 'October', 'October'),
(4495, 'Bangla', 'November', 'November'),
(4496, 'Bangla', 'December', 'December'),
(4497, 'Bangla', 'Select a year', 'Select a year'),
(4498, 'Bangla', 'Please select in all fields !', 'Please select in all fields !'),
(4499, 'Bangla', 'Classes', 'Classes'),
(4500, 'Bangla', 'Create Class', 'Create Class'),
(4501, 'Bangla', 'Add class', 'Add class'),
(4502, 'Bangla', 'Edit Section', 'Edit Section'),
(4503, 'Bangla', 'Edit Class', 'Edit Class'),
(4504, 'Bangla', 'Routines', 'Routines'),
(4505, 'Bangla', 'Add class routine', 'Add class routine'),
(4506, 'Bangla', 'Create Subject', 'Create Subject'),
(4507, 'Bangla', 'Add subject', 'Add subject'),
(4508, 'Bangla', 'Edit Subject', 'Edit Subject'),
(4509, 'Bangla', 'Select a exam category', 'Select a exam category'),
(4510, 'Bangla', 'Create syllabus', 'Create syllabus'),
(4511, 'Bangla', 'Add syllabus', 'Add syllabus'),
(4512, 'Bangla', 'Class Rooms', 'Class Rooms'),
(4513, 'Bangla', 'Create Class Room', 'Create Class Room'),
(4514, 'Bangla', 'Add class room', 'Add class room'),
(4515, 'Bangla', 'Edit Class Room', 'Edit Class Room'),
(4516, 'Bangla', 'Departments', 'Departments'),
(4517, 'Bangla', 'Create Department', 'Create Department'),
(4518, 'Bangla', 'Add department', 'Add department'),
(4519, 'Bangla', 'Edit Department', 'Edit Department'),
(4520, 'Bangla', 'Add Single Invoice', 'Add Single Invoice'),
(4521, 'Bangla', 'Add Mass Invoice', 'Add Mass Invoice'),
(4522, 'Bangla', 'All class', 'All class'),
(4523, 'Bangla', 'All status', 'All status'),
(4524, 'Bangla', 'Paid', 'Paid'),
(4525, 'Bangla', 'Unpaid', 'Unpaid'),
(4526, 'Bangla', 'Invoice No', 'Invoice No'),
(4527, 'Bangla', 'Invoice Title', 'Invoice Title'),
(4528, 'Bangla', 'Total Amount', 'Total Amount'),
(4529, 'Bangla', 'Created at', 'Created at'),
(4530, 'Bangla', 'Paid Amount', 'Paid Amount'),
(4531, 'Bangla', 'Expense', 'Expense'),
(4532, 'Bangla', 'Create Expense', 'Create Expense'),
(4533, 'Bangla', 'Add New Expense', 'Add New Expense'),
(4534, 'Bangla', 'Create Expense Category', 'Create Expense Category'),
(4535, 'Bangla', 'Add Expense Category', 'Add Expense Category'),
(4536, 'Bangla', 'Option', 'Option'),
(4537, 'Bangla', 'Edit Expense Category', 'Edit Expense Category'),
(4538, 'Bangla', 'Book', 'Book'),
(4539, 'Bangla', 'Add book', 'Add book'),
(4540, 'Bangla', 'Book name', 'Book name'),
(4541, 'Bangla', 'Author', 'Author'),
(4542, 'Bangla', 'Copies', 'Copies'),
(4543, 'Bangla', 'Available copies', 'Available copies'),
(4544, 'Bangla', 'Edit Book', 'Edit Book'),
(4545, 'Bangla', 'Book Issue', 'Book Issue'),
(4546, 'Bangla', 'Issue Book', 'Issue Book'),
(4547, 'Bangla', 'Noticeboard calendar', 'Noticeboard calendar'),
(4548, 'Bangla', 'Add New Notice', 'Add New Notice'),
(4549, 'Bangla', 'Locales:', 'Locales:'),
(4550, 'Bangla', 'Current Plan', 'Current Plan'),
(4551, 'Bangla', 'Silver', 'Silver'),
(4552, 'Bangla', 'Monthly', 'Monthly'),
(4553, 'Bangla', 'Subscription Renew Date', 'Subscription Renew Date'),
(4554, 'Bangla', 'Amount To Be Charged', 'Amount To Be Charged'),
(4555, 'Bangla', 'Create Event', 'Create Event'),
(4556, 'Bangla', 'Event title', 'Event title'),
(4557, 'Bangla', 'Date', 'Date'),
(4558, 'Bangla', 'Update event', 'Update event'),
(4559, 'Bangla', 'Upload addons zip file', 'Upload addons zip file'),
(4560, 'Bangla', 'Verified', 'Verified'),
(4561, 'Bangla', 'Details info', 'Details info'),
(4562, 'Bangla', 'Phone Number', 'Phone Number'),
(4563, 'Bangla', 'Designation', 'Designation'),
(4564, 'Bangla', 'Save Changes', 'Save Changes'),
(4565, 'Bangla', 'Select a status', 'Select a status'),
(4566, 'Bangla', 'Update school', 'Update school'),
(4567, 'Bangla', 'Package price', 'Package price'),
(4568, 'Bangla', 'Package Type', 'Package Type'),
(4569, 'Bangla', 'Select a package type', 'Select a package type'),
(4570, 'Bangla', 'Trail', 'Trail'),
(4571, 'Bangla', 'Select a interval', 'Select a interval'),
(4572, 'Bangla', 'Days', 'Days'),
(4573, 'Bangla', 'Yearly', 'Yearly'),
(4574, 'Bangla', 'Interval Preiod', 'Interval Preiod');
INSERT INTO `language` (`id`, `name`, `phrase`, `translated`) VALUES
(4575, 'Bangla', 'Description', 'Description'),
(4576, 'Bangla', 'Create package', 'Create package'),
(4577, 'Bangla', 'Update package', 'Update package'),
(4578, 'Bangla', 'Invalid purchase code', 'Invalid purchase code'),
(4579, 'Bangla', 'Inactive', 'Inactive'),
(4580, 'Bangla', 'Save event', 'Save event'),
(4581, 'Bangla', 'Create', 'Create'),
(4582, 'Bangla', 'Select a department', 'Select a department'),
(4583, 'Bangla', 'One', 'One'),
(4584, 'Bangla', 'Two', 'Two'),
(4585, 'Bangla', 'Three', 'Three'),
(4586, 'Bangla', 'Four', 'Four'),
(4587, 'Bangla', 'Five', 'Five'),
(4588, 'Bangla', 'Six', 'Six'),
(4589, 'Bangla', 'Seven', 'Seven'),
(4590, 'Bangla', 'Eight', 'Eight'),
(4591, 'Bangla', 'Nine', 'Nine'),
(4592, 'Bangla', 'Ten', 'Ten'),
(4593, 'Bangla', 'Add students', 'Add students'),
(4594, 'Bangla', 'Create category', 'Create category'),
(4595, 'Bangla', 'Exam Name', 'Exam Name'),
(4596, 'Bangla', 'Select exam category name', 'Select exam category name'),
(4597, 'Bangla', 'Subject', 'Subject'),
(4598, 'Bangla', 'Starting date', 'Starting date'),
(4599, 'Bangla', 'Ending date', 'Ending date'),
(4600, 'Bangla', 'Student name', 'Student name'),
(4601, 'Bangla', 'Mark', 'Mark'),
(4602, 'Bangla', 'Comment', 'Comment'),
(4603, 'Bangla', 'Value has been updated successfully', 'Value has been updated successfully'),
(4604, 'Bangla', 'Required mark field', 'Required mark field'),
(4605, 'Bangla', 'Image', 'Image'),
(4606, 'Bangla', 'Enroll to', 'Enroll to'),
(4607, 'Bangla', 'Select a section', 'Select a section'),
(4608, 'Bangla', 'Attendance Report Of', 'Attendance Report Of'),
(4609, 'Bangla', 'Last Update at', 'Last Update at'),
(4610, 'Bangla', 'Time', 'Time'),
(4611, 'Bangla', 'Please select the required fields', 'Please select the required fields'),
(4612, 'Bangla', 'Saturday', 'Saturday'),
(4613, 'Bangla', 'Sunday', 'Sunday'),
(4614, 'Bangla', 'Monday', 'Monday'),
(4615, 'Bangla', 'Tuesday', 'Tuesday'),
(4616, 'Bangla', 'Wednesday', 'Wednesday'),
(4617, 'Bangla', 'Update subject', 'Update subject'),
(4618, 'Bangla', 'Select subject', 'Select subject'),
(4619, 'Bangla', 'Assign a teacher', 'Assign a teacher'),
(4620, 'Bangla', 'Select a class room', 'Select a class room'),
(4621, 'Bangla', 'Day', 'Day'),
(4622, 'Bangla', 'Select a day', 'Select a day'),
(4623, 'Bangla', 'Thursday', 'Thursday'),
(4624, 'Bangla', 'Friday', 'Friday'),
(4625, 'Bangla', 'Starting hour', 'Starting hour'),
(4626, 'Bangla', 'Starting minute', 'Starting minute'),
(4627, 'Bangla', 'Ending hour', 'Ending hour'),
(4628, 'Bangla', 'Ending minute', 'Ending minute'),
(4629, 'Bangla', 'Add routine', 'Add routine'),
(4630, 'Bangla', 'Edit class routine', 'Edit class routine'),
(4631, 'Bangla', 'Tittle', 'Tittle'),
(4632, 'Bangla', 'Upload syllabus', 'Upload syllabus'),
(4633, 'Bangla', 'Select student', 'Select student'),
(4634, 'Bangla', 'Select a student', 'Select a student'),
(4635, 'Bangla', 'Payment method', 'Payment method'),
(4636, 'Bangla', 'Select a payment method', 'Select a payment method'),
(4637, 'Bangla', 'Cash', 'Cash'),
(4638, 'Bangla', 'Paypal', 'Paypal'),
(4639, 'Bangla', 'Paytm', 'Paytm'),
(4640, 'Bangla', 'Razorpay', 'Razorpay'),
(4641, 'Bangla', 'Create invoice', 'Create invoice'),
(4642, 'Bangla', 'Payment date', 'Payment date'),
(4643, 'Bangla', 'Print invoice', 'Print invoice'),
(4644, 'Bangla', 'Edit Invoice', 'Edit Invoice'),
(4645, 'Bangla', 'Amount', 'Amount'),
(4646, 'Bangla', 'Select an expense category', 'Select an expense category'),
(4647, 'Bangla', 'Edit Expense', 'Edit Expense'),
(4648, 'Bangla', 'Issue date', 'Issue date'),
(4649, 'Bangla', 'Select book', 'Select book'),
(4650, 'Bangla', 'Id', 'Id'),
(4651, 'Bangla', 'Pending', 'Pending'),
(4652, 'Bangla', 'Update issued book', 'Update issued book'),
(4653, 'Bangla', 'Return this book', 'Return this book'),
(4654, 'Bangla', 'Notice title', 'Notice title'),
(4655, 'Bangla', 'Start date', 'Start date'),
(4656, 'Bangla', 'Setup additional date & time', 'Setup additional date & time'),
(4657, 'Bangla', 'Start time', 'Start time'),
(4658, 'Bangla', 'End date', 'End date'),
(4659, 'Bangla', 'End time', 'End time'),
(4660, 'Bangla', 'Notice', 'Notice'),
(4661, 'Bangla', 'Show on website', 'Show on website'),
(4662, 'Bangla', 'Show', 'Show'),
(4663, 'Bangla', 'Do not need to show', 'Do not need to show'),
(4664, 'Bangla', 'Upload notice photo', 'Upload notice photo'),
(4665, 'Bangla', 'Save notice', 'Save notice'),
(4666, 'Bangla', 'School Currency', 'School Currency'),
(4667, 'Bangla', 'Exam List', 'Exam List'),
(4668, 'Bangla', 'Profile', 'Profile'),
(4669, 'Bangla', ' Download', ' Download'),
(4670, 'Bangla', 'Select a subject', 'Select a subject'),
(4671, 'Bangla', 'Welcome, to', 'Welcome, to'),
(4672, 'Bangla', 'Fee Manager', 'Fee Manager'),
(4673, 'Bangla', 'List Of Books', 'List Of Books'),
(4674, 'Bangla', 'Issued Book', 'Issued Book'),
(4675, 'Bangla', 'Student Code', 'Student Code'),
(4676, 'Bangla', 'Candice Kennedy', 'Candice Kennedy'),
(4677, 'Bangla', 'English', 'English'),
(4678, 'Bangla', 'Natalie Ashley', 'Natalie Ashley'),
(4679, 'Bangla', 'Byron Chase', 'Byron Chase'),
(4680, 'Bangla', 'Rafael Hardy', 'Rafael Hardy'),
(4681, 'Bangla', 'Mathematics', 'Mathematics'),
(4682, 'Bangla', 'Aphrodite Shaffer', 'Aphrodite Shaffer'),
(4683, 'Bangla', 'Bangla', 'Bangla'),
(4684, 'Bangla', 'Fatima Phillips', 'Fatima Phillips'),
(4685, 'Bangla', 'Sydney Pearson', 'Sydney Pearson'),
(4686, 'Bangla', 'Drawing', 'Drawing'),
(4687, 'Bangla', 'Imani Cooper', 'Imani Cooper'),
(4688, 'Bangla', 'Ulric Spencer', 'Ulric Spencer'),
(4689, 'Bangla', 'Yoshio Gentry', 'Yoshio Gentry'),
(4690, 'Bangla', 'Attendance report', 'Attendance report'),
(4691, 'Bangla', 'Of', 'Of'),
(4692, 'Bangla', 'Last updated at', 'Last updated at'),
(4693, 'Bangla', 'View Marks', 'View Marks'),
(4694, 'Bangla', 'Subject name', 'Subject name'),
(4695, 'Bangla', 'Pay', 'Pay'),
(4696, 'Bangla', 'List Of Book', 'List Of Book'),
(4697, 'Bangla', 'Child', 'Child'),
(4698, 'Bangla', 'Teaches', 'Teaches'),
(4699, 'Bangla', 'Student List', 'Student List'),
(4700, 'Bangla', 'Id card', 'Id card'),
(4701, 'Bangla', 'Code', 'Code'),
(4702, 'Bangla', 'Not found', 'Not found'),
(4703, 'Bangla', 'Subcription', 'Subcription'),
(4704, 'Bangla', 'Expired Subscription', 'Expired Subscription'),
(4705, 'Bangla', ' Pending Requests', ' Pending Requests'),
(4706, 'Bangla', 'Website Settings', 'Website Settings'),
(4707, 'Bangla', 'Manage Faq', 'Manage Faq'),
(4708, 'Bangla', 'Language Settings', 'Language Settings'),
(4709, 'Bangla', 'Visit Website', 'Visit Website'),
(4710, 'Bangla', 'Heads up', 'Heads up'),
(4711, 'Bangla', 'Are you sure', 'Are you sure'),
(4712, 'Bangla', 'School Logo', 'School Logo'),
(4713, 'Bangla', 'Admin List', 'Admin List'),
(4714, 'Bangla', 'Archive ', 'Archive '),
(4715, 'Bangla', 'Trial', 'Trial'),
(4716, 'Bangla', 'Life Time', 'Life Time'),
(4717, 'Bangla', 'Students Limit', 'Students Limit'),
(4718, 'Bangla', 'Features', 'Features'),
(4719, 'Bangla', 'Write Features', 'Write Features'),
(4720, 'Bangla', 'Write service', 'Write service'),
(4721, 'Bangla', 'Student Limit', 'Student Limit'),
(4722, 'Bangla', 'Package List', 'Package List'),
(4723, 'Bangla', 'Feature', 'Feature'),
(4724, 'Bangla', 'Faq', 'Faq'),
(4725, 'Bangla', 'Contact', 'Contact'),
(4726, 'Bangla', 'Register', 'Register'),
(4727, 'Bangla', 'School Register Form', 'School Register Form'),
(4728, 'Bangla', 'Admin Name', 'Admin Name'),
(4729, 'Bangla', 'User Account', 'User Account'),
(4730, 'Bangla', 'Our Features', 'Our Features'),
(4731, 'Bangla', 'Subscribe', 'Subscribe'),
(4732, 'Bangla', 'Have Any Question', 'Have Any Question'),
(4733, 'Bangla', 'Contact us with any questions', 'Contact us with any questions'),
(4734, 'Bangla', 'Contact Us', 'Contact Us'),
(4735, 'Bangla', 'Social Link', 'Social Link'),
(4736, 'Bangla', 'Feedback', 'Feedback'),
(4737, 'Bangla', 'Not Subscribed', 'Not Subscribed'),
(4738, 'Bangla', 'You are not subscribed to any plan. Subscribe now.', 'You are not subscribed to any plan. Subscribe now.'),
(4739, 'Bangla', 'Deliverable', 'Deliverable'),
(4740, 'Bangla', 'Rate', 'Rate'),
(4741, 'Bangla', 'Total', 'Total'),
(4742, 'Bangla', 'All Rights Reserved', 'All Rights Reserved'),
(4743, 'Bangla', 'Subscription Purchase Date', 'Subscription Purchase Date'),
(4744, 'Bangla', 'Upgrade Subscribe ', 'Upgrade Subscribe '),
(4745, 'Bangla', 'Department List', 'Department List'),
(4746, 'Bangla', 'Filter Options', 'Filter Options'),
(4747, 'Bangla', 'Apply', 'Apply'),
(4748, 'Bangla', 'Excel upload', 'Excel upload'),
(4749, 'Bangla', 'Add Parent', 'Add Parent'),
(4750, 'Bangla', 'Parent Create', 'Parent Create'),
(4751, 'Bangla', '', ''),
(4752, 'Bangla', 'Account Status', 'Account Status'),
(4753, 'Bangla', 'Enable', 'Enable'),
(4754, 'Bangla', 'librarian Profile', 'librarian Profile'),
(4755, 'Bangla', 'Documents', 'Documents'),
(4756, 'Bangla', 'Disable', 'Disable'),
(4757, 'Bangla', 'Librarian List', 'Librarian List'),
(4758, 'Bangla', 'Teacher Profile', 'Teacher Profile'),
(4759, 'Bangla', 'Teachers List', 'Teachers List'),
(4760, 'Bangla', 'Email receipt title', 'Email receipt title'),
(4761, 'Bangla', 'Email Details', 'Email Details'),
(4762, 'Bangla', 'Remaining characters is', 'Remaining characters is'),
(4763, 'Bangla', 'Warning Text', 'Warning Text'),
(4764, 'Bangla', 'Social Link 1', 'Social Link 1'),
(4765, 'Bangla', 'Social Link 2', 'Social Link 2'),
(4766, 'Bangla', 'Social Link 3', 'Social Link 3'),
(4767, 'Bangla', 'Social Logo-1', 'Social Logo-1'),
(4768, 'Bangla', 'Social Logo-2', 'Social Logo-2'),
(4769, 'Bangla', 'Social Logo-3', 'Social Logo-3'),
(4770, 'Bangla', 'Email template Logo', 'Email template Logo'),
(4771, 'Bangla', 'instruction', 'instruction'),
(4772, 'Bangla', 'Images for email templates will only support if the application is hosted on a live server. Localhost will not support this.', 'Images for email templates will only support if the application is hosted on a live server. Localhost will not support this.'),
(4773, 'Bangla', 'Student Profile', 'Student Profile'),
(4774, 'Bangla', 'Showing', 'Showing'),
(4775, 'Bangla', 'from', 'from'),
(4776, 'Bangla', 'data', 'data'),
(4777, 'Bangla', ' Students List', ' Students List'),
(4778, 'Bangla', 'Blood', 'Blood'),
(4779, 'Bangla', 'All Message', 'All Message'),
(4780, 'Bangla', 'Message', 'Message'),
(4781, 'Bangla', 'Read & Reply', 'Read & Reply'),
(4782, 'Bangla', 'View & Reply', 'View & Reply'),
(4783, 'Bangla', 'Show student list', 'Show student list'),
(4784, 'Bangla', 'Update attendance', 'Update attendance'),
(4785, 'Bangla', 'Present All', 'Present All'),
(4786, 'Bangla', 'Absent All', 'Absent All'),
(4787, 'Bangla', 'present', 'present'),
(4788, 'Bangla', 'absent', 'absent'),
(4789, 'Bangla', 'Number of child', 'Number of child'),
(4790, 'Bangla', 'Parent Profile', 'Parent Profile'),
(4791, 'Bangla', 'Parent List', 'Parent List'),
(4792, 'Bangla', 'Payment', 'Payment'),
(4793, 'Bangla', 'Make Payment', 'Make Payment'),
(4794, 'Bangla', 'Payment Gateway', 'Payment Gateway'),
(4795, 'Bangla', 'Offline', 'Offline'),
(4796, 'Bangla', 'Addon', 'Addon'),
(4797, 'Bangla', 'Invoice Summary', 'Invoice Summary'),
(4798, 'Bangla', 'Grand Total', 'Grand Total'),
(4799, 'Bangla', 'Document of your payment', 'Document of your payment'),
(4800, 'Bangla', 'Submit payment document', 'Submit payment document'),
(4801, 'Bangla', 'Admin will review your payment document and then approve the Payment.', 'Admin will review your payment document and then approve the Payment.'),
(4802, 'Bangla', 'Pending Payment', 'Pending Payment'),
(4803, 'Bangla', 'You payment request has been sent to Superadmin ', 'You payment request has been sent to Superadmin '),
(4804, 'Bangla', 'Pending Requests', 'Pending Requests'),
(4805, 'Bangla', 'To', 'To'),
(4806, 'Bangla', 'Suspended', 'Suspended'),
(4807, 'Bangla', 'Create Feedback', 'Create Feedback'),
(4808, 'Bangla', 'Add New Feedback', 'Add New Feedback'),
(4809, 'Bangla', 'Admin Profile', 'Admin Profile'),
(4810, 'Bangla', 'Admin Permission', 'Admin Permission'),
(4811, 'Bangla', 'Administrator', 'Administrator'),
(4812, 'Bangla', ' Important Notice: Your Account Has Been Disabled', ' Important Notice: Your Account Has Been Disabled'),
(4813, 'Bangla', 'We regret to inform you that your account with', 'We regret to inform you that your account with'),
(4814, 'Bangla', ' has been disabled effective immediately', ' has been disabled effective immediately'),
(4815, 'Bangla', 'What this means for you', 'What this means for you'),
(4816, 'Bangla', 'You will no longer be able to access your account.', 'You will no longer be able to access your account.'),
(4817, 'Bangla', 'All services and features associated with your account are now inaccessible.', 'All services and features associated with your account are now inaccessible.'),
(4818, 'Bangla', 'Any active subscriptions or services have been terminated.', 'Any active subscriptions or services have been terminated.'),
(4819, 'Bangla', 'Add Users', 'Add Users'),
(4820, 'Bangla', 'Accountant Profile', 'Accountant Profile'),
(4821, 'Bangla', 'Accountant List', 'Accountant List'),
(4822, 'Bangla', 'Class & Section', 'Class & Section'),
(4823, 'Bangla', 'Stripe', 'Stripe'),
(4824, 'Bangla', 'Flutterwave', 'Flutterwave'),
(4825, 'Bangla', 'Select expense category', 'Select expense category'),
(4826, 'Bangla', 'Discount amount', 'Discount amount'),
(4827, 'Bangla', 'this_course_has', 'this_course_has'),
(4828, 'Bangla', 'discount', 'discount'),
(4829, 'Bangla', 'This student has', 'This student has'),
(4830, 'Bangla', 'Invoice', 'Invoice'),
(4831, 'Bangla', 'Please find below the invoice', 'Please find below the invoice'),
(4832, 'Bangla', 'Billing Address', 'Billing Address'),
(4833, 'Bangla', 'Due Amount', 'Due Amount'),
(4834, 'Bangla', 'Subtotal', 'Subtotal'),
(4835, 'Bangla', 'Due', 'Due'),
(4836, 'Bangla', 'Discount:', 'Discount:'),
(4837, 'Bangla', 'Update invoice', 'Update invoice'),
(4838, 'Bangla', 'Payment | Ekator 8', 'Payment | Ekator 8'),
(4839, 'Bangla', 'Document', 'Document'),
(4840, 'Bangla', 'Decline', 'Decline'),
(4841, 'Bangla', 'Make Admit Card', 'Make Admit Card'),
(4842, 'Bangla', 'Teacher list', 'Teacher list'),
(4843, 'Bangla', 'Students list', 'Students list'),
(4844, 'Bangla', 'Teacher Permission list', 'Teacher Permission list'),
(4845, 'Bangla', 'Addmission', 'Addmission'),
(4846, 'Bangla', 'Subject List', 'Subject List'),
(4847, 'Bangla', 'Students Fee Manager', 'Students Fee Manager'),
(4848, 'Bangla', 'Navigation Menu Settings', 'Navigation Menu Settings'),
(4849, 'Bangla', 'Language list ', 'Language list '),
(4850, 'Bangla', 'Add language', 'Add language'),
(4851, 'Bangla', 'Language', 'Language'),
(4852, 'Bangla', 'Edit phrase', 'Edit phrase'),
(4853, 'Bangla', 'Delete language', 'Delete language'),
(4854, 'Bangla', 'Add new language', 'Add new language'),
(4855, 'Bangla', 'No special character or space is allowed', 'No special character or space is allowed'),
(4856, 'Bangla', 'Valid examples', 'Valid examples'),
(4857, 'Bangla', 'System default language can not be removed', 'System default language can not be removed'),
(4858, 'Bangla', 'Phrase updated', 'Phrase updated'),
(4859, 'english', 'Admit Card', 'Admit Card'),
(4860, 'Bangla', 'Admit Card', 'Admit Card'),
(4861, 'english', 'Create Admit Card', 'Create Admit Card'),
(4862, 'Bangla', 'Create Admit Card', 'Create Admit Card'),
(4863, 'english', 'Create New Admit Card', 'Create New Admit Card'),
(4864, 'Bangla', 'Create New Admit Card', 'Create New Admit Card'),
(4865, 'english', 'Template *', 'Template *'),
(4866, 'Bangla', 'Template *', 'Template *'),
(4867, 'english', 'Heading', 'Heading'),
(4868, 'Bangla', 'Heading', 'Heading'),
(4869, 'english', 'Exam Center', 'Exam Center'),
(4870, 'Bangla', 'Exam Center', 'Exam Center'),
(4871, 'english', 'Disabled', 'Disabled'),
(4872, 'Bangla', 'Disabled', 'Disabled'),
(4873, 'english', 'Select Students', 'Select Students'),
(4874, 'Bangla', 'Select Students', 'Select Students'),
(4875, 'english', 'Select Parent', 'Select Parent'),
(4876, 'Bangla', 'Select Parent', 'Select Parent'),
(4877, 'english', 'Write Feedback', 'Write Feedback'),
(4878, 'Bangla', 'Write Feedback', 'Write Feedback'),
(4879, 'english', 'Send Feedback', 'Send Feedback'),
(4880, 'Bangla', 'Send Feedback', 'Send Feedback'),
(4881, 'english', 'Edit feedback', 'Edit feedback'),
(4882, 'Bangla', 'Edit feedback', 'Edit feedback'),
(4883, 'english', 'Template Name', 'Template Name'),
(4884, 'Bangla', 'Template Name', 'Template Name'),
(4885, 'english', 'Social Media Icon', 'Social Media Icon'),
(4886, 'Bangla', 'Social Media Icon', 'Social Media Icon'),
(4887, 'english', 'Social Media Name', 'Social Media Name'),
(4888, 'Bangla', 'Social Media Name', 'Social Media Name'),
(4889, 'english', 'Social Media Url', 'Social Media Url'),
(4890, 'Bangla', 'Social Media Url', 'Social Media Url'),
(4891, 'english', 'Contact Email', 'Contact Email'),
(4892, 'Bangla', 'Contact Email', 'Contact Email'),
(4893, 'english', 'Contact Number', 'Contact Number'),
(4894, 'Bangla', 'Contact Number', 'Contact Number'),
(4895, 'english', 'Signature', 'Signature'),
(4896, 'Bangla', 'Signature', 'Signature'),
(4897, 'english', 'See Admit Card', 'See Admit Card'),
(4898, 'Bangla', 'See Admit Card', 'See Admit Card'),
(4899, 'english', 'Edit Admit Card', 'Edit Admit Card'),
(4900, 'Bangla', 'Edit Admit Card', 'Edit Admit Card'),
(4901, 'english', 'Update admitCardEdit', 'Update admitCardEdit'),
(4902, 'Bangla', 'Update admitCardEdit', 'Update admitCardEdit'),
(4903, 'english', 'Room Number', 'Room Number'),
(4904, 'Bangla', 'Room Number', 'Room Number'),
(4905, 'english', 'Print Admit Card', 'Print Admit Card'),
(4906, 'Bangla', 'Print Admit Card', 'Print Admit Card'),
(4907, 'english', 'Admit Card List', 'Admit Card List'),
(4908, 'Bangla', 'Admit Card List', 'Admit Card List'),
(4909, 'english', 'Admit Card Print', 'Admit Card Print'),
(4910, 'Bangla', 'Admit Card Print', 'Admit Card Print'),
(4911, 'english', 'Edit Grade', 'Edit Grade'),
(4912, 'Bangla', 'Edit Grade', 'Edit Grade');

-- --------------------------------------------------------

--
-- Table structure for table `message_thrades`
--

CREATE TABLE `message_thrades` (
  `id` int NOT NULL,
  `reciver_id` int DEFAULT NULL,
  `sender_id` int DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int UNSIGNED NOT NULL,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `noticeboard`
--

CREATE TABLE `noticeboard` (
  `id` bigint UNSIGNED NOT NULL,
  `notice_title` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `notice` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `end_date` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `end_time` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` int NOT NULL,
  `show_on_website` int NOT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `packages`
--

CREATE TABLE `packages` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `package_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `interval` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `days` int DEFAULT NULL,
  `studentLimit` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `features` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` int DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int NOT NULL,
  `expense_type` longtext COLLATE utf8mb4_general_ci NOT NULL,
  `expense_id` int NOT NULL,
  `user_id` int NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `transaction_keys` longtext COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated_at` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `school_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_gateways`
--

CREATE TABLE `payment_gateways` (
  `id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `status` int DEFAULT NULL,
  `configuration` longtext COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` int NOT NULL,
  `updated_at` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_history`
--

CREATE TABLE `payment_history` (
  `id` int NOT NULL,
  `payment_type` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `course_id` int DEFAULT NULL,
  `package_id` int DEFAULT NULL,
  `amount` float DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `transaction_keys` longtext COLLATE utf8mb4_general_ci,
  `document_image` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `paid_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `timestamp` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `payment_keys` longtext COLLATE utf8mb4_general_ci NOT NULL,
  `image` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` int NOT NULL DEFAULT '0' COMMENT '0=inactive , 1=active',
  `mode` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'test' COMMENT 'test / live',
  `created_at` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated_at` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `school_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `name`, `payment_keys`, `image`, `status`, `mode`, `created_at`, `updated_at`, `school_id`) VALUES
(1, 'offline', '{}', 'offline.png', 1, 'offline', '', '', NULL),
(2, 'paypal', '{\"test_client_id\":\"snd_cl_id_xxxxxxxxxxxxx\",\"test_secret_key\":\"snd_cl_sid_xxxxxxxxxxxx\",\"live_client_id\":\"lv_cl_id_xxxxxxxxxxxxxxx\",\"live_secret_key\":\"lv_cl_sid_xxxxxxxxxxxxxx\"}', 'paypal.png', 1, 'test', NULL, NULL, 1),
(3, 'stripe', '{\"test_key\":\"pk_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"sk_test_xxxxxxxxxxxxxx\",\"public_live_key\":\"pk_live_xxxxxxxxxxxxxx\",\"secret_live_key\":\"sk_live_xxxxxxxxxxxxxx\"}', 'stripe.png', 1, 'test', NULL, NULL, 1),
(4, 'razorpay', '{\"test_key\":\"rzp_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"rzs_test_xxxxxxxxxxxxx\",\"live_key\":\"rzp_live_xxxxxxxxxxxxx\",\"live_secret_key\":\"rzs_live_xxxxxxxxxxxxx\",\"theme_color\":\"#c7a600\"}', 'razorpay.png', 1, 'test', NULL, NULL, 1),
(5, 'paytm', '{\"test_merchant_id\":\"tm_id_xxxxxxxxxxxx\",\"test_merchant_key\":\"tm_key_xxxxxxxxxx\",\"live_merchant_id\":\"lv_mid_xxxxxxxxxxx\",\"live_merchant_key\":\"lv_key_xxxxxxxxxxx\",\"environment\":\"provide-a-environment\",\"merchant_website\":\"merchant-website\",\"channel\":\"provide-channel-type\",\"industry_type\":\"provide-industry-type\"}', 'paytm.png', 1, 'test', NULL, NULL, 1),
(6, 'flutterwave', '{\"test_key\":\"flwp_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"flws_test_xxxxxxxxxxxxx\",\"test_encryption_key\":\"flwe_test_xxxxxxxxxxxxx\",\"public_live_key\":\"flwp_live_xxxxxxxxxxxxxx\",\"secret_live_key\":\"flws_live_xxxxxxxxxxxxxx\",\"encryption_live_key\":\"flwe_live_xxxxxxxxxxxxxx\"}', 'flutterwave.png', 1, 'test', NULL, NULL, 1),
(7, 'paystack', '{\"test_key\":\"pk_test_xxxxxxxxxx\",\"test_secret_key\":\"sk_test_xxxxxxxxxxxxxx\",\"public_live_key\":\"pk_live_xxxxxxxxxxxxxx\",\"secret_live_key\":\"sk_live_xxxxxxxxxxxxxx\"}', 'paystack.png', 1, 'test', NULL, NULL, 1),
(8, 'paypal', '{\"test_client_id\":\"snd_cl_id_xxxxxxxxxxxxx\",\"test_secret_key\":\"snd_cl_sid_xxxxxxxxxxxx\",\"live_client_id\":\"lv_cl_id_xxxxxxxxxxxxxxx\",\"live_secret_key\":\"lv_cl_sid_xxxxxxxxxxxxxx\"}', 'paypal.png', 1, 'test', NULL, NULL, 2),
(9, 'stripe', '{\"test_key\":\"pk_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"sk_test_xxxxxxxxxxxxxx\",\"public_live_key\":\"pk_live_xxxxxxxxxxxxxx\",\"secret_live_key\":\"sk_live_xxxxxxxxxxxxxx\"}', 'stripe.png', 1, 'test', NULL, NULL, 2),
(10, 'razorpay', '{\"test_key\":\"rzp_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"rzs_test_xxxxxxxxxxxxx\",\"live_key\":\"rzp_live_xxxxxxxxxxxxx\",\"live_secret_key\":\"rzs_live_xxxxxxxxxxxxx\",\"theme_color\":\"#c7a600\"}', 'razorpay.png', 1, 'test', NULL, NULL, 2),
(11, 'paytm', '{\"test_merchant_id\":\"tm_id_xxxxxxxxxxxx\",\"test_merchant_key\":\"tm_key_xxxxxxxxxx\",\"live_merchant_id\":\"lv_mid_xxxxxxxxxxx\",\"live_merchant_key\":\"lv_key_xxxxxxxxxxx\",\"environment\":\"provide-a-environment\",\"merchant_website\":\"merchant-website\",\"channel\":\"provide-channel-type\",\"industry_type\":\"provide-industry-type\"}', 'paytm.png', 1, 'test', NULL, NULL, 2),
(12, 'flutterwave', '{\"test_key\":\"flwp_test_xxxxxxxxxxxxx\",\"test_secret_key\":\"flws_test_xxxxxxxxxxxxx\",\"test_encryption_key\":\"flwe_test_xxxxxxxxxxxxx\",\"public_live_key\":\"flwp_live_xxxxxxxxxxxxxx\",\"secret_live_key\":\"flws_live_xxxxxxxxxxxxxx\",\"encryption_live_key\":\"flwe_live_xxxxxxxxxxxxxx\"}', 'flutterwave.png', 1, 'test', NULL, NULL, 2),
(13, 'paystack', '{\"test_key\":\"pk_test_xxxxxxxxxx\",\"test_secret_key\":\"sk_test_xxxxxxxxxxxxxx\",\"public_live_key\":\"pk_live_xxxxxxxxxxxxxx\",\"secret_live_key\":\"sk_live_xxxxxxxxxxxxxx\"}', 'paystack.png', 1, 'test', NULL, NULL, 2);

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'superadmin', '2022-05-17 07:14:27', '2022-05-17 07:14:27'),
(2, 'admin', '2022-05-17 07:14:27', '2022-05-17 07:14:27'),
(3, 'teacher', '2022-05-17 07:15:14', '2022-05-17 07:14:27'),
(4, 'accountant', '2022-05-17 07:15:14', '2022-05-17 07:14:27'),
(5, 'librarian', '2022-05-17 07:15:14', '2022-05-17 07:14:27'),
(6, 'parent', '2022-05-17 07:15:14', '2022-05-17 07:14:27'),
(7, 'student', '2022-05-17 07:15:14', '2022-05-17 07:14:27'),
(8, 'user', '2023-05-24 06:06:50', '2023-05-24 06:06:50'),
(9, 'alumni', '2023-06-01 11:38:30', '2023-06-01 11:38:30'),
(20, 'staff', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

-- --------------------------------------------------------

--
-- Table structure for table `routines`
--

CREATE TABLE `routines` (
  `id` bigint UNSIGNED NOT NULL,
  `class_id` int DEFAULT NULL,
  `section_id` int DEFAULT NULL,
  `subject_id` int DEFAULT NULL,
  `programme_id` bigint UNSIGNED DEFAULT NULL,
  `starting_hour` int NOT NULL,
  `ending_hour` int NOT NULL,
  `starting_minute` int NOT NULL,
  `ending_minute` int NOT NULL,
  `day` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `teacher_id` int NOT NULL,
  `room_id` int NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schools`
--

CREATE TABLE `schools` (
  `id` bigint UNSIGNED NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `school_info` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` int NOT NULL,
  `running_session` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `school_currency` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_position` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `school_logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warning_text` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socialLink1` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socialLink2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socialLink3` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socialLogo1` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socialLogo2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `socialLogo3` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `off_pay_ins_text` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `off_pay_ins_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `session_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` int NOT NULL,
  `school_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_fee_managers`
--

CREATE TABLE `student_fee_managers` (
  `id` bigint UNSIGNED NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` int NULL,
  `amount` int NULL,
  `discounted_price` int DEFAULT NULL,
  `class_id` int NOT NULL,
  `student_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_amount` int NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `timestamp` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `document_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_id` int NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` bigint UNSIGNED NOT NULL,
  `package_id` int NOT NULL,
  `school_id` int NOT NULL,
  `paid_amount` double(8,2) NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_keys` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expire_date` int NOT NULL,
  `studentLimit` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_added` int NOT NULL,
  `active` int NOT NULL,
  `status` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `syllabuses`
--

CREATE TABLE `syllabuses` (
  `id` bigint UNSIGNED NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_id` int NOT NULL,
  `section_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `file` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_id` int NOT NULL,
  `session_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_permissions`
--

CREATE TABLE `teacher_permissions` (
  `id` int NOT NULL,
  `class_id` int DEFAULT NULL,
  `section_id` int DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `teacher_id` int DEFAULT NULL,
  `marks` int DEFAULT NULL,
  `attendance` int DEFAULT NULL,
  `updated_at` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `role_id` int DEFAULT NULL,
  `school_role` int DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `school_id` int DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_information` longtext COLLATE utf8mb4_unicode_ci,
  `student_info` longtext COLLATE utf8mb4_unicode_ci,
  `documents` longtext COLLATE utf8mb4_unicode_ci,
  `status` int DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `designation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `language` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `menu_permission` text COLLATE utf8mb4_unicode_ci,
  `account_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `addons`
--
ALTER TABLE `addons`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `appraisals`
--
ALTER TABLE `appraisals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `appraisal_submits`
--
ALTER TABLE `appraisal_submits`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admit_cards`
--
ALTER TABLE `admit_cards`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `book_issues`
--
ALTER TABLE `book_issues`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chats`
--
ALTER TABLE `chats`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `class_rooms`
--
ALTER TABLE `class_rooms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `currency`
--
ALTER TABLE `currency`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `daily_attendances`
--
ALTER TABLE `daily_attendances`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `enrollment`
--
ALTER TABLE `enrollment`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exam_categories`
--
ALTER TABLE `exam_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `faq`
--
ALTER TABLE `faq`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `frontend_events`
--
ALTER TABLE `frontend_events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `frontend_features`
--
ALTER TABLE `frontend_features`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `global_settings`
--
ALTER TABLE `global_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `gradebooks`
--
ALTER TABLE `gradebooks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `language`
--
ALTER TABLE `language`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `message_thrades`
--
ALTER TABLE `message_thrades`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `noticeboard`
--
ALTER TABLE `noticeboard`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `packages`
--
ALTER TABLE `packages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD KEY `password_resets_email_index` (`email`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_gateways`
--
ALTER TABLE `payment_gateways`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_history`
--
ALTER TABLE `payment_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`);

--
-- Indexes for table `routines`
--
ALTER TABLE `routines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `schools`
--
ALTER TABLE `schools`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_fee_managers`
--
ALTER TABLE `student_fee_managers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `syllabuses`
--
ALTER TABLE `syllabuses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `teacher_permissions`
--
ALTER TABLE `teacher_permissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `addons`
--
ALTER TABLE `addons`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appraisals`
--
ALTER TABLE `appraisals`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

  --
-- AUTO_INCREMENT for table `appraisal_submits`
--
ALTER TABLE `appraisal_submits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

--
-- AUTO_INCREMENT for table `admit_cards`
--
ALTER TABLE `admit_cards`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `book_issues`
--
ALTER TABLE `book_issues`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chats`
--
ALTER TABLE `chats`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_rooms`
--
ALTER TABLE `class_rooms`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `currency`
--
ALTER TABLE `currency`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=170;

--
-- AUTO_INCREMENT for table `daily_attendances`
--
ALTER TABLE `daily_attendances`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollment`
--
ALTER TABLE `enrollment`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exams`
--
ALTER TABLE `exams`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_categories`
--
ALTER TABLE `exam_categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faq`
--
ALTER TABLE `faq`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `frontend_events`
--
ALTER TABLE `frontend_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `frontend_features`
--
ALTER TABLE `frontend_features`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `global_settings`
--
ALTER TABLE `global_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `gradebooks`
--
ALTER TABLE `gradebooks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `language`
--
ALTER TABLE `language`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4913;

--
-- AUTO_INCREMENT for table `message_thrades`
--
ALTER TABLE `message_thrades`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `noticeboard`
--
ALTER TABLE `noticeboard`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `packages`
--
ALTER TABLE `packages`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_gateways`
--
ALTER TABLE `payment_gateways`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_history`
--
ALTER TABLE `payment_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `routines`
--
ALTER TABLE `routines`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schools`
--
ALTER TABLE `schools`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_fee_managers`
--
ALTER TABLE `student_fee_managers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `syllabuses`
--
ALTER TABLE `syllabuses`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teacher_permissions`
--
ALTER TABLE `teacher_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

DROP TABLE IF EXISTS `hostels`;
CREATE TABLE `hostels` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `warden_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `hostels`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `hostels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------
-- 2. hostel_applications
-- --------------------------------------------------------
DROP TABLE IF EXISTS `hostel_applications`;
CREATE TABLE `hostel_applications` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `hostel_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `note` longtext DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `hostel_applications`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `hostel_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------
-- 3. hostel_fees
-- --------------------------------------------------------
DROP TABLE IF EXISTS `hostel_fees`;
CREATE TABLE `hostel_fees` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `hostel_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `document_image` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `fee_payment_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `status` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `hostel_fees`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `hostel_fees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------
-- 4. hostel_rooms
-- --------------------------------------------------------
DROP TABLE IF EXISTS `hostel_rooms`;
CREATE TABLE `hostel_rooms` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `hostel_id` int(11) DEFAULT NULL,
  `room_no` varchar(255) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `occupied` int(11) DEFAULT NULL,
  `seat_fee` int(11) DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `hostel_rooms`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `hostel_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------
-- 5. hostel_room_allocations
-- --------------------------------------------------------
DROP TABLE IF EXISTS `hostel_room_allocations`;
CREATE TABLE `hostel_room_allocations` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `allocated_on` varchar(255) DEFAULT NULL,
  `vacated_on` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `hostel_room_allocations`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `hostel_room_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


--
-- Table structure for table `clubs`
--

DROP TABLE IF EXISTS `clubs`;
CREATE TABLE `clubs` (
  `id` int(11) NOT NULL,
  `club_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `status` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `advisor_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_members`
--

DROP TABLE IF EXISTS `club_members`;
CREATE TABLE `club_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `club_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `status` tinyint(1) DEFAULT '0' COMMENT '0=Pending,1=Approved,2=Rejected',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_notices`
--

DROP TABLE IF EXISTS `club_notices`;
CREATE TABLE `club_notices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `club_id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `notice_date` date NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `advisor_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `clubs`
--
ALTER TABLE `clubs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `club_members`
--
ALTER TABLE `club_members`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `club_notices`
--
ALTER TABLE `club_notices`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `clubs`
--
ALTER TABLE `clubs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_members`
--
ALTER TABLE `club_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_notices`
--
ALTER TABLE `club_notices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
