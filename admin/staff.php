<?php
session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

ensureEmpStructure($conn);
ensureRoomRates($conn);
ensureStaffStructure($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
admin_require_permission('personal');

function staff_normalize_salary(string $value): ?string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $clean = preg_replace('/[^0-9,\.]/', '', $trimmed);
    if ($clean === '') {
        return null;
    }

    $hasComma = strpos($clean, ',') !== false;
    $hasDot = strpos($clean, '.') !== false;

    if ($hasComma && $hasDot) {
        if (strrpos($clean, ',') > strrpos($clean, '.')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }
    } elseif ($hasComma && !$hasDot) {
        $clean = str_replace(',', '.', $clean);
    }

    if (!is_numeric($clean)) {
        return null;
    }

    return number_format((float)$clean, 2, '.', '');
}

function staff_collect_payload(array $source, ?string $forcedCategory = null, bool $requireAdminEmail = false): array
{
    $name = trim($source['name'] ?? '');
    $category = $forcedCategory ?? ($source['category'] ?? 'operativo');
    $category = $category === 'administrativo' ? 'administrativo' : 'operativo';
    $work = trim($source['work'] ?? '');
    $customWork = trim($source['work_custom'] ?? '');
    if ($work === '__custom') {
        $work = $customWork;
    }
    $adminEmail = trim($source['admin_email'] ?? '');
    if ($adminEmail === '') {
        $adminEmail = null;
    }
    if ($category !== 'administrativo') {
        $adminEmail = null;
    }

    $document = trim($source['document_number'] ?? '');
    $email = trim($source['email'] ?? '');
    $phone = trim($source['phone'] ?? '');
    $hireDate = trim($source['hire_date'] ?? '');
    $notes = trim($source['notes'] ?? '');

    $errors = [];
    if ($name === '') {
        $errors[] = 'El nombre es obligatorio.';
    }
    if ($work === '') {
        $errors[] = 'El cargo es obligatorio.';
    }
    if ($category === 'administrativo' && $requireAdminEmail && !$adminEmail) {
        $errors[] = 'Selecciona o indica el correo del administrativo.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El correo electrónico no es válido.';
    }

    if ($hireDate !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $hireDate);
        if (!$date || $date->format('Y-m-d') !== $hireDate) {
            $errors[] = 'La fecha de ingreso no es válida.';
        }
    } else {
        $hireDate = null;
    }

    $salaryValue = staff_normalize_salary($source['salary'] ?? '');
    if (($source['salary'] ?? '') !== '' && $salaryValue === null) {
        $errors[] = 'El salario no tiene un formato válido.';
    }

    $data = [
        'name'            => $name,
        'work'            => $work,
        'category'        => $category,
        'admin_email'     => $adminEmail,
        'document_number' => $document !== '' ? $document : null,
        'email'           => $email !== '' ? $email : null,
        'phone'           => $phone !== '' ? $phone : null,
        'hire_date'       => $hireDate,
        'salary'          => $salaryValue,
        'notes'           => $notes !== '' ? $notes : null,
    ];

    return [$data, $errors];
}

