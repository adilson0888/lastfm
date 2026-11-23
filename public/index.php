<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Security.php';

Security::headers();
header('Location: music_list.html', true, 302);
exit;
