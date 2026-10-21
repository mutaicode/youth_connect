<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

function escape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['forgot_csrf'])) {
    $_SESSION['forgot_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;
$resetLink = null;
$email = '';

// How long a reset link is valid (in seconds).
$tokenLifetime = 3600; // 1 hour

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['forgot_csrf'], $token)
    ) {
        $errors[] = 'Your session has expired. Refresh the page and try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if (!$errors) {
            try {
                // Look up an active account with this email.
                $stmt = $pdo->prepare(
                    "SELECT id, full_name, email, role
                     FROM users
                     WHERE LOWER(email) = LOWER(:email)
                       AND account_status = 'active'
                     LIMIT 1"
                );
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Always show the same success message, even if the email
                // doesn't exist — this prevents account enumeration.
                $success = true;

                if ($user) {
                    // Invalidate any previous unused tokens for this user.
                    $pdo->prepare(
                        "UPDATE password_resets
                         SET used_at = NOW()
                         WHERE user_id = ?
                           AND used_at IS NULL
                           AND expires_at > NOW()"
                    )->execute([(int) $user['id']]);

                    // Create a fresh token.
                    $rawToken = bin2hex(random_bytes(32)); // 64 hex chars
                    $tokenHash = hash('sha256', $rawToken);

                    $expiresAt = (new DateTimeImmutable(
                        '+' . $tokenLifetime . ' seconds'
                    ))->format('Y-m-d H:i:s');

                    $insert = $pdo->prepare(
                        "INSERT INTO password_resets
                            (user_id, token_hash, expires_at, requested_by)
                         VALUES (?, ?, ?, NULL)"
                    );
                    $insert->execute([
                        (int) $user['id'],
                        $tokenHash,
                        $expiresAt
                    ]);

                    // Build the reset link.
                    $scheme = (!empty($_SERVER['HTTPS']) &&
                               $_SERVER['HTTPS'] !== 'off')
                        ? 'https'
                        : 'http';

                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

                    $resetLink = $scheme . '://' . $host . $dir .
                                 '/reset_password.php?token=' . $rawToken;

                    // Log the request (useful for auditing).
                    error_log(
                        'Password reset requested for user #' .
                        $user['id'] . ' (' . $user['email'] . ')'
                    );

                    // 🔔 TODO: Send $resetLink to $user['email']
                    //         via PHPMailer / SMTP when configured.
                    //         For now, it is shown on-screen below.
                }

                // Rotate CSRF.
                $_SESSION['forgot_csrf'] = bin2hex(random_bytes(32));
            } catch (Throwable $ex) {
                error_log('Forgot password error: ' . $ex->getMessage());
                $errors[] = 'Something went wrong. Please try again later.';
                $success = false;
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
    <title>Forgot Password | Youth Connect</title>

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
        .info    { background: #eaf3ff; color: #1e40af; }

        .link-box {
            margin-top: 18px;
            padding: 14px;
            background: #f8fafc;
            border: 1px dashed #94a3b8;
            border-radius: 8px;
            word-break: break-all;
            font-family: monospace;
            font-size: 13px;
            color: #1e40af;
        }

        .link-box strong {
            display: block;
            font-family: Arial, sans-serif;
            color: #334155;
            margin-bottom: 8px;
            font-size: 13px;
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
        <h1>Forgot Password?</h1>

        <p class="intro">
            Enter the email address linked to your Youth Connect account.
            We'll generate a reset link that expires in one hour.
        </p>

        <?php if ($errors): ?>
            <div class="notice error" role="alert">
                <?php foreach ($errors as $err): ?>
                    <p><?= escape($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="notice success" role="status">
                If an active account with that email exists, a reset link has
                been generated. It expires in one hour.
            </div>

            <?php if ($resetLink): ?>
                <div class="info notice">
                    <strong>📧 Email delivery is not configured yet.</strong><br>
                    Since SMTP isn't set up, here is your reset link directly.
                    Copy it into your browser to reset your password.
                </div>

                <div class="link-box">
                    <strong>Your reset link:</strong>
                    <?= escape($resetLink) ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <form method="POST" action="forgot_password.php" autocomplete="off">
                <input type="hidden" name="csrf_token"
                       value="<?= escape($_SESSION['forgot_csrf']) ?>">

                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= escape($email) ?>"
                    maxlength="190"
                    autocomplete="email"
                    required
                >

                <button class="btn" type="submit">Generate Reset Link</button>
            </form>
        <?php endif; ?>

        <a class="back" href="login.php">&larr; Back to Login</a>
    </div>
</div>

</body>
</html>