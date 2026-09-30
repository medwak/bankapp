<?php
require __DIR__ . '/init.php';
dispatch(fn() => Bank\Web\CustomerController::account($_GET['id'] ?? ''));
