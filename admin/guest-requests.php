<?php
session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
admin_require_permission('reservas');
admin_ensure_guest_portal($conn);

$employee = admin_current_employee();
$adminEmail = $employee['email'];
$adminRole = strtolower((string)($employee['role'] ?? ''));
$audience = (!empty($_SESSION['admin_is_super']) || $adminRole !== 'recepcionista') ? 'admin' : 'recepcion';

$allowedStatuses = ['pendiente','en_proceso','completado','cancelado'];
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$flashMessages = $_SESSION['guest_requests_flash'] ?? [];
unset($_SESSION['guest_requests_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $messages = [];

    if (isset($_POST['update_request'])) {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $note = trim($_POST['response_note'] ?? '');
        $chargeRaw = trim($_POST['charge_amount'] ?? '');
        $charge = null;
        if ($chargeRaw !== '') {
            $normalized = str_replace(['.', ','], ['', '.'], $chargeRaw);
            if (is_numeric($normalized)) {
                $charge = (float)$normalized;
            }
        }

        if ($requestId > 0 && in_array($status, $allowedStatuses, true)) {
            if (guest_portal_update_request($conn, $requestId, $status, $note, $adminEmail, $charge)) {
                $messages[] = ['type' => 'success', 'text' => 'Solicitud actualizada correctamente.'];
            } else {
                $messages[] = ['type' => 'error', 'text' => 'No se pudo actualizar la solicitud.'];
            }
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Datos inválidos para actualizar la solicitud.'];
        }
    } elseif (isset($_POST['add_minibar'])) {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        $concept = trim($_POST['concept'] ?? '');
        $amountRaw = trim($_POST['amount'] ?? '');
        $amountNormalized = str_replace(['.', ','], ['', '.'], $amountRaw);
        $amount = is_numeric($amountNormalized) ? (float)$amountNormalized : null;

        if ($reservationId > 0 && $concept !== '' && $amount !== null) {
            $userId = null;
            if ($stmt = $conn->prepare('SELECT user_id FROM roombook WHERE id = ? LIMIT 1')) {
                $stmt->bind_param('i', $reservationId);
                if ($stmt->execute()) {
                    $stmt->bind_result($uid);
                    if ($stmt->fetch()) {
                        $userId = $uid ? (int)$uid : null;
                    }
                }
                $stmt->close();
            }

            $requestId = guest_portal_create_request($conn, $reservationId, $userId, 'minibar', $concept, 'staff', $amount);
            if ($requestId) {
                $messages[] = ['type' => 'success', 'text' => 'Consumo de minibar registrado.'];
                $notifBody = sprintf('Se registró consumo de minibar (%s) por COP %s.', $concept, number_format($amount, 0, ',', '.'));
                guest_portal_record_notification($conn, 'admin', 'Consumo en minibar', $notifBody, 'admin/guest-requests.php', $reservationId, $requestId);
                guest_portal_record_notification($conn, 'recepcion', 'Consumo en minibar', $notifBody, 'admin/guest-requests.php', $reservationId, $requestId);
            } else {
                $messages[] = ['type' => 'error', 'text' => 'No fue posible registrar el consumo.'];
            }
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Completa los datos para registrar el consumo.'];
        }
    } elseif (isset($_POST['mark_notifications'])) {
        $ids = array_map('intval', $_POST['notification_ids'] ?? []);
        guest_portal_mark_notifications($conn, $ids, true);
        $messages[] = ['type' => 'success', 'text' => 'Notificaciones marcadas como leídas.'];
    }

    $_SESSION['guest_requests_flash'] = $messages;
    $redirect = 'guest-requests.php';
    if ($statusFilter !== '') {
        $redirect .= '?status=' . urlencode($statusFilter);
    }
    header('Location: ' . $redirect);
    exit;
}

$requests = guest_portal_requests_for_admin($conn, $statusFilter ?: null);
$notifications = guest_portal_notifications_for_audience($conn, $audience, 8, true);
$confirmedReservations = guest_portal_confirmed_reservations($conn);
$requestTypesCatalog = guest_portal_request_types();

function guest_requests_status_badge(string $status): string
{
    $label = guest_portal_format_status($status);
    $class = 'status-' . preg_replace('/[^a-z_\-]/i', '', strtolower($status));
    return '<span class="badge bg-light text-dark status-badge ' . htmlspecialchars($class) . '">' . htmlspecialchars($label) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes de huéspedes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" integrity="sha512-xh6O/CkQoPOWDdYTDqeRdPCVd1SpvCA9XXcUnZS2FmJNp1coAFzvtCN9BmamE+4aHK8yyUHUSCcJHgXloTyT2A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body{ background:#f5f4f0; }
        .page-header{ display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:24px; }
        .card-shadow{ border:none; border-radius:16px; box-shadow:0 16px 32px rgba(0,0,0,.08); }
        .requests-table th{ background:#f8f1d8; color:#5f4c1f; text-transform:uppercase; font-size:12px; letter-spacing:.04em; }
        .requests-table td{ vertical-align:middle; }
        .status-badge{ border-radius:999px; padding:4px 10px; font-size:12px; text-transform:capitalize; }
        .status-pendiente{ background:rgba(241,196,15,.18); color:#b9770e; }
        .status-en_proceso{ background:rgba(52,152,219,.18); color:#1f618d; }
        .status-completado{ background:rgba(39,174,96,.18); color:#1d8348; }
        .status-cancelado{ background:rgba(231,76,60,.18); color:#943126; }
        .filter-tabs .nav-link{ border-radius:999px; padding:6px 18px; font-weight:500; color:#5b4a2b; }
        .filter-tabs .nav-link.active{ background:#d4af37; color:#fff; }
        .minibar-form .form-control,.minibar-form .form-select{ border-radius:10px; }
    </style>
</head>
<body class="p-4">
    <div class="container-fluid">
        <div class="page-header">
            <div>
                <h1 class="h3 mb-1">Solicitudes de huéspedes</h1>
                <p class="text-muted mb-0">Gestiona peticiones en habitación, consumos y asistencia en tiempo real.</p>
            </div>
            <form class="d-flex align-items-center gap-2" method="get" action="">
                <select class="form-select" name="status" onchange="this.form.submit()">
                    <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>Todas las solicitudes</option>
                    <option value="pendiente" <?php echo $statusFilter === 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
                    <option value="en_proceso" <?php echo $statusFilter === 'en_proceso' ? 'selected' : ''; ?>>En proceso</option>
                    <option value="completado" <?php echo $statusFilter === 'completado' ? 'selected' : ''; ?>>Completadas</option>
                    <option value="cancelado" <?php echo $statusFilter === 'cancelado' ? 'selected' : ''; ?>>Canceladas</option>
                </select>
                <?php if ($statusFilter !== ''): ?>
                    <a class="btn btn-outline-secondary" href="guest-requests.php">Limpiar</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!empty($flashMessages)): ?>
            <?php foreach ($flashMessages as $msg): ?>
                <div class="alert alert-<?php echo $msg['type'] === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($msg['text']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card card-shadow">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0">Solicitudes activas</h2>
                            <span class="badge bg-light text-dark">Total: <?php echo count($requests); ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table requests-table align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Huésped</th>
                                        <th>Servicio</th>
                                        <th>Estado</th>
                                        <th>Actualizado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requests)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No hay solicitudes para mostrar.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($requests as $request): ?>
                                            <?php
                                                $guestName = $request['reservation_name'] ?? ($request['Username'] ?? 'Huésped');
                                                $guestEmail = $request['reservation_email'] ?? ($request['user_email'] ?? '');
                                                $typeLabel = $requestTypesCatalog[$request['request_type']] ?? ucfirst($request['request_type']);
                                                $updatedAt = $request['updated_at'] ?? $request['created_at'] ?? '';
                                                $updatedLabel = $updatedAt ? date('d/m/Y H:i', strtotime($updatedAt)) : '—';
                                                $roomNumber = $request['room_number'] ?? '';
                                                $reservationDates = '';
                                                if (!empty($request['cin']) && !empty($request['cout'])) {
                                                    $reservationDates = date('d/m', strtotime($request['cin'])) . ' - ' . date('d/m', strtotime($request['cout']));
                                                }
                                            ?>
                                            <tr>
                                                <td>#<?php echo (int)$request['id']; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($guestName); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($guestEmail); ?></small><br>
                                                    <?php if ($roomNumber): ?><span class="badge bg-secondary">Hab. <?php echo htmlspecialchars($roomNumber); ?></span><?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($typeLabel); ?></div>
                                                    <div class="text-muted small">Reserva #<?php echo (int)($request['roombook_id'] ?? 0); ?><?php if ($reservationDates): ?> · <?php echo htmlspecialchars($reservationDates); ?><?php endif; ?></div>
                                                    <?php if (!empty($request['details'])): ?>
                                                        <div class="text-muted small mt-1"><?php echo nl2br(htmlspecialchars($request['details'])); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($request['charge_amount'] !== null): ?>
                                                        <div class="small text-success mt-1">Consumo: COP <?php echo number_format((float)$request['charge_amount'], 0, ',', '.'); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo guest_requests_status_badge($request['status'] ?? 'pendiente'); ?></td>
                                                <td><?php echo htmlspecialchars($updatedLabel); ?></td>
                                                <td>
                                                    <form method="post" class="d-grid gap-2">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                                        <select name="status" class="form-select form-select-sm">
                                                            <?php foreach ($allowedStatuses as $statusKey): ?>
                                                                <option value="<?php echo $statusKey; ?>" <?php echo ($request['status'] === $statusKey) ? 'selected' : ''; ?>><?php echo guest_portal_format_status($statusKey); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <textarea name="response_note" class="form-control form-control-sm" rows="2" placeholder="Nota interna (opcional)"><?php echo htmlspecialchars($request['response_note'] ?? ''); ?></textarea>
                                                        <input type="text" name="charge_amount" class="form-control form-control-sm" placeholder="COP" value="<?php echo $request['charge_amount'] !== null ? number_format((float)$request['charge_amount'], 2, '.', '') : ''; ?>">
                                                        <button class="btn btn-sm btn-primary" type="submit" name="update_request" value="1">Actualizar</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card card-shadow mb-4">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Registrar consumo de minibar</h2>
                        <form method="post" class="minibar-form d-grid gap-3">
                            <div>
                                <label for="reservation_id" class="form-label">Reserva</label>
                                <select class="form-select" name="reservation_id" id="reservation_id" required>
                                    <option value="" selected disabled>Selecciona una reserva</option>
                                    <?php foreach ($confirmedReservations as $reservation): ?>
                                        <option value="<?php echo (int)$reservation['id']; ?>">
                                            #<?php echo (int)$reservation['id']; ?> · <?php echo htmlspecialchars($reservation['Name'] ?? $reservation['Email'] ?? 'Huésped'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="concept" class="form-label">Detalle</label>
                                <input type="text" class="form-control" id="concept" name="concept" placeholder="Ej. Bebida, snack" required>
                            </div>
                            <div>
                                <label for="amount" class="form-label">Monto (COP)</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="amount" name="amount" required>
                            </div>
                            <button class="btn btn-success" type="submit" name="add_minibar" value="1"><i class="fa-solid fa-plus"></i> Registrar consumo</button>
                        </form>
                    </div>
                </div>

                <div class="card card-shadow">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h2 class="h6 mb-0">Notificaciones recientes</h2>
                            <?php if (!empty($notifications)): ?>
                            <form method="post" class="d-inline">
                                <?php foreach ($notifications as $notif): ?>
                                    <input type="hidden" name="notification_ids[]" value="<?php echo (int)$notif['id']; ?>">
                                <?php endforeach; ?>
                                <button class="btn btn-link btn-sm" type="submit" name="mark_notifications" value="1">Marcar como leídas</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <ul class="list-unstyled mb-0 d-grid gap-3">
                            <?php if (empty($notifications)): ?>
                                <li class="text-muted small">Sin notificaciones pendientes.</li>
                            <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <li>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="text-muted small mb-1"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="text-muted small"><?php echo date('d/m/Y H:i', strtotime($notif['created_at'])); ?></div>
                                        <?php if (!empty($notif['link'])): ?>
                                            <a class="small" href="<?php echo htmlspecialchars($notif['link']); ?>" target="_top">Ver detalle</a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
</body>
</html>
