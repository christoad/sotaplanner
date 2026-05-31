<?php
require_once 'config.php';
session_start();
requireLogin();
header('Location: god_mode.php?tab=data');
exit;
