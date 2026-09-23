<?php
/**
 * Шаблон конфига для интеграции с RAPT Pill.
 *
 * Скопируйте этот файл рядом с api.php под именем config.local.php
 * и впишите свои данные. config.local.php никогда не должен попадать
 * в git/GitHub — он уже добавлен в .gitignore.
 *
 * rapt_username — email, которым вы вошли в app.rapt.io
 * rapt_secret    — НЕ пароль от аккаунта, а отдельный API-секрет.
 *                   Получить его: app.rapt.io → настройки аккаунта →
 *                   раздел API / Integrations → сгенерировать секрет.
 *                   Если такого раздела не видно в интерфейсе —
 *                   можно временно использовать обычный пароль от
 *                   аккаунта RAPT, но отдельный секрет безопаснее.
 */
return [
    'rapt_username' => 'your-email@example.com',
    'rapt_secret' => 'your-rapt-api-secret',
];
