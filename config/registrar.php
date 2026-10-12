<?php

/*
 | Registrar settings that are institutional policy. Every value here is a
 | placeholder to be confirmed (docs/ASSUMPTIONS_AND_DECISIONS.md).
 */

return [
    // LTC: blocks and how many journeys each allows (placeholders; confirm
    // with the applicable LTC rules before use).
    'ltc' => [
        'blocks' => ['2026-2029' => ['2026-01-01', '2029-12-31']],
        'per_block' => (int) env('LTC_PER_BLOCK', 1),
    ],

    // Bills may be dated this many days before/after the journey.
    'claim_bill_margin_days' => 1,

    // RTI Act 2005 time limits. VERIFY against the Act and current rules
    // before production (s.7(1) 30 days; proviso 48 hours for life or
    // liberty; s.5(2) +5 days via APIO; s.6(3) transfer within 5 days;
    // s.19(1)/(6) first appeal decided within 30, at most 45 days).
    'rti' => [
        'reply_days' => 30,
        'life_liberty_days' => 2,
        'apio_extra_days' => 5,
        'transfer_days' => 5,
        'appeal_days' => 30,
        'appeal_max_days' => 45,
    ],
];
