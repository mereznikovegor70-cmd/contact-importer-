<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($title ?? 'Контакты') ?> · Контакт</title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/responsive.css"><script src="assets/app.js" defer></script></head>
<body>
<aside class="sidebar">
 <a class="brand" href="index.php"><span class="brand-symbol">к</span>контакт<span class="brand-dot">.</span></a>
 <div class="workspace-label">РАБОЧЕЕ ПРОСТРАНСТВО</div>
 <nav><a class="<?= !str_contains($_GET['a'] ?? '', 'template') ? 'active' : '' ?>" href="index.php"><span class="nav-icon">▤</span>Базы контактов</a><a class="<?= str_contains($_GET['a'] ?? '', 'template') ? 'active' : '' ?>" href="<?= $e($url('templates')) ?>"><span class="nav-icon">▦</span>Шаблоны импорта</a></nav>
 <div class="sidebar-bottom"><span class="avatar">К</span><div>Менеджер контактов<small>Импорт и обработка баз</small></div></div>
</aside>
<div class="shell">
 <header class="topbar"><span>Рабочее пространство <span class="slash">/</span> <strong><?= $e($title ?? 'Контакты') ?></strong></span><?php if (!empty($_SESSION['authenticated'])): ?><form action="<?= $e($url('logout')) ?>" method="post"><?= self::csrf() ?><button class="text-button">Выйти ↗</button></form><?php endif ?></header>
 <main>
 <?php if (isset($_SESSION['notice'])): ?><div class="notice" role="status"><?= $e($_SESSION['notice']) ?></div><?php unset($_SESSION['notice']); endif ?>
 <?= $content ?>
 </main>
 <footer>Контакт <span>Импорт · Нормализация · Экспорт</span></footer>
</div>
</body></html>
