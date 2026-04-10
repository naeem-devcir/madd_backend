-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 04, 2026 at 11:04 PM
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
-- Database: `madd_new2`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `user_id` char(36) DEFAULT NULL,
  `vendor_id` char(36) DEFAULT NULL,
  `store_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `module` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `key_hash` varchar(255) NOT NULL,
  `key_preview` varchar(50) NOT NULL,
  `secret_hash` varchar(255) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `allowed_ips` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_ips`)),
  `allowed_origins` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_origins`)),
  `rate_limit_per_minute` int(11) NOT NULL DEFAULT 60,
  `rate_limit_per_day` int(11) NOT NULL DEFAULT 10000,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `country_configs`
--

CREATE TABLE `country_configs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` char(2) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone_code` varchar(10) NOT NULL,
  `eu_member` tinyint(1) NOT NULL DEFAULT 0,
  `currency_code` char(3) NOT NULL,
  `currency_symbol` varchar(10) NOT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `timezone` varchar(50) NOT NULL DEFAULT 'UTC',
  `language_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`language_codes`)),
  `madd_company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coupons`
--

CREATE TABLE `coupons` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type` enum('platform','vendor') NOT NULL,
  `vendor_id` char(36) DEFAULT NULL,
  `discount_type` enum('percentage','fixed_amount','free_shipping','buy_x_get_y') NOT NULL,
  `discount_value` decimal(12,4) NOT NULL,
  `min_order_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `max_uses` int(11) DEFAULT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `usage_limit_per_transaction` int(11) NOT NULL DEFAULT 1,
  `per_customer_limit` int(11) NOT NULL DEFAULT 1,
  `exclude_sale_items` tinyint(1) NOT NULL DEFAULT 0,
  `allowed_emails` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_emails`)),
  `allowed_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_roles`)),
  `combination_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`combination_rules`)),
  `budget_limit` decimal(12,4) DEFAULT NULL,
  `spent_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `applicable_to` enum('all','products','vendors','stores') NOT NULL DEFAULT 'all',
  `applicable_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applicable_ids`)),
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `magento_rule_id` int(11) DEFAULT NULL,
  `magento_coupon_id` int(11) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') NOT NULL DEFAULT 'pending',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `couriers`
--

CREATE TABLE `couriers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `api_type` varchar(50) NOT NULL,
  `credentials` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`credentials`)),
  `countries` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`countries`)),
  `service_levels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`service_levels`)),
  `tracking_url_template` varchar(255) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `support_contact` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`support_contact`)),
  `settlement_contact` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settlement_contact`)),
  `weight_limit_kg` decimal(8,2) DEFAULT NULL,
  `insurance_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`insurance_options`)),
  `data_processing_agreement` tinyint(1) NOT NULL DEFAULT 0,
  `contract_reference` varchar(255) DEFAULT NULL,
  `settlement_due_day` tinyint(4) NOT NULL DEFAULT 15,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `currencies`
--

