<?php

session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
admin_require_permission('reservas');

// fetch room data
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: roombook.php');
    exit;
}

$sql ="Select * from roombook where id = '$id'";
$re = mysqli_query($conn,$sql);
while($row=mysqli_fetch_array($re))
{
    $Name = $row['Name'];
    $Email = $row['Email'];
    $Country = $row['Country'];
    $Phone = $row['Phone'];
    $cin = $row['cin'];
    $cout = $row['cout'];
    $noofday = $row['nodays'];
    $stat = $row['stat'];
    $RoomType = $row['RoomType'];
    $Bed = $row['Bed'];
    $NoofRoom = $row['NoofRoom'];
    $Meal = $row['Meal'];
}

if (isset($_POST['guestdetailedit'])) {
    $EditName = $_POST['Name'];
    $EditEmail = $_POST['Email'];
    $EditCountry = $_POST['Country'];
    $EditPhone = $_POST['Phone'];
    $EditRoomType = $_POST['RoomType'];
    $EditBed = $_POST['Bed'];
    $EditNoofRoom = $_POST['NoofRoom'];
    $EditMeal = $_POST['Meal'];
    $Editcin = $_POST['cin'];
    $Editcout = $_POST['cout'];

    $sql = "UPDATE roombook SET Name = '$EditName',Email = '$EditEmail',Country='$EditCountry',Phone='$EditPhone',RoomType='$EditRoomType',Bed='$EditBed',NoofRoom='$EditNoofRoom',Meal='$EditMeal',cin='$Editcin',cout='$Editcout',nodays = datediff('$Editcout','$Editcin') WHERE id = '$id'";

    $result = mysqli_query($conn, $sql);

    $type_of_room = admin_room_base_price($conn, $EditRoomType);

    $bedFactor = match ($EditBed) {
        '1 cliente' => 0.01,
        '2 clientes' => 0.02,
        '3 clientes' => 0.03,
        '4 clientes' => 0.04,
        default => 0.0,
    };
    $type_of_bed = $type_of_room * $bedFactor;

    $mealMultiplier = match ($EditMeal) {
        'Room only' => 0,
        'Breakfast' => 2,
        'Half Board' => 3,
        'Full Board' => 4,
        default => 0,
    };
    $type_of_meal = $type_of_bed * $mealMultiplier;
    
    // noofday update
    $psql ="Select * from roombook where id = '$id'";
    $presult = mysqli_query($conn,$psql);
    $prow=mysqli_fetch_array($presult);
    $Editnoofday = $prow['nodays'];

    $editttot = $type_of_room*$Editnoofday * $EditNoofRoom;
    $editmepr = $type_of_meal*$Editnoofday;
    $editbtot = $type_of_bed*$Editnoofday;

    $editfintot = $editttot + $editmepr + $editbtot;

    $psql = "UPDATE payment SET Name = '$EditName',Email = '$EditEmail',RoomType='$EditRoomType',Bed='$EditBed',NoofRoom='$EditNoofRoom',Meal='$EditMeal',cin='$Editcin',cout='$Editcout',noofdays = '$Editnoofday',roomtotal = '$editttot',bedtotal = '$editbtot',mealtotal = '$editmepr',finaltotal = '$editfintot' WHERE id = '$id'";

    $paymentresult = mysqli_query($conn,$psql);

    if ($paymentresult) {
            header("Location:roombook.php");
    }

}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- boot -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
    <!-- fontowesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" integrity="sha512-xh6O/CkQoPOWDdYTDqeRdPCVd1SpvCA9XXcUnZS2FmJNp1coAFzvtCN9BmamE+4aHK8yyUHUSCcJHgXloTyT2A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- sweet alert -->
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    <link rel="stylesheet" href="./css/roombook.css">
    <link rel="stylesheet" href="./css/roombook-edit.css">
    <title>Editar reserva</title>
</head>