function staff_insert_or_merge(mysqli $conn, array $data): bool
{
    $sql = 'INSERT INTO staff (name, work, category, admin_email, document_number, email, phone, hire_date, salary, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                work = VALUES(work),
                category = VALUES(category),
                admin_email = VALUES(admin_email),
                document_number = VALUES(document_number),
                email = VALUES(email),
                phone = VALUES(phone),
                hire_date = VALUES(hire_date),
                salary = VALUES(salary),
                notes = VALUES(notes)';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'ssssssssss',
        $data['name'],
        $data['work'],
        $data['category'],
        $data['admin_email'],
        $data['document_number'],
        $data['email'],
        $data['phone'],
        $data['hire_date'],
        $data['salary'],
        $data['notes']
    );

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function staff_update_by_id(mysqli $conn, array $data, int $id): bool
{
    $sql = 'UPDATE staff
            SET name = ?, work = ?, category = ?, admin_email = ?, document_number = ?, email = ?, phone = ?, hire_date = ?, salary = ?, notes = ?
            WHERE id = ?';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'ssssssssssi',
        $data['name'],
        $data['work'],
        $data['category'],
        $data['admin_email'],
        $data['document_number'],
        $data['email'],
        $data['phone'],
        $data['hire_date'],
        $data['salary'],
        $data['notes'],
        $id
    );

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function staff_add_flash(string $type, string $text): void
{
    if (!isset($_SESSION['staff_flash']) || !is_array($_SESSION['staff_flash'])) {
        $_SESSION['staff_flash'] = [];
    }
    $_SESSION['staff_flash'][] = ['type' => $type, 'text' => $text];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['addstaff'])) {
        [$payload, $errors] = staff_collect_payload($_POST);
        if ($errors) {
            staff_add_flash('danger', implode(' ', $errors));
        } elseif (staff_insert_or_merge($conn, $payload)) {
            staff_add_flash('success', 'Se registró el colaborador correctamente.');
        } else {
            staff_add_flash('danger', 'No se pudo registrar al colaborador. Intenta nuevamente.');
        }
        header('Location: staff.php');
        exit;
    }

    if (isset($_POST['update_staff'])) {
        $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
        [$payload, $errors] = staff_collect_payload($_POST);
        if ($staffId <= 0) {
            staff_add_flash('danger', 'No se pudo identificar el registro a actualizar.');
        } elseif ($errors) {
            staff_add_flash('danger', implode(' ', $errors));
        } elseif (staff_update_by_id($conn, $payload, $staffId)) {
            staff_add_flash('success', 'Se actualizaron los datos del colaborador.');
        } else {
            staff_add_flash('danger', 'No se pudieron guardar los cambios.');
        }
        header('Location: staff.php');
        exit;
    }

    if (isset($_POST['update_admin_profile'])) {
        $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
        [$payload, $errors] = staff_collect_payload($_POST, 'administrativo', true);
        $adminEmail = $payload['admin_email'];
        if (!$adminEmail) {
            $errors[] = 'No se pudo determinar el correo del administrativo.';
        }

        if ($errors) {
            staff_add_flash('danger', implode(' ', $errors));
            header('Location: staff.php');
            exit;
        }

        if ($stmt = $conn->prepare('UPDATE emp_login SET FullName = ?, Role = ? WHERE Emp_Email = ? LIMIT 1')) {
            $stmt->bind_param('sss', $payload['name'], $payload['work'], $adminEmail);
            $stmt->execute();
            $stmt->close();
        }

        $payload['category'] = 'administrativo';
        $saved = false;
        if ($staffId > 0) {
            $saved = staff_update_by_id($conn, $payload, $staffId);
        } else {
            $saved = staff_insert_or_merge($conn, $payload);
        }

        if ($saved) {
            staff_add_flash('success', 'Se actualizaron los datos del perfil administrativo.');
        } else {
            staff_add_flash('danger', 'No se pudieron actualizar los datos administrativos.');
        }
        header('Location: staff.php');
        exit;
    }
}

$messages = [];
if (!empty($_SESSION['staff_flash']) && is_array($_SESSION['staff_flash'])) {
    $messages = $_SESSION['staff_flash'];
}
unset($_SESSION['staff_flash']);

$roleCatalog = [
    'Administrador general',
    'Recepcionista',
    'Coordinador de reservas',
    'Contador',
    'Marketing y ventas',
    'Recursos humanos',
    'Servicios generales',
    'Chef',
    'Conserje',
    'Mantenimiento',
];

function staff_role_is_known(string $role, array $catalog): bool
{
    foreach ($catalog as $option) {
        if (strcasecmp($option, $role) === 0) {
            return true;
        }
    }
    return false;
}

$adminProfiles = [];
$sqlAdmins = 'SELECT e.EmpID, e.Emp_Email, e.FullName, e.Role, e.CreatedAt, s.id AS staff_id, s.name AS staff_name, s.work AS staff_work,
                     s.document_number, s.email AS staff_email, s.phone AS staff_phone, s.hire_date, s.salary, s.notes
              FROM emp_login e
              LEFT JOIN staff s ON s.admin_email = e.Emp_Email
              ORDER BY e.FullName IS NULL, e.FullName, e.Emp_Email';
if ($result = mysqli_query($conn, $sqlAdmins)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $adminProfiles[] = $row;
    }
    mysqli_free_result($result);
}

