<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<h1>End of day</h1>
<div class="card">
  <p>Business date <strong><?= e($bdate) ?></strong> will be closed and <strong><?= e($next) ?></strong> opened.</p>
  <ol>
    <li>Every transaction of the day is checked for balance</li>
    <li>Interest accrues on savings</li>
    <li>Month-end capitalises accrued interest</li>
    <li>Subsidiary ledgers are reconciled to the control accounts</li>
    <li>The trial balance must balance; a snapshot is stored</li>
    <li>The day is closed and the next business day opened</li>
  </ol>
  <?php if ($pending): ?><div class="flash info"><?= (int) $pending ?> held transfer(s) are still awaiting fraud review. Their funds stay reserved across the close.</div><?php endif; ?>
  <form method="post" action="staff-eod.php">
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <label for="confirm">Type the business date (<?= e($bdate) ?>) to confirm</label>
    <input id="confirm" name="confirm" autocomplete="off" required pattern="\d{4}-\d{2}-\d{2}">
    <button type="submit">Run end of day</button>
  </form>
</div>

<?php if ($result): ?>
<div class="card">
  <h2 class="flush">Result: <?= $result['ok'] ? '<span class="pill ok">closed</span>' : '<span class="pill bad">aborted - nothing was changed</span>' ?></h2>
  <pre class="mono"><?= e(implode("\n", $result['log'])) ?></pre>
</div>
<?php endif; ?>

<h2>Recent runs</h2>
<div class="card scroll"><table class="ledger">
  <thead><tr><th>#</th><th>Business date</th><th>Started</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): ?>
    <tr><td><?= (int) $r['id'] ?></td><td><?= e($r['bdate']) ?></td><td><?= e(date('Y-m-d H:i:s', (int) $r['started_at'])) ?></td><td><?= e($r['status']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
