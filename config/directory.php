<?php

/*
 | Institutional directory (OpenLDAP). Every value that depends on how the
 | institute's directory is organised is configuration, not code: see
 | docs/ASSUMPTIONS_AND_DECISIONS.md (A-01 .. A-04).
 */

return [

    'enabled' => (bool) env('LDAP_ENABLED', true),

    'connection' => [
        'hosts' => array_filter(explode(',', (string) env('LDAP_HOSTS', ''))),
        'port' => (int) env('LDAP_PORT', 389),
        'base_dn' => env('LDAP_BASE_DN', ''),
        // Read-only service account used to look users up and to sync.
        'username' => env('LDAP_USERNAME'),
        'password' => env('LDAP_PASSWORD'),
        'use_ssl' => (bool) env('LDAP_SSL', false),   // LDAPS (636)
        'use_tls' => (bool) env('LDAP_TLS', true),    // StartTLS
        'ca_cert' => env('LDAP_CA_CERT'),             // CA bundle the server certificate must chain to
        'timeout' => (int) env('LDAP_TIMEOUT', 5),
    ],

    'people_dn' => env('LDAP_PEOPLE_DN', env('LDAP_BASE_DN', '')),
    'login_attribute' => env('LDAP_LOGIN_ATTRIBUTE', 'uid'),
    'user_filter' => env('LDAP_USER_FILTER', '(objectClass=inetOrgPerson)'),

    // Login names accepted at all; anything else is rejected before it can
    // reach a filter (escaping is still applied).
    'username_pattern' => '/^[a-z0-9][a-z0-9._-]{0,63}$/',

    // Local field => directory attribute. Missing attributes become null.
    'attributes' => [
        'name' => env('LDAP_ATTR_NAME', 'cn'),
        'email' => env('LDAP_ATTR_EMAIL', 'mail'),
        'phone' => env('LDAP_ATTR_PHONE', 'telephoneNumber'),
        'employee_id' => env('LDAP_ATTR_EMPLOYEE_ID', 'employeeNumber'),
        'department_code' => env('LDAP_ATTR_DEPARTMENT', 'departmentNumber'),
        'designation' => env('LDAP_ATTR_DESIGNATION', 'title'),
        'category' => env('LDAP_ATTR_CATEGORY', 'employeeType'),
    ],

    // Directory value of the category attribute => local user category.
    // Unmapped values fall back to `default_category`.
    'category_map' => [
        'faculty' => 'faculty',
        'staff' => 'staff',
        'student' => 'student',
    ],
    'default_category' => env('DIRECTORY_DEFAULT_CATEGORY', 'staff'),

    // How the directory marks an account as disabled (A-04).
    'disabled' => [
        'attribute' => env('LDAP_DISABLED_ATTRIBUTE', 'description'),
        'values' => array_filter(explode(',', (string) env('LDAP_DISABLED_VALUES', 'account-disabled'))),
    ],

    // Base role granted to new users of each category. Never an admin role.
    'default_roles' => [
        'faculty' => ['employee'],
        'staff' => ['employee'],
        'student' => ['student'],
    ],
];
