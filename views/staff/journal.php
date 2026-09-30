<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<h1>General journal</h1>
<form method="get" class="card row">
  <div><label for="date">Business date</label><input id="date" name="date" type="date" value="<?= e($date) ?>"></div>
  <div><button type="submit" class="secondary">Show</button></div>
</form>

<?php foreach ($txns as $t): ?>
<div class="txn">
  <div class="head">
    <strong><?= e($t['txn_ref']) ?></strong><span><?= e($t['type_code']) ?></span><span><?= e($t['description']) ?></span>
    <?= $t['reverses_txn_id'] ? '<span class="pill warn">reversal of #' . (int) $t['reverses_txn_id'] . '</span>' : '' ?>
    <?= $t['reversed'] ? '<span class="pill warn">reversed</span>' : '' ?>
  </div>
  <div class="scroll"><table class="ledger">
    <thead><tr><th>Ln</th><th>GL</th><th>Sub-account</th><th>Narrative</th><th class="n">Debit</th><th class="n">Credit</th></tr></thead>
    <tbody>
    <?php foreach ($t['entries'] as $e): ?>
      <tr><td><?= (int) $e['line_no'] ?></td><td><?= e($e['gl_code']) ?></td><td><?= e($e['acct_no'] ?? '') ?></td><td><?= e($e['narrative']) ?></td>
        <td class="n"><?= (int) $e['debit'] ? money((int) $e['debit']) : '' ?></td><td class="n"><?= (int) $e['credit'] ? money((int) $e['credit']) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($user['role'] === 'supervisor' && !$t['reverses_txn_id'] && !$t['reversed'] && $t['type_code'] !== 'OPN'): ?>
  <details class="reverse"><summary>Reverse this transaction…</summary>
    <form method="post" action="staff-reverse.php" class="row">
      <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
      <input type="hidden" name="txn_id" value="<?= (int) $t['id'] ?>">
      <input name="reason" placeholder="Reason for reversal (required)" minlength="5" required>
      <button type="submit" class="secondary">Post reversal</button>
    </form>
  </details>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$txns): ?><div class="card muted">No transactions on this date.</div><?php endif; ?>
