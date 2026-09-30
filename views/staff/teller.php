<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $cur = Bank\Config::get('currency'); ?>
<h1>Teller</h1>
<form method="get" class="card row">
  <div><label for="acct">Account number</label><input id="acct" name="acct" inputmode="numeric" value="<?= e($no) ?>" maxlength="12"></div>
  <div><button type="submit" class="secondary">Look up</button></div>
</form>

<?php if ($acct): ?>
<div class="card">
  <dl class="kv">
    <dt>Holder</dt><dd><?= e($acct['holder']) ?></dd>
    <dt>Account</dt><dd class="mono"><?= e($acct['acct_no']) ?> · <?= e($acct['product']) ?> · <?= e($acct['status']) ?></dd>
    <dt>Ledger balance</dt><dd class="mono"><?= e($cur) ?> <?= money((int) $acct['ledger_balance']) ?></dd>
    <dt>Available</dt><dd class="mono"><?= e($cur) ?> <?= money((int) $acct['available']) ?></dd>
  </dl>
  <form method="post" action="staff-teller.php" data-once>
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <input type="hidden" name="account_id" value="<?= (int) $acct['id'] ?>">
    <input type="hidden" name="acct_no" value="<?= e($acct['acct_no']) ?>">
    <div class="row">
      <div><label for="amount">Amount (<?= e($cur) ?>)</label><input id="amount" name="amount" inputmode="decimal" required></div>
      <div><label for="note">Note</label><input id="note" name="note" maxlength="100"></div>
    </div>
    <button name="action" value="deposit" type="submit">Cash deposit</button>
    <button name="action" value="withdraw" type="submit" class="secondary">Cash withdrawal</button>
    <?php if ($user['role'] === 'supervisor'): ?><button name="action" value="fee" type="submit" class="secondary">Service charge</button><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<h2>Open an account for a verified customer</h2>
<form method="post" action="staff-teller.php" class="card row">
  <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
  <input type="hidden" name="action" value="open">
  <div><label for="email">Customer email</label><input id="email" name="email" type="email" required></div>
  <div><label for="product">Product</label><select id="product" name="product"><option value="DDA">Current (DDA)</option><option value="SAV">Savings (SAV)</option></select></div>
  <div><button type="submit">Open account</button></div>
</form>
