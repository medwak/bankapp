<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $cur = Bank\Config::get('currency'); ?>
<h1>Fraud review</h1>
<?php foreach ($rows as $r): ?>
<div class="card">
  <div class="row">
    <div>
      <strong><?= e($cur) ?> <?= money((int) $r['amount']) ?></strong>
      <span class="pill <?= $r['status'] === 'PENDING' ? 'warn' : ($r['status'] === 'APPROVED' ? 'ok' : 'bad') ?>"><?= e($r['status']) ?></span>
      <span class="pill">risk <?= (int) $r['score'] ?>/100 · <?= e($r['decision']) ?></span>
      <div class="hint">Case #<?= (int) $r['id'] ?> · <?= e(date('Y-m-d H:i', (int) $r['created_at'])) ?></div>
    </div>
    <div>
      <div>From <strong><?= e($r['full_name']) ?></strong> <span class="mono"><?= e($r['from_no']) ?></span></div>
      <div>To <strong><?= e($r['to_name']) ?></strong> <span class="mono"><?= e($r['to_no']) ?></span></div>
      <?php if ($r['memo'] !== ''): ?><div class="hint">“<?= e($r['memo']) ?>”</div><?php endif; ?>
    </div>
  </div>
  <ul class="reasons"><?php foreach ($r['reasons'] as $why): ?><li><?= e($why) ?></li><?php endforeach; ?></ul>
  <?php if ($r['status'] === 'PENDING' && $user['role'] === 'supervisor'): ?>
    <form method="post" action="staff-fraud.php?id=<?= (int) $r['id'] ?>">
      <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
      <label for="note<?= (int) $r['id'] ?>">Decision note (required)</label>
      <input id="note<?= (int) $r['id'] ?>" name="note" required minlength="5" maxlength="300">
      <button name="decision" value="approve" type="submit">Approve &amp; post</button>
      <button name="decision" value="reject" type="submit" class="danger">Reject &amp; release funds</button>
    </form>
  <?php elseif ($r['note']): ?>
    <div class="hint">Note: <?= e($r['note']) ?></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card muted">No cases yet.</div><?php endif; ?>
