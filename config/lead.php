<?php

return [
    'runtime_enabled' => env('LEAD_DB_ENABLED', false),
    'metrics_enabled' => env('LEAD_METRICS_DB_ENABLED', false),
    // Escolhido pelo operador somente após configurar o servidor próprio.
    'files_disk' => env('LEAD_FILES_DISK'),
];
