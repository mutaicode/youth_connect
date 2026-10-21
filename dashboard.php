<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/charts.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

// Verify the user's account is still active.
$stmt = $pdo->prepare(
    'SELECT id, full_name, email, role, account_status
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute(['id' => (int) $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || $user['account_status'] !== 'active') {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['role'] = $user['role'];
$_SESSION['full_name'] = $user['full_name'];

$roles = [
    'admin' => 'System Administrator',
    'chairperson' => 'Chairperson',
    'vice_chairperson' => 'Vice Chairperson',
    'secretary' => 'Secretary',
    'vice_secretary' => 'Vice Secretary',
    'treasurer' => 'Treasurer',
    'member' => 'Youth Member'
];

$roleName = $roles[$user['role']] ?? 'User';

// Dashboard statistics.
$totalUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE account_status = 'active'"
)->fetchColumn();

$totalEvents = (int) $pdo->query(
    "SELECT COUNT(*) FROM events WHERE status = 'published'"
)->fetchColumn();

$upcomingEvents = (int) $pdo->query(
    "SELECT COUNT(*) FROM events
     WHERE status = 'published' AND start_datetime >= NOW()"
)->fetchColumn();

$totalRegistrations = (int) $pdo->query(
    "SELECT COUNT(*) FROM registrations
     WHERE status IN ('registered', 'confirmed')"
)->fetchColumn();

// Load upcoming events for the dashboard.
$stmt = $pdo->query(
    "SELECT e.title, e.venue, e.start_datetime, c.name AS category_name
     FROM events e
     INNER JOIN program_categories c ON c.id = e.category_id
     WHERE e.status = 'published'
       AND e.start_datetime >= NOW()
     ORDER BY e.start_datetime ASC
     LIMIT 5"
);
$events = $stmt->fetchAll();
// -------------------------------------------------------------
// Chart data — registrations per event (top 6)
// -------------------------------------------------------------
$chartEvents = $pdo->query(
    "SELECT
        e.id,
        e.title,
        COUNT(r.id) AS registration_count
     FROM events e
     LEFT JOIN registrations r
        ON r.event_id = e.id
       AND r.status IN ('registered', 'confirmed')
     WHERE e.status IN ('published', 'completed')
     GROUP BY e.id, e.title
     HAVING registration_count > 0
     ORDER BY registration_count DESC
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

$eventBars = [];
foreach ($chartEvents as $row) {
    $eventBars[] = [
        'label' => $row['title'],
        'value' => (int) $row['registration_count'],
    ];
}

// -------------------------------------------------------------
// Chart data — attendance for the most recent event
// -------------------------------------------------------------
$recentEvent = $pdo->query(
    "SELECT id, title
     FROM events
     WHERE status IN ('published', 'completed')
     ORDER BY start_datetime DESC
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$attendanceSlices = [];
$attendanceTotal = 0;

if ($recentEvent) {
    $attStmt = $pdo->prepare(
        "SELECT
            r.id AS reg_id,
            ea.attendance_status
         FROM registrations r
         LEFT JOIN event_attendance ea
            ON ea.registration_id = r.id
           AND ea.event_id = r.event_id
         WHERE r.event_id = ?
           AND r.status IN ('registered', 'confirmed')"
    );
    $attStmt->execute([(int) $recentEvent['id']]);
    $attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['present' => 0, 'absent' => 0, 'excused' => 0, 'unmarked' => 0];
    foreach ($attRows as $a) {
        $st = $a['attendance_status'];
        if ($st === null || $st === '') {
            $counts['unmarked']++;
        } elseif (isset($counts[$st])) {
            $counts[$st]++;
        }
    }

    $attendanceTotal = array_sum($counts);

    $attendanceSlices = [
        ['label' => 'Present',  'value' => $counts['present'],  'color' => '#16a34a'],
        ['label' => 'Absent',   'value' => $counts['absent'],   'color' => '#dc2626'],
        ['label' => 'Excused',  'value' => $counts['excused'],  'color' => '#f59e0b'],
        ['label' => 'Unmarked', 'value' => $counts['unmarked'], 'color' => '#94a3b8'],
    ];
}

$recentEventTitle = $recentEvent['title'] ?? '';

$isAdmin = $user['role'] === 'admin';
$isLeadership = in_array(
    $user['role'],
    [
        'chairperson',
        'vice_chairperson',
        'secretary',
        'vice_secretary',
        'treasurer'
    ],
    true
);
$isFinancial = in_array(
    $user['role'],
    ['chairperson', 'secretary', 'treasurer'],
    true
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Youth Connect</title>
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f7f4;
            color: #243329;
            font-family: Arial, sans-serif;
        }

        .layout {
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            position: fixed;
            inset: 0 auto 0 0;
            overflow-y: auto;
            padding: 25px 16px;
            background: #123d27;
            color: white;
        }

        .brand {
            padding: 0 10px 25px;
            border-bottom: 1px solid #ffffff30;
        }

        .brand h2 {
            margin: 0 0 7px;
            color: #f1cf72;
            font-size: 21px;
        }

        .brand p {
            margin: 0;
            font-size: 12px;
            color: #d9e8dd;
        }

        .nav-label {
            margin: 24px 10px 10px;
            color: #c2d3c6;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .sidebar a {
            display: block;
            padding: 12px;
            margin: 5px 0;
            border-radius: 7px;
            color: #fff;
            text-decoration: none;
            font-size: 14px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #246640;
        }

        .main {
            margin-left: 250px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 28px;
        }

        .topbar h1 {
            margin: 0 0 8px;
            color: #17472d;
        }

        .topbar p {
            margin: 0;
            color: #66736a;
        }

        .role {
            display: inline-block;
            margin-top: 10px;
            padding: 7px 12px;
            border-radius: 20px;
            background: #e0eee3;
            color: #14532d;
            font-size: 13px;
            font-weight: bold;
        }

        .logout {
            display: inline-block;
            background: #8d2e2e;
            color: white;
            text-decoration: none;
            padding: 11px 16px;
            border-radius: 7px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }

        .stat-card,
        .panel {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 4px 16px #00000008;
        }

        .stat-card p {
            margin: 0 0 12px;
            color: #68756c;
            font-size: 14px;
        }

        .stat-card h2 {
            margin: 0;
            color: #14532d;
            font-size: 30px;
        }

        .panel {
            margin-top: 25px;
            overflow-x: auto;
        }

        .panel h2 {
            margin-top: 0;
            color: #17472d;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }

        th, td {
            padding: 13px 10px;
            text-align: left;
            border-bottom: 1px solid #e8ede8;
            font-size: 14px;
        }

        th {
            color: #506154;
            background: #f7f9f7;
        }

        .quick-links {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .quick-links a {
            padding: 12px 15px;
            background: #14532d;
            color: white;
            text-decoration: none;
            border-radius: 7px;
        }

        .quick-links a:hover {
            background: #267044;
        }

        .empty {
            color: #68756c;
            padding: 10px 0;
        }

        @media (max-width: 1000px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .sidebar {
                position: static;
                width: 100%;
                max-height: none;
            }

            .main {
                margin-left: 0;
                padding: 18px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="layout">

    <aside class="sidebar">
        <div class="brand">
            <h2>Youth Connect</h2>
            <p>AIC Kabartegan Region</p>
        </div>

        <p class="nav-label">Main Menu</p>
        <a class="active" href="dashboard.php">Dashboard</a>
        <a href="events.php">View Public Events</a>
        <a href="index.php">Visit Website</a>
        <a href="manage_registrations.php">Manage Registrations</a>
        <a href="attendance.php">📊 Attendance</a>
        <a href="attendance_reports.php">📈 Attendance Reports</a>

        <?php if ($isAdmin): ?>
            <p class="nav-label">Administration</p>
            <a href="manage_users.php">Manage Users</a>
            <a href="members.php">👥 Members</a>
            <a href="manage_churches.php">⛪ Churches</a>
            <a href="manage_events.php">Manage Events</a>
            <a href="manage_announcements.php">Announcements</a>
            <a href="manage_feedback.php">📝 Event Feedback</a>
            <a href="member_directory.php">📇 Member Directory</a>
            <a href="vbs/admin_dashboard.php" style="background: #14532d; color: #fbbf24; margin-top: 8px; font-weight: bold;">
    🏖️ VBS Module
</a>
        <?php endif; ?>

        <?php if ($isLeadership): ?>
            <p class="nav-label">Leadership</p>
            <a href="leadership_records.php">Leadership Records</a>
            <a href="program_budgets.php">💰 Program Budgets</a>
            <a href="manage_feedback.php">📝 Event Feedback</a>
            <a href="member_directory.php">📇 Member Directory</a>
            <a href="vbs/admin_dashboard.php" style="background: #14532d; color: #fbbf24; margin-top: 8px; font-weight: bold;">
    🏖️ VBS Module
</a>
        <?php endif; ?>

        <?php if ($isFinancial): ?>
            <p class="nav-label">Financial</p>
            <a href="financial_records.php">Financial Records</a>
            <a href="financial_dashboard.php">Financial Dashboard</a>
            <a href="financial_audit.php">🔍 Audit Trail</a>
        <?php endif; ?>

                <p class="nav-label">Account</p>
        <a href="change_password.php">🔐 Change Password</a>
        <a href="logout.php">Logout</a>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <h1>Dashboard</h1>
                <p>
                    Welcome,
                    <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <span class="role">
                    <?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <a class="logout" href="logout.php">Sign Out</a>
        </header>

        <section class="stats">
            <?php if ($isAdmin): ?>
                <div class="stat-card">
                    <p>Active Users</p>
                    <h2><?= $totalUsers ?></h2>
                </div>

                <div class="stat-card">
                    <p>Published Events</p>
                    <h2><?= $totalEvents ?></h2>
                </div>

                <div class="stat-card">
                    <p>Upcoming Events</p>
                    <h2><?= $upcomingEvents ?></h2>
                </div>

                <div class="stat-card">
                    <p>Registrations</p>
                    <h2><?= $totalRegistrations ?></h2>
                </div>
            <?php else: ?>
                <div class="stat-card">
                    <p>Published Events</p>
                    <h2><?= $totalEvents ?></h2>
                </div>

                <div class="stat-card">
                    <p>Upcoming Events</p>
                    <h2><?= $upcomingEvents ?></h2>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($isAdmin): ?>
            <section class="panel">
                <h2>Management Shortcuts</h2>
                <div class="quick-links">
                    <a href="manage_users.php">Manage Users</a>
                    <a href="members.php">Members</a>
                    <a href="manage_churches.php">Churches</a>
                    <a href="manage_events.php">Manage Events</a>
                    <a href="manage_registrations.php">Manage Registrations</a>
                    <a href="manage_announcements.php">Manage Announcements</a>
                </div>
            </section>
        <?php endif; ?>
                <!-- Charts row -->
        <?php if ($isAdmin || $isLeadership): ?>
            <?= chart_styles() ?>

            <div class="chart-grid">
                <?= render_bar_chart($eventBars, [
                    'title'    => 'Top Events by Registrations',
                    'unit'     => 'registrations',
                    'color'    => '#1769aa',
                    'max_bars' => 6,
                    'empty'    => 'No event registrations yet.',
                ]) ?>

                <?= render_donut_chart($attendanceSlices, [
                    'title'        => 'Attendance: ' . ($recentEventTitle ?: 'Most Recent Event'),
                    'center'       => $attendanceTotal > 0 ? number_format($attendanceTotal) : '',
                    'center_label' => $attendanceTotal > 0 ? 'participants' : '',
                    'empty'        => 'No event participants yet.',
                ]) ?>
            </div>
        <?php endif; ?>


        <section class="panel">
            <h2>Upcoming Events</h2>

            <?php if (count($events) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Program</th>
                            <th>Venue</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($event['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($event['venue'] ?: 'To be announced', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= date('d M Y, H:i', strtotime($event['start_datetime'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">
                    No upcoming published events. Create and publish an event to see it here.
                </p>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>