<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Retrieve published upcoming events and their program categories.
$sql = "SELECT
            e.id,
            e.title,
            e.description,
            e.venue,
            e.start_datetime,
            e.end_datetime,
            e.poster_path,
            c.name AS category_name
        FROM events e
        INNER JOIN program_categories c
            ON e.category_id = c.id
        WHERE e.status = 'published'
          AND e.start_datetime >= NOW()
        ORDER BY e.start_datetime ASC";

$stmt = $pdo->query($sql);
$events = $stmt->fetchAll();

function escape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Retrieve recently completed events.
$pastSql = "
    SELECT
        e.id,
        e.title,
        e.venue,
        e.start_datetime,
        c.name AS category_name
    FROM events e
    INNER JOIN program_categories c
        ON e.category_id = c.id
    WHERE e.status = 'completed'
       OR (
           e.status = 'published'
           AND e.end_datetime < NOW()
       )
    ORDER BY e.start_datetime DESC
    LIMIT 12
";

$pastStmt = $pdo->query($pastSql);
$pastEvents = $pastStmt->fetchAll(PDO::FETCH_ASSOC);

// Determine feedback eligibility for the logged-in user.
$loggedIn = isset($_SESSION['user_id'], $_SESSION['role']);
$userId = $loggedIn ? (int) $_SESSION['user_id'] : null;
$sessionEmail = $_SESSION['user_email'] ?? $_SESSION['email'] ?? null;

$eligibleEventIds = [];   // event_id => true  (eligible to give feedback)
$submittedEventIds = [];  // event_id => true  (already submitted)

