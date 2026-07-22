<?php

$hook_array['after_save'][] = [
    100,
    'SpeedPhone: selbst angelegte Kontakte externer Mitarbeiter zuordnen',
    'custom/modules/Prospects/SpeedPhoneProspectHook.php',
    'SpeedPhoneProspectHook',
    'assignExternalCreator',
];
