<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<?php $cur = Bank\Config::get('currency'); ?>
<h1>Transfer money</h1>
<?php if ((int) $user['kyc_tier'] < 1): ?>
  <div class="flash error">Confirm your email address before making transfers.</div>
<?php endif; ?>
<div class="card narrow left">
  <form method="post" action="transfer.php" data-once>
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <label for="from">From</label>
    <select id="from" name="from" required>
      <?php foreach ($accounts as $a): ?>
        <option value="<?= (int) $a['id'] ?>" <?= ((string) ($old['from'] ?? '')) === (string) $a['id'] ? 'selected' : '' ?>>
          <?= $a['product'] === 'SAV' ? 'Savings' : 'Current' ?> <?= e($a['acct_no']) ?> - <?= e($cur) ?> <?= money((int) $a['available']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label for="to">To account number</label>
    <input id="to" name="to" inputmode="numeric" autocomplete="off" required maxlength="12" value="<?= e($old['to'] ?? '') ?>">
    <div class="hint" id="payee" aria-live="polite"></div>
    <label for="amount">Amount (<?= e($cur) ?>)</label>
    <input id="amount" name="amount" inputmode="decimal" autocomplete="off" required value="<?= e($old['amount'] ?? '') ?>">
    <label for="memo">Reference (optional)</label>
    <input id="memo" name="memo" maxlength="140" value="<?= e($old['memo'] ?? '') ?>">
    <button type="submit">Continue</button>
  </form>
  <p class="hint">Daily limit <?= e($cur) ?> <?= money($limit) ?> · sent today <?= e($cur) ?> <?= money($sent) ?>.
    Larger or unusual transfers ask for a second verification step.</p>
</div>
