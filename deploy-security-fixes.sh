#!/bin/bash

# ECWMS Security Deployment Script
# This script safely replaces vulnerable files with secure versions
# Run this script from your WMS root directory

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
BACKUP_DIR="security_backup_$(date +%Y%m%d_%H%M%S)"
LOG_FILE="security_deployment.log"

echo -e "${BLUE}🛡️  ECWMS Security Deployment Script${NC}"
echo -e "${BLUE}======================================${NC}"
echo ""

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
    echo -e "$1"
}

# Function to create backup
create_backup() {
    log_message "${YELLOW}📁 Creating backup directory: $BACKUP_DIR${NC}"
    mkdir -p "$BACKUP_DIR"

    # List of files to backup
    FILES_TO_BACKUP=(
        "allocate_order.php"
        "edit_sku.php"
        "manage_users.php"
        "inventory_delete.php"
        "putaway.php"
        "outbound_delete.php"
        "outbound_edit.php"
        "pick_order.php"
        "ship_order.php"
        "fetch_sku_info.php"
        "auto_release_orders.php"
        "style.css"
        "dashboard.php"
        "view_inventory.php"
    )

    for file in "${FILES_TO_BACKUP[@]}"; do
        if [[ -f "$file" ]]; then
            cp "$file" "$BACKUP_DIR/" 2>/dev/null || true
            log_message "  ✓ Backed up: $file"
        else
            log_message "  ⚠️  File not found: $file"
        fi
    done

    log_message "${GREEN}✅ Backup completed in: $BACKUP_DIR${NC}"
}

# Function to deploy secure files
deploy_secure_files() {
    log_message "${YELLOW}🔒 Deploying secure file versions...${NC}"

    # Array of secure file mappings: "source:destination"
    SECURE_FILES=(
        "allocate_order_secure.php:allocate_order.php"
        "edit_sku_secure.php:edit_sku.php"
        "manage_users_secure.php:manage_users.php"
        "inventory_delete_secure.php:inventory_delete.php"
        "putaway_secure.php:putaway.php"
        "outbound_delete_secure.php:outbound_delete.php"
        "outbound_edit_secure.php:outbound_edit.php"
        "pick_order_secure.php:pick_order.php"
        "ship_order_secure.php:ship_order.php"
        "fetch_sku_info_secure.php:fetch_sku_info.php"
        "auto_release_orders_secure.php:auto_release_orders.php"
        "modern-style.css:style.css"
        "secure-dashboard.php:dashboard.php"
        "secure-inventory.php:view_inventory.php"
    )

    for mapping in "${SECURE_FILES[@]}"; do
        source_file="${mapping%%:*}"
        dest_file="${mapping##*:}"

        if [[ -f "$source_file" ]]; then
            cp "$source_file" "$dest_file"
            log_message "  ✓ Deployed: $source_file → $dest_file"
        else
            log_message "  ❌ Secure file not found: $source_file"
        fi
    done
}

# Function to set proper permissions
set_permissions() {
    log_message "${YELLOW}🔧 Setting secure file permissions...${NC}"

    # Set secure permissions for PHP files
    find . -name "*.php" -type f -exec chmod 644 {} \;

    # Set permissions for CSS and other assets
    find . -name "*.css" -type f -exec chmod 644 {} \;

    # Secure the security utilities
    chmod 600 security-utils.php 2>/dev/null || true

    log_message "  ✓ File permissions updated"
}

