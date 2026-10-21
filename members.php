
<?php
session_start();
require_once __DIR__ . '/config/database.php';

function escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// 1. Allow active administrators only.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

$adminStmt = $pdo->prepare(
    "SELECT account_status FROM users WHERE id = :id LIMIT 1"
);
$adminStmt->execute(['id' => (int) $_SESSION['user_id']]);
$adminStatus = $adminStmt->fetchColumn();

if ($adminStatus !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// 2. Generate a CSRF token.
if (empty($_SESSION['members_csrf'])) {
    $_SESSION['members_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$successMessage = $_SESSION['members_message'] ?? '';
unset($_SESSION['members_message']);

// 3. Process account status changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['account_status'] ?? '';

    if (
        !is_string($token) ||
        !isset($_SESSION['members_csrf']) ||
        !hash_equals($_SESSION['members_csrf'], $token)
    ) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    if (!$memberId || $memberId < 1) {
        $errors[] = 'Invalid member selected.';
    }

    $allowedStatuses = ['pending', 'active', 'suspended'];

    if (!in_array($newStatus, $allowedStatuses, true)) {
        $errors[] = 'Please select a valid account status.';
    }

    if (!$errors) {
        try {
            // Only member accounts can be changed on this page.
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = :new_status
                 WHERE id = :member_id
                   AND role = 'member'"
            );

            $stmt->execute([
                'new_status' => $newStatus,
                'member_id' => $memberId
            ]);

            if ($stmt->rowCount() > 0) {
                $_SESSION['members_message'] =
                    'Member account status updated successfully.';
            } else {
                // Check whether the member exists and already has this status.
                $check = $pdo->prepare(
                    "SELECT account_status
                     FROM users
                     WHERE id = :id AND role = 'member'
                     LIMIT 1"
                );
                $check->execute(['id' => $memberId]);
                $currentStatus = $check->fetchColumn();

                if ($currentStatus === false) {
                    $errors[] = 'The selected member could not be found.';
                } elseif ($currentStatus === $newStatus) {
                    $_SESSION['members_message'] =
                        'The member already has that account status.';
                } else {
                    $errors[] = 'The account status could not be updated.';
                }
            }

            if (!$errors) {
                $_SESSION['members_csrf'] = bin2hex(random_bytes(32));

                // Redirect after POST to prevent accidental resubmission.
                header('Location: members.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Unable to update the account. Please try again.';
        }
    }
}

// 4. Search and filter members.
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

$validFilters = ['all', 'pending', 'active', 'suspended'];

if (!in_array($statusFilter, $validFilters, true)) {
    $statusFilter = 'all';
}

if (strlen($search) > 150) {
    $search = substr($search, 0, 150);
}

// 5. Get membership statistics.
$statsStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total_members,
        COALESCE(SUM(account_status = 'active'), 0) AS active_members,
        COALESCE(SUM(account_status = 'pending'), 0) AS pending_members,
        COALESCE(SUM(account_status = 'suspended'), 0) AS suspended_members
     FROM users
     WHERE role = 'member'"
);

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// 6. Fetch the filtered member list.
$sql = "SELECT id, full_name, email, phone, account_status, created_at
        FROM users
        WHERE role = 'member'";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        full_name LIKE :name_search
        OR email LIKE :email_search
        OR phone LIKE :phone_search
    )";

    $term = '%' . $search . '%';

    $params['name_search'] = $term;
    $params['email_search'] = $term;
    $params['phone_search'] = $term;
}

