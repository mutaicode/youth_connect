
<?php
session_start();
require_once __DIR__ . '/config/database.php';

function escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Generate a separate CSRF token for member registration.
if (!isset($_SESSION['member_register_csrf'])) {
    $_SESSION['member_register_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;

$full_name = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Verify the form security token.
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_token) ||
        !isset($_SESSION['member_register_csrf']) ||
        !hash_equals($_SESSION['member_register_csrf'], $submitted_token)
    ) {
        $errors[] = 'Your form session has expired. Please refresh and try again.';
    }

    // 2. Collect and validate the submitted information.
    $full_name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (
        $full_name === '' ||
        (function_exists('mb_strlen')
            ? mb_strlen($full_name, 'UTF-8')
            : strlen($full_name)) > 150
    ) {
        $errors[] = 'Please enter your full name (maximum 150 characters).';
    }

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 190
    ) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (strlen($phone) > 30) {
        $errors[] = 'Your phone number must not exceed 30 characters.';
    }

    if (!is_string($password) || strlen($password) < 12) {
        $errors[] = 'Your password must contain at least 12 characters.';
    }

    if (!is_string($confirm_password) ||
        !is_string($password) ||
        !hash_equals($password, $confirm_password)
    ) {
        $errors[] = 'Your passwords do not match.';
    }

    // 3. Check whether the email is already registered.
    if (!$errors) {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM users
             WHERE LOWER(email) = LOWER(:email)
             LIMIT 1"
        );

        $stmt->execute(['email' => $email]);

        if ($stmt->fetch()) {
            $errors[] = 'This email address is already registered. Please use another email or sign in.';
        }
    }

    // 4. Create the account only if all validation passes.
    if (!$errors) {
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            if ($password_hash === false) {
                throw new RuntimeException('Password hashing failed.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO users
                    (full_name, email, phone, password_hash, role, account_status)
                 VALUES
                    (:full_name, :email, :phone, :password_hash, 'member', 'pending')"
            );

            $stmt->execute([
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'password_hash' => $password_hash
            ]);

            $success = true;

            // Prevent the same form submission from being reused.
            $_SESSION['member_register_csrf'] = bin2hex(random_bytes(32));

            // Clear password fields from the form after success.
            $password = '';
            $confirm_password = '';

        } catch (PDOException $e) {
            error_log($e->getMessage());

            // SQLSTATE 23000 commonly indicates a uniqueness constraint violation.
            if ($e->getCode() === '23000') {
                $errors[] = 'This email address may already be registered. Please try signing in or use another email.';
            } else {
                $errors[] = 'We could not create your account. Please try again later.';
            }
        } catch (RuntimeException $e) {
            error_log($e->getMessage());
            $errors[] = 'We could not secure your password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Member Registration | Youth Connect</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .member-register-page {
            min-height: 75vh;
            padding: 45px 16px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f4f7fb;
        }

        .member-register-card {
            width: 100%;
            max-width: 540px;
            padding: 32px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        .member-register-card h1 {
            margin-bottom: 10px;
            color: #183153;
        }

        .member-register-card .intro {
            color: #64748b;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .member-register-card .form-group {
            margin-bottom: 18px;
        }

        .member-register-card label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
            color: #26364b;
        }

        .member-register-card input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
            box-sizing: border-box;
        }

        .member-register-card input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .member-register-card button {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 8px;
            background: #1d4ed8;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }

        .member-register-card button:hover {
            background: #1e40af;
        }

        .member-register-card .form-errors {
            margin-bottom: 20px;
            padding: 14px 18px;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 8px;
        }

        .member-register-card .form-errors ul {
            padding-left: 20px;
            margin-bottom: 0;
        }

        .member-register-card .notice,
        .member-register-card .success-message {
            padding: 15px;
            border-radius: 8px;
            line-height: 1.6;
        }

        .member-register-card .notice {
            margin-top: 18px;
            background: #eff6ff;
            color: #1e40af;
        }

        .member-register-card .success-message {
            background: #f0fdf4;
            color: #166534;
        }

        .member-register-card .links {
            margin-top: 22px;
            text-align: center;
        }

        .member-register-card a {
            color: #1d4ed8;
            text-decoration: none;
        }

        .member-register-card a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .member-register-card {
                padding: 22px 18px;
            }
        }
    </style>
</head>

<body>

<header class="navbar">
    <a href="index.php" class="brand">
        <div class="logo">YC</div>
        <div>
            <h2>Youth Connect</h2>
            <span>AIC Kabartegan Region</span>
        </div>
    </a>

    <nav>
        <a href="index.php">Home</a>
        <a href="events.php">Events</a>
        <a href="login.php">Login</a>
    </nav>
</header>

<main class="member-register-page">
    <section class="member-register-card">

        <?php if ($success): ?>

            <div class="success-message">
                <h1>Registration Successful!</h1>

                <p>
                    Thank you, <strong><?= escape($full_name) ?></strong>.
                    Your Youth Connect membership application has been received.
                </p>

                <p>
                    Your account is currently <strong>pending approval</strong>.
                    An administrator must activate it before you can sign in.
                </p>
            </div>

            <div class="links">
                <a href="login.php">Go to Login</a>
                &nbsp; | &nbsp;
                <a href="index.php">Back to Home</a>
            </div>

        <?php else: ?>

            <h1>Create Your Member Account</h1>

            <p class="intro">
                Join AIC Kabartegan Region Youth Connect to stay connected
                with youth programs, events, and activities.
            </p>

            <?php if ($errors): ?>
                <div class="form-errors" role="alert">
                    <strong>Please correct the following:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= escape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="member_register.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escape($_SESSION['member_register_csrf']) ?>"
                >

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        maxlength="150"
                        autocomplete="name"
                        required
                        value="<?= escape($full_name) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="190"
                        autocomplete="email"
                        required
                        value="<?= escape($email) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number (Optional)</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        maxlength="30"
                        autocomplete="tel"
                        value="<?= escape($phone) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                    <small>Use at least 12 characters.</small>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit">Submit Membership Application</button>
            </form>

            <div class="notice">
                <strong>Important:</strong>
                Your account will remain pending until an administrator
                approves it. You cannot sign in as a member before activation.
            </div>

            <div class="links">
                Already have an account?
                <a href="login.php">Sign in</a>
                <p><a href="index.php">← Back to Home</a></p>
            </div>

        <?php endif; ?>

    </section>
</main>

<footer>
    <div>
        <h3>AIC Kabartegan Region Youth Connect</h3>
        <p>Connecting People, Strengthening Faith, Building Community.</p>
    </div>

    <p class="copyright">
        &copy; <?= date('Y') ?> AIC Kabartegan Region Youth Connect.
    </p>
</footer>

</body>
</html>
