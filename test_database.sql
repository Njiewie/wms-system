-- Test Database Schema for Free WMS Deployment
-- Import this file into your 000webhost phpMyAdmin

-- Create users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'user',
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create inventory table
CREATE TABLE IF NOT EXISTS `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL,
  `description` text,
  `quantity` int(11) DEFAULT '0',
  `unit_price` decimal(10,2) DEFAULT '0.00',
  `location` varchar(50) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `supplier` varchar(100) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT '10',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create orders table
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `priority` varchar(10) DEFAULT 'normal',
  `total_amount` decimal(10,2) DEFAULT '0.00',
  `shipping_address` text,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create order_items table
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create movements table for inventory tracking
CREATE TABLE IF NOT EXISTS `movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL,
  `type` varchar(20) NOT NULL, -- 'in', 'out', 'adjustment'
  `quantity` int(11) NOT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `reference` varchar(50) DEFAULT NULL, -- order number, etc.
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Insert test admin user (password: admin123)
INSERT INTO `users` (`username`, `password`, `email`, `role`, `first_name`, `last_name`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@wms-demo.com', 'admin', 'System', 'Administrator'),
('demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'demo@wms-demo.com', 'user', 'Demo', 'User');

-- Insert sample inventory items
INSERT INTO `inventory` (`sku`, `description`, `quantity`, `unit_price`, `location`, `category`, `supplier`, `reorder_level`) VALUES
('DEMO-001', 'Demo Product 1 - Electronics', 100, 29.99, 'A1-B2-C3', 'Electronics', 'TechSupply Co', 20),
('DEMO-002', 'Demo Product 2 - Office Supplies', 250, 5.50, 'B2-A1-D4', 'Office', 'OfficeMax Pro', 50),
('DEMO-003', 'Demo Product 3 - Industrial Tools', 75, 125.00, 'C3-D4-A1', 'Tools', 'Industrial Supply', 15),
('DEMO-004', 'Demo Product 4 - Safety Equipment', 200, 15.75, 'D4-C3-B2', 'Safety', 'SafetyFirst Inc', 30),
('DEMO-005', 'Demo Product 5 - Packaging Materials', 500, 2.25, 'A2-B3-C1', 'Packaging', 'PackagePro Ltd', 100);

-- Insert sample orders
INSERT INTO `orders` (`order_number`, `customer_name`, `customer_email`, `status`, `priority`, `total_amount`, `shipping_address`, `notes`) VALUES
('ORD-2024-001', 'ABC Company Ltd', 'orders@abccompany.com', 'pending', 'high', 159.95, '123 Business St, City, State 12345', 'Rush delivery requested'),
('ORD-2024-002', 'XYZ Corporation', 'purchasing@xyzcorp.com', 'processing', 'normal', 275.50, '456 Corporate Ave, City, State 67890', 'Standard shipping'),
('ORD-2024-003', 'Demo Customer', 'demo@customer.com', 'shipped', 'normal', 85.25, '789 Customer Rd, City, State 54321', 'Delivered successfully'),
('ORD-2024-004', 'Test Industries', 'orders@testind.com', 'completed', 'low', 45.00, '321 Industrial Blvd, City, State 98765', 'Completed order');

-- Insert sample order items
INSERT INTO `order_items` (`order_id`, `sku`, `quantity`, `unit_price`, `total_price`) VALUES
(1, 'DEMO-001', 2, 29.99, 59.98),
(1, 'DEMO-003', 1, 125.00, 125.00),
(2, 'DEMO-002', 20, 5.50, 110.00),
(2, 'DEMO-004', 5, 15.75, 78.75),
(3, 'DEMO-001', 1, 29.99, 29.99),
(3, 'DEMO-005', 10, 2.25, 22.50),
(4, 'DEMO-002', 5, 5.50, 27.50);

-- Insert sample movements
INSERT INTO `movements` (`sku`, `type`, `quantity`, `reason`, `reference`, `user_id`) VALUES
('DEMO-001', 'in', 150, 'Initial stock', 'STOCK-001', 1),
('DEMO-002', 'in', 300, 'Initial stock', 'STOCK-001', 1),
('DEMO-003', 'in', 100, 'Initial stock', 'STOCK-001', 1),
('DEMO-004', 'in', 250, 'Initial stock', 'STOCK-001', 1),
('DEMO-005', 'in', 600, 'Initial stock', 'STOCK-001', 1),
('DEMO-001', 'out', 50, 'Sales order', 'ORD-2024-001', 1),
('DEMO-002', 'out', 50, 'Sales order', 'ORD-2024-002', 1),
('DEMO-003', 'out', 25, 'Sales order', 'ORD-2024-001', 1),
('DEMO-004', 'out', 50, 'Sales order', 'ORD-2024-002', 1),
('DEMO-005', 'out', 100, 'Sales orders', 'MIXED', 1);

-- Create indexes for better performance
CREATE INDEX idx_inventory_sku ON inventory(sku);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_created ON orders(created_at);
CREATE INDEX idx_movements_sku ON movements(sku);
CREATE INDEX idx_movements_created ON movements(created_at);

-- Grant permissions (adjust as needed for your hosting)
-- GRANT ALL PRIVILEGES ON *.* TO 'wms_user'@'localhost';
-- FLUSH PRIVILEGES;
