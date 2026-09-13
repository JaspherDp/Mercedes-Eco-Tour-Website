<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
}
require_once __DIR__ . '/../php/admin_auth_helper.php';

$placeholderPages = [
    'earnings-disbursements' => [
        'Earnings & Disbursements',
        'Review platform earnings, settlements, and provider disbursement activity',
        '<path d="M4 6.5h14a2 2 0 0 1 2 2V19H4a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2h12"/><path d="M20 11h-5a2 2 0 0 0 0 4h5M15 13h.01"/>',
    ],
    'payments-transactions' => [
        'Payments & Transactions',
        'Review payments, settlements, and transaction activity',
        '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M7 15h3"/>',
    ],
    'destinations' => [
        'Destination Management',
        'Manage destination listings and tourism information',
        '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/><path d="M9 3v15M15 6v15"/>',
    ],
    'reviews-feedback' => [
        'Reviews & Feedback',
        'Review visitor feedback, ratings, and service experiences',
        '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
    ],
    'complaints-incidents' => [
        'Complaints & Incidents',
        'Track reported concerns, incidents, and resolutions',
        '<path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/>',
    ],
    'reports-analytics' => [
        'Reports & Analytics',
        'Review tourism performance and operational insights',
        '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    ],
    'system-settings' => [
        'System Settings',
        'Configure administrator portal preferences and controls',
        '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
    ],
    'administrator-profile' => [
        'Administrator Profile',
        'Manage administrator identity and account details',
        '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    ],
];

$pageKey = preg_replace(
    '/[^a-z0-9-]/',
    '',
    strtolower((string)($adminPlaceholderKey ?? $_GET['page'] ?? ''))
);
if (!isset($placeholderPages[$pageKey])) {
    http_response_code(404);
    $pageKey = 'destinations';
}
[$pageTitle, $pageSubtitle, $pageIcon] = $placeholderPages[$pageKey];

AdminRequireLogin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> | iTour Mercedes Admin</title>
  <link rel="icon" type="image/png" sizes="32x32" href="img/newlogo.png?v=2">
  <link rel="shortcut icon" type="image/png" href="img/newlogo.png?v=2">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/admin_panel_theme.css">
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      color: #132028;
      background: #f4f8f6;
      font-family: "Poppins", sans-serif;
    }
    .admin-placeholder-main {
      box-sizing: border-box;
      min-height: 100vh;
      padding: 0;
    }
    .admin-placeholder-main * { box-sizing: border-box; }
    .admin-placeholder-canvas {
      min-height: calc(100vh - 70px);
      padding: 24px 28px 32px;
    }
    @media (max-width: 800px) {
      .admin-placeholder-main { margin-left: 0 !important; }
      .admin-placeholder-canvas { padding: 16px; }
    }
  </style>
</head>
<body>
  <div class="admin-container">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>
    <main class="main-content admin-placeholder-main">
      <header class="admin-header admin-page-header">
        <div class="admin-header-left admin-page-title">
          <span class="admin-page-title-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><?= $pageIcon ?></svg>
          </span>
          <div class="admin-page-title-copy">
            <h2><?= htmlspecialchars($pageTitle) ?></h2>
            <p class="admin-header-subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>
          </div>
        </div>
        <div class="admin-header-right"></div>
      </header>
      <section class="admin-placeholder-canvas" aria-label="<?= htmlspecialchars($pageTitle) ?> workspace"></section>
    </main>
  </div>
</body>
</html>
