<?php
/**
 * Logout page for Legal Intake System
 * Handles secure logout and session cleanup
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../config/config.php';
require_once '../includes/auth.php';

$auth = new AuthSystem();

// Perform logout
$auth->logout();

// Redirect to login with logout message
header('Location: login.php?logged_out=1');
exit();
?>