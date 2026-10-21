
<?php
session_start();

require_once __DIR__ . '/config/database.php';

// 1. Check login.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 2. Verify the current account and role from the database.
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT role, account_status
     FROM users
     WHERE id = ?"
);
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();

if (!$currentUser || $currentUser['account_status'] !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

$role = $currentUser['role'];

$viewRoles = ['chairperson', 'secretary', 'treasurer'];

if (!in_array($role, $viewRoles, true)) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view financial records.');
}

// Only the Treasurer can create and edit financial records.
$canEdit = ($role === 'treasurer');

// Escape output.
function h($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// 3. Create CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$editRecord = null;

// 4. Load events for the Program / Event dropdown.
$eventStmt = $pdo->query(
    "SELECT
        e.id,
        e.title,
        e.start_datetime,
        CASE
            WHEN b.status = 'approved' THEN 1
            ELSE 0
        END AS has_approved_budget
     FROM events e
     LEFT JOIN program_budgets b
        ON b.event_id = e.id
     WHERE e.status != 'cancelled'
     ORDER BY e.start_datetime DESC"
);

$availableEvents = $eventStmt->fetchAll();

// 5. Load a record for editing.
if (isset($_GET['edit'])) {
    if (!$canEdit) {
        http_response_code(403);
        exit('Access denied.');
    }

    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT
    );

    if (!$editId || $editId < 1) {
        http_response_code(400);
        exit('Invalid transaction ID.');
    }

    $stmt = $pdo->prepare(
        "SELECT *
         FROM financial_records
         WHERE id = ?"
    );
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();

    if (!$editRecord) {
        http_response_code(404);
        exit('Financial record not found.');
    }
}

