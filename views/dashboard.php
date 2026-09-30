<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $cur = Bank\Config::get('currency'); ?>
<h1>My accounts</h1>

<?php if ((int) $user['kyc_tier'] < 2): ?>
  <div class="flash info">Your daily transfer limit is <?= e($cur) ?> <?= money($limit) ?>.
    <a href="kyc.php">Verify your identity</a> to raise it.</div>
<?php endif; ?>

<?php foreach ($pending as $p): ?>
  <div class="flash info">A transfer of <?= e($cur) ?> <?= money((int) $p['amount']) ?> is being reviewed. The funds are reserved and you will be notified.</div>
<?php endforeach; ?>

<div class="grid">
<?php foreach ($accounts as $a): ?>
  <div class="card">
    <div class="muted"><?= $a['product'] === 'SAV' ? 'Savings account' : 'Current account' ?> <?= $a['status'] !== 'ACTIVE' ? '<span class="pill warn">' . e($a['status']) . '</span>' : '' ?></div>
    <div class="mono"><?= e($a['acct_no']) ?></div>
    <div class="balance"><?= e($cur) ?> <?= money((int) $a['available']) ?></div>
    <div class="hint">Available<?= (int) $a['available'] !== (int) $a['ledger_balance'] ? ' (ledger ' . money((int) $a['ledger_balance']) . ', ' . money((int) $a['ledger_balance'] - (int) $a['available']) . ' reserved)' : '' ?></div>
    <a class="btn secondary" href="account.php?id=<?= (int) $a['id'] ?>">Statement</a>
  </div>
<?php endforeach; ?>
</div>

<p><a class="btn" href="transfer.php">Make a transfer</a></p>
<p class="hint">Sent today: <?= e($cur) ?> <?= money($sent) ?> of <?= e($cur) ?> <?= money($limit) ?> daily limit.</p>

<h2>Recent activity</h2>
<div class="card scroll">
<table>
  <thead><tr><th>Date</th><th>Account</th><th>Description</th><th class="n">Money out</th><th class="n">Money in</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $r): ?>
    <tr><td><?= e($r['bdate']) ?></td><td class="mono"><?= e($r['acct_no']) ?></td><td><?= e($r['description']) ?><div class="hint mono"><?= e($r['txn_ref']) ?></div></td>
      <td class="n"><?= (int) $r['debit'] ? money((int) $r['debit']) : '' ?></td><td class="n"><?= (int) $r['credit'] ? money((int) $r['credit']) : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$recent): ?><tr><td colspan="5" class="muted">No activity yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
