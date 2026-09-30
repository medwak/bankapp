<?php
require __DIR__ . '/init.php';
use Bank\Web\StaffController;
dispatch(fn() => $_SERVER['REQUEST_METHOD'] === 'POST' ? StaffController::usersPost() : StaffController::users());
