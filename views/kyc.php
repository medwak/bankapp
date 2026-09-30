<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<h1>Verify your identity</h1>
<p>Your current tier is <strong>Tier <?= (int) $user['kyc_tier'] ?></strong>. Verified customers get higher daily transfer limits.</p>

<?php if ($p && $p['status'] === 'PENDING'): ?>
  <div class="flash info">Your details were submitted and are waiting for review.</div>
<?php elseif ($p && $p['status'] === 'APPROVED'): ?>
  <div class="flash ok">Your identity has been verified (Tier <?= (int) $p['assigned_tier'] ?>).</div>
<?php else: ?>
  <?php if ($p && $p['status'] === 'REJECTED'): ?>
    <div class="flash error">Your last submission could not be approved<?= $p['note'] ? ': ' . e($p['note']) : '' ?>. You can submit again.</div>
  <?php endif; ?>
  <div class="card narrow left">
    <form method="post" action="kyc.php" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
      <label for="legal_name">Full legal name (as on your ID)</label>
      <input id="legal_name" name="legal_name" required value="<?= e($old['legal_name'] ?? '') ?>">
      <label for="dob">Date of birth</label>
      <input id="dob" name="dob" type="date" required value="<?= e($old['dob'] ?? '') ?>">
      <label for="id_type">ID type</label>
      <select id="id_type" name="id_type" required>
        <?php foreach (['NATIONAL_ID' => 'National ID', 'PASSPORT' => 'Passport', 'DRIVING_LICENCE' => 'Driving licence'] as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= ($old['id_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="id_number">ID number</label>
      <input id="id_number" name="id_number" required maxlength="20" value="">
      <div class="hint">Stored encrypted. Staff only ever see the last 4 characters.</div>
      <label for="address">Residential address</label>
      <textarea id="address" name="address" rows="3" required><?= e($old['address'] ?? '') ?></textarea>
      <button type="submit">Submit for review</button>
    </form>
  </div>
<?php endif; ?>
