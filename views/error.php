<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>
<div class="card narrow">
  <h1><?= e($title) ?></h1>
  <p><?= e($message) ?></p>
  <p><a class="btn secondary" href="index.php">Back to start</a></p>
</div>
