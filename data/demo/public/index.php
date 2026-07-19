<?php
$phpVersion = PHP_VERSION;
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'PHP-FPM';
$time = date('Y-m-d H:i:s');
$extensions = ['pdo_mysql', 'redis', 'swoole', 'mysqli', 'zip'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Docker Develop Demo</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7f9; color: #111827; }
        main { max-width: 920px; margin: 0 auto; padding: 56px 20px; }
        .panel { background: #fff; border: 1px solid #dedede; padding: 28px; }
        h1 { margin: 0 0 10px; font-size: 28px; letter-spacing: 0; }
        p { margin: 0; color: #4b5563; line-height: 1.7; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-top: 24px; }
        .item { border: 1px solid #e5e7eb; padding: 14px; background: #fafafa; }
        .label { display: block; color: #6b7280; font-size: 12px; font-weight: 700; margin-bottom: 6px; }
        .value { font-size: 15px; font-weight: 700; word-break: break-word; }
        .extensions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 24px; }
        .chip { border: 1px solid #d1d5db; background: #fff; padding: 6px 10px; font-size: 13px; }
        .ok { color: #047857; }
        .missing { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Docker Develop Demo</h1>
        <p>如果你能看到这个页面，说明 Nginx、PHP-FPM 和项目路径映射已经跑通。</p>
        <div class="grid">
            <div class="item"><span class="label">PHP</span><span class="value"><?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="item"><span class="label">Server</span><span class="value"><?= htmlspecialchars($serverSoftware, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="item"><span class="label">Path</span><span class="value"><?= htmlspecialchars(__DIR__, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="item"><span class="label">Time</span><span class="value"><?= htmlspecialchars($time, ENT_QUOTES, 'UTF-8') ?></span></div>
        </div>
        <div class="extensions">
            <?php foreach ($extensions as $extension): ?>
                <?php $loaded = extension_loaded($extension); ?>
                <span class="chip <?= $loaded ? 'ok' : 'missing' ?>"><?= htmlspecialchars($extension, ENT_QUOTES, 'UTF-8') ?> <?= $loaded ? 'OK' : 'missing' ?></span>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>