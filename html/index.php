<?php
/**
 * Main entry point for Legal Intake System
 * Handles routing and authentication
 */

// Define application constant
define('LEGAL_INTAKE_SYSTEM', true);

// Start session
session_start();

// Load configuration
require_once 'config/config.php';

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['user_role']);

// If not logged in, redirect to login
if (!$is_logged_in) {
    header('Location: pages/login.php');
    exit();
}

// If logged in, redirect to dashboard
header('Location: pages/dashboard.php');
exit();
?>