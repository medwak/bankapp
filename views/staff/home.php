<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $cur = Bank\Config::get('currency'); ?>
<h1>Staff console</h1>
<div class="grid">
  <div class="card"><div class="muted">Business date</div><div class="balance"><?= e($bdate) ?></div><div class="hint"><?= $txns ?> transactions posted today</div></div>
  <div class="card"><div class="muted">Fraud cases awaiting review</div><div class="balance"><?= $alerts ?></div><a href="staff-fraud.php">Open queue</a></div>
  <div class="card"><div class="muted">KYC awaiting review</div><div class="balance"><?= $kyc ?></div><a href="staff-kyc.php">Open queue</a></div>
  <div class="card"><div class="muted">Trial balance</div>
    <div><?= $tb['balanced'] ? '<span class="pill ok">in balance</span>' : '<span class="pill bad">OUT OF BALANCE</span>' ?></div>
    <div class="hint">DR <?= money($tb['total_debits']) ?> / CR <?= money($tb['total_credits']) ?></div></div>
  <div class="card"><div class="muted">Subsidiary ledgers</div>
    <div><?= $recon ? '<span class="pill bad">DO NOT RECONCILE</span>' : '<span class="pill ok">reconciled to control accounts</span>' ?></div>
    <?php foreach ($recon as $r): ?><div class="hint"><?= e($r) ?></div><?php endforeach; ?></div>
</div>
<p class="hint">Signed in as <?= e($user['full_name']) ?> (<?= e($user['role']) ?>).</p>