<body class="bg-light">
<?php
    $countries = array("Afghanistan", "Albania", "Algeria", "American Samoa", "Andorra", "Angola", "Anguilla", "Antarctica", "Antigua and Barbuda", "Argentina", "Armenia", "Aruba", "Australia", "Austria", "Azerbaijan", "Bahamas", "Bahrain", "Bangladesh", "Barbados", "Belarus", "Belgium", "Belize", "Benin", "Bermuda", "Bhutan", "Bolivia", "Bosnia and Herzegowina", "Botswana", "Bouvet Island", "Brazil", "British Indian Ocean Territory", "Brunei Darussalam", "Bulgaria", "Burkina Faso", "Burundi", "Cambodia", "Cameroon", "Canada", "Cape Verde", "Cayman Islands", "Central African Republic", "Chad", "Chile", "China", "Christmas Island", "Cocos (Keeling) Islands", "Colombia", "Comoros", "Congo", "Congo, the Democratic Republic of the", "Cook Islands", "Costa Rica", "Cote d'Ivoire", "Croatia (Hrvatska)", "Cuba", "Cyprus", "Czech Republic", "Denmark", "Djibouti", "Dominica", "Dominican Republic", "East Timor", "Ecuador", "Egypt", "El Salvador", "Equatorial Guinea", "Eritrea", "Estonia", "Ethiopia", "Falkland Islands (Malvinas)", "Faroe Islands", "Fiji", "Finland", "France", "France Metropolitan", "French Guiana", "French Polynesia", "French Southern Territories", "Gabon", "Gambia", "Georgia", "Germany", "Ghana", "Gibraltar", "Greece", "Greenland", "Grenada", "Guadeloupe", "Guam", "Guatemala", "Guinea", "Guinea-Bissau", "Guyana", "Haiti", "Heard and Mc Donald Islands", "Holy See (Vatican City State)", "Honduras", "Hong Kong", "Hungary", "Iceland", "India", "Indonesia", "Iran (Islamic Republic of)", "Iraq", "Ireland", "Israel", "Italy", "Jamaica", "Japan", "Jordan", "Kazakhstan", "Kenya", "Kiribati", "Korea, Democratic People's Republic of", "Korea, Republic of", "Kuwait", "Kyrgyzstan", "Lao, People's Democratic Republic", "Latvia", "Lebanon", "Lesotho", "Liberia", "Libyan Arab Jamahiriya", "Liechtenstein", "Lithuania", "Luxembourg", "Macau", "Macedonia, The Former Yugoslav Republic of", "Madagascar", "Malawi", "Malaysia", "Maldives", "Mali", "Malta", "Marshall Islands", "Martinique", "Mauritania", "Mauritius", "Mayotte", "Mexico", "Micronesia, Federated States of", "Moldova, Republic of", "Monaco", "Mongolia", "Montserrat", "Morocco", "Mozambique", "Myanmar", "Namibia", "Nauru", "Nepal", "Netherlands", "Netherlands Antilles", "New Caledonia", "New Zealand", "Nicaragua", "Niger", "Nigeria", "Niue", "Norfolk Island", "Northern Mariana Islands", "Norway", "Oman", "Pakistan", "Palau", "Panama", "Papua New Guinea", "Paraguay", "Peru", "Philippines", "Pitcairn", "Poland", "Portugal", "Puerto Rico", "Qatar", "Reunion", "Romania", "Russian Federation", "Rwanda", "Saint Kitts and Nevis", "Saint Lucia", "Saint Vincent and the Grenadines", "Samoa", "San Marino", "Sao Tome and Principe", "Saudi Arabia", "Senegal", "Seychelles", "Sierra Leone", "Singapore", "Slovakia (Slovak Republic)", "Slovenia", "Solomon Islands", "Somalia", "South Africa", "South Georgia and the South Sandwich Islands", "Spain", "Sri Lanka", "St. Helena", "St. Pierre and Miquelon", "Sudan", "Suriname", "Svalbard and Jan Mayen Islands", "Swaziland", "Sweden", "Switzerland", "Syrian Arab Republic", "Taiwan, Province of China", "Tajikistan", "Tanzania, United Republic of", "Thailand", "Togo", "Tokelau", "Tonga", "Trinidad and Tobago", "Tunisia", "Turkey", "Turkmenistan", "Turks and Caicos Islands", "Tuvalu", "Uganda", "Ukraine", "United Arab Emirates", "United Kingdom", "United States", "United States Minor Outlying Islands", "Uruguay", "Uzbekistan", "Vanuatu", "Venezuela", "Vietnam", "Virgin Islands (British)", "Virgin Islands (U.S.)", "Wallis and Futuna Islands", "Western Sahara", "Yemen", "Yugoslavia", "Zambia", "Zimbabwe");
    $roomTypes = [
        'Habitación Doble',
        'Habitación Suite',
        'Habitación Múltiple',
        'Habitación Sencilla',
    ];
    $bedOptions = ['1 cliente', '2 clientes', '3 clientes', '4 clientes', 'None'];
    $mealOptions = ['Room only', 'Breakfast', 'Half Board', 'Full Board'];
