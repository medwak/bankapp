<?php
require __DIR__ . '/init.php';
dispatch(fn() => Bank\Web\AuthController::home());
