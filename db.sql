CREATE DATABASE IF NOT EXISTS tnb_meter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tnb_meter;

-- 1. 用户表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. 电表表
CREATE TABLE IF NOT EXISTS meters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meter_name VARCHAR(100) NOT NULL UNIQUE,
    building_name VARCHAR(100) NOT NULL,
    usage_limit DOUBLE NOT NULL DEFAULT 80.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. 抄表历史记录表
CREATE TABLE IF NOT EXISTS readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meter_id INT NOT NULL,
    user_id INT NOT NULL,
    reading_value DOUBLE NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    submitted_date DATE NOT NULL,
    submitted_at DATETIME NOT NULL,
    FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_meter_date (meter_id, submitted_date)
) ENGINE=InnoDB;

-- 4. 自定义配置参数表
CREATE TABLE IF NOT EXISTS configs (
    cfg_key VARCHAR(50) PRIMARY KEY,
    cfg_value TEXT NULL
) ENGINE=InnoDB;

-- 5. 操作员与电表配对表 (新增关系表) [2]
CREATE TABLE IF NOT EXISTS user_meters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    meter_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_meter (user_id, meter_id)
) ENGINE=InnoDB;