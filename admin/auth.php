<?php
session_start();

/** 
 * Checkt of de admin is ingelogd
 *
 * @return bool True als de admin is ingelogd, anders false
 */
function isAdminLoggedIn(): bool
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/** 
 * Verplicht de admin in te loggen
 * 
 * @return redirects naar login.php als de admin niet is ingelogd
 */
function requireAdmin(): void
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
function redirectIfLoggedIn(): void
{
    if (isAdminLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Geeft het CSRF-token terug (genereert het indien nodig).
 *
 * @return string 64-teken hex token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valideert het CSRF-token uit $_POST.
 * Stopt de aanvraag met HTTP 403 als het token ontbreekt of onjuist is.
 */
function csrf_validate(): void
{
    if (!hash_equals(csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Ongeldige aanvraag (CSRF).');
    }
}