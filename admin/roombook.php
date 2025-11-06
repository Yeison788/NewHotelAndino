<?php
session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_ensure_guest_portal($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
admin_require_permission('reservas');

if (isset($_POST['guestdetailsubmit'])) {
    $Name = $_POST['Name'] ?? '';
    $Email = $_POST['Email'] ?? '';
    $Country = $_POST['Country'] ?? '';
    $Phone = $_POST['Phone'] ?? '';
    $RoomType = $_POST['RoomType'] ?? '';
    $Bed = $_POST['Bed'] ?? '';
    $NoofRoom = $_POST['NoofRoom'] ?? '';
    $Meal = $_POST['Meal'] ?? '';
    $cin = $_POST['cin'] ?? '';
    $cout = $_POST['cout'] ?? '';

    if ($Name === '' || $Email === '' || $Country === '') {
        echo "<script>swal({title: 'Completa los datos del huésped', icon: 'error'});</script>";
    } else {
        $sta = 'NotConfirm';
        $sql = "INSERT INTO roombook (Name, Email, Country, Phone, RoomType, Bed, NoofRoom, Meal, cin, cout, stat, nodays) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATEDIFF(?, ?))";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sssssssssssss', $Name, $Email, $Country, $Phone, $RoomType, $Bed, $NoofRoom, $Meal, $cin, $cout, $sta, $cout, $cin);
            if ($stmt->execute()) {
                echo "<script>swal({title: 'Reserva registrada', icon: 'success'});</script>";
            } else {
                echo "<script>swal({title: 'No se pudo registrar la reserva', icon: 'error'});</script>";
            }
            $stmt->close();
        }
    }
}

$roomTypes = ['Habitación Doble', 'Habitación Suite', 'Habitación Múltiple', 'Habitación Sencilla'];
$roomInventory = array_fill_keys($roomTypes, 0);
$roomBooked = array_fill_keys($roomTypes, 0);

if ($roomResult = mysqli_query($conn, 'SELECT type FROM room')) {
    while ($row = mysqli_fetch_assoc($roomResult)) {
        $type = $row['type'] ?? '';
        if (isset($roomInventory[$type])) {
            $roomInventory[$type]++;
        }
    }
    mysqli_free_result($roomResult);
}

if ($paymentResult = mysqli_query($conn, 'SELECT RoomType FROM payment')) {
    while ($row = mysqli_fetch_assoc($paymentResult)) {
        $type = $row['RoomType'] ?? '';
        if (isset($roomBooked[$type])) {
            $roomBooked[$type]++;
        }
    }
    mysqli_free_result($paymentResult);
}

$availabilityByType = [];
$availableTotal = 0;
foreach ($roomInventory as $type => $count) {
    $booked = $roomBooked[$type] ?? 0;
    $available = $count - $booked;
    if ($available < 0) {
        $available = 0;
    }
    $availabilityByType[$type] = $available;
    $availableTotal += $available;
}

