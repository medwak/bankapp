<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<div class="card narrow">
  <h1>Two-factor authentication</h1>
  <p>Enter the 6-digit code from your authenticator app.</p>
  <form method="post" action="login-2fa.php">
    <input type="hidden" name="_csrf" value="<?= e(Bank\Web\Http::csrf()) ?>">
    <label for="code">Code</label>
    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="16">
    <div class="hint">Lost your phone? Enter one of your recovery codes instead (each works once).</div>
    <button type="submit">Verify</button>
  </form>
  <p class="hint"><a href="login.php">Start over</a></p>
</div>
