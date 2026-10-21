
<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$allowedRoles = [
    'admin',
    'chairperson',
    'secretary',
    'vice_secretary'
];

if (!in_array($_SESSION['role'], $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. You are not allowed to manage announcements.');
}

function escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['announcement_csrf'])) {
    $_SESSION['announcement_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = $_SESSION['announcement_message'] ?? '';
unset($_SESSION['announcement_message']);

$editing = null;

// Handle create, update and delete actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['announcement_csrf'], $token)
    ) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = filter_var(
                $_POST['id'] ?? '0',
                FILTER_VALIDATE_INT
            );

            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $status = $_POST['status'] ?? 'draft';

            if ($title === '' || mb_strlen($title) > 200) {
                $errors[] = 'Enter a title of up to 200 characters.';
            }

            if ($content === '') {
                $errors[] = 'Announcement content is required.';
            }

            if (!in_array($status, ['draft', 'published'], true)) {
                $errors[] = 'Choose a valid announcement status.';
            }

            if ($id === false || $id < 0) {
                $errors[] = 'Invalid announcement ID.';
            }

            if (!$errors) {
                if ($id > 0) {
                    $check = $pdo->prepare(
                        'SELECT id FROM announcements WHERE id = :id'
                    );
                    $check->execute(['id' => $id]);

                    if (!$check->fetch()) {
                        $errors[] = 'Announcement not found.';
                    }
                }
            }

            if (!$errors) {
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE announcements
                         SET title = :title,
                             content = :content,
                             status = :status,
                             published_at = CASE
                                 WHEN :published_status = 'published'
                                 THEN COALESCE(published_at, NOW())
                                 ELSE NULL
                             END
                         WHERE id = :id"
                    );

                    $stmt->execute([
                        'title' => $title,
                        'content' => $content,
                        'status' => $status,
                        'published_status' => $status,
                        'id' => $id
                    ]);

                    $_SESSION['announcement_message'] =
                        'Announcement updated successfully.';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO announcements
                            (title, content, status, published_at, created_by)
                         VALUES
                            (:title, :content, :status, :published_at, :created_by)"
                    );

                    $stmt->execute([
                        'title' => $title,
                        'content' => $content,
                        'status' => $status,
                        'published_at' =>
                            $status === 'published' ? date('Y-m-d H:i:s') : null,
                        'created_by' => (int)$_SESSION['user_id']
                    ]);

                    $_SESSION['announcement_message'] =
                        'Announcement created successfully.';
                }

                header('Location: manage_announcements.php');
                exit;
            }
        } elseif ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if ($id === false || $id < 1) {
                $errors[] = 'Invalid announcement ID.';
            } else {
                $stmt = $pdo->prepare(
                    'DELETE FROM announcements WHERE id = :id'
                );
                $stmt->execute(['id' => $id]);

                $_SESSION['announcement_message'] =
                    $stmt->rowCount()
                    ? 'Announcement deleted successfully.'
                    : 'Announcement not found.';

                header('Location: manage_announcements.php');
                exit;
            }
        }
    }
}

// Load an announcement for editing.
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($editId && $editId > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, title, content, status
         FROM announcements
         WHERE id = :id'
    );
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editing) {
        $errors[] = 'The announcement you selected was not found.';
    }
}

// List announcements, including drafts.
$stmt = $pdo->query(
    "SELECT a.id, a.title, a.content, a.status,
            a.published_at, a.created_at, u.full_name AS author
     FROM announcements a
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.created_at DESC, a.id DESC"
);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Announcements | Youth Connect</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f3f6fb;
    color: #263348;
}

