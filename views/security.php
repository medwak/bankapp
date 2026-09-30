<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<h1>Security</h1>

<?php if ($recovery): ?>
<div class="card">
  <h2>Save your recovery codes</h2>
  <p>Each code works once if you lose your phone. They will <strong>not be shown again</strong>.</p>
  <div class="codes"><?php foreach ($recovery as $c): ?><div><?= e($c) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Two-factor authentication
    <?= $user['totp_enabled'] ? '<span class="pill ok">on</span>' : '<span class="pill warn">off</span>' ?></h2>
  <?php if ($user['totp_enabled']): ?>
    <p>Your account requires a code from your authenticator app at sign-in and for larger transfers.
       Unused recovery codes: <strong><?= (int) $remaining ?></strong>.</p>
  <?php else: ?>
    <p>Add an authenticator app (Google Authenticator, Authy, 1Password, ...). Choose "enter a setup key" and type:</p>
    <div class="secret"><?= e($setup['secret']) ?></div>
    <p class="hint">Account: <?= e($user['email']) ?> · time-based · 6 digits · 30 s.<br>
       Setup link (for apps that accept it): <span class="mono"><?= e($setup['uri']) ?></span></p>
    <form method="post" action="security.php">
      <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
      <input type="hidden" name="action" value="enable2fa">
      <label for="code">Enter the 6-digit code to confirm</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="8">
      <button type="submit">Turn on two-factor</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Change password</h2>
  <form method="post" action="security.php">
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <input type="hidden" name="action" value="password">
    <label for="current">Current password</label>
    <input id="current" name="current" type="password" autocomplete="current-password" required>
    <label for="new">New password</label>
    <input id="new" name="new" type="password" autocomplete="new-password" required minlength="12">
    <label for="new2">Repeat new password</label>
    <input id="new2" name="new2" type="password" autocomplete="new-password" required minlength="12">
    <button type="submit">Change password</button>
  </form>
</div>

<div class="card scroll">
  <h2>Devices that have signed in</h2>
  <table>
    <thead><tr><th>Device</th><th>First seen</th><th>Last seen</th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d): ?>
      <tr><td><?= e($d['user_agent']) ?></td><td><?= e(date('Y-m-d H:i', (int) $d['first_seen'])) ?></td><td><?= e(date('Y-m-d H:i', (int) $d['last_seen'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$devices): ?><tr><td colspan="3" class="muted">None recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="hint">Don't recognise one? Change your password now and contact the bank.</p>
</div>
