
<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT role, account_status FROM users WHERE id = ?"
);
$stmt->execute([(int) $_SESSION['user_id']]);
$user = $stmt->fetch();

$allowedRoles = ['chairperson', 'secretary', 'treasurer'];

if (
    !$user ||
    $user['account_status'] !== 'active' ||
    !in_array($user['role'], $allowedRoles, true)
) {
    http_response_code(403);
    exit('Access denied.');
}

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

function validExportDate($value) {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

if (
    !validExportDate($from) ||
    !validExportDate($to) ||
    $from > $to
) {
    http_response_code(400);
    exit('Invalid date range.');
}

$stmt = $pdo->prepare(
    "SELECT transaction_date, transaction_type, category,
            description, amount, reference_number
     FROM financial_records
     WHERE transaction_date BETWEEN ? AND ?
     ORDER BY transaction_date DESC, id DESC"
);
$stmt->execute([$from, $to]);

header('Content-Type: text/csv; charset=UTF-8');
header(
    'Content-Disposition: attachment; filename="youth_connect_financial_report.csv"'
);

$output = fopen('php://output', 'w');

// UTF-8 BOM helps Excel display special characters correctly.
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Transaction Date',
    'Type',
    'Category',
    'Description',
    'Amount (KSh)',
    'Reference Number'
]);

while ($record = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Prevent spreadsheet formula injection in text fields.
    foreach ([
        'transaction_type',
        'category',
        'description',
        'reference_number'
    ] as $field) {
        if (
            isset($record[$field]) &&
            preg_match('/^[\s]*[=+\-@]/u', $record[$field])
        ) {
            $record[$field] = "'" . $record[$field];
        }
    }

    fputcsv($output, [
        $record['transaction_date'],
        $record['transaction_type'],
        $record['category'],
        $record['description'],
        $record['amount'],
        $record['reference_number']
    ]);
}

fclose($output);
exit;
