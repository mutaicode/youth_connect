<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT role, account_status
     FROM users WHERE id = ?"
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || $user['account_status'] !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

$allowedRoles = ['chairperson', 'secretary', 'treasurer'];

if (!in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied.');
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Validate optional date filters.
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

function validDateFilter($value) {
    if ($value === '') {
        return true;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value;
}

if (!validDateFilter($from) || !validDateFilter($to)) {
    http_response_code(400);
    exit('Invalid date filter.');
}

if ($from !== '' && $to !== '' && $from > $to) {
    http_response_code(400);
    exit('The start date must not be after the end date.');
}

// Financial transaction filter.
$where = [];
$params = [];

if ($from !== '') {
    $where[] = 'fr.transaction_date >= ?';
    $params[] = $from;
}

if ($to !== '') {
    $where[] = 'fr.transaction_date <= ?';
    $params[] = $to;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT fr.*, recorder.full_name AS recorder_name,
            updater.full_name AS updater_name
     FROM financial_records fr
     INNER JOIN users recorder ON recorder.id = fr.recorded_by
     LEFT JOIN users updater ON updater.id = fr.updated_by
     $whereSql
     ORDER BY fr.transaction_date DESC, fr.id DESC"
);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Calculate totals for the selected period.
$totalIncome = 0;
$totalExpenses = 0;

foreach ($records as $record) {
    if ($record['transaction_type'] === 'income') {
        $totalIncome += (float) $record['amount'];
    } else {
        $totalExpenses += (float) $record['amount'];
    }
}

$balance = $totalIncome - $totalExpenses;

// Audit history uses the date the change was made.
$auditWhere = [];
$auditParams = [];

if ($from !== '') {
    $auditWhere[] = 'DATE(a.changed_at) >= ?';
    $auditParams[] = $from;
}

if ($to !== '') {
    $auditWhere[] = 'DATE(a.changed_at) <= ?';
    $auditParams[] = $to;
}

$auditWhereSql = $auditWhere
    ? 'WHERE ' . implode(' AND ', $auditWhere)
    : '';

$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS changed_by_name
     FROM financial_audit_logs a
     INNER JOIN users u ON u.id = a.changed_by
     $auditWhereSql
     ORDER BY a.changed_at DESC, a.id DESC"
);
$stmt->execute($auditParams);
$auditLogs = $stmt->fetchAll();

function money($amount) {
    return 'KSh ' . number_format((float) $amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Printable Financial Report | Youth Connect</title>

<style>
* { box-sizing: border-box; }

body {
    font-family: Arial, sans-serif;
    margin: 25px;
    color: #222;
    background: #f3f6f4;
}

header {
    text-align: center;
    border-bottom: 2px solid #14532d;
    padding-bottom: 15px;
    margin-bottom: 20px;
}

header h1 { margin-bottom: 6px; color: #14532d; }
header p { margin: 4px; }

.toolbar, .filters {
    margin: 15px 0;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

button, .button {
    padding: 10px 14px;
    background: #166534;
    color: white;
    border: 0;
    border-radius: 5px;
    text-decoration: none;
    cursor: pointer;
}

input {
    padding: 8px;
    border: 1px solid #aaa;
    border-radius: 4px;
}

.summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin: 20px 0;
}

.summary div {
    background: white;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 6px;
}

.summary strong {
    display: block;
    margin-top: 8px;
    font-size: 18px;
}

h2 {
    color: #14532d;
    margin-top: 30px;
}

.table-wrap {
    overflow-x: auto;
    background: white;
}

table {
    border-collapse: collapse;
    width: 100%;
    background: white;
}

th, td {
    border: 1px solid #ccc;
    padding: 8px;
    text-align: left;
    font-size: 12px;
    overflow-wrap: anywhere;
}

th { background: #e8f0e9; }

.income { color: #166534; }
.expense { color: #b91c1c; }

.small { font-size: 12px; color: #555; }

@media (max-width: 650px) {
    body { margin: 10px; }
    .summary { grid-template-columns: 1fr; }
}

@media print {
    @page {
        size: A4 landscape;
        margin: 12mm;
    }

    body {
        background: white;
        margin: 0;
        color: black;
        font-size: 10px;
    }

    .no-print { display: none !important; }

    .summary {
        grid-template-columns: repeat(3, 1fr);
    }

    .summary div {
        padding: 8px;
        border: 1px solid #aaa;
    }

    h2 {
        page-break-after: avoid;
        color: black;
    }

    table {
        page-break-inside: auto;
    }

    tr {
        page-break-inside: avoid;
    }

    thead {
        display: table-header-group;
    }

    th, td {
        padding: 5px;
        font-size: 9px;
    }

    .table-wrap { overflow: visible; }
}
</style>
</head>

<body>

<header>
    <h1>AIC Kabartegan Region Youth Connect</h1>
    <h2>Financial Report</h2>
    <p>Prepared by: <?= h($user['role']) ?></p>
    <p>Report generated: <?= h(date('d M Y, H:i')) ?></p>

    <?php if ($from || $to): ?>
        <p>
            Period: <?= h($from ?: 'Beginning') ?>
            to <?= h($to ?: 'Present') ?>
        </p>
    <?php else: ?>
        <p>Period: All recorded transactions</p>
    <?php endif; ?>
</header>

<div class="toolbar no-print">
    <button type="button" onclick="window.print()">
        Print / Save as PDF
    </button>
    <a class="button" href="financial_records.php">
        Back to Financial Records
    </a>
</div>

<form method="GET" class="filters no-print">
    <label>
        From:
        <input type="date" name="from" value="<?= h($from) ?>">
    </label>

    <label>
        To:
        <input type="date" name="to" value="<?= h($to) ?>">
    </label>

    <button type="submit">Filter Report</button>
    <a class="button" href="financial_print.php">Show All</a>
</form>

<section class="summary">
    <div>
        Total Income
        <strong><?= h(money($totalIncome)) ?></strong>
    </div>

    <div>
        Total Expenses
        <strong><?= h(money($totalExpenses)) ?></strong>
    </div>

    <div>
        Balance for Selected Period
        <strong><?= h(money($balance)) ?></strong>
    </div>
</section>

<h2>1. Financial Transactions</h2>

<div class="table-wrap">
<table>
    <thead>
    <tr>
        <th>Date</th>
        <th>Type</th>
        <th>Category</th>
        <th>Description</th>
        <th>Amount</th>
        <th>Reference</th>
        <th>Recorded By</th>
        <th>Last Updated By</th>
        <th>Last Updated</th>
    </tr>
    </thead>

    <tbody>
    <?php if (!$records): ?>
        <tr>
            <td colspan="9">No transactions found for this period.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($records as $record): ?>
        <tr>
            <td><?= h($record['transaction_date']) ?></td>
            <td class="<?= h($record['transaction_type']) ?>">
                <?= h(ucfirst($record['transaction_type'])) ?>
            </td>
            <td><?= h($record['category']) ?></td>
            <td><?= nl2br(h($record['description'])) ?></td>
            <td><?= h(money($record['amount'])) ?></td>
            <td><?= h($record['reference_number'] ?: '—') ?></td>
            <td><?= h($record['recorder_name']) ?></td>
            <td><?= h($record['updater_name'] ?: '—') ?></td>
            <td><?= h($record['updated_at'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<h2>2. Financial Audit History</h2>
<p class="small">
    This section records transactions created or updated after audit logging
    was implemented.
</p>

<div class="table-wrap">
<table>
    <thead>
    <tr>
        <th>Change Date</th>
        <th>Transaction ID</th>
        <th>Action</th>
        <th>Changed By</th>
        <th>Previous Values</th>
        <th>New Values</th>
    </tr>
    </thead>

    <tbody>
    <?php if (!$auditLogs): ?>
        <tr>
            <td colspan="6">No audit entries found for this period.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($auditLogs as $log): ?>
        <tr>
            <td><?= h($log['changed_at']) ?></td>
            <td><?= (int) $log['financial_record_id'] ?></td>
            <td><?= h(ucfirst($log['action'])) ?></td>
            <td><?= h($log['changed_by_name']) ?></td>
            <td>
                <?php if ($log['old_values']): ?>
                    <pre><?= h(
                        json_encode(
                            json_decode($log['old_values'], true),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        )
                    ) ?></pre>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td>
                <pre><?= h(
                    json_encode(
                        json_decode($log['new_values'], true),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                    )
                ) ?></pre>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<footer>
    <p class="small">
        Confidential financial information. Share only with authorized officials.
    </p>
</footer>

</body>
</html>