CREATE TABLE `currencies` (
  `code` char(3) NOT NULL,
  `name` varchar(100) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `decimal_places` tinyint(4) NOT NULL DEFAULT 2,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `domains`
--

CREATE TABLE `domains` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_store_id` bigint(20) UNSIGNED DEFAULT NULL,
  `domain` varchar(253) NOT NULL,
  `type` enum('madd_subdomain','vendor_custom','marketplace') NOT NULL,
  `dns_verified` tinyint(1) NOT NULL DEFAULT 0,
  `dns_verified_at` timestamp NULL DEFAULT NULL,
  `verification_token` varchar(255) DEFAULT NULL,
  `ssl_status` enum('pending','active','expired','failed') NOT NULL DEFAULT 'pending',
  `ssl_provider` varchar(100) DEFAULT NULL,
  `ssl_issued_at` timestamp NULL DEFAULT NULL,
  `ssl_expires_at` timestamp NULL DEFAULT NULL,
  `ssl_auto_renew` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` timestamp NULL DEFAULT NULL,
  `redirect_type` varchar(10) NOT NULL DEFAULT '301',
  `www_redirect` tinyint(1) NOT NULL DEFAULT 1,
  `registrar` varchar(100) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `type` enum('vendor_invoice','credit_note','platform_invoice') NOT NULL,
  `payable_type` varchar(255) NOT NULL,
  `payable_id` bigint(20) UNSIGNED NOT NULL,
  `vendor_id` char(36) DEFAULT NULL,
  `settlement_id` bigint(20) UNSIGNED DEFAULT NULL,
  `order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `credit_note_id` bigint(20) UNSIGNED DEFAULT NULL,
  `madd_company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `billing_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`billing_address`)),
  `vat_number` varchar(50) DEFAULT NULL,
  `reverse_charge` tinyint(1) NOT NULL DEFAULT 0,
  `subtotal` decimal(12,4) NOT NULL,
  `tax_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total` decimal(12,4) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `language_code` varchar(10) NOT NULL DEFAULT 'en',
  `payment_terms` varchar(100) DEFAULT NULL,
  `footer_notes` text DEFAULT NULL,
  `pdf_path` varchar(500) DEFAULT NULL,
  `status` enum('draft','issued','paid','cancelled') NOT NULL,
  `issued_at` date NOT NULL,
  `due_at` date DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `languages`
--

CREATE TABLE `languages` (
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `locale` varchar(20) NOT NULL,
  `is_rtl` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `madd_companies`
--

CREATE TABLE `madd_companies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Legal entity name',
  `country_code` char(2) NOT NULL,
  `vat_number` varchar(50) NOT NULL,
  `registration_number` varchar(100) NOT NULL,
  `legal_representative` varchar(255) NOT NULL COMMENT 'Legal rep name',
  `contact_email` varchar(191) NOT NULL COMMENT 'Official email',
  `contact_phone` varchar(30) DEFAULT NULL COMMENT 'Official phone',
  `tax_office` varchar(255) DEFAULT NULL COMMENT 'Tax authority details',
  `address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Full legal address' CHECK (json_valid(`address`)),
  `bank_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Company bank info' CHECK (json_valid(`bank_details`)),
  `logo_url` varchar(500) DEFAULT NULL COMMENT 'For invoices',
  `invoice_prefix` varchar(20) NOT NULL COMMENT 'Invoice number prefix',
  `fiscal_year_start` date NOT NULL DEFAULT '2024-01-01' COMMENT 'Accounting year start',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_04_04_000001_create_roles_table', 1),
(5, '2026_04_04_000002_create_permissions_table', 1),
(6, '2026_04_04_000003_create_model_has_roles_table', 1),
(7, '2026_04_04_000004_create_social_accounts_table', 1),
(8, '2026_04_04_000005_create_vendor_plans_table', 1),
(9, '2026_04_04_000006_create_vendors_table', 2),
(10, '2026_04_04_000007_create_madd_companies_table', 2),
(11, '2026_04_04_000008_create_country_configs_table', 2),
(12, '2026_04_04_000009_create_currencies_table', 2),
(13, '2026_04_04_000010_create_languages_table', 2),
(14, '2026_04_04_000011_create_themes_table', 2),
(15, '2026_04_04_000012_create_sales_policies_table', 2),
(16, '2026_04_04_000013_create_domains_table', 3),
(17, '2026_04_04_000014_create_vendor_stores_table', 4),
(18, '2026_04_04_000015_create_vendor_banking_table', 5),
(19, '2026_04_04_000016_create_vendor_users_table', 6),
(20, '2026_04_04_000017_create_couriers_table', 6),
(21, '2026_04_04_000018_create_vendor_products_table', 7),
(22, '2026_04_04_000019_create_orders_table', 8),
(23, '2026_04_04_000020_create_order_items_table', 8),
(24, '2026_04_04_000021_create_coupons_table', 9),
(25, '2026_04_04_000022_create_settlements_table', 10),
(26, '2026_04_04_000023_create_transactions_table', 11),
(27, '2026_04_04_000024_create_invoices_table', 11),
(28, '2026_04_04_000025_create_vendor_wallets_table', 11),
(29, '2026_04_04_000026_create_notifications_table', 11),
(30, '2026_04_04_000027_create_reviews_table', 12),
(31, '2026_04_04_000028_create_mlm_agents_table', 12),
(32, '2026_04_04_000029_create_mlm_commissions_table', 12),
(33, '2026_04_04_000030_create_returns_table', 12),
(34, '2026_04_04_000031_create_return_items_table', 12),
(35, '2026_04_04_000032_create_product_drafts_table', 12),
(36, '2026_04_04_000033_create_product_approvals_table', 13),
(37, '2026_04_04_000034_create_product_sharing_table', 14),
(38, '2026_04_04_000035_create_order_status_history_table', 15),
(39, '2026_04_04_000036_create_order_tracking_table', 15),
(40, '2026_04_04_000037_create_payment_transactions_table', 15),
(41, '2026_04_04_000038_create_refunds_table', 16),
(42, '2026_04_04_000039_create_vendor_payouts_table', 17),
(43, '2026_04_04_000040_create_api_keys_table', 18),
(44, '2026_04_04_000041_create_webhook_endpoints_table', 19),
(45, '2026_04_04_000042_create_webhook_deliveries_table', 19),
(46, '2026_04_04_000043_create_activity_logs_table', 20),
(47, '2026_04_04_000044_create_notification_templates_table', 20),
(48, '2026_04_04_000045_create_system_settings_table', 20);

-- --------------------------------------------------------

--
-- Table structure for table `mlm_agents`
--

CREATE TABLE `mlm_agents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `level` tinyint(4) NOT NULL DEFAULT 1,
  `territory_type` enum('country','region','city') NOT NULL,
  `territory_code` varchar(50) NOT NULL,
  `commission_rate` decimal(5,2) NOT NULL,
  `total_vendors_recruited` int(11) NOT NULL DEFAULT 0,
  `total_commissions_earned` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `rank` varchar(50) NOT NULL DEFAULT 'starter',
  `phone` varchar(30) DEFAULT NULL,
  `kyc_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `status` enum('active','inactive','suspended') NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mlm_commissions`
--

CREATE TABLE `mlm_commissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `settlement_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_type` enum('vendor_signup','vendor_sale') NOT NULL,
  `source_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(12,4) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `status` enum('pending','approved','paid','rejected') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `calculation_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`calculation_snapshot`)),
  `rejection_reason` text DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_roles`
--

CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` char(36) NOT NULL,
  `assigned_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` varchar(36) NOT NULL,
  `channel` enum('email','sms','push','in_app') NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `priority` enum('high','medium','low') NOT NULL DEFAULT 'medium',
  `action_url` varchar(500) DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_templates`
--

CREATE TABLE `notification_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`subject`)),
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`body`)),
  `channels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`channels`)),
  `variables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`variables`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `magento_order_id` int(10) UNSIGNED NOT NULL,
  `magento_order_increment_id` varchar(50) NOT NULL,
  `parent_order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `vendor_id` char(36) NOT NULL,
  `vendor_store_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` char(36) DEFAULT NULL,
  `claimed_by_user_id` char(36) DEFAULT NULL,
  `customer_email` varchar(191) NOT NULL,
  `customer_firstname` varchar(100) DEFAULT NULL,
  `customer_lastname` varchar(100) DEFAULT NULL,
  `customer_ip` varchar(45) DEFAULT NULL,
  `guest_token` varchar(255) DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `status` varchar(50) NOT NULL,
  `payment_status` enum('pending','paid','refunded','chargeback','failed') NOT NULL DEFAULT 'pending',
  `fulfillment_status` enum('pending','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `currency_code` char(3) NOT NULL,
  `currency_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `subtotal` decimal(12,4) NOT NULL,
  `tax_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `shipping_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `grand_total` decimal(12,4) NOT NULL,
  `commission_amount` decimal(12,4) DEFAULT NULL,
  `commission_rate` decimal(5,2) DEFAULT NULL,
  `vendor_payout_amount` decimal(12,4) DEFAULT NULL,
  `payment_method` varchar(100) NOT NULL,
  `payment_fee` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `shipping_method` varchar(100) DEFAULT NULL,
  `carrier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `coupon_code` varchar(50) DEFAULT NULL,
  `coupon_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source` enum('web','mobile','marketplace','erp','pos') NOT NULL DEFAULT 'web',
  `shipping_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`shipping_address`)),
  `billing_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`billing_address`)),
  `customer_note` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `shipped_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `settlement_id` bigint(20) UNSIGNED DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sync_status` enum('pending','synced','failed') NOT NULL DEFAULT 'pending',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `magento_item_id` int(11) DEFAULT NULL,
  `vendor_product_id` char(36) DEFAULT NULL,
  `magento_product_id` int(11) NOT NULL,
  `magento_sku` varchar(255) NOT NULL,
  `vendor_sku` varchar(100) DEFAULT NULL,
  `product_sku` varchar(255) NOT NULL,
  `product_name` varchar(500) NOT NULL,
  `product_type` varchar(50) NOT NULL DEFAULT 'simple',
  `weight` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `qty_ordered` decimal(10,4) NOT NULL,
  `qty_shipped` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `qty_refunded` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `fulfilled_qty` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `price` decimal(12,4) NOT NULL,
  `tax_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `row_total` decimal(12,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_tracking`
--

CREATE TABLE `order_tracking` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `carrier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `tracking_number` varchar(255) NOT NULL,
  `tracking_url` varchar(500) DEFAULT NULL,
  `label_url` varchar(500) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `estimated_delivery` date DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `last_update` timestamp NULL DEFAULT NULL,
  `tracking_events` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tracking_events`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(191) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_transactions`
--

CREATE TABLE `payment_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `gateway` varchar(50) NOT NULL,
  `gateway_transaction_id` varchar(255) NOT NULL,
  `parent_transaction_id` varchar(255) DEFAULT NULL,
  `transaction_type` enum('authorize','capture','sale','refund','void') NOT NULL,
  `amount` decimal(12,4) NOT NULL,
  `currency` char(3) NOT NULL,
  `status` enum('pending','authorized','captured','failed','refunded','voided') NOT NULL DEFAULT 'pending',
  `payment_method_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payment_method_details`)),
  `customer_ip` varchar(45) DEFAULT NULL,
  `card_last4` varchar(4) DEFAULT NULL,
  `card_brand` varchar(50) DEFAULT NULL,
  `fraud_status` enum('clean','suspicious','fraud') NOT NULL DEFAULT 'clean',
  `fraud_score` decimal(3,2) DEFAULT NULL,
  `gateway_request` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gateway_request`)),
  `gateway_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gateway_response`)),
  `error_message` text DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `module` varchar(100) NOT NULL,
  `guard_name` varchar(100) NOT NULL DEFAULT 'web',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_approvals`
--

CREATE TABLE `product_approvals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `product_draft_id` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `approval_type` enum('new','update','restore','delete') NOT NULL DEFAULT 'new',
  `status` enum('pending','approved','rejected','needs_modification') NOT NULL DEFAULT 'pending',
  `submitted_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`submitted_data`)),
  `admin_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by` char(36) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_drafts`
