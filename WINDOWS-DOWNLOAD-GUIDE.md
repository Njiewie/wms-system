# 💻 Windows Download Guide - WMS to Desktop

## 🎯 Download to: `C:\Users\Etoijeck Clovis\Desktop\new wms`

### Method 1: PowerShell (Recommended)

#### Step 1: Open PowerShell as Administrator
```powershell
# Press Win + X, select "Windows PowerShell (Admin)"
# Or search "PowerShell" in Start menu
```

#### Step 2: Navigate to Desktop
```powershell
cd "C:\Users\Etoijeck Clovis\Desktop"
```

#### Step 3: Create Folder and Download
```powershell
# Create the folder
New-Item -ItemType Directory -Name "new wms" -Force
cd "new wms"

# Download using SCP (if OpenSSH is installed)
scp -r same@your-same-server-ip:/home/project/wms-github-export/* ./

# Alternative: Download as archive first
scp same@your-same-server-ip:/home/project/wms-complete.tar.gz ./
```

### Method 2: WSL (Windows Subsystem for Linux) - Best Option

#### Step 1: Install WSL (if not installed)
```powershell
# In PowerShell as Admin
wsl --install
# Restart computer when prompted
```

#### Step 2: Use WSL to Download
```bash
# Open WSL (search "Ubuntu" or "WSL" in Start menu)
cd "/mnt/c/Users/Etoijeck Clovis/Desktop"
mkdir "new wms"
cd "new wms"

# Download complete project
scp -r same@your-same-server-ip:/home/project/wms-github-export/* ./

# Or download as archive
scp same@your-same-server-ip:/home/project/wms-complete.tar.gz ./
tar -xzf wms-complete.tar.gz
```

### Method 3: WinSCP (GUI Tool) - Easiest

#### Step 1: Download WinSCP
```
1. Go to: https://winscp.net/eng/download.php
2. Download and install WinSCP
```

#### Step 2: Connect and Download
```
1. Open WinSCP
2. Session settings:
   - Protocol: SCP
   - Host name: your-same-server-ip
   - User name: same
   - Password: [your password]
3. Click "Login"
4. Navigate to: /home/project/
5. Select: wms-github-export folder
6. Right-click → Download
7. Save to: C:\Users\Etoijeck Clovis\Desktop\new wms\
```

### Method 4: Create Archive First (Recommended for Large Files)

#### Step 1: Create Archive on Server
```bash
# Connect via SSH first
ssh same@your-same-server-ip

# Create compressed archive
cd /home/project
tar -czf wms-system.tar.gz \
    wms-github-export/ \
    ecwms-modern-ui/ \
    *.php \
    *.css \
    *.sql \
    *.md \
    uploads/ \
    --exclude=node_modules \
    --exclude=.next

# Exit SSH
exit
```

#### Step 2: Download Archive to Windows
```powershell
# In PowerShell
cd "C:\Users\Etoijeck Clovis\Desktop"
New-Item -ItemType Directory -Name "new wms" -Force
cd "new wms"

# Download the archive
scp same@your-same-server-ip:/home/project/wms-system.tar.gz ./
```

#### Step 3: Extract Archive
```powershell
# Install 7-Zip if not installed
# Download from: https://www.7-zip.org/

# Extract using 7-Zip
& "C:\Program Files\7-Zip\7z.exe" x wms-system.tar.gz
& "C:\Program Files\7-Zip\7z.exe" x wms-system.tar

# Or use Windows built-in (for .zip files)
# If you created a .zip instead of .tar.gz
```

### Method 5: PowerShell with OpenSSH

#### Step 1: Install OpenSSH (if not installed)
```powershell
# Check if installed
Get-WindowsCapability -Online | Where-Object Name -like 'OpenSSH*'

# Install if needed
Add-WindowsCapability -Online -Name OpenSSH.Client~~~~0.0.1.0
```

#### Step 2: Download Files
```powershell
# Navigate to destination
cd "C:\Users\Etoijeck Clovis\Desktop"
New-Item -ItemType Directory -Name "new wms" -Force
cd "new wms"

# Download individual folders
scp -r same@your-same-server-ip:/home/project/ecwms-modern-ui ./frontend
scp -r same@your-same-server-ip:/home/project/uploads ./backend
scp same@your-same-server-ip:/home/project/*.php ./backend/
scp same@your-same-server-ip:/home/project/*.css ./backend/
scp same@your-same-server-ip:/home/project/*.sql ./database/
scp same@your-same-server-ip:/home/project/*.md ./docs/
```

## 🔧 Step-by-Step for Complete Beginners

