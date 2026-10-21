
<?php
session_start();

require_once __DIR__ . '/config/database.php';

// 1. Check whether the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// 2. Fetch the current user's role and account status
$stmt = $pdo->prepare("
    SELECT role, account_status
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([(int) $_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. Allow only authorized financial officers
$allowedRoles = ['chairperson', 'secretary', 'treasurer'];

if (
    !$currentUser ||
    $currentUser['account_status'] !== 'active' ||
    !in_array($currentUser['role'], $allowedRoles, true)
) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view financial audit records.');
}

// 4. Read filters
$action = trim($_GET['action'] ?? '');
$searchUser = trim($_GET['user'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

$errors = [];

// Validate action filter
if ($action !== '' && !in_array($action, ['created', 'updated'], true)) {
    $errors[] = 'Invalid action filter.';
    $action = '';
}

// Validate dates
foreach ([$startDate, $endDate] as $date) {
    if ($date !== '') {
        $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);

        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            $errors[] = 'Please enter valid dates.';
            break;
        }
    }
}

if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    $errors[] = 'The start date cannot be later than the end date.';
}

// 5. Fetch audit history
$auditRows = [];

if (empty($errors)) {
    $sql = "
        SELECT
            a.id,
            a.financial_record_id,
            a.action,
            a.old_values,
            a.new_values,
            a.changed_by,
            a.changed_at,
            u.full_name AS changed_by_name
        FROM financial_audit_logs AS a
        LEFT JOIN users AS u
            ON u.id = a.changed_by
        WHERE 1 = 1
    ";

    $params = [];

    // Filter by action
    if ($action !== '') {
        $sql .= " AND a.action = ?";
        $params[] = $action;
    }

    // Filter by user name or ID
    if ($searchUser !== '') {
        $sql .= "
            AND (
                u.full_name LIKE ?
                OR CAST(a.changed_by AS CHAR) = ?
            )
        ";

        $params[] = '%' . $searchUser . '%';
        $params[] = $searchUser;
    }

    // Filter by starting date
    if ($startDate !== '') {
        $sql .= " AND a.changed_at >= ?";
        $params[] = $startDate . ' 00:00:00';
    }

    // Filter by ending date
    if ($endDate !== '') {
        $sql .= " AND a.changed_at <= ?";
        $params[] = $endDate . ' 23:59:59';
    }

    $sql .= " ORDER BY a.changed_at DESC, a.id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 6. Safely display values
function auditEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// 7. Read a value from stored JSON
function auditValue($json, $key)
{
    if ($json === null || $json === '') {
        return '—';
    }

    $values = json_decode($json, true);

    if (!is_array($values) || !array_key_exists($key, $values)) {
        return '—';
    }

    $value = $values[$key];

    if ($value === null || $value === '') {
        return '—';
    }

    if ($key === 'amount' && is_numeric($value)) {
        return 'KSh ' . number_format((float) $value, 2);
    }

    return (string) $value;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Financial Audit Trail | Youth Connect</title>
    

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #243047;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 24px;
        }

        h1 {
            margin: 0 0 8px;
            color: #17365d;
        }

        h2 {
            color: #17365d;
        }

        .muted {
            color: #64748b;
            font-size: 14px;
        }

        .card {
            background: #fff;
            padding: 22px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
            gap: 14px;
            align-items: end;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            font-size: 14px;
        }

        input,
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: #fff;
            font-size: 14px;
        }

        .button {
            display: inline-block;
            padding: 10px 15px;
            border: none;
            border-radius: 7px;
            background: #17365d;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            text-align: center;
            font-size: 14px;
        }

        .button:hover {
            background: #24518a;
        }

        .button.secondary {
            background: #64748b;
        }

        .button.secondary:hover {
            background: #475569;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            font-size: 14px;
        }

        th {
            background: #edf2f7;
            color: #17365d;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .created {
            color: #166534;
            font-weight: bold;
        }

        .updated {
            color: #92400e;
            font-weight: bold;
        }

        .error {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 7px;
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            padding: 25px;
            text-align: center;
            color: #64748b;
        }

        details summary {
            cursor: pointer;
            color: #17365d;
            font-weight: bold;
        }

        .change-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 12px;
            min-width: 400px;
        }

        .change-box {
            padding: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
            overflow-wrap: anywhere;
        }

        .change-box p {
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 800px) {
            body {
                padding: 12px;
            }

            .filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .card {
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .filters {
                grid-template-columns: 1fr;
            }

            .header {
                align-items: flex-start;
            }

            .change-grid {
                grid-template-columns: 1fr;
                min-width: 0;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="header">
        <div>
            <h1>Financial Audit Trail</h1>
            <p class="muted">
                A record of financial transactions created or updated in Youth Connect.
            </p>
        </div>

        <a class="button secondary" href="financial_dashboard.php">
            Back to Dashboard
        </a>
    </div>

    <!-- Filters -->
    <div class="card">
        <h2>Filter Audit Records</h2>

        <form method="GET" action="financial_audit.php" class="filters">

            <div>
                <label for="action">Action</label>

                <select name="action" id="action">
                    <option value="">All actions</option>

                    <option value="created"
                        <?= $action === 'created' ? 'selected' : '' ?>>
                        Created
                    </option>

                    <option value="updated"
                        <?= $action === 'updated' ? 'selected' : '' ?>>
                        Updated
                    </option>
                </select>
            </div>

            <div>
                <label for="user">User name or ID</label>

                <input
                    type="text"
                    id="user"
                    name="user"
                    value="<?= auditEscape($searchUser) ?>"
                    placeholder="Search user"
                >
            </div>

            <div>
                <label for="start_date">From date</label>

                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= auditEscape($startDate) ?>"
                >
            </div>

            <div>
                <label for="end_date">To date</label>

                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= auditEscape($endDate) ?>"
                >
            </div>

            <div>
                <button type="submit" class="button">
                    Apply Filters
                </button>
            </div>

            <div>
                <a href="financial_audit.php" class="button secondary">
                    Clear Filters
                </a>
            </div>

        </form>
    </div>

    <!-- Validation messages -->
    <?php foreach ($errors as $error): ?>
        <div class="error">
            <?= auditEscape($error) ?>
        </div>
    <?php endforeach; ?>

    <!-- Audit history -->
    <div class="card">
        <h2>Audit History</h2>

        <p class="muted">
            Showing up to 500 matching records, newest first.
        </p>

        <?php if (empty($auditRows)): ?>

            <div class="empty">
                No audit records found for the selected filters.
            </div>

        <?php else: ?>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Date and Time</th>
                        <th>Record ID</th>
                        <th>Action</th>
                        <th>Changed By</th>
                        <th>Details</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($auditRows as $row): ?>

                        <tr>
                            <td>
                                <?= auditEscape($row['changed_at']) ?>
                            </td>

                            <td>
                                #<?= (int) $row['financial_record_id'] ?>
                            </td>

                            <td>
                                <span class="<?= $row['action'] === 'created' ? 'created' : 'updated' ?>">
                                    <?= auditEscape(ucfirst($row['action'])) ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                echo auditEscape(
                                    $row['changed_by_name']
                                    ?? ('User ID ' . ($row['changed_by'] ?? 'Unknown'))
                                );
                                ?>
                            </td>

                            <td>
                                <details>
                                    <summary>View changes</summary>

                                    <?php
                                    $fields = [
                                        'transaction_date' => 'Transaction Date',
                                        'transaction_type' => 'Transaction Type',
                                        'category' => 'Category',
                                        'description' => 'Description',
                                        'amount' => 'Amount',
                                        'reference_number' => 'Reference Number',
                                        'event_id' => 'Event ID'
                                    ];
                                    ?>

                                    <div class="change-grid">

                                        <div class="change-box">
                                            <strong>Previous Values</strong>

                                            <?php foreach ($fields as $key => $label): ?>
                                                <p>
                                                    <strong><?= auditEscape($label) ?>:</strong>
                                                    <?= auditEscape(auditValue($row['old_values'], $key)) ?>
                                                </p>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="change-box">
                                            <strong>New Values</strong>

                                            <?php foreach ($fields as $key => $label): ?>
                                                <p>
                                                    <strong><?= auditEscape($label) ?>:</strong>
                                                    <?= auditEscape(auditValue($row['new_values'], $key)) ?>
                                                </p>
                                            <?php endforeach; ?>
                                        </div>

                                    </div>
                                </details>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>

</div>
</body>
</html>
