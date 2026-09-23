<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($title); ?> — ARCHI</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #faf9f6;
            color: #16150f;
            font-family: Inter, "Segoe UI", system-ui, sans-serif;
        }
        .card { max-width: 460px; text-align: center; }
        .brand {
            font-weight: 700;
            letter-spacing: .28em;
            font-size: 13px;
            color: #75705f;
            margin-bottom: 32px;
        }
        .code {
            font-size: 64px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -.03em;
            color: #c07a05;
            font-variant-numeric: tabular-nums;
        }
        h1 { font-size: 21px; font-weight: 600; margin: 16px 0 8px; }
        p { color: #45423a; line-height: 1.6; margin: 0 0 28px; font-size: 15px; }
        a {
            display: inline-block;
            padding: 10px 22px;
            background: #16150f;
            color: #faf9f6;
            text-decoration: none;
            border-radius: 3px;
            font-size: 14px;
            font-weight: 500;
        }
        a:focus-visible { outline: 2px solid #c07a05; outline-offset: 3px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">ARCHI</div>
        <div class="code"><?php echo $__env->yieldContent('code'); ?></div>
        <h1><?php echo $__env->yieldContent('heading'); ?></h1>
        <p><?php echo $__env->yieldContent('message'); ?></p>
        <a href="<?php echo e(url('/')); ?>">Ana səhifəyə qayıt</a>
    </div>
</body>
</html>
<?php /**PATH C:\Users\User\Herd\ArchiCRM\resources\views/errors/layout.blade.php ENDPATH**/ ?>