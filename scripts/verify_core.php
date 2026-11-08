<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/print_field_definitions.php';
require_once $root . '/includes/printing.php';
require_once $root . '/includes/appointment_follow_up.php';
require_once $root . '/includes/sms.php';

$errors = [];

foreach (['birth', 'death', 'marriage'] as $type) {
    $fields = printFillEditorFields($type, ['record_type' => $type]);
    if ($fields === []) {
        $errors[] = "printFillEditorFields returned no fields for {$type}";
    }
}

$mapped = prepareCivilRecordFormInput([
    'record_type' => 'birth',
    'print_fill' => [
        'child_first_name' => 'Ana',
        'child_last_name' => 'Cruz',
    ],
]);
if (($mapped['first_name'] ?? '') !== 'Ana' || ($mapped['last_name'] ?? '') !== 'Cruz') {
    $errors[] = 'prepareCivilRecordFormInput failed to map child names';
}

foreach ([
    'notifyFollowUpReminderEmail',
    'notifyFollowUpDayOfReminderEmail',
    'notifyFollowUpReminderSms',
    'notifyFollowUpDayOfReminderSms',
    'sendCitizenEmail',
    'sendCitizenSms',
] as $fn) {
    if (!function_exists($fn)) {
        $errors[] = "Missing function: {$fn}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "OK: core print/civil helpers verified\n";
