<?php

return [
    // Local correlation policy, not a vendor delivery SLA. Never use delivery time.
    'link_anchor_before_seconds' => 15 * 60,
    'link_anchor_after_seconds' => 2 * 60,
];
