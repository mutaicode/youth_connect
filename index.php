<?php
require_once 'config/database.php';

$stmt = $pdo->query("
    SELECT id, title, content, published_at, created_at
    FROM announcements
    WHERE status = 'published'
    ORDER BY COALESCE(published_at, created_at) DESC, id DESC
    LIMIT 5
");

$announcements = $stmt->fetchAll();

function announcementText($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Build a short excerpt from announcement content.
 * Strips line breaks, collapses whitespace, and appends an ellipsis.
 */
function announcementExcerpt($content, $limit = 180) {
    $text = preg_replace('/\s+/u', ' ', trim((string) $content));

    if (mb_strlen($text) <= $limit) {
        return ['excerpt' => $text, 'truncated' => false];
    }

    $excerpt = mb_substr($text, 0, $limit);
    $lastSpace = mb_strrpos($excerpt, ' ');

    if ($lastSpace !== false) {
        $excerpt = mb_substr($excerpt, 0, $lastSpace);
    }

    return ['excerpt' => $excerpt . '…', 'truncated' => true];
}

/**
 * Format a date for display, with a fallback if null.
 */
function announcementDate($published, $created) {
    $value = $published ?: $created;

    if (!$value) {
        return 'Date unavailable';
    }

    $ts = strtotime($value);

    return $ts ? date('d M Y', $ts) : 'Date unavailable';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AIC Kabartegan Region Youth Connect</title>
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        /* Announcements section styles (scoped, safe to add) */
        .announcements-section {
            padding: 60px 5%;
            background: #f7faf7;
        }

        .announcements-section .section-heading {
            max-width: 720px;
            margin: 0 auto 40px;
            text-align: center;
        }

        .announcements-section .section-heading .section-label {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            background: #e0eee3;
            color: #14532d;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1px;
            margin-bottom: 14px;
        }

        .announcements-section .section-heading h2 {
            margin: 0 0 12px;
            color: #14532d;
            font-size: 30px;
        }

        .announcements-section .section-heading p {
            margin: 0;
            color: #5f6d63;
            line-height: 1.6;
        }

        .announcements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 22px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .announcement-card {
            display: flex;
            flex-direction: column;
            background: #fff;
            border: 1px solid #e3ebe5;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 4px 14px rgba(20, 83, 45, 0.05);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(20, 83, 45, 0.09);
        }

        .announcement-tag {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #fdf6e3;
            color: #8a6d1a;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            align-self: flex-start;
            margin-bottom: 14px;
        }

        .announcement-card h3 {
            margin: 0 0 8px;
            color: #14532d;
            font-size: 19px;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .announcement-date {
            margin: 0 0 14px;
            color: #7a877f;
            font-size: 13px;
        }

        .announcement-content {
            margin: 0;
            color: #3f4a42;
            font-size: 15px;
            line-height: 1.65;
            overflow-wrap: anywhere;
        }

        /* Collapsible full content */
        .announcement-full {
            display: none;
            margin-top: 8px;
            color: #3f4a42;
            font-size: 15px;
            line-height: 1.7;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .announcement-card.expanded .announcement-full {
            display: block;
        }

        .announcement-card.expanded .announcement-excerpt {
            display: none;
        }

        .announcement-toggle {
            margin-top: 16px;
            align-self: flex-start;
            border: 0;
            background: transparent;
            color: #166534;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }

        .announcement-toggle:hover {
            color: #0f3f24;
        }

        .announcement-empty {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px 20px;
            color: #6b7a70;
            background: #fff;
            border: 1px dashed #cfdcd3;
            border-radius: 14px;
        }

        .announcement-empty h3 {
            margin: 0 0 8px;
            color: #14532d;
        }

        @media (max-width: 600px) {
            .announcements-section {
                padding: 45px 5%;
            }

            .announcement-card {
                padding: 20px;
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
        <a href="#home">Home</a>
        <a href="#about">About</a>
        <a href="#programs">Programs</a>
        <a href="#events">Events</a>
        <a href="#contact">Contact</a>
        <a href="member_register.php">Become a Member</a>
    </nav>

    <a href="login.php" class="login-btn">Leadership Login</a>
</header>

<main>
    <section class="hero" id="home">
        <div class="hero-content">
            <span class="eyebrow">FAITH · UNITY · PURPOSE</span>
            <h1>Connecting Youth.<br><span>Building Purpose.</span></h1>
            <p>
                Welcome to AIC Kabartegan Region Youth Connect.
                A community committed to nurturing faith, developing
                young leaders and bringing young people together
                through fellowship and service.
            </p>

            <div class="hero-buttons">
                <a href="#programs" class="btn-primary">Explore Programs</a>
                <a href="#about" class="btn-outline">Discover Our Mission</a>
            </div>
        </div>

        <div class="hero-badge">
            <span>Growing Together</span>
            <strong>Faith. Leadership. Service.</strong>
        </div>
    </section>

    <section class="intro section" id="about">
        <div class="section-heading">
            <span class="eyebrow">WHO WE ARE</span>
            <h2>A place to belong, grow and serve.</h2>
            <p>
                Youth Connect brings together young people across
                AIC Kabartegan Region to strengthen their faith,
                discover their gifts and serve their communities.
            </p>
        </div>

        <div class="values-grid">
            <article class="value-card">
                <div class="value-icon">✦</div>
                <h3>Our Mission</h3>
                <p>To nurture Christ-centred young people equipped to lead and serve.</p>
            </article>

            <article class="value-card">
                <div class="value-icon">♡</div>
                <h3>Our Community</h3>
                <p>To encourage fellowship, friendship and unity among our youth.</p>
            </article>

            <article class="value-card">
                <div class="value-icon">↗</div>
                <h3>Our Vision</h3>
                <p>To inspire a generation of faithful, responsible and impactful leaders.</p>
            </article>
        </div>
    </section>

    <!-- ================= ANNOUNCEMENTS ================= -->
    <section class="announcements-section">
        <div class="section-heading">
            <p class="section-label">STAY UPDATED</p>
            <h2>Latest Announcements</h2>
            <p>Keep up with news and important updates from our youth region.</p>
        </div>

        <div class="announcements-grid">
            <?php if (empty($announcements)): ?>

                <div class="announcement-empty">
                    <h3>No announcements yet</h3>
                    <p>Check back soon for updates from Youth Connect.</p>
                </div>

            <?php else: ?>

                <?php foreach ($announcements as $index => $announcement): ?>
                    <?php
                        $excerptData = announcementExcerpt(
                            $announcement['content'],
                            180
                        );

                        $cardId = 'announcement-' . (int) $announcement['id'];
                        $dateText = announcementDate(
                            $announcement['published_at'] ?? null,
                            $announcement['created_at'] ?? null
                        );
                    ?>

                    <article
                        class="announcement-card"
                        id="<?= announcementText($cardId) ?>"
                    >
                        <span class="announcement-tag">Official Update</span>

                        <h3><?= announcementText($announcement['title']) ?></h3>

                        <p class="announcement-date">
                            <?= announcementText($dateText) ?>
                        </p>

                        <p class="announcement-excerpt announcement-content">
                            <?= announcementText($excerptData['excerpt']) ?>
                        </p>

                        <?php if ($excerptData['truncated']): ?>
                            <div class="announcement-full">
                                <?= nl2br(announcementText($announcement['content'])) ?>
                            </div>

                            <button
                                type="button"
                                class="announcement-toggle"
                                data-target="<?= announcementText($cardId) ?>"
                            >
                                Read more ↓
                            </button>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </section>
    <!-- =============== /ANNOUNCEMENTS ================ -->

    <section class="programs section" id="programs">
        <div class="section-heading">
            <span class="eyebrow">WHAT WE DO</span>
            <h2>Our Annual Programs</h2>
            <p>Discover opportunities to learn, grow, fellowship and serve.</p>
        </div>

        <div class="program-grid">
            <article class="program-card">
                <span class="program-number">01</span>
                <h3>Regional Junior Youth Camp</h3>
                <p>Helping junior youths grow in faith, friendship and Christian values.</p>
            </article>

            <article class="program-card">
                <span class="program-number">02</span>
                <h3>Ex-Candidates Seminars</h3>
                <p>Encouraging spiritual growth and purposeful living after candidacy.</p>
            </article>

            <article class="program-card">
                <span class="program-number">03</span>
                <h3>Regional Senior Youth Camps</h3>
                <p>Building leadership, fellowship and a stronger spiritual foundation.</p>
            </article>

            <article class="program-card featured">
                <span class="program-number">04</span>
                <h3>Vacation Bible School</h3>
                <p>Creating meaningful Bible-learning experiences for children.</p>
            </article>


            <article class="program-card">
                <span class="program-number">05</span>
                <h3>Senior Youth Conferences</h3>
                <p>Bringing young people together for worship, learning and direction.</p>
            </article>
        </div>
    </section>

    <section class="cta-section" id="events">
        <div>
            <span class="eyebrow">BE PART OF THE JOURNEY</span>
            <h2>Your next opportunity to grow starts here.</h2>
            <p>Keep an eye on this page for upcoming events and registration details.</p>
        </div>
        <a href="events.php" class="btn-primary">Explore Events →</a>
    </section>
</main>
<!-- ============================================================
     VBS PORTAL CARD
     ============================================================ -->
<section class="vbs-cta-section" style="padding: 60px 7%; background: #f4f7f4;">
    <div style="max-width: 900px; margin: 0 auto;">
        <div style="background: linear-gradient(135deg, #14532d, #166534); border-radius: 20px; padding: 50px 40px; text-align: center; color: white; box-shadow: 0 20px 40px rgba(20, 83, 45, 0.25); position: relative; overflow: hidden;">
            
            <!-- Decorative accent circles -->
            <div style="position: absolute; top: -30px; right: -30px; width: 120px; height: 120px; background: rgba(251, 191, 36, 0.15); border-radius: 50%;"></div>
            <div style="position: absolute; bottom: -40px; left: -40px; width: 160px; height: 160px; background: rgba(251, 191, 36, 0.1); border-radius: 50%;"></div>

            <div style="position: relative; z-index: 1;">
                <!-- Icon -->
                <div style="width: 90px; height: 90px; background: #fbbf24; color: #14532d; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 44px; margin: 0 auto 25px; box-shadow: 0 8px 20px rgba(251, 191, 36, 0.3);">
                    ⛪
                </div>

                <h2 style="margin: 0 0 15px; font-size: 32px; color: white; font-weight: 700;">
                    Vacation Bible School Portal
                </h2>

                <p style="margin: 0 0 30px; font-size: 16px; line-height: 1.6; color: #d9e8dd; max-width: 600px; margin-left: auto; margin-right: auto;">
                    Are you a VBS Headteacher? Log in to manage your team, submit daily reports, track attendance, and monitor workbook distribution throughout the 5-day program.
                </p>

                <!-- Buttons -->
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    <a href="vbs/login.php" style="display: inline-block; padding: 15px 32px; background: #fbbf24; color: #14532d; font-weight: bold; text-decoration: none; border-radius: 10px; font-size: 16px; box-shadow: 0 6px 15px rgba(251, 191, 36, 0.4); transition: all 0.2s;">
                        Access VBS Portal →
                    </a>

                    <a href="events.php" style="display: inline-block; padding: 15px 32px; background: rgba(255, 255, 255, 0.1); color: white; font-weight: bold; text-decoration: none; border-radius: 10px; font-size: 16px; border: 2px solid rgba(255, 255, 255, 0.3); transition: all 0.2s;">
                        View All Events
                    </a>
                </div>

                <!-- Info line -->
                <p style="margin: 30px 0 0; font-size: 13px; color: #a8c1af;">
                    📌 For Youth Connect Leadership? Access the VBS Admin panel from your <a href="login.php" style="color: #fbbf24; text-decoration: underline;">main dashboard</a>.
                </p>
            </div>
        </div>
    </div>
</section>
<!-- ============================================================
     END VBS PORTAL CARD
     ============================================================ -->

<footer id="contact">
    <div>
        <h3>AIC Kabartegan Region Youth Connect</h3>
        <p>Connecting People, Strengthening Faith, Building Community.</p>
    </div>
    <p class="copyright">
        &copy; <?= date('Y'); ?> AIC Kabartegan Region Youth Connect.
        All rights reserved.
    </p>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.announcement-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var cardId = button.getAttribute('data-target');
            var card = document.getElementById(cardId);

            if (!card) {
                return;
            }

            var isExpanded = card.classList.toggle('expanded');

            button.textContent = isExpanded
                ? 'Show less ↑'
                : 'Read more ↓';
        });
    });
});
</script>

<script src="assets/js/script.js"></script>
</body>
</html>