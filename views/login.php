<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<div class="card narrow">
  <h1>Sign in</h1>
  <form method="post" action="login.php" autocomplete="on">
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required value="<?= e($old['email'] ?? '') ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
  </form>
  <p class="hint">New here? <a href="register.php">Open an account</a></p>
</div>
