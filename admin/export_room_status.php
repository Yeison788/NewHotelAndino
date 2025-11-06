<?php
session_start();
require_once '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

if (!isset($_SESSION['adminmail'])) {
    header('Location: ../index.php');
    exit;
}

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_refresh_session($conn, $_SESSION['adminmail']);
admin_require_permission('estado_habitaciones');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    $_SESSION['estado_flash'][] = [
        'type' => 'danger',
        'text' => 'No se encontró la librería Dompdf. Ejecuta "composer install" en el servidor para habilitar la descarga en PDF.'
    ];
    header('Location: room-status.php');
    exit;
}

require_once $autoload;

use Dompdf\Dompdf;

mysqli_query($conn, "ALTER TABLE room MODIFY status ENUM('Disponible','Reservada','Limpieza','Ocupada') NOT NULL DEFAULT 'Disponible'");

$sql = "SELECT r.room_number, r.type, r.floor, r.status, rs.guest_name, rs.nationality, rs.check_in_date, rs.check_out_date
        FROM room r
        LEFT JOIN room_stays rs ON rs.room_id = r.id
        WHERE r.status IN ('Ocupada','Reservada','Limpieza')
        ORDER BY r.status, r.floor, r.room_number";
$result = mysqli_query($conn, $sql);

$roomsByStatus = [
    'Ocupada' => [],
    'Reservada' => [],
    'Limpieza' => [],
];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $roomsByStatus[$row['status']][] = $row;
    }
    mysqli_free_result($result);
}

$logoPath = __DIR__ . '/../image/LogoAndino.png';
$logoBase64 = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
$today = new DateTime('now');

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; margin: 0; padding: 24px; color: #1f2937; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .header h1 { margin: 0; font-size: 20px; }
        .header span { font-size: 12px; color: #6b7280; }
        .status-section { margin-bottom: 20px; }
        .status-title { background: #1d4ed8; color: #fff; padding: 8px 12px; border-radius: 8px; font-size: 14px; display: inline-block; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #d1d5db; font-size: 12px; text-align: left; }
        th { background: #e5e7eb; text-transform: uppercase; letter-spacing: .05em; font-size: 11px; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Informe diario de estado de habitaciones</h1>
            <span>Generado el <?php echo htmlspecialchars($today->format('d/m/Y H:i')); ?></span>
        </div>
        <?php if ($logoBase64): ?>
            <img src="<?php echo $logoBase64; ?>" alt="Hotel Andino" style="height:60px;">
        <?php endif; ?>
    </div>

    <?php foreach ($roomsByStatus as $status => $items): ?>
        <div class="status-section">
            <span class="status-title"><?php echo htmlspecialchars($status); ?> (<?php echo count($items); ?>)</span>
            <?php if (empty($items)): ?>
                <p style="margin-top:10px; color:#6b7280; font-size:12px;">No hay habitaciones en este estado.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Habitación</th>
                            <th>Piso</th>
                            <th>Tipo</th>
                            <th>Huésped</th>
                            <th>Nacionalidad</th>
                            <th>Ingreso</th>
                            <th>Salida</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $room): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($room['room_number']); ?></td>
                                <td><?php echo (int)$room['floor']; ?></td>
                                <td><?php echo htmlspecialchars($room['type']); ?></td>
                                <td><?php echo htmlspecialchars($room['guest_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($room['nationality'] ?? ''); ?></td>
                                <td><?php echo $room['check_in_date'] ? date('d/m/Y', strtotime($room['check_in_date'])) : ''; ?></td>
                                <td><?php echo $room['check_out_date'] ? date('d/m/Y', strtotime($room['check_out_date'])) : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
<?php
$html = ob_get_clean();

$dompdf = new Dompdf();
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('estado-habitaciones-' . $today->format('Ymd_His') . '.pdf', ['Attachment' => true]);
exit;
