<?php

return [
    // Financial year start month (4 = April). Budget heads belong to a year.
    'year_starts_month' => (int) env('FINANCE_YEAR_STARTS_MONTH', 4),

    'default_delivery_days' => (int) env('PO_DEFAULT_DELIVERY_DAYS', 30),

    // Printed on purchase orders. Placeholder: Purchase must supply the
    // institute's standard terms and conditions.
    'po_terms' => env('PO_TERMS', 'Supply as per the specifications and quantities above. Payment after receipt, inspection and acceptance of the goods, against a valid tax invoice. The institute\'s standard terms and conditions apply.'),
];
