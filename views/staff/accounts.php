<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $cur = Bank\Config::get('currency'); ?>
<h1>Customer accounts</h1>
<form method="get" class="card row">
  <div><label for="q">Search number, name or email</label><input id="q" name="q" value="<?= e($q) ?>"></div>
  <div><button type="submit" class="secondary">Search</button></div>
</form>
<div class="card scroll">
<table class="ledger">
  <thead><tr><th>Account</th><th>Holder</th><th>Product</th><th>Status</th><th class="n">Ledger balance</th><th>Opened</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="staff-teller.php?acct=<?= e($r['acct_no']) ?>"><?= e($r['acct_no']) ?></a></td><td><?= e($r['full_name']) ?><div class="hint"><?= e($r['email']) ?></div></td>
      <td><?= e($r['product']) ?> / <?= e($r['control_code']) ?></td><td><?= e($r['status']) ?></td><td class="n"><?= money((int) $r['ledger_balance']) ?></td><td><?= e($r['opened_bdate']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No matching accounts.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
