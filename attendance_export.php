<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Allow only authorized leadership roles (matches attendance.php).
$allowed_roles = [
    'admin',
    'chairperson',
    'vice_chairperson',
    'secretary',
    'vice_secretary',
    'treasurer'
];

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], $allowed_roles, true)
) {
    http_response_code(403);
    exit('Access denied.');
}

// Optional filter: a specific event.
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$eventId || $eventId < 1) {
    http_response_code(400);
    exit('Please select an event to export.');
}

try {
    // Load event details.
    $stmt = $pdo->prepare(
        "SELECT id, title, start_datetime, venue, status
         FROM events
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        exit('Event not found.');
    }

    // Load participants and their attendance.
    $stmt = $pdo->prepare(
        "SELECT
            r.registration_reference,
            r.participant_name,
            r.participant_email,
            r.participant_phone,
            r.participant_type,
            r.status AS registration_status,
            c.church_name,
            ea.attendance_status,
            ea.marked_at,
            marker.full_name AS marked_by_name
         FROM registrations r
         LEFT JOIN churches c
            ON c.id = r.church_id
         LEFT JOIN event_attendance ea
            ON ea.registration_id = r.id
           AND ea.event_id = r.event_id
         LEFT JOIN users marker
            ON marker.id = ea.marked_by
         WHERE r.event_id = ?
           AND r.status IN ('registered', 'confirmed')
         ORDER BY r.participant_name ASC"
    );

    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('Attendance export error: ' . $ex->getMessage());
    http_response_code(500);
    exit('Unable to generate export.');
}

// Build a safe filename from the event title.
$slug = preg_replace('/[^a-z0-9]+/i', '_', $event['title']);
$slug = trim((string) $slug, '_');
$slug = $slug !== '' ? $slug : 'event';
$filename = 'attendance_' . $slug . '_' . date('Ymd') . '.csv';

// Send CSV headers.
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

if ($out === false) {
    http_response_code(500);
    exit('Unable to open output stream.');
}

// UTF-8 BOM so Excel displays special characters correctly.
fwrite($out, "\xEF\xBB\xBF");

// Metadata header block.
fputcsv($out, ['AIC Kabartegan Region Youth Connect']);
fputcsv($out, ['Attendance Report']);
fputcsv($out, []);
fputcsv($out, ['Event', $event['title']]);
fputcsv($out, [
    'Date',
    date('d M Y, H:i', strtotime($event['start_datetime']))
]);
fputcsv($out, ['Venue', $event['venue'] ?: 'Not specified']);
fputcsv($out, ['Event Status', ucfirst($event['status'])]);
fputcsv($out, ['Exported', date('d M Y, H:i')]);
fputcsv($out, []);
fputcsv($out, []);

// Column headers.
fputcsv($out, [
    '#',
    'Reference',
    'Participant Name',
    'Email',
    'Phone',
    'Church',
    'Type',
    'Registration Status',
    'Attendance',
    'Marked By',
    'Marked At',
]);

// Reusable helper to neutralise spreadsheet formula injection.
$safeText = function (string $value): string {
    if ($value !== '' && preg_match('/^[\s]*[=+\-@]/u', $value)) {
        return "'" . $value;
    }
    return $value;
};

// Rows.
$num = 0;

foreach ($rows as $row) {
    $num++;

    $attendance = $row['attendance_status'];
    $statusLabel = $attendance === null
        ? 'Unmarked'
        : ucfirst((string) $attendance);

    $markedAt = '';
    if (!empty($row['marked_at'])) {
        $ts = strtotime($row['marked_at']);
        $markedAt = $ts ? date('d M Y H:i', $ts) : '';
    }

    fputcsv($out, [
        $num,
        $safeText((string) ($row['registration_reference'] ?? '')),
        $safeText((string) ($row['participant_name'] ?? '')),
        $safeText((string) ($row['participant_email'] ?? '')),
        $safeText((string) ($row['participant_phone'] ?? '')),
        $safeText((string) ($row['church_name'] ?? '')),
        ucfirst((string) $row['participant_type']),
        ucfirst((string) $row['registration_status']),
        $statusLabel,
        $safeText((string) ($row['marked_by_name'] ?? '')),
        $markedAt,
    ]);
}

// Footer summary.
fputcsv($out, []);

$presentCount = 0;
$absentCount = 0;
$excusedCount = 0;
$unmarkedCount = 0;

foreach ($rows as $row) {
    switch ($row['attendance_status']) {
        case 'present': $presentCount++; break;
        case 'absent':  $absentCount++;  break;
        case 'excused': $excusedCount++; break;
        default:        $unmarkedCount++; break;
    }
}

$total = count($rows);

$markedCount = $presentCount + $absentCount + $excusedCount;
$presentPct = $markedCount > 0
    ? round(($presentCount / $markedCount) * 100, 1)
    : 0;

fputcsv($out, ['Summary']);
fputcsv($out, ['Registered Participants', $total]);
fputcsv($out, ['Present', $presentCount]);
fputcsv($out, ['Absent', $absentCount]);
fputcsv($out, ['Excused', $excusedCount]);
fputcsv($out, ['Unmarked', $unmarkedCount]);
fputcsv($out, ['Present % of Marked', $presentPct . '%']);

fclose($out);
exit;