<?php
session_start();

require_once __DIR__ . '/config/database.php';

$error = '';
$email = '';

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'member') {
        header('Location: member_dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['login_csrf'], $csrfToken)
    ) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, full_name, email, password_hash, role, account_status
             FROM users
             WHERE email = :email
             LIMIT 1'
        );

        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (
            $user &&
            password_verify($password, $user['password_hash']) &&
            $user['account_status'] === 'active'
        ) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_email'] = $user['email'];

            unset($_SESSION['login_csrf']);

            if ($user['role'] === 'member') {
    header('Location: member_dashboard.php');
} else {
    header('Location: dashboard.php');
}
exit;
        }

        $error = 'Login failed. Check your details or contact the system administrator.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leadership Login | Youth Connect</title>
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .login-section {
            min-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: #f3f7f2;
        }

        .login-card {
            width: 100%;
            max-width: 430px;
            background: #fff;
            padding: 35px;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .09);
        }

        .login-card h1 {
            color: #14532d;
            margin-bottom: 10px;
            text-align: center;
        }

        .login-intro {
            text-align: center;
            color: #647067;
            margin-bottom: 25px;
        }

        .login-card label {
            display: block;
            margin: 16px 0 7px;
            font-weight: 600;
        }

        .login-card input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #ccd5cc;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 16px;
        }

        .login-card input:focus {
            outline: 2px solid #d4a72c;
            border-color: #14532d;
        }

        .login-button {
            width: 100%;
            padding: 13px;
            margin-top: 24px;
            border: none;
            border-radius: 8px;
            background: #14532d;
            color: #fff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        .login-button:hover {
            background: #166534;
        }

        .login-error {
            background: #fff0f0;
            color: #a11;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .login-back {
            display: block;
            text-align: center;
            margin-top: 22px;
            color: #14532d;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <section class="login-section">
        <div class="login-card">
            <h1>Leadership Login</h1>

            <p class="login-intro">
                Sign in to access the Youth Connect management system.
            </p>

            <?php if ($error !== ''): ?>
                <div class="login-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    required
                >

                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >

                                <button type="submit" class="login-button">
                    Sign In
                </button>
            </form>

            <div style="text-align:center;margin-top:16px;">
                <a href="forgot_password.php"
                   style="color:#14532d;font-size:14px;text-decoration:none;">
                    Forgot your password?
                </a>
            </div>

            <a href="index.php" class="login-back">
                &larr; Back to Home
            </a>
        </div>
    </section>
</body>
</html>