--

CREATE TABLE `product_drafts` (
  `id` char(36) NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `vendor_store_id` bigint(20) UNSIGNED NOT NULL,
  `vendor_product_id` char(36) DEFAULT NULL,
  `parent_draft_id` char(36) DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `sku` varchar(255) NOT NULL,
  `name` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `short_description` text DEFAULT NULL,
  `price` decimal(12,4) NOT NULL,
  `special_price` decimal(12,4) DEFAULT NULL,
  `special_price_from` timestamp NULL DEFAULT NULL,
  `special_price_to` timestamp NULL DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `weight` decimal(10,4) DEFAULT NULL,
  `status` enum('draft','pending','approved','rejected','needs_modification') NOT NULL DEFAULT 'draft',
  `product_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`product_data`)),
  `media_gallery` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`media_gallery`)),
  `categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`categories`)),
  `attributes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attributes`)),
  `seo_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`seo_data`)),
  `review_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `auto_approve` tinyint(1) NOT NULL DEFAULT 0,
  `scheduled_publish_at` timestamp NULL DEFAULT NULL,
  `magento_product_id` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_by` char(36) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_sharing`
--

CREATE TABLE `product_sharing` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `source_product_id` char(36) NOT NULL,
  `target_store_id` bigint(20) UNSIGNED NOT NULL,
  `sharing_type` enum('full','partial','referral') NOT NULL DEFAULT 'full',
  `commission_percentage` decimal(5,2) DEFAULT NULL,
  `markup_percentage` decimal(5,2) DEFAULT NULL,
  `status` enum('active','inactive','pending') NOT NULL DEFAULT 'pending',
  `approved_by` char(36) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `refunds`
--

CREATE TABLE `refunds` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `payment_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `refund_amount` decimal(12,4) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','processed','failed') NOT NULL DEFAULT 'pending',
  `gateway_refund_id` varchar(255) DEFAULT NULL,
  `gateway_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gateway_response`)),
  `processed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `rma_number` varchar(50) NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `courier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('requested','approved','rejected','shipped','received','refunded','cancelled') NOT NULL DEFAULT 'requested',
  `return_type` enum('full','partial','exchange','warranty','damaged','wrong_item') NOT NULL DEFAULT 'full',
  `reason` enum('defective','wrong_size','wrong_color','not_as_described','damaged_in_shipping','no_longer_needed','better_price','other') DEFAULT NULL,
  `customer_notes` text DEFAULT NULL,
  `vendor_notes` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `refund_amount` decimal(12,4) DEFAULT NULL,
  `restocking_fee` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `currency_code` char(3) NOT NULL DEFAULT 'EUR',
  `tracking_number` varchar(100) DEFAULT NULL,
  `return_label_url` varchar(500) DEFAULT NULL,
  `shipped_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `inspection_result` enum('pending','approved','partial_approved','rejected') NOT NULL DEFAULT 'pending',
  `inspection_notes` text DEFAULT NULL,
  `images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`images`)),
  `vendor_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`vendor_images`)),
  `refunded_at` timestamp NULL DEFAULT NULL,
  `refund_transaction_id` varchar(255) DEFAULT NULL,
  `refund_method` enum('original_payment','store_credit','bank_transfer','manual') DEFAULT NULL,
  `quality_check_passed` tinyint(1) NOT NULL DEFAULT 0,
  `disposition` enum('restock','refurbish','donate','recycle','destroy') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `return_items`
--

