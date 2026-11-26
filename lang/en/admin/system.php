<?php

// System page: maintenance commands run from the dashboard (super admin only).
return [
    'title' => 'System',
    'sub' => 'Maintenance commands for this server (:env · :url). A fixed list — not a shell.',
    'quick' => 'Quick actions',
    'all' => 'All commands',
    'command' => 'Command',
    'what' => 'What it does',
    'run' => 'Run',
    'running' => 'Running…',
    'output' => 'Output',
    'empty' => 'Run a command and its output shows here.',
    'no_output' => '(no output)',
    'truncated' => 'output cut short',
    'clear_screen' => 'Clear screen',
    'audited' => 'Every run is written to the audit log with your name, the command and its exit code.',

    'confirm' => [
        'migrate' => 'Run the database migrations on this server now?',
        'optimize_clear' => 'Clear every cache on this server? The app keeps working, a little slower until the caches are rebuilt (Rebuild caches). GPS points from the last 30 seconds that are not saved yet are lost.',
    ],

    'commands' => [
        'migrate' => [
            'label' => 'Run migrations',
            'hint' => 'Applies any database changes that have not run yet. Every deploy already does this.',
        ],
        'migrate_status' => [
            'label' => 'Migration status',
            'hint' => 'Lists every migration and whether it has run. Changes nothing.',
        ],
        'optimize_clear' => [
            'label' => 'Clear cache',
            'hint' => 'Clears config, route, view, event and application caches (optimize:clear).',
        ],
        'optimize' => [
            'label' => 'Rebuild caches',
            'hint' => 'Caches config, routes, views and events again, so requests are fast.',
        ],
        'queue_restart' => [
            'label' => 'Restart queue worker',
            'hint' => 'The worker finishes its current job and starts again with the latest code and settings.',
        ],
        'reverb_restart' => [
            'label' => 'Restart Reverb',
            'hint' => 'Restarts the WebSocket server (live trip positions). Apps reconnect by themselves.',
        ],
        'doctor' => [
            'label' => 'Health check',
            'hint' => 'rafeeq:doctor — database, migrations, storage and broadcasting, each checked. Changes nothing.',
        ],
        'schedule_list' => [
            'label' => 'Scheduled tasks',
            'hint' => 'Every scheduled command and when it next runs. Changes nothing.',
        ],
        'about' => [
            'label' => 'About',
            'hint' => 'Laravel and PHP versions, environment, drivers and what is cached. Changes nothing.',
        ],
    ],
];