header {
    background: #173b67;
    color: white;
    padding: 18px 5%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

header h2 { margin: 0; font-size: 21px; }

header a {
    color: white;
    text-decoration: none;
    margin-left: 15px;
}

.container {
    max-width: 1150px;
    margin: 30px auto;
    padding: 0 18px;
}

h1 { color: #173b67; }

.grid {
    display: grid;
    grid-template-columns: minmax(280px, 0.8fr) minmax(0, 1.5fr);
    gap: 22px;
    align-items: start;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
    margin-bottom: 22px;
}

.card h2 { margin-top: 0; color: #173b67; }

label {
    display: block;
    margin: 14px 0 7px;
    font-weight: bold;
}

input, textarea, select {
    width: 100%;
    padding: 11px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font: inherit;
}

textarea {
    min-height: 170px;
    resize: vertical;
}

button, .button {
    display: inline-block;
    padding: 11px 15px;
    border: none;
    border-radius: 7px;
    background: #1769aa;
    color: white;
    text-decoration: none;
    font-weight: bold;
    cursor: pointer;
}

button:hover, .button:hover { background: #104d80; }

.secondary { background: #64748b; }
.danger { background: #b42318; }
.small { padding: 8px 10px; font-size: 13px; }

.notice {
    padding: 13px 16px;
    border-radius: 8px;
    margin-bottom: 18px;
    overflow-wrap: anywhere;
}

.success { background: #dcfce7; color: #166534; }
.error { background: #fee2e2; color: #991b1b; }

.announcement {
    padding: 16px 0;
    border-bottom: 1px solid #e5eaf1;
}

.announcement:last-child { border-bottom: none; }

.announcement h3 {
    margin: 0 0 8px;
    overflow-wrap: anywhere;
}

.announcement p {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.meta {
    color: #64748b;
    font-size: 13px;
    line-height: 1.7;
}

.badge {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.published { background: #dcfce7; color: #166534; }
.draft { background: #fef3c7; color: #92400e; }

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.actions form { margin: 0; }

@media (max-width: 800px) {
    .grid { grid-template-columns: 1fr; }
    header a { margin-left: 0; margin-right: 10px; }
}
</style>
</head>

<body>
<header>
    <h2>Youth Connect</h2>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main class="container">
    <h1>Manage Announcements</h1>
    <p>Create announcements and publish important updates for members.</p>

    <?php if ($success): ?>
        <div class="notice success"><?= escape($success) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="notice error"><?= escape($error) ?></div>
    <?php endforeach; ?>

    <div class="grid">
        <section class="card">
            <h2><?= $editing ? 'Edit Announcement' : 'Create Announcement' ?></h2>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= escape($_SESSION['announcement_csrf']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id"
                       value="<?= escape($editing['id'] ?? 0) ?>">

                <label for="title">Announcement Title</label>
                <input type="text" id="title" name="title" maxlength="200"
                       required
                       value="<?= escape(
                           $_POST['title'] ?? $editing['title'] ?? ''
                       ) ?>">

                <label for="content">Announcement Content</label>
                <textarea id="content" name="content" required><?= escape(
                    $_POST['content'] ?? $editing['content'] ?? ''
                ) ?></textarea>

                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="draft"
                        <?= ($_POST['status'] ?? $editing['status'] ?? 'draft') === 'draft'
                            ? 'selected' : '' ?>>
                        Save as Draft
                    </option>
                    <option value="published"
                        <?= ($_POST['status'] ?? $editing['status'] ?? 'draft') === 'published'
                            ? 'selected' : '' ?>>
                        Publish Now
                    </option>
                </select>

                <br><br>
                <button type="submit">
                    <?= $editing ? 'Save Changes' : 'Save Announcement' ?>
                </button>

                <?php if ($editing): ?>
                    <a class="button secondary"
                       href="manage_announcements.php">Cancel</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="card">
            <h2>All Announcements (<?= count($announcements) ?>)</h2>

            <?php if (!$announcements): ?>
                <p>No announcements have been created yet.</p>
            <?php else: ?>
                <?php foreach ($announcements as $item): ?>
                    <article class="announcement">
                        <h3><?= escape($item['title']) ?></h3>

                        <span class="badge <?= escape($item['status']) ?>">
                            <?= escape(ucfirst($item['status'])) ?>
                        </span>

                        <p><?= escape($item['content']) ?></p>

                        <div class="meta">
                            Author: <?= escape($item['author'] ?? 'Unknown') ?><br>
                            Created: <?= escape($item['created_at']) ?><br>
                            <?php if ($item['published_at']): ?>
                                Published: <?= escape($item['published_at']) ?>
                            <?php endif; ?>
                        </div>

                        <div class="actions">
                            <a class="button small"
                               href="manage_announcements.php?edit=<?= (int)$item['id'] ?>">
                                Edit
                            </a>

                            <form method="POST"
                                  onsubmit="return confirm('Delete this announcement permanently?');">
                                <input type="hidden" name="csrf_token"
                                       value="<?= escape($_SESSION['announcement_csrf']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id"
                                       value="<?= (int)$item['id'] ?>">
                                <button class="danger small" type="submit">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
