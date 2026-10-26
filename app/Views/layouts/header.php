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
$dashboardArea = $currentUser !== [] && ($activePath === '/dashboard' || str_starts_with($activePath, '/admin/') || str_starts_with($activePath, '/learning') || str_starts_with($activePath, '/student') || str_starts_with($activePath, '/payments') || str_starts_with($activePath, '/notifications'));
$dashboardRole = (string)($currentUser['role'] ?? 'student');
$dashboardNavIcon = static function (string $name): string {
    $contents = match ($name) {
        'overview' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="11" width="7" height="10" rx="1.5"/><rect x="3" y="13" width="7" height="8" rx="1.5"/>',
        'book' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21z"/><path d="M4 5.5v13A2.5 2.5 0 0 1 6.5 16H20M8 7h8M8 11h7"/>',
        'grid' => '<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/>',
        'card' => '<rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><path d="M3 9h18M7 15h4"/>',
        'quiz' => '<path d="M8 3h8l4 4v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M15 3v5h5M8 13l2.2 2.2L15.5 10"/>',
        'assignment' => '<path d="M8 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="m8 13 2.5 2.5L16 10"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM20 8v6M23 11h-6"/>',
        'megaphone' => '<path d="m3 11 18-6v14L3 13zM5 14l2 7h4l-2-8M21 9a3 3 0 0 1 0 6"/>',
        'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/>',
        'settings' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M2 14h4M10 8h4M18 16h4"/>',
        'logout' => '<path d="M10 17l5-5-5-5M15 12H3M21 19V5a2 2 0 0 0-2-2h-5"/>',
        default => '<circle cx="12" cy="12" r="9"/>',
    };
    return '<svg class="dashboard-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $contents . '</svg>';
};
$dashboardNavItems = $dashboardRole === 'admin'
    ? [
        ['number' => '01', 'label' => 'Overview', 'href' => '/dashboard', 'icon' => 'overview', 'active' => $activePath === '/dashboard'],
        ['number' => '02', 'label' => 'Manage courses', 'href' => '/admin/courses', 'icon' => 'book', 'active' => $activePath === '/admin/courses'],
        ['number' => '03', 'label' => 'Quiz management', 'href' => '/admin/quizzes', 'icon' => 'quiz', 'active' => $activePath === '/admin/quizzes'],
        ['number' => '04', 'label' => 'Assignments', 'href' => '/admin/assignments', 'icon' => 'assignment', 'active' => $activePath === '/admin/assignments'],
        ['number' => '05', 'label' => 'Students', 'href' => '/admin/students', 'icon' => 'users', 'active' => $activePath === '/admin/students'],
        ['number' => '06', 'label' => 'Announcements', 'href' => '/admin/announcements', 'icon' => 'megaphone', 'active' => str_starts_with($activePath, '/admin/announcements')],
        ['number' => '07', 'label' => 'Payment review', 'href' => '/admin/payments', 'icon' => 'card', 'active' => $activePath === '/admin/payments'],
        ['number' => '08', 'label' => 'Review moderation', 'href' => '/admin/reviews', 'icon' => 'star', 'active' => $activePath === '/admin/reviews'],
        ['number' => '09', 'label' => 'Security settings', 'href' => '/admin/settings', 'icon' => 'settings', 'active' => $activePath === '/admin/settings'],
    ]
    : [
        ['number' => '01', 'label' => 'Overview', 'href' => '/dashboard', 'icon' => 'overview', 'active' => $activePath === '/dashboard'],
        ['number' => '02', 'label' => 'My courses', 'href' => '/student/courses', 'icon' => 'book', 'active' => in_array($activePath, ['/student/courses', '/learning', '/learning/lesson'], true)],
        ['number' => '03', 'label' => 'Course catalogue', 'href' => '/courses', 'icon' => 'grid', 'active' => $activePath === '/courses'],
        ['number' => '04', 'label' => 'Payments', 'href' => '/student/payments', 'icon' => 'card', 'active' => $activePath === '/student/payments' || str_starts_with($activePath, '/payments')],
        ['number' => '05', 'label' => 'Quizzes & results', 'href' => '/student/quizzes', 'icon' => 'quiz', 'active' => $activePath === '/student/quizzes' || str_starts_with($activePath, '/learning/quizzes')],
        ['number' => '06', 'label' => 'Assignments', 'href' => '/student/assignments', 'icon' => 'assignment', 'active' => $activePath === '/student/assignments' || str_starts_with($activePath, '/learning/assignments')],
        ['number' => '07', 'label' => 'Notifications', 'href' => '/notifications', 'icon' => 'bell', 'active' => str_starts_with($activePath, '/notifications')],
    ];
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

<body<?= $dashboardArea ? ' class="dashboard-shell"' : '' ?>>
    <?php if ($dashboardArea): ?>
        <aside class="dashboard-sidebar" id="dashboard-sidebar" aria-label="Dashboard navigation">
            <div class="dashboard-sidebar-head">
                <a class="dashboard-sidebar-brand" href="<?= Security::escape($baseUrl) ?>/dashboard"><span class="brand-mark">TSG</span><span>Tech SG<small><?= $dashboardRole === 'admin' ? 'ADMIN' : 'ACADEMY' ?></small></span></a>
                <span class="dashboard-nav-caption">WORKSPACE</span>
            </div>
            <nav class="dashboard-side-nav" aria-label="Dashboard sections">
                <?php foreach ($dashboardNavItems as $item): ?>
                    <a<?= $item['active'] ? ' class="active" aria-current="page"' : '' ?> href="<?= Security::escape($baseUrl . $item['href']) ?>"><span class="dashboard-nav-number"><?= Security::escape($item['number']) ?></span><?= $dashboardNavIcon($item['icon']) ?><span class="dashboard-nav-label"><?= Security::escape($item['label']) ?></span></a>
                <?php endforeach; ?>
                <form class="dashboard-mobile-logout" method="post" action="<?= Security::escape($baseUrl) ?>/logout">
                    <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
                    <button type="submit" aria-label="Log out" title="Log out"><?= $dashboardNavIcon('logout') ?><span>Log out</span></button>
                </form>
            </nav>
            <div class="dashboard-sidebar-bottom">
                <div class="dashboard-logout-card"><span class="dashboard-logout-mark">TSG</span><strong>Ready to sign out?</strong><p>Your progress is saved to your account.</p><form method="post" action="<?= Security::escape($baseUrl) ?>/logout"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><button type="submit">Log out <span aria-hidden="true">→</span></button></form></div>
            </div>
        </aside>
        <button class="dashboard-sidebar-scrim" type="button" data-dashboard-close aria-label="Close dashboard menu"></button>
    <?php endif; ?>
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
    <main class="container<?= $dashboardArea ? ' dashboard-container' : '' ?>">
