<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$allowedRoles = [
    'chairperson',
    'vice_chairperson',
    'secretary',
    'vice_secretary',
    'treasurer'
];

// Verify current account status and role from the database
$stmt = $pdo->prepare(
    "SELECT role, account_status
     FROM users
     WHERE id = ?"
);
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();

if (
    !$currentUser ||
    $currentUser['account_status'] !== 'active' ||
    !in_array($currentUser['role'], $allowedRoles, true)
) {
    http_response_code(403);
    exit('Access denied. Authorized youth officials only.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$editingRecord = null;

// Save a new record or update an existing record
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Invalid request. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $recordId = filter_input(
                INPUT_POST,
                'id',
                FILTER_VALIDATE_INT
            ) ?: 0;

            $title = trim($_POST['title'] ?? '');
            $recordType = $_POST['record_type'] ?? 'minutes';
            $meetingDate = $_POST['meeting_date'] ?? '';
            $content = trim($_POST['content'] ?? '');
            $confidentiality =
                $_POST['confidentiality'] ?? 'confidential';

            $validTypes = [
                'minutes',
                'resolution',
                'decision',
                'other'
            ];

            $validConfidentiality = [
                'confidential',
                'restricted'
            ];

            $dateObject = DateTime::createFromFormat(
                '!Y-m-d',
                $meetingDate
            );

            $validDate = $dateObject &&
                $dateObject->format('Y-m-d') === $meetingDate;

            if ($title === '' || mb_strlen($title) > 200) {
                $error = 'Enter a title of up to 200 characters.';
            } elseif (!$validDate) {
                $error = 'Enter a valid meeting date.';
            } elseif ($content === '' || mb_strlen($content) > 500000) {
                $error = 'Enter the record content within the allowed length.';
            } elseif (!in_array($recordType, $validTypes, true)) {
                $error = 'Choose a valid record type.';
            } elseif (
                !in_array(
                    $confidentiality,
                    $validConfidentiality,
                    true
                )
            ) {
                $error = 'Choose a valid confidentiality level.';
            } else {
                if ($recordId > 0) {
                    $check = $pdo->prepare(
                        "SELECT id FROM leadership_records WHERE id = ?"
                    );
                    $check->execute([$recordId]);

                    if (!$check->fetch()) {
                        $error = 'Record not found.';
                    }
                }

                if ($error === '') {
                    if ($recordId > 0) {
                        $save = $pdo->prepare(
                            "UPDATE leadership_records
                             SET title = ?,
                                 record_type = ?,
                                 meeting_date = ?,
                                 content = ?,
                                 confidentiality = ?,
                                 updated_by = ?
                             WHERE id = ?"
                        );

                        $save->execute([
                            $title,
                            $recordType,
                            $meetingDate,
                            $content,
                            $confidentiality,
                            $userId,
                            $recordId
                        ]);

                        $message = 'Leadership record updated successfully.';
                    } else {
                        $save = $pdo->prepare(
                            "INSERT INTO leadership_records
                             (title, record_type, meeting_date, content,
                              confidentiality, created_by)
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );

                        $save->execute([
                            $title,
                            $recordType,
                            $meetingDate,
                            $content,
                            $confidentiality,
                            $userId
                        ]);

                        $message = 'Leadership record saved successfully.';
                    }
                }
            }
        }
    }
}

// Load a record for editing
if (isset($_GET['edit'])) {
    $editId = filter_input(
        INPUT_GET,
        'edit',
        FILTER_VALIDATE_INT
    );

    if ($editId && $editId > 0) {
        $stmt = $pdo->prepare(
            "SELECT * FROM leadership_records WHERE id = ?"
        );
        $stmt->execute([$editId]);
        $editingRecord = $stmt->fetch() ?: null;
    }
}

// Search records by title or content
$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT r.*, u.full_name AS author_name
         FROM leadership_records r
         LEFT JOIN users u ON r.created_by = u.id
         WHERE r.title LIKE ? OR r.content LIKE ?
         ORDER BY r.meeting_date DESC, r.id DESC"
    );

    $term = '%' . $search . '%';
    $stmt->execute([$term, $term]);
} else {
    $stmt = $pdo->query(
        "SELECT r.*, u.full_name AS author_name
         FROM leadership_records r
         LEFT JOIN users u ON r.created_by = u.id
         ORDER BY r.meeting_date DESC, r.id DESC"
    );
}

$records = $stmt->fetchAll();

