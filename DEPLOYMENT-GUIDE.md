# 🚀 WMS Deployment Guide

## Overview
Your warehouse management system consists of:
- **Backend**: PHP files with MySQL database
- **Frontend**: Next.js React application with modern UI
- **Assets**: CSS themes and configuration files

## 🏗️ Architecture Options

### Option 1: Full-Stack Deployment (Recommended)
Deploy both frontend and backend together for seamless integration.

### Option 2: Separate Deployment
Deploy frontend and backend separately for better scalability.

## 📋 Pre-Deployment Checklist

### Database Preparation
```sql
-- Create production database
CREATE DATABASE wms_production;
-- Import your schema and data
-- Update user permissions
```

### Environment Configuration
```php
// Update db_config.php for production
$host = "your-production-host";
$username = "your-db-user";
$password = "your-secure-password";
$database = "wms_production";
```

### Security Settings
- [ ] Change default passwords
- [ ] Enable HTTPS/SSL
- [ ] Configure firewall rules
- [ ] Set proper file permissions
- [ ] Enable security headers

## 🌐 Deployment Methods

### Method 1: Traditional Web Hosting

#### A. Shared Hosting (Easiest)
**Providers**: Bluehost, SiteGround, HostGator

**Steps:**
1. **Upload PHP files:**
   ```bash
   # Via cPanel File Manager or FTP
   - Upload all .php files to public_html/
   - Upload uploads/ directory
   - Set permissions: 644 for files, 755 for directories
   ```

2. **Database setup:**
   - Create MySQL database via cPanel
   - Import your SQL schema
   - Update db_config.php with new credentials

3. **Deploy React frontend:**
   ```bash
   # Option A: Static export
   cd ecwms-modern-ui
   npm run build
   npm run export
   # Upload 'out' folder contents to subdirectory

   # Option B: Use hosting's Node.js support
   # Upload entire ecwms-modern-ui folder
   # Run npm install && npm run build
   ```

#### B. VPS Hosting (More Control)
**Providers**: DigitalOcean, Linode, Vultr

**Server Setup:**
```bash
# Ubuntu 22.04 setup
sudo apt update && sudo apt upgrade -y

# Install LAMP stack
sudo apt install apache2 mysql-server php8.1 php8.1-mysql php8.1-curl php8.1-json -y

# Install Node.js for React app
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install nodejs -y

# Install SSL certificate
sudo apt install certbot python3-certbot-apache -y
```

**File Upload:**
```bash
# Upload via SCP/SFTP
scp -r *.php user@your-server:/var/www/html/
scp -r ecwms-modern-ui user@your-server:/var/www/html/

# Set permissions
sudo chown -R www-data:www-data /var/www/html/
sudo chmod -R 644 /var/www/html/*.php
sudo chmod -R 755 /var/www/html/uploads/
```

### Method 2: Cloud Deployment

#### A. AWS (Enterprise Grade)
```bash
# Infrastructure setup
1. EC2 instance (t3.medium or larger)
2. RDS MySQL instance
3. Application Load Balancer
4. CloudFront CDN (optional)
5. Route 53 for DNS

# Deployment script
#!/bin/bash
# Update system
sudo yum update -y

# Install Apache and PHP
sudo yum install httpd php php-mysqlnd -y

# Install Node.js
curl -sL https://rpm.nodesource.com/setup_18.x | sudo bash -
sudo yum install nodejs -y

# Start services
sudo systemctl start httpd
sudo systemctl enable httpd

# Deploy code
sudo cp *.php /var/www/html/
cd ecwms-modern-ui && npm install && npm run build
```

#### B. Google Cloud Platform
```bash
# App Engine deployment
# Create app.yaml for PHP
runtime: php81

# Create app.yaml for Node.js (React)
runtime: nodejs18
```

#### C. DigitalOcean App Platform
```yaml
# .do/app.yaml
name: wms-system
services:
- name: api
  source_dir: /
  github:
    repo: your-repo
    branch: main
  run_command: php -S 0.0.0.0:8080
  environment_slug: php
  instance_count: 1
  instance_size_slug: basic-xxs

- name: frontend
  source_dir: /ecwms-modern-ui
  github:
    repo: your-repo
    branch: main
  build_command: npm run build
  run_command: npm start
  environment_slug: node-js
  instance_count: 1
  instance_size_slug: basic-xxs
```

### Method 3: Modern Deployment (Recommended)

