
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!-- Sticky Header -->
<div id="sticky-bar">
  <div class="left">📦 <strong>ECWMS</strong></div>
  <div class="center">👤 Username: <strong><?= $_SESSION['user'] ?? 'User' ?></strong></div>
  <div class="right">
    <span id="datetime"></span>
    <button id="openSearchBtn" class="search-btn">🔍 Search</button>
    <button class="logout-btn" onclick="openLogoutModal()">🔒 Logout</button>
  </div>
</div>


<!-- Search Modal -->
<div id="searchModal" class="modal">
  <div class="modal-content">
    <h3 style="margin-top: 0; color: #0c4a6e; font-size: 18px;">🔍 Search on ECWMS</h3>
    <input id="searchInput" type="text" placeholder="Search screen..." />
    <ul id="searchSuggestions"></ul>
    <div class="modal-actions">
      <button id="cancelSearch">Cancel</button>
    </div>
  </div>
</div>

<!-- Logout Modal -->
<div id="logoutModal" class="modal">
  <div class="modal-content">
    <p style="font-size: 15px; margin-bottom: 15px;">Are you sure you want to log out?</p>
    <div class="modal-actions">
      <a href="logout.php"><button style="background:#f44336;color:white;">Yes, Logout</button></a>
      <button onclick="closeLogoutModal()">Cancel</button>
    </div>
  </div>
</div>

<style>
  #sticky-bar {
    position: sticky;
    top: 0;
    background: #0c4a6e;
    color: white;
    padding: 8px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-family: Arial, sans-serif;
    z-index: 1000;
    font-size: 14px;
  }

  #sticky-bar .right {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .logout-btn {
    background: #f44336;
    color: white;
    padding: 6px 12px;
    text-decoration: none;
    border: none;
    border-radius: 4px;
    font-weight: bold;
    cursor: pointer;
  }

  .logout-btn:hover {
    background: #d32f2f;
  }

  .search-btn {
    background: white;
    color: #0c4a6e;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
  }

  /* Modal styling */
  .modal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    backdrop-filter: blur(3px);
    background-color: rgba(0, 0, 0, 0.3);
    z-index: 9999;
    justify-content: center;
    align-items: center;
  }

  .modal-content {
    background: white;
    padding: 20px;
    border-radius: 10px;
    width: 300px;
    max-width: 80%;
    box-shadow: 0 0 10px rgba(0,0,0,0.3);
  }

  .modal-content input {
    width: 100%;
    padding: 8px;
    font-size: 14px;
  }

  .modal-content ul {
    list-style: none;
    padding-left: 0;
    margin-top: 10px;
    max-height: 150px;
    overflow-y: auto;
  }

  .modal-content ul li {
    padding: 6px;
    border-bottom: 1px solid #ddd;
    cursor: pointer;
  }

  .modal-content ul li:hover {
    background-color: #f0f0f0;
  }

  .modal-actions {
    text-align: right;
    margin-top: 10px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }

  .modal-actions button {
    padding: 6px 10px;
    background: #ccc;
    border: none;
    border-radius: 4px;
    cursor: pointer;
  }

  .modal-actions button:hover {
    background: #bbb;
  }
</style>

<script>
function updateDateTime() {
  const now = new Date();
  document.getElementById('datetime').textContent = now.toLocaleString();
}
setInterval(updateDateTime, 1000);
updateDateTime();

// Logout Modal Controls
function openLogoutModal() {
  document.getElementById("logoutModal").style.display = "flex";
}
function closeLogoutModal() {
  document.getElementById("logoutModal").style.display = "none";
}

// Search Modal Controls
document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('searchModal');
  const openBtn = document.getElementById('openSearchBtn');
  const cancelBtn = document.getElementById('cancelSearch');
  const searchInput = document.getElementById('searchInput');
  const suggestions = document.getElementById('searchSuggestions');

  const screens = [
  { name: "Dashboard", url: "dashboard.php" },
  { name: "Add SKU", url: "add_sku.php" },
  { name: "Manage SKU Master", url: "manage_sku_master.php" },
  { name: "Update SKU", url: "update_sku.php" },
  { name: "Delete SKU", url: "delete_sku.php" },
  { name: "View SKUs", url: "view_skus.php" },
  { name: "Manage Users", url: "manage_users.php" },
  { name: "Inventory", url: "view_inventory.php" },
  { name: "Inbound", url: "inbound.php" },
  { name: "Outbound", url: "outbound.php" },

	
  ];

  openBtn.onclick = () => {
    modal.style.display = 'flex';
    searchInput.focus();
  };

  cancelBtn.onclick = () => {
    modal.style.display = 'none';
    searchInput.value = '';
    suggestions.innerHTML = '';
  };

  searchInput.onkeyup = () => {
    const term = searchInput.value.toLowerCase();
    suggestions.innerHTML = '';
    if (term) {
      const matches = screens.filter(s => s.name.toLowerCase().includes(term));
      matches.forEach(screen => {
        const li = document.createElement('li');
        li.textContent = screen.name;
        li.onclick = () => window.location.href = screen.url;
        suggestions.appendChild(li);
      });
    }
  };
});
</script>
