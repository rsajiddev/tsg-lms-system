<?php use App\Core\Security; $title = $title ?? 'TSG Academy LMS'; ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Security::escape($title) ?> · TSG Academy LMS</title>
<link rel="stylesheet" href="<?= Security::escape((require BASE_PATH . '/config/app.php')['url']) ?>/public/assets/css/app.css">
</head><body><header class="topbar"><a class="brand" href="<?= Security::escape((require BASE_PATH . '/config/app.php')['url']) ?>/"><span class="brand-mark">TSG</span><span>Academy <small>LMS</small></span></a><nav class="top-nav"><?php if (!empty($_SESSION['user'])): ?><a href="<?= Security::escape(rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/dashboard') ?>">Dashboard</a><?php else: ?><a href="<?= Security::escape(rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/login') ?>">Sign in</a><a href="<?= Security::escape(rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/register') ?>">Register</a><?php endif; ?></nav></header><main class="container">
