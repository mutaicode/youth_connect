
<?php
session_start();
require_once __DIR__ . '/config/database.php';

$allowed_roles = [
    'admin',
    'chairperson',
    'vice_chairperson',
    'secretary',
    'vice_secretary'
];

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], $allowed_roles, true)
) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view event feedback.');
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$events = [];
$feedback = [];
$stats = [
    'responses' => 0,
    'overall' => 0,
    'organization' => 0,
    'venue' => 0,
    'program' => 0
];
$error = '';

$event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$event_id = ($event_id && $event_id > 0) ? $event_id : 0;

try {
    // Event dropdown
    $stmt = $pdo->query("
        SELECT id, title, start_datetime
        FROM events
        ORDER BY start_datetime DESC
    ");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Feedback summary
    $sqlStats = "
        SELECT
            COUNT(*) AS responses,
            ROUND(AVG(overall_rating), 2) AS overall,
            ROUND(AVG(organization_rating), 2) AS organization,
            ROUND(AVG(venue_rating), 2) AS venue,
            ROUND(AVG(program_rating), 2) AS program
        FROM event_feedback
    ";

    if ($event_id > 0) {
        $sqlStats .= " WHERE event_id = ?";
        $stmt = $pdo->prepare($sqlStats);
        $stmt->execute([$event_id]);
    } else {
        $stmt = $pdo->query($sqlStats);
    }

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $stats = array_merge($stats, $result);
    }

    // Detailed feedback
    $sql = "
        SELECT
            f.id,
            f.event_id,
            f.registration_id,
            f.organization_rating,
            f.venue_rating,
            f.program_rating,
            f.overall_rating,
            f.liked_most,
            f.improvements,
            f.suggestions,
            f.created_at,
            ev.title AS event_title
        FROM event_feedback f
        INNER JOIN events ev ON ev.id = f.event_id
    ";

    if ($event_id > 0) {
        $sql .= " WHERE f.event_id = ?";
    }

    $sql .= " ORDER BY f.created_at DESC";

    if ($event_id > 0) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$event_id]);
    } else {
        $stmt = $pdo->query($sql);
    }

    $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log('Manage feedback error: ' . $ex->getMessage());
    $error = 'Unable to load feedback at the moment. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Event Feedback Reports | Youth Connect</title>
<link rel="stylesheet" href="assets/css/style.css">

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 25px;
    background: #f3f6fb;
    color: #263449;
    font-family: Arial, sans-serif;
}

.container {
    max-width: 1250px;
    margin: auto;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

h1 {
    color: #173b6c;
    margin-bottom: 6px;
}

.muted {
    color: #66758a;
}

.filter {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 25px;
}

select, button {
    padding: 11px 14px;
    border-radius: 7px;
    border: 1px solid #cbd5e1;
    font-size: 14px;
}

button {
    background: #174a87;
    color: white;
    cursor: pointer;
    border: none;
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 4px 14px rgba(0,0,0,.05);
}

.card p {
    color: #66758a;
    margin: 0 0 10px;
}

.card h2 {
    margin: 0;
    color: #174a87;
    font-size: 27px;
}

.feedback-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    margin-bottom: 18px;
    box-shadow: 0 4px 14px rgba(0,0,0,.05);
    overflow-wrap: anywhere;
}

.feedback-card h3 {
    color: #173b6c;
    margin-top: 0;
}

.ratings {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin: 18px 0;
}

.rating {
    padding: 12px;
    background: #edf4ff;
    border-radius: 8px;
}

.rating strong {
    display: block;
    margin-bottom: 6px;
}

.comment {
    margin-top: 15px;
    line-height: 1.6;
}

.comment h4 {
    margin-bottom: 5px;
    color: #174a87;
}

.notice {
    padding: 15px;
    border-radius: 8px;
    background: #fff0f0;
    color: #9a2424;
    margin-bottom: 20px;
}

.empty {
    background: white;
    border-radius: 12px;
    padding: 35px;
    text-align: center;
}

@media (max-width: 600px) {
    body {
        padding: 15px;
    }

    .feedback-card {
        padding: 18px;
    }
}

@media print {
    body {
        background: white;
        padding: 0;
    }

    .filter, .no-print {
        display: none !important;
    }

    .card, .feedback-card {
        box-shadow: none;
        border: 1px solid #ddd;
        break-inside: avoid;
    }
}
</style>
</head>

