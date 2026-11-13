-- Masuk Sukses Pertama Warehouse Database Schema & Complete Dummy Data
-- DB: db_msp_warehouse
-- Based on real business profile & scraped product catalog from mspgrup.co.id
-- PT Masuk Sukses Pertama - Distributor Ban Kendaraan Berat (TBR, OTR, Solid Tire & Inner Tube)
-- Single-file installer: Schema + Seed + Authentic Operational Dummy Data

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+07:00";
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `db_msp_warehouse` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `db_msp_warehouse`;

-- Drop existing tables in reverse dependency order
DROP TABLE IF EXISTS `mutations`;
DROP TABLE IF EXISTS `supplier_return_details`;
DROP TABLE IF EXISTS `supplier_returns`;
DROP TABLE IF EXISTS `purchase_order_details`;
DROP TABLE IF EXISTS `purchase_orders`;
DROP TABLE IF EXISTS `stock_adjustment_details`;
DROP TABLE IF EXISTS `stock_adjustments`;
DROP TABLE IF EXISTS `stock_opname_items`;
DROP TABLE IF EXISTS `stock_opname`;
DROP TABLE IF EXISTS `transfer_details`;
DROP TABLE IF EXISTS `transfers`;
DROP TABLE IF EXISTS `stock_out_details`;
DROP TABLE IF EXISTS `stock_out`;
DROP TABLE IF EXISTS `stock_in_details`;
DROP TABLE IF EXISTS `stock_in`;
DROP TABLE IF EXISTS `stock`;
DROP TABLE IF EXISTS `items`;
DROP TABLE IF EXISTS `locations`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `units`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `user_companies`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `companies`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 1. TABLE DEFINITIONS (DDL)
-- ============================================================================

-- Companies (Holding & Subsidiaries)
CREATE TABLE `companies` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `address` TEXT,
  `phone` VARCHAR(30),
  `email` VARCHAR(150),
  `npwp` VARCHAR(50),
  `logo` VARCHAR(255) NULL,
  `is_holding` TINYINT(1) DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Roles
CREATE TABLE `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Permissions
CREATE TABLE `permissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` VARCHAR(255)
) ENGINE=InnoDB;

-- Role Permissions
CREATE TABLE `role_permissions` (
  `role_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Users
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `verification_token` VARCHAR(100) NULL,
  `reset_token` VARCHAR(100) NULL,
  `reset_token_expires` TIMESTAMP NULL DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB;

-- User Companies (Multi-PT Access Mapping)
CREATE TABLE `user_companies` (
  `user_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `is_default` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`user_id`, `company_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Categories
CREATE TABLE `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Units
CREATE TABLE `units` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `abbreviation` VARCHAR(10) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Suppliers
CREATE TABLE `suppliers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100),
  `phone` VARCHAR(30),
  `email` VARCHAR(150),
  `address` TEXT,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Locations (Shared Warehouse Racks / Zones)
CREATE TABLE `locations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255),
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Items (Centralized Group Master Catalog; ownership is segregated per PT in stock table)
CREATE TABLE `items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `min_stock` INT UNSIGNED DEFAULT 0,
  `buy_price` DECIMAL(15,2) DEFAULT 0,
  `sell_price` DECIMAL(15,2) DEFAULT 0,
  `description` TEXT,
  `image` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`),
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`)
) ENGINE=InnoDB;

-- Stock (Segregated by item, location, AND company)
CREATE TABLE `stock` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `quantity` INT DEFAULT 0,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `item_location_company` (`item_id`, `location_id`, `company_id`),
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`)
) ENGINE=InnoDB;

-- Stock In
CREATE TABLE `stock_in` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `supplier_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- Stock In Details
CREATE TABLE `stock_in_details` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `stock_in_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `buy_price` DECIMAL(15,2) DEFAULT 0,
  FOREIGN KEY (`stock_in_id`) REFERENCES `stock_in`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`)
) ENGINE=InnoDB;

-- Stock Out
CREATE TABLE `stock_out` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `recipient` VARCHAR(150),
  `purpose` VARCHAR(255),
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- Stock Out Details
CREATE TABLE `stock_out_details` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `stock_out_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `sell_price` DECIMAL(15,2) DEFAULT 0,
  FOREIGN KEY (`stock_out_id`) REFERENCES `stock_out`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`)
) ENGINE=InnoDB;

-- Transfers (Intra-company or Inter-company)
CREATE TABLE `transfers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `from_company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `to_company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `from_location_id` INT UNSIGNED NOT NULL,
  `to_location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`from_company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`to_company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`from_location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`to_location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- Transfer Details
CREATE TABLE `transfer_details` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `transfer_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  FOREIGN KEY (`transfer_id`) REFERENCES `transfers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`)
) ENGINE=InnoDB;

-- Stock Opname Sessions
CREATE TABLE `stock_opname` (
  `session_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `opname_date` DATE NOT NULL,
  `status` ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
  `user_id` INT UNSIGNED NOT NULL,
  `notes` TEXT,
  `finalized_by` INT UNSIGNED NULL,
  `finalized_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`finalized_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- Stock Opname Items
CREATE TABLE `stock_opname_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `system_qty` INT NOT NULL,
  `physical_qty` INT NOT NULL,
  `variance` INT NOT NULL,
  `counted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `session_item_location` (`session_id`,`item_id`,`location_id`),
  FOREIGN KEY (`session_id`) REFERENCES `stock_opname`(`session_id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`)
) ENGINE=InnoDB;

-- Stock Adjustments
CREATE TABLE `stock_adjustments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `approved_by` INT UNSIGNED NULL,
  `approved_at` TIMESTAMP NULL,
  `status` ENUM('draft','approved') DEFAULT 'draft',
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `opname_session_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`),
  FOREIGN KEY (`opname_session_id`) REFERENCES `stock_opname`(`session_id`)
) ENGINE=InnoDB;

-- Stock Adjustment Details
CREATE TABLE `stock_adjustment_details` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `adjustment_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `system_qty` INT NOT NULL,
  `physical_qty` INT NOT NULL,
  `difference` INT NOT NULL,
  `reason` VARCHAR(255),
  FOREIGN KEY (`adjustment_id`) REFERENCES `stock_adjustments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`)
) ENGINE=InnoDB;

