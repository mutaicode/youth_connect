<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/poster_upload.php';

// Require a valid, active administrator account.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];

$auth = $pdo->prepare(
    'SELECT role, account_status FROM users WHERE id = :id LIMIT 1'
);
$auth->execute(['id' => $adminId]);
$currentUser = $auth->fetch();

if (
    !$currentUser ||
    $currentUser['role'] !== 'admin' ||
    $currentUser['account_status'] !== 'active'
) {
    http_response_code(403);
    exit('Access denied. An active system administrator account is required.');
}

$statuses = [
    'draft' => 'Draft',
    'pending' => 'Pending',
    'published' => 'Published',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

$message = '';
$error = '';

if (empty($_SESSION['events_csrf'])) {
    $_SESSION['events_csrf'] = bin2hex(random_bytes(32));
}

// Load active program categories.
$categories = $pdo->query(
    'SELECT id, name FROM program_categories
     WHERE is_active = 1
     ORDER BY name ASC'
)->fetchAll();

// Process create and update forms.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['events_csrf'], $token)
    ) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } else {
        try {
            $action = $_POST['action'] ?? '';

            if (!in_array($action, ['create', 'update'], true)) {
                throw new RuntimeException('Invalid form action.');
            }

            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $venue = trim($_POST['venue'] ?? '');
            $categoryId = filter_var(
                $_POST['category_id'] ?? '',
                FILTER_VALIDATE_INT
            );
            $start = trim($_POST['start_datetime'] ?? '');
            $end = trim($_POST['end_datetime'] ?? '');
            $deadline = trim($_POST['registration_deadline'] ?? '');
            $capacityInput = trim($_POST['capacity'] ?? '');
            $allowGuests = isset($_POST['allow_guests']) ? 1 : 0;
            $status = $_POST['status'] ?? 'draft';
            $removePoster = !empty($_POST['remove_poster']);

            if ($title === '' || mb_strlen($title) > 200) {
                throw new RuntimeException(
                    'Enter an event title of no more than 200 characters.'
                );
            }

            if (!$categoryId) {
                throw new RuntimeException('Select a program category.');
            }

            if (!in_array($status, array_keys($statuses), true)) {
                throw new RuntimeException('Select a valid event status.');
            }

            $categoryCheck = $pdo->prepare(
                'SELECT id FROM program_categories
                 WHERE id = :id AND is_active = 1'
            );
            $categoryCheck->execute(['id' => $categoryId]);

            if (!$categoryCheck->fetch()) {
                throw new RuntimeException('The selected category is unavailable.');
            }

            // Validate local date/time input.
            $startDate = DateTime::createFromFormat('!Y-m-d\TH:i', $start);
            $endDate = DateTime::createFromFormat('!Y-m-d\TH:i', $end);

            if (
                !$startDate ||
                $startDate->format('Y-m-d\TH:i') !== $start ||
                !$endDate ||
                $endDate->format('Y-m-d\TH:i') !== $end
            ) {
                throw new RuntimeException('Enter valid event dates and times.');
            }

            if ($endDate <= $startDate) {
                throw new RuntimeException(
                    'The event end time must be after its start time.'
                );
            }

            $deadlineSql = null;

            if ($deadline !== '') {
                $deadlineDate = DateTime::createFromFormat(
                    '!Y-m-d\TH:i',
                    $deadline
                );

                if (
                    !$deadlineDate ||
                    $deadlineDate->format('Y-m-d\TH:i') !== $deadline
                ) {
                    throw new RuntimeException(
                        'Enter a valid registration deadline.'
                    );
                }

                if ($deadlineDate > $startDate) {
                    throw new RuntimeException(
                        'The registration deadline cannot be after the event starts.'
                    );
                }

                $deadlineSql = $deadlineDate->format('Y-m-d H:i:s');
            }

            $capacity = null;

            if ($capacityInput !== '') {
                $capacity = filter_var(
                    $capacityInput,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if ($capacity === false) {
                    throw new RuntimeException(
                        'Capacity must be a whole number greater than zero.'
                    );
                }
            }

            $startSql = $startDate->format('Y-m-d H:i:s');
            $endSql = $endDate->format('Y-m-d H:i:s');

            // -------- Poster handling --------
            $uploadedPosterPath = null;
            $posterUploaded = isset($_FILES['poster']) &&
                is_array($_FILES['poster']) &&
                ($_FILES['poster']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

            // Fetch current record when updating
            $existingPosterPath = null;

            if ($action === 'update') {
                $eventId = filter_var(
                    $_POST['event_id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                if (!$eventId || $eventId < 1) {
                    throw new RuntimeException('Invalid event selected for editing.');
                }

                $check = $pdo->prepare(
                    'SELECT id, poster_path FROM events WHERE id = :id'
                );
                $check->execute(['id' => $eventId]);
                $existing = $check->fetch();

                if (!$existing) {
                    throw new RuntimeException('The event could not be found.');
                }

                $existingPosterPath = $existing['poster_path'] ?? null;

                if ($posterUploaded) {
                    $result = poster_save_upload($_FILES['poster']);

                    if (!$result['ok']) {
                        throw new RuntimeException(
                            'Poster upload failed: ' . $result['error']
                        );
                    }

                    $uploadedPosterPath = $result['path'];
                }
            } else {
                if ($posterUploaded) {
                    $result = poster_save_upload($_FILES['poster']);

                    if (!$result['ok']) {
                        throw new RuntimeException(
                            'Poster upload failed: ' . $result['error']
                        );
                    }

                    $uploadedPosterPath = $result['path'];
                }
            }

            // -------- Save the event --------
            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO events (
                        category_id, title, description, venue,
                        start_datetime, end_datetime, registration_deadline,
                        capacity, allow_guests, status, poster_path, created_by
                    ) VALUES (
                        :category_id, :title, :description, :venue,
                        :start_datetime, :end_datetime, :registration_deadline,
                        :capacity, :allow_guests, :status, :poster_path, :created_by
                    )'
                );

                $stmt->execute([
                    'category_id' => $categoryId,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'venue' => $venue !== '' ? $venue : null,
                    'start_datetime' => $startSql,
                    'end_datetime' => $endSql,
                    'registration_deadline' => $deadlineSql,
                    'capacity' => $capacity,
                    'allow_guests' => $allowGuests,
                    'status' => $status,
                    'poster_path' => $uploadedPosterPath,
                    'created_by' => $adminId
                ]);

                $message = 'Event created successfully.';
            } else {
                // Final poster value: new upload > removal > keep existing
                if ($uploadedPosterPath !== null) {
                    $finalPosterPath = $uploadedPosterPath;
                } elseif ($removePoster) {
                    $finalPosterPath = null;
                } else {
                    $finalPosterPath = $existingPosterPath;
                }

                $stmt = $pdo->prepare(
                    'UPDATE events SET
                        category_id = :category_id,
                        title = :title,
                        description = :description,
                        venue = :venue,
                        start_datetime = :start_datetime,
                        end_datetime = :end_datetime,
                        registration_deadline = :registration_deadline,
                        capacity = :capacity,
                        allow_guests = :allow_guests,
                        status = :status,
                        poster_path = :poster_path
                     WHERE id = :id'
                );

                $stmt->execute([
                    'category_id' => $categoryId,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'venue' => $venue !== '' ? $venue : null,
                    'start_datetime' => $startSql,
                    'end_datetime' => $endSql,
                    'registration_deadline' => $deadlineSql,
                    'capacity' => $capacity,
                    'allow_guests' => $allowGuests,
                    'status' => $status,
                    'poster_path' => $finalPosterPath,
                    'id' => $eventId
                ]);

                // Clean up old files (after successful DB update)
                if (
                    $existingPosterPath &&
                    $existingPosterPath !== $finalPosterPath
                ) {
                    poster_delete_file($existingPosterPath);
                }

                $message = 'Event updated successfully.';
            }

            $_SESSION['events_csrf'] = bin2hex(random_bytes(32));

        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'The event could not be saved. Please check the details and try again.';
        }
    }
}

