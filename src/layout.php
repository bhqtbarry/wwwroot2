<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/i18n.php';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="<?php echo h($_SESSION['locale'] ?? detect_locale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h(t('site_title')); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="/index.php">SyPhotos</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="/index.php"><?php echo h(t('nav_home')); ?></a></li>
                <li class="nav-item"><a class="nav-link" href="/upload.php"><?php echo h(t('nav_upload')); ?></a></li>
                <?php if ($user && $user['role'] === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="/admin/review.php"><?php echo h(t('nav_review')); ?></a></li>
                <?php endif; ?>
            </ul>
            <form class="d-flex" method="get" action="/search.php">
                <input class="form-control me-2" type="search" name="q" placeholder="<?php echo h(t('search_placeholder')); ?>">
                <button class="btn btn-outline-light" type="submit">Go</button>
            </form>
            <ul class="navbar-nav ms-3">
                <?php if ($user): ?>
                    <li class="nav-item"><a class="nav-link" href="/profile.php"><?php echo h(t('nav_profile')); ?></a></li>
                    <li class="nav-item">
                        <form method="post" action="/logout.php">
                            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                            <button class="btn btn-link nav-link" type="submit"><?php echo h(t('logout')); ?></button>
                        </form>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="/login.php"><?php echo h(t('nav_login')); ?></a></li>
                    <li class="nav-item"><a class="nav-link" href="/register.php"><?php echo h(t('nav_register')); ?></a></li>
                <?php endif; ?>
            </ul>
            <form class="ms-2" method="post" action="/set-locale.php">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                <select class="form-select form-select-sm" name="locale" onchange="this.form.submit()">
                    <?php foreach (['zh','en','fr','es','pt','de','it','ru','ko','ja','th','id','vi','hi'] as $code): ?>
                        <option value="<?php echo h($code); ?>" <?php echo ($_SESSION['locale'] ?? detect_locale()) === $code ? 'selected' : ''; ?>><?php echo strtoupper($code); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
</nav>
<main class="container my-4">
