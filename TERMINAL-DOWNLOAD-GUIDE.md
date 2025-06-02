# 💻 Terminal Download Guide - WMS to Local PC

## 🎯 Multiple Download Methods

Choose the method that works best for your setup:

### Method 1: Direct File Transfer (Recommended)

#### Using SCP (Secure Copy)
```bash
# Download entire project
scp -r same@your-same-server:/home/project/wms-github-export ./wms-system

# Download specific folders
scp -r same@your-same-server:/home/project/ecwms-modern-ui ./wms-frontend
scp -r same@your-same-server:/home/project/*.php ./wms-backend
scp -r same@your-same-server:/home/project/uploads ./wms-backend/
```

#### Using SFTP
```bash
# Connect via SFTP
sftp same@your-same-server

# Navigate and download
cd /home/project
get -r wms-github-export ./local-wms-system
get -r ecwms-modern-ui ./wms-frontend
get *.php ./wms-backend/
get -r uploads ./wms-backend/
exit
```

### Method 2: Create Archive and Download

#### Create tar.gz archive
```bash
# On the Same server (create archive)
cd /home/project
tar -czf wms-complete.tar.gz \
    ecwms-modern-ui/ \
    *.php \
    *.css \
    *.sql \
    *.md \
    uploads/ \
    --exclude=node_modules \
    --exclude=.next

# Download the archive
scp same@your-same-server:/home/project/wms-complete.tar.gz ./

# Extract locally
tar -xzf wms-complete.tar.gz
```

#### Create zip archive
```bash
# On Same server
cd /home/project
zip -r wms-system.zip \
    ecwms-modern-ui/ \
    *.php \
    *.css \
    *.sql \
    *.md \
    uploads/ \
    -x "*/node_modules/*" "*/.next/*" "*.git/*"

# Download zip
scp same@your-same-server:/home/project/wms-system.zip ./

# Extract locally (Windows)
unzip wms-system.zip

# Extract locally (Linux/Mac)
unzip wms-system.zip
```

### Method 3: Git Clone (If Git is Setup)

#### Initialize Git and Clone
```bash
# On Same server - initialize git
cd /home/project/wms-github-export
git init
git add .
git commit -m "Complete WMS system"

# If you have GitHub setup
git remote add origin https://github.com/yourusername/wms-system.git
git push -u origin main

# On your local PC - clone
git clone https://github.com/yourusername/wms-system.git
cd wms-system
```

### Method 4: Rsync (Linux/Mac/WSL)

#### Sync entire project
```bash
# Download with rsync (preserves permissions)
rsync -avz -e ssh same@your-same-server:/home/project/ ./wms-local/

# Download specific folders
rsync -avz -e ssh same@your-same-server:/home/project/ecwms-modern-ui/ ./wms-frontend/
rsync -avz -e ssh same@your-same-server:/home/project/uploads/ ./wms-backend/uploads/
```

### Method 5: Individual File Download

#### Download key files one by one
```bash
# Create local structure
mkdir -p wms-system/{frontend,backend,database,docs}

# Download frontend files
scp -r same@your-same-server:/home/project/ecwms-modern-ui/* ./wms-system/frontend/

# Download backend files
scp same@your-same-server:/home/project/*.php ./wms-system/backend/
scp same@your-same-server:/home/project/*.css ./wms-system/backend/
scp -r same@your-same-server:/home/project/uploads ./wms-system/backend/

# Download database files
scp same@your-same-server:/home/project/test_database.sql ./wms-system/database/
scp same@your-same-server:/home/project/db_config_free.php ./wms-system/database/

# Download documentation
scp same@your-same-server:/home/project/*.md ./wms-system/docs/
```

## 🖥️ Platform-Specific Instructions

### Windows (PowerShell/Command Prompt)

#### Using WSL (Windows Subsystem for Linux)
```bash
# Install WSL if not already installed
wsl --install

# Use any Linux method above in WSL
wsl
# Then use scp, rsync, etc.
```

#### Using Windows PowerShell with OpenSSH
```powershell
# Download via PowerShell (if OpenSSH is installed)
scp -r same@your-same-server:/home/project/wms-github-export C:\wms-system

# Or use PSFTP (PuTTY)
psftp same@your-same-server
cd /home/project
get -r wms-github-export
exit
```

