<?php

/*
 * Agent-owned asset watch alerts (card K3VEcxtw).
 */
return [
    // Default and maximum lifetime of a watch, in days.
    'default_days' => 7,
    'max_days' => 30,

    // An `online` fire requires the agent's last_seen to be at most this many
    // seconds old at the moment the state was observed.
    'fresh_seconds' => 120,

    // assets:poll-watched — at most this many agents are fetched per run, each
    // with this per-request timeout (seconds).
    'poll_max_agents' => (int) env('ASSET_WATCH_POLL_MAX_AGENTS', 25),
    'poll_timeout_seconds' => (int) env('ASSET_WATCH_POLL_TIMEOUT_SECONDS', 3),
];