$reservations = [];
$totalReservations = 0;
$confirmedReservations = 0;
$roombookQuery = 'SELECT * FROM roombook ORDER BY id DESC';
if ($roombookResult = mysqli_query($conn, $roombookQuery)) {
    while ($row = mysqli_fetch_assoc($roombookResult)) {
        $reservations[] = $row;
        $totalReservations++;
        if (strcasecmp($row['stat'] ?? '', 'Confirm') === 0) {
            $confirmedReservations++;
        }
    }
    mysqli_free_result($roombookResult);
}
$pendingReservations = max(0, $totalReservations - $confirmedReservations);
$hasReservations = $totalReservations > 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Andino - Reservas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" integrity="sha512-xh6O/CkQoPOWDdYTDqeRdPCVd1SpvCA9XXcUnZS2FmJNp1coAFzvtCN9BmamE+4aHK8yyUHUSCcJHgXloTyT2A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    <link rel="stylesheet" href="./css/roombook.css">
</head>
<body class="bg-light">
    <div id="guestdetailpanel">
        <form action="" method="POST" class="guestdetailpanelform">
            <div class="head">
                <h3>Reserva</h3>
                <i class="fa-solid fa-circle-xmark" onclick="adduserclose()"></i>
            </div>
            <div class="middle">
                <div class="guestinfo">
                    <h4><i class="fa-solid fa-user"></i> Información del huésped</h4>
                    <input type="text" name="Name" placeholder="Nombre completo" required>
                    <input type="email" name="Email" placeholder="Correo electrónico" required>
                    <?php
                    $countries = include __DIR__ . '/includes/countries.php';
                    if (!is_array($countries)) {
                        $countries = [];
                    }
                    ?>
                    <select name="Country" class="selectinput" required>
                        <option value selected>Selecciona tu país</option>
                        <?php foreach ($countries as $country): ?>
                            <option value="<?php echo htmlspecialchars($country); ?>"><?php echo htmlspecialchars($country); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="Phone" placeholder="Número de teléfono" required>
                </div>
                <div class="line"></div>
                <div class="reservationinfo">
                    <h4><i class="fa-solid fa-calendar-days"></i> Información de la reserva</h4>
                    <select name="RoomType" class="selectinput" required>
                        <option value selected>Tipo de habitación</option>
                        <?php foreach ($roomTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="Bed" class="selectinput" required>
                        <option value selected>Tipo de cama</option>
                        <option value="1 cliente">1 cliente</option>
                        <option value="2 clientes">2 clientes</option>
                        <option value="3 clientes">3 clientes</option>
                        <option value="4 clientes">4 clientes</option>
                        <option value="None">Ninguna</option>
                    </select>
                    <select name="NoofRoom" class="selectinput" required>
                        <option value selected>Número de habitaciones</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                    </select>
                    <select name="Meal" class="selectinput" required>
                        <option value selected>Comida</option>
                        <option value="Solo habitación">Solo habitación</option>
                        <option value="Desayuno">Desayuno</option>
                        <option value="Media pensión">Media pensión</option>
                        <option value="Pensión completa">Pensión completa</option>
                    </select>
                    <div class="datesection">
                        <span>
                            <label for="cin">Llegada</label>
                            <input name="cin" type="date" required>
                        </span>
                        <span>
                            <label for="cout">Salida</label>
                            <input name="cout" type="date" required>
                        </span>
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-success" name="guestdetailsubmit">Guardar reserva</button>
            </div>
        </form>
    </div>

    <div class="container py-4 reservations-wrapper">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Reservas</h1>
                <p class="text-muted mb-0">Gestiona las solicitudes y confirmaciones de habitaciones.</p>
            </div>
            <div class="text-md-end">
                <span class="text-muted small text-uppercase">Habitaciones disponibles</span>
                <div class="fs-4 fw-semibold"><?php echo $availableTotal; ?></div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small mb-2">Reservas registradas</div>
                        <div class="h4 mb-0"><?php echo $totalReservations; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small mb-2">Confirmadas</div>
                        <div class="h5 mb-0 text-success"><?php echo $confirmedReservations; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small mb-2">Pendientes</div>
                        <div class="h5 mb-0 text-warning"><?php echo $pendingReservations; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small mb-2">Disponibilidad por tipo</div>
                        <div class="small mb-0">
                            <?php foreach ($availabilityByType as $type => $available): ?>
                                <div><?php echo htmlspecialchars($type); ?>: <strong><?php echo $available; ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 mb-3">
                    <div class="flex-grow-1">
                        <h2 class="h5 mb-1">Historial de reservas</h2>
                        <p class="text-muted mb-0">Consulta, confirma o edita reservas ingresadas al sistema.</p>
                    </div>
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2 ms-lg-auto w-100 w-lg-auto">
                        <div class="flex-grow-1">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" class="form-control" id="reservations-search" placeholder="Buscar por nombre, correo o habitación" onkeyup="filterReservations()">
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#reservationsReportModal"><i class="fa-solid fa-file-arrow-down"></i> Informe</button>
                            <button class="btn btn-primary" type="button" id="adduser" onclick="adduseropen()"><i class="fa-solid fa-plus"></i> Nueva reserva</button>
                        </div>
                    </div>
                </div>

                <?php if ($hasReservations): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="table-data">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Nombre</th>
                                    <th scope="col">Correo</th>
                                    <th scope="col">País</th>
                                    <th scope="col">Teléfono</th>
                                    <th scope="col">Tipo de habitación</th>
                                    <th scope="col">Tipo de cama</th>
                                    <th scope="col">Habitaciones</th>
                                    <th scope="col">Comida</th>
                                    <th scope="col">Llegada</th>
                                    <th scope="col">Salida</th>
                                    <th scope="col">Días</th>
                                    <th scope="col">Estado</th>
                                    <th scope="col" class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservations as $res): ?>
                                    <tr>
                                        <td><?php echo (int)$res['id']; ?></td>
                                        <td><?php echo htmlspecialchars($res['Name']); ?></td>
                                        <td><?php echo htmlspecialchars($res['Email']); ?></td>
                                        <td><?php echo htmlspecialchars($res['Country']); ?></td>
                                        <td><?php echo htmlspecialchars($res['Phone']); ?></td>
                                        <td><?php echo htmlspecialchars($res['RoomType']); ?></td>
                                        <td><?php echo htmlspecialchars($res['Bed']); ?></td>
                                        <td><?php echo (int)$res['NoofRoom']; ?></td>
                                        <td><?php echo htmlspecialchars($res['Meal']); ?></td>
                                        <td><?php echo htmlspecialchars($res['cin']); ?></td>
                                        <td><?php echo htmlspecialchars($res['cout']); ?></td>
                                        <td><?php echo htmlspecialchars($res['nodays']); ?></td>
                                        <td>
                                            <?php if (strcasecmp($res['stat'], 'Confirm') === 0): ?>
                                                <span class="badge bg-success">Confirmada</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <?php if (strcasecmp($res['stat'], 'Confirm') !== 0): ?>
                                                <a href="roomconfirm.php?id=<?php echo (int)$res['id']; ?>" class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i></a>
                                            <?php endif; ?>
                                            <a href="roombookedit.php?id=<?php echo (int)$res['id']; ?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-pen"></i></a>
                                            <a href="roombookdelete.php?id=<?php echo (int)$res['id']; ?>" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">Aún no hay reservas registradas.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reservationsReportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="report_export.php" id="reservationsReportForm">
                <div class="modal-header">
                    <h5 class="modal-title">Descargar informe de reservas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="module" value="reservas">
                    <div class="mb-3">
                        <label class="form-label">Rango de fechas</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="range_mode" id="reservations-range-today" value="today" checked>
                            <label class="form-check-label" for="reservations-range-today">Hoy</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="range_mode" id="reservations-range-day" value="day">
                            <label class="form-check-label" for="reservations-range-day">Seleccionar día específico</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="range_mode" id="reservations-range-custom" value="range">
                            <label class="form-check-label" for="reservations-range-custom">Rango personalizado</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12" data-role="specific-date">
                            <label for="reservations-specific-date" class="form-label">Fecha</label>
                            <input type="date" class="form-control" id="reservations-specific-date" name="specific_date" disabled>
                        </div>
                        <div class="col-md-6" data-role="start-date">
                            <label for="reservations-start-date" class="form-label">Desde</label>
                            <input type="date" class="form-control" id="reservations-start-date" name="start_date" disabled>
                        </div>
                        <div class="col-md-6" data-role="end-date">
                            <label for="reservations-end-date" class="form-label">Hasta</label>
                            <input type="date" class="form-control" id="reservations-end-date" name="end_date" disabled>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Descargar</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
    <script src="./javascript/roombook.js"></script>
    <script>
        function setupReportModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            const form = modal.querySelector('form');
            const radios = form.querySelectorAll('input[name="range_mode"]');
            const specificDate = form.querySelector('[data-role="specific-date"] input');
            const startDate = form.querySelector('[data-role="start-date"] input');
            const endDate = form.querySelector('[data-role="end-date"] input');

            const updateVisibility = () => {
                const selected = form.querySelector('input[name="range_mode"]:checked');
                const mode = selected ? selected.value : 'today';

                if (specificDate) {
                    specificDate.disabled = mode !== 'day';
                    specificDate.required = mode === 'day';
                    if (mode !== 'day') specificDate.value = '';
                }
                if (startDate && endDate) {
                    const isRange = mode === 'range';
                    startDate.disabled = !isRange;
                    endDate.disabled = !isRange;
                    startDate.required = isRange;
                    endDate.required = isRange;
                    if (!isRange) {
                        startDate.value = '';
                        endDate.value = '';
                    }
                }
            };

            radios.forEach((radio) => {
                radio.addEventListener('change', updateVisibility);
            });

            modal.addEventListener('shown.bs.modal', updateVisibility);
            modal.addEventListener('hidden.bs.modal', () => {
                form.reset();
                updateVisibility();
            });

            updateVisibility();
        }

        setupReportModal('reservationsReportModal');
    </script>
</body>
</html>