-- Purchase Orders
CREATE TABLE `purchase_orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `supplier_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('draft','pending','partial','completed','cancelled') DEFAULT 'draft',
  `approval_status` ENUM('draft','pending','approved','rejected') DEFAULT 'draft',
  `approved_by` INT UNSIGNED NULL,
  `approved_at` TIMESTAMP NULL,
  `approval_notes` TEXT NULL,
  `order_date` DATE NOT NULL,
  `expected_date` DATE NULL,
  `received_by` INT UNSIGNED NULL,
  `received_at` TIMESTAMP NULL,
  `stock_in_id` INT UNSIGNED NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`),
  FOREIGN KEY (`received_by`) REFERENCES `users`(`id`),
  FOREIGN KEY (`stock_in_id`) REFERENCES `stock_in`(`id`),
  UNIQUE KEY `stock_in_unique` (`stock_in_id`)
) ENGINE=InnoDB;

-- Purchase Order Details
CREATE TABLE `purchase_order_details` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `po_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `received_quantity` INT NOT NULL DEFAULT 0,
  `buy_price` DECIMAL(15,2) DEFAULT 0,
  FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`)
) ENGINE=InnoDB;

-- Supplier Returns
CREATE TABLE `supplier_returns` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `supplier_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- Supplier Return Details
CREATE TABLE `supplier_return_details` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `return_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `buy_price` DECIMAL(15,2) DEFAULT 0,
  FOREIGN KEY (`return_id`) REFERENCES `supplier_returns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`)
) ENGINE=InnoDB;

-- Mutation Log (audit trail)
CREATE TABLE `mutations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `item_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `type` ENUM('in','out','transfer_in','transfer_out','adjustment') NOT NULL,
  `quantity` INT NOT NULL,
  `reference_no` VARCHAR(50),
  `reference_type` ENUM('stock_in','stock_out','transfer','adjustment','supplier_return') NOT NULL,
  `reference_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- ── Performance Indexes ──────────────────────────────────────────────────────
ALTER TABLE `mutations`
  ADD INDEX `idx_mutations_company_id` (`company_id`),
  ADD INDEX `idx_mutations_item_id`    (`item_id`),
  ADD INDEX `idx_mutations_location_id`(`location_id`),
  ADD INDEX `idx_mutations_type`       (`type`),
  ADD INDEX `idx_mutations_created_at` (`created_at`),
  ADD INDEX `idx_mutations_comp_created_id` (`company_id`, `created_at`, `id`),
  ADD INDEX `idx_mutations_comp_id_created` (`company_id`, `id`, `created_at`),
  ADD INDEX `idx_mutations_comp_item_created` (`company_id`, `item_id`, `created_at`, `id`),
  ADD INDEX `idx_mutations_comp_type_created` (`company_id`, `type`, `created_at`, `id`);

ALTER TABLE `stock_in`
  ADD INDEX `idx_stock_in_company_id` (`company_id`),
  ADD INDEX `idx_stock_in_transaction_date` (`transaction_date`),
  ADD INDEX `idx_stock_in_comp_date_id` (`company_id`, `transaction_date`, `id`),
  ADD INDEX `idx_stock_in_comp_id_date` (`company_id`, `id`, `transaction_date`),
  ADD INDEX `idx_stock_in_comp_id` (`company_id`, `id`);

ALTER TABLE `stock_out`
  ADD INDEX `idx_stock_out_company_id` (`company_id`),
  ADD INDEX `idx_stock_out_transaction_date` (`transaction_date`),
  ADD INDEX `idx_stock_out_comp_date_id` (`company_id`, `transaction_date`, `id`),
  ADD INDEX `idx_stock_out_comp_id_date` (`company_id`, `id`, `transaction_date`),
  ADD INDEX `idx_stock_out_comp_id` (`company_id`, `id`);

ALTER TABLE `items`
  ADD INDEX `idx_items_code` (`code`),
  ADD INDEX `idx_items_name` (`name`),
  ADD INDEX `idx_items_active_code` (`is_active`, `code`),
  ADD INDEX `idx_items_active_cat_code` (`is_active`, `category_id`, `code`),
  ADD INDEX `idx_items_active_name` (`is_active`, `name`);

ALTER TABLE `stock`
  ADD INDEX `idx_stock_comp_item_qty` (`company_id`, `item_id`, `quantity`),
  ADD INDEX `idx_stock_comp_loc_item` (`company_id`, `location_id`, `item_id`),
  ADD INDEX `idx_stock_loc_qty` (`location_id`, `quantity`);

ALTER TABLE `transfers`
  ADD INDEX `idx_transfers_from_comp_id` (`from_company_id`, `id`),
  ADD INDEX `idx_transfers_to_comp_id` (`to_company_id`, `id`);

ALTER TABLE `stock_adjustments`
  ADD INDEX `idx_adjustments_comp_id` (`company_id`, `id`);

ALTER TABLE `purchase_orders`
  ADD INDEX `idx_po_comp_status_id` (`company_id`, `status`, `id`),
  ADD INDEX `idx_po_comp_date_id` (`company_id`, `order_date`, `id`);

ALTER TABLE `supplier_returns`
  ADD INDEX `idx_sr_comp_date_id` (`company_id`, `transaction_date`, `id`),
  ADD INDEX `idx_sr_comp_id` (`company_id`, `id`);

ALTER TABLE `stock_opname`
  ADD INDEX `idx_opname_comp_status_id` (`company_id`, `status`, `session_id`);

ALTER TABLE `categories`
  ADD INDEX `idx_categories_name` (`name`);

ALTER TABLE `suppliers`
  ADD INDEX `idx_suppliers_name` (`name`),
  ADD INDEX `idx_suppliers_active_name` (`is_active`, `name`);

ALTER TABLE `locations`
  ADD INDEX `idx_locations_active_code` (`is_active`, `code`);


-- ============================================================================
-- 2. SEED DATA & MASTER DATA (MSP GROUP PROFILE)
-- ============================================================================

