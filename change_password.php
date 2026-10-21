<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Require a logged-in user.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Fetch the current user from the database (never trust the session alone).
$stmt = $pdo->prepare(
    "SELECT id, full_name, email, password_hash, role, account_status
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If the account is missing or inactive, log the user out.
if (!$user || $user['account_status'] !== 'active') {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

// Members use member_profile.php for password changes.
if ($user['role'] === 'member') {
    header('Location: member_profile.php');
    exit;
}

function escape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// CSRF token.
if (empty($_SESSION['change_password_csrf'])) {
    $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['change_password_csrf'], $token)
    ) {
        $errors[] = 'Your session has expired. Refresh the page and try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!is_string($currentPassword) || $currentPassword === '') {
            $errors[] = 'Enter your current password.';
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        }

        if (!is_string($newPassword) || strlen($newPassword) < 12) {
            $errors[] = 'Your new password must contain at least 12 characters.';
        }

        if (!is_string($confirmPassword) || $newPassword !== $confirmPassword) {
            $errors[] = 'The new passwords do not match.';
        }

        if (
            $newPassword !== '' &&
            $currentPassword !== '' &&
            $newPassword === $currentPassword
        ) {
            $errors[] = 'Choose a new password that is different from your current one.';
        }

        // Optional: reject if new password matches the old hash.
        if (
            !$errors &&
            password_verify($newPassword, $user['password_hash'])
        ) {
            $errors[] = 'That password is already in use. Choose a different one.';
        }

        if (!$errors) {
            try {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                if ($newHash === false) {
                    throw new RuntimeException('Password hashing failed.');
                }

                $update = $pdo->prepare(
                    "UPDATE users
                     SET password_hash = :hash
                     WHERE id = :id
                       AND account_status = 'active'"
                );

                $update->execute([
                    'hash' => $newHash,
                    'id'   => $userId
                ]);

                // Rotate the CSRF token and log the event.
                $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));

                error_log(
                    'Password changed for user #' . $userId .
                    ' (' . $user['email'] . ') at ' . date('c')
                );

                $success = 'Your password has been changed successfully.';

                // Refresh the stored hash so the session reflects the change.
                $user['password_hash'] = $newHash;

            } catch (Throwable $ex) {
                error_log('Change password error: ' . $ex->getMessage());
                $errors[] = 'We could not update your password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | Youth Connect</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7f4;
            color: #243329;
        }

        .topbar {
            background: #14532d;
            color: white;
            padding: 22px 5%;
        }

        .topbar h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }

        .topbar p {
            margin: 0;
            color: #d9e8dd;
        }

        .container {
            max-width: 620px;
            margin: 35px auto;
            padding: 0 18px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #14532d;
            text-decoration: none;
            font-weight: bold;
        }

        .card {
            background: white;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 4px 16px #0000000a;
        }

        .card h2 {
            margin-top: 0;
            color: #14532d;
        }

        .card p.intro {
            color: #647067;
            margin-bottom: 22px;
            line-height: 1.55;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 16px 0 7px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5cc;
            border-radius: 7px;
            font-size: 15px;
            font-family: inherit;
        }

        input:focus {
            outline: 2px solid #b7d8c2;
            border-color: #14532d;
        }

        .btn {
            display: inline-block;
            margin-top: 22px;
            width: 100%;
            border: none;
            background: #14532d;
            color: white;
            padding: 13px;
            border-radius: 7px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn:hover {
            background: #166534;
        }

        .notice {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            line-height: 1.5;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .error ul {
            margin: 0;
            padding-left: 20px;
        }

        .hint {
            color: #7a877f;
            font-size: 13px;
            margin-top: 6px;
        }

        .account-info {
            background: #f4f7f4;
            border: 1px solid #e3ebe5;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 14px;
            margin-bottom: 18px;
        }

        .account-info strong {
            color: #14532d;
        }

        @media (max-width: 500px) {
            .card { padding: 20px; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <h1>Change Password</h1>
    <p>Youth Connect — AIC Kabartegan Region</p>
</header>

<main class="container">

    <a class="back-link" href="dashboard.php">&larr; Back to Dashboard</a>

    <div class="card">
        <h2>Update Your Password</h2>

        <p class="intro">
            For your account's security, choose a strong password of at
            least 12 characters. You will stay signed in after changing it.
        </p>

        <div class="account-info">
            <strong>Name:</strong> <?= escape($user['full_name']) ?><br>
            <strong>Email:</strong> <?= escape($user['email']) ?><br>
            <strong>Role:</strong> <?= escape(ucwords(str_replace('_', ' ', $user['role']))) ?>
        </div>

        <?php if ($success !== ''): ?>
            <div class="notice success" role="status">
                <?= escape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="notice error" role="alert">
                <strong>Please check the following:</strong>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= escape($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="change_password.php" autocomplete="off">
            <input type="hidden" name="csrf_token"
                   value="<?= escape($_SESSION['change_password_csrf']) ?>">

            <label for="current_password">Current Password</label>
            <input
                type="password"
                id="current_password"
                name="current_password"
                autocomplete="current-password"
                required
            >

            <label for="new_password">New Password</label>
            <input
                type="password"
                id="new_password"
                name="new_password"
                minlength="12"
                autocomplete="new-password"
                required
            >
            <p class="hint">Minimum 12 characters.</p>

            <label for="confirm_password">Confirm New Password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                minlength="12"
                autocomplete="new-password"
                required
            >

            <button class="btn" type="submit">Change Password</button>
        </form>
    </div>

</main>

</body>
</html>