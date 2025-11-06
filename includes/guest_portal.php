<?php
/**
 * Funciones de apoyo para el portal del huésped y las operaciones
 * compartidas entre la web pública y el panel administrativo.
 */

if (!function_exists('guest_portal_column_exists')) {
    function guest_portal_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'";
        if (!$result = $conn->query($sql)) {
            return false;
        }
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
}

if (!function_exists('guest_portal_constraint_exists')) {
    function guest_portal_constraint_exists(mysqli $conn, string $table, string $constraint): bool
    {
        $sql = "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $table, $constraint);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->free_result();
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('guest_portal_ensure_schema')) {
    function guest_portal_ensure_schema(mysqli $conn): void
    {
        // --- roombook ---
        if (!guest_portal_column_exists($conn, 'roombook', 'user_id')) {
            $conn->query("ALTER TABLE roombook ADD COLUMN user_id INT NULL AFTER room_id");
        }
        if (!guest_portal_column_exists($conn, 'roombook', 'created_at')) {
            $conn->query("ALTER TABLE roombook ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER stat");
        }
        if (!guest_portal_column_exists($conn, 'roombook', 'updated_at')) {
            $conn->query("ALTER TABLE roombook ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }
        if (!guest_portal_constraint_exists($conn, 'roombook', 'fk_roombook_user')) {
            $conn->query("ALTER TABLE roombook ADD CONSTRAINT fk_roombook_user FOREIGN KEY (user_id) REFERENCES signup(UserID) ON DELETE SET NULL");
        }

        // --- guest_service_requests ---
        $conn->query("CREATE TABLE IF NOT EXISTS guest_service_requests (
            id INT NOT NULL AUTO_INCREMENT,
            roombook_id INT NOT NULL,
            user_id INT NULL,
            request_type VARCHAR(40) NOT NULL,
            details TEXT NULL,
            status ENUM('pendiente','en_proceso','completado','cancelado') NOT NULL DEFAULT 'pendiente',
            charge_amount DECIMAL(10,2) NULL,
            requested_by ENUM('guest','staff') NOT NULL DEFAULT 'guest',
            response_note TEXT NULL,
            handled_by VARCHAR(190) NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_guest_requests_user (user_id),
            KEY idx_guest_requests_room (roombook_id),
            CONSTRAINT fk_guest_requests_reservation FOREIGN KEY (roombook_id) REFERENCES roombook(id) ON DELETE CASCADE,
            CONSTRAINT fk_guest_requests_user FOREIGN KEY (user_id) REFERENCES signup(UserID) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!guest_portal_column_exists($conn, 'guest_service_requests', 'charge_amount')) {
            $conn->query("ALTER TABLE guest_service_requests ADD COLUMN charge_amount DECIMAL(10,2) NULL AFTER status");
        }
        if (!guest_portal_column_exists($conn, 'guest_service_requests', 'requested_by')) {
            $conn->query("ALTER TABLE guest_service_requests ADD COLUMN requested_by ENUM('guest','staff') NOT NULL DEFAULT 'guest' AFTER charge_amount");
        }
        if (!guest_portal_column_exists($conn, 'guest_service_requests', 'response_note')) {
            $conn->query("ALTER TABLE guest_service_requests ADD COLUMN response_note TEXT NULL AFTER requested_by");
        }
        if (!guest_portal_column_exists($conn, 'guest_service_requests', 'handled_by')) {
            $conn->query("ALTER TABLE guest_service_requests ADD COLUMN handled_by VARCHAR(190) NULL AFTER response_note");
        }
        if (!guest_portal_column_exists($conn, 'guest_service_requests', 'resolved_at')) {
            $conn->query("ALTER TABLE guest_service_requests ADD COLUMN resolved_at DATETIME NULL AFTER handled_by");
        }

        // --- notifications ---
        $conn->query("CREATE TABLE IF NOT EXISTS system_notifications (
            id INT NOT NULL AUTO_INCREMENT,
            audience ENUM('admin','recepcion','staff') NOT NULL DEFAULT 'admin',
            title VARCHAR(190) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255) NULL,
            related_reservation_id INT NULL,
            related_request_id INT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notifications_audience (audience),
            KEY idx_notifications_reservation (related_reservation_id),
            KEY idx_notifications_request (related_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('guest_portal_fetch_user')) {
    function guest_portal_fetch_user(mysqli $conn, string $email): ?array
    {
        $sql = "SELECT UserID, Username, Email FROM signup WHERE Email = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $email);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $user ?: null;
    }
}

if (!function_exists('guest_portal_reservations_for_user')) {
    function guest_portal_reservations_for_user(mysqli $conn, int $userId): array
    {
        $sql = "SELECT r.*, p.finaltotal, p.mealtotal, p.roomtotal, p.bedtotal
                FROM roombook r
                LEFT JOIN payment p ON p.id = r.id
                WHERE r.user_id = ?
                ORDER BY r.created_at DESC, r.id DESC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $reservations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $reservations;
    }
}

if (!function_exists('guest_portal_active_reservation')) {
    function guest_portal_active_reservation(mysqli $conn, int $userId): ?array
    {
        $sql = "SELECT r.*, rm.room_number, rm.type AS room_type_name, rm.bedding AS room_bedding,
                       p.finaltotal, p.mealtotal, p.roomtotal, p.bedtotal
                FROM roombook r
                LEFT JOIN room rm ON rm.id = r.room_id
                LEFT JOIN payment p ON p.id = r.id
                WHERE r.user_id = ? AND r.stat IN ('Confirm','Ocupado','CheckIn')
                ORDER BY r.cin DESC, r.id DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $reservation = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $reservation ?: null;
    }
}

if (!function_exists('guest_portal_request_types')) {
    function guest_portal_request_types(): array
    {
        return [
            'toalla'      => 'Toalla adicional',
            'jabon'       => 'Jabón / amenities',
            'asistencia'  => 'Asistencia de recepción',
            'minibar'     => 'Consumo de minibar',
            'otro'        => 'Otro servicio'
        ];
    }
}

if (!function_exists('guest_portal_create_request')) {
    function guest_portal_create_request(mysqli $conn, int $reservationId, ?int $userId, string $type, string $details, string $requestedBy = 'guest', ?float $charge = null): ?int
    {
        $sql = "INSERT INTO guest_service_requests (roombook_id, user_id, request_type, details, requested_by, charge_amount)
                VALUES (?, NULLIF(?,0), ?, ?, ?, NULLIF(?, -1))";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $userIdParam = $userId ? (int)$userId : 0;
        $chargeParam = ($charge !== null) ? (float)$charge : -1.0;
        $stmt->bind_param('iisssd', $reservationId, $userIdParam, $type, $details, $requestedBy, $chargeParam);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $id = $stmt->insert_id;
        $stmt->close();
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('guest_portal_requests_for_user')) {
    function guest_portal_requests_for_user(mysqli $conn, int $userId): array
    {
        $sql = "SELECT r.id, r.request_type, r.details, r.status, r.charge_amount, r.created_at, r.updated_at, r.roombook_id,
                       rb.RoomType, rb.stat, rb.cin, rb.cout, rb.room_id
                FROM guest_service_requests r
                INNER JOIN roombook rb ON rb.id = r.roombook_id
                WHERE r.user_id = ?
                ORDER BY r.created_at DESC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $requests;
    }
}

if (!function_exists('guest_portal_requests_for_admin')) {
    function guest_portal_requests_for_admin(mysqli $conn, ?string $statusFilter = null): array
    {
        $sql = "SELECT r.*, s.Username, s.Email AS user_email, rb.RoomType, rb.cin, rb.cout, rb.stat, rb.Name AS reservation_name, rb.Email AS reservation_email, rm.room_number
                FROM guest_service_requests r
                LEFT JOIN signup s ON s.UserID = r.user_id
                LEFT JOIN roombook rb ON rb.id = r.roombook_id
                LEFT JOIN room rm ON rm.id = rb.room_id";
        $params = [];
        $types = '';
        if ($statusFilter && in_array($statusFilter, ['pendiente','en_proceso','completado','cancelado'], true)) {
            $sql .= " WHERE r.status = ?";
            $types .= 's';
            $params[] = $statusFilter;
        }
        $sql .= " ORDER BY r.created_at DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('guest_portal_confirmed_reservations')) {
    function guest_portal_confirmed_reservations(mysqli $conn): array
    {
        $sql = "SELECT rb.id, rb.Name, rb.Email, rb.RoomType, rb.user_id, rb.cin, rb.cout, rb.stat, rb.room_id, rm.room_number
                FROM roombook rb
                LEFT JOIN room rm ON rm.id = rb.room_id
                WHERE rb.stat IN ('Confirm','Ocupado','CheckIn')
                ORDER BY rb.cin DESC, rb.id DESC";
        $result = $conn->query($sql);
        if (!$result) {
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    }
}

if (!function_exists('guest_portal_update_request')) {
    function guest_portal_update_request(mysqli $conn, int $requestId, string $status, ?string $note, ?string $handledBy, ?float $charge = null): bool
    {
        if (!in_array($status, ['pendiente','en_proceso','completado','cancelado'], true)) {
            return false;
        }
        $resolvedAt = null;
        if (in_array($status, ['completado','cancelado'], true)) {
            $resolvedAt = date('Y-m-d H:i:s');
        }

        if ($charge === null) {
            $sql = "UPDATE guest_service_requests SET status = ?, response_note = ?, handled_by = ?, resolved_at = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssssi', $status, $note, $handledBy, $resolvedAt, $requestId);
        } else {
            $sql = "UPDATE guest_service_requests SET status = ?, response_note = ?, handled_by = ?, resolved_at = ?, charge_amount = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssssdi', $status, $note, $handledBy, $resolvedAt, $charge, $requestId);
        }

        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('guest_portal_record_notification')) {
    function guest_portal_record_notification(mysqli $conn, string $audience, string $title, string $message, ?string $link = null, ?int $reservationId = null, ?int $requestId = null): void
    {
        if (!in_array($audience, ['admin','recepcion','staff'], true)) {
            $audience = 'admin';
        }
        $sql = "INSERT INTO system_notifications (audience, title, message, link, related_reservation_id, related_request_id)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ssssii', $audience, $title, $message, $link, $reservationId, $requestId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('guest_portal_notifications_for_audience')) {
    function guest_portal_notifications_for_audience(mysqli $conn, string $audience, int $limit = 10, bool $onlyUnread = false): array
    {
        if (!in_array($audience, ['admin','recepcion','staff'], true)) {
            $audience = 'admin';
        }
        $sql = "SELECT * FROM system_notifications WHERE audience = ?";
        $params = [$audience];
        $types = 's';
        if ($onlyUnread) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $types .= 'i';
        $params[] = $limit;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $notifications = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $notifications;
    }
}

if (!function_exists('guest_portal_mark_notifications')) {
    function guest_portal_mark_notifications(mysqli $conn, array $ids, bool $read = true): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE system_notifications SET is_read = ? WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $types = str_repeat('i', count($ids) + 1);
        $params = array_merge([$read ? 1 : 0], $ids);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('guest_portal_format_status')) {
    function guest_portal_format_status(string $status): string
    {
        return match ($status) {
            'pendiente'   => 'Pendiente',
            'en_proceso'  => 'En proceso',
            'completado'  => 'Completado',
            'cancelado'   => 'Cancelado',
            default       => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}

?>