-- Companies (Holding MSP Group + Anak PT)
INSERT INTO `companies` (`id`, `code`, `name`, `address`, `phone`, `email`, `npwp`, `is_holding`, `is_active`) VALUES
(1, 'MSP', 'PT Masuk Sukses Pertama', 'The East Building Lt. 37 Mega Kuningan, Jakarta Selatan', '0811-1919-0008', 'sales@mspgrup.co.id', '01.234.567.8-012.000', 1, 1),
(2, 'SLN', 'PT Sukses Logistik Nusantara', 'Komplek Pergudangan Bizpark Banjarmasin Blok C1-7, Jl. Gubernur A Subarjo, Kalsel', '0812-1183-2686', 'logistik@mspgrup.co.id', '02.345.678.9-023.000', 0, 1),
(3, 'PDM', 'PT Prima Distribusi Mandiri', 'Kawasan Industri Margomulyo Permai Blok B-14, Tandes, Surabaya', '031-7481234', 'surabaya@mspgrup.co.id', '03.456.789.0-034.000', 0, 1);

-- Roles
INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Super Admin', 'Akses penuh ke seluruh modul dan seluruh entitas PT'),
(2, 'Admin', 'Kelola data master dan transaksi operasional pergudangan'),
(3, 'Staff Gudang', 'Input barang masuk, barang keluar, dan opname fisik ban'),
(4, 'Viewer', 'Hanya melihat data stok, transaksi, dan cetak laporan');

-- Permissions
INSERT INTO `permissions` (`id`, `name`, `description`) VALUES
(1,  'dashboard.view', 'Lihat dashboard'),
(2,  'companies.view', 'Lihat daftar perusahaan/PT'),
(3,  'companies.create', 'Tambah perusahaan/PT'),
(4,  'companies.edit', 'Edit perusahaan/PT'),
(5,  'companies.delete', 'Hapus perusahaan/PT'),
(6,  'users.view', 'Lihat daftar pengguna'),
(7,  'users.create', 'Tambah pengguna'),
(8,  'users.edit', 'Edit pengguna'),
(9,  'users.delete', 'Hapus pengguna'),
(10, 'roles.view', 'Lihat daftar peran'),
(11, 'roles.edit', 'Edit peran dan izin'),
(12, 'categories.view', 'Lihat kategori'),
(13, 'categories.create', 'Tambah kategori'),
(14, 'categories.edit', 'Edit kategori'),
(15, 'categories.delete', 'Hapus kategori'),
(16, 'units.view', 'Lihat satuan'),
(17, 'units.create', 'Tambah satuan'),
(18, 'units.edit', 'Edit satuan'),
(19, 'units.delete', 'Hapus satuan'),
(20, 'suppliers.view', 'Lihat supplier'),
(21, 'suppliers.create', 'Tambah supplier'),
(22, 'suppliers.edit', 'Edit supplier'),
(23, 'suppliers.delete', 'Hapus supplier'),
(24, 'locations.view', 'Lihat lokasi'),
(25, 'locations.create', 'Tambah lokasi'),
(26, 'locations.edit', 'Edit lokasi'),
(27, 'locations.delete', 'Hapus lokasi'),
(28, 'items.view', 'Lihat barang'),
(29, 'items.create', 'Tambah barang'),
(30, 'items.edit', 'Edit barang'),
(31, 'items.delete', 'Hapus barang'),
(32, 'stock_in.view', 'Lihat barang masuk'),
(33, 'stock_in.create', 'Input barang masuk'),
(34, 'stock_in.delete', 'Hapus barang masuk'),
(35, 'stock_out.view', 'Lihat barang keluar'),
(36, 'stock_out.create', 'Input barang keluar'),
(37, 'stock_out.delete', 'Hapus barang keluar'),
(38, 'transfers.view', 'Lihat transfer'),
(39, 'transfers.create', 'Input transfer'),
(40, 'transfers.delete', 'Hapus transfer'),
(41, 'adjustments.view', 'Lihat penyesuaian stok'),
(42, 'adjustments.create', 'Buat penyesuaian stok'),
(43, 'adjustments.approve', 'Approve penyesuaian stok'),
(44, 'stock_opname.view', 'Lihat stock opname'),
(45, 'stock_opname.create', 'Buat sesi stock opname'),
(46, 'stock_opname.count', 'Input hasil hitung stock opname'),
(47, 'stock_opname.finalize', 'Finalisasi stock opname'),
(48, 'stock.view', 'Lihat stok'),
(49, 'purchase_orders.view', 'Lihat purchase order'),
(50, 'purchase_orders.create', 'Buat purchase order'),
(51, 'purchase_orders.edit', 'Edit purchase order'),
(52, 'po.approve', 'Approve/reject purchase order'),
(53, 'purchase_orders.receive', 'Terima purchase order'),
(54, 'purchase_orders.cancel', 'Batalkan purchase order'),
(55, 'items.labels', 'Cetak label barcode'),
(56, 'stock.alerts', 'Lihat peringatan stok'),
(57, 'supplier_returns.view', 'Lihat retur ke supplier'),
(58, 'supplier_returns.create', 'Buat retur ke supplier'),
(59, 'supplier_returns.delete', 'Hapus retur ke supplier'),
(60, 'reports.view', 'Lihat laporan');

-- Super Admin: all permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`;

-- Admin: all except users.delete, roles.edit, companies.delete
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions` WHERE `name` NOT IN ('users.delete','roles.edit','companies.delete');

-- Staff Gudang
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, id FROM `permissions` WHERE `name` IN (
  'dashboard.view','categories.view','units.view','suppliers.view','locations.view',
  'items.view','items.labels','stock_in.view','stock_in.create','stock_out.view','stock_out.create',
  'transfers.view','transfers.create','adjustments.view','adjustments.create','stock.view','stock.alerts',
  'stock_opname.view','stock_opname.create','stock_opname.count',
  'purchase_orders.view','purchase_orders.create','purchase_orders.edit','purchase_orders.receive',
  'supplier_returns.view','supplier_returns.create','reports.view'
);

-- Viewer
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, id FROM `permissions` WHERE `name` IN (
  'dashboard.view','categories.view','units.view','suppliers.view','locations.view',
  'items.view','stock_in.view','stock_out.view','transfers.view','adjustments.view','stock.view','reports.view','supplier_returns.view'
);

