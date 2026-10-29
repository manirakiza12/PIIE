<?php

return [
    'max_upload_kb' => (int) env('COURSE_CONTENT_MAX_UPLOAD_KB', 20480),
    'allowed_extensions' => [
        'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'csv',
        'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'webm', 'mov',
    ],
];
