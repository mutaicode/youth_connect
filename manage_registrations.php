<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Require an active system administrator.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];

$auth = $pdo->prepare(
    'SELECT role, account_status
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$auth->execute(['id' => $adminId]);
$admin = $auth->fetch();

if (
    !$admin ||
    $admin['role'] !== 'admin' ||
    $admin['account_status'] !== 'active'
) {
    http_response_code(403);
    exit('Access denied. An active system administrator is required.');
}

if (empty($_SESSION['registrations_csrf'])) {
    $_SESSION['registrations_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Process registration status updates.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['registrations_csrf'], $token)
    ) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } else {
        $registrationId = filter_var(
            $_POST['registration_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST['status'] ?? '';

        // ---------------------------------------------------
        // Bulk action handler
        // ---------------------------------------------------
        if ($newStatus === 'bulk') {
            $bulkAction = $_POST['bulk_action'] ?? '';
            $bulkIds    = $_POST['bulk_ids']    ?? [];

            if (
                !is_array($bulkIds) ||
                empty($bulkIds) ||
                !in_array($bulkAction, ['confirm', 'cancel'], true)
            ) {
                $error = 'Select at least one registration and a valid bulk action.';
            } else {
                $targetStatus = $bulkAction === 'confirm' ? 'confirmed' : 'cancelled';
                $cleanIds = [];

                foreach ($bulkIds as $bid) {
                    if (ctype_digit((string) $bid) && (int) $bid > 0) {
                        $cleanIds[] = (int) $bid;
                    }
                }

                if (empty($cleanIds)) {
                    $error = 'No valid registrations selected.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $placeholders = implode(
                            ',',
                            array_fill(0, count($cleanIds), '?')
                        );

                        $upd = $pdo->prepare(
                            "UPDATE registrations
                             SET status = ?
                             WHERE id IN ($placeholders)
                               AND status != ?"
                        );

                        $bindParams = array_merge(
                            [$targetStatus],
                            $cleanIds,
                            [$targetStatus]
                        );

                        $upd->execute($bindParams);

                        $affected = $upd->rowCount();

                        $pdo->commit();

                        $_SESSION['registrations_csrf'] = bin2hex(random_bytes(32));
                        $message = $affected .
                            ' registration(s) updated to "' .
                            $targetStatus . '".';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log($e->getMessage());
                        $error = 'Bulk update failed. Please try again.';
                    }
                }
            }
        }
        // ---------------------------------------------------
        // Single registration update (existing behaviour)
        // ---------------------------------------------------
        elseif (
            !$registrationId ||
            $registrationId < 1 ||
            !in_array($newStatus, ['registered', 'confirmed', 'cancelled'], true)
        ) {
            $error = 'Invalid registration or status.';
        } else {
            try {
                $pdo->beginTransaction();

                // Lock the event row while checking its capacity.
                $stmt = $pdo->prepare(
                    'SELECT r.id, r.event_id, r.status AS current_status,
                            e.capacity
                     FROM registrations r
                     INNER JOIN events e ON e.id = r.event_id
                     WHERE r.id = :id
                     FOR UPDATE'
                );
                $stmt->execute(['id' => $registrationId]);
                $registration = $stmt->fetch();

                if (!$registration) {
                    throw new RuntimeException('Registration not found.');
                }

                $isCurrentlyActive = in_array(
                    $registration['current_status'],
                    ['registered', 'confirmed'],
                    true
                );

                $willBeActive = in_array(
                    $newStatus,
                    ['registered', 'confirmed'],
                    true
                );

                // Check capacity when reactivating a cancelled registration.
                if (
                    !$isCurrentlyActive &&
                    $willBeActive &&
                    $registration['capacity'] !== null
                ) {
                    $countStmt = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM registrations
                         WHERE event_id = :event_id
                           AND status IN ('registered', 'confirmed')"
                    );
                    $countStmt->execute([
                        'event_id' => $registration['event_id']
                    ]);

                    $activeCount = (int) $countStmt->fetchColumn();

                    if ($activeCount >= (int) $registration['capacity']) {
                        throw new RuntimeException(
                            'The event has reached its participant capacity.'
                        );
                    }
                }

                $update = $pdo->prepare(
                    'UPDATE registrations
                     SET status = :status
                     WHERE id = :id'
                );
                $update->execute([
                    'status' => $newStatus,
                    'id' => $registrationId
                ]);

                $pdo->commit();

                $_SESSION['registrations_csrf'] = bin2hex(random_bytes(32));
                $message = 'Registration status updated successfully.';

            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Unable to update the registration. Please try again.';
            }
        }
    }
}

