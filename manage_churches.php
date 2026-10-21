<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Allow only authorized leadership roles
$allowed_roles = [
    'admin',
    'chairperson',
    'vice_chairperson',
    'secretary',
    'vice_secretary'
];

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], $allowed_roles, true)
) {
    header('Location: login.php');
    exit;
}

// Create CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = 'success';
$editing_church = null;

// 1. Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $message = 'Invalid request. Please refresh the page and try again.';
        $message_type = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'add') {
                $church_name = trim($_POST['church_name'] ?? '');
                $location = trim($_POST['location'] ?? '');
                $contact_person = trim($_POST['contact_person'] ?? '');
                $contact_phone = trim($_POST['contact_phone'] ?? '');

                if ($church_name === '') {
                    throw new Exception('Please enter the church name.');
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO churches
                    (church_name, location, contact_person, contact_phone)
                    VALUES (?, ?, ?, ?)"
                );

                $stmt->execute([
                    $church_name,
                    $location !== '' ? $location : null,
                    $contact_person !== '' ? $contact_person : null,
                    $contact_phone !== '' ? $contact_phone : null
                ]);

                $message = 'Church added successfully.';

            } elseif ($action === 'update') {
                // NEW: Update logic
                $church_id = filter_input(INPUT_POST, 'church_id', FILTER_VALIDATE_INT);
                $church_name = trim($_POST['church_name'] ?? '');
                $location = trim($_POST['location'] ?? '');
                $contact_person = trim($_POST['contact_person'] ?? '');
                $contact_phone = trim($_POST['contact_phone'] ?? '');

                if (!$church_id || $church_id < 1) {
                    throw new Exception('Invalid church selected.');
                }

                if ($church_name === '') {
                    throw new Exception('Please enter the church name.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE churches
                     SET church_name = ?, location = ?, contact_person = ?, contact_phone = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $church_name,
                    $location !== '' ? $location : null,
                    $contact_person !== '' ? $contact_person : null,
                    $contact_phone !== '' ? $contact_phone : null,
                    $church_id
                ]);

                $message = 'Church updated successfully.';

            } elseif ($action === 'toggle') {
                $church_id = filter_input(
                    INPUT_POST,
                    'church_id',
                    FILTER_VALIDATE_INT
                );

                if (!$church_id || $church_id < 1) {
                    throw new Exception('Invalid church selected.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE churches
                     SET status = CASE
                         WHEN status = 'active' THEN 'inactive'
                         ELSE 'active'
                     END
                     WHERE id = ?"
                );

                $stmt->execute([$church_id]);

                if ($stmt->rowCount() > 0) {
                    $message = 'Church status updated successfully.';
                } else {
                    $message = 'No church status was changed.';
                    $message_type = 'error';
                }
            } else {
                throw new Exception('Unknown action.');
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'This church name may already exist. Please check the list.';
            } else {
                error_log($e->getMessage());
                $message = 'A database error occurred. Please try again.';
            }

            $message_type = 'error';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 2. Check if we're in edit mode (via ?edit=ID)
$edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($edit_id && $edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM churches WHERE id = ?");
    $stmt->execute([$edit_id]);
    $editing_church = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editing_church) {
        $message = 'Church not found.';
        $message_type = 'error';
    }
}

// 3. Load churches and summary statistics
$churches = [];
$total_churches = 0;
$active_churches = 0;
$inactive_churches = 0;

