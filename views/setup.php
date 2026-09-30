<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<div class="card narrow">
  <h1>Set up the bank</h1>
  <p class="hint">No database has been installed yet. This runs once: it creates the chart of accounts,
     opens the first business day, posts opening capital, and creates the first administrator
     (supervisor) login for you to sign in with.</p>
  <form method="post" action="setup.php" autocomplete="on">
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <label for="full_name">Administrator full name</label>
    <input id="full_name" name="full_name" autocomplete="name" required maxlength="80" value="<?= e($old['full_name'] ?? '') ?>">
    <label for="email">Administrator email</label>
    <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($old['email'] ?? '') ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
    <div class="hint">At least 12 characters. A few random words makes a strong passphrase.</div>
    <label for="password2">Repeat password</label>
    <input id="password2" name="password2" type="password" autocomplete="new-password" required minlength="12">
    <label class="row"><input type="checkbox" name="demo" value="1" <?= !empty($old['demo']) ? 'checked' : '' ?>> Also create demo staff and customer logins with sample balances</label>
    <button type="submit">Install</button>
  </form>
</div>
