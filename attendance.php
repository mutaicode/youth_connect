<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Allow only authorized leadership roles.
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
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Escape output before displaying it in HTML.
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Create a CSRF token for secure form submissions.
if (empty($_SESSION['attendance_csrf'])) {
    $_SESSION['attendance_csrf'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['attendance_csrf'];

$message = '';
$error = '';

$selected_event = filter_input(
    INPUT_GET,
    'event_id',
    FILTER_VALIDATE_INT
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($posted_token) ||
        !hash_equals($csrf_token, $posted_token)
    ) {
        $error = 'Security validation failed. Refresh the page and try again.';
    } else {
        $posted_event = filter_input(
            INPUT_POST,
            'event_id',
            FILTER_VALIDATE_INT
        );

        $attendance = $_POST['attendance'] ?? [];

        if (
            !$posted_event ||
            !is_array($attendance)
        ) {
            $error = 'Please select a valid event and attendance records.';
        } else {
            try {
                // Confirm the event exists.
                $event_check = $pdo->prepare(
                    "SELECT id FROM events WHERE id = ?"
                );
                $event_check->execute([$posted_event]);

                if (!$event_check->fetch()) {
                    $error = 'The selected event does not exist.';
                } else {
                    $pdo->beginTransaction();

                    $save = $pdo->prepare(
                        "INSERT INTO event_attendance
                            (event_id, registration_id, user_id,
                             attendance_status, marked_by)
                         SELECT r.event_id, r.id, r.user_id, ?, ?
                         FROM registrations r
                         WHERE r.id = ?
                           AND r.event_id = ?
                           AND r.status IN ('registered', 'confirmed')
                         ON DUPLICATE KEY UPDATE
                            attendance_status = VALUES(attendance_status),
                            marked_by = VALUES(marked_by),
                            marked_at = CURRENT_TIMESTAMP"
                    );

                    foreach ($attendance as $registration_id => $status) {
                        if (
                            !ctype_digit((string) $registration_id) ||
                            !in_array(
                                $status,
                                ['present', 'absent', 'excused'],
                                true
                            )
                        ) {
                            throw new RuntimeException(
                                'An invalid attendance value was submitted.'
                            );
                        }

                        $save->execute([
                            $status,
                            $user_id,
                            (int) $registration_id,
                            $posted_event
                        ]);

                        // A row count of zero can be valid for an unchanged
                        // record, so verify the registration separately.
                        $verify = $pdo->prepare(
                            "SELECT id FROM registrations
                             WHERE id = ?
                               AND event_id = ?
                               AND status IN ('registered', 'confirmed')"
                        );
                        $verify->execute([
                            (int) $registration_id,
                            $posted_event
                        ]);

                        if (!$verify->fetch()) {
                            throw new RuntimeException(
                                'A participant does not belong to this event '
                                . 'or is no longer registered.'
                            );
                        }
                    }

                    $pdo->commit();

                    header(
                        'Location: attendance.php?event_id='
                        . $posted_event . '&saved=1'
                    );
                    exit;
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Keep technical details out of the page.
                error_log('Attendance save error: ' . $ex->getMessage());
                $error = 'Attendance could not be saved. Please check your '
                    . 'database configuration and try again.';
            }
        }

        if ($posted_event) {
            $selected_event = $posted_event;
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = 'Attendance saved successfully!';
}

// Initialize variables before database queries.
$events = [];
$participants = [];

$selected_event_data = null;

$stats = [
    'total' => 0,
    'present' => 0,
    'absent' => 0,
    'excused' => 0
];

$attendance_percentage = 0;

try {
    // Load available events.
    $events_stmt = $pdo->query(
        "SELECT id, title, start_datetime, venue, status
         FROM events
         ORDER BY start_datetime DESC"
    );

    $events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($selected_event) {
        $event_stmt = $pdo->prepare(
            "SELECT id, title, start_datetime, venue, status
             FROM events
             WHERE id = ?"
        );
        $event_stmt->execute([$selected_event]);
        $selected_event_data = $event_stmt->fetch(PDO::FETCH_ASSOC);

        if ($selected_event_data) {
            // Include members and guests. Exclude cancelled registrations.
            $participants_stmt = $pdo->prepare(
                "SELECT
                    r.id AS registration_id,
                    r.participant_name,
                    r.participant_email,
                    r.participant_phone,
                    r.participant_type,
                    r.status AS registration_status,
                    a.attendance_status
                 FROM registrations r
                 LEFT JOIN event_attendance a
                    ON a.registration_id = r.id
                    AND a.event_id = r.event_id
                 WHERE r.event_id = ?
                   AND r.status IN ('registered', 'confirmed')
                 ORDER BY r.participant_name ASC"
            );

            $participants_stmt->execute([$selected_event]);
            $participants = $participants_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($participants as &$participant) {
                // Treat unmarked participants as absent until marked.
                $status = $participant['attendance_status'] ?? 'absent';
                $participant['attendance_status'] = $status;

                $stats['total']++;

                if (isset($stats[$status])) {
                    $stats[$status]++;
                }
            }
            unset($participant);
        } else {
            $error = 'The selected event could not be found.';
        }
    }
} catch (Throwable $ex) {
    error_log('Attendance page error: ' . $ex->getMessage());

    $events = $events ?? [];
    $participants = $participants ?? [];
    $selected_event_data = $selected_event_data ?? null;

    $error = 'Unable to load attendance information. Please check the '
        . 'database connection and table structure.';
}

$attendance_percentage = $stats['total'] > 0
    ? round(($stats['present'] / $stats['total']) * 100, 1)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Attendance | Youth Connect</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f6fb;
            color: #263247;
            font-family: Arial, sans-serif;
        }

        .topbar {
            background: #173b68;
            color: white;
            padding: 22px 5%;
        }

        .topbar h1 {
            margin: 0 0 7px;
            font-size: 25px;
        }

        .topbar p {
            margin: 0;
            color: #dbe8f8;
        }

        .container {
            max-width: 1250px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .panel {
            background: white;
            padding: 23px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(20, 40, 70, .06);
        }

        .panel h2 {
            margin-top: 0;
            font-size: 20px;
        }

        .event-form {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
        }

        .field {
            flex: 1;
            min-width: 220px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        select,
        button {
            width: 100%;
            padding: 12px;
            border-radius: 7px;
            font-size: 15px;
        }

        select {
            border: 1px solid #ccd5e1;
            background: white;
        }

        button {
            border: none;
            background: #1769aa;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #105487;
        }

        .event-form button {
            width: auto;
            min-width: 130px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat {
            padding: 20px;
            background: white;
            border-radius: 10px;
            border-left: 4px solid #1769aa;
            box-shadow: 0 3px 12px rgba(20, 40, 70, .05);
        }

        .stat span {
            display: block;
            color: #637187;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .stat strong {
            font-size: 27px;
        }

        .success,
        .error,
        .notice {
            padding: 14px;
            margin-bottom: 20px;
            border-radius: 8px;
        }

        .success {
            background: #e4f8eb;
            color: #17643a;
        }

        .error {
            background: #ffebeb;
            color: #a52222;
        }

        .notice {
            background: #eaf3ff;
            color: #244d7c;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th,
        td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #e7ecf3;
        }

        th {
            background: #f5f8fc;
            font-size: 13px;
            color: #4a5a70;
        }

        td {
            font-size: 14px;
        }

        .type {
            display: inline-block;
            padding: 5px 9px;
            background: #edf2f9;
            border-radius: 20px;
            text-transform: capitalize;
        }

        .status-select {
            min-width: 130px;
        }

        .save-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .save-row button {
            width: auto;
            min-width: 190px;
        }

        .back-link {
            display: inline-block;
            margin-top: 16px;
            color: #1769aa;
            text-decoration: none;
        }

        .empty {
            padding: 25px;
            text-align: center;
            color: #68768b;
        }

        /* ---- Bulk toolbar ---- */
        .attendance-toolbar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 14px 16px;
            margin-bottom: 16px;
            background: #f5f8fc;
            border: 1px solid #dde5ef;
            border-radius: 10px;
        }

        .attendance-toolbar .tb-label {
            color: #4a5a70;
            font-size: 13px;
            font-weight: bold;
            margin-right: 4px;
        }

        .attendance-toolbar button {
            width: auto;
            padding: 9px 14px;
            font-size: 13px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .attendance-toolbar .btn-all-present {
            background: #166534;
        }
        .attendance-toolbar .btn-all-present:hover {
            background: #14532d;
        }

        .attendance-toolbar .btn-all-absent {
            background: #b91c1c;
        }
        .attendance-toolbar .btn-all-absent:hover {
            background: #991b1b;
        }

        .attendance-toolbar .btn-unmarked {
            background: #475569;
        }
        .attendance-toolbar .btn-unmarked:hover {
            background: #334155;
        }

        .attendance-toolbar .btn-clear {
            background: #94a3b8;
        }
        .attendance-toolbar .btn-clear:hover {
            background: #64748b;
        }

        .attendance-toolbar .live-counter {
            margin-left: auto;
            font-size: 13px;
            color: #14532d;
            font-weight: bold;
        }

        .attendance-toolbar .live-counter.warn {
            color: #b45309;
        }

        @media (max-width: 800px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .attendance-toolbar .live-counter {
                margin-left: 0;
                flex-basis: 100%;
            }
        }

        @media (max-width: 480px) {
            .stats {
                grid-template-columns: 1fr 1fr;
                gap: 9px;
            }

            .stat {
                padding: 14px;
            }

            .stat strong {
                font-size: 22px;
            }

            .container {
                padding: 0 12px;
            }

            .panel {
                padding: 16px;
            }

            .event-form button,
            .save-row button {
                width: 100%;
            }

            .attendance-toolbar button {
                flex: 1;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <h1>Event Attendance Management</h1>
    <p>AIC Kabartegan Region Youth Connect</p>
</header>

<main class="container">

    <?php if ($message): ?>
        <div class="success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Select Event</h2>

        <form method="GET" action="attendance.php" class="event-form">
            <div class="field">
                <label for="event_id">Choose an event</label>

                <select name="event_id" id="event_id" required>
                    <option value="">-- Select an event --</option>

                    <?php foreach ($events as $event): ?>
                        <option
                            value="<?= (int) $event['id'] ?>"
                            <?= (string) $selected_event === (string) $event['id']
                                ? 'selected' : '' ?>
                        >
                            <?= e($event['title']) ?>
                            (<?= e(date(
                                'd M Y',
                                strtotime($event['start_datetime'])
                            )) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit">View Participants</button>
        </form>
    </section>

    <?php if ($selected_event_data): ?>

        <section class="panel">
            <h2><?= e($selected_event_data['title']) ?></h2>

            <p>
                <strong>Date:</strong>
                <?= e(date(
                    'd M Y, H:i',
                    strtotime($selected_event_data['start_datetime'])
                )) ?>
            </p>

            <p>
                <strong>Venue:</strong>
                <?= e($selected_event_data['venue'] ?: 'Not specified') ?>
            </p>

            <p>
                <strong>Event status:</strong>
                <?= e(ucfirst($selected_event_data['status'])) ?>
            </p>
        </section>

        <section class="stats">
            <div class="stat">
                <span>Registered Participants</span>
                <strong><?= $stats['total'] ?></strong>
            </div>

            <div class="stat">
                <span>Present</span>
                <strong><?= $stats['present'] ?></strong>
            </div>

            <div class="stat">
                <span>Absent</span>
                <strong><?= $stats['absent'] ?></strong>
            </div>

            <div class="stat">
                <span>Attendance Rate</span>
                <strong><?= $attendance_percentage ?>%</strong>
            </div>
        </section>

        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
                <h2 style="margin:0;">Mark Participant Attendance</h2>
                <a
                    href="attendance_export.php?event_id=<?= (int) $selected_event ?>"
                    style="display:inline-block;padding:10px 14px;background:#1769aa;color:#fff;text-decoration:none;border-radius:7px;font-size:14px;font-weight:bold;"
                >
                    ⬇️ Export CSV
                </a>
            </div>

            <?php if (count($participants) > 0): ?>

                <div class="notice">
                    Select the correct status for every participant, then
                    click Save Attendance. Use the quick tools below to speed
                    up marking.
                </div>

                <form method="POST" action="attendance.php?event_id=<?= (int) $selected_event ?>" id="attendance-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf_token) ?>"
                    >

                    <input
                        type="hidden"
                        name="event_id"
                        value="<?= (int) $selected_event ?>"
                    >

                    <!-- ===== BULK TOOLBAR ===== -->
                    <div class="attendance-toolbar">
                        <span class="tb-label">Quick tools:</span>

                        <button
                            type="button"
                            class="btn-all-present"
                            onclick="applyAll('present')"
                        >
                            ✓ Mark All Present
                        </button>

                        <button
                            type="button"
                            class="btn-all-absent"
                            onclick="applyAll('absent')"
                        >
                            ✕ Mark All Absent
                        </button>

                        <button
                            type="button"
                            class="btn-unmarked"
                            onclick="applyUnmarked('present')"
                        >
                            Mark Unmarked as Present
                        </button>

                        <button
                            type="button"
                            class="btn-clear"
                            onclick="clearAll()"
                        >
                            ↺ Reset
                        </button>

                        <span class="live-counter" id="live-counter">
                            Present: 0 / <?= count($participants) ?>
                        </span>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Participant</th>
                                    <th>Email</th>
                                    <th>Type</th>
                                    <th>Attendance Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($participants as $index => $participant): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>

                                        <td>
                                            <strong>
                                                <?= e($participant['participant_name']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= e($participant['participant_email']) ?>
                                        </td>

                                        <td>
                                            <span class="type">
                                                <?= e($participant['participant_type']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <select
                                                class="status-select"
                                                name="attendance[<?= (int) $participant['registration_id'] ?>]"
                                                required
                                            >
                                                <option
                                                    value="present"
                                                    <?= $participant['attendance_status'] === 'present'
                                                        ? 'selected' : '' ?>
                                                >
                                                    Present
                                                </option>

                                                <option
                                                    value="absent"
                                                    <?= $participant['attendance_status'] === 'absent'
                                                        ? 'selected' : ''
                                                    ?>
                                                >
                                                    Absent
                                                </option>

                                                <option
                                                    value="excused"
                                                    <?= $participant['attendance_status'] === 'excused'
                                                        ? 'selected' : ''
                                                    ?>
                                                >
                                                    Excused
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="save-row">
                        <span>
                            Participants listed: <strong><?= count($participants) ?></strong>
                        </span>

                        <button type="submit">
                            Save Attendance
                        </button>
                    </div>
                </form>

            <?php else: ?>

                <div class="empty">
                    No registered or confirmed participants were found for
                    this event.
                </div>

            <?php endif; ?>
        </section>

    <?php elseif ($selected_event): ?>

        <div class="notice">
            Select a valid event to view its participants.
        </div>

    <?php else: ?>

        <section class="panel empty">
            Select an event above to view its registered participants
            and manage attendance.
        </section>

    <?php endif; ?>

    <a class="back-link" href="dashboard.php">
        &larr; Back to Dashboard
    </a>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('attendance-form');

    if (!form) {
        return;
    }

    var selects = form.querySelectorAll('.status-select');
    var counter = document.getElementById('live-counter');
    var total = selects.length;

    function updateCounter() {
        if (!counter) return;

        var present = 0;
        var unmarked = 0;

        selects.forEach(function (sel) {
            if (sel.value === 'present') present++;
            // "Unmarked" no longer exists as an option, so we track
            // only present count for the live indicator.
        });

        counter.textContent = 'Present: ' + present + ' / ' + total;

        if (present === total) {
            counter.classList.remove('warn');
        } else {
            counter.classList.add('warn');
        }
    }

    function applyAll(status) {
        selects.forEach(function (sel) {
            sel.value = status;
        });
        updateCounter();
    }

    function applyUnmarked(status) {
        selects.forEach(function (sel) {
            // "Unmarked" = whatever the DB had. Since we default
            // unmarked participants to 'absent' on the server, we
            // can't distinguish. Instead, only touch participants
            // whose dropdown is still at the default first option
            // (i.e., 'present' if the default was present, or
            // 'absent' if the default was absent). To keep this
            // predictable, we use the data-original attribute.
            if (sel.dataset.original === 'absent') {
                sel.value = status;
            }
        });
        updateCounter();
    }

    function clearAll() {
        selects.forEach(function (sel) {
            sel.value = sel.dataset.original || 'absent';
        });
        updateCounter();
    }

    // Attach the original value so Reset can restore it.
    selects.forEach(function (sel) {
        var initial = sel.querySelector('option[selected]');
        sel.dataset.original = initial ? initial.value : 'absent';
        sel.addEventListener('change', updateCounter);
    });

    updateCounter();

    // Confirm before leaving with unsaved changes
    var formDirty = false;
    form.addEventListener('change', function () {
        formDirty = true;
    });

    form.addEventListener('submit', function () {
        formDirty = false;
    });

    window.addEventListener('beforeunload', function (e) {
        if (formDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Expose the toolbar functions globally
    window.applyAll = applyAll;
    window.applyUnmarked = applyUnmarked;
    window.clearAll = clearAll;
});
</script>

</body>
</html>