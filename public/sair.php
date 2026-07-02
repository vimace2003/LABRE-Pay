<?php
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';

auth_logout();
redirect('index.php');
