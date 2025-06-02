<!-- persistent_header.php -->
<div id="persistent-tabs">
  <span>📂 Open Screens:</span>
  <div id="tab-list"></div>
</div>

<style>
  #persistent-tabs {
    position: sticky;
    top: 0;
    background: #e8f0fe;
    padding: 8px 12px;
    font-family: Arial, sans-serif;
    border-bottom: 1px solid #ccc;
    z-index: 999;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
  }
  #tab-list {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .tab {
    background-color: #0d6efd;
    color: white;
    padding: 0.3px 6px;
    border-radius: 1px;
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 14px;
  }
  .tab a {
    color: white;
    text-decoration: none;
    font-weight: bold;
    cursor: pointer;
  }
  .tab button {
    background: none;
    border: none;
    color: White;
    font-weight: bold;
    cursor: pointer;
  }
  .tab button:hover {
    color: #ffeb3b;
  }
</style>

<script>
function saveOpenedScreen(label, url) {
  let tabs = JSON.parse(localStorage.getItem("tabs") || "[]");
  if (!tabs.some(t => t.label === label)) {
    tabs.push({ label, url });
    localStorage.setItem("tabs", JSON.stringify(tabs));
  }
  renderTabs();
}

function renderTabs() {
  let tabs = JSON.parse(localStorage.getItem("tabs") || "[]");
  const container = document.getElementById("tab-list");
  container.innerHTML = "";
  tabs.forEach((tab, index) => {
    const el = document.createElement("div");
    el.className = "tab";

    const a = document.createElement("a");
    a.textContent = tab.label;
    a.href = tab.url;
    el.appendChild(a);

    const btn = document.createElement("button");
    btn.textContent = "✕";
    btn.onclick = () => {
      tabs.splice(index, 1);
      localStorage.setItem("tabs", JSON.stringify(tabs));
      renderTabs();
    };

    el.appendChild(btn);
    container.appendChild(el);
  });
}

document.addEventListener("DOMContentLoaded", renderTabs);
</script>