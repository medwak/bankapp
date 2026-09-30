<?php
require __DIR__ . '/init.php';
use Bank\Web\CustomerController;
dispatch(fn() => $_SERVER['REQUEST_METHOD'] === 'POST' ? CustomerController::securityPost() : CustomerController::security());
