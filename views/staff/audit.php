<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<h1>Audit trail</h1>
<div class="card scroll"><table class="ledger">
  <thead><tr><th>#</th><th>Time (UTC)</th><th>User</th><th>IP</th><th>Action</th><th>Detail</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= (int) $r['id'] ?></td><td><?= e($r['at']) ?></td><td><?= e((string) $r['user_id']) ?></td><td><?= e($r['ip']) ?></td><td><?= e($r['action']) ?></td><td><?= e($r['detail']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="hint">Latest 200 entries. Append-only: rows cannot be edited or deleted once written (see db/schema.sql).</p>
