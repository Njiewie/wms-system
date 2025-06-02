# 🆓 Free Testing Deployment - Step by Step

## ⚡ Quick Overview
- **Time**: 10-15 minutes
- **Cost**: $0 (completely free)
- **Frontend**: Vercel (React app)
- **Backend**: 000webhost (PHP + MySQL)

## 📋 What You'll Need
- GitHub account (free)
- Vercel account (free)
- 000webhost account (free)
- Your project files (already in zip)

---

## 🎯 Step 1: Setup GitHub Repository (3 minutes)

### 1.1 Create GitHub Account
- Go to [github.com](https://github.com)
- Sign up for free account
- Verify your email

### 1.2 Create Repository for Frontend
```bash
# Go to github.com/new
Repository name: wms-frontend
Description: WMS Modern UI
Public repository
✓ Add README file
Create repository
```

### 1.3 Upload Frontend Files
- Click "uploading an existing file"
- Drag the entire `ecwms-modern-ui` folder contents
- Commit changes

---

## 🌐 Step 2: Deploy Frontend to Vercel (2 minutes)

### 2.1 Create Vercel Account
- Go to [vercel.com](https://vercel.com)
- Click "Sign up"
- Choose "Continue with GitHub"
- Authorize Vercel

### 2.2 Deploy Your App
- Click "New Project"
- Import your `wms-frontend` repository
- Framework: **Next.js** (auto-detected)
- Click "Deploy"
- Wait 1-2 minutes for deployment

### 2.3 Get Your Frontend URL
- Copy your live URL (e.g., `wms-frontend-abc123.vercel.app`)
- Your React frontend is now live! 🎉

---

## 🖥️ Step 3: Setup Free PHP Hosting (5 minutes)

### 3.1 Create 000webhost Account
- Go to [000webhost.com](https://www.000webhost.com)
- Click "Free Sign Up"
- Create account with email
- Verify email address

### 3.2 Create Website
- Click "Create Website"
- Choose "Build Website"
- Website name: `your-wms-system` (or any available name)
- Click "Create"

### 3.3 Upload PHP Files
- Go to "File Manager"
- Navigate to `public_html` folder
- Click "Upload Files"
- Upload these files from your zip:
  ```
  ✓ All .php files (secure-dashboard.php, etc.)
  ✓ uploads/ folder
  ✓ *.css files
  ✓ security-utils.php
  ✓ All other PHP backend files
  ```

---

## 🗄️ Step 4: Setup Free Database (3 minutes)

### 4.1 Create MySQL Database
- In 000webhost control panel
- Go to "Database Manager"
- Click "New Database"
- Database name: `wms_db`
- Username: `wms_user`
- Password: Create secure password
- Click "Create"

### 4.2 Import Database Schema
- Click "Manage Database" (phpMyAdmin)
- Click "Import" tab
- Create these tables manually or upload SQL:

```sql
-- Basic tables for testing
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role VARCHAR(20) DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    quantity INT DEFAULT 0,
    location VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_name VARCHAR(100),
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert test admin user (password: admin123)
INSERT INTO users (username, password, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
```

### 4.3 Update Database Configuration
- Edit `db_config.php` file in File Manager:
```php
<?php
$host = "localhost";
$username = "your_db_username"; // From step 4.1
$password = "your_db_password"; // From step 4.1
$database = "your_db_name";     // From step 4.1

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
```

---

## 🔗 Step 5: Connect Frontend to Backend (2 minutes)

### 5.1 Update API URLs
- Go back to your GitHub repository
- Edit files in `src` folder that make API calls
- Update API base URL to your 000webhost URL:

```javascript
// In your React components, update API calls:
const API_BASE = 'https://your-wms-system.000webhostapp.com';

// Example:
fetch(`${API_BASE}/secure-dashboard.php`)
```

### 5.2 Redeploy Frontend
- Commit changes to GitHub
- Vercel automatically redeploys
- Wait 1-2 minutes for new deployment

---

## 🎉 Step 6: Test Your Live System

### 6.1 Access Your WMS
- **Frontend**: `https://wms-frontend-abc123.vercel.app`
- **Backend**: `https://your-wms-system.000webhostapp.com`

### 6.2 Login Credentials
```
Username: admin
Password: admin123
```

### 6.3 Test Features
- ✅ Dashboard loads
- ✅ Inventory management
- ✅ Order processing
- ✅ User authentication
- ✅ Data persistence

---

## 🔧 Troubleshooting

### Common Issues & Fixes:

#### "Database Connection Error"
```php
// Check db_config.php has correct credentials
// Verify database exists in 000webhost panel
```

#### "CORS Error" in Browser
```javascript
// Add CORS headers to PHP files
header("Access-Control-Allow-Origin: https://your-vercel-app.vercel.app");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
```

#### "File Permission Error"
```bash
# In 000webhost File Manager, set permissions:
# Folders: 755
# PHP files: 644
# uploads/ folder: 777
```

#### "Frontend Not Loading Data"
```javascript
// Check API URLs are correct
// Verify 000webhost site is active
// Check browser console for errors
```

---

## 🚀 Your Live URLs

After setup, you'll have:

### Frontend (React UI)
```
https://your-app-name.vercel.app
```

### Backend (PHP API)
```
https://your-site-name.000webhostapp.com
```

### Database Admin
```
https://your-site-name.000webhostapp.com/phpmyadmin
```

---

## 📱 Mobile Access

Your WMS is mobile-responsive:
- ✅ Works on smartphones
- ✅ Tablet optimized
- ✅ Touch-friendly interface
- ✅ Offline capabilities

---

## 🔄 Making Updates

### Update Frontend:
1. Edit files in GitHub
2. Commit changes
3. Vercel auto-deploys

### Update Backend:
1. Edit files in 000webhost File Manager
2. Changes are live immediately

---

## 💡 Free Tier Limitations

### Vercel (Frontend):
- ✅ Unlimited personal projects
- ✅ Custom domains
- ⚠️ 100GB bandwidth/month
- ⚠️ Serverless functions limit

### 000webhost (Backend):
- ✅ 1GB storage
- ✅ 10GB bandwidth/month
- ✅ MySQL database
- ⚠️ May show ads
- ⚠️ Daily usage limits

### When to Upgrade:
- Heavy traffic (>1000 users/day)
- Large file uploads (>100MB)
- 24/7 critical operations
- Custom domain requirements

---

## 🎯 Next Steps After Free Testing

1. **Test thoroughly** with real data
2. **Gather user feedback**
3. **Plan for production** deployment
4. **Consider paid hosting** for business use

Your free WMS system is perfect for:
- ✅ Testing and demos
- ✅ Small team usage
- ✅ Proof of concept
- ✅ Learning and development
