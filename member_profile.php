
<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'member') {
    header('Location: login.php');
    exit;
}

function escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = '';
$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['member_profile_csrf'])) {
    $_SESSION['member_profile_csrf'] = bin2hex(random_bytes(32));
}

// Confirm that the account still exists and is active.
$stmt = $pdo->prepare(
    "SELECT id, full_name, email, phone, password_hash, account_status
     FROM users
     WHERE id = :id AND role = 'member'
     LIMIT 1"
);
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['account_status'] !== 'active') {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['member_profile_csrf'], $token)) {
        $errors[] = 'Your session has expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if ($fullName === '' || strlen($fullName) > 150) {
                $errors[] = 'Enter your name (maximum 150 characters).';
            }

            if (strlen($phone) > 30) {
                $errors[] = 'Your phone number must not exceed 30 characters.';
            }

            if (!$errors) {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET full_name = :full_name, phone = :phone
                     WHERE id = :id AND role = 'member'"
                );

                $stmt->execute([
                    'full_name' => $fullName,
                    'phone' => $phone !== '' ? $phone : null,
                    'id' => $userId
                ]);

                $_SESSION['full_name'] = $fullName;
                $success = 'Your profile has been updated.';
            }
        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!password_verify($currentPassword, $user['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
            }

            if (strlen($newPassword) < 12) {
                $errors[] = 'Your new password must contain at least 12 characters.';
            }

            if ($newPassword !== $confirmPassword) {
                $errors[] = 'The new passwords do not match.';
            }

            if ($newPassword !== $currentPassword && $newPassword !== '') {
                if (password_verify($newPassword, $user['password_hash'])) {
                    $errors[] = 'Choose a password different from your current password.';
                }
            }

            if (!$errors) {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET password_hash = :password_hash
                     WHERE id = :id AND role = 'member'"
                );

                $stmt->execute([
                    'password_hash' => $newHash,
                    'id' => $userId
                ]);

                $success = 'Your password has been changed successfully.';
            }
        } else {
            $errors[] = 'Invalid request.';
        }
    }

    // Refresh the displayed profile after processing the form.
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, phone, password_hash, account_status
         FROM users
         WHERE id = :id AND role = 'member'
         LIMIT 1"
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Youth Connect</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #243047;
        }

        header {
            background: #173b67;
            color: white;
            padding: 18px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        header h2 {
            margin: 0;
            font-size: 21px;
        }

        header a {
            color: white;
            text-decoration: none;
            margin-left: 15px;
        }

        .container {
            max-width: 950px;
            margin: 35px auto;
            padding: 0 18px;
        }

        .intro {
            margin-bottom: 24px;
        }

        .intro h1 {
            margin-bottom: 8px;
            color: #173b67;
        }

        .intro p {
            color: #64748b;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.06);
        }

        .card h2 {
            margin-top: 0;
            color: #173b67;
            font-size: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 15px 0 7px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #b7d5ff;
            border-color: #2867a5;
        }

        button {
            margin-top: 20px;
            width: 100%;
            border: none;
            background: #1769aa;
            color: white;
            padding: 13px;
            border-radius: 7px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #104d80;
        }

        .notice {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .success {
            background: #e0f7e9;
            color: #176534;
        }

        .error {
            background: #fee8e8;
            color: #9b1c1c;
        }

        .details p {
            padding: 10px 0;
            border-bottom: 1px solid #edf0f5;
            overflow-wrap: anywhere;
        }

        .details strong {
            display: inline-block;
            min-width: 130px;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #dcfce7;
            color: #166534;
            font-size: 13px;
        }

        .back-link {
            display: inline-block;
            margin-top: 24px;
            color: #1769aa;
            text-decoration: none;
            font-weight: bold;
        }

        @media (max-width: 700px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            header a {
                margin-left: 0;
                margin-right: 12px;
            }

            .card {
                padding: 19px;
            }
        }
    </style>
</head>
<body>

<header>
    <h2>Youth Connect</h2>
    <nav>
        <a href="member_dashboard.php">Dashboard</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main class="container">
    <div class="intro">
        <h1>My Profile</h1>
        <p>View your account details and keep your information up to date.</p>
    </div>

    <?php if ($success): ?>
        <div class="notice success">
            <?= escape($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="notice error">
            <?php foreach ($errors as $error): ?>
                <p><?= escape($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="profile-grid">
        <section class="card">
            <h2>Account Details</h2>

            <div class="details">
                <p>
                    <strong>Full name:</strong>
                    <?= escape($user['full_name']) ?>
                </p>

                <p>
                    <strong>Email:</strong>
                    <?= escape($user['email']) ?>
                </p>

                <p>
                    <strong>Phone:</strong>
                    <?= escape($user['phone'] ?: 'Not provided') ?>
                </p>

                <p>
                    <strong>Status:</strong>
                    <span class="status"><?= escape(ucfirst($user['account_status'])) ?></span>
                </p>
            </div>

            <h2>Update Profile</h2>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= escape($_SESSION['member_profile_csrf']) ?>">
                <input type="hidden" name="action" value="update_profile">

                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name"
                       maxlength="150" required
                       value="<?= escape($_POST['full_name'] ?? $user['full_name']) ?>">

                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" maxlength="30"
                       value="<?= escape($_POST['phone'] ?? $user['phone']) ?>">

                <button type="submit">Save Profile Changes</button>
            </form>
        </section>

        <section class="card">
            <h2>Change Password</h2>
            <p>Use a strong password with at least 12 characters.</p>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= escape($_SESSION['member_profile_csrf']) ?>">
                <input type="hidden" name="action" value="change_password">

                <label for="current_password">Current Password</label>
                <input type="password" id="current_password"
                       name="current_password" autocomplete="current-password"
                       required>

                <label for="new_password">New Password</label>
                <input type="password" id="new_password"
                       name="new_password" minlength="12"
                       autocomplete="new-password" required>

                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password"
                       name="confirm_password" minlength="12"
                       autocomplete="new-password" required>

                <button type="submit">Change Password</button>
            </form>
        </section>
    </div>

    <a class="back-link" href="member_dashboard.php">
        ← Back to Member Dashboard
    </a>
</main>

</body>
</html>
