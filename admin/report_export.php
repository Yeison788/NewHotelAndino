<?php
session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido.';
    exit;
}

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');

$module = $_POST['module'] ?? '';
$rangeMode = $_POST['range_mode'] ?? 'today';

$modules = [
    'reservas' => [
        'permission' => 'reservas',
        'table'      => 'roombook',
        'dateColumn' => 'cin',
        'orderBy'    => 'cin DESC',
        'columns'    => [
            'id'        => 'ID',
            'Name'      => 'Nombre',
            'Email'     => 'Correo',
            'Country'   => 'País',
            'Phone'     => 'Teléfono',
            'RoomType'  => 'Tipo de habitación',
            'Bed'       => 'Tipo de cama',
            'NoofRoom'  => 'Habitaciones',
            'Meal'      => 'Comida',
            'cin'       => 'Llegada',
            'cout'      => 'Salida',
            'nodays'    => 'Días',
            'stat'      => 'Estado',
        ],
    ],
    'pagos' => [
        'permission' => 'pagos',
        'table'      => 'payment',
        'dateColumn' => 'cout',
        'orderBy'    => 'cout DESC',
        'columns'    => [
            'id'         => 'ID',
            'Name'       => 'Huésped',
            'RoomType'   => 'Tipo de habitación',
            'Bed'        => 'Cama',
            'cin'        => 'Ingreso',
            'cout'       => 'Salida',
            'noofdays'   => 'Días',
            'NoofRoom'   => 'Habitaciones',
            'meal'       => 'Plan de comida',
            'roomtotal'  => 'Total habitación',
            'bedtotal'   => 'Total cama',
            'mealtotal'  => 'Total comidas',
            'finaltotal' => 'Total factura',
        ],
    ],
];

if (!isset($modules[$module])) {
    http_response_code(400);
    echo 'Módulo no válido.';
    exit;
}

$meta = $modules[$module];
if (!admin_user_can($meta['permission'])) {
    http_response_code(403);
    echo 'No tienes permisos para exportar esta información.';
    exit;
}

$startDate = null;
$endDate = null;
$today = new DateTime('today');

try {
    switch ($rangeMode) {
        case 'day':
            $date = trim($_POST['specific_date'] ?? '');
            if ($date === '') {
                throw new InvalidArgumentException('Debes seleccionar una fecha.');
            }
            $startDate = DateTime::createFromFormat('Y-m-d', $date);
            if (!$startDate) {
                throw new InvalidArgumentException('La fecha proporcionada no es válida.');
            }
            $startDate->setTime(0, 0, 0);
            $endDate = clone $startDate;
            break;
        case 'range':
            $start = trim($_POST['start_date'] ?? '');
            $end = trim($_POST['end_date'] ?? '');
            if ($start === '' || $end === '') {
                throw new InvalidArgumentException('Debes indicar el rango completo.');
            }
            $startDate = DateTime::createFromFormat('Y-m-d', $start);
            $endDate = DateTime::createFromFormat('Y-m-d', $end);
            if (!$startDate || !$endDate) {
                throw new InvalidArgumentException('Alguna de las fechas del rango no es válida.');
            }
            if ($startDate > $endDate) {
                throw new InvalidArgumentException('La fecha inicial no puede ser mayor que la final.');
            }
            $startDate->setTime(0, 0, 0);
            $endDate->setTime(0, 0, 0);
            break;
        case 'today':
        default:
            $startDate = clone $today;
            $endDate = clone $today;
            break;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo $e->getMessage();
    exit;
}

$start = $startDate->format('Y-m-d');
$end = $endDate->format('Y-m-d');
$columns = $meta['columns'];
$columnList = implode(', ', array_keys($columns));
$sql = sprintf(
    'SELECT %s FROM %s WHERE DATE(%s) BETWEEN ? AND ? ORDER BY %s',
    $columnList,
    $meta['table'],
    $meta['dateColumn'],
    $meta['orderBy']
);

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo 'No se pudo preparar la consulta.';
    exit;
}

$stmt->bind_param('ss', $start, $end);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo 'No se pudo generar el informe.';
    exit;
}

$result = $stmt->get_result();
if (!$result) {
    $stmt->close();
    http_response_code(500);
    echo 'No se pudo obtener el resultado del informe.';
    exit;
}

$filename = sprintf('%s_informe_%s.csv', $module, date('Ymd_His'));
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF";

$fp = fopen('php://output', 'w');
fputcsv($fp, array_values($columns));
while ($row = $result->fetch_assoc()) {
    $line = [];
    foreach ($columns as $key => $label) {
        $line[] = $row[$key] ?? '';
    }
    fputcsv($fp, $line);
}

fclose($fp);
$stmt->close();
exit;
