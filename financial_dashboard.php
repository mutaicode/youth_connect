
<?php
session_start();

require_once __DIR__ . '/config/database.php';

/*
|--------------------------------------------------------------------------
| 1. Authentication and permissions
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT role, account_status
     FROM users
     WHERE id = :id
     LIMIT 1"
);
$stmt->execute(['id' => $userId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

$allowedRoles = ['chairperson', 'secretary', 'treasurer'];

if (
    !$currentUser ||
    strtolower((string) $currentUser['account_status']) !== 'active' ||
    !in_array(
        strtolower((string) $currentUser['role']),
        $allowedRoles,
        true
    )
) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view financial information.');
}

$role = strtolower((string) $currentUser['role']);

/*
|--------------------------------------------------------------------------
| 2. Helper functions
|--------------------------------------------------------------------------
*/

function h($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function money($amount): string
{
    return 'KSh ' . number_format((float) $amount, 2);
}

/*
|--------------------------------------------------------------------------
| 3. Date filters
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');

$from = $_GET['from'] ?? $firstDayOfMonth;
$to = $_GET['to'] ?? $today;

if (
    !is_string($from) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ||
    !strtotime($from) ||
    date('Y-m-d', strtotime($from)) !== $from
) {
    $from = $firstDayOfMonth;
}

if (
    !is_string($to) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ||
    !strtotime($to) ||
    date('Y-m-d', strtotime($to)) !== $to
) {
    $to = $today;
}

if ($from > $to) {
    $from = $firstDayOfMonth;
    $to = $today;
}

/*
|--------------------------------------------------------------------------
| 4. Financial summary for the selected date range
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT
        COALESCE(
            SUM(CASE
                WHEN transaction_type = 'income'
                THEN amount ELSE 0
            END), 0
        ) AS income,

        COALESCE(
            SUM(CASE
                WHEN transaction_type = 'expense'
                THEN amount ELSE 0
            END), 0
        ) AS expenses,

        COUNT(*) AS transaction_count

     FROM financial_records
     WHERE DATE(transaction_date) BETWEEN :from_date AND :to_date"
);

$stmt->execute([
    'from_date' => $from,
    'to_date' => $to
]);

$summary = $stmt->fetch(PDO::FETCH_ASSOC);

$totalIncome = (float) $summary['income'];
$totalExpenses = (float) $summary['expenses'];
$balance = $totalIncome - $totalExpenses;
$transactionCount = (int) $summary['transaction_count'];

/*
|--------------------------------------------------------------------------
| 5. Monthly income and expenses
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT
        DATE_FORMAT(transaction_date, '%Y-%m') AS month_key,

        COALESCE(
            SUM(CASE
                WHEN transaction_type = 'income'
                THEN amount ELSE 0
            END), 0
        ) AS income,

        COALESCE(
            SUM(CASE
                WHEN transaction_type = 'expense'
                THEN amount ELSE 0
            END), 0
        ) AS expenses

     FROM financial_records
     WHERE DATE(transaction_date) BETWEEN :from_date AND :to_date
     GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
     ORDER BY month_key ASC"
);

$stmt->execute([
    'from_date' => $from,
    'to_date' => $to
]);

$monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$maxMonthlyAmount = 0;

foreach ($monthlyData as $month) {
    $maxMonthlyAmount = max(
        $maxMonthlyAmount,
        (float) $month['income'],
        (float) $month['expenses']
    );
}

/*
|--------------------------------------------------------------------------
| 6. Transactions for the selected period
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT
        fr.id,
        fr.transaction_date,
        fr.transaction_type,
        fr.category,
        fr.description,
        fr.amount,
        fr.reference_number,
        fr.event_id,
        e.title AS event_title

     FROM financial_records fr

     LEFT JOIN events e
        ON e.id = fr.event_id

     WHERE DATE(fr.transaction_date)
        BETWEEN :from_date AND :to_date

     ORDER BY fr.transaction_date DESC, fr.id DESC"
);

$stmt->execute([
    'from_date' => $from,
    'to_date' => $to
]);

$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 7. Approved event budget summary
|--------------------------------------------------------------------------
| Budgets are all-time figures, independent of the date filter above.
| Only expenses linked to an event count against its approved budget.
*/

$stmt = $pdo->query(
    "SELECT
        COALESCE(SUM(b.approved_amount), 0) AS approved_total,

        COALESCE(
            SUM(COALESCE(expenses.total_expenditure, 0)), 0
        ) AS expenditure_total

     FROM program_budgets b

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

     WHERE b.status = 'approved'"
);

$budgetSummary = $stmt->fetch(PDO::FETCH_ASSOC);

$approvedBudgetTotal = (float) $budgetSummary['approved_total'];
$budgetExpenditureTotal = (float) $budgetSummary['expenditure_total'];
$remainingBudgetTotal =
    $approvedBudgetTotal - $budgetExpenditureTotal;

/*
|--------------------------------------------------------------------------
| 8. Individual event budgets and budget alerts
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query(
    "SELECT
        e.id AS event_id,
        e.title AS event_title,
        e.start_datetime,
        b.approved_amount,

        COALESCE(
            expenses.total_expenditure, 0
        ) AS expenditure

     FROM program_budgets b

     INNER JOIN events e
        ON e.id = b.event_id

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

     WHERE b.status = 'approved'
     ORDER BY e.start_datetime DESC"
);

$eventBudgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$overBudgetCount = 0;
$nearlyExhaustedCount = 0;
$withinBudgetCount = 0;

foreach ($eventBudgets as &$event) {
    $approved = (float) $event['approved_amount'];
    $spent = (float) $event['expenditure'];

    $event['remaining'] = $approved - $spent;

    if ($spent > $approved) {
        $event['budget_status'] = 'Over Budget';
        $event['status_class'] = 'over-budget';
        $overBudgetCount++;
    } elseif ($approved > 0 && ($spent / $approved) >= 0.80) {
        $event['budget_status'] = 'Nearly Exhausted';
        $event['status_class'] = 'nearly-exhausted';
        $nearlyExhaustedCount++;
    } else {
        $event['budget_status'] = 'Within Budget';
        $event['status_class'] = 'within-budget';
        $withinBudgetCount++;
    }

    $event['percentage_used'] = $approved > 0
        ? ($spent / $approved) * 100
        : 0;
}
unset($event);

$totalApprovedEvents = count($eventBudgets);
$alertCount = $overBudgetCount + $nearlyExhaustedCount;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Financial Dashboard | Youth Connect</title>
    

    <style>
        :root {
            --primary: #174c35;
            --primary-dark: #103825;
            --background: #f3f6f4;
            --white: #ffffff;
            --text: #24332b;
            --muted: #66756c;
            --border: #dce5df;
            --green: #15803d;
            --orange: #b45309;
            --red: #b91c1c;
            --blue: #1d4ed8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.5;
        }

        .topbar {
            background: var(--primary);
            color: white;
            padding: 20px 28px;
        }

        .topbar-inner {
            max-width: 1450px;
            margin: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .topbar h1 {
            margin: 0;
            font-size: 25px;
        }

        .topbar p {
            margin: 5px 0 0;
            color: #dcece2;
        }

        .role-label {
            display: inline-block;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.25);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            text-transform: capitalize;
        }

        .container {
            max-width: 1450px;
            margin: 25px auto;
            padding: 0 22px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .page-heading h2 {
            margin: 0;
            font-size: 24px;
        }

        .muted,
        .legend {
            color: var(--muted);
            font-size: 13px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            display: inline-block;
            text-decoration: none;
            border: none;
            cursor: pointer;
            background: var(--primary);
            color: white;
            padding: 10px 15px;
            border-radius: 7px;
            font-size: 14px;
        }

        .btn:hover {
            background: var(--primary-dark);
        }

        .btn-light {
            background: white;
            color: var(--primary);
            border: 1px solid var(--border);
        }

        .btn-light:hover {
            background: #edf4ef;
        }

        .filter-panel,
        .panel {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(20, 50, 30, .04);
        }

        .filter-panel form {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 15px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field label {
            font-weight: 600;
            font-size: 13px;
        }

        input[type="date"] {
            border: 1px solid #cbd5ce;
            border-radius: 7px;
            padding: 10px;
            min-width: 175px;
            font: inherit;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            background: white;
            padding: 21px;
            border: 1px solid var(--border);
            border-radius: 12px;
            border-top: 4px solid var(--primary);
            box-shadow: 0 3px 12px rgba(20, 50, 30, .04);
            min-width: 0;
        }

        .card p {
            margin: 0 0 10px;
            color: var(--muted);
            font-size: 14px;
        }

        .card h2 {
            margin: 0;
            font-size: clamp(20px, 2.1vw, 27px);
            overflow-wrap: anywhere;
        }

        .card.income {
            border-top-color: var(--green);
        }

        .card.expenses {
            border-top-color: var(--red);
        }

        .card.balance {
            border-top-color: var(--blue);
        }

        .card.alert-card {
            border-top-color: var(--orange);
        }

        .panel h2 {
            margin: 0 0 6px;
            font-size: 20px;
        }

        .panel-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 15px;
        }

        .alert-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 18px 0;
        }

        .alert-box {
            border-radius: 9px;
            padding: 15px;
            border: 1px solid var(--border);
        }

        .alert-box strong {
            display: block;
            font-size: 24px;
            margin-bottom: 3px;
        }

        .alert-box span {
            font-size: 13px;
        }

        .alert-box.green {
            background: #f0fdf4;
            color: #166534;
        }

        .alert-box.orange {
            background: #fff7ed;
            color: #9a3412;
        }

        .alert-box.red {
            background: #fef2f2;
            color: #991b1b;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            min-width: 760px;
        }

        th,
        td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #e8eeea;
            vertical-align: middle;
            font-size: 14px;
        }

        th {
            background: #f3f7f4;
            color: #304a3a;
            font-weight: 700;
            white-space: nowrap;
        }

        tbody tr:hover {
            background: #fafcfb;
        }

        .income-text {
            color: var(--green);
            font-weight: 700;
        }

        .expense-text {
            color: var(--red);
            font-weight: 700;
        }

        .status {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .within-budget {
            background: #dcfce7;
            color: #166534;
        }

        .nearly-exhausted {
            background: #ffedd5;
            color: #9a3412;
        }

        .over-budget {
            background: #fee2e2;
            color: #991b1b;
        }

        .progress-track {
            height: 8px;
            min-width: 100px;
            background: #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 7px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
        }

        .progress-fill.within-budget {
            background: #16a34a;
        }

        .progress-fill.nearly-exhausted {
            background: #ea580c;
        }

        .progress-fill.over-budget {
            background: #dc2626;
        }

        .bar-chart {
            display: flex;
            flex-direction: column;
            gap: 18px;
            margin-top: 22px;
        }

        .month-row {
            display: grid;
            grid-template-columns: 110px minmax(0, 1fr);
            gap: 15px;
            align-items: start;
        }

        .month-name {
            font-size: 13px;
            font-weight: 700;
            padding-top: 4px;
        }

        .bar-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .bar-line {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bar-label {
            font-size: 12px;
            width: 65px;
            flex-shrink: 0;
        }

        .bar-track {
            flex: 1;
            height: 16px;
            background: #eef2ef;
            border-radius: 5px;
            overflow: hidden;
        }

        .bar {
            height: 100%;
            min-width: 0;
            border-radius: 5px;
        }

        .income-bar {
            background: #16a34a;
        }

        .expense-bar {
            background: #dc2626;
        }

        .bar-amount {
            width: 125px;
            max-width: 35%;
            font-size: 12px;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: var(--muted);
        }

        .footer {
            text-align: center;
            color: var(--muted);
            padding: 15px 20px 30px;
            font-size: 13px;
        }

        @media (max-width: 850px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .alert-summary {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 520px) {
            .container {
                padding: 0 12px;
                margin-top: 15px;
            }

            .topbar {
                padding: 18px 15px;
            }

            .topbar h1 {
                font-size: 21px;
            }

            .cards {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .filter-panel,
            .panel {
                padding: 15px;
            }

            .field,
            input[type="date"] {
                width: 100%;
            }

            .filter-panel form .btn {
                width: 100%;
                text-align: center;
            }

            .month-row {
                grid-template-columns: 1fr;
                gap: 5px;
            }

            .bar-amount {
                width: 95px;
            }
        }

        @media print {
            body {
                background: white;
                color: black;
            }

            .topbar {
                background: white;
                color: black;
                border-bottom: 2px solid #333;
                padding: 10px 0;
            }

            .topbar p,
            .role-label {
                color: #333;
            }

            .filter-panel,
            .actions,
            .no-print {
                display: none !important;
            }

            .container {
                max-width: none;
                margin: 10px 0;
                padding: 0;
            }

            .panel,
            .card {
                box-shadow: none;
                break-inside: avoid;
            }

            .cards {
                grid-template-columns: repeat(3, 1fr);
            }

            .table-wrap {
                overflow: visible;
            }

            table {
                min-width: 0;
            }

            th,
            td {
                padding: 7px 5px;
                font-size: 10px;
            }

            .footer {
                display: none;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="topbar-inner">
        <div>
            <h1>AIC Kabartegan Region Youth Connect</h1>
            <p>Financial Management Dashboard</p>
        </div>

        <span class="role-label">
            Role: <?= h(ucfirst($role)) ?>
        </span>
    </div>
</header>

<main class="container">

    <div class="page-heading">
        <div>
            <h2>Financial Overview</h2>
            <p class="muted">
                Income, expenses, approved budgets and financial alerts.
            </p>
        </div>

        <div class="actions">
    <a class="btn btn-light" href="dashboard.php">
        Main Dashboard
    </a>

    <a class="btn btn-light" href="financial_records.php">
        Financial Records
    </a>

    <a class="btn btn-light" href="program_budgets.php">
        Program Budgets
    </a>

    <a class="btn btn-light" href="financial_audit.php">
        🔍 Audit Trail
    </a>

    <a class="btn btn-light"
       href="financial_export.php?from=<?= h($from) ?>&to=<?= h($to) ?>">
        ⬇️ Export CSV
    </a>

    <a class="btn btn-light"
       href="financial_print.php?from=<?= h($from) ?>&to=<?= h($to) ?>">
        🖨️ Print Report
    </a>

    <button class="btn" type="button" onclick="window.print()">
        Print Dashboard
    </button>
</div>

    <!-- Date filter -->
    <section class="filter-panel">
        <form method="GET" action="financial_dashboard.php">
            <div class="field">
                <label for="from">From date</label>
                <input
                    type="date"
                    id="from"
                    name="from"
                    value="<?= h($from) ?>"
                    max="<?= h($to) ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="to">To date</label>
                <input
                    type="date"
                    id="to"
                    name="to"
                    value="<?= h($to) ?>"
                    min="<?= h($from) ?>"
                    required
                >
            </div>

            <button class="btn" type="submit">
                Apply Filter
            </button>

            <a
                class="btn btn-light"
                href="financial_dashboard.php"
            >
                This Month
            </a>
        </form>

        <p class="legend">
            The date filter applies to income, expenses, monthly charts and
            transaction history. Approved budget balances use all recorded
            expenses linked to each approved event.
        </p>
    </section>

    <!-- Financial summary cards -->
    <section class="cards">

        <div class="card income">
            <p>Total Income (Selected Period)</p>
            <h2><?= h(money($totalIncome)) ?></h2>
        </div>

        <div class="card expenses">
            <p>Total Expenses (Selected Period)</p>
            <h2><?= h(money($totalExpenses)) ?></h2>
        </div>

        <div class="card balance">
            <p>Net Balance (Selected Period)</p>
            <h2 style="color: <?= $balance < 0 ? '#b91c1c' : '#15803d' ?>">
                <?= h(money($balance)) ?>
            </h2>
        </div>

        <div class="card">
            <p>Transactions (Selected Period)</p>
            <h2><?= number_format($transactionCount) ?></h2>
        </div>

        <div class="card">
            <p>Total Approved Budgets</p>
            <h2><?= h(money($approvedBudgetTotal)) ?></h2>
        </div>

        <div class="card <?= $alertCount > 0 ? 'alert-card' : '' ?>">
            <p>Remaining Approved Budget</p>
            <h2 style="color: <?= $remainingBudgetTotal < 0 ? '#b91c1c' : '#15803d' ?>">
                <?= h(money($remainingBudgetTotal)) ?>
            </h2>
        </div>

    </section>

    <!-- Budget alerts -->
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Budget Alerts</h2>
                <p class="legend">
                    Alerts are based on total expenditure against each approved
                    event budget.
                </p>
            </div>

            <?php if ($alertCount > 0): ?>
                <span class="status nearly-exhausted">
                    <?= number_format($alertCount) ?> event(s) need attention
                </span>
            <?php else: ?>
                <span class="status within-budget">
                    No budget alerts
                </span>
            <?php endif; ?>
        </div>

        <div class="alert-summary">
            <div class="alert-box green">
                <strong><?= number_format($withinBudgetCount) ?></strong>
                <span>Within Budget</span>
            </div>

            <div class="alert-box orange">
                <strong><?= number_format($nearlyExhaustedCount) ?></strong>
                <span>Nearly Exhausted (80% or more)</span>
            </div>

            <div class="alert-box red">
                <strong><?= number_format($overBudgetCount) ?></strong>
                <span>Over Budget</span>
            </div>
        </div>

        <?php if ($overBudgetCount > 0): ?>
            <p class="legend" style="color:#b91c1c;">
                Attention: one or more events have expenditure above their
                approved budget. Review these records with the appropriate
                financial officers.
            </p>
        <?php elseif ($nearlyExhaustedCount > 0): ?>
            <p class="legend" style="color:#9a3412;">
                Some events have used at least 80% of their approved budget.
                Review their remaining funds before authorizing further
                expenditure.
            </p>
        <?php endif; ?>
    </section>

    <!-- Monthly chart -->
    <section class="panel">
        <h2>Monthly Income and Expenses</h2>
        <p class="legend">
            Comparison for the selected date range.
        </p>

        <?php if (!$monthlyData): ?>
            <div class="empty">
                No financial transactions were found for this period.
            </div>
        <?php else: ?>
            <div class="bar-chart">
                <?php foreach ($monthlyData as $month): ?>
                    <?php
                    $monthIncome = (float) $month['income'];
                    $monthExpenses = (float) $month['expenses'];

                    $incomeWidth = $maxMonthlyAmount > 0
                        ? ($monthIncome / $maxMonthlyAmount) * 100
                        : 0;

                    $expenseWidth = $maxMonthlyAmount > 0
                        ? ($monthExpenses / $maxMonthlyAmount) * 100
                        : 0;

                    $monthTimestamp = strtotime(
                        $month['month_key'] . '-01'
                    );
                    ?>

                    <div class="month-row">
                        <div class="month-name">
                            <?= h(date('M Y', $monthTimestamp)) ?>
                        </div>

                        <div class="bar-group">
                            <div class="bar-line">
                                <span class="bar-label">Income</span>

                                <div class="bar-track">
                                    <div
                                        class="bar income-bar"
                                        style="width: <?= h($incomeWidth) ?>%;"
                                    ></div>
                                </div>

                                <span class="bar-amount income-text">
                                    <?= h(money($monthIncome)) ?>
                                </span>
                            </div>

                            <div class="bar-line">
                                <span class="bar-label">Expenses</span>

                                <div class="bar-track">
                                    <div
                                        class="bar expense-bar"
                                        style="width: <?= h($expenseWidth) ?>%;"
                                    ></div>
                                </div>

                                <span class="bar-amount expense-text">
                                    <?= h(money($monthExpenses)) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="legend" style="margin-top:20px;">
                <span class="income-text">■ Income</span>
                &nbsp;&nbsp;
                <span class="expense-text">■ Expenses</span>
            </p>
        <?php endif; ?>
    </section>

    <!-- Approved event budgets -->
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Approved Event Budgets</h2>
                <p class="legend">
                    Expenditure includes all recorded expenses linked to each
                    approved event, regardless of the date filter.
                </p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Approved Budget</th>
                        <th>Expenditure</th>
                        <th>Remaining Balance</th>
                        <th>Budget Used</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$eventBudgets): ?>
                        <tr>
                            <td colspan="6" class="empty">
                                No approved event budgets found.
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($eventBudgets as $event): ?>
                            <?php
                            $approved = (float) $event['approved_amount'];
                            $spent = (float) $event['expenditure'];
                            $remaining = (float) $event['remaining'];

                            // Cap the visual bar at 100%.
                            $progressWidth = min(
                                100,
                                max(0, (float) $event['percentage_used'])
                            );
                            ?>

                            <tr>
                                <td>
                                    <strong>
                                        <?= h($event['event_title']) ?>
                                    </strong>

                                    <br>

                                    <span class="legend">
                                        <?php
                                        $eventTimestamp = strtotime(
                                            (string) $event['start_datetime']
                                        );
                                        ?>
                                        <?= $eventTimestamp
                                            ? h(date('d M Y', $eventTimestamp))
                                            : 'Date not available' ?>
                                    </span>
                                </td>

                                <td><?= h(money($approved)) ?></td>

                                <td class="expense-text">
                                    <?= h(money($spent)) ?>
                                </td>

                                <td>
                                    <strong style="color: <?= $remaining < 0 ? '#b91c1c' : '#15803d' ?>">
                                        <?= h(money($remaining)) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= h(number_format(
                                        (float) $event['percentage_used'],
                                        1
                                    )) ?>%

                                    <div class="progress-track">
                                        <div
                                            class="progress-fill <?= h($event['status_class']) ?>"
                                            style="width: <?= h($progressWidth) ?>%;"
                                        ></div>
                                    </div>
                                </td>

                                <td>
                                    <span class="status <?= h($event['status_class']) ?>">
                                        <?= h($event['budget_status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Transaction history -->
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Transactions for Selected Period</h2>
                <p class="legend">
                    <?= h(date('d M Y', strtotime($from))) ?>
                    to
                    <?= h(date('d M Y', strtotime($to))) ?>
                </p>
            </div>

            <span class="muted">
                <?= number_format($transactionCount) ?> transaction(s)
            </span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Event</th>
                        <th>Reference</th>
                        <th>Amount</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$transactions): ?>
                        <tr>
                            <td colspan="7" class="empty">
                                No transactions found for the selected period.
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($transactions as $transaction): ?>
                            <?php
                            $isIncome =
                                $transaction['transaction_type'] === 'income';
                            ?>

                            <tr>
                                <td>
                                    <?php
                                    $transactionTimestamp = strtotime(
                                        (string) $transaction['transaction_date']
                                    );
                                    ?>
                                    <?= $transactionTimestamp
                                        ? h(date('d M Y', $transactionTimestamp))
                                        : '—' ?>
                                </td>

                                <td>
                                    <span class="status <?= $isIncome
                                        ? 'within-budget'
                                        : 'over-budget' ?>">
                                        <?= h(ucfirst(
                                            $transaction['transaction_type']
                                        )) ?>
                                    </span>
                                </td>

                                <td><?= h($transaction['category']) ?></td>

                                <td>
                                    <?= h(
                                        $transaction['description'] ?: '—'
                                    ) ?>
                                </td>

                                <td>
                                    <?= h(
                                        $transaction['event_title'] ?: '—'
                                    ) ?>
                                </td>

                                <td>
                                    <?= h(
                                        $transaction['reference_number'] ?: '—'
                                    ) ?>
                                </td>

                                <td class="<?= $isIncome
                                    ? 'income-text'
                                    : 'expense-text' ?>">
                                    <?= h(money($transaction['amount'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<footer class="footer">
    AIC Kabartegan Region Youth Connect
    <br>
    Financial information is restricted to authorized users.
</footer>

</body>
</html>
