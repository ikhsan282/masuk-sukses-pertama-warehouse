-- Masuk Sukses Pertama Warehouse Database Schema
-- DB: db_msp_warehouse

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+07:00";

CREATE DATABASE IF NOT EXISTS `db_msp_warehouse` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `db_msp_warehouse`;

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
  `phone` VARCHAR(20),
  `email` VARCHAR(150),
  `address` TEXT,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Locations (Rak/Bin)
CREATE TABLE `locations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255),
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Items
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

-- Stock (per location)
CREATE TABLE `stock` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `quantity` INT DEFAULT 0,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `item_location` (`item_id`, `location_id`),
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`)
) ENGINE=InnoDB;

-- Stock In
CREATE TABLE `stock_in` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `supplier_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `recipient` VARCHAR(150),
  `purpose` VARCHAR(255),
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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

-- Transfers
CREATE TABLE `transfers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `from_location_id` INT UNSIGNED NOT NULL,
  `to_location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
  `opname_date` DATE NOT NULL,
  `status` ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
  `user_id` INT UNSIGNED NOT NULL,
  `notes` TEXT,
  `finalized_by` INT UNSIGNED NULL,
  `finalized_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`finalized_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

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
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `approved_by` INT UNSIGNED NULL,
  `approved_at` TIMESTAMP NULL,
  `status` ENUM('draft','approved') DEFAULT 'draft',
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `opname_session_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
  `supplier_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
  `notes` TEXT,
  `transaction_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- =====================
-- SEED DATA
-- =====================

INSERT INTO `roles` (`name`, `description`) VALUES
('Super Admin', 'Akses penuh ke semua fitur'),
('Admin', 'Kelola data master dan transaksi'),
('Staff Gudang', 'Input barang masuk dan keluar'),
('Viewer', 'Hanya lihat data dan laporan');

INSERT INTO `permissions` (`name`, `description`) VALUES
('dashboard.view', 'Lihat dashboard'),
('users.view', 'Lihat daftar pengguna'),
('users.create', 'Tambah pengguna'),
('users.edit', 'Edit pengguna'),
('users.delete', 'Hapus pengguna'),
('roles.view', 'Lihat daftar peran'),
('roles.edit', 'Edit peran dan izin'),
('categories.view', 'Lihat kategori'),
('categories.create', 'Tambah kategori'),
('categories.edit', 'Edit kategori'),
('categories.delete', 'Hapus kategori'),
('units.view', 'Lihat satuan'),
('units.create', 'Tambah satuan'),
('units.edit', 'Edit satuan'),
('units.delete', 'Hapus satuan'),
('suppliers.view', 'Lihat supplier'),
('suppliers.create', 'Tambah supplier'),
('suppliers.edit', 'Edit supplier'),
('suppliers.delete', 'Hapus supplier'),
('locations.view', 'Lihat lokasi'),
('locations.create', 'Tambah lokasi'),
('locations.edit', 'Edit lokasi'),
('locations.delete', 'Hapus lokasi'),
('items.view', 'Lihat barang'),
('items.create', 'Tambah barang'),
('items.edit', 'Edit barang'),
('items.delete', 'Hapus barang'),
('stock_in.view', 'Lihat barang masuk'),
('stock_in.create', 'Input barang masuk'),
('stock_in.delete', 'Hapus barang masuk'),
('stock_out.view', 'Lihat barang keluar'),
('stock_out.create', 'Input barang keluar'),
('stock_out.delete', 'Hapus barang keluar'),
('transfers.view', 'Lihat transfer'),
('transfers.create', 'Input transfer'),
('transfers.delete', 'Hapus transfer'),
('adjustments.view', 'Lihat penyesuaian stok'),
('adjustments.create', 'Buat penyesuaian stok'),
('adjustments.approve', 'Approve penyesuaian stok'),
('stock_opname.view', 'Lihat stock opname'),
('stock_opname.create', 'Buat sesi stock opname'),
('stock_opname.count', 'Input hasil hitung stock opname'),
('stock_opname.finalize', 'Finalisasi stock opname'),
('stock.view', 'Lihat stok'),
('purchase_orders.view', 'Lihat purchase order'),
('purchase_orders.create', 'Buat purchase order'),
('purchase_orders.edit', 'Edit purchase order'),
('po.approve', 'Approve/reject purchase order'),
('purchase_orders.receive', 'Terima purchase order'),
('purchase_orders.cancel', 'Batalkan purchase order'),
('items.labels', 'Cetak label barcode'),
('stock.alerts', 'Lihat peringatan stok'),
('supplier_returns.view', 'Lihat retur ke supplier'),
('supplier_returns.create', 'Buat retur ke supplier'),
('supplier_returns.delete', 'Hapus retur ke supplier'),
('reports.view', 'Lihat laporan');

-- Super Admin: all permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`;

-- Admin: all except users.delete, roles.edit
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions` WHERE `name` NOT IN ('users.delete','roles.edit');

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

-- Default users for each role (password: P@ssw0rd)
INSERT INTO `users` (`role_id`, `name`, `username`, `email`, `password`, `email_verified_at`, `is_active`) VALUES
(1, 'Super Administrator', 'superadmin',  'admin@localhost',    '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW(), 1),
(2, 'Admin',               'admin',       'admin2@localhost',   '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW(), 1),
(3, 'Staff Gudang',        'staffgudang', 'staffgudang@localhost', '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW(), 1),
(4, 'Viewer',              'viewer',      'viewer@localhost',   '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', NOW(), 1);

-- Seed: categories
INSERT INTO `categories` (`code`, `name`) VALUES
('KAT-001', 'Elektronik'),
('KAT-002', 'Peralatan Kantor'),
('KAT-003', 'Bahan Baku'),
('KAT-004', 'Produk Jadi'),
('KAT-005', 'Perlengkapan Gudang');

-- Seed: units
INSERT INTO `units` (`name`, `abbreviation`) VALUES
('Pcs', 'pcs'),
('Karton', 'ktn'),
('Kilogram', 'kg'),
('Liter', 'ltr'),
('Meter', 'mtr'),
('Lusin', 'lsn'),
('Roll', 'rol');

-- ── Indexes ──────────────────────────────────────────────────────────────────
ALTER TABLE `mutations`
  ADD INDEX `idx_mutations_item_id`    (`item_id`),
  ADD INDEX `idx_mutations_location_id`(`location_id`),
  ADD INDEX `idx_mutations_type`       (`type`),
  ADD INDEX `idx_mutations_created_at` (`created_at`);

ALTER TABLE `stock_in`
  ADD INDEX `idx_stock_in_transaction_date` (`transaction_date`);

ALTER TABLE `stock_out`
  ADD INDEX `idx_stock_out_transaction_date` (`transaction_date`);

ALTER TABLE `items`
  ADD INDEX `idx_items_code` (`code`),
  ADD INDEX `idx_items_name` (`name`);

-- Seed: locations
INSERT INTO `locations` (`code`, `name`, `description`) VALUES
('RAK-A1', 'Rak A1', 'Rak utama bagian depan'),
('RAK-A2', 'Rak A2', 'Rak utama bagian tengah'),
('RAK-B1', 'Rak B1', 'Rak samping kiri'),
('RAK-B2', 'Rak B2', 'Rak samping kanan'),
('GUDANG-UTAMA', 'Gudang Utama', 'Area penyimpanan utama');
