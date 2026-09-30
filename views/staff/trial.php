<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<h1>Trial balance <?= $asof ? 'as of ' . e($asof) : '(live)' ?></h1>
<form method="get" class="card row">
  <div><label for="asof">As of business date (blank = now)</label><input id="asof" name="asof" type="date" value="<?= e($asof ?? '') ?>"></div>
  <div><button type="submit" class="secondary">Show</button></div>
</form>

<div class="card scroll">
<table class="ledger">
  <thead><tr><th>GL</th><th>Account</th><th>Type</th><th class="n">Debit</th><th class="n">Credit</th></tr></thead>
  <tbody>
  <?php foreach ($tb['rows'] as $r): ?>
    <tr><td><a href="staff-ledger.php?gl=<?= e($r['code']) ?>"><?= e($r['code']) ?></a></td><td><?= e($r['name']) ?></td><td><?= e($r['type']) ?></td>
      <td class="n"><?= $r['dr_balance'] ? money($r['dr_balance']) : '' ?></td><td class="n"><?= $r['cr_balance'] ? money($r['cr_balance']) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="3">TOTAL <?= $tb['balanced'] ? '<span class="pill ok">balanced</span>' : '<span class="pill bad">OUT OF BALANCE</span>' ?></td>
    <td class="n"><?= money($tb['total_debits']) ?></td><td class="n"><?= money($tb['total_credits']) ?></td></tr></tfoot>
</table>
</div>

<div class="grid">
  <div class="card"><h2 class="flush">Financial position</h2>
    <dl class="kv">
      <dt>Assets</dt><dd class="mono"><?= money($st['ASSET']) ?></dd>
      <dt>Liabilities</dt><dd class="mono"><?= money($st['LIABILITY']) ?></dd>
      <dt>Equity</dt><dd class="mono"><?= money($st['EQUITY']) ?></dd>
      <dt>Profit (income - expense)</dt><dd class="mono"><?= money($st['PROFIT']) ?></dd>
      <dt>A - (L + E + P)</dt><dd class="mono"><?= money($st['CHECK']) ?> <?= $st['CHECK'] === 0 ? '<span class="pill ok">ok</span>' : '<span class="pill bad">error</span>' ?></dd>
    </dl></div>
  <div class="card"><h2 class="flush">Subsidiary ledgers</h2>
    <dl class="kv"><?php foreach ($subs as $s): ?><dt><?= e($s['product']) ?> (<?= (int) $s["n"] ?> account(s))</dt><dd class="mono"><?= money((int) $s['bal']) ?></dd><?php endforeach; ?></dl>
    <p class="hint">Must agree with control accounts 2100 / 2200 above. End-of-day refuses to close if they do not.</p></div>
</div>
