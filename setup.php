<?php
require __DIR__ . '/init.php';
use Bank\Web\SetupController;
dispatch(fn() => $_SERVER['REQUEST_METHOD'] === 'POST' ? SetupController::submit() : SetupController::form());