if ($statusFilter !== 'all') {
    $sql .= " AND account_status = :status";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY
    CASE account_status
        WHEN 'pending' THEN 1
        WHEN 'active' THEN 2
        WHEN 'suspended' THEN 3
        ELSE 4
    END,
    created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

function statusLabel(string $status): string
{
    return match ($status) {
        'active' => 'Active',
        'pending' => 'Pending Approval',
        'suspended' => 'Suspended',
        default => ucfirst($status)
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Management | Youth Connect</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .members-page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .members-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .members-header h1 {
            margin: 0 0 8px;
            color: #183153;
        }

        .members-header p {
            margin: 0;
            color: #64748b;
        }

        .members-back {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 8px;
            background: #183153;
            color: white;
            text-decoration: none;
        }

        .members-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .members-stat {
            background: white;
            padding: 22px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 14px rgba(0,0,0,.04);
        }

        .members-stat p {
            margin: 0 0 10px;
            color: #64748b;
            font-size: 14px;
        }

        .members-stat h2 {
            margin: 0;
            font-size: 28px;
            color: #183153;
        }

        .members-panel {
            background: white;
            padding: 22px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 24px;
        }

        .members-filters {
            display: grid;
            grid-template-columns: minmax(200px, 1fr) minmax(160px, 220px) auto auto;
            gap: 12px;
            align-items: end;
        }

        .members-filters label,
        .member-status-form label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }

        .members-filters input,
        .members-filters select,
        .member-status-form select {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: white;
        }

        .members-btn {
            display: inline-block;
            padding: 10px 15px;
            border: 0;
            border-radius: 7px;
            background: #1d4ed8;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .members-btn:hover {
            background: #1e40af;
        }

        .members-btn-secondary {
            background: #64748b;
        }

        .members-btn-secondary:hover {
            background: #475569;
        }

        .members-alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .members-alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .members-alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .members-table-wrap {
            overflow-x: auto;
        }

        .members-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        .members-table th,
        .members-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .members-table th {
            background: #f8fafc;
            color: #334155;
            font-size: 13px;
        }

        .members-table td {
            color: #475569;
            font-size: 14px;
        }

        .member-name {
            font-weight: 600;
            color: #183153;
        }

        .member-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .member-status-form {
            min-width: 155px;
        }

        .member-status-form button {
            margin-top: 8px;
            width: 100%;
        }

        .members-empty {
            padding: 35px 15px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 900px) {
            .members-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .members-filters {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 520px) {
            .members-page {
                padding: 20px 12px;
            }

            .members-stats {
                gap: 10px;
            }

            .members-stat {
                padding: 16px;
            }

            .members-stat h2 {
                font-size: 23px;
            }

            .members-filters {
                grid-template-columns: 1fr;
            }

            .members-panel {
                padding: 15px;
            }
        }
    </style>
</head>
<body>

<main class="members-page">

    <header class="members-header">
        <div>
            <h1>Member Management</h1>
            <p>Manage membership applications and account access.</p>
        </div>

        <a href="dashboard.php" class="members-back">
            ← Back to Dashboard
        </a>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="members-alert members-alert-success" role="status">
            <?= escape($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="members-alert members-alert-error" role="alert">
            <strong>Please check the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="members-stats">

        <article class="members-stat">
            <p>Total Members</p>
            <h2><?= (int) $stats['total_members'] ?></h2>
        </article>

        <article class="members-stat">
            <p>Active Members</p>
            <h2><?= (int) $stats['active_members'] ?></h2>
        </article>

        <article class="members-stat">
            <p>Pending Approval</p>
            <h2><?= (int) $stats['pending_members'] ?></h2>
        </article>

        <article class="members-stat">
            <p>Suspended Members</p>
            <h2><?= (int) $stats['suspended_members'] ?></h2>
        </article>

    </section>

    <section class="members-panel">
        <h2>Find Members</h2>

        <form method="GET" action="members.php" class="members-filters">

            <div>
                <label for="search">Search</label>
                <input
                    type="search"
                    id="search"
                    name="search"
                    placeholder="Name, email or phone"
                    value="<?= escape($search) ?>"
                >
            </div>

            <div>
                <label for="status">Account Status</label>
                <select id="status" name="status">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                        All Members
                    </option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>
                        Pending
                    </option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>
                    <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>
                        Suspended
                    </option>
                </select>
            </div>

            <button type="submit" class="members-btn">Search</button>

            <a href="members.php" class="members-btn members-btn-secondary">
                Reset
            </a>

        </form>
    </section>

    <section class="members-panel">
        <h2>Registered Members</h2>

        <p>
            Showing <?= count($members) ?> member(s) matching your search.
        </p>

        <div class="members-table-wrap">
            <table class="members-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Member</th>
                        <th>Contact</th>
                        <th>Date Registered</th>
                        <th>Status</th>
                        <th>Manage Account</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$members): ?>
                    <tr>
                        <td colspan="6" class="members-empty">
                            No members found. Try another search or filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $index => $member): ?>
                        <?php
                            $status = $member['account_status'];
                            $badgeClass = match ($status) {
                                'active' => 'badge-active',
                                'pending' => 'badge-pending',
                                'suspended' => 'badge-suspended',
                                default => ''
                            };
                        ?>

                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <div class="member-name">
                                    <?= escape($member['full_name']) ?>
                                </div>
                                <small>
                                    Member ID: <?= (int) $member['id'] ?>
                                </small>
                            </td>

                            <td>
                                <?= escape($member['email']) ?>
                                <br>
                                <?= escape($member['phone'] ?: 'No phone provided') ?>
                            </td>

                            <td>
                                <?= $member['created_at']
                                    ? escape(date(
                                        'd M Y',
                                        strtotime($member['created_at'])
                                    ))
                                    : '—' ?>
                            </td>

                            <td>
                                <span class="member-badge <?= escape($badgeClass) ?>">
                                    <?= escape(statusLabel($status)) ?>
                                </span>
                            </td>

                            <td>
                                <form
                                    method="POST"
                                    action="members.php<?= $search !== '' || $statusFilter !== 'all'
                                        ? '?' . http_build_query([
                                            'search' => $search,
                                            'status' => $statusFilter
                                        ])
                                        : '' ?>"
                                    class="member-status-form"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= escape($_SESSION['members_csrf']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="member_id"
                                        value="<?= (int) $member['id'] ?>"
                                    >

                                    <label for="status-<?= (int) $member['id'] ?>">
                                        Change status
                                    </label>

                                    <select
                                        id="status-<?= (int) $member['id'] ?>"
                                        name="account_status"
                                        required
                                    >
                                        <option value="pending"
                                            <?= $status === 'pending' ? 'selected' : '' ?>>
                                            Pending
                                        </option>

                                        <option value="active"
                                            <?= $status === 'active' ? 'selected' : '' ?>>
                                            Active
                                        </option>

                                        <option value="suspended"
                                            <?= $status === 'suspended' ? 'selected' : '' ?>>
                                            Suspended
                                        </option>
                                    </select>

                                    <button type="submit" class="members-btn">
                                        Save Status
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

</body>
</html>
