
<?php
require 'auth.php';
require_login();
include 'db_config.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create ASN | ECWMS</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f6f8;
            padding: 30px;
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        form {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 1300px;
            margin: auto;
        }
        label {
            display: block;
            margin: 10px 0 5px;
        }
        input, select, button {
            padding: 8px;
            margin: 5px 0 15px;
            width: 100%;
            max-width: 300px;
            box-sizing: border-box;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            background-color: #f0f0f0;
        }
        button[type="submit"], .add-row-btn, .delete-row-btn {
            background-color: #007BFF;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: auto;
        }
        button[type="submit"]:hover, .add-row-btn:hover, .delete-row-btn:hover {
            background-color: #0056b3;
        }
        a {
            display: inline-block;
            margin-top: 20px;
            color: #007BFF;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
    <script>
function fetchSKU(input) {
    const skuValue = input.value.trim();
    if (!skuValue) return;

    fetch('fetch_sku_info.php?sku=' + encodeURIComponent(skuValue))
    .then(res => res.json())
    .then(data => {
        const row = input.closest('tr');
        row.querySelector('[name="client_id[]"]').value = data.client_id || '';
        row.querySelector('[name="site_id[]"]').value = data.site_id || '';
        row.querySelector('[name="description[]"]').value = data.description || '';
        row.querySelector('[name="pack_config[]"]').value = data.pack_config || '';
        row.querySelector('[name="receipt_id[]"]').value = document.querySelector('[name="asn_number"]').value;
    })
    .catch(error => {
        console.error("Fetch error:", error);
    });
}


function addRow() {
    const table = document.getElementById('asnTable');
    const rows = table.querySelectorAll('tr');
    const dataRows = Array.from(rows).slice(1); // exclude header
    const lastRow = dataRows[dataRows.length - 1];
    const newRow = lastRow.cloneNode(true);

    const nextLineId = dataRows.length + 1;

    newRow.querySelectorAll('input').forEach(input => {
        if (input.type === 'checkbox') {
            input.checked = false;
        } else if (input.name === "line_id[]") {
            input.value = nextLineId;
        } else if (input.name === "receipt_id[]") {
            input.value = document.querySelector('[name="asn_number"]').value;
        } else {
            input.value = '';
        }
    });

    table.appendChild(newRow);
}

function deleteSelectedRows() {
    const table = document.getElementById('asnTable');
    const rows = Array.from(table.rows).slice(1); // exclude header

    rows.forEach((row, i) => {
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (checkbox && checkbox.checked) {
            table.deleteRow(i + 1); // offset by 1 because header is row[0]
        }
    });

    // Renumber line_id
    const lineInputs = table.querySelectorAll('[name="line_id[]"]');
    lineInputs.forEach((input, i) => {
        input.value = i + 1;
    });
}
</script>

</head>
<body>

<h2>📦 Create ASN</h2>
<form method="POST" action="create_asn_save.php">
    <label>ASN Number:</label>
    <input type="text" name="asn_number" required>

    <label>Supplier Name:</label>
    <input type="text" name="supplier_name" required>

    <label>Arrival Date:</label>
    <input type="date" name="arrival_date" required>

    <table id="asnTable">
        <tr>
            <th>Select</th><th>SKU</th><th>Client ID</th><th>Site ID</th><th>Description</th><th>QTY</th><th>Batch ID</th><th>Condition</th>
            <th>Pack Config</th><th>Receipt ID</th><th>Line ID</th><th>Receipt Date</th><th>Receipt Time</th><th>Expiry Date</th>
        </tr>
        <tr>
            <td><input type="checkbox"></td>
            <td><input type="text" name="sku_id[]" onblur="fetchSKU(this)"></td>
            <td><input type="text" name="client_id[]" readonly></td>
            <td><input type="text" name="site_id[]" readonly></td>
            <td><input type="text" name="description[]" readonly></td>
            <td><input type="number" name="qty[]"></td>
            <td><input type="text" name="batch_id[]"></td>
            <td><input type="text" name="condition[]"></td>
            <td><input type="text" name="pack_config[]" readonly></td>
            <td><input type="text" name="receipt_id[]" readonly></td>
            <td><input type="text" name="line_id[]" value="1" readonly></td>
            <td><input type="date" name="receipt_dstamp[]"></td>
            <td><input type="time" name="receipt_time[]"></td>
            <td><input type="date" name="expiry_date[]"></td>
        </tr>
    </table>

    <button type="button" class="add-row-btn" onclick="addRow()">➕ Add Row</button>
    <button type="button" class="delete-row-btn" onclick="deleteSelectedRows()">🗑️ Delete Selected</button>
    <br><br>
    <button type="submit">✅ Create ASN</button>
</form>

<a href="dashboard.php">⬅️ Return to Dashboard</a>

</body>
</html>
