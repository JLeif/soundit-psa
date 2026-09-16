<?php

return [
    // No public admission/UI/adapter is installed by the substrate PR.
    // Strict boolean acceptance, not a truthiness test: Env::getOption() hands back
    // raw strings for anything but true/false/empty/null, and every consumer of this
    // flag gates live mailbox/Tactical dispatch on a bare truthy check. Only
    // true/1/on/yes enable; 'off', 'no', 'disabled' or a typo resolve to false
    // instead of failing open. A plain (bool) cast would not do this.
    'enabled' => filter_var(env('SCHEDULED_APPROVALS_ENABLED', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
];
