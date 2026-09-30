<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<h1>Staff accounts</h1>

<?php if ($created): ?>
<div class="card">
  <h2 class="flush">Created <?= e($created['email']) ?></h2>
  <p>Password (shown once - write it down now): <span class="mono"><?= e($created['password']) ?></span></p>
  <p class="hint">Two-factor authentication is required before this account can do anything else.</p>
</div>
<?php endif; ?>

<div class="card narrow">
  <h2 class="flush">New staff login</h2>
  <form method="post" action="staff-users.php">
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <label for="full_name">Full name</label>
    <input id="full_name" name="full_name" required maxlength="80">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required>
    <label for="role">Role</label>
    <select id="role" name="role">
      <option value="teller">Teller</option>
      <option value="supervisor">Supervisor</option>
    </select>
    <button type="submit">Create staff account</button>
  </form>
</div>

<div class="card scroll">
<table class="ledger">
  <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>2FA</th><th>Created</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= (int) $r['id'] ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['email']) ?></td><td><?= e($r['role']) ?></td>
      <td><?= e($r['status']) ?></td><td><?= $r['totp_enabled'] ? 'on' : 'off' ?></td><td><?= e(date('Y-m-d', (int) $r['created_at'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No staff accounts yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
