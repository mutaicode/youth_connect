<?php
session_start();
require_once __DIR__ . '/config/database.php';

function escape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;
$token = '';
$row = null;
$userId = null;

// Read the token from GET or POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = is_string($_POST['token'] ?? null)
        ? trim($_POST['token'])
        : '';
} else {
    $token = is_string($_GET['token'] ?? null)
        ? trim($_GET['token'])
        : '';
}

// Validate token format (64 hex chars).
$validFormat = preg_match('/^[a-f0-9]{64}$/i', $token) === 1;

if (!$validFormat) {
    $errors[] = 'Invalid or missing reset token.';
} else {
    try {
        $tokenHash = hash('sha256', $token);

        $stmt = $pdo->prepare(
            "SELECT id, user_id, expires_at, used_at
             FROM password_resets
             WHERE token_hash = ?
             LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $errors[] = 'This reset link is invalid.';
        } elseif ($row['used_at'] !== null) {
            $errors[] = 'This reset link has already been used. Request a new one.';
        } elseif (strtotime($row['expires_at']) < time()) {
            $errors[] = 'This reset link has expired. Request a new one.';
        } else {
            $userId = (int) $row['user_id'];
        }
    } catch (Throwable $ex) {
        error_log('Reset password lookup error: ' . $ex->getMessage());
        $errors[] = 'Something went wrong. Please try again.';
    }
}

// Handle the POST submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $csrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrf) ||
        !hash_equals($_SESSION['reset_csrf'], $csrf)
    ) {
        $errors[] = 'Your session has expired. Refresh the page and try again.';
    } else {
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!is_string($newPassword) || strlen($newPassword) < 12) {
            $errors[] = 'Your new password must contain at least 12 characters.';
        }

        if (!is_string($confirmPassword) || $newPassword !== $confirmPassword) {
            $errors[] = 'The new passwords do not match.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Lock the row so two simultaneous requests can't both succeed.
                $lockStmt = $pdo->prepare(
                    "SELECT id, user_id, used_at
                     FROM password_resets
                     WHERE id = ?
                     FOR UPDATE"
                );
                $lockStmt->execute([(int) $row['id']]);
                $fresh = $lockStmt->fetch(PDO::FETCH_ASSOC);

                if (!$fresh || $fresh['used_at'] !== null) {
                    throw new RuntimeException(
                        'This reset link is no longer valid.'
                    );
                }

                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                if ($newHash === false) {
                    throw new RuntimeException('Password hashing failed.');
                }

                // Update the user's password.
                $update = $pdo->prepare(
                    "UPDATE users
                     SET password_hash = :hash
                     WHERE id = :id
                       AND account_status = 'active'"
                );
                $update->execute([
                    'hash' => $newHash,
                    'id'   => (int) $fresh['user_id']
                ]);

                // Mark the token as used.
                $pdo->prepare(
                    "UPDATE password_resets
                     SET used_at = NOW()
                     WHERE id = ?"
                )->execute([(int) $fresh['id']]);

                // Invalidate any other outstanding tokens for this user.
                $pdo->prepare(
                    "UPDATE password_resets
                     SET used_at = NOW()
                     WHERE user_id = ?
                       AND used_at IS NULL"
                )->execute([(int) $fresh['user_id']]);

                $pdo->commit();

                $success = true;

                // Rotate CSRF and clear the token from the page.
                $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
                $token = '';

                error_log(
                    'Password reset completed for user #' .
                    $fresh['user_id']
                );
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Reset password error: ' . $ex->getMessage());
                $errors[] = 'We could not reset your password. Please try again.';
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
    <title>Reset Password | Youth Connect</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #243047;
        }

        .wrap {
            max-width: 520px;
            margin: 60px auto;
            padding: 0 18px;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .08);
        }

        h1 {
            margin-top: 0;
            color: #173b67;
        }

        .intro {
            color: #64748b;
            line-height: 1.55;
            margin-bottom: 22px;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 15px 0 7px;
        }

        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #ccd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #b6d4fe;
            border-color: #2363a6;
        }

        .btn {
            display: inline-block;
            width: 100%;
            margin-top: 22px;
            border: none;
            background: #174a87;
            color: white;
            padding: 13px;
            border-radius: 7px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn:hover { background: #103764; }

        .notice {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            line-height: 1.5;
        }

        .success { background: #dcfce7; color: #166534; }
        .error   { background: #fee2e2; color: #991b1b; }

        .hint {
            color: #7a877f;
            font-size: 13px;
            margin-top: 6px;
        }

        .back {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #174a87;
            text-decoration: none;
        }

        @media (max-width: 480px) {
            .card { padding: 22px; }
        }
    </style>
</head>
<body>

<div class="wrap">
    <div class="card">

        <?php if ($success): ?>
            <h1>Password Reset Complete</h1>

            <div class="notice success">
                Your password has been updated. You can now sign in with
                your new password.
            </div>

            <a class="btn" href="login.php" style="text-align:center;text-decoration:none;">
                Go to Login
            </a>

        <?php elseif ($errors): ?>
            <h1>Reset Link Problem</h1>

            <div class="notice error">
                <?php foreach ($errors as $err): ?>
                    <p><?= escape($err) ?></p>
                <?php endforeach; ?>
            </div>

            <a class="btn" href="forgot_password.php"
               style="text-align:center;text-decoration:none;">
                Request a New Reset Link
            </a>

        <?php else: ?>
            <h1>Set a New Password</h1>

            <p class="intro">
                Choose a strong password of at least 12 characters.
                Once saved, this reset link will stop working.
            </p>

            <form method="POST" action="reset_password.php" autocomplete="off">
                <input type="hidden" name="csrf_token"
                       value="<?= escape($_SESSION['reset_csrf']) ?>">

                <input type="hidden" name="token"
                       value="<?= escape($token) ?>">

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

                <button class="btn" type="submit">Save New Password</button>
            </form>
        <?php endif; ?>

        <?php if (!$success): ?>
            <a class="back" href="login.php">&larr; Back to Login</a>
        <?php endif; ?>
    </div>
</div>

</body>
</html>