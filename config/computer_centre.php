<?php

/*
 | Computer Centre integrations (docs/INTEGRATIONS.md). Every driver
 | defaults to "none": the workflow step then becomes a manual task for the
 | Computer Centre instead of pretending the account was created.
 */

return [
    'email' => [
        'driver' => env('CC_MAIL_DRIVER', 'none'),      // none (no adapter yet: depends on the institute's mail platform)
        'domain' => env('CC_MAIL_DOMAIN', 'institute.test'),
    ],

    // Wi-Fi / VPN access is granted by LDAP group membership, which
    // FreeRADIUS (or the VPN concentrator) checks at authentication time.
    'network' => [
        'driver' => env('CC_NETWORK_DRIVER', 'none'),   // ldap | none
        'groups' => [
            'wifi' => env('CC_WIFI_GROUP_DN'),
            'vpn' => env('CC_VPN_GROUP_DN'),
        ],
        // A directory account allowed to modify those groups (never the read-only bind account).
        'bind_dn' => env('CC_LDAP_PROVISION_DN'),
        'bind_password' => env('CC_LDAP_PROVISION_PASSWORD'),
    ],

    'vm' => [
        'driver' => env('CC_VM_DRIVER', 'none'),        // proxmox | none
        'proxmox' => [
            'url' => env('PROXMOX_URL'),                // https://pve.example:8006
            'token_id' => env('PROXMOX_TOKEN_ID'),      // user@realm!token
            'token_secret' => env('PROXMOX_TOKEN_SECRET'),
            'node' => env('PROXMOX_NODE'),
            'templates' => array_filter([               // OS => template VMID to clone
                'ubuntu-24.04' => env('PROXMOX_TEMPLATE_UBUNTU'),
                'debian-12' => env('PROXMOX_TEMPLATE_DEBIAN'),
            ]),
            'ca_bundle' => env('PROXMOX_CA_BUNDLE'),
            'timeout' => (int) env('PROXMOX_TIMEOUT', 30),
        ],
        // Per-request ceilings by requester category. Placeholders: the Computer
        // Centre must confirm (docs/ASSUMPTIONS_AND_DECISIONS.md).
        'quotas' => [
            'faculty' => ['cpu' => 8, 'ram_gb' => 32, 'disk_gb' => 500],
            'staff' => ['cpu' => 4, 'ram_gb' => 16, 'disk_gb' => 200],
            'student' => ['cpu' => 2, 'ram_gb' => 4, 'disk_gb' => 50],
        ],
    ],

    'asset_categories' => ['laptop', 'desktop', 'monitor', 'printer', 'scanner', 'projector', 'network', 'server', 'storage', 'peripheral', 'other'],
];