// 6. Handle creation and editing.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        exit('Access denied. Only the Treasurer can manage financial entries.');
    }

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? 'create';

        $date = is_string($_POST['transaction_date'] ?? null)
            ? trim($_POST['transaction_date'])
            : '';

        $type = is_string($_POST['transaction_type'] ?? null)
            ? $_POST['transaction_type']
            : '';

        $category = is_string($_POST['category'] ?? null)
            ? trim($_POST['category'])
            : '';

        $description = is_string($_POST['description'] ?? null)
            ? trim($_POST['description'])
            : '';

        $amountInput = is_string($_POST['amount'] ?? null)
            ? trim($_POST['amount'])
            : '';

        $reference = is_string($_POST['reference_number'] ?? null)
            ? trim($_POST['reference_number'])
            : '';

        $eventInput = $_POST['event_id'] ?? '';

        $eventId = null;
        $validEventId = true;

        if (!is_string($eventInput)) {
            $validEventId = false;
        } elseif ($eventInput !== '') {
            if (
                !ctype_digit($eventInput) ||
                (int) $eventInput < 1
            ) {
                $validEventId = false;
            } else {
                $eventId = (int) $eventInput;
            }
        }

        // Validate date.
        $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTime::getLastErrors();

        $validDate = $dateObject !== false
            && (
                $dateErrors === false ||
                (
                    $dateErrors['warning_count'] === 0 &&
                    $dateErrors['error_count'] === 0
                )
            )
            && $dateObject->format('Y-m-d') === $date;

        // Validate amount: positive, up to 10 whole digits
        // and 2 decimal places.
        $validAmount = preg_match(
            '/^\d{1,10}(\.\d{1,2})?$/',
            $amountInput
        );

        if (
            !in_array($action, ['create', 'update'], true) ||
            !$validDate ||
            !in_array($type, ['income', 'expense'], true) ||
            $category === '' ||
            strlen($category) > 100 ||
            $description === '' ||
            strlen($description) > 10000 ||
            !$validAmount ||
            (float) $amountInput <= 0 ||
            strlen($reference) > 100 ||
            !$validEventId
        ) {
            $error = 'Please enter valid information in all required fields.';
        } else {
            try {
                $pdo->beginTransaction();

                // Verify the event on the server.
                // Expenses must be linked to an approved budget.
                if ($eventId !== null) {
                    $eventCheck = $pdo->prepare(
                        "SELECT
                            e.id,
                            b.status AS budget_status
                         FROM events e
                         LEFT JOIN program_budgets b
                            ON b.event_id = e.id
                         WHERE e.id = ?
                           AND e.status != 'cancelled'"
                    );

                    $eventCheck->execute([$eventId]);
                    $selectedEvent = $eventCheck->fetch();

                    if (!$selectedEvent) {
                        throw new InvalidArgumentException(
                            'The selected event does not exist or has been cancelled.'
                        );
                    }

                    if (
                        $type === 'expense' &&
                        $selectedEvent['budget_status'] !== 'approved'
                    ) {
                        throw new InvalidArgumentException(
                            'Expenses can only be linked to events with approved budgets.'
                        );
                    }
                }

                if ($action === 'update') {
                    $editId = filter_var(
                        $_POST['record_id'] ?? '',
                        FILTER_VALIDATE_INT
                    );

                    if (!$editId || $editId < 1) {
                        throw new InvalidArgumentException(
                            'Invalid transaction ID.'
                        );
                    }

                    // Lock and retrieve the existing record.
                    $stmt = $pdo->prepare(
                        "SELECT *
                         FROM financial_records
                         WHERE id = ?
                         FOR UPDATE"
                    );
                    $stmt->execute([$editId]);
                    $oldRecord = $stmt->fetch();

                    if (!$oldRecord) {
                        throw new InvalidArgumentException(
                            'Financial record not found.'
                        );
                    }

                    $oldValues = [
                        'transaction_date' =>
                            $oldRecord['transaction_date'],
                        'transaction_type' =>
                            $oldRecord['transaction_type'],
                        'category' =>
                            $oldRecord['category'],
                        'description' =>
                            $oldRecord['description'],
                        'amount' =>
                            $oldRecord['amount'],
                        'reference_number' =>
                            $oldRecord['reference_number'],
                        'event_id' =>
                            $oldRecord['event_id']
                    ];

                    $newValues = [
                        'transaction_date' => $date,
                        'transaction_type' => $type,
                        'category' => $category,
                        'description' => $description,
                        'amount' => $amountInput,
                        'reference_number' =>
                            $reference !== '' ? $reference : null,
                        'event_id' => $eventId
                    ];

                    // Update the financial record.
                    $stmt = $pdo->prepare(
                        "UPDATE financial_records
                         SET transaction_date = ?,
                             transaction_type = ?,
                             category = ?,
                             description = ?,
                             amount = ?,
                             reference_number = ?,
                             event_id = ?,
                             updated_by = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([
                        $date,
                        $type,
                        $category,
                        $description,
                        $amountInput,
                        $reference !== '' ? $reference : null,
                        $eventId,
                        $userId,
                        $editId
                    ]);

                    // Record the change in the audit log.
                    $stmt = $pdo->prepare(
                        "INSERT INTO financial_audit_logs
                         (
                            financial_record_id,
                            action,
                            old_values,
                            new_values,
                            changed_by
                         )
                         VALUES (?, 'updated', ?, ?, ?)"
                    );

                    $stmt->execute([
                        $editId,
                        json_encode(
                            $oldValues,
                            JSON_UNESCAPED_UNICODE |
                            JSON_THROW_ON_ERROR
                        ),
                        json_encode(
                            $newValues,
                            JSON_UNESCAPED_UNICODE |
                            JSON_THROW_ON_ERROR
                        ),
                        $userId
                    ]);

                    $pdo->commit();

                    header(
                        'Location: financial_records.php?updated=1'
                    );
                    exit;

                } else {
                    // Create a new financial record.
                    $stmt = $pdo->prepare(
                        "INSERT INTO financial_records
                         (
                            transaction_date,
                            transaction_type,
                            category,
                            description,
                            amount,
                            reference_number,
                            event_id,
                            recorded_by
                         )
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $date,
                        $type,
                        $category,
                        $description,
                        $amountInput,
                        $reference !== '' ? $reference : null,
                        $eventId,
                        $userId
                    ]);

                    $newRecordId = (int) $pdo->lastInsertId();

                    $newValues = [
                        'transaction_date' => $date,
                        'transaction_type' => $type,
                        'category' => $category,
                        'description' => $description,
                        'amount' => $amountInput,
                        'reference_number' =>
                            $reference !== '' ? $reference : null,
                        'event_id' => $eventId
                    ];

                    // Log the creation.
                    $stmt = $pdo->prepare(
                        "INSERT INTO financial_audit_logs
                         (
                            financial_record_id,
                            action,
                            old_values,
                            new_values,
                            changed_by
                         )
                         VALUES (?, 'created', NULL, ?, ?)"
                    );

                    $stmt->execute([
                        $newRecordId,
                        json_encode(
                            $newValues,
                            JSON_UNESCAPED_UNICODE |
                            JSON_THROW_ON_ERROR
                        ),
                        $userId
                    ]);

                    $pdo->commit();

                    header(
                        'Location: financial_records.php?saved=1'
                    );
                    exit;
                }

            } catch (InvalidArgumentException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $e->getMessage();

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());

                $error = 'The financial entry could not be saved. Please try again.';
            }
        }
    }
}

