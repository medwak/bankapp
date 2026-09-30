<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php
/** @var string $title @var string $content @var ?array $user @var array $flashes */
$app = (string) Bank\Config::get('app_name');
$isStaff = $user && $user['role'] !== 'customer';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body class="<?= $isStaff ? 'staff' : '' ?>">
<header class="top">
  <a class="brand" href="index.php"><span class="mark">&#9638;</span> <?= e($app) ?></a>
  <?php if ($user): ?>
  <nav>
    <?php if ($isStaff): ?>
      <a href="staff.php">Console</a>
      <a href="staff-teller.php">Teller</a>
      <a href="staff-accounts.php">Accounts</a>
      <a href="staff-journal.php">Journal</a>
      <a href="staff-ledger.php">Ledger</a>
      <a href="staff-trial-balance.php">Trial balance</a>
      <a href="staff-fraud.php">Fraud</a>
      <a href="staff-kyc.php">KYC</a>
      <?php if ($user['role'] === 'supervisor'): ?><a href="staff-eod.php">End of day</a><a href="staff-audit.php">Audit</a><a href="staff-users.php">Staff</a><?php endif; ?>
    <?php else: ?>
      <a href="dashboard.php">Accounts</a>
      <a href="transfer.php">Transfer</a>
      <a href="kyc.php">Verify identity</a>
    <?php endif; ?>
    <a href="security.php">Security</a>
    <form method="post" action="logout.php" class="inline"><input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>"><button class="link">Sign out</button></form>
  </nav>
  <?php endif; ?>
</header>
<main>
  <?php foreach ($flashes as [$type, $msg]): ?>
    <div class="flash <?= e($type) ?>" role="status"><?= e($msg) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer>
  <?php if ($isStaff): ?>Business date <strong><?= e(Bank\Ledger::currentDay()) ?></strong> · <?php endif; ?>
  <?= e($app) ?> · demonstration software - not a licensed bank
</footer>
<script src="app.js"></script>
</body>
</html>
