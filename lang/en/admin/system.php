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

    'tabs' => [
        'status' => 'Status',
        'commands' => 'Commands',
        'errors' => 'Errors',
    ],
    'services' => [
        'scheduler' => 'Scheduler',
        'queue' => 'Queue worker',
        'reverb' => 'Reverb (live updates)',
    ],
    'states' => [
        'running' => 'Running',
        'stopped' => 'Stopped',
        'starting' => 'Starting / busy',
        'unknown' => 'Unknown on this machine',
    ],
    'last_beat' => 'Last run :time',
    'queue' => [
        'title' => 'Background jobs',
        'summary' => ':pending waiting · :failed failed',
        'oldest' => 'Oldest waiting since :time',
    ],
    'backups' => [
        'title' => 'Backups',
        'fresh' => 'A good backup exists from the last 24 hours.',
        'stale' => 'The last good backup is more than a day old — check the scheduler and the failed backups below.',
        'none' => 'No backup yet. The first one runs tonight at 23:55, or run one now.',
        'when' => 'When',
        'database' => 'Database',
        'files' => 'Files',
        'ok' => 'OK',
        'failed' => 'Failed',
        'hint' => 'Every night at 23:55 Cairo time; the last 7 days are kept on this server. A copy on the same server does not protect against losing the server.',
    ],
    'disk' => [
        'title' => 'Disk space',
        'summary' => ':free free of :total (:percent% used)',
    ],
    'failed' => [
        'title' => 'Failed jobs',
        'none' => 'No failed jobs.',
        'job' => 'Job',
        'why' => 'Why it failed',
        'when' => 'When',
        'retry' => 'Retry',
        'forget' => 'Delete',
    ],
    'errors' => [
        'title' => 'Latest errors and warnings',
        'none' => 'No errors or warnings in the latest log.',
        'refresh' => 'Refresh',
        'hint' => 'From the newest log file, newest first. Phone numbers, IDs, emails and tokens are hidden.',
    ],

    'confirm' => [
        'backup' => 'Take a backup of the database and uploaded files now? It can take a minute.',
        'queue_retry_all' => 'Put every failed job back on the queue?',
        'forget' => 'Delete this failed job for good? It will not run again.',
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
        'backup' => [
            'label' => 'Back up now',
            'hint' => 'Copies the database and uploaded files into a dated backup on this server. Reads only.',
        ],
        'queue_retry_all' => [
            'label' => 'Retry all failed jobs',
            'hint' => 'Puts every failed background job back on the queue.',
        ],
    ],
];
