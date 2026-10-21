
<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Require a logged-in user
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$event_id || $event_id < 1) {
    exit('Invalid event selected. Please open the event from the events page.');
}

if (empty($_SESSION['feedback_csrf'])) {
    $_SESSION['feedback_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$event = null;
$registration = null;
$already_submitted = false;

try {
    // Get event details
    $stmt = $pdo->prepare("
        SELECT id, title, start_datetime, end_datetime, status
        FROM events
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        exit('Event not found.');
    }

    // Find this user's registration for the event.
    // A member's registration is linked through user_id.
    // A guest's registration is linked through participant_email.
    $stmt = $pdo->prepare("
        SELECT r.id
        FROM registrations r
        LEFT JOIN users u ON u.id = r.user_id
        INNER JOIN event_attendance ea
            ON ea.registration_id = r.id
            AND ea.event_id = r.event_id
        WHERE r.event_id = ?
          AND r.status IN ('registered', 'confirmed')
          AND ea.attendance_status = 'present'
          AND (
              r.user_id = ?
              OR (
                  r.user_id IS NULL
                  AND u.id IS NULL
                  AND LOWER(r.participant_email) = LOWER(?)
              )
          )
        LIMIT 1
    ");

    // The session email is used only for guest registrations.
    $session_email = $_SESSION['user_email']
        ?? $_SESSION['email']
        ?? '';

    $stmt->execute([$event_id, $user_id, $session_email]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($registration) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM event_feedback
            WHERE registration_id = ?
            LIMIT 1
        ");
        $stmt->execute([$registration['id']]);
        $already_submitted = (bool) $stmt->fetchColumn();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (!hash_equals($_SESSION['feedback_csrf'], $token)) {
            throw new RuntimeException(
                'Your session has expired. Refresh the page and try again.'
            );
        }

        if (!$registration) {
            throw new RuntimeException(
                'Feedback is available only to registered participants marked present.'
            );
        }

        if ($already_submitted) {
            throw new RuntimeException(
                'You have already submitted feedback for this event.'
            );
        }

        $organization = filter_input(
            INPUT_POST, 'organization_rating', FILTER_VALIDATE_INT
        );
        $venue = filter_input(
            INPUT_POST, 'venue_rating', FILTER_VALIDATE_INT
        );
        $program = filter_input(
            INPUT_POST, 'program_rating', FILTER_VALIDATE_INT
        );
        $overall = filter_input(
            INPUT_POST, 'overall_rating', FILTER_VALIDATE_INT
        );

        foreach ([$organization, $venue, $program, $overall] as $rating) {
            if ($rating === false || $rating === null ||
                $rating < 1 || $rating > 5) {
                throw new RuntimeException(
                    'Please select a rating from 1 to 5 for every question.'
                );
            }
        }

        $liked = trim($_POST['liked_most'] ?? '');
        $improvements = trim($_POST['improvements'] ?? '');
        $suggestions = trim($_POST['suggestions'] ?? '');

        if (mb_strlen($liked) > 5000 ||
            mb_strlen($improvements) > 5000 ||
            mb_strlen($suggestions) > 5000) {
            throw new RuntimeException(
                'Each written response must be 5,000 characters or fewer.'
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO event_feedback (
                event_id,
                registration_id,
                organization_rating,
                venue_rating,
                program_rating,
                overall_rating,
                liked_most,
                improvements,
                suggestions
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $event_id,
            $registration['id'],
            $organization,
            $venue,
            $program,
            $overall,
            $liked ?: null,
            $improvements ?: null,
            $suggestions ?: null
        ]);

        $already_submitted = true;
        $message = 'Thank you! Your feedback has been submitted successfully.';
    }
} catch (PDOException $e) {
    error_log('Event feedback database error: ' . $e->getMessage());
    $error = 'A database error occurred. Please try again later.';
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Feedback | Youth Connect</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            background: #f3f6fb;
            color: #263449;
            font-family: Arial, sans-serif;
        }

        .feedback-container {
            max-width: 760px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .08);
        }

        h1 {
            margin-top: 0;
            color: #173b6c;
        }

        .event-title {
            color: #49617d;
            margin-bottom: 25px;
        }

        .question {
            margin: 24px 0;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5eaf1;
        }

        .question label,
        .question legend {
            display: block;
            font-weight: bold;
            margin-bottom: 12px;
        }

        fieldset {
            border: 0;
            padding: 0;
            margin: 0;
        }

        .rating-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .rating-options label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 13px;
            border: 1px solid #d5deea;
            border-radius: 8px;
            cursor: pointer;
            font-weight: normal;
            margin: 0;
        }

        .rating-options label:has(input:checked) {
            border-color: #245ca6;
            background: #edf4ff;
        }

        textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font: inherit;
            resize: vertical;
        }

        .submit-btn {
            width: 100%;
            border: none;
            background: #174a87;
            color: #fff;
            padding: 14px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
        }

        .submit-btn:hover {
            background: #103764;
        }

        .notice {
            padding: 14px;
            border-radius: 8px;
            margin: 18px 0;
            line-height: 1.5;
        }

        .success {
            background: #e7f7ed;
            color: #176438;
        }

        .error {
            background: #fff0f0;
            color: #9a2424;
        }

        .info {
            background: #edf4ff;
            color: #244b7e;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #174a87;
            text-decoration: none;
        }

        @media (max-width: 600px) {
            .feedback-container {
                padding: 20px;
            }

            .rating-options {
                gap: 8px;
            }
        }
    </style>
</head>

<body>
<div class="feedback-container">
    <h1>Event Feedback</h1>

    <?php if ($event): ?>
        <p class="event-title">
            <strong>Event:</strong>
            <?= e($event['title']) ?>
        </p>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="notice success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$registration): ?>
        <div class="notice info">
            Feedback is available to registered participants who were
            marked present at this event. If you attended but cannot
            access this form, contact the event leadership to check
            your attendance record.
        </div>

    <?php elseif ($already_submitted): ?>
        <div class="notice success">
            Your feedback for this event has already been recorded.
            Thank you for helping us improve future programs.
        </div>

    <?php else: ?>
        <p>
            Please share your honest experience. Your feedback will
            help the leadership improve future youth events.
        </p>

        <p><strong>Rating guide:</strong>
            1 = Poor, 2 = Fair, 3 = Good, 4 = Very Good, 5 = Excellent
        </p>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['feedback_csrf']) ?>">

            <?php
            $questions = [
                'organization_rating' => 'How was the event organization?',
                'venue_rating' => 'How was the venue?',
                'program_rating' => 'How was the program content?',
                'overall_rating' => 'How would you rate the event overall?'
            ];
            ?>

            <?php foreach ($questions as $field => $question): ?>
                <div class="question">
                    <fieldset>
                        <legend><?= e($question) ?></legend>

                        <div class="rating-options">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <label>
                                    <input
                                        type="radio"
                                        name="<?= e($field) ?>"
                                        value="<?= $i ?>"
                                        required>
                                    <?= $i ?>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </fieldset>
                </div>
            <?php endforeach; ?>

            <div class="question">
                <label for="liked_most">
                    What did you like most about the event?
                </label>
                <textarea id="liked_most" name="liked_most"
                          maxlength="5000"></textarea>
            </div>

            <div class="question">
                <label for="improvements">
                    What could we improve?
                </label>
                <textarea id="improvements" name="improvements"
                          maxlength="5000"></textarea>
            </div>

            <div class="question">
                <label for="suggestions">
                    What would you like to see in future events?
                </label>
                <textarea id="suggestions" name="suggestions"
                          maxlength="5000"></textarea>
            </div>

            <button class="submit-btn" type="submit">
                Submit Feedback
            </button>
        </form>
    <?php endif; ?>

    <a class="back-link" href="events.php">
        &larr; Back to Events
    </a>
</div>
</body>
</html>
