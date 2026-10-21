<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];

$auth = $pdo->prepare(
    "SELECT role, account_status
     FROM users
     WHERE id = :id
     LIMIT 1"
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
$message = '';
$error = '';

$roles = [
    'admin' => 'System Administrator',
    'chairperson' => 'Chairperson',
    'vice_chairperson' => 'Vice Chairperson',
    'secretary' => 'Secretary',
    'vice_secretary' => 'Vice Secretary',
    'treasurer' => 'Treasurer',
    'member' => 'Youth Member'
];

$statuses = [
    'active' => 'Active',
    'pending' => 'Pending',
    'suspended' => 'Suspended'
];

// Generate a CSRF token.
if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['users_csrf'], $token)
    ) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create') {
                $name = trim($_POST['full_name'] ?? '');
                $email = strtolower(trim($_POST['email'] ?? ''));
                $phone = trim($_POST['phone'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? '';
                $status = $_POST['account_status'] ?? 'active';

                if ($name === '' || mb_strlen($name) > 150) {
                    throw new RuntimeException(
                        'Enter a name of no more than 150 characters.'
                    );
                }

                if (
                    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                    strlen($email) > 254
                ) {
                    throw new RuntimeException(
                        'Enter a valid email address.'
                    );
                }

                if (strlen($password) < 12) {
                    throw new RuntimeException(
                        'The password must contain at least 12 characters.'
                    );
                }

                if (!array_key_exists($role, $roles)) {
                    throw new RuntimeException('Select a valid user role.');
                }

                if (!array_key_exists($status, $statuses)) {
                    throw new RuntimeException('Select a valid account status.');
                }

                if ($phone !== '' && mb_strlen($phone) > 30) {
                    throw new RuntimeException(
                        'The phone number must not exceed 30 characters.'
                    );
                }

                $check = $pdo->prepare(
                    'SELECT id FROM users WHERE email = :email LIMIT 1'
                );
                $check->execute(['email' => $email]);

                if ($check->fetch()) {
                    throw new RuntimeException(
                        'That email address is already registered.'
                    );
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    'INSERT INTO users
                        (full_name, email, phone, password_hash, role, account_status)
                     VALUES
                        (:name, :email, :phone, :password_hash, :role, :status)'
                );

                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone !== '' ? $phone : null,
                    'password_hash' => $hash,
                    'role' => $role,
                    'status' => $status
                ]);

                $message = 'User account created successfully.';
            } elseif ($action === 'update') {
                $userId = filter_var(
                    $_POST['user_id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                $role = $_POST['role'] ?? '';
                $status = $_POST['account_status'] ?? '';

                if (!$userId || $userId < 1) {
                    throw new RuntimeException('Invalid user account.');
                }

                if (!array_key_exists($role, $roles)) {
                    throw new RuntimeException('Select a valid user role.');
                }

                if (!array_key_exists($status, $statuses)) {
                    throw new RuntimeException('Select a valid account status.');
                }

                if ($userId === $adminId) {
                    throw new RuntimeException(
                        'You cannot change your own role or account status here.'
                    );
                }

                $check = $pdo->prepare(
                    'SELECT id FROM users WHERE id = :id LIMIT 1'
                );
                $check->execute(['id' => $userId]);

                if (!$check->fetch()) {
                    throw new RuntimeException('User account not found.');
                }

                $stmt = $pdo->prepare(
                    'UPDATE users
                     SET role = :role, account_status = :status
                     WHERE id = :id'
                );

                $stmt->execute([
                    'role' => $role,
                    'status' => $status,
                    'id' => $userId
                ]);

                $message = 'User account updated successfully.';
                        } elseif ($action === 'generate_reset') {
                $resetUserId = filter_var(
                    $_POST['user_id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                if (!$resetUserId || $resetUserId < 1) {
                    throw new RuntimeException('Invalid user account.');
                }

                // Look up the target user.
                $check = $pdo->prepare(
                    "SELECT id, full_name, email, account_status
                     FROM users
                     WHERE id = ?
                     LIMIT 1"
                );
                $check->execute([$resetUserId]);
                $target = $check->fetch(PDO::FETCH_ASSOC);

                if (!$target) {
                    throw new RuntimeException('User account not found.');
                }

                if ($target['account_status'] !== 'active') {
                    throw new RuntimeException(
                        'Only active accounts can receive a reset link.'
                    );
                }

                // Invalidate previous unused tokens.
                $pdo->prepare(
                    "UPDATE password_resets
                     SET used_at = NOW()
                     WHERE user_id = ?
                       AND used_at IS NULL
                       AND expires_at > NOW()"
                )->execute([$resetUserId]);

                // Generate a new token.
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);

                $expiresAt = (new DateTimeImmutable('+1 hour'))
                    ->format('Y-m-d H:i:s');

                $insert = $pdo->prepare(
                    "INSERT INTO password_resets
                        (user_id, token_hash, expires_at, requested_by)
                     VALUES (?, ?, ?, ?)"
                );
                $insert->execute([
                    $resetUserId,
                    $tokenHash,
                    $expiresAt,
                    $adminId
                ]);

                // Build the link.
                $scheme = (!empty($_SERVER['HTTPS']) &&
                           $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http';

                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

                $resetLink = $scheme . '://' . $host . $dir .
                             '/reset_password.php?token=' . $rawToken;

                $_SESSION['users_reset_link'] = [
                    'name' => $target['full_name'],
                    'email' => $target['email'],
                    'link' => $resetLink,
                    'expires_at' => $expiresAt
                ];

                error_log(
                    'Admin #' . $adminId .
                    ' generated reset link for user #' . $resetUserId
                );

                // Store in a temp session key so the page can display it once.
                $_SESSION['users_csrf'] = bin2hex(random_bytes(32));

                $message = 'Password reset link generated. It is shown below '
                         . 'and expires in 1 hour. Give it to the user through '
                         . 'a secure channel.';
            } else {
                throw new RuntimeException('Invalid request.');
            }

            // Rotate the token after a successful operation.
            $_SESSION['users_csrf'] = bin2hex(random_bytes(32));

        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'The operation failed. Check the information and try again.';
        }
    }
}