### Easy Method: WinSCP GUI

#### Step 1: Download WinSCP
```
1. Go to https://winscp.net
2. Click "Download WinSCP"
3. Install the downloaded file
```

#### Step 2: Connect to Server
```
1. Open WinSCP
2. Fill in connection details:
   - File protocol: SCP
   - Host name: [your Same server IP]
   - User name: same
   - Password: [your password]
3. Click "Login"
```

#### Step 3: Navigate and Download
```
1. On the server side (right panel):
   - Navigate to: /home/project/
   - You'll see: wms-github-export, ecwms-modern-ui, *.php files, uploads folder

2. On your computer side (left panel):
   - Navigate to: C:\Users\Etoijeck Clovis\Desktop\
   - Create folder: "new wms"
   - Enter the folder

3. Download files:
   - Select all files/folders on server side
   - Drag and drop to local side
   - Or use F5 (Copy) button
```

### PowerShell Method (Copy-Paste Commands)

#### Complete Command Set:
```powershell
# Open PowerShell and run these commands one by one:

# Navigate to desktop
cd "C:\Users\Etoijeck Clovis\Desktop"

# Create folder
New-Item -ItemType Directory -Name "new wms" -Force
cd "new wms"

# Create subfolders
New-Item -ItemType Directory -Name "frontend" -Force
New-Item -ItemType Directory -Name "backend" -Force
New-Item -ItemType Directory -Name "database" -Force
New-Item -ItemType Directory -Name "docs" -Force

# Download files (replace YOUR-SERVER-IP with actual IP)
scp -r same@YOUR-SERVER-IP:/home/project/ecwms-modern-ui/* ./frontend/
scp -r same@YOUR-SERVER-IP:/home/project/uploads ./backend/
scp same@YOUR-SERVER-IP:/home/project/*.php ./backend/
scp same@YOUR-SERVER-IP:/home/project/*.css ./backend/
scp same@YOUR-SERVER-IP:/home/project/*.sql ./database/
scp same@YOUR-SERVER-IP:/home/project/*.md ./docs/
```

## 🎯 After Download - Verify Files

### Check Your Downloaded Files
```powershell
# Navigate to your folder
cd "C:\Users\Etoijeck Clovis\Desktop\new wms"

# List contents
Get-ChildItem -Recurse | Select-Object Name, Length

# Check main folders
ls frontend/
ls backend/
ls database/
ls docs/
```

### Expected File Structure
```
C:\Users\Etoijeck Clovis\Desktop\new wms\
├── frontend\                 # React/Next.js app
│   ├── src\
│   ├── package.json
│   ├── next.config.js
│   └── ...
├── backend\                  # PHP files
│   ├── secure-dashboard.php
│   ├── uploads\
│   ├── *.css files
│   └── ...
├── database\                 # Database files
│   ├── test_database.sql
│   └── db_config_free.php
└── docs\                     # Documentation
    └── *.md files
```

## 🚀 Next Steps After Download

### 1. Install Node.js (for React frontend)
```
1. Go to: https://nodejs.org
2. Download LTS version
3. Install with default settings
```

### 2. Test Frontend Locally
```powershell
# In PowerShell
cd "C:\Users\Etoijeck Clovis\Desktop\new wms\frontend"
npm install
npm run dev
# Opens http://localhost:3000
```

### 3. Test Backend (Optional)
```powershell
# Install PHP if needed: https://windows.php.net
cd "C:\Users\Etoijeck Clovis\Desktop\new wms\backend"
php -S localhost:8000
# Opens http://localhost:8000
```

### 4. Upload to GitHub
```powershell
# Install Git: https://git-scm.com/download/win
cd "C:\Users\Etoijeck Clovis\Desktop\new wms"
git init
git add .
git commit -m "Complete WMS system"
git remote add origin https://github.com/yourusername/wms-system.git
git push -u origin main
```

## 🆘 Troubleshooting

### Connection Issues
```powershell
# Test SSH connection first
ssh same@your-server-ip

# If that works, then SCP should work
```

### Permission Denied
```powershell
# Try with verbose mode
scp -v same@your-server-ip:/home/project/test.txt ./
```

### Large File Transfer
```powershell
# Use compression for faster transfer
scp -C -r same@your-server-ip:/home/project/wms-github-export ./
```

## 💡 Pro Tips

1. **Use WinSCP** if you're not comfortable with command line
2. **Create archive first** for faster download of many files
3. **Use WSL** for best Linux compatibility
4. **Test connection** with simple file first

**Choose the method you're most comfortable with and start downloading!** 🚀
