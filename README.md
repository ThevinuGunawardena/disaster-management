# Disaster Management System

A web-based disaster displacement management platform built with PHP and MySQL.

---

## 🚀 How to Run (After a PC Restart)

### Option 1: 1-Click Launch (Easiest)
Double-click the **`run.bat`** file in this repository folder:
```text
C:\Users\Administrator\Documents\GitHub\disaster-management\run.bat
```
This script will automatically:
1. Start the MySQL database engine (if not already running).
2. Start the PHP server on port `8000`.
3. Open `http://localhost:8000/login.php` in your default browser.

---

### Option 2: Manual Start

#### 1. Start MySQL
- **Via XAMPP Control Panel**: Open XAMPP Control Panel and click **Start** next to **MySQL**.
- *OR via PowerShell / Command Prompt:*
  ```powershell
  Start-Process "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=C:\xampp\mysql\bin\my.ini" -WindowStyle Hidden
  ```

#### 2. Start the PHP Web Server
Open PowerShell or Command Prompt, navigate to this project, and run:
```powershell
cd "C:\Users\Administrator\Documents\GitHub\disaster-management\disaster-management"
& "C:\xampp\php\php.exe" -S localhost:8000
```

#### 3. Open the Browser
Open your browser and navigate to:
```text
http://localhost:8000/login.php
```

---

## 👥 Demo Logins

All seeded accounts use password: **`password123`**

| Role | Username | Password | District | Capabilities |
|---|---|---|---|---|
| **Camp Officer** | `officer1` | `password123` | Colombo | Register camps, record families, request supplies |
| **District Admin** | `admin_colombo` | `password123` | Colombo | Approve or reject supply requests |
| **National Authority** | `national_admin` | `password123` | All | Island-wide interactive map and nationwide statistics |