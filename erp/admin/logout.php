<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

unset($_SESSION['admin_user']);
session_destroy();
header('Location: login.php');
exit;
