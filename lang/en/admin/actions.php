<?php

// User detail · More actions (D14, D16–D18, D22).
return [
    'more' => 'More actions',
    'reason' => 'Reason (kept in the audit log)',
    'escalate_note' => 'What the Admin needs to know',
    'amount_egp' => 'Amount (EGP)',
    'nothing_changed' => 'Nothing was changed.',
    'nothing_owed' => 'Nothing is owed on that side.',
    'not_locked' => 'Sign-in is not locked for this number.',
    'escalated_note' => 'Escalated to an Admin: :note',
    'locked_banner' => 'Sign-in is locked for this number after too many wrong codes.',
    'ban_confirm' => 'Ban :name permanently? They will see the restricted screen and cannot appeal in the app.',
    'titles' => [
        'correct' => 'Correct details',
        'write_off' => 'Write off debt',
        'credit' => 'Add a credit',
        'unlock' => 'Unlock sign-in',
        'escalate' => 'Escalate to an Admin',
    ],
    'hints' => [
        'correct' => 'Fix what the member entered. A woman changed to another gender has her women-only commutes paused for review (COM-45).',
        'write_off' => 'Forgives everything owed on the side you choose, as one ledger line. It cannot be undone except by a new charge.',
        'credit' => 'Money off what they owe, kept for next time if it is more. Never paid out.',
        'unlock' => 'Lifts the wrong-code lock on their number now, instead of at its expiry.',
        'escalate' => 'Opens a high-priority support case for an Admin and keeps your note on the member.',
    ],
    'confirm' => [
        'correct' => 'Save the correction',
        'write_off' => 'Write it off',
        'credit' => 'Add the credit',
        'unlock' => 'Unlock now',
        'escalate' => 'Escalate',
    ],
    'done' => [
        'correct' => 'Details corrected.',
        'write_off' => 'EGP :amount written off.',
        'credit' => 'Credit added.',
        'unlock' => 'Sign-in unlocked.',
        'escalate' => 'Escalated. It is on the Support cases page for an Admin.',
    ],
];