// Load events for the filter.
$events = $pdo->query(
    'SELECT id, title, start_datetime
     FROM events
     ORDER BY start_datetime DESC'
)->fetchAll();

$selectedEvent = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$selectedEvent || $selectedEvent < 1) {
    $selectedEvent = 0;
}

// Prepare the registration list.
$sql = "
    SELECT
        r.id,
        r.event_id,
        r.participant_name,
        r.participant_email,
        r.participant_phone,
        r.participant_type,
        r.registration_reference,
        r.status,
        r.created_at,
        e.title AS event_title,
        e.start_datetime
    FROM registrations r
    INNER JOIN events e ON e.id = r.event_id
";

$params = [];

if ($selectedEvent > 0) {
    $sql .= ' WHERE r.event_id = :event_id';
    $params['event_id'] = $selectedEvent;
}

$sql .= ' ORDER BY r.created_at DESC, r.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registrations = $stmt->fetchAll();

// Summary counts for the selected event or all events.
$countSql = "
    SELECT
        COUNT(*) AS total,
        SUM(status = 'registered') AS registered_count,
        SUM(status = 'confirmed') AS confirmed_count,
        SUM(status = 'cancelled') AS cancelled_count
    FROM registrations
";

$countParams = [];

if ($selectedEvent > 0) {
    $countSql .= ' WHERE event_id = :event_id';
    $countParams['event_id'] = $selectedEvent;
}

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$summary = $countStmt->fetch();

$exportUrl = 'registrations_export.php';
if ($selectedEvent > 0) {
    $exportUrl .= '?event_id=' . (int) $selectedEvent;
}