// 7. Display one-time success messages.
if (isset($_GET['saved'])) {
    $message = 'Financial entry saved successfully.';
}

if (isset($_GET['updated'])) {
    $message = 'Financial record updated successfully.';
}

// 8. Calculate overall income, expenses and balance.
$summary = $pdo->query(
    "SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN transaction_type = 'income'
                    THEN amount
                    ELSE 0
                END
            ), 0
        ) AS total_income,
        COALESCE(
            SUM(
                CASE
                    WHEN transaction_type = 'expense'
                    THEN amount
                    ELSE 0
                END
            ), 0
        ) AS total_expenses,
        COUNT(*) AS total_transactions
     FROM financial_records"
)->fetch();

$balance = (float) $summary['total_income']
         - (float) $summary['total_expenses'];

// 9. Retrieve financial transactions, event names and user names.
$stmt = $pdo->query(
    "SELECT
        fr.*,
        recorder.full_name AS recorder_name,
        updater.full_name AS updater_name,
        e.title AS event_title
     FROM financial_records fr
     INNER JOIN users recorder
        ON recorder.id = fr.recorded_by
     LEFT JOIN users updater
        ON updater.id = fr.updated_by
     LEFT JOIN events e
        ON e.id = fr.event_id
     ORDER BY fr.transaction_date DESC, fr.id DESC"
);

