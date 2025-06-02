<?php
require 'auth.php';
require_login();
include 'db_config.php';

$conn->query("DELETE FROM inventory WHERE total_qty = 0");

header("Location: view_inventory.php?msg=cleanupdone");
exit;
