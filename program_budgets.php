
<?php

session_start();

require_once __DIR__ . '/config/database.php';

/*
|--------------------------------------------------------------------------
| Program Budgets
|--------------------------------------------------------------------------
| Chairperson: View, approve and reject proposals.
| Secretary: View and submit proposals.
| Treasurer: View and submit proposals.
| Other roles: No access.
|
| Expected database:
| program_budgets:
| id, event_id, proposed_amount, approved_amount, status,
| notes, created_by, reviewed_by, created_at, updated_at
|
| financial_records:
| event_id, transaction_type, amount
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id'], $_SESSION['role'])
) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = strtolower(trim((string) $_SESSION['role']));

$allowedRoles = ['chairperson', 'secretary', 'treasurer'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. You are not allowed to view program budgets.');
}

/*
|--------------------------------------------------------------------------
| Database connection check
|--------------------------------------------------------------------------
*/

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit(
        'Database connection error. Check config/database.php and confirm '
        . 'that it creates a PDO connection named $pdo.'
    );
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$message = '';
$messageType = 'success';

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function money(mixed $amount): string
{
    return 'KSh ' . number_format((float) $amount, 2);
}

/*
|--------------------------------------------------------------------------
| Handle form submissions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session token is invalid. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            /*
            |--------------------------------------------------------------
            | Submit a new budget proposal
            |--------------------------------------------------------------
            */

            if ($action === 'submit_budget') {
                if (!in_array($role, ['secretary', 'treasurer'], true)) {
                    throw new RuntimeException(
                        'Only the Secretary or Treasurer can submit a budget proposal.'
                    );
                }

                $eventId = filter_var(
                    $_POST['event_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $proposedAmount = filter_var(
                    $_POST['proposed_amount'] ?? null,
                    FILTER_VALIDATE_FLOAT
                );

                $notes = trim((string) ($_POST['notes'] ?? ''));

                if (!$eventId || $eventId < 1) {
                    throw new RuntimeException('Please select an event.');
                }

                if (
                    $proposedAmount === false
                    || $proposedAmount <= 0
                    || !is_finite((float) $proposedAmount)
                ) {
                    throw new RuntimeException(
                        'Enter a valid proposed budget greater than zero.'
                    );
                }

                if (strlen($notes) > 5000) {
                    throw new RuntimeException(
                        'The notes must not exceed 5,000 characters.'
                    );
                }

                $pdo->beginTransaction();

                $eventStmt = $pdo->prepare(
                    "SELECT id, status
                     FROM events
                     WHERE id = ?
                     FOR UPDATE"
                );
                $eventStmt->execute([$eventId]);
                $event = $eventStmt->fetch();

                if (!$event || $event['status'] === 'cancelled') {
                    throw new RuntimeException(
                        'The selected event does not exist or has been cancelled.'
                    );
                }

                $existingStmt = $pdo->prepare(
                    "SELECT id
                     FROM program_budgets
                     WHERE event_id = ?
                     LIMIT 1"
                );
                $existingStmt->execute([$eventId]);

                if ($existingStmt->fetch()) {
                    throw new RuntimeException(
                        'A budget proposal already exists for this event.'
                    );
                }

                $insertStmt = $pdo->prepare(
                    "INSERT INTO program_budgets
                        (
                            event_id,
                            proposed_amount,
                            approved_amount,
                            status,
                            notes,
                            created_by,
                            reviewed_by,
                            created_at,
                            updated_at
                        )
                     VALUES (?, ?, NULL, 'submitted', ?, ?, NULL, NOW(), NOW())"
                );

                $insertStmt->execute([
                    $eventId,
                    $proposedAmount,
                    $notes !== '' ? $notes : null,
                    $userId
                ]);

                $pdo->commit();

                $message = 'Budget proposal submitted successfully.';
            }

            /*
            |--------------------------------------------------------------
            | Approve or reject a proposal
            |--------------------------------------------------------------
            */

            elseif ($action === 'review_budget') {
                if ($role !== 'chairperson') {
                    throw new RuntimeException(
                        'Only the Chairperson can approve or reject budget proposals.'
                    );
                }

                $budgetId = filter_var(
                    $_POST['budget_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $decision = (string) ($_POST['decision'] ?? '');

                if (!$budgetId || $budgetId < 1) {
                    throw new RuntimeException('Invalid budget proposal.');
                }

                if (!in_array($decision, ['approved', 'rejected'], true)) {
                    throw new RuntimeException('Invalid review decision.');
                }

                $pdo->beginTransaction();

                $budgetStmt = $pdo->prepare(
                    "SELECT id, status
                     FROM program_budgets
                     WHERE id = ?
                     FOR UPDATE"
                );
                $budgetStmt->execute([$budgetId]);
                $existingBudget = $budgetStmt->fetch();

                if (!$existingBudget) {
                    throw new RuntimeException('Budget proposal not found.');
                }

                if ($existingBudget['status'] !== 'submitted') {
                    throw new RuntimeException(
                        'Only submitted proposals can be reviewed.'
                    );
                }

                $approvedAmount = null;

                if ($decision === 'approved') {
                    $amountStmt = $pdo->prepare(
                        "SELECT proposed_amount
                         FROM program_budgets
                         WHERE id = ?"
                    );
                    $amountStmt->execute([$budgetId]);
                    $approvedAmount = $amountStmt->fetchColumn();

                    if ($approvedAmount === false) {
                        throw new RuntimeException(
                            'Could not retrieve the proposed amount.'
                        );
                    }
                }

                $reviewStmt = $pdo->prepare(
                    "UPDATE program_budgets
                     SET status = ?,
                         approved_amount = ?,
                         reviewed_by = ?,
                         updated_at = NOW()
                     WHERE id = ?"
                );

                $reviewStmt->execute([
                    $decision,
                    $approvedAmount,
                    $userId,
                    $budgetId
                ]);

                $pdo->commit();

                $message = $decision === 'approved'
                    ? 'Budget approved successfully.'
                    : 'Budget proposal rejected.';
            }

            else {
                throw new RuntimeException('Unknown form action.');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $exception instanceof PDOException
                ? 'A database error occurred. Check your database structure and try again.'
                : $exception->getMessage();

            $messageType = 'error';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch events available for new proposals
|--------------------------------------------------------------------------
*/

$eventsStmt = $pdo->query(
    "SELECT e.id, e.title, e.start_datetime, e.venue
     FROM events e
     LEFT JOIN program_budgets b ON b.event_id = e.id
     WHERE e.status <> 'cancelled'
       AND b.id IS NULL
     ORDER BY e.start_datetime DESC"
);

$availableEvents = $eventsStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Fetch budgets and calculate expenditure for each event
|--------------------------------------------------------------------------
*/

$budgetsStmt = $pdo->query(
    "SELECT
        b.id,
        b.event_id,
        b.proposed_amount,
        b.approved_amount,
        b.status,
        b.notes,
        b.created_at,
        e.title AS event_title,
        e.start_datetime,
        e.venue,
        creator.full_name AS creator_name,
        reviewer.full_name AS reviewer_name,

        COALESCE(expenses.total_expenditure, 0)
            AS total_expenditure

     FROM program_budgets b

     INNER JOIN events e
        ON e.id = b.event_id

     INNER JOIN users creator
        ON creator.id = b.created_by

     LEFT JOIN users reviewer
        ON reviewer.id = b.reviewed_by

     LEFT JOIN (
        SELECT
            event_id,
            SUM(amount) AS total_expenditure
        FROM financial_records
        WHERE transaction_type = 'expense'
          AND event_id IS NOT NULL
        GROUP BY event_id
     ) expenses
        ON expenses.event_id = b.event_id

     ORDER BY b.created_at DESC"
);

$budgets = $budgetsStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Dashboard totals
|--------------------------------------------------------------------------
*/

$totalProposed = 0.0;
$totalApproved = 0.0;
$totalExpenditure = 0.0;
$pendingCount = 0;

foreach ($budgets as $budget) {
    $totalProposed += (float) $budget['proposed_amount'];

    if ($budget['status'] === 'approved') {
        $totalApproved += (float) $budget['approved_amount'];
        $totalExpenditure += (float) $budget['total_expenditure'];
    }

    if ($budget['status'] === 'submitted') {
        $pendingCount++;
    }
}

$totalRemaining = $totalApproved - $totalExpenditure;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Program Budgets | Youth Connect</title>
    

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7fb;
            color: #1e293b;
        }

        .container {
            width: min(1400px, 96%);
            margin: 28px auto;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        h1 {
            margin: 0 0 8px;
            color: #12366b;
        }

        .subtitle {
            color: #64748b;
            margin: 0;
        }

        .back-link {
            display: inline-block;
            padding: 10px 15px;
            background: #12366b;
            color: #fff;
            border-radius: 7px;
            text-decoration: none;
        }

        .message {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card,
        .panel {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .06);
        }

        .stat-card p {
            margin: 0 0 10px;
            color: #64748b;
            font-size: 14px;
        }

        .stat-card h2 {
            margin: 0;
            font-size: 22px;
            overflow-wrap: anywhere;
        }

        .panel {
            margin-bottom: 24px;
        }

        .panel h2 {
            margin-top: 0;
            color: #12366b;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: 600;
            font-size: 14px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font: inherit;
            background: #fff;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        button {
            padding: 10px 14px;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }

        .btn-primary {
            background: #12366b;
            color: #fff;
        }

        .btn-approve {
            background: #15803d;
            color: #fff;
        }

        .btn-reject {
            background: #b91c1c;
            color: #fff;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1150px;
        }

        th,
        td {
            text-align: left;
            padding: 13px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        th {
            background: #f1f5f9;
            color: #334155;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        td {
            font-size: 13px;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status.approved {
            background: #dcfce7;
            color: #166534;
        }

        .status.submitted,
        .status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .positive {
            color: #15803d;
            font-weight: 700;
        }

        .negative {
            color: #b91c1c;
            font-weight: 700;
        }

        .muted {
            color: #64748b;
        }

        .review-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .review-actions form {
            margin: 0;
        }

        .notes {
            max-width: 220px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .empty {
            text-align: center;
            color: #64748b;
            padding: 30px;
        }

        @media (max-width: 900px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .stats,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .container {
                width: 94%;
                margin: 18px auto;
            }

            .stat-card h2 {
                font-size: 19px;
            }
        }

        @media print {
            body {
                background: #fff;
            }

            .no-print,
            .back-link,
            .review-actions {
                display: none !important;
            }

            .container {
                width: 100%;
                margin: 0;
            }

            .panel,
            .stat-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .table-wrap {
                overflow: visible;
            }

            table {
                min-width: 0;
                font-size: 10px;
            }

            th,
            td {
                padding: 6px;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <header class="page-header">
        <div>
            <h1>Program Budget Management</h1>
            <p class="subtitle">
                AIC Kabartegan Region Youth Connect
            </p>
        </div>

        <div class="no-print">
            <a class="back-link" href="javascript:history.back()">
                &larr; Back
            </a>
            <button type="button" class="btn-primary" onclick="window.print()">
                Print Report
            </button>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <p>Total Proposed Budgets</p>
            <h2><?= e(money($totalProposed)) ?></h2>
        </div>

        <div class="stat-card">
            <p>Total Approved Budgets</p>
            <h2><?= e(money($totalApproved)) ?></h2>
        </div>

        <div class="stat-card">
            <p>Total Expenditure</p>
            <h2><?= e(money($totalExpenditure)) ?></h2>
        </div>

        <div class="stat-card">
            <p>Overall Remaining Balance</p>
            <h2 class="<?= $totalRemaining < 0 ? 'negative' : 'positive' ?>">
                <?= e(money($totalRemaining)) ?>
            </h2>
        </div>
    </section>

    <section class="stats">
        <div class="stat-card">
            <p>Pending Proposals</p>
            <h2><?= (int) $pendingCount ?></h2>
        </div>
    </section>

    <?php if (in_array($role, ['secretary', 'treasurer'], true)): ?>
        <section class="panel no-print">
            <h2>Submit a Budget Proposal</h2>

            <?php if (count($availableEvents) > 0): ?>
                <form method="POST" action="">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="submit_budget"
                    >

                    <div class="form-grid">
                        <div class="field">
                            <label for="event_id">Select Event</label>

                            <select
                                id="event_id"
                                name="event_id"
                                required
                            >
                                <option value="">-- Choose Event --</option>

                                <?php foreach ($availableEvents as $event): ?>
                                    <option value="<?= (int) $event['id'] ?>">
                                        <?= e($event['title']) ?>
                                        <?php if (!empty($event['start_datetime'])): ?>
                                            — <?= e($event['start_datetime']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="proposed_amount">
                                Proposed Budget (KSh)
                            </label>

                            <input
                                type="number"
                                id="proposed_amount"
                                name="proposed_amount"
                                min="0.01"
                                step="0.01"
                                required
                            >
                        </div>

                        <div class="field full">
                            <label for="notes">
                                Budget Details / Notes
                            </label>

                            <textarea
                                id="notes"
                                name="notes"
                                maxlength="5000"
                                placeholder="Describe the expected program expenses..."
                            ></textarea>
                        </div>

                        <div class="field full">
                            <button type="submit" class="btn-primary">
                                Submit Proposal
                            </button>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <p class="muted">
                    There are no eligible events without a budget proposal.
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Budget Proposals and Expenditure</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Event Date</th>
                        <th>Venue</th>
                        <th>Proposed</th>
                        <th>Approved</th>
                        <th>Expenditure</th>
                        <th>Remaining</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Reviewed By</th>
                        <th>Notes</th>

                        <?php if ($role === 'chairperson'): ?>
                            <th class="no-print">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($budgets)): ?>
                        <tr>
                            <td
                                colspan="<?= $role === 'chairperson' ? 12 : 11 ?>"
                                class="empty"
                            >
                                No budget proposals have been submitted yet.
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($budgets as $budget): ?>
                            <?php
                            $proposed = (float) $budget['proposed_amount'];
                            $approved = (float) ($budget['approved_amount'] ?? 0);
                            $spent = (float) $budget['total_expenditure'];
                            $remaining = $approved - $spent;
                            $approvedStatus = $budget['status'] === 'approved';
                            ?>

                            <tr>
                                <td>
                                    <strong>
                                        <?= e($budget['event_title']) ?>
                                    </strong>
                                    <br>
                                    <small class="muted">
                                        Budget #<?= (int) $budget['id'] ?>
                                    </small>
                                </td>

                                <td>
                                    <?= e($budget['start_datetime'] ?: 'Not set') ?>
                                </td>

                                <td>
                                    <?= e($budget['venue'] ?: 'Not set') ?>
                                </td>

                                <td><?= e(money($proposed)) ?></td>

                                <td>
                                    <?= $approvedStatus
                                        ? e(money($approved))
                                        : '—' ?>
                                </td>

                                <td>
                                    <?= $approvedStatus
                                        ? e(money($spent))
                                        : '—' ?>
                                </td>

                                <td>
                                    <?php if ($approvedStatus): ?>
                                        <span class="<?= $remaining < 0 ? 'negative' : 'positive' ?>">
                                            <?= e(money($remaining)) ?>
                                        </span>

                                        <?php if ($remaining < 0): ?>
                                            <br>
                                            <small class="negative">
                                                Over budget
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">
                                            Awaiting approval
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="status <?= e($budget['status']) ?>">
                                        <?= e($budget['status']) ?>
                                    </span>
                                </td>

                                <td><?= e($budget['creator_name']) ?></td>

                                <td>
                                    <?= e($budget['reviewer_name'] ?: '—') ?>
                                </td>

                                <td class="notes">
                                    <?= e($budget['notes'] ?: '—') ?>
                                </td>

                                <?php if ($role === 'chairperson'): ?>
                                    <td class="no-print">
                                        <?php if ($budget['status'] === 'submitted'): ?>
                                            <div class="review-actions">
                                                <form method="POST" action="">
                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($csrfToken) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="review_budget"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="budget_id"
                                                        value="<?= (int) $budget['id'] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="decision"
                                                        value="approved"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="btn-approve"
                                                        onclick="return confirm('Approve this proposed budget?');"
                                                    >
                                                        Approve
                                                    </button>
                                                </form>

                                                <form method="POST" action="">
                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($csrfToken) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="review_budget"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="budget_id"
                                                        value="<?= (int) $budget['id'] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="decision"
                                                        value="rejected"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="btn-reject"
                                                        onclick="return confirm('Reject this budget proposal?');"
                                                    >
                                                        Reject
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted">
                                                Already reviewed
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="muted">
        Expenditure is calculated from financial records linked to each event.
        Only approved budgets contribute to the approved-budget totals.
    </p>

</div>
</body>
</html>
