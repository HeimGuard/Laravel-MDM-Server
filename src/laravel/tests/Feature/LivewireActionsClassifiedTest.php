<?php

namespace Tests\Feature;

use Livewire\Attributes\Computed;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LivewireActions;
use Tests\TestCase;

/**
 * A ratchet: every public action of every Livewire component is in tests/Support/LivewireActions.php with
 * the level of who may call it (ui, user, admin). A new action fails here until somebody decides.
 * There are no roles: every signed-in user is a trusted operator, system admins have the Gate.
 */
class LivewireActionsClassifiedTest extends TestCase
{
    /** Lifecycle and rendering hooks of Livewire. */
    private const LIVEWIRE_HOOKS = '/^(render|mount|boot|booted|hydrate.*|dehydrate.*|updating.*|updated.*|rendering|rendered|placeholder|exception|__.*)$/';

    /** What the DataTable and Form base classes call (they build the table / form, they change nothing). */
    private const BASE_CLASS_HOOKS = '/^(query|row|headers|headerFilters|actions|renderCasts|renderColumn.*|fields|properties|types|labels|rules|onSuccess|onError)$/';

    /** A data-changing call in an action marked "ui" (a cheap guard, not a proof). */
    private const WRITE = '/->save\(|->delete\(|::create\(|->update\(|issueCommand|queueCommand|DB::/';

    /** An authorization in the action itself. */
    private const AUTHORIZES = '/Gate::(authorize|allows|denies)\(|->authorize\(/';

    /** @return array<class-string, array<int, string>> component => its public actions, as found in the code */
    private function discover(): array
    {
        $root = realpath(app_path('Livewire'));
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getRealPath(), strlen($root) + 1, -4);
            $class = 'App\\Livewire\\'.str_replace('/', '\\', $relative);
            if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $actions = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                // Declared in our code (also through a trait: it reports the class that uses it), not by a package.
                if (! str_starts_with((string) realpath((string) $method->getFileName()), realpath(app_path()))) {
                    continue;
                }
                $name = $method->getName();
                if (preg_match(self::LIVEWIRE_HOOKS, $name) || preg_match(self::BASE_CLASS_HOOKS, $name)) {
                    continue;
                }
                if ($method->getAttributes(Computed::class) !== []) {
                    continue;
                }
                $actions[] = $name;
            }
            sort($actions);
            $found[$class] = $actions;
        }
        ksort($found);

        return $found;
    }

    private function source(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    public function test_every_livewire_action_is_classified_and_the_classification_holds(): void
    {
        $map = LivewireActions::map();
        $discovered = $this->discover();
        $levels = [LivewireActions::UI, LivewireActions::USER, LivewireActions::ADMIN];
        $violations = [];

        // (a) every action is in the map.
        foreach ($discovered as $class => $actions) {
            foreach ($actions as $action) {
                if (! isset($map[$class][$action])) {
                    $violations[] = "$class::$action is not classified: decide who may call it: add it to tests/Support/LivewireActions.php, "
                        ."and add Gate::authorize('is-system-admin') to the method when only system admins may";
                }
            }
        }

        // (b) the map names nothing that is gone, and only known levels.
        foreach ($map as $class => $actions) {
            if (! isset($discovered[$class])) {
                $violations[] = "$class is in tests/Support/LivewireActions.php but is no Livewire component (or has no public action) anymore: remove it";
                continue;
            }
            foreach ($actions as $action => $level) {
                if (! in_array($action, $discovered[$class], true)) {
                    $violations[] = "$class::$action is in tests/Support/LivewireActions.php but is no public action anymore: remove it";
                    continue;
                }
                if (! in_array($level, $levels, true)) {
                    $violations[] = "$class::$action has the unknown level '$level' (ui, user or admin)";
                    continue;
                }

                $source = $this->source($class, $action);
                // (c) an admin action really checks the Gate (or a policy) itself.
                if ($level === LivewireActions::ADMIN && ! preg_match(self::AUTHORIZES, $source)) {
                    $violations[] = "$class::$action is marked admin but does not call Gate::authorize('is-system-admin') (or ->authorize())";
                }
                // (d) a ui action does not write data.
                if ($level === LivewireActions::UI && preg_match(self::WRITE, $source, $match)) {
                    $violations[] = "$class::$action is marked ui but contains '{$match[0]}': it changes data, mark it user (any signed-in user) or admin";
                }
            }
        }

        $this->assertSame([], $violations, "Livewire actions without a decision:\n - ".implode("\n - ", $violations));
    }

    public function test_the_components_are_found(): void
    {
        // A guard of the scan itself: an empty result would let the test above pass without looking at anything.
        $discovered = $this->discover();

        $this->assertArrayHasKey(\App\Livewire\DeviceCommands::class, $discovered);
        $this->assertContains('deleteDevice', $discovered[\App\Livewire\DeviceCommands::class]);
        $this->assertContains('rescan', $discovered[\App\Livewire\SecurityScan\Page::class]);
        // An action of a trait is found under the component that uses it.
        $this->assertContains('explainWithAi', $discovered[\App\Livewire\DeviceSecurity::class]);
    }
}