$records = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Financial Records | Youth Connect</title>
    <link rel="stylesheet" href="assets/css/print.css">
    

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6f4;
            color: #26352b;
        }

        .header {
            background: #14532d;
            color: white;
            padding: 22px 5%;
        }

        .header h1 {
            margin: 0 0 6px;
            font-size: 25px;
        }

        .header p {
            margin: 0;
            color: #e0f2e6;
        }

        .container {
            width: 92%;
            max-width: 1250px;
            margin: 28px auto;
        }

        .top-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .btn {
            display: inline-block;
            border: none;
            border-radius: 6px;
            padding: 11px 16px;
            background: #166534;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn:hover {
            background: #14532d;
        }

        .btn-light {
            background: white;
            color: #166534;
            border: 1px solid #cbd5ce;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .card,
        .panel {
            background: white;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, .05);
        }

        .card p {
            margin: 0 0 10px;
            color: #647067;
            font-size: 14px;
        }

        .card h2 {
            margin: 0;
            font-size: 23px;
            overflow-wrap: anywhere;
        }

        .panel {
            margin-bottom: 25px;
        }

        .panel h2 {
            margin-top: 0;
            font-size: 21px;
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
            font-size: 14px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5ce;
            border-radius: 6px;
            font: inherit;
            background: white;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: 2px solid #86b99a;
            border-color: #166534;
        }

        .notice {
            padding: 13px 16px;
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

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        th,
        td {
            text-align: left;
            padding: 13px;
            border-bottom: 1px solid #e5eae6;
            font-size: 14px;
            vertical-align: top;
        }

        th {
            background: #f0f5f1;
            color: #34483a;
        }

        .income {
            color: #15803d;
            font-weight: bold;
        }

        .expense {
            color: #b91c1c;
            font-weight: bold;
        }

        .muted {
            color: #647067;
            font-size: 13px;
        }

        .event-name {
            font-weight: bold;
        }

        @media (max-width: 850px) {
            .summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 520px) {
            .form-grid,
            .summary {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .container {
                width: 94%;
            }

            .card,
            .panel {
                padding: 17px;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h1>Financial Records</h1>
    <p>AIC Kabartegan Region Youth Connect</p>
</header>

<main class="container">
    <main class="container">

    <nav class="top-links no-print"></nav>

        <a class="btn btn-light" href="dashboard.php">
            ← Dashboard
        </a>

        <a class="btn btn-light" href="index.php">
            Public Website
        </a>

        <a class="btn btn-light" href="financial_dashboard.php">
            Financial Dashboard
        </a>

        <a class="btn" href="financial_print.php">
            Print Financial Report
        </a>
    </nav>

    <?php if ($message !== ''): ?>
        <div class="notice success">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <section class="summary">

        <div class="card">
            <p>Total Income</p>
            <h2>
                KSh <?= number_format(
                    (float) $summary['total_income'],
                    2
                ) ?>
            </h2>
        </div>

        <div class="card">
            <p>Total Expenses</p>
            <h2>
                KSh <?= number_format(
                    (float) $summary['total_expenses'],
                    2
                ) ?>
            </h2>
        </div>

        <div class="card">
            <p>Current Balance</p>
            <h2>
                KSh <?= number_format($balance, 2) ?>
            </h2>
        </div>

        <div class="card">
            <p>Transactions</p>
            <h2>
                <?= (int) $summary['total_transactions'] ?>
            </h2>
        </div>

    </section>

    <?php if ($canEdit): ?>

        <section class="panel no-print">

            <h2>
                <?= $editRecord
                    ? 'Edit Financial Record'
                    : 'Record Income or Expense' ?>
            </h2>

            <p class="muted">
                Enter the transaction details carefully.
                Link expenses to an approved event budget when applicable.
            </p>

            <form method="POST" action="financial_records.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['csrf_token']) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="<?= $editRecord ? 'update' : 'create' ?>"
                >

                <?php if ($editRecord): ?>
                    <input
                        type="hidden"
                        name="record_id"
                        value="<?= (int) $editRecord['id'] ?>"
                    >
                <?php endif; ?>

                <div class="form-grid">

                    <div class="field">
                        <label for="transaction_date">
                            Transaction Date *
                        </label>

                        <input
                            type="date"
                            id="transaction_date"
                            name="transaction_date"
                            value="<?= h(
                                $editRecord['transaction_date']
                                ?? date('Y-m-d')
                            ) ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="transaction_type">
                            Transaction Type *
                        </label>

                        <select
                            id="transaction_type"
                            name="transaction_type"
                            required
                        >
                            <option value="">Select type</option>

                            <option
                                value="income"
                                <?= ($editRecord['transaction_type'] ?? '') === 'income'
                                    ? 'selected' : '' ?>
                            >
                                Income
                            </option>

                            <option
                                value="expense"
                                <?= ($editRecord['transaction_type'] ?? '') === 'expense'
                                    ? 'selected' : '' ?>
                            >
                                Expense
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="category">Category *</label>

                        <input
                            type="text"
                            id="category"
                            name="category"
                            maxlength="100"
                            value="<?= h($editRecord['category'] ?? '') ?>"
                            placeholder="e.g. Registration fees, Transport"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="amount">Amount (KSh) *</label>

                        <input
                            type="number"
                            id="amount"
                            name="amount"
                            min="0.01"
                            max="9999999999.99"
                            step="0.01"
                            value="<?= h($editRecord['amount'] ?? '') ?>"
                            placeholder="0.00"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="event_id">Program / Event</label>

                        <select id="event_id" name="event_id">

                            <option value="">
                                General transaction / Unassigned
                            </option>

                            <?php foreach ($availableEvents as $event): ?>

                                <option
                                    value="<?= (int) $event['id'] ?>"
                                    data-approved-budget="<?= (int) $event['has_approved_budget'] ?>"
                                    <?= (string) ($editRecord['event_id'] ?? '') ===
                                        (string) $event['id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= h($event['title']) ?>
                                    —
                                    <?= $event['has_approved_budget']
                                        ? 'Budget approved'
                                        : 'No approved budget' ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <small class="muted">
                            Expenses must be linked to events with approved
                            budgets. General transactions may remain unassigned.
                        </small>
                    </div>

                    <div class="field">
                        <label for="reference_number">
                            Receipt / Reference Number
                        </label>

                        <input
                            type="text"
                            id="reference_number"
                            name="reference_number"
                            maxlength="100"
                            value="<?= h(
                                $editRecord['reference_number'] ?? ''
                            ) ?>"
                            placeholder="Optional reference"
                        >
                    </div>

                    <div class="field full">
                        <label for="description">Description *</label>

                        <textarea
                            id="description"
                            name="description"
                            maxlength="10000"
                            placeholder="Explain the income received or expense paid..."
                            required
                        ><?= h($editRecord['description'] ?? '') ?></textarea>
                    </div>

                    <div class="field full">

                        <button class="btn" type="submit">
                            <?= $editRecord
                                ? 'Update Financial Record'
                                : 'Save Financial Entry' ?>
                        </button>

                        <?php if ($editRecord): ?>
                            <a
                                class="btn btn-light"
                                href="financial_records.php"
                            >
                                Cancel Editing
                            </a>
                        <?php endif; ?>

                    </div>

                </div>
            </form>
        </section>

    <?php else: ?>

        <section class="panel">
            <h2>Read-only Access</h2>

            <p>
                You can review financial records, but only the Treasurer
                can add or edit entries.
            </p>
        </section>

    <?php endif; ?>

    <section class="panel">

        <h2>Transaction History</h2>

        <?php if (empty($records)): ?>

            <p class="muted">
                No financial transactions have been recorded yet.
            </p>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Program / Event</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Reference</th>
                            <th>Recorded By</th>
                            <th>Last Updated By</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($records as $record): ?>

                            <tr>

                                <td>
                                    <?= h($record['transaction_date']) ?>
                                </td>

                                <td class="<?= h($record['transaction_type']) ?>">
                                    <?= h(
                                        ucfirst($record['transaction_type'])
                                    ) ?>
                                </td>

                                <td>
                                    <?= h($record['category']) ?>
                                </td>

                                <td>
                                    <?php if (!empty($record['event_title'])): ?>

                                        <span class="event-name">
                                            <?= h($record['event_title']) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="muted">
                                            General / Unassigned
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= nl2br(h($record['description'])) ?>
                                </td>

                                <td>
                                    <strong>
                                        KSh <?= number_format(
                                            (float) $record['amount'],
                                            2
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= h(
                                        $record['reference_number'] ?: '—'
                                    ) ?>
                                </td>

                                <td>
                                    <?= h($record['recorder_name']) ?>
                                </td>

                                <td>
                                    <?php if (!empty($record['updated_by'])): ?>

                                        <?= h(
                                            $record['updater_name']
                                            ?: 'Unknown user'
                                        ) ?>

                                        <br>

                                        <small class="muted">
                                            <?= h(
                                                $record['updated_at'] ?? ''
                                            ) ?>
                                        </small>

                                    <?php else: ?>

                                        <span class="muted">
                                            Not edited
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($canEdit): ?>

                                        <a
                                            class="btn"
                                            href="financial_records.php?edit=<?= (int) $record['id'] ?>"
                                        >
                                            Edit
                                        </a>

                                    <?php else: ?>

                                        <span class="muted">
                                            Read only
                                        </span>

                                    <?php endif; ?>
                                </td>
                                <td class="no-print">
    <a class="btn" href="financial_records.php?edit=...">Edit</a>
</td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('transaction_type');
    const eventSelect = document.getElementById('event_id');

    if (!typeSelect || !eventSelect) {
        return;
    }

    function filterEvents() {
        const isExpense = typeSelect.value === 'expense';

        Array.from(eventSelect.options).forEach(function (option) {
            if (!option.value) {
                return;
            }

            const hasApprovedBudget =
                option.dataset.approvedBudget === '1';

            const unavailable = isExpense && !hasApprovedBudget;

            option.disabled = unavailable;
            option.hidden = unavailable;
        });

        const selectedOption = eventSelect.selectedOptions[0];

        if (selectedOption && selectedOption.disabled) {
            eventSelect.value = '';
        }
    }

    typeSelect.addEventListener('change', filterEvents);
    filterEvents();
});
</script>

</body>
</html>
