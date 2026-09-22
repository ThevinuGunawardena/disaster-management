-- Disaster Management System Database Schema
CREATE DATABASE IF NOT EXISTS disaster_management;
USE disaster_management;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    district VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Camps Table
CREATE TABLE IF NOT EXISTS camps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    camp_name VARCHAR(255) NOT NULL,
    district VARCHAR(100) NOT NULL,
    capacity INT NOT NULL,
    current_population INT NOT NULL DEFAULT 0,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    managed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (managed_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Families Table
CREATE TABLE IF NOT EXISTS families (
    id INT AUTO_INCREMENT PRIMARY KEY,
    camp_id INT NOT NULL,
    family_head_name VARCHAR(255) NOT NULL,
    members_count INT NOT NULL,
    infants_count INT NOT NULL DEFAULT 0,
    special_needs_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE
);

-- Supply Requests Table
CREATE TABLE IF NOT EXISTS supply_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    camp_id INT NOT NULL,
    item_type VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    requested_by INT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Seed Initial Demo Users (Password for all accounts is: password123)
INSERT INTO users (id, username, password, role, district) VALUES
(1, 'officer1', '$2y$10$Ojhv1TZTpVuszfWWUNI7T.X3oVWk6OYKHfO9lTFcZ39a6zbChEFYC', 'Camp Officer', 'Colombo'),
(2, 'admin_colombo', '$2y$10$Ojhv1TZTpVuszfWWUNI7T.X3oVWk6OYKHfO9lTFcZ39a6zbChEFYC', 'District Admin', 'Colombo'),
(3, 'national_admin', '$2y$10$Ojhv1TZTpVuszfWWUNI7T.X3oVWk6OYKHfO9lTFcZ39a6zbChEFYC', 'National Authority', 'All')
ON DUPLICATE KEY UPDATE username=VALUES(username);

-- Seed Sample Camps in Sri Lanka
INSERT INTO camps (id, camp_name, district, capacity, current_population, latitude, longitude, managed_by) VALUES
(1, 'Sugathadasa Relief Center', 'Colombo', 500, 120, 6.94580000, 79.86980000, 1),
(2, 'Kaduwela Community Shelter', 'Colombo', 300, 75, 6.93330000, 79.98330000, 1)
ON DUPLICATE KEY UPDATE camp_name=VALUES(camp_name);

-- Seed Sample Families
INSERT INTO families (id, camp_id, family_head_name, members_count, infants_count, special_needs_details) VALUES
(1, 1, 'Sunil Perera', 4, 1, 'Elderly grandmother requires wheelchair access'),
(2, 1, 'Mohamed Rizwan', 5, 2, 'Infant nutritional supplements needed'),
(3, 2, 'Kamal Silva', 3, 0, 'None')
ON DUPLICATE KEY UPDATE family_head_name=VALUES(family_head_name);

-- Seed Sample Supply Requests
INSERT INTO supply_requests (id, camp_id, item_type, quantity, requested_by, status) VALUES
(1, 1, 'Drinking Water (Bottles 5L)', 200, 1, 'Pending'),
(2, 1, 'Dry Rations & Canned Food Packs', 150, 1, 'Pending'),
(3, 1, 'Baby Formula & Milk Powder', 50, 1, 'Approved'),
(4, 2, 'Medical First Aid Kits', 40, 1, 'Pending')
ON DUPLICATE KEY UPDATE item_type=VALUES(item_type);
