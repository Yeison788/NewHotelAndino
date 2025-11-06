<?php
session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');

$employee = admin_current_employee();
if (empty($employee['email'])) {
    header('Location: ../index.php');
    exit;
}

$messages = [];
$profileData = [
    'email'  => $employee['email'],
    'name'   => $employee['name'],
    'role'   => $employee['role'],
    'avatar' => $employee['avatar'] ?? '',
];
$currentPasswordHash = '';

$stmt = $conn->prepare('SELECT FullName, Role, AvatarPath, Emp_Password FROM emp_login WHERE Emp_Email = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $employee['email']);
    if ($stmt->execute()) {
        $stmt->bind_result($fullName, $role, $avatarPath, $passwordHash);
        if ($stmt->fetch()) {
            $profileData['name'] = $fullName ?: $profileData['name'];
            $profileData['role'] = $role ?: $profileData['role'];
            $profileData['avatar'] = $avatarPath ?: $profileData['avatar'];
            $currentPasswordHash = $passwordHash ?: '';
        }
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '') {
        $messages[] = ['type' => 'danger', 'text' => 'El nombre completo es obligatorio.'];
    }

    $shouldUpdatePassword = ($newPassword !== '' || $confirmPassword !== '');
    if ($shouldUpdatePassword) {
        if ($newPassword === '' || $confirmPassword === '') {
            $messages[] = ['type' => 'danger', 'text' => 'Debes completar y confirmar la nueva contraseña.'];
        } elseif ($newPassword !== $confirmPassword) {
            $messages[] = ['type' => 'danger', 'text' => 'Las contraseñas nuevas no coinciden.'];
        } elseif (strlen($newPassword) < 8) {
            $messages[] = ['type' => 'danger', 'text' => 'La nueva contraseña debe tener al menos 8 caracteres.'];
        } elseif ($currentPasswordHash === '' || !password_verify($currentPassword, $currentPasswordHash)) {
            $messages[] = ['type' => 'danger', 'text' => 'La contraseña actual no es correcta.'];
        }
    }

    $avatarToStore = $profileData['avatar'];
    $uploadedFile = $_FILES['avatar'] ?? null;
    $newAvatarUploaded = false;

    if ($uploadedFile && $uploadedFile['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $messages[] = ['type' => 'danger', 'text' => 'No se pudo cargar la imagen de perfil.'];
        } elseif ($uploadedFile['size'] > 3 * 1024 * 1024) {
            $messages[] = ['type' => 'danger', 'text' => 'La imagen de perfil debe pesar menos de 3 MB.'];
        } else {
            $allowedMime = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($uploadedFile['tmp_name']) ?: '';
            if (!isset($allowedMime[$mime])) {
                $messages[] = ['type' => 'danger', 'text' => 'Formato de imagen no permitido. Usa JPG, PNG o WEBP.'];
            } else {
                $uploadDir = dirname(__DIR__) . '/image/profiles';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                if (is_dir($uploadDir) && is_writable($uploadDir)) {
                    $filename = sprintf('avatar_%s.%s', bin2hex(random_bytes(8)), $allowedMime[$mime]);
                    $destination = $uploadDir . '/' . $filename;
                    if (move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
                        $avatarToStore = 'image/profiles/' . $filename;
                        $newAvatarUploaded = true;
                    } else {
                        $messages[] = ['type' => 'danger', 'text' => 'No se pudo guardar la imagen de perfil.'];
                    }
                } else {
                    $messages[] = ['type' => 'danger', 'text' => 'No hay permisos para guardar la imagen de perfil.'];
                }
            }
        }
    }

    if (empty(array_filter($messages, fn($msg) => $msg['type'] === 'danger'))) {
        $updateFields = ['FullName = ?', 'Role = ?', 'AvatarPath = ?'];
        $params = [$fullName, $role, $avatarToStore];
        $types = 'sss';

        if ($shouldUpdatePassword) {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateFields[] = 'Emp_Password = ?';
            $params[] = $hashedPassword;
            $types .= 's';
        }

        $params[] = $employee['email'];
        $types .= 's';

        $sql = 'UPDATE emp_login SET ' . implode(', ', $updateFields) . ' WHERE Emp_Email = ? LIMIT 1';
        $stmtUpdate = $conn->prepare($sql);
        if ($stmtUpdate) {
            $stmtUpdate->bind_param($types, ...$params);
            if ($stmtUpdate->execute()) {
                if ($newAvatarUploaded && $profileData['avatar'] && $profileData['avatar'] !== $avatarToStore) {
                    $oldPath = dirname(__DIR__) . '/' . $profileData['avatar'];
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }
                $profileData['name'] = $fullName;
                $profileData['role'] = $role;
                $profileData['avatar'] = $avatarToStore;
                if ($shouldUpdatePassword) {
                    $currentPasswordHash = $hashedPassword;
                }
                admin_refresh_session($conn, $employee['email']);
                $messages[] = ['type' => 'success', 'text' => 'Perfil actualizado correctamente.'];
            } else {
                $messages[] = ['type' => 'danger', 'text' => 'No se pudo actualizar el perfil.'];
            }
            $stmtUpdate->close();
        } else {
            $messages[] = ['type' => 'danger', 'text' => 'No se pudo preparar la actualización del perfil.'];
        }
    }
}