<body>
<div class="container">
    <div class="container">

    <!-- PRINT HEADER -->
    <div class="print-header">
        <h1>AIC Kabartegan Region Youth Connect</h1>
        <p><strong>Event Feedback Report</strong></p>
        <p>Event: <?= $event_id > 0 ? h($events[$event_id]['title'] ?? 'Selected') : 'All Events' ?></p>
        <p>Generated: <?= date('d M Y, H:i A') ?></p>
    </div>

    <div class="header no-print"></div>
    </div>
    <div class="header no-print">
    <div>
        <h1>Event Feedback Reports</h1>
        <p class="muted">Review youth feedback and improve future programs.</p>
    </div>
    <div style="display:flex;gap:10px;">
        <button type="button" onclick="window.print()" class="btn btn-primary no-print">
            🖨️ Print Report
        </button>
    </div>
</div>

    <?php if ($error): ?>
        <div class="notice"><?= e($error) ?></div>
    <?php else: ?>

        <form method="GET" class="filter no-print">
            <select name="event_id">
                <option value="0">All Events</option>

                <?php foreach ($events as $event): ?>
                    <option
                        value="<?= (int) $event['id'] ?>"
                        <?= $event_id === (int) $event['id']
                            ? 'selected' : '' ?>>
                        <?= e($event['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Filter Feedback</button>
        </form>

        <div class="cards">
            <div class="card">
                <p>Total Responses</p>
                <h2><?= (int) $stats['responses'] ?></h2>
            </div>

            <div class="card">
                <p>Average Overall Rating</p>
                <h2>
                    <?= $stats['responses'] > 0
                        ? number_format((float) $stats['overall'], 2)
                        : '—' ?>
                    <small>/ 5</small>
                </h2>
            </div>

            <div class="card">
                <p>Organization</p>
                <h2>
                    <?= $stats['responses'] > 0
                        ? number_format((float) $stats['organization'], 2)
                        : '—' ?>
                    <small>/ 5</small>
                </h2>
            </div>

            <div class="card">
                <p>Venue</p>
                <h2>
                    <?= $stats['responses'] > 0
                        ? number_format((float) $stats['venue'], 2)
                        : '—' ?>
                    <small>/ 5</small>
                </h2>
            </div>

            <div class="card">
                <p>Program Content</p>
                <h2>
                    <?= $stats['responses'] > 0
                        ? number_format((float) $stats['program'], 2)
                        : '—' ?>
                    <small>/ 5</small>
                </h2>
            </div>
        </div>

        <h2>Youth Responses</h2>

        <?php if (empty($feedback)): ?>
            <div class="empty">
                <h3>No feedback yet</h3>
                <p class="muted">
                    Responses will appear here after youths submit feedback.
                </p>
            </div>
        <?php else: ?>

            <?php foreach ($feedback as $item): ?>
                <article class="feedback-card">
                    <h3><?= e($item['event_title']) ?></h3>

                    <p class="muted">
                        Submitted:
                        <?= e(date(
                            'd M Y, H:i',
                            strtotime($item['created_at'])
                        )) ?>
                    </p>

                    <div class="ratings">
                        <div class="rating">
                            <strong>Organization</strong>
                            <?= (int) $item['organization_rating'] ?> / 5
                        </div>

                        <div class="rating">
                            <strong>Venue</strong>
                            <?= (int) $item['venue_rating'] ?> / 5
                        </div>

                        <div class="rating">
                            <strong>Program</strong>
                            <?= (int) $item['program_rating'] ?> / 5
                        </div>

                        <div class="rating">
                            <strong>Overall</strong>
                            <?= (int) $item['overall_rating'] ?> / 5
                        </div>
                    </div>

                    <div class="comment">
                        <h4>What they liked</h4>
                        <p><?= nl2br(e(
                            $item['liked_most'] ?: 'No response provided.'
                        )) ?></p>
                    </div>

                    <div class="comment">
                        <h4>Areas to improve</h4>
                        <p><?= nl2br(e(
                            $item['improvements'] ?: 'No response provided.'
                        )) ?></p>
                    </div>

                    <div class="comment">
                        <h4>Suggestions for future events</h4>
                        <p><?= nl2br(e(
                            $item['suggestions'] ?: 'No response provided.'
                        )) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>

        <?php endif; ?>
    <?php endif; ?>

</div>
</body>
</html>