$formAction = 'manage_registrations.php';
if ($selectedEvent > 0) {
    $formAction .= '?event_id=' . (int) $selectedEvent;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Registration Management | Youth Connect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/print.css"> 

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            background: #f4f7f4;
            color: #26352b;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 1350px;
            margin: auto;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1, h2 {
            color: #14532d;
        }

        .back-link, .filter-btn, .save-btn {
            display: inline-block;
            padding: 11px 15px;
            background: #14532d;
            color: white;
            border: none;
            border-radius: 7px;
            text-decoration: none;
            cursor: pointer;
        }

        .panel {
            background: white;
            padding: 23px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 18px #00000008;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .stat {
            background: #f7faf7;
            border: 1px solid #e5ece5;
            padding: 18px;
            border-radius: 10px;
        }

        .stat p {
            margin: 0 0 10px;
            color: #647067;
        }

        .stat strong {
            color: #14532d;
            font-size: 26px;
        }

        .filter-form {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 12px;
        }

        .filter-form label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        .filter-form select {
            min-width: 260px;
            padding: 11px;
            border: 1px solid #cbd5cc;
            border-radius: 7px;
            background: white;
        }

        .notice {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        /* ---- Bulk action bar ---- */
        .bulk-bar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
            padding: 12px 14px;
            background: #f7faf7;
            border: 1px solid #e5ece5;
            border-radius: 9px;
        }

        .bulk-bar strong {
            color: #14532d;
        }

        .bulk-bar .btn-bulk {
            display: inline-block;
            padding: 9px 14px;
            border: 0;
            border-radius: 6px;
            color: white;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
        }

        .bulk-bar .btn-confirm { background: #166534; }
        .bulk-bar .btn-cancel  { background: #b91c1c; }

        .bulk-bar .btn-export {
            display: inline-block;
            padding: 9px 14px;
            background: #1769aa;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: bold;
            margin-left: auto;
        }

        .bulk-bar .btn-bulk:hover,
        .bulk-bar .btn-export:hover {
            opacity: .9;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1050px;
            border-collapse: collapse;
        }

        th, td {
            padding: 13px 10px;
            text-align: left;
            border-bottom: 1px solid #e5ebe5;
            vertical-align: top;
        }

        th {
            background: #f4f7f4;
        }

        th.checkbox-col,
        td.checkbox-col {
            width: 34px;
            text-align: center;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-registered {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-confirmed {
            background: #dcfce7;
            color: #166534;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .update-form select {
            padding: 8px;
            border: 1px solid #cbd5cc;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .save-btn {
            padding: 8px 11px;
        }

        .muted {
            color: #68756c;
            font-size: 13px;
        }

        @media (max-width: 850px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            body {
                padding: 12px;
            }

            .panel {
                padding: 15px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .filter-form select {
                min-width: 0;
                width: 100%;
            }

            .bulk-bar .btn-export {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
<main class="container">
    <main class="container">

    <!-- PRINT HEADER -->
    <div class="print-header">
        <h1>AIC Kabartegan Region Youth Connect</h1>
        <p><strong>Registration Management Report</strong></p>
        <p>Event: <?= $selectedEvent > 0 ? h($events[$selectedEvent]['title'] ?? 'Selected') : 'All Events' ?></p>
        <p>Generated: <?= date('d M Y, H:i A') ?></p>
    </div>

    <header class="header no-print"></header>

    <header class=" header no-print">
        <div>
            <h1>Registration Management</h1>
            <p>Review and manage participants for regional youth events.</p>
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

    <section class="panel">
        <h2>Filter Registrations</h2>

        <form class="filter-form" method="GET">
            <div>
                <label for="event_id">Choose Event</label>
                <select id="event_id" name="event_id">
                    <option value="0">All Events</option>

                    <?php foreach ($events as $event): ?>
                        <option value="<?= (int) $event['id'] ?>"
                            <?= $selectedEvent === (int) $event['id'] ? 'selected' : '' ?>>
                            <?= h($event['title']) ?>
                            — <?= date('d M Y', strtotime($event['start_datetime'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="filter-btn" type="submit">Filter</button>
            <a class="back-link" href="manage_registrations.php">Clear Filter</a>
        </form>
    </section>

    <section class="panel">
        <h2>Registration Summary</h2>

        <div class="stats">
            <div class="stat">
                <p>Total Registrations</p>
                <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
            </div>

            <div class="stat">
                <p>Awaiting Confirmation</p>
                <strong><?= (int) ($summary['registered_count'] ?? 0) ?></strong>
            </div>

            <div class="stat">
                <p>Confirmed</p>
                <strong><?= (int) ($summary['confirmed_count'] ?? 0) ?></strong>
            </div>

            <div class="stat">
                <p>Cancelled</p>
                <strong><?= (int) ($summary['cancelled_count'] ?? 0) ?></strong>
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Participant Register (<?= count($registrations) ?>)</h2>

        <!-- Bulk action bar -->
        <form
            id="bulk-form"
            method="POST"
            action="<?= h($formAction) ?>"
            class="bulk-bar no-print"
            onsubmit="return confirmBulkAction(event);"
        >
            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['registrations_csrf']) ?>">
            <input type="hidden" name="status" value="bulk">

            <strong>
                <span id="selected-count">0</span> selected
            </strong>

            <button type="submit" name="bulk_action" value="confirm"
                    class="btn-bulk btn-confirm">
                ✓ Confirm Selected
            </button>

            <button type="submit" name="bulk_action" value="cancel"
                    class="btn-bulk btn-cancel">
                ✕ Cancel Selected
            </button>

            <a href="<?= h($exportUrl) ?>" class="btn-export">
                ⬇️ Export CSV
            </a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="checkbox-col">
                            <input type="checkbox" id="select-all-rows"
                                   title="Select all rows">
                        </th>
                        <th>Participant</th>
                        <th>Contact</th>
                        <th>Event</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Date Registered</th>
                        <th>Status</th>
                        <th class="no-print">Update Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$registrations): ?>
                    <tr>
                        <td colspan="9">
                            No registrations found for this selection.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($registrations as $registration): ?>
                        <tr>
                            <td class="checkbox-col">
                                <input
                                    type="checkbox"
                                    class="row-select"
                                    form="bulk-form"
                                    name="bulk_ids[]"
                                    value="<?= (int) $registration['id'] ?>"
                                >
                            </td>

                            <td>
                                <strong>
                                    <?= h($registration['participant_name']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= h($registration['participant_email']) ?>
                                <br>
                                <span class="muted">
                                    <?= h($registration['participant_phone'] ?: 'No phone provided') ?>
                                </span>
                            </td>

                            <td><?= h($registration['event_title']) ?></td>

                            <td><?= h(ucfirst($registration['participant_type'])) ?></td>

                            <td><?= h($registration['registration_reference']) ?></td>

                            <td>
                                <?= date('d M Y, H:i', strtotime($registration['created_at'])) ?>
                            </td>

                            <td>
                                <span class="status status-<?= h($registration['status']) ?>">
                                    <?= h(ucfirst($registration['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <form class="update-form"
                                      method="POST"
                                      action="<?= h($formAction) ?>">

                                    <input type="hidden" name="csrf_token"
                                           value="<?= h($_SESSION['registrations_csrf']) ?>">

                                    <input type="hidden" name="registration_id"
                                           value="<?= (int) $registration['id'] ?>">

                                    <select name="status" aria-label="Registration status">
                                        <?php foreach (
                                            ['registered', 'confirmed', 'cancelled']
                                            as $status
                                        ): ?>
                                            <option value="<?= h($status) ?>"
                                                <?= $registration['status'] === $status ? 'selected' : '' ?>>
                                                <?= h(ucfirst($status)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <br>

                                    <button class="save-btn" type="submit">
                                        Save
                                    </button>
                                </form>
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
    var selectAll = document.getElementById('select-all-rows');
    var rowBoxes  = document.querySelectorAll('.row-select');
    var counter   = document.getElementById('selected-count');

    function updateCount() {
        if (!counter) {
            return;
        }
        var n = 0;
        rowBoxes.forEach(function (b) {
            if (b.checked) {
                n++;
            }
        });
        counter.textContent = n;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowBoxes.forEach(function (b) {
                b.checked = selectAll.checked;
            });
            updateCount();
        });
    }

    rowBoxes.forEach(function (b) {
        b.addEventListener('change', function () {
            if (selectAll && !b.checked) {
                selectAll.checked = false;
            }

            // Auto-check "select all" if all rows are checked
            if (selectAll) {
                var allChecked = rowBoxes.length > 0 &&
                    Array.prototype.every.call(rowBoxes, function (x) {
                        return x.checked;
                    });

                selectAll.checked = allChecked;
            }

            updateCount();
        });
    });

    updateCount();
});

function confirmBulkAction(event) {
    var submitter = event.submitter;
    var counter   = document.getElementById('selected-count');
    var count     = counter ? parseInt(counter.textContent, 10) : 0;

    if (!submitter || !submitter.name || submitter.name !== 'bulk_action') {
        return true;
    }

    if (count < 1) {
        alert('Select at least one registration first.');
        return false;
    }

    if (submitter.value === 'cancel') {
        return confirm(
            'Cancel ' + count + ' selected registration(s)?'
        );
    }

    if (submitter.value === 'confirm') {
        return confirm(
            'Confirm ' + count + ' selected registration(s)?'
        );
    }

    return true;
}
</script>
</body>
</html>