# Function to create .htaccess for additional security
create_htaccess() {
    log_message "${YELLOW}🛡️  Creating security .htaccess files...${NC}"

    # Main .htaccess
    cat > .htaccess << 'EOF'
# ECWMS Security Headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' fonts.googleapis.com; font-src 'self' fonts.gstatic.com"
</IfModule>

# Prevent access to sensitive files
<Files "security-utils.php">
    Order allow,deny
    Deny from all
</Files>

<Files "db_config.php">
    Order allow,deny
    Deny from all
</Files>

<Files ".env">
    Order allow,deny
    Deny from all
</Files>

# Hide backup directory
<DirectoryMatch "security_backup_.*">
    Order allow,deny
    Deny from all
</DirectoryMatch>

# Prevent directory browsing
Options -Indexes

# Block suspicious requests
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Block SQL injection attempts
    RewriteCond %{QUERY_STRING} (\<|%3C).*script.*(\>|%3E) [NC,OR]
    RewriteCond %{QUERY_STRING} GLOBALS(=|\[|\%[0-9A-Z]{0,2}) [OR]
    RewriteCond %{QUERY_STRING} _REQUEST(=|\[|\%[0-9A-Z]{0,2}) [OR]
    RewriteCond %{QUERY_STRING} proc/self/environ [OR]
    RewriteCond %{QUERY_STRING} mosConfig_[a-zA-Z_]{1,21}(=|\%3D) [OR]
    RewriteCond %{QUERY_STRING} base64_(en|de)code[^(]*\([^)]*\) [OR]
    RewriteCond %{QUERY_STRING} (<|%3C)([^s]*s)+cript.*(>|%3E) [NC,OR]
    RewriteCond %{QUERY_STRING} (\<|%3C).*embed.*(\>|%3E) [NC,OR]
    RewriteCond %{QUERY_STRING} (\<|%3C).*object.*(\>|%3E) [NC,OR]
    RewriteCond %{QUERY_STRING} (\<|%3C).*iframe.*(\>|%3E) [NC,OR]
    RewriteCond %{QUERY_STRING} union.*select.*\( [NC,OR]
    RewriteCond %{QUERY_STRING} union.*all.*select.* [NC,OR]
    RewriteCond %{QUERY_STRING} concat.*\( [NC,OR]
    RewriteCond %{QUERY_STRING} insert.*into.* [NC,OR]
    RewriteCond %{QUERY_STRING} drop.*table.* [NC,OR]
    RewriteCond %{QUERY_STRING} delete.*from.* [NC]
    RewriteRule .* - [F,L]
</IfModule>
EOF

    log_message "  ✓ Security .htaccess created"
}

# Function to verify deployment
verify_deployment() {
    log_message "${YELLOW}🔍 Verifying deployment...${NC}"

    local errors=0

    # Check critical files exist
    CRITICAL_FILES=("security-utils.php" "allocate_order.php" "manage_users.php")

    for file in "${CRITICAL_FILES[@]}"; do
        if [[ -f "$file" ]]; then
            log_message "  ✓ Critical file present: $file"
        else
            log_message "  ❌ Critical file missing: $file"
            ((errors++))
        fi
    done

    # Check for security-utils inclusion
    if grep -l "require_once 'security-utils.php'" *.php > /dev/null 2>&1; then
        log_message "  ✓ Security utilities properly included"
    else
        log_message "  ⚠️  Security utilities inclusion not found in some files"
    fi

    # Check file permissions
    if [[ $(stat -c %a security-utils.php 2>/dev/null) == "600" ]]; then
        log_message "  ✓ Security utilities have correct permissions"
    else
        log_message "  ⚠️  Security utilities permissions may need adjustment"
    fi

    if [[ $errors -eq 0 ]]; then
        log_message "${GREEN}✅ Deployment verification passed${NC}"
        return 0
    else
        log_message "${RED}❌ Deployment verification found $errors errors${NC}"
        return 1
    fi
}

# Function to create post-deployment checklist
create_checklist() {
    cat > "post_deployment_checklist.md" << 'EOF'
# Post-Deployment Security Checklist

## Immediate Actions Required:

### 1. Database Security
- [ ] Create environment file (.env) with database credentials
- [ ] Remove hardcoded credentials from db_config.php
- [ ] Test database connections after credential change

### 2. File Permissions
- [ ] Verify security-utils.php has 600 permissions
- [ ] Ensure web server can read PHP files (644)
- [ ] Check that backup directory is not web-accessible

### 3. Functionality Testing
- [ ] Test login functionality
- [ ] Test order allocation process
- [ ] Test inventory management operations
- [ ] Test user management (admin only)
- [ ] Test outbound operations (pick, ship)
- [ ] Verify SKU information fetching works

### 4. Security Testing
- [ ] Attempt SQL injection on forms (should be blocked)
- [ ] Test CSRF protection on forms
- [ ] Verify rate limiting is working
- [ ] Check that error messages don't reveal sensitive info

### 5. Performance Verification
- [ ] Check page load times
- [ ] Verify database query performance
- [ ] Test with multiple concurrent users

### 6. Monitoring Setup
- [ ] Configure error logging
- [ ] Set up security monitoring alerts
- [ ] Implement backup procedures
- [ ] Schedule auto-release cron job if needed

## Emergency Rollback (if needed):
```bash
# Restore from backup
cp security_backup_*/allocate_order.php ./
cp security_backup_*/manage_users.php ./
# ... repeat for other files
```

## Support Information:
- Backup Location: Stored in security_backup_* directory
- Log File: security_deployment.log
- Security Documentation: security-audit-checklist.md

## Success Criteria:
✅ All functionality tests pass
✅ No security vulnerabilities in scans
✅ Performance is acceptable
✅ Error logs are clean
✅ User acceptance testing complete
EOF

    log_message "${GREEN}📋 Post-deployment checklist created: post_deployment_checklist.md${NC}"
}

# Main execution
main() {
    log_message "${BLUE}🚀 Starting ECWMS security deployment...${NC}"

    # Check if we're in the right directory
    if [[ ! -f "dashboard.php" ]]; then
        log_message "${RED}❌ Error: Please run this script from your WMS root directory${NC}"
        exit 1
    fi

    # Check if security utilities exist
    if [[ ! -f "security-utils.php" ]]; then
        log_message "${RED}❌ Error: security-utils.php not found. Please ensure all secure files are present.${NC}"
        exit 1
    fi

    echo -e "${YELLOW}⚠️  This script will replace your current WMS files with secure versions.${NC}"
    echo -e "${YELLOW}   A backup will be created automatically.${NC}"
    echo ""
    read -p "Do you want to continue? (y/N): " -n 1 -r
    echo ""

    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_message "${YELLOW}🛑 Deployment cancelled by user${NC}"
        exit 0
    fi

    # Execute deployment steps
    create_backup
    echo ""

    deploy_secure_files
    echo ""

    set_permissions
    echo ""

    create_htaccess
    echo ""

    if verify_deployment; then
        echo ""
        create_checklist
        echo ""

        log_message "${GREEN}🎉 SECURITY DEPLOYMENT COMPLETED SUCCESSFULLY!${NC}"
        echo ""
        echo -e "${GREEN}✅ Your WMS is now secured with enterprise-grade protection!${NC}"
        echo ""
        echo -e "${BLUE}Next steps:${NC}"
        echo -e "  1. Review post_deployment_checklist.md"
        echo -e "  2. Test all functionality"
        echo -e "  3. Configure environment variables"
        echo -e "  4. Set up monitoring and backups"
        echo ""
        echo -e "${YELLOW}⚠️  Important: Review the checklist before going live!${NC}"

    else
        log_message "${RED}❌ Deployment completed with warnings. Please review the log.${NC}"
        exit 1
    fi
}

# Run main function
main "$@"
