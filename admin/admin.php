<?php
include '../config.php';
session_start();

require_once __DIR__ . '/includes/admin_bootstrap.php';

// Verificación de sesión admin
$adminmail = $_SESSION['adminmail'] ?? '';
if (!$adminmail) {
  header("location: ../index.php");
  exit;
}

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_refresh_session($conn, $adminmail);

$employee = admin_current_employee();
$avatarPath = !empty($employee['avatar']) ? '../' . ltrim($employee['avatar'], '/') : '../image/Profile.png';
$hasRecords = admin_has_records_access();
$recordsDefaultView = admin_first_records_view();

$framesPermissions = [
  0 => admin_user_can('dashboard'),
  1 => admin_user_can('reservas'),
  2 => admin_user_can('reservas'),
  3 => admin_user_can('pagos'),
  4 => admin_user_can('habitaciones'),
  5 => admin_user_can('personal'),
  6 => $hasRecords,
  7 => admin_user_can('estado_habitaciones'),
];

$initialFrame = 0;
foreach ($framesPermissions as $idx => $allowed) {
  if ($allowed) {
    $initialFrame = $idx;
    break;
  }
}
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Andino - Admin</title>

    <!-- admin.css (ya con paleta dorada) -->
    <link rel="stylesheet" href="./css/admin.css">

    <!-- loading bar -->
    <script src="https://cdn.jsdelivr.net/npm/pace-js@latest/pace.min.js"></script>

    <!-- fontawesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" 
          integrity="sha512-xh6O/CkQoPOWDdYTDqeRdPCVd1SpvCA9XXcUnZS2FmJNp1coAFzvtCN9BmamE+4aHK8yyUHUSCcJHgXloTyT2A==" 
          crossorigin="anonymous" referrerpolicy="no-referrer"/>
</head>

