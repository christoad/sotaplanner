<?php
require_once 'config.php';
session_start();
requireLogin();
header('Location: admin_mode.php?tab=data');
exit;
