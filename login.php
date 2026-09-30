<?php
require __DIR__ . '/init.php';
use Bank\Web\AuthController;
dispatch(fn() => $_SERVER['REQUEST_METHOD'] === 'POST' ? AuthController::login() : AuthController::loginForm());
