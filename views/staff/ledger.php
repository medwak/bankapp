<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $g = $ledger['account']; ?>
<h1>General ledger</h1>
<form method="get" class="card row">
  <div><label for="gl">GL account</label>
    <select id="gl" name="gl"><?php foreach ($coa as $c): ?><option value="<?= e($c['code']) ?>" <?= $c['code'] === $gl ? 'selected' : '' ?>><?= e($c['code']) ?> <?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from ?? '') ?>"></div>
  <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to ?? '') ?>"></div>
  <div><button type="submit" class="secondary">Show</button></div>
</form>
<?php if ($g): ?>
<div class="card scroll">
  <h2 class="flush"><?= e($g['code']) ?> - <?= e($g['name']) ?> <span class="pill"><?= e($g['type']) ?> · normal <?= $g['normal_side'] === 'D' ? 'debit' : 'credit' ?></span></h2>
  <table class="ledger">
    <thead><tr><th>Date</th><th>Ref</th><th>Sub-account</th><th>Description</th><th class="n">Debit</th><th class="n">Credit</th><th class="n">Balance</th></tr></thead>
    <tbody>
      <?php if ($from): ?><tr><td colspan="6">Brought forward</td><td class="n"><?= money($ledger['opening']) ?></td></tr><?php endif; ?>
      <?php foreach ($ledger['rows'] as $r): ?>
        <tr><td><?= e($r['bdate']) ?></td><td><?= e($r['txn_ref']) ?></td><td><?= e($r['acct_no'] ?? '') ?></td><td><?= e($r['description']) ?></td>
          <td class="n"><?= (int) $r['debit'] ? money((int) $r['debit']) : '' ?></td><td class="n"><?= (int) $r['credit'] ? money((int) $r['credit']) : '' ?></td><td class="n"><?= money((int) $r['balance']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="6">Closing balance</td><td class="n"><?= money($ledger['closing']) ?></td></tr></tfoot>
  </table>
  <?php if (count($ledger['rows']) >= 2000): ?><p class="hint">Showing the first 2,000 lines; narrow the date range.</p><?php endif; ?>
</div>
<?php endif; ?>
