
<?php
session_start();
require_once __DIR__ . '/config/database.php';

function escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Require a logged-in member.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'member'
) {
    header('Location: login.php');
    exit;
}

// Verify that the member's account still exists and is active.
$stmt = $pdo->prepare(
    "SELECT id, full_name, email, phone, account_status, created_at
     FROM users
     WHERE id = :id AND role = 'member'
     LIMIT 1"
);
$stmt->execute(['id' => (int) $_SESSION['user_id']]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member || $member['account_status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

$_SESSION['full_name'] = $member['full_name'];
$userId = (int) $member['id'];

// Get upcoming published events.
$eventsStmt = $pdo->query(
    "SELECT id, title, description, venue, start_datetime,
            registration_deadline, capacity, allow_guests
     FROM events
     WHERE status = 'published'
       AND start_datetime >= NOW()
       AND (
           registration_deadline IS NULL
           OR registration_deadline >= NOW()
       )
     ORDER BY start_datetime ASC
     LIMIT 6"
);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get this member's recent registrations, attendance and feedback status.
$registrationsStmt = $pdo->prepare(
    "SELECT r.id AS registration_id,
            r.event_id,
            r.registration_reference,
            r.status AS registration_status,
            r.participant_name,
            e.title,
            e.start_datetime,
            e.venue,
            ea.attendance_status,
            ef.id AS feedback_id
     FROM registrations r
     INNER JOIN events e ON e.id = r.event_id
     LEFT JOIN event_attendance ea
        ON ea.registration_id = r.id
        AND ea.event_id = r.event_id
     LEFT JOIN event_feedback ef
        ON ef.registration_id = r.id
        AND ef.event_id = r.event_id
     WHERE r.user_id = :user_id
     ORDER BY e.start_datetime DESC
     LIMIT 10"
);
$registrationsStmt->execute(['user_id' => $userId]);
$registrations = $registrationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Count this member's registrations.
$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM registrations
     WHERE user_id = :user_id"
);
$countStmt->execute(['user_id' => $userId]);
$totalRegistrations = (int) $countStmt->fetchColumn();

// Get the five most recent published announcements.
$announcementsStmt = $pdo->query(
    "SELECT a.id, a.title, a.content, a.published_at,
            a.created_at, u.full_name AS author
     FROM announcements a
     LEFT JOIN users u ON u.id = a.created_by
     WHERE a.status = 'published'
     ORDER BY COALESCE(a.published_at, a.created_at) DESC,
              a.id DESC
     LIMIT 5"
);
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Member Dashboard | Youth Connect</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        body {
            margin: 0;
            background: #f4f7fb;
            color: #26364b;
        }

        .member-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            padding: 16px 5%;
            background: #14532d;
            color: white;
        }

        .member-nav h2 {
            margin: 0;
            font-size: 21px;
        }

        .member-nav p {
            margin: 4px 0 0;
            font-size: 13px;
            color: #dcfce7;
        }

        .member-nav a {
            color: white;
            text-decoration: none;
        }

        .member-nav-links {
            display: flex;
            gap: 18px;
            align-items: center;
            flex-wrap: wrap;
        }

        .member-nav .logout-link {
            background: #ffffff;
            color: #14532d;
            padding: 9px 14px;
            border-radius: 7px;
            font-weight: 600;
        }

        .member-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .member-welcome {
            margin-bottom: 25px;
        }

        .member-welcome h1 {
            margin-bottom: 8px;
            color: #183153;
        }

        .member-welcome p {
            color: #64748b;
            line-height: 1.6;
        }

        .member-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .member-stat,
        .member-panel {
            background: white;
            padding: 22px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .04);
        }

        .member-stat p {
            color: #64748b;
            margin: 0 0 10px;
        }

        .member-stat h2 {
            color: #14532d;
            margin: 0;
            font-size: 25px;
        }

        .member-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }

        .member-panel {
            margin-bottom: 22px;
            min-width: 0;
        }

        .member-panel h2 {
            color: #183153;
            margin-top: 0;
            font-size: 21px;
        }

        .profile-row {
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            overflow-wrap: anywhere;
        }

        .profile-row:last-child {
            border-bottom: none;
        }

        .profile-row strong {
            display: block;
            margin-bottom: 5px;
            color: #64748b;
            font-size: 13px;
        }

        .member-event {
            padding: 16px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .member-event:last-child {
            border-bottom: none;
        }

        .member-event h3 {
            color: #14532d;
            margin: 0 0 8px;
            font-size: 17px;
        }

        .member-event p {
            color: #64748b;
            margin: 6px 0;
            line-height: 1.5;
        }

        .member-button {
            display: inline-block;
            padding: 10px 14px;
            margin-top: 8px;
            border-radius: 7px;
            background: #14532d;
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        .member-button:hover {
            background: #166534;
        }

        /* Announcements */
        .announcement-card {
            padding: 18px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .announcement-card:first-of-type {
            padding-top: 5px;
        }

        .announcement-card:last-child {
            border-bottom: none;
        }

        .announcement-card h3 {
            color: #14532d;
            margin: 0 0 10px;
            overflow-wrap: anywhere;
        }

        .announcement-content {
            color: #475569;
            line-height: 1.7;
            overflow-wrap: anywhere;
            white-space: normal;
        }

        .announcement-meta {
            display: block;
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
            margin-top: 12px;
        }

        .announcement-label {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .member-table-wrap {
            overflow-x: auto;
        }

        .member-table {
            width: 100%;
            min-width: 580px;
            border-collapse: collapse;
        }

        .member-table th,
        .member-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .member-table th {
            background: #f8fafc;
        }

        .member-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #e2e8f0;
            color: #334155;
            font-size: 12px;
            white-space: nowrap;
        }

        .member-empty {
            color: #64748b;
            line-height: 1.6;
        }

        .member-footer {
            padding: 22px 15px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 800px) {
            .member-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 550px) {
            .member-container {
                padding: 20px 12px;
            }

            .member-stats {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .member-stat,
            .member-panel {
                padding: 17px;
            }

            .member-nav {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<header class="member-nav">
    <div>
        <a href="member_dashboard.php">
            <h2>Youth Connect</h2>
        </a>
        <p>AIC Kabartegan Region</p>
    </div>

    <nav class="member-nav-links">
        <a href="index.php">Home</a>
        <a href="events.php">All Events</a>
        <a href="member_profile.php">My Profile</a>
        <a class="logout-link" href="logout.php">Logout</a>
    </nav>
</header>

<main class="member-container">

    <section class="member-welcome">
        <h1>
            Welcome, <?= escape($member['full_name']) ?>!
        </h1>

        <p>
            Welcome to your Youth Connect member dashboard.
            Stay informed about church announcements, upcoming activities,
            and your event participation.
        </p>
    </section>

    <!-- Dashboard statistics -->
    <section class="member-stats">

        <article class="member-stat">
            <p>Account Status</p>
            <h2><?= escape(ucfirst($member['account_status'])) ?></h2>
        </article>

        <article class="member-stat">
            <p>My Registrations</p>
            <h2><?= $totalRegistrations ?></h2>
        </article>

        <article class="member-stat">
            <p>Upcoming Events</p>
            <h2><?= count($events) ?></h2>
        </article>

    </section>

    <!-- Latest announcements -->
    <section class="member-panel">
        <h2>📢 Church Announcements</h2>

        <p class="member-empty">
            Stay updated with the latest news and activities from
            AIC Kabartegan Region Youth Connect.
        </p>

        <?php if (empty($announcements)): ?>

            <p class="member-empty">
                There are no published announcements at the moment.
                Please check again later.
            </p>

        <?php else: ?>

            <?php foreach ($announcements as $announcement): ?>

                <article class="announcement-card">

                    <span class="announcement-label">
                        Announcement
                    </span>

                    <h3>
                        <?= escape($announcement['title']) ?>
                    </h3>

                    <div class="announcement-content"><?=
                        nl2br(escape($announcement['content']))
                    ?></div>

                    <small class="announcement-meta">
                        Published:
                        <?= escape(
                            $announcement['published_at']
                                ?? $announcement['created_at']
                        ) ?>

                        <?php if (!empty($announcement['author'])): ?>
                            <br>
                            Posted by:
                            <?= escape($announcement['author']) ?>
                        <?php endif; ?>
                    </small>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>
    </section>

    <div class="member-grid">

        <!-- Member profile -->
        <section class="member-panel">
            <h2>My Profile</h2>

            <div class="profile-row">
                <strong>Full Name</strong>
                <?= escape($member['full_name']) ?>
            </div>

            <div class="profile-row">
                <strong>Email Address</strong>
                <?= escape($member['email']) ?>
            </div>

            <div class="profile-row">
                <strong>Phone Number</strong>
                <?= escape($member['phone'] ?: 'Not provided') ?>
            </div>

            <div class="profile-row">
                <strong>Member Since</strong>
                <?= !empty($member['created_at'])
                    ? escape(date(
                        'd M Y',
                        strtotime($member['created_at'])
                    ))
                    : 'Not available' ?>
            </div>

            <a href="member_profile.php" class="member-button">
                Update My Profile
            </a>
        </section>

        <!-- Upcoming events -->
        <section class="member-panel">
            <h2>Upcoming Events</h2>

            <?php if (!$events): ?>

                <p class="member-empty">
                    There are no upcoming published events available right now.
                    Please check again later.
                </p>

            <?php else: ?>

                <?php foreach ($events as $event): ?>

                    <article class="member-event">
                        <h3><?= escape($event['title']) ?></h3>

                        <p>
                            <strong>Date:</strong>
                            <?= escape(date(
                                'd M Y, H:i',
                                strtotime($event['start_datetime'])
                            )) ?>
                        </p>

                        <?php if (!empty($event['venue'])): ?>
                            <p>
                                <strong>Venue:</strong>
                                <?= escape($event['venue']) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($event['description'])): ?>
                            <p><?= escape($event['description']) ?></p>
                        <?php endif; ?>

                        <a
                            href="register.php?event_id=<?= (int)$event['id'] ?>"
                            class="member-button"
                        >
                            Register for Event
                        </a>
                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

            <a href="events.php" class="member-button">
                View All Events
            </a>
        </section>

    </div>

    <!-- Registration history -->
    <section class="member-panel">
        <h2>My Event Registrations</h2>

        <?php if (!$registrations): ?>

            <p class="member-empty">
                You haven't registered for any events yet.
                Explore the upcoming events above to get started.
            </p>

        <?php else: ?>

            <div class="member-table-wrap">
                <table class="member-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Date</th>
                            <th>Venue</th>
                            <th>Registration Status</th>
                            <th>Reference</th>
                            <th>Feedback</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($registrations as $registration): ?>
                            <tr>
                                <td>
                                    <?= escape($registration['title']) ?>
                                </td>

                                <td>
                                    <?= escape(date(
                                        'd M Y, H:i',
                                        strtotime($registration['start_datetime'])
                                    )) ?>
                                </td>

                                <td>
                                    <?= escape(
                                        $registration['venue'] ?: 'Not specified'
                                    ) ?>
                                </td>

                                <td>
                                    <span class="member-badge">
                                        <?= escape(ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $registration['registration_status']
                                            )
                                        )) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= escape(
                                        $registration['registration_reference']
                                    ) ?>
                                </td>

                                <td>
                                    <?php if (!empty($registration['feedback_id'])): ?>

                                        <span class="member-badge"
                                              style="background:#dcfce7;color:#166534;">
                                            Feedback Submitted
                                        </span>

                                    <?php elseif (
                                        $registration['attendance_status'] === 'present'
                                    ): ?>

                                        <a
                                            href="event_feedback.php?event_id=<?= (int)$registration['event_id'] ?>"
                                            class="member-button"
                                        >
                                            ★ Give Feedback
                                        </a>

                                    <?php else: ?>

                                        <span class="member-badge">
                                            Not Yet Eligible
                                        </span>

                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>

</main>

<footer class="member-footer">
    <p>
        &copy; <?= date('Y') ?> AIC Kabartegan Region Youth Connect.
    </p>
    <p>Connecting People, Strengthening Faith, Building Community.</p>
</footer>

</body>
</html>
