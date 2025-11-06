<?php
    session_start();
    include '../config.php';
    require_once __DIR__ . '/includes/admin_bootstrap.php';

    ensureEmpStructure($conn);
    ensureRoomRates($conn);
    admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
    admin_require_permission('pagos');

    function payment_normalize_price(string $value): ?string
    {
        $clean = preg_replace('/[^0-9,\.]/', '', str_replace(' ', '', trim($value)));
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
        return is_numeric($clean) ? number_format((float)$clean, 2, '.', '') : null;
    }

    if (!isset($_SESSION['payments_flash']) || !is_array($_SESSION['payments_flash'])) {
        $_SESSION['payments_flash'] = [];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_room_rates'])) {
        $rates = $_POST['rates'] ?? [];
        $updated = 0;
        foreach ($rates as $type => $priceRaw) {
            $normalized = payment_normalize_price((string)$priceRaw);
            if ($normalized === null) {
                continue;
            }
            if ($stmt = $conn->prepare('UPDATE room_rates SET base_price = ? WHERE room_type = ?')) {
                $normalizedFloat = (float)$normalized;
                $stmt->bind_param('ds', $normalizedFloat, $type);
                if ($stmt->execute()) {
                    $updated++;
                }
                $stmt->close();
            }
        }
        if ($updated > 0) {
            $_SESSION['payments_flash'][] = ['type' => 'success', 'text' => 'Tarifas de habitaciones actualizadas correctamente.'];
        } else {
            $_SESSION['payments_flash'][] = ['type' => 'warning', 'text' => 'No se detectaron cambios en las tarifas enviadas.'];
        }
        header('Location: payment.php');
        exit;
    }

    $totals = [
        'count' => 0,
        'room' => 0,
        'bed' => 0,
        'meal' => 0,
        'final' => 0,
    ];

    $paymanttablesql = "SELECT * FROM payment ORDER BY id DESC";
    $paymantresult = mysqli_query($conn, $paymanttablesql);
    if ($paymantresult) {
        while ($row = mysqli_fetch_assoc($paymantresult)) {
            $totals['count']++;
            $totals['room'] += (float)$row['roomtotal'];
            $totals['bed']  += (float)$row['bedtotal'];
            $totals['meal'] += (float)$row['mealtotal'];
            $totals['final'] += (float)$row['finaltotal'];
        }
        mysqli_data_seek($paymantresult, 0);
    }
    $totals['avg'] = $totals['count'] > 0 ? $totals['final'] / $totals['count'] : 0;
    $hasPayments = $paymantresult && $totals['count'] > 0;

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

    $flashMessages = $_SESSION['payments_flash'];
    $_SESSION['payments_flash'] = [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Andino - Pagos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" integrity="sha512-xh6O/CkQoPOWDdYTDqeRdPCVd1SpvCA9XXcUnZS2FmJNp1coAFzvtCN9BmamE+4aHK8yyUHUSCcJHgXloTyT2A==" crossorigin="anonymous" referrerpolicy="no-referrer"/>
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Pagos y facturación</h1>
                <p class="text-muted mb-0">Control de los cobros confirmados para huéspedes y reservas.</p>
            </div>
            <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3">
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#roomRatesModal">
                    <i class="fa-solid fa-tags me-2"></i>Editar tarifas de habitaciones
                </button>
                <div class="text-sm-end">
                    <span class="text-muted small text-uppercase d-block">Total facturado</span>
                    <div class="fs-4 fw-semibold">COP <?php echo number_format($totals['final'], 0, ',', '.'); ?></div>
                </div>
            </div>
        </div>

        <?php foreach ($flashMessages as $message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message['type']); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message['text']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            </div>
        <?php endforeach; ?>

        <?php if ($hasPayments): ?>
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted text-uppercase small mb-2">Facturas registradas</div>
                            <div class="h4 mb-0"><?php echo $totals['count']; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted text-uppercase small mb-2">Ingresos por habitaciones</div>
                            <div class="h5 mb-0">COP <?php echo number_format($totals['room'], 0, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted text-uppercase small mb-2">Adicionales (camas y comidas)</div>
                            <div class="h5 mb-0">COP <?php echo number_format($totals['bed'] + $totals['meal'], 0, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted text-uppercase small mb-2">Ticket promedio</div>
                            <div class="h5 mb-0">COP <?php echo number_format($totals['avg'], 0, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 mb-3">
                    <div class="flex-grow-1">
                        <h2 class="h5 mb-1">Historial de pagos</h2>
                        <p class="text-muted mb-0">Últimos movimientos registrados en el sistema.</p>
                    </div>
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2 ms-lg-auto w-100 w-lg-auto">
                        <div class="flex-grow-1">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" class="form-control" id="payments-search" placeholder="Buscar por nombre, habitación o correo" onkeyup="filterPayments()">
                            </div>
                        </div>
                        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#paymentsReportModal"><i class="fa-solid fa-file-arrow-down"></i> Informe</button>
                    </div>
                </div>

                <?php if ($hasPayments && $paymantresult): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="table-data">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Huésped</th>
                                    <th scope="col">Tipo de habitación</th>
                                    <th scope="col">Cama</th>
                                    <th scope="col">Ingreso</th>
                                    <th scope="col">Salida</th>
                                    <th scope="col">Días</th>
                                    <th scope="col">Habitaciones</th>
                                    <th scope="col">Plan de comida</th>
                                    <th scope="col" class="text-end">Hab.</th>
                                    <th scope="col" class="text-end">Cama</th>
                                    <th scope="col" class="text-end">Comidas</th>
                                    <th scope="col" class="text-end">Total</th>
                                    <th scope="col">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($res = mysqli_fetch_assoc($paymantresult)): ?>
                                    <tr>
                                        <td><?php echo (int)$res['id']; ?></td>
                                        <td><?php echo htmlspecialchars($res['Name']); ?></td>
                                        <td><?php echo htmlspecialchars($res['RoomType']); ?></td>
                                        <td><?php echo htmlspecialchars($res['Bed']); ?></td>
                                        <td><?php echo htmlspecialchars($res['cin']); ?></td>
                                        <td><?php echo htmlspecialchars($res['cout']); ?></td>
                                        <td><?php echo (int)$res['noofdays']; ?></td>
                                        <td><?php echo (int)$res['NoofRoom']; ?></td>
                                        <td><?php echo htmlspecialchars($res['meal']); ?></td>
                                        <td class="text-end">COP <?php echo number_format($res['roomtotal'], 0, ',', '.'); ?></td>
                                        <td class="text-end">COP <?php echo number_format($res['bedtotal'], 0, ',', '.'); ?></td>
                                        <td class="text-end">COP <?php echo number_format($res['mealtotal'], 0, ',', '.'); ?></td>
                                        <td class="text-end fw-semibold">COP <?php echo number_format($res['finaltotal'], 0, ',', '.'); ?></td>
                                        <td class="text-nowrap">
                                            <a class="btn btn-sm btn-primary" href="invoiceprint.php?id=<?php echo (int)$res['id']; ?>"><i class="fa-solid fa-print"></i> Imprimir</a>
                                            <a class="btn btn-sm btn-outline-danger" href="paymantdelete.php?id=<?php echo (int)$res['id']; ?>">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        No se han registrado pagos todavía.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="paymentsReportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="report_export.php" id="paymentsReportForm">
                <div class="modal-header">
                    <h5 class="modal-title">Descargar informe de pagos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="module" value="pagos">
                    <div class="mb-3">
                        <label class="form-label">Rango de fechas</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="range_mode" id="payments-range-today" value="today" checked>
                            <label class="form-check-label" for="payments-range-today">Hoy</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="range_mode" id="payments-range-day" value="day">
                            <label class="form-check-label" for="payments-range-day">Seleccionar día específico</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="range_mode" id="payments-range-custom" value="range">
                            <label class="form-check-label" for="payments-range-custom">Rango personalizado</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12" data-role="specific-date">
                            <label for="payments-specific-date" class="form-label">Fecha</label>
                            <input type="date" class="form-control" id="payments-specific-date" name="specific_date" disabled>
                        </div>
                        <div class="col-md-6" data-role="start-date">
                            <label for="payments-start-date" class="form-label">Desde</label>
                            <input type="date" class="form-control" id="payments-start-date" name="start_date" disabled>
                        </div>
                        <div class="col-md-6" data-role="end-date">
                            <label for="payments-end-date" class="form-label">Hasta</label>
                            <input type="date" class="form-control" id="payments-end-date" name="end_date" disabled>
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

    <div class="modal fade" id="roomRatesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" method="post">
                <input type="hidden" name="update_room_rates" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Editar tarifas base de habitaciones</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Las tarifas aquí definidas se utilizan como referencia para cálculos automáticos y pueden ajustarse para negociaciones especiales.</p>
                    <div class="row g-3">
                        <?php foreach ($roomRates as $type => $price): ?>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo htmlspecialchars($type); ?></label>
                                <div class="input-group">
                                    <span class="input-group-text">COP</span>
                                    <input type="text" class="form-control" name="rates[<?php echo htmlspecialchars($type); ?>]" value="<?php echo number_format($price, 0, ',', '.'); ?>" inputmode="decimal" required>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar tarifas</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
    <script>
        const searchInput = document.getElementById('payments-search');
        function filterPayments() {
            if (!searchInput) return;
            const filter = searchInput.value.trim().toLowerCase();
            const rows = document.querySelectorAll('#table-data tbody tr');
            rows.forEach((row) => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        }
        if (searchInput) {
            searchInput.addEventListener('input', filterPayments);
        }
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

            radios.forEach((radio) => radio.addEventListener('change', updateVisibility));
            modal.addEventListener('shown.bs.modal', updateVisibility);
            modal.addEventListener('hidden.bs.modal', () => {
                form.reset();
                updateVisibility();
            });
            updateVisibility();
        }

        setupReportModal('paymentsReportModal');
    </script>
</body>
</html>