try {
    $stmt = $pdo->query(
        "SELECT id, church_name, location, contact_person,
                contact_phone, status, created_at
         FROM churches
         ORDER BY church_name ASC"
    );

    $churches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_churches = count($churches);

    foreach ($churches as $church) {
        if ($church['status'] === 'active') {
            $active_churches++;
        } else {
            $inactive_churches++;
        }
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $message = 'Unable to load churches. Check your database connection and table.';
    $message_type = 'error';
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Churches | Youth Connect</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #243047;
        }

        .topbar {
            background: #153e75;
            color: white;
            padding: 20px 5%;
        }

        .topbar h1 {
            margin: 0 0 6px;
            font-size: 25px;
        }

        .topbar p {
            margin: 0;
            color: #dce8f8;
        }

        .container {
            width: min(1150px, 92%);
            margin: 28px auto;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #153e75;
            text-decoration: none;
            font-weight: bold;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card, .panel {
            background: white;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 14px rgba(25, 45, 80, 0.07);
        }

        .stat-card h3 {
            margin: 0 0 10px;
            color: #65728a;
            font-size: 14px;
        }

        .stat-card strong {
            font-size: 28px;
            color: #153e75;
        }

        .panel {
            margin-bottom: 25px;
        }

        .panel h2 {
            margin-top: 0;
            font-size: 20px;
        }

        /* EDIT MODE BANNER */
        .edit-banner {
            background: #fff7ed;
            border: 1px solid #fdba74;
            color: #7c2d12;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .edit-banner a {
            color: #7c2d12;
            font-weight: bold;
            text-decoration: underline;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5e2;
            border-radius: 7px;
            font-size: 15px;
        }

        .form-group input:focus {
            outline: 2px solid #b6d4fe;
            border-color: #2363a6;
        }

        .btn {
            border: none;
            border-radius: 7px;
            padding: 11px 16px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #17633c;
            color: white;
            margin-top: 18px;
        }

        .btn-primary:hover {
            background: #104d2e;
        }

        .btn-cancel {
            background: #e5ebe5;
            color: #14532d;
            margin-left: 8px;
            margin-top: 18px;
        }

        .btn-toggle {
            background: #e8eef7;
            color: #153e75;
        }

        .btn-edit {
            background: #1769aa;
            color: white;
            text-decoration: none;
        }

        .btn-edit:hover {
            background: #105487;
        }

        .message {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #e4f7eb;
            color: #17633c;
        }

        .message.error {
            background: #fde8e8;
            color: #a12626;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th, td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #e5eaf1;
        }

        th {
            background: #f5f8fc;
            font-size: 13px;
            color: #526079;
        }

        td {
            font-size: 14px;
        }

        .status {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: bold;
        }

        .status.active {
            background: #e0f6e9;
            color: #17633c;
        }

        .status.inactive {
            background: #fce8e8;
            color: #a12626;
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .empty {
            padding: 25px;
            text-align: center;
            color: #65728a;
        }

        @media (max-width: 650px) {
            .stats, .form-grid {
                grid-template-columns: 1fr;
            }

            .topbar h1 {
                font-size: 21px;
            }

            .stat-card, .panel {
                padding: 17px;
            }

            .actions {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <h1>Local Churches Management</h1>
    <p>AIC Kabartegan Region Youth Connect</p>
</header>

<main class="container">

    <a class="back-link" href="dashboard.php">&larr; Back to Dashboard</a>

    <?php if ($message !== ''): ?>
        <div class="message <?= e($message_type) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <h3>Total Churches</h3>
            <strong><?= $total_churches ?></strong>
        </div>

        <div class="stat-card">
            <h3>Active Churches</h3>
            <strong><?= $active_churches ?></strong>
        </div>

        <div class="stat-card">
            <h3>Inactive Churches</h3>
            <strong><?= $inactive_churches ?></strong>
        </div>
    </section>

    <!-- ADD / EDIT FORM -->
    <section class="panel">
        <?php if ($editing_church): ?>
            <div class="edit-banner">
                <span>
                    ✏️ <strong>Editing:</strong>
                    <?= e($editing_church['church_name']) ?>
                </span>
                <a href="manage_churches.php">Cancel Edit</a>
            </div>
            <h2>Edit Church</h2>
        <?php else: ?>
            <h2>Add a Local Church</h2>
            <p>Enter the details of a church in your region.</p>
        <?php endif; ?>

        <form method="POST" action="manage_churches.php">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action"
                   value="<?= $editing_church ? 'update' : 'add' ?>">
            <?php if ($editing_church): ?>
                <input type="hidden" name="church_id"
                       value="<?= (int) $editing_church['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label for="church_name">Church Name *</label>
                    <input type="text" id="church_name" name="church_name"
                           maxlength="150" required
                           value="<?= e($editing_church['church_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location"
                           maxlength="255" placeholder="e.g. Kabartegan"
                           value="<?= e($editing_church['location'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="contact_person">Contact Person</label>
                    <input type="text" id="contact_person" name="contact_person"
                           maxlength="150"
                           value="<?= e($editing_church['contact_person'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="contact_phone">Contact Phone</label>
                    <input type="tel" id="contact_phone" name="contact_phone"
                           maxlength="30"
                           value="<?= e($editing_church['contact_phone'] ?? '') ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <?= $editing_church ? '💾 Save Changes' : '+ Add Church' ?>
            </button>

            <?php if ($editing_church): ?>
                <a href="manage_churches.php" class="btn btn-cancel">Cancel</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- CHURCH LIST -->
    <section class="panel">
        <h2>Registered Churches</h2>

        <?php if (empty($churches)): ?>
            <div class="empty">
                No churches have been added yet. Use the form above to add one.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Church Name</th>
                            <th>Location</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($churches as $index => $church): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= e($church['church_name']) ?></td>
                            <td><?= e($church['location'] ?: '—') ?></td>
                            <td><?= e($church['contact_person'] ?: '—') ?></td>
                            <td><?= e($church['contact_phone'] ?: '—') ?></td>
                            <td>
                                <span class="status <?= e($church['status']) ?>">
                                    <?= ucfirst(e($church['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <!-- EDIT BUTTON (NEW) -->
                                    <a class="btn btn-edit"
                                       href="manage_churches.php?edit=<?= (int) $church['id'] ?>">
                                        ✏️ Edit
                                    </a>

                                    <!-- TOGGLE ACTIVE/INACTIVE -->
                                    <form method="POST" action="manage_churches.php"
                                          onsubmit="return confirm('Change this church status?');"
                                          style="display:inline;">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="church_id"
                                               value="<?= (int) $church['id'] ?>">

                                        <button type="submit" class="btn btn-toggle">
                                            <?= $church['status'] === 'active'
                                                ? 'Deactivate'
                                                : 'Activate' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</main>
</body>
</html>