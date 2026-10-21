<?php
session_start();
require_once __DIR__ . '/config/database.php';

/* Escape output safely */
function escape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Validate event ID */
$event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$event_id || $event_id < 1) {
    http_response_code(400);
    exit('Invalid event. Please return to the events page and choose an event.');
}

/* Create CSRF token before processing the form */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Fetch active churches */
$churches = [];

try {
    $church_stmt = $pdo->query(
        "SELECT id, church_name
         FROM churches
         WHERE status = 'active'
         ORDER BY church_name ASC"
    );

    $churches = $church_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to load the church list. Please try again later.');
}

/* Fetch published event that has not started */
$stmt = $pdo->prepare(
    "SELECT id, title, description, venue, start_datetime,
            registration_deadline, capacity, allow_guests
     FROM events
     WHERE id = :id
       AND status = 'published'
       AND start_datetime >= NOW()
     LIMIT 1"
);

$stmt->execute(['id' => $event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    exit('This event is unavailable for registration.');
}

/* Count active registrations */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM registrations
     WHERE event_id = :event_id
       AND status IN ('registered', 'confirmed')"
);

$stmt->execute(['event_id' => $event_id]);
$registration_count = (int) $stmt->fetchColumn();

/* Form state */
$errors = [];
$success = false;
$reference = null;
$selected_church_id = '';

/* Identify logged-in member */
$is_member = isset($_SESSION['user_id'])
    && isset($_SESSION['role'])
    && $_SESSION['role'] === 'member';

