# ⚡ TNB Electricity Meter Monitor

A lightweight, secure, and multi-language web-based platform designed to monitor daily electricity consumption, prevent reporting fraud, and automate threshold alerts for TNB electricity meters. 

This system helps organizations maintain precise utility logging by combining role-based physical tracking with instant notification gateway integrations.

---

## ✨ Features

- **🔒 Role-Based Access Control (RBAC):** Distinct interfaces and operational permissions for System Administrators and Site Operators.
- **🌐 Dynamic Multi-Language Support:** Instant switching between English, 中文, and Bahasa Melayu across all pages.
- **🌓 Adaptive Dark Mode:** Toggleable system-wide UI theme adjusting gracefully to ambient lighting conditions.
- **📸 Anti-Fraud Photo Verification:** Mobile-optimized interface enforcing live camera capture (`capture="environment"`) while restricting gallery uploads.
- **🤝 Operator-Meter Pairing:** Granular control allowing administrators to link specific operators to designated meters.
- **📈 Automatical Consumption Tracking:** Calculates daily differential usage against previous logs to flag anomalous consumption instantly.
- **🔔 Notification Gateways Integration:** Built-in cURL-based alert system supporting both Telegram Bot API and WhatsApp Gateways.
- **⏱️ Automated Reminder (Cron Daemon):** Integrated background scanner (`cron.php`) to identify unsubmitted logs after predefined daily cutoff times.

---

## 🏗️ Tech Stack

- **Backend Logic:** PHP 8.x (Vanilla, clean separation of database operations via PDO).
- **Database Engine:** MySQL / MariaDB (Structured with relational foreign key cascading and multi-column unique constraints).
- **Frontend Styling:** Tailwind CSS v3 (Responsive mobile-first utility layout) and Lucide Icons (Vector iconography).
- **Communication Protocols:** JSON/cURL REST APIs for communication with Telegram and WhatsApp gateway APIs.

---

## 🖼️ Project Screenshots & User Guide

### 📍 Step 1: Secure System Authentication
Access the platform through a unified secure login interface featuring responsive styling, theme toggles, and language select configurations.

<img width="1366" height="606" alt="image" src="https://github.com/user-attachments/assets/655d6487-900b-41a6-98b0-4a14ccf42e0f" />


### 📍 Step 2: Operator Daily Meter Recording
Operators submit daily readings by selecting their assigned meter, triggering their mobile device's camera for direct photo evidence, and typing the current meter reading in kWh.

<img width="1366" height="616" alt="image" src="https://github.com/user-attachments/assets/c03011fe-210d-4a15-917c-d04f7c42bad9" />


### 📍 Step 3: Administrator Console & Consumption Monitoring
Administrators can oversee all registered daily readings, evaluate calculated consumption differentials (24-hour delta), examine visual photo evidence, and filter tables instantly.

<img width="1128" height="326" alt="image" src="https://github.com/user-attachments/assets/bcbbbe2e-041f-4c8f-9a20-a1b82efeaa20" />


### 📍 Step 4: Asset Management & Operator-Meter Pairing
Manage system operators, register new physical meters with distinct threshold limits, and establish mapping relations.

<img width="723" height="1014" alt="image" src="https://github.com/user-attachments/assets/c84ed38a-14b0-483d-b3f5-bf6727bd86a0" />


### 📍 Step 5: System Settings & Notification Gateway Integration
Configure communication settings for Telegram Bot and WhatsApp APIs, setting specific daily reporting deadlines to govern reporting schedules.

<img width="370" height="524" alt="image" src="https://github.com/user-attachments/assets/5ec98761-c0ff-4859-9b37-f896a6c47ac7" />

---

## 🛠️ Project Setup

### Prerequisites
Before deploying the application, ensure your environment meets the following specifications:
- **Web Server:** Apache 2.4+ or Nginx
- **PHP Version:** PHP 8.0 or higher (with `pdo_mysql` and `curl` extensions enabled)
- **Database:** MySQL 5.7+ or MariaDB 10.3+

### Installation Steps

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/Archie-a11y/Android-Video-Editor.git
   cd Android-Video-Editor
   ```

2. **Database Setup:**
   - Create a database in your local environment (e.g., via phpMyAdmin or MySQL CLI) named `tnb_meter`.
   - Import the schema configuration from `db.sql`:
     ```bash
     mysql -u root -p tnb_meter < db.sql
     ```

3. **Database Configuration:**
   - Open `db.php` in a text editor.
   - Adjust the connection variables to match your database server details:
     ```php
     $host = 'localhost';
     $db   = 'tnb_meter';
     $user = 'your_username';
     $pass = 'your_password';
     ```

4. **Directory Permissions:**
   - Ensure the server has permission to write and store dynamic uploads. The system will automatically attempt to initialize an `uploads/` directory on the first file process. If required, set permissions manually:
     ```bash
     mkdir uploads
     chmod 775 uploads
     ```

5. **Initial Account Setup (Optional but recommended):**
   - Insert an administrative account into the `users` table via SQL command to access the portal first:
     ```sql
     INSERT INTO users (username, password_hash, role) 
     VALUES ('admin', '$2y$10$YourBcryptHashHere', 'admin');
     ```

6. **Configure Cron Daemon:**
   - To activate automatic daily checks for missing meter logs, configure a server cron job to execute `cron.php` daily after your set cutoff time (e.g., 18:00):
     ```text
     0 18 * * * php /path/to/your/project/cron.php >> /path/to/your/project/cron.log 2>&1
     ```