?>
    <div class="container py-4 roombook-edit">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h4 mb-1">Editar reserva</h1>
                <p class="text-muted mb-0">Actualiza los datos del huésped y los detalles de su estadía.</p>
            </div>
            <a href="./roombook.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-2"></i>Volver a reservas</a>
        </div>

        <form method="POST" class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="section-card">
                            <h2 class="section-title"><i class="fa-solid fa-user me-2"></i>Datos del huésped</h2>
                            <div class="mb-3">
                                <label class="form-label" for="guest-name">Nombre completo</label>
                                <input type="text" class="form-control" id="guest-name" name="Name" value="<?php echo htmlspecialchars($Name); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="guest-email">Correo electrónico</label>
                                <input type="email" class="form-control" id="guest-email" name="Email" value="<?php echo htmlspecialchars($Email); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="guest-country">País</label>
                                <select class="form-select" id="guest-country" name="Country" required>
                                    <option value="">Selecciona tu país</option>
                                    <?php foreach ($countries as $country): ?>
                                        <option value="<?php echo htmlspecialchars($country); ?>" <?php echo strcasecmp($Country, $country) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($country); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="guest-phone">Teléfono</label>
                                <input type="text" class="form-control" id="guest-phone" name="Phone" value="<?php echo htmlspecialchars($Phone); ?>" placeholder="Ej. +57 300 000 0000">
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="section-card">
                            <h2 class="section-title"><i class="fa-solid fa-bed me-2"></i>Detalles de la reserva</h2>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label" for="room-type">Tipo de habitación</label>
                                    <select class="form-select" id="room-type" name="RoomType" required>
                                        <option value="">Selecciona una opción</option>
                                        <?php foreach ($roomTypes as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo strcasecmp($RoomType, $type) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="room-bed">Capacidad</label>
                                    <select class="form-select" id="room-bed" name="Bed" required>
                                        <option value="">Selecciona una opción</option>
                                        <?php foreach ($bedOptions as $option): ?>
                                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo strcasecmp($Bed, $option) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($option)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="room-count">Número de habitaciones</label>
                                    <select class="form-select" id="room-count" name="NoofRoom" required>
                                        <option value="">Selecciona una opción</option>
                                        <option value="1" <?php echo (int)$NoofRoom === 1 ? 'selected' : ''; ?>>1</option>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="room-meal">Plan de alimentación</label>
                                    <select class="form-select" id="room-meal" name="Meal" required>
                                        <option value="">Selecciona una opción</option>
                                        <?php foreach ($mealOptions as $option): ?>
                                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo strcasecmp($Meal, $option) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="check-in">Check-in</label>
                                    <input type="date" class="form-control" id="check-in" name="cin" value="<?php echo htmlspecialchars($cin); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="check-out">Check-out</label>
                                    <input type="date" class="form-control" id="check-out" name="cout" value="<?php echo htmlspecialchars($cout); ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row g-4 mt-1">
                    <div class="col-lg-4">
                        <div class="summary-card">
                            <span class="summary-label">Estado actual</span>
                            <span class="summary-value badge text-bg-primary"><?php echo htmlspecialchars($stat); ?></span>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="summary-card">
                            <span class="summary-label">Noches</span>
                            <span class="summary-value"><?php echo (int)$noofday; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white d-flex flex-column flex-md-row justify-content-end gap-2 py-3 px-4">
                <a href="./roombook.php" class="btn btn-outline-secondary"><i class="fa-solid fa-circle-xmark me-2"></i>Cancelar</a>
                <button type="submit" class="btn btn-primary" name="guestdetailedit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar cambios</button>
            </div>
        </form>
    </div>
</body>
</html>
