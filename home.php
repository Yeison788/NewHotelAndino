<?php
include 'config.php';
require_once __DIR__ . '/includes/guest_portal.php';
session_start();

/* ===========
   Autenticación
   =========== */
if (empty($_SESSION['usermail'])) {
  header("Location: index.php");
  exit;
}

$usermail = $_SESSION['usermail'];

guest_portal_ensure_schema($conn);

$currentUser = guest_portal_fetch_user($conn, $usermail);
if (!$currentUser) {
  header("Location: logout.php");
  exit;
}

$userId = (int) ($currentUser['UserID'] ?? 0);
$profileName = $currentUser['Username'] ?: $usermail;
$profileInitial = strtoupper(substr($profileName, 0, 1));
$requestTypes = guest_portal_request_types();

$reservations = $userId ? guest_portal_reservations_for_user($conn, $userId) : [];
$activeReservation = $userId ? guest_portal_active_reservation($conn, $userId) : null;
$userRequests = $userId ? guest_portal_requests_for_user($conn, $userId) : [];
$openRequestsCount = count(array_filter(
  $userRequests,
  static fn($item) => in_array($item['status'] ?? '', ['pendiente', 'en_proceso'], true)
));

$requestsByReservation = [];
foreach ($userRequests as $requestRow) {
  $rid = (int) ($requestRow['roombook_id'] ?? 0);
  if (!isset($requestsByReservation[$rid])) {
    $requestsByReservation[$rid] = [];
  }
  $requestsByReservation[$rid][] = $requestRow;
}

$confirmedReservations = array_filter(
  $reservations,
  static fn(array $row): bool => in_array($row['stat'] ?? '', ['Confirm', 'Ocupado', 'CheckIn'], true)
);
$confirmedReservationIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $confirmedReservations);

if ($userId && isset($_POST['create_service_request'])) {
  $reservationId = (int) ($_POST['reservation_id'] ?? 0);
  $requestType = $_POST['request_type'] ?? '';
  $requestDetail = trim($_POST['request_detail'] ?? '');

  $isValidReservation = in_array($reservationId, $confirmedReservationIds, true);
  $isValidType = isset($requestTypes[$requestType]);

  if ($isValidReservation && $isValidType) {
    $requestId = guest_portal_create_request($conn, $reservationId, $userId, $requestType, $requestDetail);

    if ($requestId) {
      $selectedReservation = null;
      foreach ($reservations as $row) {
        if ((int) ($row['id'] ?? 0) === $reservationId) {
          $selectedReservation = $row;
          break;
        }
      }
      $message = sprintf('Nueva solicitud de %s para %s', $profileName, strtolower($requestTypes[$requestType] ?? 'servicio'));
      guest_portal_record_notification($conn, 'admin', 'Solicitud de habitación', $message, 'admin/guest-requests.php', $reservationId, $requestId);
      guest_portal_record_notification($conn, 'recepcion', 'Solicitud de habitación', $message, 'admin/guest-requests.php', $reservationId, $requestId);

      $_SESSION['guest_request_flash'] = [
        'type' => 'success',
        'text' => 'Tu solicitud fue enviada al equipo del hotel. Te contactaremos pronto.',
      ];
    } else {
      $_SESSION['guest_request_flash'] = [
        'type' => 'error',
        'text' => 'No fue posible registrar tu solicitud. Intenta nuevamente.',
      ];
    }
  } else {
    $_SESSION['guest_request_flash'] = [
      'type' => 'error',
      'text' => 'Selecciona una reserva y un servicio válidos para continuar.',
    ];
  }

  header('Location: home.php');
  exit;
}