$team = [];
$sqlStaff = "SELECT * FROM staff WHERE COALESCE(admin_email, '') = '' ORDER BY name";
if ($result = mysqli_query($conn, $sqlStaff)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $team[] = $row;
    }
    mysqli_free_result($result);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Andino - Admin | Personal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohENhE6kG7Y3MZ9Z6Q4b9omW7WdF2zjg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/staff.css">
</head>
<body class="bg-light">
<div class="staff-page container py-4">
    <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
                        <div>
                            <h1 class="h4 mb-1">Gestión de personal</h1>
                            <p class="text-muted mb-0">Administra la información del equipo administrativo y operativo del hotel.</p>
                        </div>
                        <span class="badge text-bg-dark">Sesión: <?php echo htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['adminmail'] ?? ''); ?></span>
                    </div>

                    <?php foreach ($messages as $message): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($message['type']); ?> alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($message['text']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                        </div>
                    <?php endforeach; ?>

                    <form method="post" class="row g-3" id="staff-create-form">
                        <input type="hidden" name="addstaff" value="1">
                        <div class="col-md-6">
                            <label class="form-label" for="staff-name">Nombre completo</label>
                            <input type="text" class="form-control" id="staff-name" name="name" placeholder="Ej. Ana Pérez" required>
                        </div>
                        <div class="col-md-6" data-category-wrapper>
                            <label class="form-label" for="staff-category">Tipo de colaborador</label>
                            <select class="form-select" id="staff-category" name="category" data-category-select>
                                <option value="administrativo">Administrativo</option>
                                <option value="operativo" selected>Operativo</option>
                            </select>
                        </div>
                        <div class="col-md-6" data-role-wrapper>
                            <label class="form-label" for="staff-work">Cargo</label>
                            <select class="form-select" id="staff-work" name="work" data-role-select>
                                <option value="">Selecciona un cargo</option>
                                <?php foreach ($roleCatalog as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                                <?php endforeach; ?>
                                <option value="__custom">Otro (especificar)</option>
                            </select>
                            <input type="text" class="form-control mt-2 d-none" name="work_custom" placeholder="Describe el cargo" data-custom-role>
                        </div>
                        <div class="col-md-6" data-admin-email>
                            <label class="form-label" for="staff-admin-email">Correo institucional (opcional)</label>
                            <input type="email" class="form-control" id="staff-admin-email" name="admin_email" placeholder="usuario@hotelandino.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="staff-document">Documento / ID</label>
                            <input type="text" class="form-control" id="staff-document" name="document_number" placeholder="CC o documento">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="staff-email">Correo personal</label>
                            <input type="email" class="form-control" id="staff-email" name="email" placeholder="nombre@correo.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="staff-phone">Teléfono</label>
                            <input type="text" class="form-control" id="staff-phone" name="phone" placeholder="Ej. +57 300 000 0000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="staff-hire-date">Fecha de ingreso</label>
                            <input type="date" class="form-control" id="staff-hire-date" name="hire_date">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="staff-salary">Salario base (COP)</label>
                            <input type="text" class="form-control" id="staff-salary" name="salary" placeholder="Ej. 1.500.000">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="staff-notes">Notas internas</label>
                            <textarea class="form-control" id="staff-notes" name="notes" rows="2" placeholder="Observaciones adicionales"></textarea>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-user-plus me-2"></i>Registrar personal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <section class="mt-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Equipo administrativo</h2>
            <span class="badge text-bg-secondary"><?php echo count($adminProfiles); ?> perfiles</span>
        </div>
        <?php if (empty($adminProfiles)): ?>
            <div class="alert alert-info">No hay perfiles administrativos registrados.</div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($adminProfiles as $admin):
                    $profileName = $admin['staff_name'] ?: ($admin['FullName'] ?: $admin['Emp_Email']);
                    $roleValue = $admin['staff_work'] ?: ($admin['Role'] ?: 'Administrativo');
                    $isCustomRole = $roleValue !== '' && !staff_role_is_known($roleValue, $roleCatalog);
                    $roleSelected = $isCustomRole ? '__custom' : $roleValue;
                    $customRoleValue = $isCustomRole ? $roleValue : '';
                    $cardId = 'adminModal-' . preg_replace('/[^a-z0-9]+/i', '-', $admin['Emp_Email']);
                    $hireDate = $admin['hire_date'] ?: ($admin['CreatedAt'] ? substr($admin['CreatedAt'], 0, 10) : '');
                    $salaryFormatted = $admin['salary'] !== null && $admin['salary'] !== '' ? number_format((float)$admin['salary'], 0, ',', '.') : 'Sin registrar';
                    ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div>
                                        <h3 class="h5 mb-1"><?php echo htmlspecialchars($profileName); ?></h3>
                                        <span class="badge text-bg-primary"><?php echo htmlspecialchars($roleValue ?: 'Administrativo'); ?></span>
                                    </div>
                                    <div class="avatar-circle">
                                        <i class="fa-solid fa-user-tie"></i>
                                    </div>
                                </div>
                                <ul class="list-unstyled small text-muted mb-3">
                                    <li><i class="fa-regular fa-envelope me-2"></i><?php echo htmlspecialchars($admin['Emp_Email']); ?></li>
                                    <?php if (!empty($admin['staff_phone'])): ?>
                                        <li><i class="fa-solid fa-phone me-2"></i><?php echo htmlspecialchars($admin['staff_phone']); ?></li>
                                    <?php endif; ?>
                                    <?php if (!empty($hireDate)): ?>
                                        <li><i class="fa-regular fa-calendar-check me-2"></i>Ingreso: <?php echo htmlspecialchars(date('d/m/Y', strtotime($hireDate))); ?></li>
                                    <?php endif; ?>
                                    <li><i class="fa-solid fa-money-bill-wave me-2"></i>Salario: <?php echo htmlspecialchars($salaryFormatted); ?></li>
                                </ul>
                                <?php if (!empty($admin['notes'])): ?>
                                    <p class="small text-muted mb-3"><?php echo nl2br(htmlspecialchars($admin['notes'])); ?></p>
                                <?php endif; ?>
                                <button class="btn btn-outline-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#<?php echo htmlspecialchars($cardId); ?>">
                                    <i class="fa-solid fa-pen-to-square me-2"></i>Editar perfil
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade" id="<?php echo htmlspecialchars($cardId); ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post" class="needs-validation" novalidate>
                                    <div class="modal-header">
                                        <h5 class="modal-title">Editar perfil administrativo</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                    </div>
                                    <div class="modal-body">
                                    <input type="hidden" name="update_admin_profile" value="1">
                                    <input type="hidden" name="staff_id" value="<?php echo (int)($admin['staff_id'] ?? 0); ?>">
                                    <input type="hidden" name="category" value="administrativo">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label" for="admin-name-<?php echo htmlspecialchars($cardId); ?>">Nombre completo</label>
                                                <input type="text" class="form-control" id="admin-name-<?php echo htmlspecialchars($cardId); ?>" name="name" value="<?php echo htmlspecialchars($profileName); ?>" required>
                                            </div>
                                            <div class="col-md-6" data-admin-email>
                                                <label class="form-label" for="admin-email-<?php echo htmlspecialchars($cardId); ?>">Correo institucional</label>
                                                <input type="email" class="form-control" id="admin-email-<?php echo htmlspecialchars($cardId); ?>" name="admin_email" value="<?php echo htmlspecialchars($admin['Emp_Email']); ?>" required>
                                            </div>
                                            <div class="col-md-6" data-role-wrapper>
                                                <label class="form-label" for="admin-role-<?php echo htmlspecialchars($cardId); ?>">Cargo</label>
                                                <select class="form-select" id="admin-role-<?php echo htmlspecialchars($cardId); ?>" name="work" data-role-select>
                                                    <option value="">Selecciona un cargo</option>
                                                    <?php foreach ($roleCatalog as $option): ?>
                                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo strcasecmp($roleValue, $option) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="__custom" <?php echo $isCustomRole ? 'selected' : ''; ?>>Otro (especificar)</option>
                                                </select>
                                                <input type="text" class="form-control mt-2 <?php echo $isCustomRole ? '' : 'd-none'; ?>" name="work_custom" value="<?php echo htmlspecialchars($customRoleValue); ?>" placeholder="Describe el cargo" data-custom-role>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="admin-document-<?php echo htmlspecialchars($cardId); ?>">Documento / ID</label>
                                                <input type="text" class="form-control" id="admin-document-<?php echo htmlspecialchars($cardId); ?>" name="document_number" value="<?php echo htmlspecialchars($admin['document_number'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="admin-phone-<?php echo htmlspecialchars($cardId); ?>">Teléfono</label>
                                                <input type="text" class="form-control" id="admin-phone-<?php echo htmlspecialchars($cardId); ?>" name="phone" value="<?php echo htmlspecialchars($admin['staff_phone'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="admin-alt-email-<?php echo htmlspecialchars($cardId); ?>">Correo alternativo</label>
                                                <input type="email" class="form-control" id="admin-alt-email-<?php echo htmlspecialchars($cardId); ?>" name="email" value="<?php echo htmlspecialchars($admin['staff_email'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="admin-hire-date-<?php echo htmlspecialchars($cardId); ?>">Fecha de ingreso</label>
                                                <input type="date" class="form-control" id="admin-hire-date-<?php echo htmlspecialchars($cardId); ?>" name="hire_date" value="<?php echo htmlspecialchars($admin['hire_date'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="admin-salary-<?php echo htmlspecialchars($cardId); ?>">Salario (COP)</label>
                                                <input type="text" class="form-control" id="admin-salary-<?php echo htmlspecialchars($cardId); ?>" name="salary" value="<?php echo htmlspecialchars($admin['salary'] !== null ? number_format((float)$admin['salary'], 0, ',', '.') : ''); ?>">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label" for="admin-notes-<?php echo htmlspecialchars($cardId); ?>">Notas internas</label>
                                                <textarea class="form-control" id="admin-notes-<?php echo htmlspecialchars($cardId); ?>" name="notes" rows="2"><?php echo htmlspecialchars($admin['notes'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Guardar cambios</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="mt-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Personal operativo</h2>
            <span class="badge text-bg-secondary"><?php echo count($team); ?> integrantes</span>
        </div>
        <?php if (empty($team)): ?>
            <div class="alert alert-info">Aún no has registrado miembros del personal operativo.</div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($team as $member):
                    $memberRole = $member['work'] ?? '';
                    $isCustomMemberRole = $memberRole !== '' && !staff_role_is_known($memberRole, $roleCatalog);
                    $memberSelectedRole = $isCustomMemberRole ? '__custom' : $memberRole;
                    $memberCustomRole = $isCustomMemberRole ? $memberRole : '';
                    $memberSalary = $member['salary'] !== null && $member['salary'] !== '' ? number_format((float)$member['salary'], 0, ',', '.') : 'Sin registrar';
                    $memberHire = $member['hire_date'] ?? '';
                    $modalId = 'staffModal-' . (int)$member['id'];
                    ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div>
                                        <h3 class="h5 mb-1"><?php echo htmlspecialchars($member['name']); ?></h3>
                                        <span class="badge text-bg-secondary"><?php echo htmlspecialchars($memberRole ?: 'Operativo'); ?></span>
                                    </div>
                                    <div class="avatar-circle bg-secondary-subtle text-secondary">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                </div>
                                <ul class="list-unstyled small text-muted mb-3">
                                    <?php if (!empty($member['phone'])): ?>
                                        <li><i class="fa-solid fa-phone me-2"></i><?php echo htmlspecialchars($member['phone']); ?></li>
                                    <?php endif; ?>
                                    <?php if (!empty($member['email'])): ?>
                                        <li><i class="fa-regular fa-envelope me-2"></i><?php echo htmlspecialchars($member['email']); ?></li>
                                    <?php endif; ?>
                                    <?php if (!empty($memberHire)): ?>
                                        <li><i class="fa-regular fa-calendar-check me-2"></i>Ingreso: <?php echo htmlspecialchars(date('d/m/Y', strtotime($memberHire))); ?></li>
                                    <?php endif; ?>
                                    <li><i class="fa-solid fa-money-bill-wave me-2"></i>Salario: <?php echo htmlspecialchars($memberSalary); ?></li>
                                </ul>
                                <?php if (!empty($member['notes'])): ?>
                                    <p class="small text-muted mb-3"><?php echo nl2br(htmlspecialchars($member['notes'])); ?></p>
                                <?php endif; ?>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-primary btn-sm flex-fill" data-bs-toggle="modal" data-bs-target="#<?php echo htmlspecialchars($modalId); ?>">
                                        <i class="fa-solid fa-pen-to-square me-2"></i>Editar
                                    </button>
                                    <form method="post" action="staffdelete.php" class="flex-fill" onsubmit="return confirm('¿Deseas eliminar este registro?');">
                                        <input type="hidden" name="id" value="<?php echo (int)$member['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                            <i class="fa-solid fa-trash-can me-2"></i>Eliminar
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="<?php echo htmlspecialchars($modalId); ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Editar colaborador</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="update_staff" value="1">
                                            <input type="hidden" name="staff_id" value="<?php echo (int)$member['id']; ?>">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label" for="member-name-<?php echo htmlspecialchars($modalId); ?>">Nombre completo</label>
                                                    <input type="text" class="form-control" id="member-name-<?php echo htmlspecialchars($modalId); ?>" name="name" value="<?php echo htmlspecialchars($member['name']); ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label" for="member-category-<?php echo htmlspecialchars($modalId); ?>">Tipo</label>
                                                    <select class="form-select" id="member-category-<?php echo htmlspecialchars($modalId); ?>" name="category" data-category-select>
                                                        <option value="administrativo" <?php echo ($member['category'] ?? 'operativo') === 'administrativo' ? 'selected' : ''; ?>>Administrativo</option>
                                                        <option value="operativo" <?php echo ($member['category'] ?? 'operativo') !== 'administrativo' ? 'selected' : ''; ?>>Operativo</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6" data-role-wrapper>
                                                    <label class="form-label" for="member-role-<?php echo htmlspecialchars($modalId); ?>">Cargo</label>
                                                    <select class="form-select" id="member-role-<?php echo htmlspecialchars($modalId); ?>" name="work" data-role-select>
                                                        <option value="">Selecciona un cargo</option>
                                                        <?php foreach ($roleCatalog as $option): ?>
                                                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo strcasecmp($memberRole, $option) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                                                        <?php endforeach; ?>
                                                        <option value="__custom" <?php echo $isCustomMemberRole ? 'selected' : ''; ?>>Otro (especificar)</option>
                                                    </select>
                                                    <input type="text" class="form-control mt-2 <?php echo $isCustomMemberRole ? '' : 'd-none'; ?>" name="work_custom" value="<?php echo htmlspecialchars($memberCustomRole); ?>" placeholder="Describe el cargo" data-custom-role>
                                                </div>
                                                <div class="col-md-6" data-admin-email>
                                                    <label class="form-label" for="member-admin-email-<?php echo htmlspecialchars($modalId); ?>">Correo institucional</label>
                                                    <input type="email" class="form-control" id="member-admin-email-<?php echo htmlspecialchars($modalId); ?>" name="admin_email" value="<?php echo htmlspecialchars($member['admin_email'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label" for="member-document-<?php echo htmlspecialchars($modalId); ?>">Documento / ID</label>
                                                    <input type="text" class="form-control" id="member-document-<?php echo htmlspecialchars($modalId); ?>" name="document_number" value="<?php echo htmlspecialchars($member['document_number'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label" for="member-phone-<?php echo htmlspecialchars($modalId); ?>">Teléfono</label>
                                                    <input type="text" class="form-control" id="member-phone-<?php echo htmlspecialchars($modalId); ?>" name="phone" value="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label" for="member-email-<?php echo htmlspecialchars($modalId); ?>">Correo</label>
                                                    <input type="email" class="form-control" id="member-email-<?php echo htmlspecialchars($modalId); ?>" name="email" value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" for="member-hire-<?php echo htmlspecialchars($modalId); ?>">Fecha de ingreso</label>
                                                    <input type="date" class="form-control" id="member-hire-<?php echo htmlspecialchars($modalId); ?>" name="hire_date" value="<?php echo htmlspecialchars($member['hire_date'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" for="member-salary-<?php echo htmlspecialchars($modalId); ?>">Salario (COP)</label>
                                                    <input type="text" class="form-control" id="member-salary-<?php echo htmlspecialchars($modalId); ?>" name="salary" value="<?php echo htmlspecialchars($member['salary'] !== null ? number_format((float)$member['salary'], 0, ',', '.') : ''); ?>">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label" for="member-notes-<?php echo htmlspecialchars($modalId); ?>">Notas internas</label>
                                                    <textarea class="form-control" id="member-notes-<?php echo htmlspecialchars($modalId); ?>" name="notes" rows="2"><?php echo htmlspecialchars($member['notes'] ?? ''); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                            <button type="submit" class="btn btn-primary">Guardar cambios</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="javascript/staff.js" defer></script>
</body>
</html>