#### Frontend: Vercel/Netlify (React App)
```bash
# Vercel deployment
cd ecwms-modern-ui
npx vercel

# Netlify deployment
npm run build
# Drag 'out' folder to Netlify dashboard
```

#### Backend: Railway/Heroku (PHP API)
```bash
# Create Procfile
echo "web: php -S 0.0.0.0:\$PORT" > Procfile

# Deploy to Railway
railway login
railway init
railway up
```

## 🔧 Configuration Files

### Apache Virtual Host
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

### Nginx Configuration
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html;
    index index.php index.html;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

### PM2 Configuration (Node.js)
```javascript
// ecosystem.config.js
module.exports = {
  apps: [{
    name: 'wms-frontend',
    cwd: './ecwms-modern-ui',
    script: 'npm',
    args: 'start',
    env: {
      NODE_ENV: 'production',
      PORT: 3000
    }
  }]
}
```

## 🔐 Security Configuration

### SSL Certificate
```bash
# Let's Encrypt (Free)
sudo certbot --apache -d your-domain.com

# Or configure manually
sudo a2enmod ssl
# Add SSL configuration to virtual host
```

### Environment Variables
```bash
# Create .env file
DB_HOST=your-db-host
DB_USER=your-db-user
DB_PASS=your-db-password
DB_NAME=wms_production
APP_ENV=production
```

### Security Headers
```apache
# Add to .htaccess
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

## 📊 Production Optimizations

### PHP Optimizations
```ini
# php.ini settings
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 50M
post_max_size = 50M
opcache.enable = 1
```

### Database Optimizations
```sql
-- Add indexes for performance
CREATE INDEX idx_sku ON inventory(sku);
CREATE INDEX idx_status ON orders(status);
CREATE INDEX idx_date ON movements(created_at);
```

### Frontend Optimizations
```bash
# Build optimizations
cd ecwms-modern-ui
npm run build
# Files are automatically optimized by Next.js
```

## 🔍 Monitoring & Maintenance

### Health Checks
```bash
# Create health check endpoints
# /health.php
<?php
echo json_encode(['status' => 'healthy', 'timestamp' => time()]);
?>
```

### Backup Strategy
```bash
# Database backup
mysqldump -u user -p wms_production > backup_$(date +%Y%m%d).sql

# File backup
tar -czf files_backup_$(date +%Y%m%d).tar.gz /var/www/html/
```

### Log Monitoring
```bash
# Apache logs
tail -f /var/log/apache2/error.log

# PHP logs
tail -f /var/log/php_errors.log

# Application logs
tail -f /var/www/html/logs/app.log
```

## 🚀 Quick Start Commands

### For Shared Hosting:
1. Upload files via FTP
2. Create database via cPanel
3. Update db_config.php
4. Access your domain

### For VPS:
```bash
# Clone or upload your files
git clone your-repo.git /var/www/html/
cd /var/www/html/

# Set permissions
sudo chown -R www-data:www-data .
sudo chmod -R 644 *.php
sudo chmod -R 755 uploads/

# Install dependencies (if using modern frontend)
cd ecwms-modern-ui
npm install
npm run build
```

### For Cloud:
- Use respective cloud CLI tools
- Follow cloud-specific deployment guides
- Configure environment variables
- Set up monitoring and alerts

## 📞 Support & Troubleshooting

### Common Issues:
1. **Database connection errors**: Check credentials and host
2. **File permission issues**: Set correct chmod permissions
3. **SSL certificate problems**: Verify certificate installation
4. **Performance issues**: Enable caching and optimize queries

### Useful Commands:
```bash
# Check Apache status
sudo systemctl status apache2

# Check PHP version
php -v

# Check database connection
mysql -u user -p -h host

# Monitor server resources
htop
df -h
```

---

## 🎯 Recommended Deployment Strategy

For production use, I recommend:

1. **Frontend**: Deploy React app to Vercel/Netlify (free tier available)
2. **Backend**: Deploy PHP to VPS (DigitalOcean $5/month droplet)
3. **Database**: Use managed MySQL (DigitalOcean Managed Database or AWS RDS)
4. **Domain**: Point to your services with proper SSL

This setup provides:
- ✅ High performance
- ✅ Scalability
- ✅ Cost-effective
- ✅ Easy maintenance
- ✅ Professional grade security

Total monthly cost: ~$15-30 for small to medium usage.
