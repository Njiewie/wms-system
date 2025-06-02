<!-- footer_actions.php -->
<div class="footer-actions" style="
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100%;
  background: #f8f9fa;
  border-top: 1px solid #ccc;
  padding: 10px;
  text-align: center;
  box-shadow: 0 -2px 5px rgba(0,0,0,0.1);
">
  <form method="POST" id="footerForm">
    <input type="hidden" name="item_id" id="selected_id">
    <button type="button" onclick="handleAdd()">➕ Add</button>
    <button type="button" id="updateBtn" disabled onclick="handleEdit()">✏️ Update</button>
    <button type="submit" id="deleteBtn" formaction="" disabled onclick="return confirm('Delete this item?')">🗑️ Delete</button>
    <button type="submit" formaction="cleanup_inventory.php" onclick="return confirm('Delete all items with Total Qty = 0?')">🧹 Auto-Delete Zero Qty</button>
  </form>
</div>

<script>
  let selectedId = null;
  let previouslySelected = null;
  let currentModule = "";

  function setModule(name) {
    currentModule = name;
    document.getElementById('updateBtn').formAction = name + "_edit.php";
    document.getElementById('deleteBtn').formAction = name + "_delete.php";
  }

  function handleAdd() {
    if (currentModule) {
      window.location.href = currentModule + "_add.php";
    }
  }

  function setSelectedId(id, row) {
    selectedId = id;
    document.getElementById("selected_id").value = id;
    document.getElementById("updateBtn").disabled = false;
    document.getElementById("deleteBtn").disabled = false;

    if (previouslySelected) {
      previouslySelected.classList.remove("selected");
    }
    row.classList.add("selected");
    previouslySelected = row;
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".item-row").forEach(row => {
      row.addEventListener("click", () => {
        const id = row.getAttribute("data-id");
        setSelectedId(id, row);
      });
    });
  });

  function handleEdit() {
    if (selectedId && currentModule) {
      window.location.href = currentModule + "_edit.php?id=" + selectedId;
    }
  }
</script>
