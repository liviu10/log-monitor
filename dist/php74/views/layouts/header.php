<!DOCTYPE html>
<html lang="<?= getLang() ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-eval' 'nonce-<?= APP_NONCE ?>'; style-src 'self' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net;">

    <title><?= APP_NAME ?> - <?= __('Centralized Logs') ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css">
    
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="assets/app.css?v=<?= time() ?>">
    
    <script nonce="<?= APP_NONCE ?>">
        <?php
            $lang = getLang();
            $langFile = __DIR__ . sprintf('/../../lang/%s.json', $lang);
            $translations = file_exists($langFile) ? json_decode(file_get_contents($langFile), true) : [];
        ?>
        
        window.Translations = <?= json_encode($translations) ?>;
        window.__ = function(key, replacements = {}) {
            let text = window.Translations[key] || key;
            for (let placeholder in replacements) {
                text = text.replace(':' + placeholder, replacements[placeholder]);
            }
            return text;
        };
    </script>
</head>
<body>