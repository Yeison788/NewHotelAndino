<?php
session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

ensureEmpStructure($conn);
ensureRoomRates($conn);
ensureStaffStructure($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
admin_require_permission('personal');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id > 0) {
    if ($stmt = $conn->prepare('DELETE FROM staff WHERE id = ? AND COALESCE(admin_email, "") = ""')) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
}

header('Location: staff.php');
exit;
