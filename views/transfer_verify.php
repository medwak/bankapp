<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $cur = Bank\Config::get('currency'); ?>
<div class="card narrow">
  <h1>Confirm your transfer</h1>
  <dl class="kv">
    <dt>Amount</dt><dd><strong><?= e($cur) ?> <?= money((int) $p['amount_minor']) ?></strong></dd>
    <dt>To account</dt><dd class="mono"><?= e($p['to']) ?></dd>
    <dt>Account holder</dt><dd><?= e($payee) ?></dd>
    <?php if ($p['memo'] !== ''): ?><dt>Reference</dt><dd><?= e($p['memo']) ?></dd><?php endif; ?>
  </dl>
  <form method="post" action="transfer-verify.php" data-once>
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <label for="code"><?= $p['method'] === 'totp' ? 'Code from your authenticator app' : 'Code we emailed you' ?></label>
    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="16">
    <button type="submit">Confirm and send</button>
  </form>
  <p class="hint">This extra check protects you if someone else gets hold of your password. <a href="transfer.php">Cancel</a></p>
</div>
