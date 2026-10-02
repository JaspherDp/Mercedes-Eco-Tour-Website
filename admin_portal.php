<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Choose the appropriate iTour Mercedes administration portal.">
  <title>iTour Mercedes - Admin Portal</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="stylesheet" href="styles/auth-portal.css?v=<?= (int)@filemtime(__DIR__ . '/styles/auth-portal.css') ?>">
  <link rel="stylesheet" href="styles/admin-portal-selector.css?v=<?= (int)@filemtime(__DIR__ . '/styles/admin-portal-selector.css') ?>">
</head>
<body class="auth-page admin-portal-page">
  <main class="admin-portal-card" aria-labelledby="adminPortalTitle">
    <div class="adlog-brand admin-portal-brand">
      <img class="adlog-brand-logo" src="img/newlogo.png" alt="">
      <img class="adlog-brand-wordmark" src="img/textlogo2-transparent.png" alt="iTour Mercedes">
    </div>

    <header class="admin-portal-heading">
      <span class="admin-portal-eyebrow">Secure staff access</span>
      <h1 id="adminPortalTitle">Which portal do you need?</h1>
    </header>

    <nav class="admin-portal-options" aria-label="Administration portals">
      <a class="admin-portal-option" href="php/admin_login.php">
        <span class="admin-portal-copy"><strong>Administrator</strong></span>
      </a>

      <a class="admin-portal-option" href="php/hotel_admin_login.php">
        <span class="admin-portal-copy"><strong>Hotel / Resort Admin</strong></span>
      </a>

      <a class="admin-portal-option" href="php/operator_login.php">
        <span class="admin-portal-copy"><strong>Operator</strong></span>
      </a>
    </nav>

    <a class="admin-portal-back" href="./">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg>
      Back to iTour Mercedes
    </a>
  </main>
</body>
</html>
