<?php require 'auth.php'; require_login(); ?>
<!DOCTYPE html>
<html>
<head>
    <title>ECWMS RF Scanner</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f0f0; padding: 30px; }
        .container {
            max-width: 500px; margin: auto; background: white; padding: 25px;
            border-radius: 12px; box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        input, select, button {
            width: 100%; padding: 12px; font-size: 16px; margin-top: 10px;
        }
        .message { margin-top: 15px; font-size: 16px; }
        .success { color: green; }
        .fail { color: red; }
    </style>
</head>
<body>
<div class="container">
    <h2>📦 ECWMS Web RF Scanner</h2>
    <form id="rf-scan-form">
        <label>Scan Barcode:</label>
        <input type="text" id="barcode" name="barcode" autofocus required>

        <label>Select Mode:</label>
        <select id="mode" name="mode">
            <option value="inbound">Inbound</option>
            <option value="outbound">Outbound</option>
        </select>

        <button type="submit">Submit</button>
    </form>

    <div id="result" class="message"></div>
</div>

<!-- Sound Effects -->
<audio id="successSound"><source src="beep-success.mp3" type="audio/mpeg"></audio>
<audio id="failSound"><source src="beep-error.mp3" type="audio/mpeg"></audio>

<script>
document.getElementById("rf-scan-form").addEventListener("submit", function(e) {
    e.preventDefault();

    const barcode = document.getElementById("barcode").value.trim();
    const mode = document.getElementById("mode").value;
    const resultDiv = document.getElementById("result");

    if (!barcode) return;

    const targetUrl = mode === "inbound" ? "scanner_inbound.php" : "scanner_outbound.php";

    fetch(targetUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `barcode=${encodeURIComponent(barcode)}`
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.textContent = data.message;
        resultDiv.className = "message " + (data.status === "success" ? "success" : "fail");

        // Play sound
        const sound = document.getElementById(data.status === "success" ? "successSound" : "failSound");
        sound.play();

        // Clear input & refocus
        document.getElementById("barcode").value = '';
        document.getElementById("barcode").focus();
    })
    .catch(() => {
        resultDiv.textContent = "❌ Network or server error.";
        resultDiv.className = "message fail";
        document.getElementById("failSound").play();
    });
});
</script>
</body>
</html>
