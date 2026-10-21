<?php
session_start();
require_once __DIR__ . '/config/database.php';

$allowed = [
    'admin',
    'chairperson',
    'vice_chairperson',
    'secretary',
    'vice_secretary',
    'treasurer'
];

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], $allowed, true)
) {
    header('Location: login.php');
    exit;
}

function h($v): string {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$search = trim($_GET['search'] ?? '');
if (strlen($search) > 150) {
    $search = substr($search, 0, 150);
}

// Fetch active members with optional search
try {
    $sql = "SELECT
                id, full_name, email, phone, account_status, created_at
            FROM users
            WHERE role = 'member'
              AND account_status = 'active'";

    $params = [];
    if ($search !== '') {
        $sql .= " AND (
            full_name LIKE :s1
            OR email LIKE :s2
            OR phone LIKE :s3
        )";
        $term = '%' . $search . '%';
        $params['s1'] = $term;
        $params['s2'] = $term;
        $params['s3'] = $term;
    }

    $sql .= " ORDER BY full_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('Member directory error: ' . $ex->getMessage());
    $members = [];
    $error = 'Unable to load member directory.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Directory | Youth Connect</title>
        <link rel="stylesheet" href="assets/css/print.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f4f7f4;
            color: #243329;
        }
        .container { max-width: 1200px; margin: auto; }
        .head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 22px;
        }
        h1 { color: #14532d; margin: 0 0 6px; }
        .muted { color: #68756c; font-size: 14px; }
        .toplinks a {
            display: inline-block;
            padding: 10px 14px;
            background: #14532d;
            color: white;
            text-decoration: none;
            border-radius: 7px;
            margin-left: 8px;
            font-size: 14px;
        }
        .panel {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px #0000000a;
            margin-bottom: 20px;
        }
        .search-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: end;
        }
        .search-row > div { flex: 1; min-width: 200px; }
        label { display: block; font-weight: bold; font-size: 13px; margin-bottom: 6px; }
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5cc;
            border-radius: 7px;
            font-size: 14px;
        }
        .btn {
            display: inline-block;
            padding: 10px 15px;
            background: #14532d;
            color: white;
            border: 0;
            border-radius: 7px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #e5ebe5;
            font-size: 14px;
        }
        th { background: #f4f7f4; color: #506154; }
        .member-name { font-weight: bold; color: #14532d; }
        .empty { padding: 30px; text-align: center; color: #68756c; }
        .count { color: #68756c; font-size: 13px; margin-bottom: 12px; }
        @media print {
            body { background: white; padding: 0; }
            .toplinks, .search-row, .no-print { display: none !important; }
            .panel { box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="container">

    <div class="head no-print">

    <div class="head">
        <div>
            <h1>📇 Member Directory</h1>
            <p class="muted">
                Active Youth Connect members, sorted alphabetically.
            </p>
        </div>
        <div class="toplinks">
            <a href="dashboard.php">← Dashboard</a>
        </div>
    </div>

    <div class="panel no-print">
        <form method="GET" class="search-row">
            <div>
                <label for="search">Search by name, email or phone</label>
                <input type="search" id="search" name="search"
                       value="<?= h($search) ?>" placeholder="Search members…">
            </div>
            <button type="submit" class="btn">Search</button>
            <?php if ($search !== ''): ?>
                <a href="member_directory.php" class="btn"
                   style="background:#68756c;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <p class="count">
            <strong><?= count($members) ?></strong>
            active member<?= count($members) === 1 ? '' : 's' ?>
            <?= $search !== '' ? 'matching "' . h($search) . '"' : '' ?>
        </p>

        <?php if (empty($members)): ?>
            <div class="empty">
                <?= $search !== ''
                    ? 'No members match your search.'
                    : 'No active members yet.' ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Member Since</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $i => $m): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <span class="member-name">
                                        <?= h($m['full_name']) ?>
                                    </span>
                                </td>
                                <td><?= h($m['email']) ?></td>
                                <td><?= h($m['phone'] ?: '—') ?></td>
                                <td>
                                    <?= $m['created_at']
                                        ? h(date('d M Y', strtotime($m['created_at'])))
                                        : '—' ?>
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