<?php

use App\Core\Security;

$title = isset($title) && is_string($title) ? $title : 'TSG Academy LMS';
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$currentUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Security::escape($title) ?> · TSG Academy LMS</title>
    <link rel="stylesheet" href="<?= Security::escape($baseUrl) ?>/public/assets/css/app.css?v=<?= (string) filemtime(BASE_PATH . '/public/assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= Security::escape($baseUrl) ?>/public/assets/css/pages.css?v=<?= (string) filemtime(BASE_PATH . '/public/assets/css/pages.css') ?>">
    <script>document.documentElement.classList.add('js');</script>
    <script src="<?= Security::escape($baseUrl) ?>/public/assets/js/main.js?v=<?= (string) filemtime(BASE_PATH . '/public/assets/js/main.js') ?>" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= Security::escape($baseUrl) ?>/">
        <span class="brand-mark">TSG</span>
        <span>Tech SG <small>Academy</small></span>
    </a>
    <nav class="top-nav" aria-label="Main navigation">
        <a href="<?= Security::escape($baseUrl) ?>/courses">Courses</a>
        <a href="<?= Security::escape($baseUrl) ?>/about">About</a>
        <a href="<?= Security::escape($baseUrl) ?>/contact">Contact</a>
        <?php if ($currentUser !== []): ?>
            <a href="<?= Security::escape($baseUrl) ?>/dashboard">Dashboard</a>
        <?php else: ?>
            <a href="<?= Security::escape($baseUrl) ?>/login">Sign in</a>
            <a class="nav-cta" href="<?= Security::escape($baseUrl) ?>/register">Get started</a>
        <?php endif; ?>
    </nav>
    <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="mobile-nav" aria-label="Open menu">
        <span class="nav-toggle-bar" aria-hidden="true"></span>
        <span class="nav-toggle-bar" aria-hidden="true"></span>
        <span class="nav-toggle-bar" aria-hidden="true"></span>
    </button>
    <div class="mobile-nav" id="mobile-nav">
        <nav aria-label="Mobile navigation">
            <a href="<?= Security::escape($baseUrl) ?>/">Home</a>
            <a href="<?= Security::escape($baseUrl) ?>/courses">Courses</a>
            <a href="<?= Security::escape($baseUrl) ?>/about">About</a>
            <a href="<?= Security::escape($baseUrl) ?>/contact">Contact</a>
            <?php if ($currentUser !== []): ?>
                <a href="<?= Security::escape($baseUrl) ?>/dashboard">Dashboard</a>
            <?php else: ?>
                <a href="<?= Security::escape($baseUrl) ?>/login">Sign in</a>
                <a class="nav-cta" href="<?= Security::escape($baseUrl) ?>/register">Get started</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
