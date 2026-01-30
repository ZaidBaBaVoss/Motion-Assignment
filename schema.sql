CREATE DATABASE IF NOT EXISTS user_management;
USE user_management;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(15) NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    gender ENUM('Male', 'Female') NOT NULL DEFAULT 'Male',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
