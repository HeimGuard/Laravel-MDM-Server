<?php

return [

    // Where install commands download the agent from; defaults to this server (/agent/app.ps1),
    // so the agent always matches the server version.
    'agent_download_url' => env('AGENT_DOWNLOAD_URL'),

    // The server's private signing key (RSA-3072, generated on first use). Agents pin its public
    // key and accept only responses, commands and agent updates signed with it.
    'signing_key' => env('MDM_SIGNING_KEY'),

    // Reject agents that do not sign their requests yet (older than 1.7.0). They can no longer be
    // updated remotely then, only reinstalled.
    'require_signed_agents' => (bool) env('MDM_REQUIRE_SIGNED_AGENTS', false),

    // Time zone of the script schedules (cron expressions), e.g. Europe/Prague.
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // pwsh for checking scripts with the PowerShell parser when they are saved (found in PATH when
    // not set); without it a lighter check of their structure is used.
    'pwsh' => env('MDM_PWSH'),

    // The public address this server is reached at, for the network map; found from the address
    // its name (APP_URL) resolves to when not set.
    'public_address' => env('MDM_PUBLIC_ADDRESS'),

    // Without it (and when the name does not resolve to a public address, no device reaches the
    // server through the router's) the server asks this address for its own public address,
    // once an hour (only its address leaves the server). The agents (1.21.0+) ask it too, for
    // each interface, so a device on a VPN to the server or with two connections is drawn behind
    // the address it reaches the internet from. MDM_DETECT_PUBLIC_ADDRESS=false for a server
    // without internet access (and agents that ask nothing).
    'detect_public_address' => (bool) env('MDM_DETECT_PUBLIC_ADDRESS', true),
    'public_address_url' => env('MDM_PUBLIC_ADDRESS_URL', 'https://ifconfig.me/ip'),

    // Where the security scanner takes its rules from besides the ones that come with it: a public git
    // repository (a GitHub address, or the base of the raw files of another host) with a manifest.json,
    // rules/*.json and parsers/*.json, at a branch or tag. Once a day by a job; empty for none.
    'security_feed_url' => env('MDM_SECURITY_FEED_URL'),
    'security_feed_ref' => env('MDM_SECURITY_FEED_REF', 'main'),

    // AI for writing remediation scripts from a description (the AI button in the script form):
    // anthropic (Claude) or openai, with its API key; without a key the button is off. The model
    // defaults to claude-opus-5-5 / gpt-5. MDM_AI_URL is another endpoint: for openai any server
    // with the Chat Completions API (Ollama, LM Studio, vLLM, OpenRouter; a key is optional
    // there). Only the description and the script being edited are sent, nothing about the devices.
    'ai_provider' => env('MDM_AI_PROVIDER', 'anthropic'),
    'ai_api_key' => env('MDM_AI_API_KEY'),
    'ai_model' => env('MDM_AI_MODEL'),
    'ai_url' => env('MDM_AI_URL'),

    // Seconds a signed message may differ from the server clock.
    'signature_max_skew' => 300,

    // Languages of the user interface (lang/*.json), named in their own language. Each user picks one
    // in the profile, the first one on the setup page. Logs, the scheduler and the queue stay in
    // English (APP_LOCALE).
    'locales' => [
        'en' => 'English',
        'cs' => 'Čeština',
        'de' => 'Deutsch',
        'es' => 'Español',
        'fr' => 'Français',
        'it' => 'Italiano',
    ],

];