<body>
    <!-- mobile view -->
    <div id="mobileview">
        <h5>El panel de administración no está disponible en dispositivos móviles</h5>
    </div>
  
    <!-- nav bar -->
    <nav class="uppernav">
        <div class="logo">
            <img class="HotelAndino" src="../image/LogoAndino.png" alt="logo">
            <p>Hotel Andino</p>
        </div>
        <div class="profile-menu">
            <button class="profile-trigger" type="button" aria-haspopup="true" aria-expanded="false">
                <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Perfil" class="profile-avatar">
            </button>
            <div class="profile-dropdown" role="menu">
                <div class="profile-summary">
                    <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Avatar" class="profile-avatar">
                    <div class="profile-text">
                        <div class="profile-name"><?php echo htmlspecialchars($employee['name'] ?: 'Administrador'); ?></div>
                        <?php if (!empty($employee['role'])): ?>
                            <div class="profile-role"><?php echo htmlspecialchars($employee['role']); ?></div>
                        <?php endif; ?>
                        <div class="profile-email"><?php echo htmlspecialchars($employee['email']); ?></div>
                    </div>
                </div>
                <div class="profile-actions">
                    <a class="dropdown-item" href="./profile.php"><i class="fa-solid fa-user-pen"></i> Editar perfil</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Cerrar sesión</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- side nav -->
    <nav class="sidenav">
        <ul>
            <?php if (admin_user_can('dashboard')): ?>
                <li class="pagebtn <?php echo $initialFrame === 0 ? 'active' : ''; ?>" data-frame="0" data-src="./dashboard.php"><i class="fa-solid fa-chart-line"></i>&nbsp;&nbsp; Panel</li>
            <?php endif; ?>
            <?php if (admin_user_can('reservas')): ?>
                <li class="pagebtn <?php echo $initialFrame === 1 ? 'active' : ''; ?>" data-frame="1" data-src="./roombook.php"><i class="fa-solid fa-bed"></i>&nbsp;&nbsp; Reservas</li>
                <li class="pagebtn <?php echo $initialFrame === 2 ? 'active' : ''; ?>" data-frame="2" data-src="./guest-requests.php"><i class="fa-solid fa-bell-concierge"></i>&nbsp;&nbsp; Solicitudes</li>
            <?php endif; ?>
            <?php if (admin_user_can('pagos')): ?>
                <li class="pagebtn <?php echo $initialFrame === 3 ? 'active' : ''; ?>" data-frame="3" data-src="./payment.php"><i class="fa-solid fa-money-bill-wave"></i>&nbsp;&nbsp; Pagos</li>
            <?php endif; ?>
            <?php if (admin_user_can('habitaciones')): ?>
                <li class="pagebtn <?php echo $initialFrame === 4 ? 'active' : ''; ?>" data-frame="4" data-src="./room.php"><i class="fa-solid fa-house"></i>&nbsp;&nbsp; Habitaciones</li>
            <?php endif; ?>
            <?php if (admin_user_can('personal')): ?>
                <li class="pagebtn <?php echo $initialFrame === 5 ? 'active' : ''; ?>" data-frame="5" data-src="./staff.php"><i class="fa-solid fa-user-group"></i>&nbsp;&nbsp; Personal</li>
            <?php endif; ?>
            <?php if ($hasRecords): ?>
                <li class="has-submenu">
                    <div class="submenu-trigger <?php echo $initialFrame === 6 ? 'active' : ''; ?>" tabindex="0"><i class="fa-solid fa-clipboard-list"></i>&nbsp;&nbsp; Registros</div>
                    <ul class="submenu">
                        <?php if (admin_user_can('registros.summary')): ?>
                            <li class="pagebtn <?php echo ($initialFrame === 6 && $recordsDefaultView === 'summary') ? 'active' : ''; ?>" data-frame="6" data-src="./records.php?view=summary"><i class="fa-solid fa-chart-line"></i>&nbsp;&nbsp; Resumen rápido</li>
                        <?php endif; ?>
                        <?php if (admin_user_can('registros.rooms')): ?>
                            <li class="pagebtn <?php echo ($initialFrame === 6 && $recordsDefaultView === 'rooms') ? 'active' : ''; ?>" data-frame="6" data-src="./records.php?view=rooms"><i class="fa-solid fa-door-open"></i>&nbsp;&nbsp; Registrar habitación</li>
                        <?php endif; ?>
                        <?php if (admin_user_can('registros.room-types')): ?>
                            <li class="pagebtn <?php echo ($initialFrame === 6 && $recordsDefaultView === 'room-types') ? 'active' : ''; ?>" data-frame="6" data-src="./records.php?view=room-types"><i class="fa-solid fa-layer-group"></i>&nbsp;&nbsp; Registrar tipo de habitación</li>
                        <?php endif; ?>
                        <?php if (admin_user_can('registros.admins')): ?>
                            <li class="pagebtn <?php echo ($initialFrame === 6 && $recordsDefaultView === 'admin-staff') ? 'active' : ''; ?>" data-frame="6" data-src="./records.php?view=admin-staff"><i class="fa-solid fa-user-gear"></i>&nbsp;&nbsp; Registrar administrativos</li>
                        <?php endif; ?>
                        <?php if (admin_user_can('registros.products')): ?>
                            <li class="pagebtn <?php echo ($initialFrame === 6 && $recordsDefaultView === 'products') ? 'active' : ''; ?>" data-frame="6" data-src="./records.php?view=products"><i class="fa-solid fa-box"></i>&nbsp;&nbsp; Registrar producto</li>
                        <?php endif; ?>
                        <?php if (admin_user_can('registros.sales')): ?>
                            <li class="pagebtn <?php echo ($initialFrame === 6 && $recordsDefaultView === 'sales') ? 'active' : ''; ?>" data-frame="6" data-src="./records.php?view=sales"><i class="fa-solid fa-cash-register"></i>&nbsp;&nbsp; Registrar venta</li>
                        <?php endif; ?>
                        <?php if (admin_user_can('registros.pricing')): ?>
                            <li class="pagebtn <?php echo ($initialFrame === 6 && $recordsDefaultView === 'pricing') ? 'active' : ''; ?>" data-frame="6" data-src="./records.php?view=pricing"><i class="fa-solid fa-tags"></i>&nbsp;&nbsp; Tarifas de habitaciones</li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>
            <?php if (admin_user_can('estado_habitaciones')): ?>
                <li class="pagebtn <?php echo $initialFrame === 7 ? 'active' : ''; ?>" data-frame="7" data-src="./room-status.php"><i class="fa-solid fa-eye"></i>&nbsp;&nbsp; Estado</li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- main section -->
    <div class="mainscreen">
        <iframe class="frames frame1 <?php echo $initialFrame === 0 ? 'active' : ''; ?>" src="<?php echo admin_user_can('dashboard') ? './dashboard.php' : './empty.html'; ?>" frameborder="0"></iframe>
        <iframe class="frames frame2 <?php echo $initialFrame === 1 ? 'active' : ''; ?>" src="<?php echo admin_user_can('reservas') ? './roombook.php' : './empty.html'; ?>" frameborder="0"></iframe>
        <iframe class="frames frame3 <?php echo $initialFrame === 2 ? 'active' : ''; ?>" src="<?php echo admin_user_can('reservas') ? './guest-requests.php' : './empty.html'; ?>" frameborder="0"></iframe>
        <iframe class="frames frame4 <?php echo $initialFrame === 3 ? 'active' : ''; ?>" src="<?php echo admin_user_can('pagos') ? './payment.php' : './empty.html'; ?>" frameborder="0"></iframe>
        <iframe class="frames frame5 <?php echo $initialFrame === 4 ? 'active' : ''; ?>" src="<?php echo admin_user_can('habitaciones') ? './room.php' : './empty.html'; ?>" frameborder="0"></iframe>
        <iframe class="frames frame6 <?php echo $initialFrame === 5 ? 'active' : ''; ?>" src="<?php echo admin_user_can('personal') ? './staff.php' : './empty.html'; ?>" frameborder="0"></iframe>
        <iframe class="frames frame7 <?php echo $initialFrame === 6 ? 'active' : ''; ?>" src="<?php echo $hasRecords ? './records.php?view=' . urlencode($recordsDefaultView) : './empty.html'; ?>" frameborder="0"></iframe>
        <iframe class="frames frame8 <?php echo $initialFrame === 7 ? 'active' : ''; ?>" src="<?php echo admin_user_can('estado_habitaciones') ? './room-status.php' : './empty.html'; ?>" frameborder="0"></iframe>
    </div>

    <script src="./javascript/script.js"></script>
</body>
</html>
