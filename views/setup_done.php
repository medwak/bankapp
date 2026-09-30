<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<div class="card narrow">
  <h1>Bank set up</h1>
  <p>You can now <a href="login.php">sign in</a> with the administrator account you just created.
     Staff accounts must enrol two-factor authentication at first sign-in.</p>
  <?php if ($creds): ?>
  <p class="hint">Demo logins (passwords shown once - write them down now):</p>
  <table class="ledger">
    <thead><tr><th>Email</th><th>Password</th></tr></thead>
    <tbody>
    <?php foreach ($creds as $email => $pw): ?>
      <tr><td><?= e($email) ?></td><td class="mono"><?= e($pw) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
