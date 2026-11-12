<?php

namespace App\Services;

use App\Models\SecurityFinding;
use RuntimeException;

/**
 * "Explain with AI" of a finding: what it is, why it matters on this device, how urgent it is and what to do,
 * in the language of the user. Only the description of the finding leaves the server (describe()),
 * nothing else about the device.
 */
class FindingExplanationService
{
    private const SYSTEM = <<<'TEXT'
        You explain a finding of the security scanner of a device management server to an administrator who is
        not a security specialist. Explain in a few short paragraphs (at most about 200 words):
        - what the finding means (the vulnerability or the weak setting, in plain words),
        - what an attacker could do with it and how likely that is on this device (local or remote, needs a user,
          exploited in the wild),
        - how urgent it is,
        - what to do about it (the hint of the scanner, an update, a mitigation), and when nothing can be done yet.
        Linux packages (Debian, Ubuntu) get security fixes backported without a new upstream version: say so when
        the finding may already be fixed by the distribution. You cannot open the references; use what you know.
        Do not invent facts you are not sure about (CVSS scores, dates, versions): say that the reference has them.
        Plain text only, no Markdown (no #, **, tables, code blocks); short lists may start with "- ".
        TEXT;

    /** The language of the answer by the locale of the user (config mdm.locales). */
    private const LANGUAGES = ['cs' => 'Czech', 'de' => 'German', 'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'it' => 'Italian'];

    /**
     * The explanation of the finding as plain text. Throws a RuntimeException with a message for the user when it cannot.
     */
    public static function explain(SecurityFinding $finding, ?string $locale = null): string
    {
        $language = self::LANGUAGES[$locale ?? app()->getLocale()] ?? 'English';
        $text = trim(AiService::ask(self::SYSTEM, "Explain this security finding. Answer in $language.\n".self::describe($finding), name: 'explanation', maxTokens: 4000, effort: 'low'));
        if ($text === '') {
            throw new RuntimeException(__('The AI did not answer, try again.'));
        }

        return mb_substr($text, 0, 5000);
    }

    /**
     * What the AI learns about a finding: the rule, the message, what the scanner found out about it, the hint, the
     * references of the detection, the platform and the operating system of the device.
     */
    public static function describe(SecurityFinding $finding): string
    {
        $rule = $finding->rule;
        $platform = $finding->device?->platform;
        $details = is_array($finding->details) ? $finding->details : [];

        return "Rule: {$finding->title}".($rule ? " ({$rule->key})" : '')."\nSeverity: {$finding->severity}\nFinding: {$finding->message}"
            // What the scanner found out about it: where the software comes from, what can be done about the CVEs (a fix to install, none yet).
            .(filled($details['Status'] ?? null) ? "\nStatus of the CVEs: {$details['Status']}" : '')
            .(filled($details['Cves'] ?? null) ? "\nCVEs: ".mb_strimwidth((string) $details['Cves'], 0, 600, '…') : '')
            .(filled($details['Channel'] ?? null) ? "\nSnap channel: {$details['Channel']}" : '')
            .(filled($details['Origin'] ?? null) ? "\nPackage origin: {$details['Origin']}" : '')
            .($finding->remediation ? "\nHint: {$finding->remediation}" : '')
            // The advisories and the vendor documentation of the detection: they often name a recommended mitigation.
            .($finding->urls !== [] ? "\nReferences of the detection (advisories, vendor documentation):\n".collect($finding->urls)->pluck('url')->map(fn ($url) => "- $url")->implode("\n") : '')
            .(in_array($platform, ['windows', 'linux'], true) ? "\nPlatform: {$platform}" : '')
            // The operating system and its version (Ubuntu 26.04 LTS, Microsoft Windows 11 Pro (10.0.26200)).
            .(filled($finding->device?->os) ? "\nOperating system: ".mb_strimwidth(preg_replace('/\s+/', ' ', (string) $finding->device->os), 0, 200, '…') : '');
    }
}
