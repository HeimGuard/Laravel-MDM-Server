<?php

namespace App\Models;

use App\Services\ReportInventoryService;
use JsonSerializable;

/**
 * The report of a device as the code reads it ($device->data->machine->Hostname, $device->data->services): what is in
 * devices.data (the machine and the small rest) with the lists of the report (services, containers, updates, disk health)
 * read from the inventory of the device when something asks for them.
 */
class ReportData implements JsonSerializable
{
    public function __construct(private object $core, private Device $device) {}

    public function __get(string $name): mixed
    {
        if (property_exists($this->core, $name)) {
            return $this->core->$name;
        }

        return in_array($name, ReportInventoryService::SECTIONS, true) ? $this->device->reportSection($name) : null;
    }

    public function __isset(string $name): bool
    {
        return property_exists($this->core, $name) ? $this->core->$name !== null : (in_array($name, ReportInventoryService::SECTIONS, true) && $this->device->reportSection($name) !== null);
    }

    /** The whole report, as it was sent (without the ARP table of the agent, which is not kept). */
    public function jsonSerialize(): mixed
    {
        $all = clone $this->core;
        foreach (ReportInventoryService::SECTIONS as $name) {
            if (($section = $this->device->reportSection($name)) !== null) {
                $all->$name = $section;
            }
        }

        return $all;
    }
}
