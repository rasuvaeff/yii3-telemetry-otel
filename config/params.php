<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-telemetry-otel' => [
        'service_name' => getenv('OTEL_SERVICE_NAME') ?: 'yii3-app',
        'endpoint' => getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4318',
        'batch' => true,
    ],
];