CREATE TABLE `return_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `return_id` bigint(20) UNSIGNED NOT NULL,
  `order_item_id` bigint(20) UNSIGNED NOT NULL,
  `vendor_product_id` char(36) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `refund_amount` decimal(12,4) DEFAULT NULL,
  `restocking_fee` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `condition` enum('new','like_new','used_good','used_fair','damaged','defective') NOT NULL DEFAULT 'new',
  `reason` enum('defective','wrong_size','wrong_color','not_as_described','damaged_in_shipping','no_longer_needed','better_price','wrong_item_shipped','other') DEFAULT NULL,
  `reason_notes` text DEFAULT NULL,
  `inspection_status` enum('pending','approved','rejected','partial') NOT NULL DEFAULT 'pending',
  `inspection_notes` text DEFAULT NULL,
  `customer_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`customer_images`)),
  `inspection_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`inspection_images`)),
  `resolution` enum('refund','exchange','store_credit','repair','replacement','none') DEFAULT NULL,
  `exchange_product_id` char(36) DEFAULT NULL,
  `exchange_sku` varchar(255) DEFAULT NULL,
  `disposition` enum('restock','refurbish','donate','recycle','destroy') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `magento_review_id` int(11) DEFAULT NULL,
  `magento_product_id` int(11) NOT NULL,
  `customer_id` char(36) DEFAULT NULL,
  `vendor_id` char(36) DEFAULT NULL,
  `vendor_store_id` bigint(20) UNSIGNED NOT NULL,
  `vendor_product_id` char(36) DEFAULT NULL,
  `moderated_by` char(36) DEFAULT NULL,
  `rating` tinyint(4) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`images`)),
  `language_code` varchar(10) NOT NULL DEFAULT 'en',
  `verified_purchased` tinyint(1) NOT NULL DEFAULT 0,
  `helpful_count` int(11) NOT NULL DEFAULT 0,
  `reported_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','approved','rejected','flagged') NOT NULL DEFAULT 'pending',
  `rejected_reason` text DEFAULT NULL,
  `moderated_at` timestamp NULL DEFAULT NULL,
  `vendor_response` text DEFAULT NULL,
  `vendor_response_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `guard_name` varchar(100) NOT NULL DEFAULT 'web',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `level` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_policies`
--

CREATE TABLE `sales_policies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `country_code` char(2) NOT NULL,
  `name` varchar(100) NOT NULL,
  `payment_methods` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payment_methods`)),
  `shipping_methods` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`shipping_methods`)),
  `allowed_currencies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_currencies`)),
  `tax_class` varchar(50) NOT NULL,
  `min_order_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `guest_checkout_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `return_window_days` int(11) NOT NULL DEFAULT 14,
  `terms_url` varchar(500) DEFAULT NULL,
  `privacy_policy_url` varchar(500) DEFAULT NULL,
  `withdrawal_right_text` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settlements`
--

CREATE TABLE `settlements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `payable_type` varchar(255) NOT NULL,
  `payable_id` bigint(20) UNSIGNED NOT NULL,
  `madd_company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `vendor_id` char(36) DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `period_days` int(11) NOT NULL DEFAULT 30,
  `gross_sales` decimal(14,4) NOT NULL,
  `total_refunds` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_commissions` decimal(14,4) NOT NULL,
  `total_shipping_fees` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_tax_collected` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `adjustment_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `gateway_fees` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `net_payout` decimal(14,4) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `status` enum('pending','approved','paid','disputed') NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `statement_pdf_path` varchar(500) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `social_accounts`
--

CREATE TABLE `social_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `provider` varchar(50) NOT NULL,
  `provider_id` varchar(255) NOT NULL,
  `provider_email` varchar(190) DEFAULT NULL,
  `access_token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_name` varchar(255) NOT NULL,
  `key_name` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` enum('string','integer','boolean','json','array') NOT NULL DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `themes`
--

CREATE TABLE `themes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `preview_url` varchar(500) DEFAULT NULL,
  `screenshot_url` varchar(500) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `config_schema` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_schema`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_premium` tinyint(1) NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `settlement_id` bigint(20) UNSIGNED DEFAULT NULL,
  `order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `vendor_id` char(36) DEFAULT NULL,
  `initiated_by` char(36) DEFAULT NULL,
  `payable_type` varchar(255) NOT NULL,
  `payable_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('sale','refund','commission','adjustment','payout') NOT NULL,
  `status` enum('pending','completed','failed','reversed') NOT NULL,
  `amount` decimal(12,4) NOT NULL,
  `gateway_fee` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `currency_code` char(3) NOT NULL,
  `balance_after` decimal(14,4) DEFAULT NULL,
  `gateway` varchar(50) DEFAULT NULL,
  `gateway_transaction_id` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `status` enum('active','suspended','pending','banned') NOT NULL DEFAULT 'active',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `magento_customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `locale` varchar(10) NOT NULL DEFAULT 'en',
  `timezone` varchar(50) NOT NULL DEFAULT 'UTC',
  `user_signin_method` varchar(255) DEFAULT NULL,
  `gdpr_consent_at` timestamp NULL DEFAULT NULL,
  `marketing_opt_in` tinyint(1) DEFAULT NULL,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `country_code` char(2) NOT NULL,
  `kyc_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

CREATE TABLE `vendors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `company_slug` varchar(255) NOT NULL,
  `legal_name` varchar(255) DEFAULT NULL,
  `trading_name` varchar(255) DEFAULT NULL,
  `vat_number` varchar(50) DEFAULT NULL,
  `registration_number` varchar(100) DEFAULT NULL,
  `contact_email` varchar(191) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `country_code` char(2) NOT NULL,
  `address_line1` varchar(255) NOT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) NOT NULL,
  `postal_code` varchar(20) NOT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `banner_url` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `plan_id` bigint(20) UNSIGNED DEFAULT NULL,
  `plan_starts_at` timestamp NULL DEFAULT NULL,
  `plan_ends_at` timestamp NULL DEFAULT NULL,
  `commission_rate` decimal(5,2) DEFAULT NULL,
  `commission_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `commission_override` decimal(5,2) DEFAULT NULL,
  `status` enum('pending','active','suspended','terminated') NOT NULL DEFAULT 'pending',
  `onboarding_step` tinyint(4) NOT NULL DEFAULT 1,
  `total_sales` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_commission_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_earned` decimal(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `pending_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `rating_average` decimal(3,2) NOT NULL DEFAULT 0.00,
  `total_reviews` int(11) NOT NULL DEFAULT 0,
  `mlm_referrer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `magento_website_id` int(11) DEFAULT NULL,
  `kyc_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `verification_documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`verification_documents`)),
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `timezone` varchar(50) NOT NULL DEFAULT 'UTC',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_banking`
--

CREATE TABLE `vendor_banking` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `account_type` enum('bank','paypal','stripe') NOT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `iban` varchar(34) DEFAULT NULL,
  `bic_swift` varchar(11) DEFAULT NULL,
  `paypal_email` varchar(255) DEFAULT NULL,
  `stripe_account_id` varchar(255) DEFAULT NULL,
  `account_holder_name` varchar(255) NOT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'EUR',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_doc_path` varchar(500) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_payouts`
--

CREATE TABLE `vendor_payouts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `settlement_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount` decimal(12,4) NOT NULL,
  `currency` char(3) NOT NULL,
  `payout_method` enum('paypal','stripe','bank_transfer','manual') NOT NULL,
  `payout_account_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payout_account_details`)),
  `status` enum('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `gateway_payout_id` varchar(255) DEFAULT NULL,
  `gateway_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gateway_response`)),
  `processed_by` char(36) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_plans`
--

CREATE TABLE `vendor_plans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price_monthly` decimal(10,2) NOT NULL,
  `price_yearly` decimal(10,2) NOT NULL,
  `setup_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `transaction_fee_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `transaction_fee_fixed` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(5,2) NOT NULL,
  `max_products` int(11) NOT NULL DEFAULT 100,
  `max_stores` int(11) NOT NULL DEFAULT 1,
  `max_users` int(11) NOT NULL DEFAULT 1,
  `bandwidth_limit_mb` int(11) DEFAULT NULL,
  `storage_limit_mb` int(11) DEFAULT NULL,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `trial_period_days` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_products`
