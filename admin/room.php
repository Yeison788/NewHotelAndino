<?php
session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

if (!isset($_SESSION['adminmail'])) {
    header('Location: ../index.php');
    exit;
}

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_refresh_session($conn, $_SESSION['adminmail']);
admin_require_permission('habitaciones');

$renderGuestSummary = function (?array $stay, float $basePrice, float $displayPrice): string {
    ob_start();
    if ($stay) {
        ?>
        <p class="mb-1">ID: <strong><?php echo htmlspecialchars($stay['guest_id']); ?></strong></p>
        <p class="mb-1">Nombre: <strong><?php echo htmlspecialchars($stay['guest_name']); ?></strong></p>
        <p class="mb-1 text-muted">Nacionalidad: <?php echo htmlspecialchars($stay['nationality']); ?></p>
        <p class="mb-1 text-muted">Ingreso: <?php echo date('d/m/Y', strtotime($stay['check_in_date'])); ?> · <?php echo htmlspecialchars(substr($stay['check_in_time'], 0, 5)); ?></p>
        <p class="mb-0 text-muted">Salida: <?php echo date('d/m/Y', strtotime($stay['check_out_date'])); ?></p>
        <p class="mb-0 text-muted">Precio: COP <?php echo number_format($displayPrice, 0, ',', '.'); ?></p>
        <?php
    } else {
        ?>
        <p class="text-muted mb-0">Sin datos del huésped. Precio base: COP <?php echo number_format($basePrice, 0, ',', '.'); ?></p>
        <?php
    }
    return trim(ob_get_clean());
};

$employee = admin_current_employee();
$adminEmail = $employee['email'];

// Aseguramos nuevas opciones de estado y tabla de estancias
mysqli_query($conn, "ALTER TABLE room MODIFY status ENUM('Disponible','Reservada','Limpieza','Ocupada') NOT NULL DEFAULT 'Disponible'");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS room_stays (
    room_id INT NOT NULL PRIMARY KEY,
    guest_id VARCHAR(40) NOT NULL,
    guest_name VARCHAR(120) NOT NULL,
    nationality VARCHAR(80) NOT NULL,
    check_in_date DATE NOT NULL,
    check_in_time TIME NOT NULL,
    check_out_date DATE NOT NULL,
    receptionist_email VARCHAR(190) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_room_stays_room FOREIGN KEY (room_id) REFERENCES room(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


// ---- Cambiar estado (AJAX) ----
if (isset($_POST['change_status'])) {
    $roomId = isset($_POST['room_id']) ? intval($_POST['room_id']) : 0;
    $newStatus = $_POST['status'] ?? '';
    $allowed = ['Disponible','Reservada','Limpieza','Ocupada'];

    header('Content-Type: application/json; charset=utf-8');

    if ($roomId <= 0 || !in_array($newStatus, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'Parámetros inválidos']);
        exit;
    }

    if ($stmt = $conn->prepare('UPDATE room SET status=? WHERE id=?')) {
        $stmt->bind_param('si', $newStatus, $roomId);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode([
            'ok' => (bool)$ok,
            'room_id' => $roomId,
            'status' => $newStatus
        ]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>'Error en la consulta']);
        exit;
    }
}