#### Using WinSCP (GUI Tool)
```bash
# Download WinSCP from https://winscp.net
# Connect to: your-same-server
# Username: same
# Navigate to: /home/project
# Download: wms-github-export folder
```

### Linux

#### Ubuntu/Debian
```bash
# Install required tools
sudo apt update
sudo apt install openssh-client rsync

# Use any method above
scp -r same@your-same-server:/home/project/wms-github-export ./wms-system
```

#### CentOS/RHEL/Fedora
```bash
# Install required tools
sudo yum install openssh-clients rsync
# or
sudo dnf install openssh-clients rsync

# Download project
scp -r same@your-same-server:/home/project/wms-github-export ./wms-system
```

### macOS

#### Using Terminal
```bash
# macOS has SSH built-in
scp -r same@your-same-server:/home/project/wms-github-export ./wms-system

# Or use rsync
rsync -avz -e ssh same@your-same-server:/home/project/ ./wms-local/
```

## 🚀 Quick Commands for Each Platform

### One-Command Download (All Platforms)
```bash
# Create and download complete project
mkdir wms-system && cd wms-system
scp -r same@your-same-server:/home/project/wms-github-export/* ./
```

### Verify Download
```bash
# Check downloaded files
ls -la wms-system/
ls -la wms-system/frontend/
ls -la wms-system/backend/
ls -la wms-system/database/

# Check file sizes
du -sh wms-system/
du -sh wms-system/*/
```

## 🔧 Connection Troubleshooting

### SSH Connection Issues
```bash
# Test SSH connection first
ssh same@your-same-server

# If connection fails, try:
ssh -v same@your-same-server  # verbose mode
ssh -p 2222 same@your-same-server  # different port
```

### Permission Issues
```bash
# If permission denied
ssh-copy-id same@your-same-server
# Or use password authentication
scp -o PasswordAuthentication=yes -r same@your-same-server:/home/project/wms-github-export ./
```

### Large File Transfer
```bash
# For large transfers, use compression
scp -C -r same@your-same-server:/home/project/wms-github-export ./
# Or use rsync with compression
rsync -avz --compress-level=9 -e ssh same@your-same-server:/home/project/ ./wms-local/
```

## 📁 Local Project Structure After Download

```
wms-system/
├── frontend/                 # React/Next.js application
│   ├── src/
│   ├── package.json
│   ├── next.config.js
│   └── ...
├── backend/                  # PHP secure backend
│   ├── secure-dashboard.php
│   ├── uploads/
│   ├── *.css
│   └── ...
├── database/                 # Database configs and schema
│   ├── test_database.sql
│   └── db_config_free.php
└── docs/                     # Documentation
    ├── deployment guides
    └── security docs
```

## 🎯 Next Steps After Download

### 1. Setup Local Development
```bash
# Navigate to frontend
cd wms-system/frontend
npm install
npm run dev
# Frontend runs on http://localhost:3000
```

### 2. Setup Local PHP Backend
```bash
# Navigate to backend
cd wms-system/backend
php -S localhost:8000
# Backend runs on http://localhost:8000
```

### 3. Setup Local Database
```bash
# Import database
mysql -u root -p < database/test_database.sql
# Update database config in backend files
```

### 4. Upload to GitHub
```bash
# Initialize git
cd wms-system
git init
git add .
git commit -m "Complete WMS system"
git remote add origin https://github.com/yourusername/wms-system.git
git push -u origin main
```

## 💡 Pro Tips

### Fastest Method
```bash
# Single command to get everything
scp -C -r same@your-same-server:/home/project/wms-github-export ./wms-system
```

### Exclude Large Files
```bash
# Skip node_modules and build files
rsync -avz --exclude 'node_modules' --exclude '.next' --exclude '*.log' \
    -e ssh same@your-same-server:/home/project/ ./wms-local/
```

### Resume Interrupted Transfer
```bash
# Rsync can resume interrupted transfers
rsync -avz --partial --progress -e ssh \
    same@your-same-server:/home/project/ ./wms-local/
```

**Choose the method that works best for your setup and run the commands!** 🚀
