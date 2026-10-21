<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/charts.php';

/* Check access permissions */
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

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* Read filters */
$church_id = filter_input(INPUT_GET, 'church_id', FILTER_VALIDATE_INT);
$event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$church_id || $church_id < 1) {
    $church_id = 0;
}

if (!$event_id || $event_id < 1) {
    $event_id = 0;
}

$churches = [];
$events = [];
$participants = [];

$stats = [
    'total' => 0,
    'present' => 0,
    'absent' => 0,
    'excused' => 0
];

$report_error = '';

try {
    /* Load church filter options */
    $stmt = $pdo->query(
        "SELECT id, church_name
         FROM churches
         ORDER BY church_name ASC"
    );

    $churches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Load event filter options */
    $stmt = $pdo->query(
        "SELECT id, title, start_datetime
         FROM events
         ORDER BY start_datetime DESC"
    );

    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Each row represents an event registration.
     * A participant without an attendance record is shown as unmarked,
     * rather than incorrectly counted as absent.
     */
    $sql = "
        SELECT
            r.id AS registration_id,
            r.participant_name,
            r.participant_email,
            r.participant_phone,
            r.participant_type,
            r.registration_reference,
            r.status AS registration_status,
            c.id AS church_id,
            c.church_name,
            ev.id AS event_id,
            ev.title AS event_title,
            ev.start_datetime,
            ea.attendance_status,
            ea.marked_at,
            marker.full_name AS marked_by_name
        FROM registrations r
        INNER JOIN events ev
            ON ev.id = r.event_id
        LEFT JOIN churches c
            ON c.id = r.church_id
        LEFT JOIN event_attendance ea
            ON ea.registration_id = r.id
           AND ea.event_id = r.event_id
        LEFT JOIN users marker
            ON marker.id = ea.marked_by
        WHERE r.status IN ('registered', 'confirmed')
    ";

    $params = [];

    if ($church_id > 0) {
        $sql .= " AND r.church_id = :church_id";
        $params['church_id'] = $church_id;
    }

    if ($event_id > 0) {
        $sql .= " AND r.event_id = :event_id";
        $params['event_id'] = $event_id;
    }

    $sql .= "
        ORDER BY
            c.church_name ASC,
            ev.start_datetime DESC,
            r.participant_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Calculate report statistics */
    foreach ($participants as $participant) {
        $stats['total']++;

        if ($participant['attendance_status'] === 'present') {
            $stats['present']++;
        } elseif ($participant['attendance_status'] === 'absent') {
            $stats['absent']++;
        } elseif ($participant['attendance_status'] === 'excused') {
            $stats['excused']++;
        }
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $report_error = 'Unable to load the report. Please check the database and try again.';
}
// -------------------------------------------------------------
// Chart data — attendance status per event
// -------------------------------------------------------------
$eventBreakdownSql = "
    SELECT
        ev.id,
        ev.title,
        SUM(CASE WHEN ea.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN ea.attendance_status = 'absent'  THEN 1 ELSE 0 END) AS absent_count,
        SUM(CASE WHEN ea.attendance_status = 'excused' THEN 1 ELSE 0 END) AS excused_count,
        SUM(CASE WHEN ea.attendance_status IS NULL      THEN 1 ELSE 0 END) AS unmarked_count,
        COUNT(*) AS total
    FROM registrations r
    INNER JOIN events ev ON ev.id = r.event_id
    LEFT JOIN event_attendance ea
        ON ea.registration_id = r.id
       AND ea.event_id = r.event_id
    WHERE r.status IN ('registered', 'confirmed')
";

$breakdownParams = [];

if ($church_id > 0) {
    $eventBreakdownSql .= " AND r.church_id = ?";
    $breakdownParams[] = $church_id;
}

if ($event_id > 0) {
    $eventBreakdownSql .= " AND r.event_id = ?";
    $breakdownParams[] = $event_id;
}

$eventBreakdownSql .= "
    GROUP BY ev.id, ev.title
    HAVING total > 0
    ORDER BY ev.start_datetime DESC
    LIMIT 8
";

$breakdownStmt = $pdo->prepare($eventBreakdownSql);
$breakdownStmt->execute($breakdownParams);
$eventBreakdown = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

/* Only marked attendance counts in the percentage denominator */
$marked_total = $stats['present'] + $stats['absent'] + $stats['excused'];

$attendance_percentage = $marked_total > 0
    ? round(($stats['present'] / $marked_total) * 100, 1)
    : 0;

/* Get selected filter labels */
$selected_church_name = 'All Churches';
$selected_event_name = 'All Events';

foreach ($churches as $church) {
    if ((int) $church['id'] === $church_id) {
        $selected_church_name = $church['church_name'];
        break;
    }
}

foreach ($events as $event) {
    if ((int) $event['id'] === $event_id) {
        $selected_event_name = $event['title'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Attendance Reports | Youth Connect</title>
        <link rel="stylesheet" href="assets/css/print.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #263247;
        }

        .topbar {
            background: #153e75;
            color: white;
            padding: 22px 5%;
        }

        .topbar h1 {
            margin: 0 0 7px;
            font-size: 25px;
        }

        .topbar p {
            margin: 0;
            color: #dce8f8;
        }

        .container {
            width: min(1250px, 94%);
            margin: 28px auto;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #153e75;
            text-decoration: none;
            font-weight: bold;
        }

        .panel {
            background: white;
            padding: 22px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 14px rgba(25, 45, 80, .06);
        }

        .panel h2 {
            margin-top: 0;
            font-size: 20px;
        }

        .filters {
            display: grid;
            grid-template-columns: 1fr 1fr auto auto;
            gap: 14px;
            align-items: end;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 7px;
        }

        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5e2;
            border-radius: 7px;
            background: white;
            font-size: 14px;
        }

        .btn {
            display: inline-block;
            border: none;
            border-radius: 7px;
            padding: 12px 16px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
            text-align: center;
        }

        .btn-primary {
            background: #17633c;
            color: white;
        }

        .btn-secondary {
            background: #e8eef7;
            color: #153e75;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 11px;
            box-shadow: 0 3px 12px rgba(25, 45, 80, .05);
        }

        .stat-card span {
            display: block;
            color: #68758b;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .stat-card strong {
            color: #153e75;
            font-size: 26px;
        }

        .report-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            flex-wrap: wrap;
        }

        .report-heading p {
            color: #68758b;
            font-size: 14px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        th, td {
            padding: 13px 11px;
            border-bottom: 1px solid #e5eaf1;
            text-align: left;
            font-size: 13px;
        }

        th {
            background: #f5f8fc;
            color: #526079;
            font-size: 12px;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        .present {
            background: #e0f6e9;
            color: #17633c;
        }

        .absent {
            background: #fde8e8;
            color: #a12626;
        }

        .excused {
            background: #fff0d5;
            color: #8b5a00;
        }

        .unmarked {
            background: #e8eef7;
            color: #526079;
        }

        .error {
            background: #fde8e8;
            color: #a12626;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .empty {
            padding: 25px;
            text-align: center;
            color: #68758b;
        }

        .print-header {
            display: none;
        }

        @media (max-width: 900px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filters {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 550px) {
            .stats, .filters {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 16px;
            }

            .topbar h1 {
                font-size: 21px;
            }
        }

        @media print {
            body {
                background: white;
                color: black;
                font-size: 10px;
            }

            .topbar {
                background: white;
                color: black;
                padding: 0 0 12px;
                border-bottom: 2px solid #333;
            }

            .topbar p {
                color: black;
            }

            .container {
                width: 100%;
                margin: 12px 0;
            }

            .back-link,
            .filters-panel,
            .no-print {
                display: none !important;
            }

            .print-header {
                display: block;
                margin-bottom: 15px;
            }

            .panel, .stat-card {
                box-shadow: none;
                border: 1px solid #ddd;
                padding: 10px;
                break-inside: avoid;
            }

            .stats {
                grid-template-columns: repeat(5, 1fr);
                gap: 7px;
            }

            .stat-card strong {
                font-size: 17px;
            }

            .table-wrap {
                overflow: visible;
            }

            table {
                min-width: 0;
                width: 100%;
            }

            th, td {
                padding: 6px 4px;
                font-size: 9px;
                overflow-wrap: anywhere;
            }

            tr {
                break-inside: avoid;
            }

            @page {
                size: landscape;
                margin: 12mm;
            }
        }
        /* ---- Stacked attendance bar chart ---- */
.stacked-chart {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 20px 0;
}

.stacked-row {
    display: grid;
    grid-template-columns: 160px minmax(0, 1fr) 120px;
    gap: 12px;
    align-items: center;
}

.stacked-label {
    font-size: 13px;
    color: #334155;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.stacked-track {
    display: flex;
    height: 24px;
    border-radius: 5px;
    overflow: hidden;
    background: #eef2f7;
}

.stacked-seg {
    height: 100%;
    transition: width .35s ease;
}

.stacked-seg.present  { background: #16a34a; }
.stacked-seg.absent   { background: #dc2626; }
.stacked-seg.excused  { background: #f59e0b; }
.stacked-seg.unmarked { background: #94a3b8; }

.stacked-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    font-size: 13px;
    margin-bottom: 14px;
}

.stacked-legend span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.stacked-legend .dot {
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

@media (max-width: 600px) {
    .stacked-row { grid-template-columns: 1fr; }
    .stacked-label { grid-column: 1 / -1; }
}

@media print {
    .stacked-seg { transition: none; }
}
    </style>
</head>

<body>

<header class="topbar">
    <h1>Attendance Reports</h1>
    <p>AIC Kabartegan Region Youth Connect</p>
</header>

<main class="container">
    <main class="container">

    <!-- PRINT HEADER -->
    <div class="print-header">
        <h1>AIC Kabartegan Region Youth Connect</h1>
        <p><strong>Attendance Report</strong></p>
        <p>Church: <?= e($selected_church_name) ?> · Event: <?= e($selected_event_name) ?></p>
        <p>Generated: <?= date('d M Y, H:i A') ?></p>
    </div>

    <a class="back-link no-print" href="dashboard.php"></a>

    <a class="back-link no-print" href="dashboard.php">
        &larr; Back to Dashboard
    </a>

    <?php if ($report_error !== ''): ?>

        <div class="error"><?= e($report_error) ?></div>

    <?php else: ?>

        <section class="panel filters-panel no-print">
            <h2>Filter Attendance</h2>

            <form method="GET" action="attendance_reports.php" class="filters">

                <div class="form-group">
                    <label for="church_id">Local Church</label>

                    <select name="church_id" id="church_id">
                        <option value="0">All Churches</option>

                        <?php foreach ($churches as $church): ?>
                            <option
                                value="<?= (int) $church['id'] ?>"
                                <?= $church_id === (int) $church['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($church['church_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="event_id">Event</label>

                    <select name="event_id" id="event_id">
                        <option value="0">All Events</option>

                        <?php foreach ($events as $event): ?>
                            <option
                                value="<?= (int) $event['id'] ?>"
                                <?= $event_id === (int) $event['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($event['title']) ?>
                                — <?= date(
                                    'd M Y',
                                    strtotime($event['start_datetime'])
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    Generate Report
                </button>

                <a href="attendance_reports.php" class="btn btn-secondary">
                    Clear Filters
                </a>

            </form>
        </section>

        <section class="stats">
            <div class="stat-card">
                <span>Registered Participants</span>
                <strong><?= $stats['total'] ?></strong>
            </div>

            <div class="stat-card">
                <span>Present</span>
                <strong><?= $stats['present'] ?></strong>
            </div>

            <div class="stat-card">
                <span>Absent</span>
                <strong><?= $stats['absent'] ?></strong>
            </div>

            <div class="stat-card">
                <span>Excused</span>
                <strong><?= $stats['excused'] ?></strong>
            </div>

            <div class="stat-card">
                <span>Present % of Marked</span>
                <strong><?= $attendance_percentage ?>%</strong>
            </div>
        </section>
                <?php if (!empty($eventBreakdown)): ?>
            <section class="panel">
                <h2>Attendance by Event</h2>

                <div class="stacked-legend">
                    <span><span class="dot" style="background:#16a34a;"></span>Present</span>
                    <span><span class="dot" style="background:#dc2626;"></span>Absent</span>
                    <span><span class="dot" style="background:#f59e0b;"></span>Excused</span>
                    <span><span class="dot" style="background:#94a3b8;"></span>Unmarked</span>
                </div>

                <div class="stacked-chart">
                    <?php foreach ($eventBreakdown as $row): ?>
                        <?php
                        $rowTotal = max(1, (int) $row['total']);
                        $p = ((int) $row['present_count']  / $rowTotal) * 100;
                        $a = ((int) $row['absent_count']   / $rowTotal) * 100;
                        $e = ((int) $row['excused_count']  / $rowTotal) * 100;
                        $u = ((int) $row['unmarked_count'] / $rowTotal) * 100;
                        ?>
                        <div class="stacked-row">
                            <div class="stacked-label" title="<?= e($row['title']) ?>">
                                <?= e($row['title']) ?>
                            </div>
                            <div class="stacked-track"
                                 title="Present: <?= (int) $row['present_count'] ?>, Absent: <?= (int) $row['absent_count'] ?>, Excused: <?= (int) $row['excused_count'] ?>, Unmarked: <?= (int) $row['unmarked_count'] ?>">
                                <?php if ($p > 0): ?>
                                    <div class="stacked-seg present" style="width:<?= round($p, 2) ?>%;"></div>
                                <?php endif; ?>
                                <?php if ($a > 0): ?>
                                    <div class="stacked-seg absent" style="width:<?= round($a, 2) ?>%;"></div>
                                <?php endif; ?>
                                <?php if ($e > 0): ?>
                                    <div class="stacked-seg excused" style="width:<?= round($e, 2) ?>%;"></div>
                                <?php endif; ?>
                                <?php if ($u > 0): ?>
                                    <div class="stacked-seg unmarked" style="width:<?= round($u, 2) ?>%;"></div>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:12px;color:#14532d;font-weight:bold;text-align:right;">
                                <?= (int) $row['total'] ?> total
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="report-heading">
                <div>
                    <h2>Participant Attendance</h2>

                    <p>
                        Church: <strong><?= e($selected_church_name) ?></strong>
                        <br>
                        Event: <strong><?= e($selected_event_name) ?></strong>
                    </p>

                    <p>
                        Unmarked participants are not counted as absent
                        until attendance is recorded.
                    </p>
                </div>
                <?php if ($event_id > 0): ?>
    <a
        href="attendance_export.php?event_id=<?= (int) $event_id ?>"
        class="btn btn-secondary no-print"
        style="margin-right:8px;"
    >
        ⬇️ Export CSV
    </a>
<?php endif; ?>

                <button
    type="button"
    class="btn btn-primary no-print"
    onclick="window.print()"
>
    🖨️ Print Report
</button>
            </div>

            <?php if (empty($participants)): ?>

                <div class="empty">
                    No registrations match your selected filters.
                </div>

            <?php else: ?>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Participant</th>
                                <th>Church</th>
                                <th>Event</th>
                                <th>Event Date</th>
                                <th>Type</th>
                                <th>Attendance</th>
                                <th>Marked By</th>
                                <th>Marked At</th>
                                <th>Reference</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($participants as $index => $p): ?>
                                <?php
                                    $attendance = $p['attendance_status'];

                                    if ($attendance === null) {
                                        $status_label = 'Unmarked';
                                        $status_class = 'unmarked';
                                    } else {
                                        $status_label = ucfirst($attendance);
                                        $status_class = $attendance;
                                    }
                                ?>

                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <strong><?= e($p['participant_name']) ?></strong>
                                        <br>
                                        <?= e($p['participant_email']) ?>
                                    </td>

                                    <td>
                                        <?= e($p['church_name'] ?? 'Not assigned') ?>
                                    </td>

                                    <td><?= e($p['event_title']) ?></td>

                                    <td>
                                        <?= date(
                                            'd M Y, H:i',
                                            strtotime($p['start_datetime'])
                                        ) ?>
                                    </td>

                                    <td><?= e(ucfirst($p['participant_type'])) ?></td>

                                    <td>
                                        <span class="status <?= e($status_class) ?>">
                                            <?= e($status_label) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= e($p['marked_by_name'] ?? '—') ?>
                                    </td>

                                    <td>
                                        <?= $p['marked_at']
                                            ? e(date(
                                                'd M Y H:i',
                                                strtotime($p['marked_at'])
                                            ))
                                            : '—' ?>
                                    </td>

                                    <td><?= e($p['registration_reference']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </section>

    <?php endif; ?>

</main>

</body>
</html>