// ---- Guardar datos de huésped ----
if (isset($_POST['save_stay'])) {
    $roomId = intval($_POST['room_id'] ?? 0);
    $guestId = trim($_POST['guest_id'] ?? '');
    $guestName = trim($_POST['guest_name'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $checkInDate = $_POST['check_in_date'] ?? '';
    $checkInTime = $_POST['check_in_time'] ?? '';
    $checkOutDate = $_POST['check_out_date'] ?? '';
    $price = floatval($_POST['price'] ?? 0);

    if ($roomId > 0 && $guestId !== '' && $guestName !== '' && $nationality !== '' && $checkInDate !== '' && $checkInTime !== '' && $checkOutDate !== '' && $price >= 0) {
        if ($stmt = $conn->prepare('INSERT INTO room_stays (room_id, guest_id, guest_name, nationality, check_in_date, check_in_time, check_out_date, receptionist_email, price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE guest_id = VALUES(guest_id), guest_name = VALUES(guest_name), nationality = VALUES(nationality),
            check_in_date = VALUES(check_in_date), check_in_time = VALUES(check_in_time), check_out_date = VALUES(check_out_date),
            receptionist_email = VALUES(receptionist_email), price = VALUES(price)')) {
            $stmt->bind_param('isssssssd', $roomId, $guestId, $guestName, $nationality, $checkInDate, $checkInTime, $checkOutDate, $adminEmail, $price);
            $stmt->execute();
            $stmt->close();
        }
    }

    $stayData = null;
    if ($roomId > 0) {
        if ($result = mysqli_query($conn, 'SELECT * FROM room_stays WHERE room_id = ' . $roomId . ' LIMIT 1')) {
            $stayData = mysqli_fetch_assoc($result) ?: null;
            mysqli_free_result($result);
        }
    }
    $roomInfo = null;
    if ($roomId > 0) {
        if ($result = mysqli_query($conn, 'SELECT room_number, type, bedding FROM room WHERE id = ' . $roomId . ' LIMIT 1')) {
            $roomInfo = mysqli_fetch_assoc($result) ?: null;
            mysqli_free_result($result);
        }
    }
    $roomTypeForPrice = $roomInfo['type'] ?? '';
    $basePrice = $roomTypeForPrice !== '' ? admin_room_base_price($conn, $roomTypeForPrice) : 0.0;
    $displayPrice = ($stayData && (float)$stayData['price'] > 0) ? (float)$stayData['price'] : $basePrice;

    if (admin_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'room_id' => $roomId,
            'guest' => $stayData ? [
                'id' => $stayData['guest_id'],
                'name' => $stayData['guest_name'],
                'nationality' => $stayData['nationality'],
                'check_in_date' => $stayData['check_in_date'],
                'check_in_time' => substr($stayData['check_in_time'], 0, 5),
                'check_out_date' => $stayData['check_out_date'],
                'price' => number_format($displayPrice, 0, ',', '.'),
            ] : null,
            'room' => $roomInfo ? [
                'type' => $roomInfo['type'],
                'bedding' => $roomInfo['bedding'],
            ] : ['type' => $roomTypeForPrice, 'bedding' => ''],
            'summary_html' => $renderGuestSummary($stayData, $basePrice, $displayPrice),
        ]);
        exit;
    }

    $floorRedirect = isset($_GET['floor']) ? intval($_GET['floor']) : 1;
    header('Location: room.php?floor=' . $floorRedirect);
    exit;
}
// ---- Editar tipo/cama ----
if (isset($_POST['edit_room'])) {
    $roomId = intval($_POST['room_id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $bedding = $_POST['bedding'] ?? '';
 if ($roomId > 0) {
        $stmt = $conn->prepare('UPDATE room SET type=?, bedding=? WHERE id=?');
        if ($stmt) {
            $stmt->bind_param('ssi', $type, $bedding, $roomId);
            $stmt->execute();
            $stmt->close();
        }
    }
    if (admin_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'room_id' => $roomId,
            'type' => $type,
            'bedding' => $bedding,
        ]);
        exit;
    }

    header('Location: room.php?floor=' . ($_GET['floor'] ?? ''));
    exit;
}

// ---- Filtro de piso ----
$currentFloor = isset($_GET['floor']) ? intval($_GET['floor']) : 1;


// ---- Datos de estancias ----
$stays = [];
if ($result = mysqli_query($conn, 'SELECT * FROM room_stays')) {
    while ($stay = mysqli_fetch_assoc($result)) {
        $stays[$stay['room_id']] = $stay;
    }
    mysqli_free_result($result);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Hotel Andino - Habitaciones</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/room.css">
</head>
<body class="p-4">
  <div class="room-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Gestión de Habitaciones</h2>
        <span class="badge bg-dark">
          <?php echo htmlspecialchars($employee['name'] ?: $adminEmail); ?>
          <?php if (!empty($employee['role'])): ?>
            · <?php echo htmlspecialchars($employee['role']); ?>
          <?php endif; ?>
        </span>
      </div>

      <!-- Selector de piso -->
      <form method="get" class="mb-4">
        <label for="floor" class="form-label">Seleccionar piso:</label>
        <select name="floor" id="floor" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
          <?php for ($f = 1; $f <= 6; $f++): ?>
            <option value="<?php echo $f; ?>" <?php echo $currentFloor === $f ? 'selected' : ''; ?>>Piso <?php echo $f; ?></option>
          <?php endfor; ?>
        </select>
      </form>

      <div class="row g-3">
        <?php
        $sql = "SELECT * FROM room WHERE floor='$currentFloor' ORDER BY room_number";
        $re = mysqli_query($conn, $sql);

        while ($row = mysqli_fetch_assoc($re)) {
          $stay = $stays[$row['id']] ?? null;
          $statusClass = match ($row['status']) {
            'Disponible' => 'bg-success text-white',
            'Reservada' => 'bg-warning text-dark',
            'Limpieza' => 'bg-info text-dark',
            'Ocupada' => 'bg-danger text-white',
            default => 'bg-secondary text-white'
          };
          $basePrice = admin_room_base_price($conn, $row['type']);
          $stayPrice = ($stay && (float)$stay['price'] > 0) ? (float)$stay['price'] : $basePrice;
          $priceInputValue = rtrim(rtrim(number_format($stayPrice, 2, '.', ''), '0'), '.');
          ?>
          <div class="col-md-4">
            <div class="card shadow-sm h-100">
              <div class="card-body" id="room-<?php echo $row['id']; ?>">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <div class="icon-wrapper">
                    <i class="fa-solid fa-bed fa-2x"></i>
                    <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                  </div>
                  <div class="text-end">
                    <h5 class="card-title mb-1">Hab. <?php echo htmlspecialchars($row['room_number']); ?></h5>
                    <p class="mb-0 text-muted room-type-label" data-room-type="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['type']); ?> · <?php echo htmlspecialchars($row['bedding']); ?></p>
                  </div>
                </div>

          <div class="mb-3 guest-summary" data-room-summary="<?php echo $row['id']; ?>">
                  <h6 class="fw-semibold mb-1">Huésped</h6>
                  <div class="guest-summary-content"><?php echo $renderGuestSummary($stay, $basePrice, $stayPrice); ?></div>
                </div>

                <form method="POST" class="d-flex flex-wrap justify-content-center gap-1 mb-3 js-status-form" data-room="<?php echo $row['id']; ?>">
                  <input type="hidden" name="room_id" value="<?php echo $row['id']; ?>">
                  <input type="hidden" name="change_status" value="1">
                  <button type="submit" name="status" value="Disponible" class="btn btn-sm btn-outline-success">Disponible</button>
                  <button type="submit" name="status" value="Reservada" class="btn btn-sm btn-outline-warning">Reservada</button>
                  <button type="submit" name="status" value="Limpieza" class="btn btn-sm btn-outline-info">Limpieza</button>
                  <button type="submit" name="status" value="Ocupada" class="btn btn-sm btn-outline-danger">Ocupada</button>
                </form>

                <form method="POST" class="mb-3 js-room-config-form" data-room="<?php echo $row['id']; ?>">
                  <input type="hidden" name="room_id" value="<?php echo $row['id']; ?>">
                  <input type="hidden" name="edit_room" value="1">
                  <div class="row g-2">
                    <div class="col-6">
                      <select name="type" class="form-select form-select-sm">
                        <option value="Habitación Doble" <?php echo $row['type'] === 'Habitación Doble' ? 'selected' : ''; ?>>Habitación Doble</option>
                        <option value="Habitación Suite" <?php echo $row['type'] === 'Habitación Suite' ? 'selected' : ''; ?>>Habitación Suite</option>
                        <option value="Habitación Múltiple" <?php echo $row['type'] === 'Habitación Múltiple' ? 'selected' : ''; ?>>Habitación Múltiple</option>
                        <option value="Habitación Sencilla" <?php echo $row['type'] === 'Habitación Sencilla' ? 'selected' : ''; ?>>Habitación Sencilla</option>
                      </select>
                    </div>
                    <div class="col-6">
                      <select name="bedding" class="form-select form-select-sm">
                        <option value="1 cliente" <?php echo $row['bedding'] === '1 cliente' ? 'selected' : ''; ?>>1 cliente</option>
                        <option value="2 clientes" <?php echo $row['bedding'] === '2 clientes' ? 'selected' : ''; ?>>2 clientes</option>
                        <option value="3 clientes" <?php echo $row['bedding'] === '3 clientes' ? 'selected' : ''; ?>>3 clientes</option>
                        <option value="4 clientes" <?php echo $row['bedding'] === '4 clientes' ? 'selected' : ''; ?>>4 clientes</option>
                      </select>
                    </div>
                  </div>
                  <button type="submit" class="btn btn-primary btn-sm w-100 mt-2">Guardar cambios</button>
                </form>
                <button class="btn btn-outline-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#stayModal-<?php echo $row['id']; ?>">
                  Gestionar huésped
                </button>
              </div>
            </div>
            </div>

          <!-- Modal -->
          <div class="modal fade" id="stayModal-<?php echo $row['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
              <div class="modal-content">
                <form method="POST" class="js-stay-form" data-room="<?php echo $row['id']; ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Huésped Hab. <?php echo htmlspecialchars($row['room_number']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <input type="hidden" name="room_id" value="<?php echo $row['id']; ?>">
                    <input type="hidden" name="save_stay" value="1">
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label" for="guest_id-<?php echo $row['id']; ?>">Identificación</label>
                        <input type="text" class="form-control" id="guest_id-<?php echo $row['id']; ?>" name="guest_id" value="<?php echo $stay ? htmlspecialchars($stay['guest_id']) : ''; ?>" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="guest_name-<?php echo $row['id']; ?>">Nombre</label>
                        <input type="text" class="form-control" id="guest_name-<?php echo $row['id']; ?>" name="guest_name" value="<?php echo $stay ? htmlspecialchars($stay['guest_name']) : ''; ?>" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="nationality-<?php echo $row['id']; ?>">Nacionalidad</label>
                        <input type="text" class="form-control" id="nationality-<?php echo $row['id']; ?>" name="nationality" value="<?php echo $stay ? htmlspecialchars($stay['nationality']) : ''; ?>" required>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label" for="check_in_date-<?php echo $row['id']; ?>">Fecha ingreso</label>
                        <input type="date" class="form-control" id="check_in_date-<?php echo $row['id']; ?>" name="check_in_date" value="<?php echo $stay ? htmlspecialchars($stay['check_in_date']) : ''; ?>" required>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label" for="check_in_time-<?php echo $row['id']; ?>">Hora entrada</label>
                        <input type="time" class="form-control" id="check_in_time-<?php echo $row['id']; ?>" name="check_in_time" value="<?php echo $stay ? htmlspecialchars(substr($stay['check_in_time'], 0, 5)) : ''; ?>" required>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label" for="check_out_date-<?php echo $row['id']; ?>">Fecha salida</label>
                        <input type="date" class="form-control" id="check_out_date-<?php echo $row['id']; ?>" name="check_out_date" value="<?php echo $stay ? htmlspecialchars($stay['check_out_date']) : ''; ?>" required>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label">Recepcionista</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(($employee['name'] ?: $adminEmail) . ' · ' . $adminEmail); ?>" readonly>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label" for="price-<?php echo $row['id']; ?>">Precio (COP)</label>
                        <input type="number" class="form-control" id="price-<?php echo $row['id']; ?>" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($priceInputValue); ?>" required>
                      </div>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar datos</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php }
        ?>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="javascript/room.js" defer></script>
</body>
</html>