$avatarUrl = !empty($profileData['avatar']) ? '../' . ltrim($profileData['avatar'], '/') : '../image/Profile.png';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil - Hotel Andino</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <link rel="stylesheet" href="./css/admin.css">
    <style>
        body{background:var(--surface);}
        .profile-wrapper{max-width:720px;margin:0 auto;padding:40px 20px 80px;}
        .profile-card{border-radius:18px;box-shadow:var(--shadow-2);background:#fff;overflow:hidden;}
        .profile-banner{background-image:linear-gradient(120deg,var(--gold-light),var(--gold-dark));height:140px;}
        .profile-avatar-wrapper{display:flex;justify-content:center;transform:translateY(-50%);}
        .profile-avatar-wrapper img{width:120px;height:120px;border-radius:50%;object-fit:cover;border:5px solid #fff;box-shadow:0 10px 30px rgba(0,0,0,.25);}
        .profile-form{padding:0 28px 32px 28px;margin-top:-40px;}
        .form-section-title{font-weight:600;font-size:1.1rem;color:var(--text);margin-bottom:12px;}
        .avatar-input input[type="file"]{display:block;width:100%;}
    </style>
</head>
<body>
    <div class="profile-wrapper">
        <div class="mb-3">
            <a href="./admin.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Volver al panel</a>
        </div>
        <div class="profile-card">
            <div class="profile-banner"></div>
            <div class="profile-avatar-wrapper">
                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar del perfil" id="avatar-preview">
            </div>
            <div class="profile-form">
                <h1 class="h4 text-center mb-4">Configuración de perfil</h1>

                <?php foreach ($messages as $message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($message['type']); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message['text']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                    </div>
                <?php endforeach; ?>

                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <div class="col-12">
                        <label for="full_name" class="form-label">Nombre completo</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($profileData['name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="role" class="form-label">Cargo / Rol</label>
                        <input type="text" class="form-control" id="role" name="role" value="<?php echo htmlspecialchars($profileData['role']); ?>" placeholder="Ej. Recepcionista">
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Correo electrónico</label>
                        <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($profileData['email']); ?>" disabled>
                    </div>
                    <div class="col-12 avatar-input">
                        <label for="avatar" class="form-label">Foto de perfil</label>
                        <input class="form-control" type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">Formatos aceptados: JPG, PNG o WEBP. Máximo 3 MB.</div>
                    </div>

                    <div class="col-12">
                        <div class="form-section-title">Cambiar contraseña</div>
                    </div>
                    <div class="col-md-4">
                        <label for="current_password" class="form-label">Contraseña actual</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password">
                    </div>
                    <div class="col-md-4">
                        <label for="new_password" class="form-label">Nueva contraseña</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password">
                    </div>
                    <div class="col-md-4">
                        <label for="confirm_password" class="form-label">Confirmar nueva contraseña</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password">
                    </div>

                    <div class="col-12 d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary px-4">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/js/all.min.js" integrity="sha512-5m2Qqj0tSP2P2oZX6mE6xPD58Ll35H5TADaBrZEcD3xKhsR4HIX66D+QP9enZ5bY3bT5p7q0E8xmPKXWNy9Cyg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        const fileInput = document.getElementById('avatar');
        const avatarPreview = document.getElementById('avatar-preview');
        if (fileInput && avatarPreview) {
            fileInput.addEventListener('change', () => {
                const [file] = fileInput.files;
                if (file) {
                    avatarPreview.src = URL.createObjectURL(file);
                }
            });
        }
    </script>
</body>
</html>