// Fetch accounts for the table.
$stmt = $pdo->query(
    'SELECT id, full_name, email, phone, role, account_status
     FROM users
     ORDER BY id DESC'
);
$users = $stmt->fetchAll();

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
    <title>Manage Users | Youth Connect</title>
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

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1, h2 { color: #14532d; }

        .back-link, .submit-btn {
            display: inline-block;
            border: 0;
            border-radius: 7px;
            padding: 11px 15px;
            background: #14532d;
            color: white;
            text-decoration: none;
            cursor: pointer;
        }

        .panel {
            margin-bottom: 25px;
            padding: 24px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px #00000008;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        .field input, .field select {
            width: 100%;
            min-height: 42px;
            padding: 10px;
            border: 1px solid #cbd5cc;
            border-radius: 7px;
            background: white;
        }

        .submit-btn { margin-top: 20px; }

        .notice {
            padding: 13px;
            margin-bottom: 18px;
            border-radius: 7px;
        }

        .success { background: #e4f5e8; color: #14532d; }
        .error { background: #ffebeb; color: #991b1b; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5ebe5;
            text-align: left;
            vertical-align: middle;
        }

        th { background: #f4f7f4; }

        td select {
            padding: 8px;
            border: 1px solid #cbd5cc;
            border-radius: 6px;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-active { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-suspended { background: #fee2e2; color: #991b1b; }

        .small-btn {
            padding: 8px 10px;
            border: 0;
            border-radius: 6px;
            background: #14532d;
            color: white;
            cursor: pointer;
        }

        .muted { color: #69776c; font-size: 13px; }

        @media (max-width: 650px) {
            body { padding: 14px; }
            .form-grid { grid-template-columns: 1fr; }
            .panel { padding: 16px; }
        }
    </style>
</head>
<body>
<main class="container">

    <header class="page-header">
        <div>
            <h1>User Management</h1>
            <p>Manage Youth Connect accounts, roles and access status.</p>
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
        <?php if (!empty($_SESSION['users_reset_link'])): ?>
        <?php $generatedLink = $_SESSION['users_reset_link']; ?>
        <div class="notice" style="background:#fff7ed;color:#7c2d12;border:1px solid #fdba74;">
            <strong>🔑 Password Reset Link Generated</strong>

            <p style="margin:8px 0;">
                For: <strong><?= h($generatedLink['name']) ?></strong>
                (<?= h($generatedLink['email']) ?>)
            </p>

            <p style="margin:8px 0;word-break:break-all;font-family:monospace;font-size:13px;color:#1e40af;">
                <?= h($generatedLink['link']) ?>
            </p>

            <p style="margin:8px 0;font-size:13px;">
                Expires: <?= h($generatedLink['expires_at']) ?> —
                give this link to the user through a secure channel.
            </p>

            <p style="margin:8px 0;">
                <button
                    type="button"
                    class="small-btn"
                    onclick="navigator.clipboard.writeText('<?= h(addslashes($generatedLink['link'])) ?>').then(() => alert('Link copied to clipboard.'));"
                >
                    Copy Link
                </button>
            </p>
        </div>
        <?php unset($_SESSION['users_reset_link']); ?>
    <?php endif; ?>

    <section class="panel">
        <h2>Create User Account</h2>
        <p class="muted">
            Create accounts for officials and members. Share initial passwords
            privately and ask users to change them when a password-change feature
            is added.
        </p>

        <form method="POST" action="manage_users.php">
            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['users_csrf']) ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div class="field">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name"
                           maxlength="150" required>
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email"
                           maxlength="254" required>
                </div>

                <div class="field">
                    <label for="phone">Phone Number (optional)</label>
                    <input type="tel" id="phone" name="phone" maxlength="30">
                </div>

                <div class="field">
                    <label for="password">Initial Password</label>
                    <input type="password" id="password" name="password"
                           minlength="12" autocomplete="new-password" required>
                    <p class="muted">At least 12 characters.</p>
                </div>

                <div class="field">
                    <label for="role">User Role</label>
                    <select id="role" name="role" required>
                        <?php foreach ($roles as $value => $label): ?>
                            <option value="<?= h($value) ?>"
                                <?= $value === 'member' ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="account_status">Account Status</label>
                    <select id="account_status" name="account_status" required>
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>

            <button class="submit-btn" type="submit">Create Account</button>
        </form>
    </section>

    <section class="panel">
        <h2>Registered Users (<?= count($users) ?>)</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Update Account</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="7">No user accounts found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $item): ?>
                        <tr>
                            <td><?= (int) $item['id'] ?></td>
                            <td><?= h($item['full_name']) ?></td>
                            <td><?= h($item['email']) ?></td>
                            <td><?= h($item['phone'] ?: '—') ?></td>
                            <td><?= h($roles[$item['role']] ?? $item['role']) ?></td>
                            <td>
                                <span class="status status-<?= h($item['account_status']) ?>">
                                    <?= h($statuses[$item['account_status']] ?? $item['account_status']) ?>
                                </span>
                            </td>
                                                        <td>
                                <?php if ((int) $item['id'] === $adminId): ?>
                                    <span class="muted">Your account</span>
                                <?php else: ?>
                                    <form method="POST" action="manage_users.php">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= h($_SESSION['users_csrf']) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="user_id"
                                               value="<?= (int) $item['id'] ?>">

                                        <label class="muted" for="role-<?= (int) $item['id'] ?>">
                                            Role
                                        </label>
                                        <select id="role-<?= (int) $item['id'] ?>"
                                                name="role" required>
                                            <?php foreach ($roles as $value => $label): ?>
                                                <option value="<?= h($value) ?>"
                                                    <?= $value === $item['role'] ? 'selected' : '' ?>>
                                                    <?= h($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <br><br>

                                        <label class="muted" for="status-<?= (int) $item['id'] ?>">
                                            Status
                                        </label>
                                        <select id="status-<?= (int) $item['id'] ?>"
                                                name="account_status" required>
                                            <?php foreach ($statuses as $value => $label): ?>
                                                <option value="<?= h($value) ?>"
                                                    <?= $value === $item['account_status'] ? 'selected' : '' ?>>
                                                    <?= h($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <br><br>
                                        <button class="small-btn" type="submit">
                                            Save Changes
                                        </button>
                                    </form>

                                    <form method="POST"
                                          action="manage_users.php?generate_reset=<?= (int) $item['id'] ?>"
                                          style="margin-top:10px;">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= h($_SESSION['users_csrf']) ?>">
                                        <input type="hidden" name="action"
                                               value="generate_reset">
                                        <input type="hidden" name="user_id"
                                               value="<?= (int) $item['id'] ?>">
                                        <button class="small-btn"
                                                type="submit"
                                                style="background:#b45309;"
                                                onclick="return confirm('Generate a password reset link for this user?');">
                                            🔑 Send Reset Link
                                        </button>
                                    </form>
                                <?php endif; ?>
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