<?php

use App\Core\Security;

$title = isset($title) && is_string($title) ? $title : 'TSG Academy LMS';
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$currentUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];

// Highlight the nav link matching the current page.
$appBasePath = rtrim(parse_url($baseUrl, PHP_URL_PATH) ?: '', '/');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($appBasePath !== '' && str_starts_with($requestPath, $appBasePath)) {
    $requestPath = '/' . ltrim(substr($requestPath, strlen($appBasePath)), '/');
}
$activePath = '/' . trim($requestPath, '/');
$navClass = static fn(string $path): string => $activePath === $path ? ' class="active"' : '';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Security::escape($title) ?> · TSG Academy LMS</title>
    <link rel="stylesheet" href="<?= Security::escape($baseUrl) ?>/public/assets/css/app.css?v=<?= (string) filemtime(BASE_PATH . '/public/assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= Security::escape($baseUrl) ?>/public/assets/css/pages.css?v=<?= (string) filemtime(BASE_PATH . '/public/assets/css/pages.css') ?>">
    <script>
        document.documentElement.classList.add('js');
    </script>
    <script src="<?= Security::escape($baseUrl) ?>/public/assets/js/main.js?v=<?= (string) filemtime(BASE_PATH . '/public/assets/js/main.js') ?>" defer></script>
</head>

<body>
    <div class="utility-bar">
        <div class="utility-inner">
            <span>An initiative of <a href="https://techsgstudio.com" target="_blank" rel="noopener noreferrer"><strong>Tech SG Studio (SMC-PVT.) LTD.</strong></a></span>
            <span class="utility-right"><a href="mailto:info@techsgstudio.com">info@techsgstudio.com</a><span>Mon to Sat, 10 AM to 6 PM</span></span>
        </div>
    </div>
    <header class="topbar">
        <a class="brand" href="<?= Security::escape($baseUrl) ?>/">
            <span class="brand-mark">TSG</span>
            <span>Tech SG <small>Academy</small></span>
        </a>
        <nav class="top-nav" aria-label="Main navigation">
            <a<?= $navClass('/') ?> href="<?= Security::escape($baseUrl) ?>/">Home</a>
            <a<?= $navClass('/courses') ?> href="<?= Security::escape($baseUrl) ?>/courses">Courses</a>
            <a<?= $navClass('/about') ?> href="<?= Security::escape($baseUrl) ?>/about">About</a>
            <a<?= $navClass('/contact') ?> href="<?= Security::escape($baseUrl) ?>/contact">Contact</a>
            <?php if ($currentUser !== []): ?>
                <a<?= $navClass('/dashboard') ?> href="<?= Security::escape($baseUrl) ?>/dashboard">Dashboard</a>
            <?php else: ?>
                <a href="<?= Security::escape($baseUrl) ?>/login">Sign in</a>
                <a class="nav-cta" href="<?= Security::escape($baseUrl) ?>/register">Register</a>
            <?php endif; ?>
        </nav>
        <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="mobile-nav" aria-label="Open menu">
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="nav-toggle-bar" aria-hidden="true"></span>
        </button>
        <div class="mobile-nav" id="mobile-nav">
            <nav aria-label="Mobile navigation">
                <a<?= $navClass('/') ?> href="<?= Security::escape($baseUrl) ?>/">Home</a>
                <a<?= $navClass('/courses') ?> href="<?= Security::escape($baseUrl) ?>/courses">Courses</a>
                <a<?= $navClass('/about') ?> href="<?= Security::escape($baseUrl) ?>/about">About</a>
                <a<?= $navClass('/contact') ?> href="<?= Security::escape($baseUrl) ?>/contact">Contact</a>
                <?php if ($currentUser !== []): ?>
                    <a<?= $navClass('/dashboard') ?> href="<?= Security::escape($baseUrl) ?>/dashboard">Dashboard</a>
                <?php else: ?>
                    <a href="<?= Security::escape($baseUrl) ?>/login">Sign in</a>
                    <a class="nav-cta" href="<?= Security::escape($baseUrl) ?>/register">Register</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <main class="container">