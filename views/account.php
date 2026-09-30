<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $cur = Bank\Config::get('currency'); ?>
<h1><?= $acct['product'] === 'SAV' ? 'Savings' : 'Current' ?> account statement</h1>
<p class="mono"><?= e($acct['acct_no']) ?> · balance <?= e($cur) ?> <?= money((int) $acct['ledger_balance']) ?> · available <?= e($cur) ?> <?= money($available) ?></p>

<form method="get" class="row card">
  <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from ?? '') ?>"></div>
  <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($to ?? '') ?>"></div>
  <div><button type="submit" class="secondary">Filter</button></div>
</form>

<div class="card scroll">
<table>
  <thead><tr><th>Date</th><th>Reference</th><th>Details</th><th class="n">Debit</th><th class="n">Credit</th><th class="n">Balance</th></tr></thead>
  <tbody>
    <?php if ($from): ?><tr><td colspan="5" class="muted">Balance brought forward</td><td class="n"><?= money($st['opening']) ?></td></tr><?php endif; ?>
    <?php foreach ($st['rows'] as $r): ?>
      <tr><td><?= e($r['bdate']) ?></td><td class="mono"><?= e($r['txn_ref']) ?> <span class="pill"><?= e($r['type_code']) ?></span></td><td><?= e($r['description']) ?></td>
        <td class="n"><?= (int) $r['debit'] ? money((int) $r['debit']) : '' ?></td>
        <td class="n"><?= (int) $r['credit'] ? money((int) $r['credit']) : '' ?></td>
        <td class="n"><?= money((int) $r['balance']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$st['rows']): ?><tr><td colspan="6" class="muted">No transactions in this period.</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr><td colspan="5">Closing balance</td><td class="n"><?= money($st['closing']) ?></td></tr></tfoot>
</table>
</div>