-- Default Users (Password: P@ssw0rd)
INSERT INTO `users` (`id`, `role_id`, `name`, `username`, `email`, `password`, `email_verified_at`, `is_active`) VALUES
(1, 1, 'Super Administrator', 'superadmin',  'admin@localhost',       '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW(), 1),
(2, 2, 'Admin Operasional',   'admin',       'admin2@localhost',      '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW(), 1),
(3, 3, 'Staff Gudang Ban',    'staffgudang', 'staffgudang@localhost', '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW(), 1),
(4, 4, 'Viewer Auditor',      'viewer',      'viewer@localhost',      '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW(), 1);

-- User Companies access
INSERT INTO `user_companies` (`user_id`, `company_id`, `is_default`) VALUES
(1, 1, 1), (1, 2, 0), (1, 3, 0),
(2, 1, 1), (2, 2, 0), (2, 3, 0),
(3, 1, 1), (3, 2, 0),
(4, 1, 1);

-- Categories (Sesuai Kategori Riil Produk mspgrup.co.id)
INSERT INTO `categories` (`id`, `code`, `name`, `description`) VALUES
(1, 'KAT-TBR', 'Truck & Bus Radial (TBR)', 'Ban radial untuk kendaraan niaga truk muatan berat dan bus penumpang'),
(2, 'KAT-OTR', 'Off The Road (OTR)', 'Ban alat berat untuk pertambangan, konstruksi, wheel loader, grader, dan dump truck'),
(3, 'KAT-SLD', 'Solid Off The Road (Solid Tire)', 'Ban mati / solid tyre anti bocor untuk forklift dan material handling industri'),
(4, 'KAT-TUB', 'Inner Tube & Flap', 'Ban dalam karet butil kualitas tinggi dan selendang/flap pelindung velg'),
(5, 'KAT-ACC', 'Aksesoris & Perlengkapan Ban', 'O-ring seal, valve pentil OTR, rim clamp, dan perlengkapan ban alat berat');

-- Units
INSERT INTO `units` (`id`, `name`, `abbreviation`) VALUES
(1, 'Pcs', 'pcs'),
(2, 'Set', 'set'),
(3, 'Karton', 'ktn'),
(4, 'Kilogram', 'kg'),
(5, 'Lusin', 'lsn'),
(6, 'Roll', 'rol'),
(7, 'Box', 'box'),
(8, 'Unit', 'unit');

-- Suppliers (Prinsipal Ban Riil MSP Group)
INSERT INTO `suppliers` (`id`, `code`, `name`, `contact_person`, `phone`, `email`, `address`, `is_active`) VALUES
(1, 'SUP-OPL', 'Shandong Fangxing Rubber Co., Ltd', 'Chen Wei (Export Dept)', '+86-546-6851234', 'export@fangxingrubber.com', 'Dawang Economic Development Zone, Guangrao, Dongying, Shandong, China', 1),
(2, 'SUP-WNB', 'Qingdao Wanbo Tyre Co., Ltd', 'Lin Song', '+86-532-86991234', 'sales@wanbotire.com', 'Qianwangang Road, Huangdao District, Qingdao, Shandong, China', 1),
(3, 'SUP-WDP', 'Windpower Tyre International Co., Ltd', 'Jessica Zhang', '+86-391-3951234', 'contact@windpowertire.com', 'Jiaozuo Industrial Park, Henan Province, China', 1),
(4, 'SUP-WNF', 'Shandong Wen Feng Rubber Products Co., Ltd', 'Liang Chen', '+86-536-2281234', 'tube@wenfengrubber.com', 'Gaomi Rubber Industrial Park, Weifang, Shandong, China', 1),
(5, 'SUP-GJT', 'PT Gajah Tunggal Tbk (Mitra Flap & Aksesoris)', 'Bambang Hartono', '021-3805900', 'oem-sales@gt-tires.com', 'Wisma Hayam Wuruk Lt. 10, Jl. Hayam Wuruk No. 8, Jakarta Pusat', 1);

-- Locations (Shared Warehouse Racks / Zones)
INSERT INTO `locations` (`id`, `code`, `name`, `description`, `is_active`) VALUES
(1, 'RAK-A1', 'Rak A1 - Ban Truk & Bus Radial (TBR)', 'Area Ban Truk & Bus Radial (Ukuran 10.00R20, 11.00R20, 12.00R20)', 1),
(2, 'RAK-A2', 'Rak A2 - Ban Truk Tubeless (TBR)', 'Area Ban Truk Tubeless (Ukuran 315/80R22.5, 295/80R22.5)', 1),
(3, 'RAK-B1', 'Rak B1 - Ban Tambang & Alat Berat (OTR)', 'Area Ban Tambang & Dump Truck (Ukuran 14.00-24, 18.00-25, 23.5-25)', 1),
(4, 'RAK-B2', 'Rak B2 - Ban Solid Forklift', 'Area Ban Mati Forklift Gudang & Fabrikasi (Ukuran 6.50-10, 28x9-15)', 1),
(5, 'RAK-C1', 'Rak C1 - Ban Dalam & Flap', 'Rak Penyimpanan Inner Tube Butyl & Flap Pelindung Velg', 1),
(6, 'GUDANG-BJM',  'Gudang Bizpark Banjarmasin', 'Komplek Bizpark Blok C1-7 Jl. Gubernur A Subarjo, Kalsel', 1),
(7, 'GUDANG-UTAMA','Gudang Utama Staging JKT', 'Area Inbound/Outbound Staging Kontainer Jakarta', 1),
(8, 'AREA-TRANSIT','Area Karantina & Retur', 'Area Khusus Pemeriksaan Fisik Ban Klaim Garansi & Retur', 1);


-- ============================================================================
-- 3. MASTER BARANG TERPUSAT GRUP SCRAPED DARI MSPGRUP.CO.ID
-- ============================================================================

INSERT INTO `items` (`id`, `code`, `name`, `category_id`, `unit_id`, `min_stock`, `buy_price`, `sell_price`, `description`, `is_active`) VALUES
-- Ban Dalam, Flap, & Aksesoris
(1, 'SHR-TUB-01', 'WEN FENG Butyl Inner Tube 12.00R20 TR179A', 4, 1, 50, 210000.00, 290000.00, 'Ban dalam karet butil tebal kualitas tahan panas untuk ban truk 12.00R20 katup pentil TR179A', 1),
(2, 'SHR-TUB-02', 'WANBO Butyl Inner Tube 23.5-25 TR179A', 4, 1, 20, 480000.00, 680000.00, 'Ban dalam alat berat loader tambang 23.5-25 pentil besar TR179A', 1),
(3, 'SHR-FLP-01', 'Flap Selendang Ban Truk 12.00-20 Heavy Duty', 4, 1, 60, 115000.00, 165000.00, 'Flap pelindung velg ban truk radial tahan gesekan velg baja', 1),
(4, 'SHR-VAL-01', 'Valve Pentil Ban OTR TRJ1175C Heavy Duty Brass', 5, 1, 100, 45000.00, 75000.00, 'Pentil kuningan ban alat berat tambang tahan tekanan tinggi', 1),
(5, 'SHR-ORG-01', 'O-Ring Seal Ban Tubeless OTR 25 Inch', 5, 1, 40, 65000.00, 95000.00, 'Karet O-Ring seal penyekat velg tubeless dump truck tambang 25 inch', 1),

-- Seri OPALS TBR & OTR
(6,  'MSP-OPL-232', 'OPALS OP232 315/80R22.5', 1, 1, 20, 3150000.00, 4100000.00, 'Ban radial truk tubeless premium tapak highway long-haul hemat bahan bakar dan traksi tinggi', 1),
(7,  'MSP-OPL-225', 'OPALS OP225 11.00R20', 1, 1, 25, 2750000.00, 3600000.00, 'Ban radial truk & bus all-position dengan 3-line tread design stabilitas tinggi jalur nasional', 1),
(8,  'MSP-OPL-359', 'OPALS EV OP359 12.00R20', 1, 1, 15, 3350000.00, 4350000.00, 'Ban radial truk tambang & logging heavy duty compound tahan sobek dan sayatan batu', 1),
(9,  'MSP-OPL-195', 'OPALS OTR OP195 23.5-25', 2, 1, 8, 14200000.00, 18500000.00, 'Ban alat berat wheel loader tapak tebal anti bocor self-cleaning groove optimal', 1),
(10, 'MSP-OPL-178', 'OPALS OTR OP178 14.00-24', 2, 1, 10, 7800000.00, 10200000.00, 'Ban alat berat motor grader traksi kuat dan ketahanan aus tinggi di medan berbatu', 1),

-- Seri WANBO Mining OTR & Solid Forklift
(11, 'SLN-WNB-699', 'WANBO WB699 18.00-25', 2, 1, 6, 17500000.00, 22800000.00, 'Ban dump truck tambang batubara heavy duty lug pattern kokoh daya angkut ekstrem', 1),
(12, 'SLN-WNB-625', 'WANBO WB625 23.5-25', 2, 1, 6, 13800000.00, 17900000.00, 'Ban wheel loader tambang traksi maksimal dan ketahanan dinding samping superior', 1),
(13, 'SLN-WNB-711', 'WANBO WB711 29.5R25', 2, 1, 4, 34500000.00, 44000000.00, 'Ban giant mining radial haul truck tambang batubara ketahanan panas dan beban berat', 1),
(14, 'SLN-WNB-SLD', 'WANBO Solid Tire 28x9-15', 3, 1, 12, 2850000.00, 3900000.00, 'Ban mati/solid anti pecah forklift 3.5 - 5 ton untuk handling logistik pelabuhan dan gudang', 1),

-- Seri Ban Distribusi Kargo & Light Truck
(15, 'PDM-OPL-366', 'OPALS OP366 10.00R20', 1, 1, 20, 2450000.00, 3200000.00, 'Ban truk ekspedisi kargo antar kota tapak awet kilometer tinggi', 1),
(16, 'PDM-OPL-265', 'OPALS OP265 7.50R16', 1, 1, 30, 1450000.00, 1950000.00, 'Ban truk engkel & canter armada distribusi retail dan logistik perkotaan', 1),
(17, 'PDM-WNB-650', 'WANBO Solid Tire 6.50-10', 3, 1, 15, 1650000.00, 2350000.00, 'Ban mati/solid forklift pabrik 2 - 2.5 ton awet dan bebas perawatan bocor', 1);


-- ============================================================================
-- 4. SALDO STOK OPERASIONAL REALISTIS (KEPEMILIKAN PER PT DI RAK BERSAMA)
-- ============================================================================

INSERT INTO `stock` (`item_id`, `location_id`, `company_id`, `quantity`) VALUES
-- RAK-A1 (Zona Ban Truk Konvensional 10.00R20 / 11.00R20 / 12.00R20)
(7,  1, 1, 85),   -- MSP: OPALS OP225 11.00R20 (85 pcs)
(7,  1, 2, 40),   -- SLN: OPALS OP225 11.00R20 (40 pcs)
(8,  1, 1, 12),   -- MSP: OPALS EV OP359 12.00R20 (12 pcs <= min 15 -> MENIPIS / ALERT!)
(15, 1, 3, 60),   -- PDM: OPALS OP366 10.00R20 (60 pcs)

-- RAK-A2 (Zona Ban Truk Tubeless 315/80R22.5 & Light Truck)
(6,  2, 1, 95),   -- MSP: OPALS OP232 315/80R22.5 (95 pcs)
(16, 2, 3, 75),   -- PDM: OPALS OP265 7.50R16 (75 pcs)

-- RAK-B1 (Zona Ban Tambang & Grader)
(9,  3, 1, 18),   -- MSP: OPALS OTR OP195 23.5-25 (18 pcs)
(10, 3, 1, 100),  -- MSP: OPALS OTR OP178 14.00-24 (100 pcs milik PT MSP)
(10, 3, 2, 1500), -- SLN: OPALS OTR OP178 14.00-24 (1500 pcs milik PT SLN)
(10, 3, 3, 15),   -- PDM: OPALS OTR OP178 14.00-24 (15 pcs milik PT PDM)
(11, 3, 2, 14),   -- SLN: WANBO WB699 18.00-25 (14 pcs)
(12, 3, 2, 16),   -- SLN: WANBO WB625 23.5-25 (16 pcs)
(13, 3, 2, 2),    -- SLN: WANBO WB711 29.5R25 (2 pcs <= min 4 -> MENIPIS / ALERT!)

-- RAK-B2 (Zona Ban Solid Forklift)
(14, 4, 2, 32),   -- SLN: WANBO Solid Tire 28x9-15 (32 pcs)
(17, 4, 3, 40),   -- PDM: WANBO Solid Tire 6.50-10 (40 pcs)

-- RAK-C1 (Zona Ban Dalam & Aksesoris - Stok terpisah per PT)
(1, 5, 1, 180),   -- MSP: Butyl Tube 12.00R20 (180 pcs)
(1, 5, 2, 95),    -- SLN: Butyl Tube 12.00R20 (95 pcs)
(1, 5, 3, 60),    -- PDM: Butyl Tube 12.00R20 (60 pcs)
(2, 5, 1, 45),    -- MSP: Butyl Tube 23.5-25 (45 pcs)
(2, 5, 2, 30),    -- SLN: Butyl Tube 23.5-25 (30 pcs)
(3, 5, 1, 220),   -- MSP: Flap 12.00-20 (220 pcs)
(3, 5, 2, 110),   -- SLN: Flap 12.00-20 (110 pcs)
(4, 5, 1, 250),   -- MSP: Valve Pentil TRJ1175C (250 pcs)
(5, 5, 1, 85),    -- MSP: O-Ring Seal 25 Inch (85 pcs)
(5, 5, 2, 40),    -- SLN: O-Ring Seal 25 Inch (40 pcs)

-- GUDANG-BJM (Gudang Bizpark Banjarmasin - Siap Kirim Site Tambang)
(11, 6, 2, 20),   -- SLN: WANBO WB699 18.00-25 (20 pcs di Banjarmasin)
(12, 6, 2, 15),   -- SLN: WANBO WB625 23.5-25 (15 pcs di Banjarmasin)
(1,  6, 2, 50);   -- SLN: Butyl Tube di Banjarmasin (50 pcs)


-- ============================================================================
-- 5. TRANSAKSI PURCHASE ORDERS (IMPOR KONTAINER DARI PRINSIPAL)
-- ============================================================================

INSERT INTO `purchase_orders` (`id`, `reference_no`, `company_id`, `supplier_id`, `location_id`, `user_id`, `status`, `approval_status`, `approved_by`, `approved_at`, `order_date`, `expected_date`, `notes`, `created_at`) VALUES
(1, 'PO-MSP-202611-0001', 1, 1, 1, 1, 'completed', 'approved', 1, '2026-11-01 10:00:00', '2026-11-01', '2026-11-04', 'Impor Kontainer 40ft Ban Radial Truk OPALS Batch Q4 (FCL)', '2026-11-01 09:30:00'),
(2, 'PO-SLN-202611-0001', 2, 2, 3, 2, 'partial',   'approved', 1, '2026-11-02 14:00:00', '2026-11-02', '2026-11-06', 'Pengadaan Ban OTR Tambang Batubara WANBO WB699 & WB625', '2026-11-02 11:15:00'),
(3, 'PO-PDM-202611-0001', 3, 1, 2, 2, 'pending',   'approved', 1, '2026-11-05 09:00:00', '2026-11-05', '2026-11-09', 'Restock Ban Truk Canter OPALS OP265 Cabang Surabaya', '2026-11-05 08:45:00'),
(4, 'PO-MSP-202611-0002', 1, 4, 5, 3, 'draft',     'draft',    NULL, NULL,               '2026-11-08', '2026-11-12', 'Usulan PO rutin Ban Dalam Butyl 12.00R20 & Flap Heavy Duty', '2026-11-08 13:20:00');

INSERT INTO `purchase_order_details` (`id`, `po_id`, `item_id`, `quantity`, `received_quantity`, `buy_price`) VALUES
-- PO-MSP-202611-0001 (Completed)
(1, 1, 6, 50, 50, 3150000.00), -- 50 pcs OPALS OP232
(2, 1, 7, 40, 40, 2750000.00), -- 40 pcs OPALS OP225
-- PO-SLN-202611-0001 (Partial: WB699 rcv 10/20, WB625 rcv 10/10)
(3, 2, 11, 20, 10, 17500000.00), -- 20 pcs WANBO WB699
(4, 2, 12, 10, 10, 13800000.00), -- 10 pcs WANBO WB625
-- PO-PDM-202611-0001 (Pending Receive)
(5, 3, 16, 60, 0, 1450000.00),  -- 60 pcs OPALS OP265
(6, 3, 17, 20, 0, 1650000.00),  -- 20 pcs WANBO Solid 6.50-10
-- PO-MSP-202611-0002 (Draft)
(7, 4, 1, 100, 0, 210000.00),   -- 100 pcs Tube 12.00R20
(8, 4, 3, 100, 0, 115000.00);   -- 100 pcs Flap 12.00-20


-- ============================================================================
-- 6. TRANSAKSI BARANG MASUK (STOCK IN / PENERIMAAN KONTAINER)
-- ============================================================================

INSERT INTO `stock_in` (`id`, `reference_no`, `company_id`, `supplier_id`, `location_id`, `user_id`, `notes`, `transaction_date`, `created_at`) VALUES
(1, 'SI-MSP-202611-0001', 1, 1, 1, 1, 'Bongkar kontainer no TCLU8821920 (PO-MSP-202611-0001) lengkap & kondisi baik', '2026-11-04', '2026-11-04 11:30:00'),
(2, 'SI-SLN-202611-0001', 2, 2, 3, 3, 'Penerimaan kontainer OTR tahap 1 dari Pelabuhan Tanjung Priok', '2026-11-05', '2026-11-05 15:45:00'),
(3, 'SI-PDM-202611-0001', 3, 4, 5, 2, 'Penerimaan restock darurat ban dalam butyl dari gudang transit', '2026-11-06', '2026-11-06 10:15:00');

-- Link PO 1 ke stock_in_id 1
UPDATE `purchase_orders` SET `stock_in_id` = 1, `received_by` = 1, `received_at` = '2026-11-04 11:30:00' WHERE `id` = 1;

INSERT INTO `stock_in_details` (`id`, `stock_in_id`, `item_id`, `quantity`, `buy_price`) VALUES
-- SI-MSP-202611-0001
(1, 1, 6, 50, 3150000.00),
(2, 1, 7, 40, 2750000.00),
-- SI-SLN-202611-0001
(3, 2, 11, 10, 17500000.00),
(4, 2, 12, 10, 13800000.00),
-- SI-PDM-202611-0001
(5, 3, 1, 50, 210000.00),
(6, 3, 3, 50, 115000.00);


-- ============================================================================
-- 7. TRANSAKSI BARANG KELUAR (STOCK OUT / DELIVERY ORDER PELANGGAN)
-- ============================================================================

INSERT INTO `stock_out` (`id`, `reference_no`, `company_id`, `location_id`, `user_id`, `recipient`, `purpose`, `notes`, `transaction_date`, `created_at`) VALUES
(1, 'SO-MSP-202611-0001', 1, 2, 3, 'PT Sinar Jaya Megah Langgeng (PO Bus Sinar Jaya)', 'Pengiriman armada bus AKAP', 'Surat Jalan No SJ-MSP-9901 armada B 9821 TNA', '2026-11-06', '2026-11-06 14:00:00'),
(2, 'SO-SLN-202611-0001', 2, 3, 3, 'PT Bukit Makmur Mandiri Utama (BUMA) Site Kalsel', 'Supply Ban Dump Truck Tambang Batubara', 'DO Kontrak Tambang No BMA-PO-7712', '2026-11-07', '2026-11-07 09:30:00'),
(3, 'SO-PDM-202611-0001', 3, 2, 2, 'Toko Ban Sumber Rejeki Surabaya', 'Penjualan grosir sub-dealer Jawa Timur', 'Faktur Penjualan No INV-PDM-1102', '2026-11-07', '2026-11-07 16:20:00');

INSERT INTO `stock_out_details` (`id`, `stock_out_id`, `item_id`, `quantity`, `sell_price`) VALUES
-- SO-MSP-202611-0001
(1, 1, 6, 12, 4100000.00), -- 12 pcs OPALS OP232
(2, 1, 7, 8,  3600000.00), -- 8 pcs OPALS OP225
-- SO-SLN-202611-0001
(3, 2, 11, 4, 22800000.00), -- 4 pcs WANBO WB699
(4, 2, 12, 2, 17900000.00), -- 2 pcs WANBO WB625
-- SO-PDM-202611-0001
(5, 3, 16, 20, 1950000.00), -- 20 pcs OPALS OP265 Canter
(6, 3, 1,  30, 290000.00);  -- 30 pcs Butyl Inner Tube


-- ============================================================================
-- 8. TRANSAKSI TRANSFER STOK (INTER-COMPANY & INTRA-WAREHOUSE)
-- ============================================================================

INSERT INTO `transfers` (`id`, `reference_no`, `from_company_id`, `to_company_id`, `from_location_id`, `to_location_id`, `user_id`, `notes`, `transaction_date`, `created_at`) VALUES
-- Transfer Antar-PT: Holding MSP kirim Ban Loader & Inner Tube ke Anak PT SLN
(1, 'TRF-202611-0001', 1, 2, 3, 6, 1, 'Transfer Stok Antar-PT: Bantuan stok ban loader tambang dari Holding Jakarta ke Gudang SLN Bizpark Banjarmasin', '2026-11-07', '2026-11-07 10:00:00'),
-- Transfer Intra-Warehouse: Pindah ban radial dari staging kontainer ke rak susun
(2, 'TRF-202611-0002', 1, 1, 7, 1, 3, 'Pemindahan stok dari staging penerimaan kontainer ke rak susun TBR 01', '2026-11-08', '2026-11-08 11:20:00');

INSERT INTO `transfer_details` (`id`, `transfer_id`, `item_id`, `quantity`) VALUES
-- TRF-202611-0001 (Inter-PT: 4 pcs OPALS OTR OP195 & 20 pcs Butyl Tube)
(1, 1, 9, 4),
(2, 1, 1, 20),
-- TRF-202611-0002 (Intra-Rak: 20 pcs OPALS OP225)
(3, 2, 7, 20);


-- ============================================================================
-- 9. STOCK OPNAME & STOCK ADJUSTMENTS
-- ============================================================================

-- Sesi 1: Opname Finalized MSP
INSERT INTO `stock_opname` (`session_id`, `company_id`, `opname_date`, `status`, `user_id`, `notes`, `finalized_by`, `finalized_at`, `created_at`) VALUES
(1, 1, '2026-11-01', 'finalized', 1, 'Stock opname fisik bulanan ban truk & bus di Gudang Jakarta', 1, '2026-11-01 17:00:00', '2026-11-01 08:00:00'),
(2, 2, '2026-11-08', 'draft',     3, 'Stock opname rutin ban alat berat di Gudang Banjarmasin (sedang berjalan)', NULL, NULL, '2026-11-08 14:00:00');

INSERT INTO `stock_opname_items` (`id`, `session_id`, `item_id`, `location_id`, `system_qty`, `physical_qty`, `variance`, `counted_at`) VALUES
(1, 1, 7, 1, 86, 85, -1, '2026-11-01 14:30:00'),
(2, 2, 11, 6, 20, 20, 0, '2026-11-08 15:15:00');

-- Stock Adjustment hasil Opname Sesi 1
INSERT INTO `stock_adjustments` (`id`, `reference_no`, `company_id`, `location_id`, `user_id`, `approved_by`, `approved_at`, `status`, `notes`, `transaction_date`, `opname_session_id`, `created_at`) VALUES
(1, 'ADJ-MSP-202611-0001', 1, 1, 1, 1, '2026-11-01 17:00:00', 'approved', 'Penyesuaian selisih stock opname SO-202611-0001: 1 unit OPALS OP225 dipindahkan ke ruang uji lab sampel', '2026-11-01', 1, '2026-11-01 17:00:00');

INSERT INTO `stock_adjustment_details` (`id`, `adjustment_id`, `item_id`, `system_qty`, `physical_qty`, `difference`, `reason`) VALUES
(1, 1, 7, 86, 85, -1, 'Sampel uji tekan ketahanan beban laboratorium Kementerian Perhubungan');


-- ============================================================================
-- 10. RETUR SUPPLIER (SUPPLIER RETURNS)
-- ============================================================================

INSERT INTO `supplier_returns` (`id`, `reference_no`, `company_id`, `supplier_id`, `location_id`, `user_id`, `reason`, `status`, `notes`, `transaction_date`, `created_at`) VALUES
(1, 'RET-MSP-202611-0001', 1, 1, 8, 2, 'Cacat Pabrik (Bead Wire Deformity saat unboxing kontainer)', 'completed', 'Klaim garansi ganti ban baru ke prinsipal Fangxing Rubber', '2026-11-07', '2026-11-07 13:00:00');

INSERT INTO `supplier_return_details` (`id`, `return_id`, `item_id`, `quantity`, `buy_price`) VALUES
(1, 1, 8, 1, 3350000.00);


-- ============================================================================
-- 11. AUDIT TRAIL / LOG MUTASI LENGKAP (MUTATIONS)
-- ============================================================================

INSERT INTO `mutations` (`company_id`, `item_id`, `location_id`, `type`, `quantity`, `reference_no`, `reference_type`, `reference_id`, `user_id`, `notes`, `created_at`) VALUES
-- Inbound SI-MSP-202611-0001
(1, 6, 1, 'in', 50, 'SI-MSP-202611-0001', 'stock_in', 1, 1, 'Penerimaan kontainer impor PO-MSP-202611-0001', '2026-11-04 11:30:00'),
(1, 7, 1, 'in', 40, 'SI-MSP-202611-0001', 'stock_in', 1, 1, 'Penerimaan kontainer impor PO-MSP-202611-0001', '2026-11-04 11:30:00'),
-- Inbound SI-SLN-202611-0001
(2, 11, 3, 'in', 10, 'SI-SLN-202611-0001', 'stock_in', 2, 3, 'Penerimaan parsial kontainer OTR tambang', '2026-11-05 15:45:00'),
(2, 12, 3, 'in', 10, 'SI-SLN-202611-0001', 'stock_in', 2, 3, 'Penerimaan parsial kontainer OTR tambang', '2026-11-05 15:45:00'),
-- Inbound SI-PDM-202611-0001
(3, 1, 5, 'in', 50, 'SI-PDM-202611-0001', 'stock_in', 3, 2, 'Penerimaan restock ban dalam butyl', '2026-11-06 10:15:00'),
(3, 3, 5, 'in', 50, 'SI-PDM-202611-0001', 'stock_in', 3, 2, 'Penerimaan restock flap ban truk', '2026-11-06 10:15:00'),
-- Outbound SO-MSP-202611-0001
(1, 6, 2, 'out', 12, 'SO-MSP-202611-0001', 'stock_out', 1, 3, 'Pengiriman armada PO Bus Sinar Jaya', '2026-11-06 14:00:00'),
(1, 7, 1, 'out', 8,  'SO-MSP-202611-0001', 'stock_out', 1, 3, 'Pengiriman armada PO Bus Sinar Jaya', '2026-11-06 14:00:00'),
-- Outbound SO-SLN-202611-0001
(2, 11, 3, 'out', 4, 'SO-SLN-202611-0001', 'stock_out', 2, 3, 'Supply ban dump truck PT BUMA Site Kalsel', '2026-11-07 09:30:00'),
(2, 12, 3, 'out', 2, 'SO-SLN-202611-0001', 'stock_out', 2, 3, 'Supply ban loader PT BUMA Site Kalsel', '2026-11-07 09:30:00'),
-- Outbound SO-PDM-202611-0001
(3, 16, 2, 'out', 20, 'SO-PDM-202611-0001', 'stock_out', 3, 2, 'Penjualan grosir Toko Ban Sumber Rejeki', '2026-11-07 16:20:00'),
(3, 1,  5, 'out', 30, 'SO-PDM-202611-0001', 'stock_out', 3, 2, 'Penjualan grosir ban dalam', '2026-11-07 16:20:00'),
-- Inter-Company Transfer TRF-202611-0001
(1, 9, 3, 'transfer_out', 4,  'TRF-202611-0001', 'transfer', 1, 1, 'Transfer ban loader ke SLN Banjarmasin', '2026-11-07 10:00:00'),
(2, 9, 6, 'transfer_in',  4,  'TRF-202611-0001', 'transfer', 1, 1, 'Penerimaan transfer ban loader dari MSP', '2026-11-07 10:00:00'),
(1, 1, 5, 'transfer_out', 20, 'TRF-202611-0001', 'transfer', 1, 1, 'Transfer tube ke SLN Banjarmasin', '2026-11-07 10:00:00'),
(2, 1, 6, 'transfer_in',  20, 'TRF-202611-0001', 'transfer', 1, 1, 'Penerimaan tube dari MSP', '2026-11-07 10:00:00'),
-- Intra-Warehouse Transfer TRF-202611-0002
(1, 7, 7, 'transfer_out', 20, 'TRF-202611-0002', 'transfer', 2, 3, 'Pindah ban dari staging ke rak susun TBR', '2026-11-08 11:20:00'),
(1, 7, 1, 'transfer_in',  20, 'TRF-202611-0002', 'transfer', 2, 3, 'Pindah ban dari staging ke rak susun TBR', '2026-11-08 11:20:00'),
-- Adjustment ADJ-MSP-202611-0001
(1, 7, 1, 'adjustment', -1, 'ADJ-MSP-202611-0001', 'adjustment', 1, 1, 'Alokasi sampel uji tekan laboratorium', '2026-11-01 17:00:00'),
-- Supplier Return RET-MSP-202611-0001
(1, 8, 8, 'out', 1, 'RET-MSP-202611-0001', 'supplier_return', 1, 2, 'Retur ban cacat bead wire ke prinsipal Fangxing', '2026-11-07 13:00:00');