// Load the event selected for editing.
$editingEvent = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($editId && $editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editingEvent = $stmt->fetch();

    if (!$editingEvent) {
        $error = 'The selected event was not found.';
    }
}

// Form defaults.
$form = [
    'title' => $editingEvent['title'] ?? '',
    'description' => $editingEvent['description'] ?? '',
    'venue' => $editingEvent['venue'] ?? '',
    'category_id' => $editingEvent['category_id'] ?? '',
    'start_datetime' => isset($editingEvent['start_datetime'])
        ? date('Y-m-d\TH:i', strtotime($editingEvent['start_datetime']))
        : '',
    'end_datetime' => isset($editingEvent['end_datetime'])
        ? date('Y-m-d\TH:i', strtotime($editingEvent['end_datetime']))
        : '',
    'registration_deadline' => !empty($editingEvent['registration_deadline'])
        ? date('Y-m-d\TH:i', strtotime($editingEvent['registration_deadline']))
        : '',
    'capacity' => $editingEvent['capacity'] ?? '',
    'allow_guests' => (int) ($editingEvent['allow_guests'] ?? 1),
    'status' => $editingEvent['status'] ?? 'draft',
    'poster_path' => $editingEvent['poster_path'] ?? null
];

// Fetch events with their program categories.
$events = $pdo->query(
    'SELECT e.id, e.title, e.venue, e.start_datetime, e.status,
            e.capacity, e.poster_path, c.name AS category_name,
            (SELECT COUNT(*) FROM registrations r
             WHERE r.event_id = e.id
               AND r.status IN ("registered", "confirmed")) AS registrations_count
     FROM events e
     INNER JOIN program_categories c ON c.id = e.category_id
     ORDER BY e.start_datetime DESC, e.id DESC'
)->fetchAll();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Events | Youth Connect</title>
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 25px;
            background: #f4f7f4;
            color: #26352b;
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
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1, h2 { color: #14532d; }

        .panel {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 18px #00000008;
        }

        .back-link, .btn {
            display: inline-block;
            padding: 11px 15px;
            border: 0;
            border-radius: 7px;
            background: #14532d;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .cancel-link {
            background: #6b7280;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .field label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5cc;
            border-radius: 7px;
            font: inherit;
            background: white;
        }

        .field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .checkbox-field {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
        }

        .checkbox-field input {
            width: auto;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .notice {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th, td {
            padding: 13px 10px;
            text-align: left;
            border-bottom: 1px solid #e5ebe5;
            vertical-align: middle;
        }

        th {
            background: #f4f7f4;
            color: #425447;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-published { background: #dcfce7; color: #166534; }
        .status-draft { background: #e5e7eb; color: #374151; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }

        .edit-link {
            color: #166534;
            font-weight: bold;
        }

        .muted {
            color: #68756c;
            font-size: 13px;
        }

        /* ---- Poster upload styles ---- */
        .poster-field {
            grid-column: 1 / -1;
            padding: 18px;
            background: #f7faf7;
            border: 1px dashed #cbd5cc;
            border-radius: 10px;
        }

        .poster-preview {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .poster-thumb {
            width: 160px;
            height: 110px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e5ebe5;
            background: #fff;
        }

        .poster-info {
            flex: 1;
            min-width: 200px;
        }

        .poster-info p {
            margin: 0 0 8px;
            color: #425447;
            font-size: 14px;
        }

        .poster-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5cc;
            border-radius: 7px;
            background: white;
            font-size: 14px;
        }

        .remove-poster {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            color: #991b1b;
            font-size: 13px;
        }

        .remove-poster input {
            width: auto;
        }

        /* List poster thumbnail */
        .list-thumb {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #e5ebe5;
            display: inline-block;
            vertical-align: middle;
            margin-right: 8px;
        }

        .list-thumb-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 6px;
            background: #eef2f7;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #94a3b8;
            margin-right: 8px;
            vertical-align: middle;
        }

        @media (max-width: 650px) {
            body { padding: 12px; }
            .panel { padding: 16px; }
            .form-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: auto; }
        }
    </style>
</head>
<body>
<main class="container">

    <header class="header">
        <div>
            <h1>Event Management</h1>
            <p>Create, edit and publish regional youth programs.</p>
        </div>

        <a class="back-link" href="dashboard.php">
            &larr; Back to Dashboard
        </a>
    </header>

    <?php if ($message !== ''): ?>
        <div class="notice success" role="status"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="panel" id="event-form">
        <h2>
            <?= $editingEvent ? 'Edit Event' : 'Create New Event' ?>
        </h2>

        <p class="muted">
            Select one of the five annual program categories. Only published
            events are visible on the public events page.
        </p>

        <?php if (!$categories): ?>
            <p class="error notice">
                No active program categories were found. Activate your categories
                in the database before creating an event.
            </p>
        <?php else: ?>

        <form method="POST"
              action="manage_events.php<?= $editingEvent ? '?edit=' . (int) $editingEvent['id'] : '' ?>"
              enctype="multipart/form-data">

            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['events_csrf']) ?>">

            <input type="hidden" name="action"
                   value="<?= $editingEvent ? 'update' : 'create' ?>">

            <?php if ($editingEvent): ?>
                <input type="hidden" name="event_id"
                       value="<?= (int) $editingEvent['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">

                <div class="field full-width">
                    <label for="title">Event Title *</label>
                    <input type="text" id="title" name="title"
                           maxlength="200" required
                           value="<?= h($form['title']) ?>"
                           placeholder="e.g. Regional Senior Youth Camp">
                </div>

                <div class="field">
                    <label for="category_id">Program Category *</label>
                    <select id="category_id" name="category_id" required>
                        <option value="">-- Select Category --</option>

                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"
                                <?= (string) $form['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
                                <?= h($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="venue">Venue</label>
                    <input type="text" id="venue" name="venue"
                           maxlength="255"
                           value="<?= h($form['venue']) ?>"
                           placeholder="Event location">
                </div>

                <div class="field">
                    <label for="start_datetime">Start Date and Time *</label>
                    <input type="datetime-local" id="start_datetime"
                           name="start_datetime" required
                           value="<?= h($form['start_datetime']) ?>">
                </div>

                <div class="field">
                    <label for="end_datetime">End Date and Time *</label>
                    <input type="datetime-local" id="end_datetime"
                           name="end_datetime" required
                           value="<?= h($form['end_datetime']) ?>">
                </div>

                <div class="field">
                    <label for="registration_deadline">Registration Deadline</label>
                    <input type="datetime-local" id="registration_deadline"
                           name="registration_deadline"
                           value="<?= h($form['registration_deadline']) ?>">
                    <p class="muted">Leave blank if there is no deadline.</p>
                </div>

                <div class="field">
                    <label for="capacity">Participant Capacity</label>
                    <input type="number" id="capacity" name="capacity"
                           min="1" step="1"
                           value="<?= h($form['capacity']) ?>"
                           placeholder="Leave blank for unlimited">
                </div>

                <div class="field">
                    <label for="status">Event Status *</label>
                    <select id="status" name="status" required>
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= h($value) ?>"
                                <?= $form['status'] === $value ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field full-width">
                    <label for="description">Event Description</label>
                    <textarea id="description" name="description"
                              placeholder="Describe the event, its purpose and who should attend."><?= h($form['description']) ?></textarea>
                </div>

                <!-- ===== POSTER UPLOAD ===== -->
                <div class="poster-field">
                    <label for="poster" style="margin-top:0;">Event Poster</label>

                    <?php if (!empty($form['poster_path'])): ?>
                        <div class="poster-preview">
                            <img
                                src="<?= h($form['poster_path']) ?>?v=<?= time() ?>"
                                alt="Current poster"
                                class="poster-thumb"
                            >
                            <div class="poster-info">
                                <p><strong>Current poster</strong></p>
                                <p class="muted">
                                    <?= h(basename($form['poster_path'])) ?>
                                </p>
                                <label class="remove-poster">
                                    <input
                                        type="checkbox"
                                        name="remove_poster"
                                        value="1"
                                    >
                                    Remove this poster
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <input
                        type="file"
                        id="poster"
                        name="poster"
                        accept="image/jpeg,image/png,image/webp"
                        class="poster-input"
                    >

                    <p class="muted" style="margin-top:8px;">
                        Accepted formats: JPG, PNG, WebP.
                        Maximum size: 5 MB.
                        <?= $editingEvent
                            ? 'Upload a new file to replace the current poster.'
                            : 'Optional — leave blank to use the default placeholder.' ?>
                    </p>
                </div>

            </div>

            <label class="checkbox-field">
                <input type="checkbox" name="allow_guests" value="1"
                    <?= (int) $form['allow_guests'] === 1 ? 'checked' : '' ?>>
                Allow invited guests to register
            </label>

            <div class="actions">
                <button class="btn" type="submit">
                    <?= $editingEvent ? 'Save Event Changes' : 'Create Event' ?>
                </button>

                <?php if ($editingEvent): ?>
                    <a class="btn cancel-link" href="manage_events.php">
                        Cancel Editing
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>All Events (<?= count($events) ?>)</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Category</th>
                        <th>Date</th>
                        <th>Capacity</th>
                        <th>Registrations</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$events): ?>
                    <tr>
                        <td colspan="7">No events have been created yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <?php if (!empty($event['poster_path'])): ?>
                                        <img
                                            src="<?= h($event['poster_path']) ?>"
                                            alt=""
                                            class="list-thumb"
                                        >
                                    <?php else: ?>
                                        <span class="list-thumb-placeholder">📅</span>
                                    <?php endif; ?>

                                    <div>
                                        <strong><?= h($event['title']) ?></strong>
                                        <br>
                                        <span class="muted">
                                            <?= h($event['venue'] ?: 'Venue to be announced') ?>
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <td><?= h($event['category_name']) ?></td>

                            <td>
                                <?= date('d M Y, H:i', strtotime($event['start_datetime'])) ?>
                            </td>

                            <td>
                                <?= $event['capacity'] === null
                                    ? 'Unlimited'
                                    : (int) $event['capacity'] ?>
                            </td>

                            <td><?= (int) $event['registrations_count'] ?></td>

                            <td>
                                <span class="status status-<?= h($event['status']) ?>">
                                    <?= h($statuses[$event['status']] ?? $event['status']) ?>
                                </span>
                            </td>

                            <td>
                                <a class="edit-link"
                                   href="manage_events.php?edit=<?= (int) $event['id'] ?>#event-form">
                                    Edit
                                </a>
                                <br><br>
                                <a href="events.php" target="_blank" rel="noopener">
                                    Public Events Page
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var fileInput = document.getElementById('poster');

    if (!fileInput) {
        return;
    }

    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];

        if (!file) {
            return;
        }

        // Client-side size hint (server enforces it too)
        if (file.size > 5 * 1024 * 1024) {
            alert('The selected file is larger than 5 MB.');
            fileInput.value = '';
            return;
        }

        var allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

        if (allowedTypes.indexOf(file.type) === -1) {
            alert('Please choose a JPG, PNG or WebP image.');
            fileInput.value = '';
            return;
        }
    });
});
</script>
</body>
</html>