$guestRequestFlash = $_SESSION['guest_request_flash'] ?? null;
unset($_SESSION['guest_request_flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="./css/home.css">
    <title>Hotel Andino</title>
    <!-- boot -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
    <!-- fontawesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" integrity="sha512-xh6O/CkQoPOWDdYTDqeRdPCVd1SpvCA9XXcUnZS2FmJNp1coAFzvtCN9BmamE+4aHK8yyUHUSCcJHgXloTyT2A==" crossorigin="anonymous" referrerpolicy="no-referrer"/>
    <!-- sweet alert -->
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    <link rel="stylesheet" href="./admin/css/roombook.css">

    <!-- ======= NUEVO: estilos FAB + popup chat en esquina ======= -->
    <style>
      #guestdetailpanel{ display:none; }
      #guestdetailpanel .middle{ height: 450px; }

      /* ==== FAB Chatbot ==== */
      .chat-fab{
        position: fixed;
        right: calc(20px + env(safe-area-inset-right));
        bottom: calc(20px + env(safe-area-inset-bottom));
        width: 56px; height: 56px;
        border-radius: 50%;
        border: 0;
        background: var(--gold, #d4af37);
        color: #fff;
        box-shadow: 0 10px 24px rgba(0,0,0,.18), 0 6px 12px rgba(0,0,0,.12);
        display: inline-flex; align-items: center; justify-content: center;
        cursor: pointer;
        z-index: 9999;
        transition: transform .15s ease, box-shadow .2s ease, background .2s ease;
      }
      .chat-fab:hover{ transform: translateY(-1px); box-shadow: 0 14px 30px rgba(0,0,0,.22), 0 8px 16px rgba(0,0,0,.14); }
      .chat-fab:active{ transform: translateY(0); }

      .chat-fab .icon-chat{ display: block; }
      .chat-fab .icon-close{ display: none; }
      .chat-fab[data-open="true"] .icon-chat{ display: none; }
      .chat-fab[data-open="true"] .icon-close{ display: block; }

      /* Pulso sutil cuando está cerrado */
      .chat-fab:not([data-open="true"])::after{
        content: "";
        position: absolute; inset: 0;
        border-radius: 50%;
        animation: fabPulse 2.2s ease-out infinite;
        box-shadow: 0 0 0 0 rgba(212,175,55,.45);
      }
      @keyframes fabPulse{
        0% { box-shadow: 0 0 0 0 rgba(212,175,55,.45); }
        70%{ box-shadow: 0 0 0 14px rgba(212,175,55,0); }
        100%{ box-shadow: 0 0 0 0 rgba(212,175,55,0); }
      }

      /* ==== Popup del chatbot anclado a esquina ==== */
      .chatbot-popup{
        position: fixed;
        right: calc(92px + env(safe-area-inset-right)); /* deja espacio al FAB */
        bottom: calc(20px + env(safe-area-inset-bottom));
        max-width: 360px;
        width: 92vw;
        max-height: 70vh;
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 18px 48px rgba(0,0,0,.22), 0 10px 24px rgba(0,0,0,.12);
        overflow: hidden;
        z-index: 9998;
      }
      .chat-header{
        background: var(--gold, #d4af37);
        color: #fff;
        padding: 10px 12px;
        display:flex; align-items:center; justify-content:space-between;
        font-weight:600;
      }
      .chat-header button{
        border:0; background:transparent; color:#fff; font-size:18px; line-height:1; cursor:pointer;
      }
      .chat-box{
        height: 340px; overflow:auto; padding:12px;
        background: #fafafa;
      }
      .chat-input{
        display:flex; gap:8px; padding:10px; background:#fff; border-top:1px solid #eee;
      }
      .chat-input input{ flex:1; border:1px solid #e5e5e5; border-radius:10px; padding:10px; outline:none;}
      .chat-input button{ border:0; border-radius:10px; padding:10px 14px; background:var(--gold, #d4af37); color:#fff; cursor:pointer; }

      .user-message, .bot-message{
        max-width: 80%;
        margin: 6px 0; padding: 10px 12px; border-radius: 12px;
        word-break: break-word; line-height: 1.25;
      }
      .user-message{
        margin-left: auto; background:#efefef; color:#1f1f1f; border-top-right-radius: 4px;
      }
      .bot-message{
        margin-right: auto; background:#ffeebe; color:#1f1f1f; border-top-left-radius: 4px;
      }

      @media (max-width: 600px){
        .chatbot-popup{
          right: calc(20px + env(safe-area-inset-right));
          width: 94vw;
          max-height: 78vh;
        }
      }
    </style>
</head>

<body>
  <nav>
    <div class="logo">
      <img class="HotelAndino" src="./image/LogoAndino.png" alt="logo">
      <p>Hotel Andino</p>
    </div>
    <ul>
      <li><a href="#firstsection">Inicio</a></li>
      <li><a href="#secondsection">Habitaciones</a></li>
      <li><a href="#thirdsection">Servicios</a></li>
      <!-- EDITADO: Se elimina el enlace que abría el chatbot desde la navbar -->
      <li><a href="turismo.php">Turismo</a></li>
      <li><a href="#contactus">Contáctanos</a></li>
      <li class="nav-profile">
        <button class="nav-profile-trigger" type="button" aria-haspopup="true" aria-expanded="false">
          <span class="nav-profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($profileInitial); ?></span>
          <span class="visually-hidden">Abrir menú de perfil</span>
        </button>
        <div class="nav-profile-dropdown" role="menu">
          <div class="nav-profile-summary">
            <span class="nav-profile-avatar nav-profile-avatar--lg" aria-hidden="true"><?php echo htmlspecialchars($profileInitial); ?></span>
            <div class="nav-profile-text">
              <strong><?php echo htmlspecialchars($profileName); ?></strong>
              <span><?php echo htmlspecialchars($usermail); ?></span>
            </div>
          </div>
          <div class="nav-profile-actions">
            <a class="nav-profile-link" href="#guest-experience"><i class="fa-solid fa-door-open"></i> Mi habitación</a>
            <a class="nav-profile-link" href="onboarding.php"><i class="fa-solid fa-sliders"></i> Preferencias</a>
            <div class="nav-profile-divider"></div>
            <a class="nav-profile-link nav-profile-link--logout" href="./logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Cerrar sesión</a>
          </div>
        </div>
      </li>
    </ul>
  </nav>

  <?php if (!empty($guestRequestFlash)): ?>
    <script>
      swal({
        title: <?php echo json_encode($guestRequestFlash['type'] === 'success' ? '¡Listo!' : 'Aviso'); ?>,
        text: <?php echo json_encode($guestRequestFlash['text']); ?>,
        icon: <?php echo json_encode($guestRequestFlash['type'] === 'success' ? 'success' : 'error'); ?>
      });
    </script>
  <?php endif; ?>

  <section id="firstsection" class="carousel slide carousel_section" data-bs-ride="carousel">
    <div class="carousel-inner">
        <div class="carousel-item active">
            <img class="carousel-image" src="./image/hotel1.jpg" alt="Hotel 1">
        </div>
        <div class="carousel-item">
            <img class="carousel-image" src="./image/hotel2.jpg" alt="Hotel 2">
        </div>
        <div class="carousel-item">
            <img class="carousel-image" src="./image/hotel3.jpg" alt="Hotel 3">
        </div>
        <div class="carousel-item">
            <img class="carousel-image" src="./image/hotel4.jpg" alt="Hotel 4">
        </div>

        <div class="welcomeline">
          <h1 class="welcometag">Bienvenido al cielo en la tierra</h1>
        </div>

      <!-- bookbox -->
      <div id="guestdetailpanel">
        <form action="" method="POST" class="guestdetailpanelform">
            <div class="head">
                <h3>Reserva</h3>
                <i class="fa-solid fa-circle-xmark" onclick="closebox()"></i>
            </div>
            <div class="middle">
                <div class="guestinfo">
                    <h4 class="card-title"><i class="fa-solid fa-user"></i> Información del huésped</h4>
                    <input type="text" name="Name" placeholder="Nombre completo" required>
                    <input type="email" name="Email" placeholder="Correo electrónico" required>

                    <?php
                    $countries = array("Afghanistan", "Albania", "Algeria", "American Samoa", "Andorra", "Angola", "Anguilla", "Antarctica", "Antigua and Barbuda", "Argentina", "Armenia", "Aruba", "Australia", "Austria", "Azerbaijan", "Bahamas", "Bahrain", "Bangladesh", "Barbados", "Belarus", "Belgium", "Belize", "Benin", "Bermuda", "Bhutan", "Bolivia", "Bosnia and Herzegowina", "Botswana", "Bouvet Island", "Brazil", "British Indian Ocean Territory", "Brunei Darussalam", "Bulgaria", "Burkina Faso", "Burundi", "Cambodia", "Cameroon", "Canada", "Cape Verde", "Cayman Islands", "Central African Republic", "Chad", "Chile", "China", "Christmas Island", "Cocos (Keeling) Islands", "Colombia", "Comoros", "Congo", "Congo, the Democratic Republic of the", "Cook Islands", "Costa Rica", "Cote d'Ivoire", "Croatia (Hrvatska)", "Cuba", "Cyprus", "Czech Republic", "Denmark", "Djibouti", "Dominica", "Dominican Republic", "East Timor", "Ecuador", "Egypt", "El Salvador", "Equatorial Guinea", "Eritrea", "Estonia", "Ethiopia", "Falkland Islands (Malvinas)", "Faroe Islands", "Fiji", "Finland", "France", "France Metropolitan", "French Guiana", "French Polynesia", "French Southern Territories", "Gabon", "Gambia", "Georgia", "Germany", "Ghana", "Gibraltar", "Greece", "Greenland", "Grenada", "Guadeloupe", "Guam", "Guatemala", "Guinea", "Guinea-Bissau", "Guyana", "Haiti", "Heard and Mc Donald Islands", "Holy See (Vatican City State)", "Honduras", "Hong Kong", "Hungary", "Iceland", "India", "Indonesia", "Iran (Islamic Republic of)", "Iraq", "Ireland", "Israel", "Italy", "Jamaica", "Japan", "Jordan", "Kazakhstan", "Kenya", "Kiribati", "Korea, Democratic People's Republic of", "Korea, Republic of", "Kuwait", "Kyrgyzstan", "Lao, People's Democratic Republic", "Latvia", "Lebanon", "Lesotho", "Liberia", "Libyan Arab Jamahiriya", "Liechtenstein", "Lithuania", "Luxembourg", "Macau", "Macedonia, The Former Yugoslav Republic of", "Madagascar", "Malawi", "Malaysia", "Maldives", "Mali", "Malta", "Marshall Islands", "Martinique", "Mauritania", "Mauritius", "Mayotte", "Mexico", "Micronesia, Federated States of", "Moldova, Republic of", "Monaco", "Mongolia", "Montserrat", "Morocco", "Mozambique", "Myanmar", "Namibia", "Nauru", "Nepal", "Netherlands", "Netherlands Antilles", "New Caledonia", "New Zealand", "Nicaragua", "Niger", "Nigeria", "Niue", "Norfolk Island", "Northern Mariana Islands", "Norway", "Oman", "Pakistan", "Palau", "Panama", "Papua New Guinea", "Paraguay", "Peru", "Philippines", "Pitcairn", "Poland", "Portugal", "Puerto Rico", "Qatar", "Reunion", "Romania", "Russian Federation", "Rwanda", "Saint Kitts and Nevis", "Saint Lucia", "Saint Vincent and the Grenadines", "Samoa", "San Marino", "Sao Tome and Principe", "Saudi Arabia", "Senegal", "Seychelles", "Sierra Leone", "Singapore", "Slovakia (Slovak Republic)", "Slovenia", "Solomon Islands", "Somalia", "South Africa", "South Georgia and the South Sandwich Islands", "Spain", "Sri Lanka", "St. Helena", "St. Pierre and Miquelon", "Sudan", "Suriname", "Svalbard and Jan Mayen Islands", "Swaziland", "Sweden", "Switzerland", "Syrian Arab Republic", "Taiwan, Province of China", "Tajikistan", "Tanzania, United Republic of", "Thailand", "Togo", "Tokelau", "Tonga", "Trinidad and Tobago", "Tunisia", "Turkey", "Turkmenistan", "Turks and Caicos Islands", "Tuvalu", "Uganda", "Ukraine", "United Arab Emirates", "United Kingdom", "United States", "United States Minor Outlying Islands", "Uruguay", "Uzbekistan", "Vanuatu", "Venezuela", "Vietnam", "Virgin Islands (British)", "Virgin Islands (U.S.)", "Wallis and Futuna Islands", "Western Sahara", "Yemen", "Yugoslavia", "Zambia", "Zimbabwe");
                    ?>

                    <select name="Country" class="selectinput" required>
                        <option value="" selected disabled hidden>Selecciona tu país</option>
                        <?php foreach($countries as $value): ?>
                          <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>
                          </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="Phone" placeholder="Número de teléfono">
                </div>

                <div class="line"></div>

                <div class="reservationinfo">
                    <h4 class="card-title"><i class="fa-solid fa-bed"></i> Información de la reserva</h4>
                    <select name="RoomType" class="selectinput" required>
                        <option value="" selected disabled hidden>Tipo de habitación</option>
                        <option value="Habitación Doble">Habitación Doble</option>
                        <option value="Habitación Suite">Habitación Suite</option>
                        <option value="Habitación Múltiple">Habitación Múltiple</option>
                        <option value="Habitación Sencilla">Habitación Sencilla</option>
                    </select>
                    <select name="Bed" class="selectinput" required>
                        <option value="" selected disabled hidden>Capacidad</option>
                        <option value="1 cliente">1 cliente</option>
                        <option value="2 clientes">2 clientes</option>
                        <option value="3 clientes">3 clientes</option>
                        <option value="4 clientes">4 clientes</option>
                        <option value="None">Sin adicional</option>
                    </select>
                    <select name="NoofRoom" class="selectinput" required>
                        <option value="" selected disabled hidden>Número de habitaciones</option>
                        <option value="1">1</option>
                    </select>
                    <select name="Meal" class="selectinput" required>
                        <option value="" selected disabled hidden>Comidas</option>
                        <option value="Room only">Solo habitación</option>
                        <option value="Breakfast">Desayuno</option>
                        <option value="Half Board">Desayuno y Cena</option>
                        <option value="Full Board">Comidas Completas</option>
                    </select>
                    <div class="datesection">
                        <span>
                            <label for="cin">Llegada</label>
                            <input id="cin" name="cin" type="date" required>
                        </span>
                        <span>
                            <label for="cout">Salida</label>
                            <input id="cout" name="cout" type="date" required>
                        </span>
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-success" name="guestdetailsubmit">Enviar</button>
            </div>
        </form>

        <!-- ==== room book php ====-->
        <?php
            if (isset($_POST['guestdetailsubmit'])) {
                $Name     = trim($_POST['Name'] ?? '');
                $Email    = trim($_POST['Email'] ?? '');
                $Country  = trim($_POST['Country'] ?? '');
                $Phone    = trim($_POST['Phone'] ?? '');
                $RoomType = trim($_POST['RoomType'] ?? '');
                $Bed      = trim($_POST['Bed'] ?? '');
                $NoofRoom = (int)($_POST['NoofRoom'] ?? 0);
                $Meal     = trim($_POST['Meal'] ?? '');
                $cin      = $_POST['cin'] ?? '';
                $cout     = $_POST['cout'] ?? '';

                if ($Name === "" || $Email === "" || $Country === "" || $RoomType === "" || $Bed === "" || $NoofRoom < 1 || $Meal === "" || $cin === "" || $cout === "") {
                    echo "<script>swal({ title: 'Completa los datos correctamente', icon: 'error' });</script>";
                } else {
                    $d1 = strtotime($cin);
                    $d2 = strtotime($cout);
                    if ($d1 === false || $d2 === false || $d2 <= $d1) {
                        echo "<script>swal({ title: 'Rango de fechas inválido', icon: 'error' });</script>";
                    } else {
                        $nodays = (int)round(($d2 - $d1) / 86400); // días
                        $sta = "NotConfirm";

                        $sql = "INSERT INTO roombook"
                                . "(user_id, Name, Email, Country, Phone, RoomType, Bed, NoofRoom, Meal, cin, cout, stat, nodays)"
                                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                        if ($stmt = mysqli_prepare($conn, $sql)) {
                            mysqli_stmt_bind_param(
                                $stmt,
                                "issssssissssi",
                                $userId,
                                $Name,
                                $Email,
                                $Country,
                                $Phone,
                                $RoomType,
                                $Bed,
                                $NoofRoom,
                                $Meal,
                                $cin,
                                $cout,
                                $sta,
                                $nodays
                            );
                            $ok = mysqli_stmt_execute($stmt);
                            $reservationId = $ok ? mysqli_insert_id($conn) : 0;
                            mysqli_stmt_close($stmt);

                            if ($ok && $reservationId > 0) {
                                $notifMessage = sprintf('Nueva reserva pendiente de %s (%s)', $Name, $Email);
                                guest_portal_record_notification($conn, 'admin', 'Reserva solicitada', $notifMessage, 'admin/roombook.php', $reservationId, null);
                                guest_portal_record_notification($conn, 'recepcion', 'Reserva solicitada', $notifMessage, 'admin/roombook.php', $reservationId, null);

                                echo "<script>"
                                    . "swal({ title: 'Reserva exitosa', text: 'Nuestro equipo confirmará tu habitación muy pronto.', icon: 'success' });"
                                . "</script>";
                            } else {
                                echo "<script>swal({ title: 'Algo salió mal al guardar', icon: 'error' });</script>";
                            }
                        } else {
                            echo "<script>swal({ title: 'Error preparando consulta', icon: 'error' });</script>";
                        }
                    }
                }
            }
            ?>
          </div>

    </div>
  </section>

  <?php
    $activeReservationRequests = [];
    $minibarTotal = 0.0;
    if ($activeReservation) {
      $activeReservationId = (int) ($activeReservation['id'] ?? 0);
      $activeReservationRequests = $requestsByReservation[$activeReservationId] ?? [];
      foreach ($activeReservationRequests as $req) {
        if (($req['request_type'] ?? '') === 'minibar' && $req['charge_amount'] !== null) {
          $minibarTotal += (float) $req['charge_amount'];
        }
      }
    }
  ?>

  <section id="guest-experience" class="guest-experience">
    <div class="guest-experience__layout">
      <article class="guest-card guest-card--profile">
        <h2 class="guest-card__title">Hola, <?php echo htmlspecialchars($profileName); ?></h2>
        <p class="guest-card__subtitle">Tu correo registrado es <strong><?php echo htmlspecialchars($usermail); ?></strong></p>
        <ul class="guest-card__stats">
          <li>
            <span class="label">Reservas totales</span>
            <span class="value"><?php echo count($reservations); ?></span>
          </li>
          <li>
            <span class="label">Reservas confirmadas</span>
            <span class="value"><?php echo count($confirmedReservationIds); ?></span>
          </li>
          <li>
            <span class="label">Solicitudes abiertas</span>
            <span class="value"><?php echo $openRequestsCount; ?></span>
          </li>
        </ul>
      </article>

      <article class="guest-card guest-card--room">
        <?php if ($activeReservation): ?>
          <?php
            $activeId = (int) ($activeReservation['id'] ?? 0);
            $activeStatus = $activeReservation['stat'] ?? '';
            $statusLabel = match ($activeStatus) {
              'Confirm'              => 'Confirmada',
              'Ocupado', 'CheckIn'   => 'En curso',
              'NotConfirm'           => 'Pendiente',
              default                => ucfirst(strtolower($activeStatus)),
            };
            $checkIn  = !empty($activeReservation['cin']) ? date('d/m/Y', strtotime($activeReservation['cin'])) : '—';
            $checkOut = !empty($activeReservation['cout']) ? date('d/m/Y', strtotime($activeReservation['cout'])) : '—';
            $roomNumber = $activeReservation['room_number'] ?? null;
            $roomType   = $activeReservation['RoomType'] ?? ($activeReservation['room_type_name'] ?? 'Habitación');
            $activeRequestsCount = count($activeReservationRequests);
          ?>
          <header class="guest-room__header">
            <div>
              <h3 class="guest-card__title">Tu habitación</h3>
              <p class="guest-card__subtitle">
                <?php echo htmlspecialchars($roomType); ?>
                <?php if ($roomNumber): ?> · #<?php echo htmlspecialchars($roomNumber); ?><?php endif; ?>
              </p>
            </div>
            <span class="status-badge status-<?php echo htmlspecialchars(strtolower($activeStatus)); ?>">
              <?php echo htmlspecialchars($statusLabel); ?>
            </span>
          </header>
          <div class="guest-room__grid">
            <div>
              <span class="label">Check-in</span>
              <span class="value"><?php echo htmlspecialchars($checkIn); ?></span>
            </div>
            <div>
              <span class="label">Check-out</span>
              <span class="value"><?php echo htmlspecialchars($checkOut); ?></span>
            </div>
            <div>
              <span class="label">Solicitudes registradas</span>
              <span class="value"><?php echo $activeRequestsCount; ?></span>
            </div>
            <div>
              <span class="label">Consumo minibar</span>
              <span class="value"><?php echo $minibarTotal > 0 ? 'COP ' . number_format($minibarTotal, 0, ',', '.') : 'Sin consumos'; ?></span>
            </div>
          </div>

          <div class="guest-request-form">
            <h4>Solicita asistencia</h4>
            <form method="post" class="guest-request-form__form">
              <input type="hidden" name="reservation_id" value="<?php echo $activeId; ?>">
              <label for="request_type" class="form-label">¿Qué necesitas?</label>
              <select class="form-select" id="request_type" name="request_type" required>
                <option value="" disabled selected>Selecciona una opción</option>
                <?php foreach ($requestTypes as $typeKey => $typeLabel): ?>
                  <option value="<?php echo htmlspecialchars($typeKey); ?>"><?php echo htmlspecialchars($typeLabel); ?></option>
                <?php endforeach; ?>
              </select>
              <label for="request_detail" class="form-label">Detalles adicionales</label>
              <textarea id="request_detail" name="request_detail" rows="3" class="form-control" placeholder="Cuéntanos si necesitas algo específico (opcional)"></textarea>
              <button class="btn btn-primary" type="submit" name="create_service_request" value="1">
                <i class="fa-solid fa-paper-plane"></i> Enviar solicitud
              </button>
            </form>
          </div>

          <div class="guest-requests">
            <h4>Historial de solicitudes</h4>
            <?php if (!empty($activeReservationRequests)): ?>
              <ul class="guest-requests__list">
                <?php foreach ($activeReservationRequests as $request): ?>
                  <?php
                    $badgeStatus = guest_portal_format_status($request['status'] ?? '');
                    $badgeClass = 'status-' . preg_replace('/[^a-z_\-]/i', '', strtolower($request['status'] ?? 'pendiente'));
                    $updatedAt = $request['updated_at'] ?? $request['created_at'] ?? '';
                    $updatedLabel = $updatedAt ? date('d/m/Y H:i', strtotime($updatedAt)) : '—';
                    $typeLabel = $requestTypes[$request['request_type']] ?? ucfirst($request['request_type'] ?? 'Servicio');
                  ?>
                  <li>
                    <div class="guest-requests__header">
                      <span class="guest-requests__type"><?php echo htmlspecialchars($typeLabel); ?></span>
                      <span class="status-badge <?php echo htmlspecialchars($badgeClass); ?>"><?php echo htmlspecialchars($badgeStatus); ?></span>
                    </div>
                    <?php if (!empty($request['details'])): ?>
                      <p class="guest-requests__details"><?php echo nl2br(htmlspecialchars($request['details'])); ?></p>
                    <?php endif; ?>
                    <div class="guest-requests__meta">
                      <span>Actualizado: <?php echo htmlspecialchars($updatedLabel); ?></span>
                      <?php if ($request['charge_amount'] !== null): ?>
                        <span>Consumo: COP <?php echo number_format((float) $request['charge_amount'], 0, ',', '.'); ?></span>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="guest-muted">Aún no registras solicitudes para esta reserva.</p>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="guest-empty">
            <h3 class="guest-card__title">Aún no tienes una habitación confirmada</h3>
            <p class="guest-card__subtitle">Cuando el equipo confirme tu reserva podrás gestionar tus solicitudes aquí.</p>
            <a href="#firstsection" class="btn btn-primary"><i class="fa-solid fa-calendar-plus"></i> Reservar ahora</a>
          </div>
        <?php endif; ?>
      </article>
    </div>

    <div class="guest-history">
      <h3 class="guest-card__title">Mis reservas</h3>
      <?php if (!empty($reservations)): ?>
        <div class="table-responsive">
          <table class="guest-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Tipo</th>
                <th>Ingreso</th>
                <th>Salida</th>
                <th>Estado</th>
                <th>Noches</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reservations as $reservation): ?>
                <?php
                  $status = $reservation['stat'] ?? '';
                  $statusText = match ($status) {
                    'Confirm'              => 'Confirmada',
                    'NotConfirm'           => 'Pendiente',
                    'Ocupado', 'CheckIn'   => 'En curso',
                    default                => ucfirst(strtolower($status)),
                  };
                  $cin = !empty($reservation['cin']) ? date('d/m/Y', strtotime($reservation['cin'])) : '—';
                  $cout = !empty($reservation['cout']) ? date('d/m/Y', strtotime($reservation['cout'])) : '—';
                ?>
                <tr>
                  <td><?php echo (int) ($reservation['id'] ?? 0); ?></td>
                  <td><?php echo htmlspecialchars($reservation['RoomType'] ?? 'Habitación'); ?></td>
                  <td><?php echo htmlspecialchars($cin); ?></td>
                  <td><?php echo htmlspecialchars($cout); ?></td>
                  <td><span class="status-badge status-<?php echo htmlspecialchars(strtolower($status)); ?>"><?php echo htmlspecialchars($statusText); ?></span></td>
                  <td><?php echo (int) ($reservation['nodays'] ?? 0); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="guest-muted">Aún no has registrado reservas. ¡Empieza creando una desde el formulario superior!</p>
      <?php endif; ?>
    </div>
  </section>

  <section id="secondsection">
    <img src="./image/homeanimatebg.svg" alt="Decoración">
    <div class="ourroom">
      <h1 class="head">≼ Nuestras habitaciones ≽</h1>
      <div class="roomselect">
        <div class="roombox">
          <div class="hotelphoto h1"></div>
          <div class="roomdata">
            <h2>Habitación Doble</h2>
            <div class="services">
              <i class="fa-solid fa-wifi"></i>
              <i class="fa-solid fa-burger"></i>
              <i class="fa-solid fa-spa"></i>
              <i class="fa-solid fa-dumbbell"></i>
              <i class="fa-solid fa-person-swimming"></i>
            </div>
            <button class="btn btn-primary bookbtn" onclick="openbookbox()">Reservar</button>
          </div>
        </div>
        <div class="roombox">
          <div class="hotelphoto h2"></div>
          <div class="roomdata">
            <h2>Habitación Suite</h2>
            <div class="services">
              <i class="fa-solid fa-wifi"></i>
              <i class="fa-solid fa-burger"></i>
              <i class="fa-solid fa-spa"></i>
              <i class="fa-solid fa-dumbbell"></i>
            </div>
            <button class="btn btn-primary bookbtn" onclick="openbookbox()">Reservar</button>
          </div>
        </div>
        <div class="roombox">
          <div class="hotelphoto h3"></div>
          <div class="roomdata">
            <h2>Habitación Múltiple</h2>
            <div class="services">
              <i class="fa-solid fa-wifi"></i>
              <i class="fa-solid fa-burger"></i>
              <i class="fa-solid fa-spa"></i>
            </div>
            <button class="btn btn-primary bookbtn" onclick="openbookbox()">Reservar</button>
          </div>
        </div>
        <div class="roombox">
          <div class="hotelphoto h4"></div>
          <div class="roomdata">
            <h2>Habitación Sencilla</h2>
            <div class="services">
              <i class="fa-solid fa-wifi"></i>
              <i class="fa-solid fa-burger"></i>
            </div>
            <button class="btn btn-primary bookbtn" onclick="openbookbox()">Reservar</button>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="thirdsection">
    <h1 class="head">≼ Servicios ≽</h1>
    <div class="facility">
      <div class="box">
        <h2>Piscina</h2>
      </div>
      <div class="box">
        <h2>Spa</h2>
      </div>
      <div class="box">
        <h2>Restaurantes 24/7</h2>
      </div>
      <div class="box">
        <h2>Gimnasio 24/7</h2>
      </div>
      <div class="box">
        <h2>Servicio de helicóptero</h2>
      </div>
    </div>
  </section>

   <section id="contactus">
    <div class="social">
      <i class="fa-brands fa-instagram"></i>
      <i class="fa-brands fa-facebook"></i>
      <i class="fa-solid fa-envelope"></i>
    </div>
  </section>

  <!-- Chatbot Popup -->
  <div id="chatbot-popup" class="chatbot-popup" style="display:none;">
    <div class="chat-header">
  <div class="bot-identity">
    <span class="avatar"><i class="fa-solid fa-robot"></i></span>
    <div class="meta">
      <strong>Asistente Andino</strong>
      <small>en línea</small>
    </div>
  </div>
  <button id="close-btn" class="icon-btn" aria-label="Cerrar" onclick="toggleChatbot()">
    <i class="fa-solid fa-chevron-down"></i>
  </button>
</div>
    <div id="chat-box" class="chat-box"></div>
    <div class="chat-input">
      <input type="text" id="user-input" placeholder="Escribe tu mensaje..." />
      <button id="send-btn">Enviar</button>
    </div>
  </div>

  <!-- ======= NUEVO: FAB para abrir/cerrar el chat ======= -->
<button id="chat-fab" class="chat-fab" aria-label="Abrir chat" title="Chatear" data-open="false">
  <i class="fa-solid fa-robot icon-robot" aria-hidden="true"></i>
</button>

</body>

<script>
  // ===== Reserva =====
  var bookbox = document.getElementById("guestdetailpanel");
  function openbookbox(){ bookbox.style.display = "flex"; }
  function closebox(){ bookbox.style.display = "none"; }

  // ===== Perfil =====
  (function(){
    const profile = document.querySelector('.nav-profile');
    if (!profile) return;
    const trigger = profile.querySelector('.nav-profile-trigger');
    const dropdown = profile.querySelector('.nav-profile-dropdown');

    const setOpen = (open) => {
      if (!dropdown) return;
      profile.classList.toggle('is-open', open);
      if (trigger) trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    if (trigger) trigger.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const isOpen = profile.classList.contains('is-open');
      setOpen(!isOpen);
    });

    document.addEventListener('click', (event) => {
      if (!profile.contains(event.target)) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    });
  })();

  // ===== Chatbot =====
  function toggleChatbot(forceClose){
    const chatbotPopup = document.getElementById('chatbot-popup');
    const fab = document.getElementById('chat-fab');
    if(!chatbotPopup || !fab) return;

    // Estado actual según display inline
    const isHidden = chatbotPopup.style.display === 'none' || chatbotPopup.style.display === '';
    const shouldOpen = (typeof forceClose === 'boolean') ? !forceClose : isHidden;

    chatbotPopup.style.display = shouldOpen ? 'block' : 'none';
    fab.setAttribute('data-open', String(shouldOpen));
    fab.setAttribute('aria-label', shouldOpen ? 'Cerrar chat' : 'Abrir chat');
    fab.title = shouldOpen ? 'Cerrar chat' : 'Chatear';

    // Mensaje de bienvenida si abre por primera vez
    if (shouldOpen && document.getElementById('chat-box').children.length === 0) {
      appendMessage('bot', "👋 Hola, soy el asistente virtual del Hotel Andino. ¿En qué puedo ayudarte hoy?");
    }

    // Persistir estado
    try{ localStorage.setItem('chatOpen', String(shouldOpen)); }catch(e){}
  }

  async function sendMessage() {
    const inputField = document.getElementById('user-input');
    const userInput = inputField.value.trim();
    if (userInput === '') return;

    appendMessage('user', userInput);
    inputField.value = '';

    try {
      const r = await fetch('send_to_groq.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: userInput })
      });

      if (r.ok) {
        const d = await r.json();
        appendMessage('bot', d.reply);
      } else {
        appendMessage('bot', "⚠️ Error en el servidor, intenta más tarde.");
      }
    } catch (e) {
      console.error(e);
      appendMessage('bot', "⚠️ Error de conexión.");
    }
  }

  function appendMessage(sender, message) {
    const chatBox = document.getElementById('chat-box');
    const div = document.createElement('div');
    div.className = sender === 'user' ? 'user-message' : 'bot-message';
    div.textContent = message;
    chatBox.appendChild(div);
    chatBox.scrollTop = chatBox.scrollHeight;
  }

  // Eventos
  document.getElementById('send-btn').addEventListener('click', sendMessage);
  document.getElementById('user-input').addEventListener('keypress', e => {
    if (e.key === 'Enter') sendMessage();
  });

  // NUEVO: click en FAB abre/cierra el chat
  document.getElementById('chat-fab').addEventListener('click', () => toggleChatbot());

  // NUEVO: restaurar estado abierto si el usuario lo dejó abierto
  document.addEventListener('DOMContentLoaded', () => {
    try{
      const saved = localStorage.getItem('chatOpen');
      if(saved === 'true'){ toggleChatbot(false); }
    }catch(e){}
  });

  // NUEVO: cerrar con ESC
  document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape'){
      const popup = document.getElementById('chatbot-popup');
      if(popup && !(popup.style.display === 'none' || popup.style.display === '')){
        toggleChatbot(true); // forzar cerrar
      }
    }
  });
</script>
</html>
