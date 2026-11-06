<?php
use App\Core\Security;
$baseUrl=rtrim((require BASE_PATH.'/config/app.php')['url'],'/'); $e=static fn($v)=>Security::escape((string)$v);
$isAdmin = (($_SESSION['user']['role'] ?? '') === 'admin');
$parentPath = $isAdmin ? '/admin/activity' : '/notifications';
$parentLabel = $isAdmin ? 'Recent activity' : 'Notifications';
?>
<section class="dashboard-page notification-read-page">
 <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><div class="dashboard-breadcrumb"><a href="<?= $e($baseUrl) ?>/dashboard">Workspace</a><i>/</i><a href="<?= $e($baseUrl . $parentPath) ?>"><?= $e($parentLabel) ?></a><i>/</i><strong>Update</strong></div></div>
 <header class="dashboard-heading"><div><p class="eyebrow">ACADEMY UPDATE</p><h1><?= $e($notification['title']??'') ?></h1><p>Published <?= $e(date('M j, Y · g:i A',strtotime((string)($notification['published_at']??'')))) ?> by <?= $e($notification['author_name']??'The Academy') ?></p></div></header>
 <article class="dashboard-panel notification-read-card"><p class="eyebrow"><?= $e(ucfirst((string)($notification['category']??'announcement'))) ?></p><div class="notification-read-body"><?= nl2br($e($notification['body']??'')) ?></div></article>
</section>