--

CREATE TABLE `vendor_products` (
  `id` char(36) NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `vendor_store_id` bigint(20) UNSIGNED NOT NULL,
  `magento_product_id` int(10) UNSIGNED NOT NULL,
  `magento_sku` varchar(255) NOT NULL,
  `sku` varchar(255) NOT NULL,
  `name` varchar(500) DEFAULT NULL,
  `type_id` varchar(32) NOT NULL DEFAULT 'simple',
  `attribute_set_id` int(10) UNSIGNED NOT NULL DEFAULT 4,
  `price` decimal(12,4) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `sync_status` enum('synced','pending','failed','deleted') NOT NULL DEFAULT 'synced',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `sync_errors` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_stores`
--

CREATE TABLE `vendor_stores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `store_name` varchar(255) NOT NULL,
  `store_slug` varchar(255) NOT NULL,
  `country_code` char(2) NOT NULL,
  `language_code` varchar(10) NOT NULL DEFAULT 'en',
  `currency_code` char(3) NOT NULL DEFAULT 'EUR',
  `timezone` varchar(100) NOT NULL DEFAULT 'UTC',
  `domain_id` bigint(20) UNSIGNED DEFAULT NULL,
  `subdomain` varchar(100) DEFAULT NULL,
  `magento_store_id` int(11) DEFAULT NULL,
  `magento_store_group_id` int(11) DEFAULT NULL,
  `magento_website_id` int(11) DEFAULT NULL,
  `theme_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('inactive','active','suspended','maintenance') NOT NULL DEFAULT 'inactive',
  `sales_policy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `favicon_url` varchar(500) DEFAULT NULL,
  `banner_url` varchar(500) DEFAULT NULL,
  `primary_color` varchar(7) DEFAULT NULL,
  `secondary_color` varchar(7) DEFAULT NULL,
  `contact_email` varchar(191) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `seo_meta_title` varchar(255) DEFAULT NULL,
  `seo_meta_description` text DEFAULT NULL,
  `seo_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`seo_settings`)),
  `payment_methods` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payment_methods`)),
  `shipping_methods` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`shipping_methods`)),
  `tax_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tax_settings`)),
  `social_links` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`social_links`)),
  `google_analytics_id` varchar(50) DEFAULT NULL,
  `facebook_pixel_id` varchar(50) DEFAULT NULL,
  `custom_css` text DEFAULT NULL,
  `custom_js` text DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`address`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `activated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_users`
--

CREATE TABLE `vendor_users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `invited_by` char(36) DEFAULT NULL,
  `role` enum('admin','orders','products','marketing','seo') NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `invited_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `notification_prefs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`notification_prefs`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_wallets`
--

CREATE TABLE `vendor_wallets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) NOT NULL,
  `balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `reserved_balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `currency_code` char(3) NOT NULL DEFAULT 'EUR',
  `last_transaction_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `webhook_deliveries`
--

CREATE TABLE `webhook_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `endpoint_id` bigint(20) UNSIGNED NOT NULL,
  `event_type` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `response_status` int(11) DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `attempt_count` int(11) NOT NULL DEFAULT 1,
  `status` enum('pending','success','failed','retry') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `webhook_endpoints`
--

CREATE TABLE `webhook_endpoints` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `vendor_id` char(36) DEFAULT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(255) NOT NULL,
  `events` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`events`)),
  `format` enum('json','xml') NOT NULL DEFAULT 'json',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_delivery_at` timestamp NULL DEFAULT NULL,
  `last_success_at` timestamp NULL DEFAULT NULL,
  `failure_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `activity_logs_uuid_unique` (`uuid`),
  ADD KEY `activity_logs_user_id_index` (`user_id`),
  ADD KEY `activity_logs_vendor_id_index` (`vendor_id`),
  ADD KEY `activity_logs_store_id_index` (`store_id`),
  ADD KEY `activity_logs_action_index` (`action`),
  ADD KEY `activity_logs_module_index` (`module`),
  ADD KEY `activity_logs_created_at_index` (`created_at`);

--
-- Indexes for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_keys_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `api_keys_key_hash_unique` (`key_hash`),
  ADD KEY `api_keys_vendor_id_index` (`vendor_id`),
  ADD KEY `api_keys_is_active_index` (`is_active`),
  ADD KEY `api_keys_key_hash_index` (`key_hash`),
  ADD KEY `api_keys_created_by_index` (`created_by`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `country_configs`
--
ALTER TABLE `country_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `country_configs_code_unique` (`code`),
  ADD KEY `country_configs_madd_company_id_foreign` (`madd_company_id`),
  ADD KEY `country_configs_deleted_at_index` (`deleted_at`);

