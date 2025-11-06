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

if (!admin_has_records_access()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso restringido</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head><body class="bg-light"><div class="container py-5"><div class="alert alert-danger shadow-sm"><h1 class="h4">Acceso restringido</h1><p class="mb-0">No cuentas con permisos suficientes para gestionar los registros.</p></div></div></body></html>';
    exit;
}

$adminEmail = $_SESSION['adminmail'];
$employee = admin_current_employee();

// Garantizar que existan las estructuras requeridas
mysqli_query($conn, "ALTER TABLE room MODIFY status ENUM('Disponible','Reservada','Limpieza','Ocupada') NOT NULL DEFAULT 'Disponible'");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS room_types (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS products (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sales (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NULL,
    details VARCHAR(190) NULL,
    quantity INT NOT NULL DEFAULT 1,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$viewDefinitions = [
    'summary' => [
        'label' => 'Resumen rápido',
        'description' => 'Consulta los últimos movimientos registrados en habitaciones, tipos, usuarios y ventas.',
        'permission' => 'registros.summary',
    ],
    'rooms' => [
        'label' => 'Registrar habitación',
        'description' => 'Crea nuevas habitaciones indicando piso, estado y tipo disponible.',
        'permission' => 'registros.rooms',
    ],
    'room-types' => [
        'label' => 'Registrar tipo de habitación',
        'description' => 'Añade los tipos de habitación que ofrece el hotel.',
        'permission' => 'registros.room-types',
    ],
    'admin-staff' => [
        'label' => 'Registrar administrativos',
        'description' => 'Gestiona cuentas para recepcionistas, porteros y personal de limpieza.',
        'permission' => 'registros.admins',
    ],
    'products' => [
        'label' => 'Registrar producto',
        'description' => 'Controla los productos y servicios disponibles para ventas internas.',
        'permission' => 'registros.products',
    ],
    'sales' => [
        'label' => 'Registrar venta',
        'description' => 'Registra ventas puntuales asociadas a productos y servicios.',
        'permission' => 'registros.sales',
    ],
    'pricing' => [
        'label' => 'Tarifas de habitaciones',
        'description' => 'Actualiza los precios base por tipo de habitación.',
        'permission' => 'registros.pricing',
    ],
];

$views = [];
foreach ($viewDefinitions as $key => $def) {
    if (empty($def['permission']) || admin_user_can($def['permission'])) {
        $views[$key] = $def;
    }
}

$defaultView = admin_first_records_view();
if (!isset($views[$defaultView])) {
    $defaultView = array_key_first($views);
}

$view = $_GET['view'] ?? $defaultView;
if (!isset($views[$view])) {
    $view = $defaultView;
}

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedView = $_POST['view'] ?? $view;
    if (array_key_exists($postedView, $views)) {
        $view = $postedView;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_room') {
        if (!admin_user_can('registros.rooms')) {
            $messages[] = ['type' => 'danger', 'text' => 'No tienes permisos para registrar habitaciones.'];
        } else {
            $floor = intval($_POST['floor'] ?? 1);
            $number = trim($_POST['room_number'] ?? '');
            $type = trim($_POST['room_type'] ?? '');
            $bedding = trim($_POST['bedding'] ?? '');
            $status = trim($_POST['status'] ?? 'Disponible');

            if ($number === '' || $type === '' || $bedding === '') {
                $messages[] = ['type' => 'danger', 'text' => 'Completa todos los campos para registrar una habitación.'];
            } else {
                $stmt = $conn->prepare('INSERT INTO room (floor, room_number, type, bedding, status) VALUES (?, ?, ?, ?, ?)');
                if ($stmt) {
                    $stmt->bind_param('issss', $floor, $number, $type, $bedding, $status);
                    if ($stmt->execute()) {
                        $messages[] = ['type' => 'success', 'text' => 'Habitación registrada correctamente.'];
                    } else {
                        $messages[] = ['type' => 'danger', 'text' => 'No se pudo registrar la habitación: ' . htmlspecialchars($stmt->error)];
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'create_room_type') {
        if (!admin_user_can('registros.room-types')) {
            $messages[] = ['type' => 'danger', 'text' => 'No tienes permisos para registrar tipos de habitación.'];
        } else {
            $name = trim($_POST['type_name'] ?? '');
            $description = trim($_POST['type_description'] ?? '');

            if ($name === '') {
                $messages[] = ['type' => 'danger', 'text' => 'El nombre del tipo de habitación es obligatorio.'];
            } else {
                $stmt = $conn->prepare('INSERT INTO room_types (name, description) VALUES (?, ?)');
                if ($stmt) {
                    $stmt->bind_param('ss', $name, $description);
                    if ($stmt->execute()) {
                        $messages[] = ['type' => 'success', 'text' => 'Tipo de habitación agregado.'];
                    } else {
                        $messages[] = ['type' => 'danger', 'text' => 'No se pudo agregar el tipo de habitación: ' . htmlspecialchars($stmt->error)];
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'create_admin_staff') {
        if (!admin_user_can('registros.admins')) {
            $messages[] = ['type' => 'danger', 'text' => 'No tienes permisos para registrar administrativos.'];
        } else {
            $fullName = trim($_POST['admin_full_name'] ?? '');
            $email = trim($_POST['admin_email'] ?? '');
            $password = $_POST['admin_password'] ?? '';
            $role = trim($_POST['admin_role'] ?? '');
            $selectedPermissions = isset($_POST['admin_permissions']) && is_array($_POST['admin_permissions']) ? array_map('strval', $_POST['admin_permissions']) : [];

            if ($fullName === '' || $email === '' || $password === '') {
                $messages[] = ['type' => 'danger', 'text' => 'Completa el nombre, correo y contraseña para el administrativo.'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $messages[] = ['type' => 'danger', 'text' => 'El correo electrónico no es válido.'];
            } else {
                $permissionsList = !empty($selectedPermissions) ? array_values(array_unique($selectedPermissions)) : admin_default_role_permissions($role);
                if (empty($permissionsList)) {
                    $permissionsList = ['dashboard'];
                }
                $permissionsJson = json_encode(array_values($permissionsList), JSON_UNESCAPED_UNICODE);
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                $stmtCheck = $conn->prepare('SELECT 1 FROM emp_login WHERE Emp_Email = ? LIMIT 1');
                if ($stmtCheck) {
                    $stmtCheck->bind_param('s', $email);
                    $stmtCheck->execute();
                    $stmtCheck->store_result();
                    if ($stmtCheck->num_rows > 0) {
                        $messages[] = ['type' => 'danger', 'text' => 'Ya existe un administrativo con ese correo.'];
                        $stmtCheck->close();
                    } else {
                        $stmtCheck->close();
                        $stmt = $conn->prepare('INSERT INTO emp_login (Emp_Email, Emp_Password, FullName, Role, Permissions, IsSuperAdmin) VALUES (?, ?, ?, ?, ?, 0)');
                        if ($stmt) {
                            $stmt->bind_param('sssss', $email, $hashedPassword, $fullName, $role, $permissionsJson);
                            if ($stmt->execute()) {
                                $messages[] = ['type' => 'success', 'text' => 'Administrativo registrado correctamente.'];
                            } else {
                                $messages[] = ['type' => 'danger', 'text' => 'No se pudo registrar el administrativo: ' . htmlspecialchars($stmt->error)];
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }
    }

    if ($action === 'update_admin_staff') {
        if (!admin_user_can('registros.admins')) {
            $messages[] = ['type' => 'danger', 'text' => 'No tienes permisos para actualizar administrativos.'];
        } else {
            $empId = intval($_POST['emp_id'] ?? 0);
            $fullName = trim($_POST['edit_full_name'] ?? '');
            $role = trim($_POST['edit_role'] ?? '');
            $newPassword = $_POST['edit_password'] ?? '';
            $selectedPermissions = isset($_POST['edit_permissions']) && is_array($_POST['edit_permissions']) ? array_map('strval', $_POST['edit_permissions']) : [];

            if ($empId <= 0) {
                $messages[] = ['type' => 'danger', 'text' => 'Identificador de administrativo no válido.'];
            } elseif ($fullName === '') {
                $messages[] = ['type' => 'danger', 'text' => 'El nombre del administrativo es obligatorio.'];
            } else {
                $stmtFetch = $conn->prepare('SELECT Emp_Email, IsSuperAdmin FROM emp_login WHERE EmpID = ? LIMIT 1');
                if ($stmtFetch) {
                    $stmtFetch->bind_param('i', $empId);
                    $stmtFetch->execute();
                    $stmtFetch->bind_result($empEmail, $isSuperAdmin);
                    if ($stmtFetch->fetch()) {
                        $stmtFetch->close();

                        $isProtected = ($empEmail === 'admin@hotelandino.com') || ($isSuperAdmin);
                        $isSuperAdmin = $isProtected ? 1 : 0;
                        if ($isProtected) {
                            $role = 'Super Administrador';
                            $permissionsList = array_keys(admin_available_permissions());
                        } else {
                            $permissionsList = !empty($selectedPermissions) ? array_values(array_unique($selectedPermissions)) : admin_default_role_permissions($role);
                            if (empty($permissionsList)) {
                                $permissionsList = ['dashboard'];
                            }
                        }

                        $permissionsJson = json_encode(array_values($permissionsList), JSON_UNESCAPED_UNICODE);

                        if ($isProtected && empty($permissionsList)) {
                            $permissionsJson = json_encode(array_keys(admin_available_permissions()), JSON_UNESCAPED_UNICODE);
                        }

                        if ($newPassword !== '') {
                            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                            $stmtUpdate = $conn->prepare('UPDATE emp_login SET FullName = ?, Role = ?, Permissions = ?, Emp_Password = ?, IsSuperAdmin = ? WHERE EmpID = ?');
                            if ($stmtUpdate) {
                                $stmtUpdate->bind_param('ssssii', $fullName, $role, $permissionsJson, $hashedPassword, $isSuperAdmin, $empId);
                            }
                        } else {
                            $stmtUpdate = $conn->prepare('UPDATE emp_login SET FullName = ?, Role = ?, Permissions = ?, IsSuperAdmin = ? WHERE EmpID = ?');
                            if ($stmtUpdate) {
                                $stmtUpdate->bind_param('sssii', $fullName, $role, $permissionsJson, $isSuperAdmin, $empId);
                            }
                        }

                        if (isset($stmtUpdate) && $stmtUpdate) {
                            if ($stmtUpdate->execute()) {
                                $messages[] = ['type' => 'success', 'text' => 'Administrativo actualizado correctamente.'];
                                if ($employee['email'] === $empEmail) {
                                    admin_refresh_session($conn, $empEmail);
                                }
                            } else {
                                $messages[] = ['type' => 'danger', 'text' => 'No se pudo actualizar el administrativo: ' . htmlspecialchars($stmtUpdate->error)];
                            }
                            $stmtUpdate->close();
                        }
                    } else {
                        $messages[] = ['type' => 'danger', 'text' => 'No se encontró el administrativo solicitado.'];
                        $stmtFetch->close();
                    }
                }
            }
        }
    }

    if ($action === 'delete_admin_staff') {
        if (!admin_user_can('registros.admins')) {
            $messages[] = ['type' => 'danger', 'text' => 'No tienes permisos para eliminar administrativos.'];
        } else {
            $empId = intval($_POST['emp_id'] ?? 0);
            if ($empId <= 0) {
                $messages[] = ['type' => 'danger', 'text' => 'Identificador de administrativo no válido.'];
            } else {
                $stmtFetch = $conn->prepare('SELECT Emp_Email, IsSuperAdmin FROM emp_login WHERE EmpID = ? LIMIT 1');
                if ($stmtFetch) {
                    $stmtFetch->bind_param('i', $empId);
                    $stmtFetch->execute();
                    $stmtFetch->bind_result($empEmail, $isSuperAdmin);
                    if ($stmtFetch->fetch()) {
                        $stmtFetch->close();
                        if ($isSuperAdmin || $empEmail === 'admin@hotelandino.com' || $empEmail === $employee['email']) {
                            $messages[] = ['type' => 'danger', 'text' => 'No es posible eliminar este administrativo.'];
                        } else {
                            $stmtDelete = $conn->prepare('DELETE FROM emp_login WHERE EmpID = ? LIMIT 1');
                            if ($stmtDelete) {
                                $stmtDelete->bind_param('i', $empId);
                                if ($stmtDelete->execute()) {
                                    $messages[] = ['type' => 'success', 'text' => 'Administrativo eliminado correctamente.'];
                                } else {
                                    $messages[] = ['type' => 'danger', 'text' => 'No se pudo eliminar el administrativo: ' . htmlspecialchars($stmtDelete->error)];
                                }
                                $stmtDelete->close();
                            }
                        }
                    } else {
                        $messages[] = ['type' => 'danger', 'text' => 'No se encontró el administrativo solicitado.'];
                        $stmtFetch->close();
                    }
                }
            }
        }
    }

    if ($action === 'create_product') {
        if (!admin_user_can('registros.products')) {
            $messages[] = ['type' => 'danger', 'text' => 'No tienes permisos para registrar productos.'];
        } else {
            $name = trim($_POST['product_name'] ?? '');
            $price = floatval($_POST['product_price'] ?? 0);

            if ($name === '' || $price <= 0) {
                $messages[] = ['type' => 'danger', 'text' => 'Indica un nombre y un precio válido para el producto.'];
            } else {
                $stmt = $conn->prepare('INSERT INTO products (name, price) VALUES (?, ?)');
                if ($stmt) {
                    $stmt->bind_param('sd', $name, $price);
                    if ($stmt->execute()) {
                        $messages[] = ['type' => 'success', 'text' => 'Producto registrado correctamente.'];
                    } else {
                        $messages[] = ['type' => 'danger', 'text' => 'No se pudo registrar el producto: ' . htmlspecialchars($stmt->error)];
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'create_sale') {
        if (!admin_user_can('registros.sales')) {
            $messages[] = ['type' => 'danger', 'text' => 'No tienes permisos para registrar ventas.'];
        } else {
            $productId = isset($_POST['sale_product']) && $_POST['sale_product'] !== '' ? intval($_POST['sale_product']) : null;
            $details = trim($_POST['sale_details'] ?? '');
            $quantity = max(1, intval($_POST['sale_quantity'] ?? 1));
            $total = floatval($_POST['sale_total'] ?? 0);

            if ($total <= 0) {
                $messages[] = ['type' => 'danger', 'text' => 'El total de la venta debe ser mayor que cero.'];
            } else {
                if ($productId === 0) {
                    $productId = null;
                }

                if ($productId === null) {
                    $stmt = $conn->prepare('INSERT INTO sales (product_id, details, quantity, total) VALUES (NULL, ?, ?, ?)');
                    if ($stmt) {
                        $stmt->bind_param('sid', $details, $quantity, $total);
                    }
                } else {
                    $stmt = $conn->prepare('INSERT INTO sales (product_id, details, quantity, total) VALUES (?, ?, ?, ?)');
                    if ($stmt) {
                        $stmt->bind_param('isid', $productId, $details, $quantity, $total);
                    }
                }

                if (isset($stmt) && $stmt) {
                    if ($stmt->execute()) {
                        $messages[] = ['type' => 'success', 'text' => 'Venta registrada correctamente.'];
                    } else {
                        $messages[] = ['type' => 'danger', 'text' => 'No se pudo registrar la venta: ' . htmlspecialchars($stmt->error)];
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'update_room_rates') {
        if (!admin_user_can('registros.pricing')) {
            $messages[] = ['type' => 'danger', 'text' => 'No tienes permisos para actualizar las tarifas.'];
        } else {
            $rates = isset($_POST['rates']) && is_array($_POST['rates']) ? $_POST['rates'] : [];
            if (empty($rates)) {
                $messages[] = ['type' => 'danger', 'text' => 'No se recibieron tarifas para actualizar.'];
            } else {
                $stmt = $conn->prepare('UPDATE room_rates SET base_price = ? WHERE room_type = ?');
                if ($stmt) {
                    $updated = 0;
                    foreach ($rates as $type => $value) {
                        $price = floatval($value);
                        if ($price <= 0) {
                            continue;
                        }
                        $stmt->bind_param('ds', $price, $type);
                        if ($stmt->execute()) {
                            $updated++;
                        }
                    }
                    $stmt->close();
                    if ($updated > 0) {
                        $messages[] = ['type' => 'success', 'text' => 'Tarifas actualizadas correctamente.'];
                    } else {
                        $messages[] = ['type' => 'warning', 'text' => 'No se realizaron cambios en las tarifas.'];
                    }
                }
            }
        }
    }
}

$roomTypes = [];
if ($result = mysqli_query($conn, 'SELECT id, name FROM room_types ORDER BY name')) {
    while ($row = mysqli_fetch_assoc($result)) {
        $roomTypes[] = $row;
    }
    mysqli_free_result($result);
}

$products = [];
if ($result = mysqli_query($conn, 'SELECT id, name, price FROM products ORDER BY name')) {
    while ($row = mysqli_fetch_assoc($result)) {
        $products[] = $row;
    }
    mysqli_free_result($result);
}

$employeesList = [];
if (admin_user_can('registros.admins')) {
    if ($result = mysqli_query($conn, 'SELECT EmpID, Emp_Email, FullName, Role, Permissions, IsSuperAdmin, CreatedAt FROM emp_login ORDER BY Emp_Email')) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['permissions_array'] = [];
            if (!empty($row['Permissions'])) {
                $decoded = json_decode($row['Permissions'], true);
                if (is_array($decoded)) {
                    $row['permissions_array'] = array_values(array_unique(array_map('strval', $decoded)));
                }
            }
            $employeesList[] = $row;
        }
        mysqli_free_result($result);
    }
}

$roomRates = [];
if ($result = mysqli_query($conn, 'SELECT room_type, base_price FROM room_rates ORDER BY room_type')) {
    while ($row = mysqli_fetch_assoc($result)) {
        $roomRates[$row['room_type']] = (float)$row['base_price'];
    }
    mysqli_free_result($result);
}
foreach (admin_default_room_rates() as $type => $price) {
    if (!isset($roomRates[$type])) {
        $roomRates[$type] = $price;
    }
}

$permissionsCatalog = admin_permissions_for_display();

$recentRooms = $recentTypes = $recentUsers = $recentAdmins = $recentSales = null;
if ($view === 'summary') {
    $recentRooms = mysqli_query($conn, 'SELECT room_number, type, status, floor FROM room ORDER BY id DESC LIMIT 8');
    $recentTypes = mysqli_query($conn, 'SELECT name, created_at FROM room_types ORDER BY created_at DESC LIMIT 8');
    $recentUsers = mysqli_query($conn, 'SELECT Username, Email, CreatedAt FROM signup ORDER BY CreatedAt DESC LIMIT 8');
    $recentAdmins = mysqli_query($conn, 'SELECT FullName, Emp_Email, CreatedAt FROM emp_login ORDER BY CreatedAt DESC LIMIT 8');
    $recentSales = mysqli_query($conn, 'SELECT s.id, s.details, s.quantity, s.total, s.sold_at, p.name AS product_name FROM sales s LEFT JOIN products p ON p.id = s.product_id ORDER BY s.sold_at DESC LIMIT 10');
}

$viewTitle = $views[$view]['label'];
$viewDescription = $views[$view]['description'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Hotel Andino - Registros</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/records.css">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h2 class="mb-1"><?php echo $viewTitle; ?></h2>
                <p class="text-muted mb-0"><?php echo $viewDescription; ?></p>
            </div>
            <span class="badge bg-dark">Sesión: <?php echo htmlspecialchars(($employee['name'] ?: $adminEmail) . ' · ' . $adminEmail); ?></span>
        </div>

        <div class="mb-4">
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($views as $key => $definition): ?>
                    <a class="btn btn-sm <?php echo $key === $view ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?view=<?php echo $key; ?>">
                        <?php echo $definition['label']; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $message['text']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            </div>
        <?php endforeach; ?>

        <?php if ($view === 'rooms'): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8 col-xl-6">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Registrar habitación</h5>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="action" value="create_room">
                                <input type="hidden" name="view" value="rooms">
                                <div class="col-md-4">
                                    <label for="room_floor" class="form-label">Piso</label>
                                    <select id="room_floor" name="floor" class="form-select">
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="room_number" class="form-label">Número</label>
                                    <input type="text" name="room_number" id="room_number" class="form-control" placeholder="301" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="room_status" class="form-label">Estado</label>
                                    <select name="status" id="room_status" class="form-select">
                                        <option value="Disponible">Disponible</option>
                                        <option value="Reservada">Reservada</option>
                                        <option value="Limpieza">Limpieza</option>
                                        <option value="Ocupada">Ocupada</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="room_type" class="form-label">Tipo de habitación</label>
                                    <input list="room_type_list" name="room_type" id="room_type" class="form-control" placeholder="Habitación Suite" required>
                                    <datalist id="room_type_list">
                                        <?php foreach ($roomTypes as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type['name']); ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-md-6">
                                    <label for="room_bedding" class="form-label">Capacidad</label>
                                    <select name="bedding" id="room_bedding" class="form-select" required>
                                        <option value="">Selecciona una opción</option>
                                        <option value="1 cliente">1 cliente</option>
                                        <option value="2 clientes">2 clientes</option>
                                        <option value="3 clientes">3 clientes</option>
                                        <option value="4 clientes">4 clientes</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">Guardar habitación</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($view === 'room-types'): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8 col-xl-6">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Registrar tipo de habitación</h5>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="action" value="create_room_type">
                                <input type="hidden" name="view" value="room-types">
                                <div class="col-12">
                                    <label for="type_name" class="form-label">Nombre</label>
                                    <input type="text" name="type_name" id="type_name" class="form-control" placeholder="Habitación Suite" required>
                                </div>
                                <div class="col-12">
                                    <label for="type_description" class="form-label">Descripción</label>
                                    <textarea name="type_description" id="type_description" rows="3" class="form-control" placeholder="Incluye balcón privado, sala y vista panorámica"></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-outline-primary">Guardar tipo</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($view === 'admin-staff'): ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <h5 class="card-title">Agregar administrativo</h5>
                            <p class="text-muted small">Crea accesos para recepcionistas, porteros o personal de apoyo.</p>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="action" value="create_admin_staff">
                                <input type="hidden" name="view" value="admin-staff">
                                <div class="col-12">
                                    <label for="admin_full_name" class="form-label">Nombre completo</label>
                                    <input type="text" id="admin_full_name" name="admin_full_name" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label for="admin_email" class="form-label">Correo institucional</label>
                                    <input type="email" id="admin_email" name="admin_email" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label for="admin_password" class="form-label">Contraseña temporal</label>
                                    <input type="password" id="admin_password" name="admin_password" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="admin_role" class="form-label">Rol</label>
                                    <select id="admin_role" name="admin_role" class="form-select">
                                        <option value="Recepcionista">Recepcionista</option>
                                        <option value="Doorman">Portero</option>
                                        <option value="Limpieza">Limpieza</option>
                                        <option value="">Otro</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <span class="form-label">Permisos</span>
                                    <div class="border rounded p-2 bg-light" style="max-height: 220px; overflow:auto;">
                                        <?php foreach ($permissionsCatalog as $section => $perms): ?>
                                            <div class="mb-2">
                                                <strong class="d-block small text-uppercase text-muted"><?php echo htmlspecialchars($section); ?></strong>
                                                <?php foreach ($perms as $permKey => $permLabel): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="admin_permissions[]" id="perm-new-<?php echo md5($permKey); ?>" value="<?php echo htmlspecialchars($permKey); ?>">
                                                        <label class="form-check-label" for="perm-new-<?php echo md5($permKey); ?>"><?php echo htmlspecialchars($permLabel); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <p class="text-muted small mb-0">Si no seleccionas permisos se aplicará la configuración sugerida según el rol.</p>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary w-100">Registrar administrativo</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Administrativos registrados</h5>
                            <?php if (empty($employeesList)): ?>
                                <p class="text-muted mb-0">Aún no se han creado cuentas administrativas adicionales.</p>
                            <?php else: ?>
                                <div class="accordion" id="adminStaffAccordion">
                                    <?php foreach ($employeesList as $index => $admin): ?>
                                        <?php
                                            $adminId = (int)$admin['EmpID'];
                                            $adminEmailSafe = htmlspecialchars($admin['Emp_Email']);
                                            $adminNameSafe = htmlspecialchars($admin['FullName'] ?: $admin['Emp_Email']);
                                            $isProtected = (int)$admin['IsSuperAdmin'] === 1 || $admin['Emp_Email'] === 'admin@hotelandino.com';
                                            $currentPermissions = $admin['permissions_array'];
                                            $collapseId = 'staff-' . $adminId;
                                        ?>
                                        <div class="accordion-item mb-2">
                                            <h2 class="accordion-header" id="heading-<?php echo $adminId; ?>">
                                                <button class="accordion-button <?php echo $index === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-controls="<?php echo $collapseId; ?>">
                                                    <div class="w-100 d-flex justify-content-between align-items-center">
                                                        <span><?php echo $adminNameSafe; ?> <small class="text-muted">(<?php echo $adminEmailSafe; ?>)</small></span>
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($admin['Role'] ?: 'Sin rol'); ?></span>
                                                    </div>
                                                </button>
                                            </h2>
                                            <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>" data-bs-parent="#adminStaffAccordion">
                                                <div class="accordion-body">
                                                    <form method="post" class="row g-3">
                                                        <input type="hidden" name="view" value="admin-staff">
                                                        <input type="hidden" name="emp_id" value="<?php echo $adminId; ?>">
                                                        <div class="col-md-6">
                                                            <label class="form-label">Nombre</label>
                                                            <input type="text" name="edit_full_name" class="form-control" value="<?php echo $adminNameSafe; ?>" <?php echo $isProtected ? 'readonly' : 'required'; ?>>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Correo</label>
                                                            <input type="text" class="form-control" value="<?php echo $adminEmailSafe; ?>" readonly>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Rol</label>
                                                            <select name="edit_role" class="form-select" <?php echo $isProtected ? 'disabled' : ''; ?>>
                                                                <option value="Recepcionista" <?php echo $admin['Role'] === 'Recepcionista' ? 'selected' : ''; ?>>Recepcionista</option>
                                                                <option value="Doorman" <?php echo $admin['Role'] === 'Doorman' ? 'selected' : ''; ?>>Portero</option>
                                                                <option value="Limpieza" <?php echo $admin['Role'] === 'Limpieza' ? 'selected' : ''; ?>>Limpieza</option>
                                                                <option value="" <?php echo $admin['Role'] === '' ? 'selected' : ''; ?>>Otro</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Nueva contraseña</label>
                                                            <input type="password" name="edit_password" class="form-control" placeholder="Opcional" <?php echo $isProtected ? 'disabled' : ''; ?>>
                                                        </div>
                                                        <div class="col-12">
                                                            <span class="form-label">Permisos</span>
                                                            <div class="border rounded p-2 bg-light" style="max-height:200px; overflow:auto;">
                                                                <?php foreach ($permissionsCatalog as $section => $perms): ?>
                                                                    <div class="mb-2">
                                                                        <strong class="d-block small text-uppercase text-muted"><?php echo htmlspecialchars($section); ?></strong>
                                                                        <?php foreach ($perms as $permKey => $permLabel): ?>
                                                                            <?php $checkboxId = 'perm-' . $adminId . '-' . md5($permKey); ?>
                                                                            <div class="form-check">
                                                                                <input class="form-check-input" type="checkbox" name="edit_permissions[]" id="<?php echo $checkboxId; ?>" value="<?php echo htmlspecialchars($permKey); ?>" <?php echo in_array($permKey, $currentPermissions, true) ? 'checked' : ''; ?> <?php echo $isProtected ? 'disabled' : ''; ?>>
                                                                                <label class="form-check-label" for="<?php echo $checkboxId; ?>"><?php echo htmlspecialchars($permLabel); ?></label>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                                <?php if ($isProtected): ?>
                                                                    <p class="text-muted small mb-0">El super administrador conserva todos los permisos.</p>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="col-12 d-flex justify-content-between">
                                                            <div>
                                                                <small class="text-muted">Creado el <?php echo htmlspecialchars(date('d/m/Y', strtotime($admin['CreatedAt']))); ?></small>
                                                            </div>
                                                            <div class="d-flex gap-2">
                                                                <button type="submit" name="action" value="update_admin_staff" class="btn btn-sm btn-outline-primary" <?php echo $isProtected ? 'disabled' : ''; ?>>Guardar</button>
                                                                <?php if (!$isProtected): ?>
                                                                    <button type="submit" name="action" value="delete_admin_staff" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar administrativo?');">Eliminar</button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($view === 'products'): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8 col-xl-6">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Registrar producto</h5>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="action" value="create_product">
                                <input type="hidden" name="view" value="products">
                                <div class="col-md-8">
                                    <label for="product_name" class="form-label">Nombre</label>
                                    <input type="text" id="product_name" name="product_name" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="product_price" class="form-label">Precio (COP)</label>
                                    <input type="number" id="product_price" name="product_price" min="0" step="0.01" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-outline-primary">Guardar producto</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($view === 'sales'): ?>
            <div class="row justify-content-center">
                <div class="col-lg-10 col-xl-8">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Registrar venta</h5>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="action" value="create_sale">
                                <input type="hidden" name="view" value="sales">
                                <div class="col-md-6">
                                    <label for="sale_product" class="form-label">Producto</label>
                                    <select id="sale_product" name="sale_product" class="form-select">
                                        <option value="">-- Selecciona --</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?> (<?php echo number_format($product['price'], 0, ',', '.'); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="0">Otro / sin producto registrado</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="sale_quantity" class="form-label">Cantidad</label>
                                    <input type="number" id="sale_quantity" name="sale_quantity" min="1" class="form-control" value="1">
                                </div>
                                <div class="col-md-3">
                                    <label for="sale_total" class="form-label">Total (COP)</label>
                                    <input type="number" id="sale_total" name="sale_total" step="0.01" min="0" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label for="sale_details" class="form-label">Descripción / Cliente</label>
                                    <input type="text" id="sale_details" name="sale_details" class="form-control" placeholder="Ej. Consumo minibar habitación 204">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-success">Guardar venta</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($view === 'pricing'): ?>
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Tarifas por tipo de habitación</h5>
                            <p class="text-muted small">Estos valores se utilizan como referencia en los módulos de reservas y habitaciones.</p>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="action" value="update_room_rates">
                                <input type="hidden" name="view" value="pricing">
                                <?php foreach ($roomRates as $type => $price): ?>
                                    <div class="col-12">
                                        <label class="form-label"><?php echo htmlspecialchars($type); ?></label>
                                        <div class="input-group">
                                            <span class="input-group-text">COP</span>
                                            <input type="number" class="form-control" step="0.01" min="0" name="rates[<?php echo htmlspecialchars($type); ?>]" value="<?php echo htmlspecialchars(rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.')); ?>" required>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary w-100">Actualizar tarifas</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row justify-content-center">
                <div class="col-lg-10 col-xl-8">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Resumen rápido</h5>
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <h6 class="text-muted">Últimas habitaciones</h6>
                                    <ul class="list-group list-group-flush">
                                        <?php if ($recentRooms && mysqli_num_rows($recentRooms) > 0): ?>
                                            <?php while ($room = mysqli_fetch_assoc($recentRooms)): ?>
                                                <li class="list-group-item">
                                                    Hab. <?php echo htmlspecialchars($room['room_number']); ?> · <?php echo htmlspecialchars($room['type']); ?> · Piso <?php echo (int)$room['floor']; ?> · <?php echo htmlspecialchars($room['status']); ?>
                                                </li>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <li class="list-group-item text-muted">Sin registros recientes.</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted">Tipos registrados</h6>
                                    <ul class="list-group list-group-flush">
                                        <?php if ($recentTypes && mysqli_num_rows($recentTypes) > 0): ?>
                                            <?php while ($type = mysqli_fetch_assoc($recentTypes)): ?>
                                                <li class="list-group-item d-flex justify-content-between">
                                                    <span><?php echo htmlspecialchars($type['name']); ?></span>
                                                    <small class="text-muted"><?php echo date('d/m/Y', strtotime($type['created_at'])); ?></small>
                                                </li>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <li class="list-group-item text-muted">Añade tu primer tipo.</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted">Usuarios recientes</h6>
                                    <ul class="list-group list-group-flush">
                                        <?php if ($recentUsers && mysqli_num_rows($recentUsers) > 0): ?>
                                            <?php while ($user = mysqli_fetch_assoc($recentUsers)): ?>
                                                <li class="list-group-item d-flex justify-content-between">
                                                    <span><?php echo htmlspecialchars($user['Username']); ?></span>
                                                    <small class="text-muted"><?php echo htmlspecialchars($user['Email']); ?></small>
                                                </li>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <li class="list-group-item text-muted">Aún no hay usuarios nuevos.</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted">Administrativos recientes</h6>
                                    <ul class="list-group list-group-flush">
                                        <?php if ($recentAdmins && mysqli_num_rows($recentAdmins) > 0): ?>
                                            <?php while ($adminRow = mysqli_fetch_assoc($recentAdmins)): ?>
                                                <li class="list-group-item d-flex justify-content-between">
                                                    <span><?php echo htmlspecialchars($adminRow['FullName'] ?: $adminRow['Emp_Email']); ?></span>
                                                    <small class="text-muted"><?php echo htmlspecialchars($adminRow['Emp_Email']); ?></small>
                                                </li>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <li class="list-group-item text-muted">Sin movimientos recientes.</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted">Últimas ventas</h6>
                                    <ul class="list-group list-group-flush">
                                        <?php if ($recentSales && mysqli_num_rows($recentSales) > 0): ?>
                                            <?php while ($sale = mysqli_fetch_assoc($recentSales)): ?>
                                                <li class="list-group-item">
                                                    <strong>COP <?php echo number_format($sale['total'], 0, ',', '.'); ?></strong> · <?php echo htmlspecialchars($sale['details'] ?: 'Sin detalle'); ?>
                                                    <br>
                                                    <small class="text-muted"><?php echo $sale['product_name'] ? htmlspecialchars($sale['product_name']) . ' · ' : ''; ?><?php echo date('d/m/Y H:i', strtotime($sale['sold_at'])); ?></small>
                                                </li>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <li class="list-group-item text-muted">Sin ventas registradas.</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