if ($loggedIn && !empty($pastEvents)) {
    $pastEventIds = array_map(
        function ($e) { return (int) $e['id']; },
        $pastEvents
    );

    $placeholders = implode(',', array_fill(0, count($pastEventIds), '?'));

    $eligStmt = $pdo->prepare(
        "SELECT
            r.event_id,
            r.id AS registration_id,
            ef.id AS feedback_id
         FROM registrations r
         INNER JOIN event_attendance ea
            ON ea.registration_id = r.id
           AND ea.event_id = r.event_id
         LEFT JOIN event_feedback ef
            ON ef.registration_id = r.id
           AND ef.event_id = r.event_id
         WHERE r.event_id IN ($placeholders)
           AND r.status IN ('registered', 'confirmed')
           AND ea.attendance_status = 'present'
           AND (
               r.user_id = ?
               OR (
                   r.user_id IS NULL
                   AND LOWER(r.participant_email) = LOWER(?)
               )
           )"
    );

    $params = array_merge($pastEventIds, [$userId, $sessionEmail ?? '']);
    $eligStmt->execute($params);

    foreach ($eligStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eid = (int) $row['event_id'];

        if (empty($row['feedback_id'])) {
            $eligibleEventIds[$eid] = true;
        } else {
            $submittedEventIds[$eid] = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upcoming Events | Youth Connect</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="navbar">
    <a href="index.php" class="brand">
        <div class="logo">YC</div>
        <div>
            <h2>Youth Connect</h2>
            <span>AIC Kabartegan Region</span>
        </div>
    </a>

    <nav>
        <a href="index.php">Home</a>
        <a href="index.php#about">About</a>
        <a href="index.php#programs">Programs</a>
        <a href="events.php">Events</a>
        <a href="index.php#contact">Contact</a>
    </nav>

    <a href="login.php" class="login-btn">Leadership Login</a>
</header>

<section class="events-hero">
    <span class="eyebrow">FAITH · FELLOWSHIP · GROWTH</span>
    <h1>Discover Our Events</h1>
    <p>
        Find upcoming camps, seminars, conferences and other
        opportunities to connect and grow together.
    </p>
</section>

<main class="events-section">
    <div class="events-heading">
        <div>
            <span class="eyebrow">SAVE YOUR PLACE</span>
            <h2>Upcoming Programs</h2>
        </div>
        <p>
            <?= count($events) ?> upcoming
            <?= count($events) === 1 ? 'event' : 'events' ?>
        </p>
    </div>

    <?php if (count($events) > 0): ?>
        <div class="events-grid">
            <?php foreach ($events as $event): ?>
                <article class="event-card">
                    <?php if (!empty($event['poster_path'])): ?>
                        <img
                            class="event-poster"
                            src="<?= escape($event['poster_path']) ?>"
                            alt="<?= escape($event['title']) ?>"
                            loading="lazy"
                        >
                    <?php else: ?>
                        <div class="event-placeholder">
                            <span>YOUTH CONNECT</span>
                            <strong><?= escape($event['category_name']) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="event-card-content">
                        <span class="event-category">
                            <?= escape($event['category_name']) ?>
                        </span>

                        <h3><?= escape($event['title']) ?></h3>

                        <p class="event-description">
                            <?= escape($event['description'] ?: 'Join us for this upcoming program.') ?>
                        </p>

                        <div class="event-meta">
                            <p>
                                <strong>Date:</strong>
                                <?= date('d M Y, H:i', strtotime($event['start_datetime'])) ?>
                            </p>

                            <?php if (!empty($event['venue'])): ?>
                                <p>
                                    <strong>Venue:</strong>
                                    <?= escape($event['venue']) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <a
                            href="register.php?event_id=<?= (int)$event['id'] ?>"
                            class="event-register-btn"
                        >
                            Register for Event →
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="events-empty">
            <div class="empty-icon">📅</div>
            <h3>No upcoming events yet</h3>
            <p>
                Our next programs will appear here once they have
                been published by the leadership team.
            </p>
            <a href="index.php" class="btn-primary">Return to Homepage</a>
        </div>
    <?php endif; ?>

<section class="past-events-section">
    <div class="events-heading">
        <div>
            <span class="eyebrow">YOUR VOICE MATTERS</span>
            <h2>Past Events & Feedback</h2>
        </div>
        <p>Help us improve future programs.</p>
    </div>

    <?php if (!empty($pastEvents)): ?>
        <div class="events-grid">
            <?php foreach ($pastEvents as $pastEvent): ?>
                <?php
                    $eid = (int) $pastEvent['id'];
                    $canGive = !empty($eligibleEventIds[$eid]);
                    $hasGiven = !empty($submittedEventIds[$eid]);
                ?>

                <article class="event-card">
                    <div class="event-card-content">
                        <span class="event-category">
                            <?= escape($pastEvent['category_name']) ?>
                        </span>

                        <h3><?= escape($pastEvent['title']) ?></h3>

                        <div class="event-meta">
                            <p>
                                <strong>Date:</strong>
                                <?= date(
                                    'd M Y',
                                    strtotime($pastEvent['start_datetime'])
                                ) ?>
                            </p>

                            <?php if (!empty($pastEvent['venue'])): ?>
                                <p>
                                    <strong>Venue:</strong>
                                    <?= escape($pastEvent['venue']) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <?php if (!$loggedIn): ?>
                            <a
                                href="login.php"
                                class="event-register-btn"
                            >
                                Sign in to give feedback →
                            </a>
                        <?php elseif ($hasGiven): ?>
                            <span class="event-register-btn" style="background:#dcfce7;color:#166534;cursor:default;">
                                ✓ Feedback submitted
                            </span>
                        <?php elseif ($canGive): ?>
                            <a
                                href="event_feedback.php?event_id=<?= $eid ?>"
                                class="event-register-btn"
                            >
                                Give Event Feedback →
                            </a>
                        <?php else: ?>
                            <span class="event-register-btn" style="background:#e5e7eb;color:#6b7280;cursor:not-allowed;">
                                Feedback not available
                            </span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="events-empty">
            <h3>No past events available yet</h3>
            <p>
                Completed programs will appear here so eligible
                participants can share their experiences.
            </p>
        </div>
    <?php endif; ?>
</section>

</main>

<footer id="contact">
    <div>
        <h3>AIC Kabartegan Region Youth Connect</h3>
        <p>Connecting People, Strengthening Faith, Building Community.</p>
    </div>
    <p class="copyright">
        &copy; <?= date('Y') ?> AIC Kabartegan Region Youth Connect.
        All rights reserved.
    </p>
</footer>

</body>
</html>