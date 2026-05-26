<?php
session_start();

/** 
 * Checkt of de admin is ingelogd
 *
 * @return bool True als de admin is ingelogd, anders false
 */
function isAdminLoggedIn()
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/** 
 * Verplicht de admin in te loggen
 * 
 * @return redirects naar login.php als de admin niet is ingelogd
 */
function requireAdmin()
{
    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/** 
 * Redirects de admin als hij is ingelogd
 * 
 * @return redirects naar index.php als de admin is ingelogd
 */
function redirectIfLoggedIn()
{
    if (isAdminLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}