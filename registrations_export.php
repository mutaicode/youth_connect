<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Admin only (same as manage_registrations.php)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT role, account_status FROM users WHERE id = ? LIMIT 1"
);
$stmt->execute([(int) $_SESSION['user_id']]);
$admin = $stmt->fetch();

if (
    !$admin ||
    $admin['role'] !== 'admin' ||
    $admin['account_status'] !== 'active'
) {
    http_response_code(403);
    exit('Access denied.');
}

$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$eventId = $eventId && $eventId > 0 ? $eventId : 0;

try {
    $sql = "SELECT
                r.registration_reference,
                r.participant_name,
                r.participant_email,
                r.participant_phone,
                r.participant_type,
                r.status,
                r.created_at,
                e.title AS event_title,
                e.start_datetime,
                e.venue,
                c.church_name
            FROM registrations r
            INNER JOIN events e ON e.id = r.event_id
            LEFT JOIN churches c ON c.id = r.church_id";

    $params = [];
    if ($eventId > 0) {
        $sql .= " WHERE r.event_id = ?";
        $params[] = $eventId;
    }

    $sql .= " ORDER BY r.created_at DESC, r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eventLabel = 'all_events';
    if ($eventId > 0 && !empty($rows)) {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', $rows[0]['event_title']);
        $eventLabel = trim($slug, '_');
    }
} catch (Throwable $ex) {
    error_log('Registrations export error: ' . $ex->getMessage());
    http_response_code(500);
    exit('Unable to generate export.');
}

$filename = 'registrations_' . $eventLabel . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['AIC Kabartegan Region Youth Connect']);
fputcsv($out, ['Registration Report']);
fputcsv($out, ['Exported', date('d M Y, H:i')]);
fputcsv($out, ['Filter', $eventId > 0 ? 'Single event' : 'All events']);
fputcsv($out, []);

fputcsv($out, [
    '#',
    'Reference',
    'Participant Name',
    'Email',
    'Phone',
    'Church',
    'Type',
    'Status',
    'Event',
    'Event Date',
    'Venue',
    'Registered On',
]);

$num = 0;
foreach ($rows as $row) {
    $num++;

    foreach (['participant_name', 'participant_email', 'participant_phone', 'church_name', 'event_title'] as $f) {
        if (!empty($row[$f]) && preg_match('/^[=+\-@]/', (string) $row[$f])) {
            $row[$f] = "'" . $row[$f];
        }
    }

    fputcsv($out, [
        $num,
        $row['registration_reference'],
        $row['participant_name'],
        $row['participant_email'],
        $row['participant_phone'] ?: '',
        $row['church_name'] ?: '',
        ucfirst($row['participant_type']),
        ucfirst($row['status']),
        $row['event_title'],
        date('d M Y H:i', strtotime($row['start_datetime'])),
        $row['venue'] ?: '',
        date('d M Y H:i', strtotime($row['created_at'])),
    ]);
}

fclose($out);
exit;