function h($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$formId = $editingRecord['id'] ?? '';
$formTitle = $editingRecord['title'] ?? '';
$formType = $editingRecord['record_type'] ?? 'minutes';
$formDate = $editingRecord['meeting_date'] ?? date('Y-m-d');
$formContent = $editingRecord['content'] ?? '';
$formConfidentiality =
    $editingRecord['confidentiality'] ?? 'confidential';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leadership Records | Youth Connect</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f4f7f4;
            color: #26352b;
            font-family: Arial, sans-serif;
        }

        header {
            background: #14532d;
            color: white;
            padding: 24px 5%;
        }

        header h1 { margin: 0 0 8px; }

        .container {
            width: min(1100px, 94%);
            margin: 28px auto;
        }

        .notice {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }

        .panel {
            background: white;
            padding: 23px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 3px 12px #0000000a;
        }

        h2 { color: #14532d; margin-top: 0; }

        label {
            display: block;
            font-weight: bold;
            margin: 14px 0 7px;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5ce;
            border-radius: 7px;
            font: inherit;
        }

        textarea {
            min-height: 220px;
            resize: vertical;
        }

        .btn, .nav-link {
            display: inline-block;
            border: 0;
            border-radius: 7px;
            padding: 10px 14px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .btn {
            background: #166534;
            color: white;
            margin-top: 16px;
        }

        .nav-link {
            background: #e0ebe2;
            color: #14532d;
            margin: 0 8px 16px 0;
        }

        .record {
            padding: 17px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .record:last-child { border-bottom: none; }

        .record h3 {
            margin: 0 0 8px;
            color: #14532d;
        }

        .record-meta {
            color: #647067;
            font-size: 13px;
        }

        .record-content {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            line-height: 1.7;
        }

        .search-row {
            display: flex;
            gap: 10px;
            align-items: end;
        }

        .search-row > div { flex: 1; }

        .print-button {
            display: inline-block;
            background: #14532d;
            color: #fff;
            border: 0;
            border-radius: 7px;
            padding: 10px 14px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 0 16px;
        }

        .record-actions {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .record-print-btn {
            background: #174a87;
            color: #fff;
            border: 0;
            border-radius: 7px;
            padding: 9px 13px;
            cursor: pointer;
            font-size: 14px;
        }

        .record-print-btn:hover {
            background: #103764;
        }

        .print-letterhead {
            display: none;
        }

        @media (max-width: 600px) {
            .search-row { display: block; }
        }

        /* ---------- PRINT STYLES ---------- */
        @page {
            size: A4;
            margin: 18mm;
        }

        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
                font-size: 11pt;
            }

            header,
            nav,
            #record-form,
            .search-row,
            .print-button,
            .edit-record-link,
            .record-print-btn,
            .record-actions,
            .no-print {
                display: none !important;
            }

            .container {
                width: 100%;
                margin: 0;
            }

            .panel {
                box-shadow: none;
                border: none;
                padding: 0;
                margin: 0;
            }

            .print-letterhead {
                display: block;
                text-align: center;
                border-bottom: 3px double #14532d;
                padding: 0 0 14px;
                margin-bottom: 20px;
            }

            .print-letterhead h1 {
                margin: 0 0 5px;
                color: #14532d;
                font-size: 18pt;
                letter-spacing: .5px;
            }

            .print-letterhead h2 {
                margin: 0 0 5px;
                color: #222;
                font-size: 13pt;
            }

            .print-letterhead p {
                margin: 3px 0;
                font-size: 9pt;
            }

            .print-report-date {
                text-align: right;
                font-size: 9pt;
                margin: 0 0 18px;
            }

            .record {
                break-inside: avoid;
                page-break-inside: avoid;
                padding: 12px 0;
                border-bottom: 1px solid #aaa;
            }

            .record h3 {
                color: #000;
            }

            .record-meta {
                color: #333;
            }

            .record-content {
                white-space: pre-wrap;
                overflow-wrap: anywhere;
            }

            a {
                color: #000;
                text-decoration: none;
            }
        }
    </style>
</head>

<body>
<header>
    <h1>Leadership Records Portal</h1>
    <p>Confidential minutes, decisions and resolutions</p>
</header>

<main class="container">
    <nav>
        <a class="nav-link" href="dashboard.php">← Dashboard</a>
    </nav>

    <p>
        Signed in as <strong><?= h($currentUser['role']) ?></strong>.
        Keep confidential records within authorized leadership channels.
    </p>

    <?php if ($message !== ''): ?>
        <div class="notice success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Add / Edit form -->
    <section class="panel" id="record-form">
        <h2>
            <?= $editingRecord ? 'Edit Record' : 'Add Leadership Record' ?>
        </h2>

        <form method="POST" action="leadership_records.php">
            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= h($formId) ?>">

            <label for="title">Record title</label>
            <input id="title" name="title" maxlength="200"
                   value="<?= h($formTitle) ?>" required>

            <label for="record_type">Record type</label>
            <select id="record_type" name="record_type" required>
                <option value="minutes" <?= $formType === 'minutes' ? 'selected' : '' ?>>Meeting Minutes</option>
                <option value="resolution" <?= $formType === 'resolution' ? 'selected' : '' ?>>Resolution</option>
                <option value="decision" <?= $formType === 'decision' ? 'selected' : '' ?>>Decision</option>
                <option value="other" <?= $formType === 'other' ? 'selected' : '' ?>>Other</option>
            </select>

            <label for="meeting_date">Meeting date</label>
            <input type="date" id="meeting_date" name="meeting_date"
                   value="<?= h($formDate) ?>" required>

            <label for="confidentiality">Confidentiality</label>
            <select id="confidentiality" name="confidentiality" required>
                <option value="confidential" <?= $formConfidentiality === 'confidential' ? 'selected' : '' ?>>
                    Confidential — leadership only
                </option>
                <option value="restricted" <?= $formConfidentiality === 'restricted' ? 'selected' : '' ?>>
                    Restricted — authorized officials only
                </option>
            </select>

            <label for="content">Minutes or record details</label>
            <textarea id="content" name="content" maxlength="500000"
                      required><?= h($formContent) ?></textarea>

            <button class="btn" type="submit">
                <?= $editingRecord ? 'Update Record' : 'Save Record' ?>
            </button>

            <?php if ($editingRecord): ?>
                <a class="nav-link" href="leadership_records.php">Cancel</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- Saved records list -->
    <section class="panel" id="saved-records">
        <div class="print-letterhead">
            <h1>AIC KABARTEGAN REGION</h1>
            <h2>YOUTH CONNECT</h2>
            <p>Connecting People, Strengthening Faith, Building Community</p>
            <p><strong>LEADERSHIP RECORDS REPORT</strong></p>
        </div>

        <p class="print-report-date">
            Generated on: <?= h(date('d F Y, H:i')) ?>
        </p>

        <button type="button" class="print-button no-print"
                onclick="printAllRecords()">
            🖨️ Print All Records
        </button>

        <h2>Saved Leadership Records</h2>

        <form method="GET" action="leadership_records.php"
              class="search-row no-print">
            <div>
                <label for="search">Search records</label>
                <input id="search" name="search"
                       value="<?= h($search) ?>"
                       placeholder="Search titles or record contents">
            </div>
            <button class="btn" type="submit">Search</button>
        </form>

        <?php if (!$records): ?>
            <p>No leadership records found.</p>
        <?php else: ?>
            <?php foreach ($records as $record): ?>
                <article class="record"
                         data-record-id="<?= (int) $record['id'] ?>">
                    <h3><?= h($record['title']) ?></h3>

                    <p class="record-meta">
                        <?= h(ucwords(str_replace('_', ' ', $record['record_type']))) ?>
                        · <?= h($record['meeting_date']) ?>
                        · <?= h(ucfirst($record['confidentiality'])) ?>
                        · Created by <?= h($record['author_name'] ?? 'Unknown') ?>
                    </p>

                    <div class="record-content"><?= h($record['content']) ?></div>

                    <div class="record-actions no-print">
                        <button
                            type="button"
                            class="record-print-btn"
                            onclick="printSingleRecord(<?= (int) $record['id'] ?>)"
                        >
                            🖨️ Print This Record
                        </button>

                        <a class="nav-link edit-record-link"
                           href="leadership_records.php?edit=<?= (int) $record['id'] ?>#record-form">
                            Edit Record
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<script>
function printSingleRecord(recordId) {
    // Mark page as single-record print
    document.body.classList.add('printing-single');

    // Remember which records were visible before we touched them
    const allRecords = document.querySelectorAll('.record');
    const hiddenStates = [];

    allRecords.forEach(function (record) {
        hiddenStates.push({
            element: record,
            display: record.style.display
        });

        if (Number(record.dataset.recordId) !== Number(recordId)) {
            record.style.display = 'none';
        }
    });

    // Also hide the heading, search bar and "Print All" button
    const heading = document.querySelector('#saved-records > h2');
    const searchRow = document.querySelector('#saved-records .search-row');
    const printAllBtn = document.querySelector('#saved-records .print-button');

    if (heading) heading.style.display = 'none';
    if (searchRow) searchRow.style.display = 'none';
    if (printAllBtn) printAllBtn.style.display = 'none';

    // Trigger print, then restore everything afterwards
    window.print();

    // Small timeout so the browser finishes generating print preview
    setTimeout(function () {
        hiddenStates.forEach(function (state) {
            state.element.style.display = state.display;
        });

        if (heading) heading.style.display = '';
        if (searchRow) searchRow.style.display = '';
        if (printAllBtn) printAllBtn.style.display = '';

        document.body.classList.remove('printing-single');
    }, 500);
}

function printAllRecords() {
    document.body.classList.remove('printing-single');
    window.print();
}
</script>
</body>
</html>