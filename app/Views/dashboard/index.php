<?php $user = isset($user) && is_array($user) ? $user : ['role' => 'student', 'name' => 'Student']; ?>
<section class="hero">
  <p class="eyebrow"><?= App\Core\Security::escape(strtoupper($user['role'])) ?> DASHBOARD</p>
  <h1>Welcome, <?= App\Core\Security::escape($user['name']) ?></h1>
  <p class="lead">Your authenticated account is ready. Course and learning tools will appear here as their protected modules are completed.</p>
  <form method="post" action="<?= App\Core\Security::escape(rtrim((require BASE_PATH . '/config/app.php')['url'], '/')) ?>/logout">
    <input type="hidden" name="_csrf" value="<?= App\Core\Security::escape(App\Core\Security::csrfToken()) ?>">
    <button class="button" type="submit">Sign out</button>
  </form>
</section>
