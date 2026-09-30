<?php
require __DIR__ . '/init.php';
dispatch(fn() => Bank\Web\StaffController::journal());