/* Process registration */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Verify CSRF token */
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_token) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $submitted_token)
    ) {
        $errors[] = 'Your form session has expired. Please refresh the page and try again.';
    }

    /* Check registration deadline */
    if (
        $event['registration_deadline'] &&
        strtotime($event['registration_deadline']) < time()
    ) {
        $errors[] = 'The registration deadline for this event has passed.';
    }

    /* Check event capacity */
    if (
        $event['capacity'] !== null &&
        $registration_count >= (int) $event['capacity']
    ) {
        $errors[] = 'This event has reached its registration capacity.';
    }

    /* Read participant details */
    $name = trim($_POST['participant_name'] ?? '');
    $email = trim($_POST['participant_email'] ?? '');
    $phone = trim($_POST['participant_phone'] ?? '');

    $submitted_church = $_POST['church_id'] ?? '';
    $selected_church_id = is_string($submitted_church)
        ? $submitted_church
        : '';

    /* Validate selected church */
    $church_id = filter_var(
        $selected_church_id,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($church_id === false) {
        $errors[] = 'Please select your local church.';
    } else {
        $church_stmt = $pdo->prepare(
            "SELECT id
             FROM churches
             WHERE id = :church_id
               AND status = 'active'
             LIMIT 1"
        );

        $church_stmt->execute(['church_id' => $church_id]);

        if (!$church_stmt->fetchColumn()) {
            $errors[] = 'The selected church is unavailable. Please choose an active church.';
        }
    }

    /* Verify logged-in member details */
    if ($is_member) {
        $stmt = $pdo->prepare(
            "SELECT full_name, email, phone
             FROM users
             WHERE id = :id
               AND role = 'member'
               AND account_status = 'active'
             LIMIT 1"
        );

        $stmt->execute([
            'id' => (int) $_SESSION['user_id']
        ]);

        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            $errors[] = 'Your member account could not be verified. Please sign in again.';
        } else {
            $name = $member['full_name'];
            $email = $member['email'];
            $phone = $member['phone'] ?? '';
        }
    } elseif ((int) $event['allow_guests'] !== 1) {
        $errors[] = 'This event is only open to eligible members.';
    }

    /* Validate guest details */
    if (!$is_member) {
        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Please enter your name (up to 150 characters).';
        }

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 190
        ) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (strlen($phone) > 30) {
            $errors[] = 'Your phone number is too long.';
        }
    }

    /* Prevent duplicate active registrations */
    if (!$errors) {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM registrations
             WHERE event_id = :event_id
               AND LOWER(participant_email) = LOWER(:email)
               AND status IN ('registered', 'confirmed')
             LIMIT 1"
        );

        $stmt->execute([
            'event_id' => $event_id,
            'email' => $email
        ]);

        if ($stmt->fetch()) {
            $errors[] = 'This email address already has an active registration for this event.';
        }
    }

    /* Save registration */
    if (!$errors) {
        $reference = 'YC-' . strtoupper(bin2hex(random_bytes(6)));

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO registrations
                    (
                        event_id,
                        user_id,
                        church_id,
                        participant_name,
                        participant_email,
                        participant_phone,
                        participant_type,
                        status,
                        registration_reference
                    )
                 VALUES
                    (
                        :event_id,
                        :user_id,
                        :church_id,
                        :name,
                        :email,
                        :phone,
                        :type,
                        'registered',
                        :reference
                    )"
            );

            $stmt->execute([
                'event_id' => $event_id,
                'user_id' => $is_member
                    ? (int) $_SESSION['user_id']
                    : null,
                'church_id' => $church_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'type' => $is_member ? 'member' : 'guest',
                'reference' => $reference
            ]);

            $success = true;

            /* Clear form token after successful submission */
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'We could not save your registration. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Event Registration | Youth Connect</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #ccd5e2;
            border-radius: 7px;
            background: #fff;
            font-size: 15px;
            color: #243047;
            box-sizing: border-box;
        }

        .form-group select:focus {
            outline: 2px solid #b6d4fe;
            border-color: #2363a6;
        }

        .form-errors {
            padding: 15px 18px;
            margin: 18px 0;
            border-radius: 8px;
            background: #fde8e8;
            color: #9f2525;
        }

        .form-errors ul {
            margin-bottom: 0;
            padding-left: 22px;
        }

        .form-errors li {
            margin-top: 6px;
        }

        .church-note {
            color: #64748b;
            font-size: 13px;
            margin-top: 6px;
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
        <a href="index.php#programs">Programs</a>
        <a href="events.php">Events</a>
    </nav>
</header>

<main class="registration-page">
    <section class="registration-card">

        <?php if ($success): ?>

            <div class="registration-success">
                <div class="success-icon">✓</div>

                <h1>Registration Received!</h1>

                <p>
                    Thank you, <?= escape($name) ?>.
                    Your registration for
                    <strong><?= escape($event['title']) ?></strong>
                    has been saved.
                </p>

                <div class="reference-box">
                    <span>Registration reference</span>
                    <strong><?= escape($reference) ?></strong>
                </div>

                <p class="form-note">
                    Keep this reference for your records.
                </p>

                <a href="events.php" class="btn-primary">
                    Back to Events
                </a>
            </div>

        <?php else: ?>

            <a href="events.php" class="back-link">
                &larr; Back to Events
            </a>

            <span class="eyebrow">JOIN US</span>

            <h1>Event Registration</h1>

            <p class="form-intro">
                Register your interest in attending the event below.
            </p>

            <div class="selected-event">
                <h2><?= escape($event['title']) ?></h2>

                <p>
                    <strong>Date:</strong>
                    <?= date(
                        'd M Y, H:i',
                        strtotime($event['start_datetime'])
                    ) ?>
                </p>

                <?php if ($event['venue']): ?>
                    <p>
                        <strong>Venue:</strong>
                        <?= escape($event['venue']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($errors): ?>
                <div class="form-errors" role="alert">
                    <strong>Please check the following:</strong>

                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= escape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (
                !$is_member &&
                (int) $event['allow_guests'] !== 1
            ): ?>

                <p>This event is restricted to eligible members.</p>

                <a href="login.php" class="btn-primary">
                    Member Login
                </a>

            <?php elseif (empty($churches)): ?>

                <p>
                    Registration is temporarily unavailable because no active
                    local churches have been configured. Please contact the
                    regional administrator.
                </p>

                <a href="events.php" class="btn-primary">
                    Back to Events
                </a>

            <?php else: ?>

                <form
                    method="POST"
                    action="register.php?event_id=<?= (int) $event['id'] ?>"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escape($_SESSION['csrf_token']) ?>"
                    >

                    <?php if ($is_member): ?>

                        <p>
                            You are registering using your active member account.
                        </p>

                    <?php else: ?>

                        <div class="form-group">
                            <label for="participant_name">Full Name</label>

                            <input
                                type="text"
                                id="participant_name"
                                name="participant_name"
                                maxlength="150"
                                autocomplete="name"
                                required
                                value="<?= escape($_POST['participant_name'] ?? '') ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="participant_email">Email Address</label>

                            <input
                                type="email"
                                id="participant_email"
                                name="participant_email"
                                maxlength="190"
                                autocomplete="email"
                                required
                                value="<?= escape($_POST['participant_email'] ?? '') ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="participant_phone">
                                Phone Number (Optional)
                            </label>

                            <input
                                type="tel"
                                id="participant_phone"
                                name="participant_phone"
                                maxlength="30"
                                autocomplete="tel"
                                value="<?= escape($_POST['participant_phone'] ?? '') ?>"
                            >
                        </div>

                    <?php endif; ?>

                    <div class="form-group">
                        <label for="church_id">Local Church *</label>

                        <select
                            name="church_id"
                            id="church_id"
                            required
                        >
                            <option value="">
                                -- Select your local church --
                            </option>

                            <?php foreach ($churches as $church): ?>
                                <option
                                    value="<?= (int) $church['id'] ?>"
                                    <?= (string) $selected_church_id ===
                                        (string) $church['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= escape($church['church_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p class="church-note">
                            Select the local church you represent.
                        </p>
                    </div>

                    <button
                        type="submit"
                        class="submit-registration"
                    >
                        Submit Registration
                    </button>
                </form>

            <?php endif; ?>

        <?php endif; ?>

    </section>
</main>

<footer>
    <div>
        <h3>AIC Kabartegan Region Youth Connect</h3>

        <p>
            Connecting People, Strengthening Faith, Building Community.
        </p>
    </div>

    <p class="copyright">
        &copy; <?= date('Y') ?> AIC Kabartegan Region Youth Connect.
    </p>
</footer>

</body>
</html>