--
-- Indexes for table `coupons`
--
ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `coupons_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `coupons_code_unique` (`code`),
  ADD KEY `coupons_code_index` (`code`),
  ADD KEY `coupons_vendor_id_index` (`vendor_id`),
  ADD KEY `coupons_is_active_index` (`is_active`),
  ADD KEY `coupons_type_index` (`type`);

--
-- Indexes for table `couriers`
--
ALTER TABLE `couriers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `couriers_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `couriers_code_unique` (`code`),
  ADD KEY `couriers_code_index` (`code`),
  ADD KEY `couriers_is_active_index` (`is_active`);

--
-- Indexes for table `currencies`
--
ALTER TABLE `currencies`
  ADD PRIMARY KEY (`code`);

--
-- Indexes for table `domains`
--
ALTER TABLE `domains`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `domains_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `domains_domain_unique` (`domain`),
  ADD KEY `domains_vendor_store_id_index` (`vendor_store_id`),
  ADD KEY `domains_domain_index` (`domain`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoices_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `invoices_invoice_number_unique` (`invoice_number`),
  ADD KEY `invoices_invoice_number_index` (`invoice_number`),
  ADD KEY `invoices_status_index` (`status`),
  ADD KEY `invoices_vendor_id_index` (`vendor_id`),
  ADD KEY `invoices_settlement_id_index` (`settlement_id`),
  ADD KEY `invoices_order_id_index` (`order_id`),
  ADD KEY `invoices_credit_note_id_index` (`credit_note_id`),
  ADD KEY `invoices_madd_company_id_index` (`madd_company_id`),
  ADD KEY `invoices_payable_type_index` (`payable_type`),
  ADD KEY `invoices_payable_id_index` (`payable_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `languages`
--
ALTER TABLE `languages`
  ADD PRIMARY KEY (`code`);

--
-- Indexes for table `madd_companies`
--
ALTER TABLE `madd_companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `madd_companies_country_code_index` (`country_code`),
  ADD KEY `madd_companies_deleted_at_index` (`deleted_at`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mlm_agents`
--
ALTER TABLE `mlm_agents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mlm_agents_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `mlm_agents_user_id_unique` (`user_id`),
  ADD KEY `mlm_agents_user_id_index` (`user_id`),
  ADD KEY `mlm_agents_parent_id_index` (`parent_id`),
  ADD KEY `mlm_agents_level_index` (`level`);

--
-- Indexes for table `mlm_commissions`
--
ALTER TABLE `mlm_commissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mlm_commissions_uuid_unique` (`uuid`),
  ADD KEY `mlm_commissions_agent_id_index` (`agent_id`),
  ADD KEY `mlm_commissions_settlement_id_index` (`settlement_id`),
  ADD KEY `mlm_commissions_source_type_source_id_index` (`source_type`,`source_id`),
  ADD KEY `mlm_commissions_status_index` (`status`);

--
-- Indexes for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD UNIQUE KEY `model_has_roles_unique` (`role_id`,`model_id`,`model_type`),
  ADD KEY `model_has_roles_model_type_model_id_index` (`model_type`,`model_id`),
  ADD KEY `model_has_roles_assigned_by_foreign` (`assigned_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`),
  ADD KEY `notifications_type_index` (`type`),
  ADD KEY `notifications_notifiable_id_index` (`notifiable_id`);

--
-- Indexes for table `notification_templates`
--
ALTER TABLE `notification_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `notification_templates_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `notification_templates_code_unique` (`code`),
  ADD KEY `notification_templates_code_index` (`code`),
  ADD KEY `notification_templates_is_active_index` (`is_active`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `orders_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `orders_magento_order_id_unique` (`magento_order_id`),
  ADD UNIQUE KEY `orders_magento_order_increment_id_unique` (`magento_order_increment_id`),
  ADD KEY `orders_status_index` (`status`),
  ADD KEY `orders_payment_status_index` (`payment_status`),
  ADD KEY `orders_fulfillment_status_index` (`fulfillment_status`),
  ADD KEY `orders_vendor_id_index` (`vendor_id`),
  ADD KEY `orders_vendor_store_id_index` (`vendor_store_id`),
  ADD KEY `orders_customer_id_index` (`customer_id`),
  ADD KEY `orders_claimed_by_user_id_index` (`claimed_by_user_id`),
  ADD KEY `orders_magento_order_id_index` (`magento_order_id`),
  ADD KEY `orders_magento_order_increment_id_index` (`magento_order_increment_id`),
  ADD KEY `orders_guest_token_index` (`guest_token`),
  ADD KEY `orders_coupon_id_index` (`coupon_id`),
  ADD KEY `orders_settlement_id_index` (`settlement_id`),
  ADD KEY `orders_created_at_index` (`created_at`),
  ADD KEY `orders_synced_at_index` (`synced_at`),
  ADD KEY `orders_vendor_status_created` (`vendor_id`,`status`,`created_at`),
  ADD KEY `orders_customer_created` (`customer_id`,`created_at`),
  ADD KEY `orders_payment_created` (`payment_status`,`created_at`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_items_uuid_unique` (`uuid`),
  ADD KEY `order_items_order_id_index` (`order_id`),
  ADD KEY `order_items_vendor_product_id_index` (`vendor_product_id`),
  ADD KEY `order_items_magento_product_id_index` (`magento_product_id`);

--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_status_history_uuid_unique` (`uuid`),
  ADD KEY `order_status_history_changed_by_foreign` (`changed_by`),
  ADD KEY `order_status_history_order_id_index` (`order_id`),
  ADD KEY `order_status_history_status_index` (`status`),
  ADD KEY `order_status_history_created_at_index` (`created_at`);

--
-- Indexes for table `order_tracking`
--
ALTER TABLE `order_tracking`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_tracking_uuid_unique` (`uuid`),
  ADD KEY `order_tracking_carrier_id_foreign` (`carrier_id`),
  ADD KEY `order_tracking_order_id_index` (`order_id`),
  ADD KEY `order_tracking_tracking_number_index` (`tracking_number`),
  ADD KEY `order_tracking_status_index` (`status`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_transactions_gateway_gateway_transaction_id_unique` (`gateway`,`gateway_transaction_id`),
  ADD UNIQUE KEY `payment_transactions_uuid_unique` (`uuid`),
  ADD KEY `payment_transactions_order_id_index` (`order_id`),
  ADD KEY `payment_transactions_status_index` (`status`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `permissions_name_unique` (`name`),
  ADD KEY `permissions_module_index` (`module`);

--
-- Indexes for table `product_approvals`
--
ALTER TABLE `product_approvals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_approvals_uuid_unique` (`uuid`),
  ADD KEY `product_approvals_product_draft_id_index` (`product_draft_id`),
  ADD KEY `product_approvals_vendor_id_index` (`vendor_id`),
  ADD KEY `product_approvals_vendor_id_status_index` (`vendor_id`,`status`),
  ADD KEY `product_approvals_status_index` (`status`);

--
-- Indexes for table `product_drafts`
--
ALTER TABLE `product_drafts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_drafts_uuid_unique` (`uuid`),
  ADD KEY `product_drafts_vendor_id_status_index` (`vendor_id`,`status`),
  ADD KEY `product_drafts_sku_index` (`sku`),
  ADD KEY `product_drafts_status_index` (`status`),
  ADD KEY `product_drafts_vendor_store_id_index` (`vendor_store_id`);

--
-- Indexes for table `product_sharing`
--
ALTER TABLE `product_sharing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_sharing_unique` (`source_product_id`,`target_store_id`),
  ADD UNIQUE KEY `product_sharing_uuid_unique` (`uuid`),
  ADD KEY `product_sharing_source_product_id_index` (`source_product_id`),
  ADD KEY `product_sharing_target_store_id_index` (`target_store_id`),
  ADD KEY `product_sharing_status_index` (`status`),
  ADD KEY `product_sharing_approved_by_index` (`approved_by`);

--
-- Indexes for table `refunds`
--
ALTER TABLE `refunds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `refunds_uuid_unique` (`uuid`),
  ADD KEY `refunds_payment_transaction_id_foreign` (`payment_transaction_id`),
  ADD KEY `refunds_processed_by_foreign` (`processed_by`),
  ADD KEY `refunds_order_id_index` (`order_id`),
  ADD KEY `refunds_status_index` (`status`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `returns_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `returns_rma_number_unique` (`rma_number`),
  ADD KEY `returns_order_id_status_index` (`order_id`,`status`),
  ADD KEY `returns_customer_id_status_index` (`customer_id`,`status`),
  ADD KEY `returns_vendor_id_status_index` (`vendor_id`,`status`),
  ADD KEY `returns_courier_id_index` (`courier_id`),
  ADD KEY `returns_rma_number_index` (`rma_number`),
  ADD KEY `returns_status_index` (`status`);

--
-- Indexes for table `return_items`
--
ALTER TABLE `return_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_items_uuid_unique` (`uuid`),
  ADD KEY `return_items_return_id_index` (`return_id`),
  ADD KEY `return_items_order_item_id_index` (`order_item_id`),
  ADD KEY `return_items_vendor_product_id_index` (`vendor_product_id`),
  ADD KEY `return_items_inspection_status_index` (`inspection_status`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reviews_uuid_unique` (`uuid`),
  ADD KEY `reviews_magento_review_id_index` (`magento_review_id`),
  ADD KEY `reviews_magento_product_id_index` (`magento_product_id`),
  ADD KEY `reviews_customer_id_index` (`customer_id`),
  ADD KEY `reviews_vendor_id_index` (`vendor_id`),
  ADD KEY `reviews_vendor_store_id_index` (`vendor_store_id`),
  ADD KEY `reviews_vendor_product_id_index` (`vendor_product_id`),
  ADD KEY `reviews_status_index` (`status`),
  ADD KEY `reviews_rating_index` (`rating`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_unique` (`name`);

--
-- Indexes for table `sales_policies`
--
ALTER TABLE `sales_policies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sales_policies_country_code_index` (`country_code`),
  ADD KEY `sales_policies_deleted_at_index` (`deleted_at`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `settlements`
--
ALTER TABLE `settlements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `settlements_uuid_unique` (`uuid`),
  ADD KEY `settlements_payable_type_payable_id_index` (`payable_type`,`payable_id`),
  ADD KEY `settlements_vendor_id_index` (`vendor_id`),
  ADD KEY `settlements_approved_by_index` (`approved_by`),
  ADD KEY `settlements_madd_company_id_index` (`madd_company_id`),
  ADD KEY `settlements_vendor_id_period_start_period_end_index` (`vendor_id`,`period_start`,`period_end`),
  ADD KEY `settlements_status_index` (`status`),
  ADD KEY `settlements_payable_type_index` (`payable_type`),
  ADD KEY `settlements_payable_id_index` (`payable_id`);

--
-- Indexes for table `social_accounts`
--
ALTER TABLE `social_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `social_accounts_provider_provider_id_unique` (`provider`,`provider_id`),
  ADD UNIQUE KEY `social_accounts_uuid_unique` (`uuid`),
  ADD KEY `social_accounts_user_id_index` (`user_id`),
  ADD KEY `social_accounts_provider_index` (`provider`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `system_settings_group_name_key_name_unique` (`group_name`,`key_name`),
  ADD KEY `system_settings_group_name_index` (`group_name`);

--
-- Indexes for table `themes`
--
ALTER TABLE `themes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `themes_slug_unique` (`slug`),
  ADD KEY `themes_deleted_at_index` (`deleted_at`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transactions_uuid_unique` (`uuid`),
  ADD KEY `transactions_payable_type_payable_id_index` (`payable_type`,`payable_id`),
  ADD KEY `transactions_type_status_index` (`type`,`status`),
  ADD KEY `transactions_settlement_id_index` (`settlement_id`),
  ADD KEY `transactions_order_id_index` (`order_id`),
  ADD KEY `transactions_vendor_id_index` (`vendor_id`),
  ADD KEY `transactions_initiated_by_index` (`initiated_by`),
  ADD KEY `transactions_payable_type_index` (`payable_type`),
  ADD KEY `transactions_payable_id_index` (`payable_id`),
  ADD KEY `transactions_gateway_transaction_id_index` (`gateway_transaction_id`),
  ADD KEY `transactions_reference_index` (`reference`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD KEY `users_magento_customer_id_index` (`magento_customer_id`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendors_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `vendors_company_slug_unique` (`company_slug`),
  ADD KEY `vendors_user_id_foreign` (`user_id`),
  ADD KEY `vendors_plan_id_foreign` (`plan_id`),
  ADD KEY `vendors_mlm_referrer_id_foreign` (`mlm_referrer_id`),
  ADD KEY `vendors_approved_by_foreign` (`approved_by`),
  ADD KEY `vendors_status_country_code_index` (`status`,`country_code`),
  ADD KEY `vendors_created_at_index` (`created_at`),
  ADD KEY `vendors_company_slug_index` (`company_slug`),
  ADD KEY `vendors_vat_number_index` (`vat_number`),
  ADD KEY `vendors_country_code_index` (`country_code`),
  ADD KEY `vendors_magento_website_id_index` (`magento_website_id`);

--
-- Indexes for table `vendor_banking`
--
ALTER TABLE `vendor_banking`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_banking_uuid_unique` (`uuid`),
  ADD KEY `vendor_banking_vendor_id_index` (`vendor_id`),
  ADD KEY `vendor_banking_is_primary_index` (`is_primary`);

--
-- Indexes for table `vendor_payouts`
--
ALTER TABLE `vendor_payouts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_payouts_uuid_unique` (`uuid`),
  ADD KEY `vendor_payouts_vendor_id_index` (`vendor_id`),
  ADD KEY `vendor_payouts_settlement_id_index` (`settlement_id`),
  ADD KEY `vendor_payouts_status_index` (`status`),
  ADD KEY `vendor_payouts_gateway_payout_id_index` (`gateway_payout_id`);

--
-- Indexes for table `vendor_plans`
--
ALTER TABLE `vendor_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_plans_slug_unique` (`slug`),
  ADD KEY `vendor_plans_is_active_index` (`is_active`),
  ADD KEY `vendor_plans_is_default_index` (`is_default`);

--
-- Indexes for table `vendor_products`
--
ALTER TABLE `vendor_products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_products_vendor_magento_unique` (`vendor_id`,`magento_product_id`),
  ADD UNIQUE KEY `vendor_products_vendor_sku_unique` (`vendor_id`,`sku`),
  ADD UNIQUE KEY `vendor_products_uuid_unique` (`uuid`),
  ADD KEY `vendor_products_vendor_id_index` (`vendor_id`),
  ADD KEY `vendor_products_vendor_store_id_index` (`vendor_store_id`),
  ADD KEY `vendor_products_magento_product_id_index` (`magento_product_id`),
  ADD KEY `vendor_products_sku_index` (`sku`),
  ADD KEY `vendor_products_status_index` (`status`),
  ADD KEY `vendor_products_sync_status_index` (`sync_status`);

--
-- Indexes for table `vendor_stores`
--
ALTER TABLE `vendor_stores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_stores_vendor_id_store_slug_unique` (`vendor_id`,`store_slug`),
  ADD UNIQUE KEY `vendor_stores_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `vendor_stores_subdomain_unique` (`subdomain`),
  ADD KEY `vendor_stores_status_country_code_index` (`status`,`country_code`),
  ADD KEY `vendor_stores_created_at_index` (`created_at`),
  ADD KEY `vendor_stores_vendor_id_index` (`vendor_id`),
  ADD KEY `vendor_stores_country_code_index` (`country_code`),
  ADD KEY `vendor_stores_magento_store_id_index` (`magento_store_id`),
  ADD KEY `vendor_stores_magento_store_group_id_index` (`magento_store_group_id`),
  ADD KEY `vendor_stores_magento_website_id_index` (`magento_website_id`);

--
-- Indexes for table `vendor_users`
--
ALTER TABLE `vendor_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_users_vendor_id_user_id_unique` (`vendor_id`,`user_id`),
  ADD UNIQUE KEY `vendor_users_uuid_unique` (`uuid`),
  ADD KEY `vendor_users_user_id_index` (`user_id`),
  ADD KEY `vendor_users_vendor_id_index` (`vendor_id`),
  ADD KEY `vendor_users_role_index` (`role`);

--
-- Indexes for table `vendor_wallets`
--
ALTER TABLE `vendor_wallets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_wallets_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `vendor_wallets_vendor_id_unique` (`vendor_id`),
  ADD KEY `vendor_wallets_vendor_id_index` (`vendor_id`);

--
-- Indexes for table `webhook_deliveries`
--
ALTER TABLE `webhook_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `webhook_deliveries_uuid_unique` (`uuid`),
  ADD KEY `webhook_deliveries_endpoint_id_index` (`endpoint_id`),
  ADD KEY `webhook_deliveries_event_type_index` (`event_type`),
  ADD KEY `webhook_deliveries_status_index` (`status`);

--
-- Indexes for table `webhook_endpoints`
--
ALTER TABLE `webhook_endpoints`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `webhook_endpoints_uuid_unique` (`uuid`),
  ADD KEY `webhook_endpoints_vendor_id_index` (`vendor_id`),
  ADD KEY `webhook_endpoints_is_active_index` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_keys`
--
ALTER TABLE `api_keys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `country_configs`
--
ALTER TABLE `country_configs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `couriers`
--
ALTER TABLE `couriers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `domains`
--
ALTER TABLE `domains`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `madd_companies`
--
ALTER TABLE `madd_companies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `mlm_agents`
--
ALTER TABLE `mlm_agents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mlm_commissions`
--
ALTER TABLE `mlm_commissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_templates`
--
ALTER TABLE `notification_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_tracking`
--
ALTER TABLE `order_tracking`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_approvals`
--
ALTER TABLE `product_approvals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_sharing`
--
ALTER TABLE `product_sharing`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `refunds`
--
ALTER TABLE `refunds`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `return_items`
--
ALTER TABLE `return_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_policies`
--
ALTER TABLE `sales_policies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settlements`
--
ALTER TABLE `settlements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `social_accounts`
--
ALTER TABLE `social_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `themes`
--
ALTER TABLE `themes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_banking`
--
ALTER TABLE `vendor_banking`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_payouts`
--
ALTER TABLE `vendor_payouts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_plans`
--
ALTER TABLE `vendor_plans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_stores`
--
ALTER TABLE `vendor_stores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_users`
--
ALTER TABLE `vendor_users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_wallets`
--
ALTER TABLE `vendor_wallets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `webhook_deliveries`
--
ALTER TABLE `webhook_deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `webhook_endpoints`
--
ALTER TABLE `webhook_endpoints`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `country_configs`
--
ALTER TABLE `country_configs`
  ADD CONSTRAINT `country_configs_madd_company_id_foreign` FOREIGN KEY (`madd_company_id`) REFERENCES `madd_companies` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `model_has_roles_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_vendor_product_id_foreign` FOREIGN KEY (`vendor_product_id`) REFERENCES `vendor_products` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD CONSTRAINT `order_status_history_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `order_status_history_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_tracking`
--
ALTER TABLE `order_tracking`
  ADD CONSTRAINT `order_tracking_carrier_id_foreign` FOREIGN KEY (`carrier_id`) REFERENCES `couriers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `order_tracking_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD CONSTRAINT `payment_transactions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_sharing`
--
ALTER TABLE `product_sharing`
  ADD CONSTRAINT `product_sharing_target_store_id_foreign` FOREIGN KEY (`target_store_id`) REFERENCES `vendor_stores` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `refunds`
--
ALTER TABLE `refunds`
  ADD CONSTRAINT `refunds_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `refunds_payment_transaction_id_foreign` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `refunds_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `social_accounts`
--
ALTER TABLE `social_accounts`
  ADD CONSTRAINT `social_accounts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vendors`
--
ALTER TABLE `vendors`
  ADD CONSTRAINT `vendors_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `vendors_mlm_referrer_id_foreign` FOREIGN KEY (`mlm_referrer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `vendors_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `vendor_plans` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `vendors_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `webhook_deliveries`
--
ALTER TABLE `webhook_deliveries`
  ADD CONSTRAINT `webhook_deliveries_endpoint_id_foreign` FOREIGN KEY (`endpoint_id`) REFERENCES `webhook_endpoints` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
