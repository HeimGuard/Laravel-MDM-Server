<?php

namespace App\Livewire\SecurityScan;

use App\Jobs\SyncSecurityFeed;
use App\Livewire\Concerns\RemediatesFindings;
use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use App\Models\SecurityRule;
use App\Support\SecurityFeed;
use App\Support\SecurityInbox;
use App\Support\SecurityRules;
use App\Support\SecurityScanner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The security scanner of the fleet: the findings of its rules on the devices' security
 * inventories (acknowledge, open again), the events the agents saw (a small SIEM) and the rules
 * (system admins switch them, add their own in JSON and scan again).
 */
class Page extends Component
{
    use RemediatesFindings;

    /** findings, events or rules */
    #[Url(except: 'findings')]
    public string $tab = 'findings';

    /** open (needs attention), acknowledged or resolved */
    #[Url(except: 'open')]
    public string $status = 'open';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $eventType = '';

    #[Url(except: '')]
    public string $eventDevice = '';

    #[Url(except: '')]
    public string $eventSearch = '';

    /** Days back the events reach (0: all that are kept). */
    #[Url(except: 7)]
    public int $eventDays = 7;

    /** Findings and events shown at most. */
    public const LIMIT = 200;

    /** Events shown at most; "Show more" raises it by this much. */
    public const EVENT_PAGE = 100;

    public int $eventLimit = self::EVENT_PAGE;

    public function showMoreEvents(): void
    {
        $this->eventLimit += self::EVENT_PAGE;
    }

    /** A new filter starts from the first page again. */
    public function updated(string $property): void
    {
        if (in_array($property, ['eventType', 'eventDevice', 'eventDays', 'eventSearch'], true)) {
            $this->eventLimit = self::EVENT_PAGE;
        }
    }

    public function mount(): void
    {
        SecurityRule::syncBuiltIn();
    }

    public function acknowledge(int $id): void
    {
        SecurityFinding::query()->with('rule')->findOrFail($id)->acknowledge(auth()->user());
    }

    protected function findingForRemediation(int $id): SecurityFinding
    {
        return SecurityFinding::query()->with(['rule', 'device'])->findOrFail($id);
    }

    public function reopen(int $id): void
    {
        SecurityFinding::query()->with('rule')->findOrFail($id)->reopen();
    }

    /** Acknowledges what the page shows (the filters apply). */
    public function acknowledgeAll(): void
    {
        foreach ($this->findings()->whereNull('acknowledged_at')->get() as $finding) {
            $finding->acknowledge(auth()->user());
        }
    }

    public function toggleRule(int $id): void
    {
        Gate::authorize('is-system-admin');
        $rule = SecurityRule::query()->findOrFail($id);
        $rule->update(['enabled' => ! $rule->enabled]);
        // A parser takes effect with the next collections, a detection rule now.
        if (! $rule->isParser) {
            SecurityScanner::scanAll();
        }
    }

    public function addRule(): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.rule-form', __('Add rule'), [], 'xl');
    }

    public function editRule(int $id): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.rule-form', __('Edit rule'), ['ruleId' => $id], 'xl');
    }

    public function addParser(): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.rule-form', __('Add parser'), ['kind' => 'parser'], 'xl');
    }

    /** A copy of a rule (a built-in one cannot be edited) to change as one's own. */
    public function duplicateRule(int $id): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'security-scan.rule-form', __('Add rule'), ['copyOf' => $id], 'xl');
    }

    /** Takes the rules of the feed now (a job: it waits for the queue worker). */
    public function updateFeed(): void
    {
        Gate::authorize('is-system-admin');
        if (! SecurityFeed::enabled()) {
            alert()->warning(__('No rules feed is set (MDM_SECURITY_FEED_URL).'))->now();

            return;
        }
        SyncSecurityFeed::dispatch(true);
        alert()->success(__('Updating the rules.'))->now();
    }

    public function rescan(): void
    {
        Gate::authorize('is-system-admin');
        $counts = SecurityScanner::scanAll();
        alert()->success(__('Scanned: :opened new, :resolved resolved.', $counts))->now();
    }

    #[On('securityRuleSaved')]
    public function refresh(): void {}

    /** Live update (resources/js/live.js): some device changed. */
    #[On('devices-changed')]
    public function devicesChanged(): void {}

    private function findings()
    {
        return SecurityFinding::query()->with(['device', 'rule', 'acknowledger'])->filtered($this->status, $this->severity, $this->search);
    }

    public function render()
    {
        $active = SecurityFinding::query()->active()->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity');
        $events = collect();
        if ($this->tab === 'events') {
            $events = SecurityEvent::query()->with('device')->latest('occurred_at')->latest('id')
                ->when($this->eventType !== '', fn ($q) => $q->where('type', $this->eventType))
                ->when($this->eventDevice !== '', fn ($q) => $q->where('device_id', (int) $this->eventDevice))
                ->when($this->eventDays > 0, fn ($q) => $q->where('occurred_at', '>=', now()->subDays($this->eventDays)))
                ->when(trim($this->eventSearch) !== '', function ($q) {
                    $like = '%'.addcslashes(trim($this->eventSearch), '%_\\').'%';
                    $q->where(fn ($q) => $q->where('user', 'like', $like)->orWhere('source', 'like', $like)->orWhere('message', 'like', $like));
                })
                ->limit($this->eventLimit + 1)->get();
            $moreEvents = $events->count() > $this->eventLimit;
            $events = $events->take($this->eventLimit);
        }

        return view('livewire.security-scan.page', [
            'counts' => collect(SecurityRules::SEVERITIES)->map(fn ($rank, $severity) => (int) ($active[$severity] ?? 0)),
            'findings' => $this->tab === 'findings' ? $this->findings()->bySeverity()->limit(self::LIMIT)->get() : collect(),
            'events' => $events,
            'moreEvents' => $moreEvents ?? false,
            'eventDevices' => $this->tab === 'events' ? Device::query()->whereIn('id', SecurityEvent::query()->select('device_id')->distinct())->get()->mapWithKeys(fn ($device) => [$device->id => $device->displayName])->sort() : collect(),
            'rules' => $this->tab === 'rules' ? SecurityRule::query()->detection()->addSelect(['open_count' => SecurityFinding::query()->active()->selectRaw('count(distinct coalesce(device_id, target_ip))')->whereColumn('security_rule_id', 'security_rules.id')])->orderBy('source')->orderBy('name')->get() : collect(),
            'parsers' => $this->tab === 'rules' ? SecurityRule::query()->parsers()->orderBy('source')->orderBy('name')->get() : collect(),
            'eventTypes' => $this->tab === 'events' ? SecurityEvent::query()->distinct()->orderBy('type')->pluck('type')->mapWithKeys(fn ($type) => [$type => (new SecurityEvent(['type' => $type]))->label]) : collect(),
            'pending' => SecurityInbox::pending(),
            'feed' => ['enabled' => SecurityFeed::enabled()] + SecurityFeed::state(),
            'scanned' => SecurityInventory::query()->count(),
            'isAdmin' => Gate::allows('is-system-admin'),
        ])->title(__('Security'));
    }
}
