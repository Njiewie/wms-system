<!-- sticky_header2.php -->
<div id="opened-screens">
  <span>📋 Opened Screens:</span>
  <div id="screen-tags"></div>
</div>

<style>
  #opened-screens {
    position: sticky;
    top: 48px;
    background: #e3f2fd;
    padding: 8px 16px;
    font-family: Arial, sans-serif;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid #b3d7f2;
    z-index: 999;
  }

  #screen-tags {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .screen-tag {
    background-color: #0c4a6e;
    color: white;
    padding: 4px 10px;
    border-radius: 16px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .screen-tag a {
    color: white;
    text-decoration: none;
    font-weight: bold;
    cursor: pointer;
  }

  .screen-tag button {
    background: none;
    border: none;
    color: white;
    font-weight: bold;
    cursor: pointer;
  }

  .screen-tag button:hover {
    color: red;
  }
</style>

<script>
  function logOpenedScreen(label, url) {
    let screens = [];
    try {
      screens = JSON.parse(sessionStorage.getItem("openedScreens") || "[]");
    } catch (e) {
      console.error("Failed to parse openedScreens:", e);
    }

    let count = screens.filter(s => s.label.startsWith(label)).length;
    let screenName = count ? \`\${label} \${count + 1}\` : label;

    if (!screens.some(s => s.label === screenName)) {
      screens.push({ label: screenName, url: url });
      sessionStorage.setItem("openedScreens", JSON.stringify(screens));
    }

    loadOpenedScreens();
  }

  function loadOpenedScreens() {
    let screens = [];
    try {
      screens = JSON.parse(sessionStorage.getItem("openedScreens") || "[]");
    } catch (e) {
      console.error("Invalid JSON in openedScreens:", e);
    }

    const container = document.getElementById("screen-tags");
    container.innerHTML = "";

    screens.forEach((screen, index) => {
      const tag = document.createElement("div");
      tag.className = "screen-tag";

      const link = document.createElement("a");
      link.textContent = screen.label;
      link.onclick = () => {
        if (window.location.pathname.endsWith(screen.url)) {
          window.location.reload();
        } else {
          window.location.href = screen.url;
        }
      };

      const btn = document.createElement("button");
      btn.textContent = "✕";
      btn.onclick = () => {
        screens.splice(index, 1);
        sessionStorage.setItem("openedScreens", JSON.stringify(screens));
        loadOpenedScreens();
      };

      tag.appendChild(link);
      tag.appendChild(btn);
      container.appendChild(tag);
    });
  }

  document.addEventListener("DOMContentLoaded", loadOpenedScreens);
</script>