<?php

return [
    'version' => '202609060002_connector_authorization_channels',
    'description' => 'Add private Realtime channels for connector authorization completion',
    'statements' => [
        "ALTER TABLE connector_device_authorizations
         ADD COLUMN notification_channel CHAR(32) NULL AFTER platform,
         ADD UNIQUE INDEX uq_connector_authorizations_channel (notification_channel)",
    ],
];
