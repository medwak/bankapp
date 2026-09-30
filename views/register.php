<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<div class="card narrow">
  <h1>Open an account</h1>
  <form method="post" action="register.php" autocomplete="on">
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <label for="full_name">Full name</label>
    <input id="full_name" name="full_name" autocomplete="name" required maxlength="80" value="<?= e($old['full_name'] ?? '') ?>">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($old['email'] ?? '') ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
    <div class="hint">At least 12 characters. A few random words makes a strong passphrase.</div>
    <label for="password2">Repeat password</label>
    <input id="password2" name="password2" type="password" autocomplete="new-password" required minlength="12">
    <button type="submit">Create account</button>
  </form>
  <p class="hint">We will email you a link to confirm your address before you can sign in.</p>
  <p class="hint">Already registered? <a href="login.php">Sign in</a></p>
</div>
