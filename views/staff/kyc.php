<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<h1>KYC review</h1>
<?php foreach ($rows as $r): ?>
<div class="card">
  <div class="row">
    <div>
      <strong><?= e($r['legal_name']) ?></strong>
      <span class="pill <?= $r['status'] === 'PENDING' ? 'warn' : ($r['status'] === 'APPROVED' ? 'ok' : 'bad') ?>"><?= e($r['status']) ?></span>
      <div class="hint"><?= e($r['email']) ?> · current tier <?= (int) $r['kyc_tier'] ?></div>
    </div>
    <dl class="kv">
      <dt>Date of birth</dt><dd><?= e($r['dob']) ?></dd>
      <dt>ID</dt><dd><?= e($r['id_type']) ?> ending <span class="mono"><?= e($r['id_last4']) ?></span></dd>
      <dt>Address</dt><dd><?= e($r['address']) ?></dd>
    </dl>
  </div>
  <?php if ($r['hits']): ?>
    <div class="flash error"><strong>Watch-list match:</strong>
      <?php foreach ($r['hits'] as $h): ?><?= e($h['name']) ?> (<?= e($h['list']) ?>, <?= e((string) $h['similarity']) ?>%) <?php endforeach; ?></div>
  <?php endif; ?>
  <?php if ($r['status'] === 'PENDING' && $user['role'] === 'supervisor'): ?>
    <form method="post" action="staff-kyc.php?id=<?= (int) $r['user_id'] ?>">
      <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
      <div class="row">
        <div><label for="tier<?= (int) $r['user_id'] ?>">Approve to</label>
          <select id="tier<?= (int) $r['user_id'] ?>" name="tier"><option value="2">Tier 2 - ID verified</option><option value="3">Tier 3 - enhanced due diligence</option></select></div>
        <div><label for="note<?= (int) $r['user_id'] ?>">Note</label><input id="note<?= (int) $r['user_id'] ?>" name="note" maxlength="300"></div>
      </div>
      <?php if ($r['hits']): ?><label><input type="checkbox" name="override" value="1"> I reviewed the watch-list match and it is a false positive</label><?php endif; ?>
      <button name="decision" value="approve" type="submit">Approve</button>
      <button name="decision" value="reject" type="submit" class="danger">Reject</button>
    </form>
  <?php elseif ($r['note']): ?><div class="hint">Note: <?= e($r['note']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card muted">No KYC submissions yet.</div><?php endif; ?>
