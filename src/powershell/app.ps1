<#
.SYNOPSIS
    Laravel-MDM agent for Windows and Linux (Debian / Ubuntu with PowerShell 7).

.DESCRIPTION
    Runs continuously: keeps a WebSocket connection to the server (Laravel Reverb, Pusher protocol)
    to receive commands instantly and periodically sends a device report over HTTP. When the
    WebSocket is unavailable, commands are still delivered in the response to the HTTP report.

.PARAMETER ServerUrl
    The address of the portal (as in Add device). Stored by -Install in the scheduled task / service.

.PARAMETER EnrolmentCode
    With -Install on a new device: the code shown in Add device.

.PARAMETER Install
    Copies the agent to its install directory, enrols the device and registers it as a scheduled
    task running as SYSTEM (Windows) or a systemd service (Linux). On an installed device it only
    updates the agent and keeps the device.

.PARAMETER InstallPath
    Where -Install copies the agent to, default %ProgramData%\Laravel-MDM or /opt/laravel-mdm.

.PARAMETER ServerKeyFingerprint
    With -Install: the fingerprint of the server key (shown in Add device). The agent pins the key
    only when it matches.

.PARAMETER ResetServerKey
    With -Install: forget the pinned server key and pin the current one (after the server key was
    replaced on purpose).

.PARAMETER DisableScripts
    With -Install: never run remediation scripts on this device (stored in config.json, the server
    cannot change it). -EnableScripts allows them again.

.PARAMETER NetworkDiscovery
    With -Install: what the agent does to find devices in its networks (stored in config.json as
    network_discovery, the server cannot change it): off; neighbours (default) reports its ARP
    table; scan also pings every address of a network of its interfaces when asked in the portal.

.PARAMETER SecurityLogs
    With -Install: whether the agent sends the records of its security logs (sign-ins, accounts,
    sudo, Defender, ...) to the portal for the security checks (stored in config.json as
    security_logs, the server cannot change it): off (default) or on. The security inventory
    (software, ports, settings) is sent either way.

.PARAMETER PortScan
    With -Install: whether the agent scans the open ports of an address in its network when asked
    in the portal (stored in config.json as port_scan, the server cannot change it): off (default)
    or on. A scan only connects to the ports of one host of an interface of this device and reads
    what each service returns on its own (a banner, the HTTP Server header and title, the TLS
    certificate); it sends no payloads to the services.

.EXAMPLE
    # Enrol the device and register the agent as a scheduled task running as SYSTEM
    .\app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install

.EXAMPLE
    # Linux: enrol and register the agent as a systemd service
    sudo pwsh ./app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install

.NOTES
    Everything else is in config.json next to the agent (the server cannot change it), read on
    every start: report_interval (300 s), heartbeat_interval (30 s), inventory_interval (21600 s),
    health_interval (3600 s) and realtime (true; false = HTTP only, no WebSocket). The WebSocket
    is always /app at the address of -ServerUrl (nginx proxies it to Reverb).
#>
param (
    [string]
    $ServerUrl,
    [string]
    $EnrolmentCode,
    [switch]
    $Install,
    [string]
    $InstallPath,
    [string]
    $ServerKeyFingerprint,
    [switch]
    $ResetServerKey,
    [switch]
    $DisableScripts,
    [switch]
    $EnableScripts,
    [ValidateSet('off', 'neighbours', 'scan')]
    [string]
    $NetworkDiscovery,
    [ValidateSet('off', 'on')]
    [string]
    $SecurityLogs,
    [ValidateSet('off', 'on')]
    [string]
    $PortScan
)

$ErrorActionPreference = 'Stop'

# Options that are not parameters (not in the help): -Once (one report and exit, for testing) and
# the options of agents before 1.16.0 still on the command line of their task / service
# (-ReportInterval 300 -ReverbHost ... -NoRealtime). They arrive in $args; -Install moves them to
# config.json, where everything else is (the -Reverb* ones are dropped: the WebSocket is /app at
# the address of -ServerUrl).
$LegacyOptions = @{}
for ($i = 0; $i -lt $args.Count; $i++) {
    if ("$($args[$i])" -notmatch '^-(\w+):?$') { continue }
    $name = $Matches[1]
    if (@('Once', 'NoRealtime') -contains $name) {
        $LegacyOptions[$name] = $true
    } elseif ($i + 1 -lt $args.Count) {
        $LegacyOptions[$name] = $args[$i + 1]
        $i++
    }
}
$Once = [bool]$LegacyOptions['Once']
$NoRealtime = [bool]$LegacyOptions['NoRealtime']
$ReportInterval = 300
$HeartbeatInterval = 30
$InventoryInterval = 21600
$HealthInterval = 3600
$SecurityInterval = 3600
foreach ($name in 'ReportInterval', 'HeartbeatInterval', 'InventoryInterval', 'HealthInterval', 'SecurityInterval') {
    $value = 0
    if ($LegacyOptions.ContainsKey($name) -and [int]::TryParse("$($LegacyOptions[$name])", [ref]$value) -and $value -gt 0) {
        Set-Variable -Name $name -Value $value
    } else {
        $LegacyOptions.Remove($name)
    }
}
$ReverbHost = "$($LegacyOptions['ReverbHost'])"
$ReverbPort = 0
[void][int]::TryParse("$($LegacyOptions['ReverbPort'])", [ref]$ReverbPort)
$ReverbScheme = if (@('http', 'https') -contains "$($LegacyOptions['ReverbScheme'])") { "$($LegacyOptions['ReverbScheme'])".ToLowerInvariant() } else { '' }
$ReverbKey = "$($LegacyOptions['ReverbKey'])"
# Not left for the functions (they would see them through dynamic scoping).
Remove-Variable -Name i, name, value -ErrorAction SilentlyContinue
# Reported to the server, which offers an update when it serves a newer agent.
$AgentVersion = '1.29.0'
$AllowedCommands = @('turnOff', 'restart', 'doUpdates', 'installUpdate', 'updateAgent', 'runScripts', 'sync', 'wake', 'pingNow', 'scanNetwork', 'scanPorts')
# What installUpdate may install on its own, with the pattern its id must match (as on the server).
$UpdateKinds = @{
    windows = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
    apt     = '^[a-z0-9][a-z0-9+.\-]*(:[a-z0-9]+)?$'
    winget  = '^[A-Za-z0-9][A-Za-z0-9._+\-]*$'
    flatpak = '^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)+$'
    snap    = '^[a-z0-9][a-z0-9\-]*$'
    module  = '^[A-Za-z0-9][A-Za-z0-9._\-]*$'
    # A PowerShell 7 release from GitHub, by its version.
    pwsh    = '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,4}$'
    # Google Chrome (all users, Windows) from Google's enterprise MSI, by the version it is updated to.
    chrome  = '^[0-9]{1,4}(\.[0-9]{1,5}){3}$'
    # A Python package (pip --user) or pipx app, by its name.
    pip     = '^[A-Za-z0-9][A-Za-z0-9._\-]*$'
    pipx    = '^[A-Za-z0-9][A-Za-z0-9._\-]*$'
}
# The server's public key ("n:e", base64), filled in by the server when it serves this script.
# The agent pins it on the first start and then trusts only what is signed with it.
$EmbeddedServerKey = ''
# $IsLinux only exists in PowerShell 6+, Windows PowerShell 5.1 is always Windows.
$OnLinux = [bool](Get-Variable -Name IsLinux -ValueOnly -ErrorAction SilentlyContinue)
# Token, logs and cache live next to the script; -Install moves the agent to its install directory first.
$AgentDir = $PSScriptRoot

function Get-MachineInfo {
    $DnsInfo = [System.Net.Dns]::GetHostByName($env:computerName)
    $OperatingSystem = Get-CimInstance -ClassName Win32_OperatingSystem -Property Caption, Version, LastBootUpTime, ProductType
    $ComputerSystem = Get-CimInstance -ClassName Win32_ComputerSystem -Property UserName, PCSystemType, Manufacturer, Model
    $Battery = (Get-CimInstance -ClassName Win32_Battery -Property EstimatedChargeRemaining).EstimatedChargeRemaining
    # ProductType 1 = workstation (2, 3 = server editions), PCSystemType 2 = mobile.
    $Type = if ($OperatingSystem.ProductType -ne 1) { 'server' } elseif ($ComputerSystem.PCSystemType -eq 2 -or $null -ne $Battery) { 'laptop' } else { 'desktop' }
    [PSCustomObject] @{
        AgentVersion    = $AgentVersion
        Platform        = 'windows'
        Type            = $Type
        Virtualization  = Get-Virtualization -Vendor $ComputerSystem.Manufacturer -Product $ComputerSystem.Model
        Hostname        = $DnsInfo.HostName
        User            = $env:USERNAME
        os              = "$($OperatingSystem.Caption) ($($OperatingSystem.Version))"
        uptime          = [int]((Get-Date) - $OperatingSystem.LastBootUpTime).TotalSeconds
        last_logon_user = $ComputerSystem.UserName
        Processor       = (Get-ItemProperty -Path 'HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0' -Name ProcessorNameString -ErrorAction SilentlyContinue).ProcessorNameString
        Cores           = [Environment]::ProcessorCount
        Battery         = $Battery
        PluggedIn       = (Get-PowerStatus).plugged
        RestartRequired = Test-PendingReboot
        Drives          = Get-Volume | Where-Object -Property DriveLetter -Value '' -NotLike | ForEach-Object {
            [PSCustomObject]@{
                "DriveLetter"   = $_.DriveLetter
                "FriendlyName"  = $_.FileSystemLabel
                "SizeRemaining" = $_.SizeRemaining
                "Size"          = $_.Size
                "DriveType"     = $_.DriveType
            }
        }
        # Every adapter that is not disabled, also disconnected ones (shown as such in the portal).
        Networks        = @(Get-NetAdapter | Where-Object { $_.Status -ne 'Disabled' -and -not $_.Hidden } | ForEach-Object {
                $address = @(Get-NetIPAddress -InterfaceIndex $_.InterfaceIndex -ErrorAction SilentlyContinue)
                # The default gateway of the interface and its MAC (the network map draws it once).
                $gateway = Get-NetRoute -InterfaceIndex $_.InterfaceIndex -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue |
                    Where-Object { $_.NextHop -and $_.NextHop -ne '0.0.0.0' } | Sort-Object -Property RouteMetric | Select-Object -First 1 -ExpandProperty NextHop
                $gatewayMac = if ($gateway) { Get-NetNeighbor -InterfaceIndex $_.InterfaceIndex -IPAddress $gateway -ErrorAction SilentlyContinue | Where-Object { $_.LinkLayerAddress -and $_.LinkLayerAddress -notmatch '^(00-){5}00$' } | Select-Object -First 1 -ExpandProperty LinkLayerAddress }
                $text = "$($_.Name) $($_.InterfaceDescription)"
                [PSCustomObject]@{
                    "Name"                 = $_.Name
                    "InterfaceDescription" = $_.InterfaceDescription
                    "Status"               = "$($_.Status)"
                    "Connected"            = "$($_.Status)" -eq 'Up'
                    "Type"                 = if ($_.PhysicalMediaType -match '802\.11|Wireless' -or $text -match 'Wi-?Fi|Wireless|WLAN') { 'wifi' }
                                             elseif ($text -match 'VPN|WireGuard|TAP-|OpenVPN|Tailscale|ZeroTier|Fortinet|AnyConnect|GlobalProtect|WAN Miniport') { 'vpn' }
                                             elseif ($text -match 'Bluetooth') { 'bluetooth' }
                                             elseif ($text -match 'Mobile Broadband|Cellular|WWAN|\bLTE\b') { 'cellular' }
                                             elseif ($text -match 'Hyper-V|vEthernet|VirtualBox|VMware|Loopback') { 'virtual' }
                                             else { 'lan' }
                    "Mac"                  = $_.MacAddress
                    "IPAddresses"          = @($address.IPAddress)
                    # With the prefix length, so the server knows which devices share a network (Wake-on-LAN).
                    "Addresses"            = @($address | ForEach-Object { @{ Address = "$($_.IPAddress)"; PrefixLength = [int]$_.PrefixLength } })
                    "Gateway"              = if ($gateway) { "$gateway" } else { $null }
                    "GatewayMac"           = if ($gatewayMac) { "$gatewayMac" } else { $null }
                }
            })
    }
}

function Get-WingetPath {
    # SYSTEM (the agent's account) has no App Installer alias on the PATH: use the newest installed package.
    $command = Get-Command -Name winget.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($command) {
        return $command.Source
    }

    $candidates = Get-ChildItem -Path "$env:ProgramFiles\WindowsApps\Microsoft.DesktopAppInstaller_*__8wekyb3d8bbwe\winget.exe" -ErrorAction SilentlyContinue
    $newest = $candidates | Sort-Object -Property { try { [version](($_.Directory.Name -split '_')[1]) } catch { [version]'0.0' } } -Descending | Select-Object -First 1
    if ($newest) {
        return $newest.FullName
    }

    return $null
}

function ConvertFrom-WingetTable {
    param (
        [string[]]
        $Lines
    )

    # Locale independent (winget prints "Name" / "Nazev" ... in the system language, SYSTEM included):
    # a table is the header above a line of dashes. Columns start where a header word starts and
    # no row has text running across that position (so "K dispozici" stays one column).
    $Lines = @($Lines | ForEach-Object { ("$_" -split "`r")[-1].TrimEnd() })
    for ($i = 1; $i -lt $Lines.Count; $i++) {
        if ($Lines[$i] -notmatch '^-{10,}$') {
            continue
        }
        $header = $Lines[$i - 1]
        $rows = @()
        for ($j = $i + 1; $j -lt $Lines.Count -and $Lines[$j].Trim(); $j++) { $rows += $Lines[$j] }

        $starts = @(0)
        foreach ($match in [regex]::Matches($header, '(?<=\s)\S')) {
            $position = $match.Index
            $crossed = @($rows | Where-Object { $_.Length -gt $position -and $_[$position - 1] -ne ' ' -and $_[$position] -ne ' ' })
            # Rows shorter than the header (a footer like "3 upgrades available.") do not count.
            $crossed = @($crossed | Where-Object { $_.Length -ge $header.Length - 10 })
            if (-not $crossed) { $starts += $position }
        }
        if ($starts.Count -lt 4) {
            continue
        }

        foreach ($row in $rows) {
            $values = for ($c = 0; $c -lt $starts.Count; $c++) {
                $from = $starts[$c]
                $to = if ($c + 1 -lt $starts.Count) { $starts[$c + 1] } else { [int]::MaxValue }
                if ($row.Length -le $from) { '' } else { $row.Substring($from, [math]::Min($to, $row.Length) - $from).Trim() }
            }
            # Name, Id, Version, [Available,] Source; footers and notes have no id and version.
            if ($values.Count -ge 4 -and $values[1] -and $values[2] -and $values[1] -notmatch '\s') {
                ,$values
            }
        }
        $i = $j
    }
}

function Get-WingetSoftware {
    param (
        [switch]
        $Updatable
    )

    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
    $winget = Get-WingetPath
    if (-not $winget) {
        throw 'winget not found'
    }
    # Never prompt (the agent runs without a user): accept the source agreements up front.
    $command = if ($Updatable) { 'upgrade' } else { 'list' }
    $output = @(& $winget $command --accept-source-agreements --disable-interactivity 2>$null)

    foreach ($values in @(ConvertFrom-WingetTable -Lines $output)) {
        $hasAvailable = $values.Count -ge 5
        $item = [PSCustomObject]@{
            Name    = $values[0]
            Id      = $values[1]
            Version = $values[2]
            Source  = $values[$values.Count - 1]
        }
        if ($Updatable) {
            $item | Add-Member -Name 'Avaliable' -Value $(if ($hasAvailable) { $values[3] } else { '' }) -MemberType NoteProperty
        }
        $item
    }
}


function Invoke-WingetInUserSession {
    # Runs "winget <Arguments>" as the logged-on user (a one-off task: interactive, highest
    # privileges, no password needed; conhost --headless keeps it without a window on Windows 10
    # 1809+). Returns @{ User; Code; Lines }, $null when nobody is logged on. -OnWait gets the
    # lines so far every 2 s. The arguments must be of a checked form (ids, fixed options).
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Arguments,
        [string]
        $Title = 'winget',
        [int]
        $TimeoutSeconds = 900,
        [scriptblock]
        $OnWait
    )

    $user = try { (Get-CimInstance -ClassName Win32_ComputerSystem -Property UserName).UserName } catch { $null }
    if (-not $user) { return $null }
    $tag = [guid]::NewGuid().ToString('N')
    $name = "Laravel-MDM-Winget-$($tag.Substring(0, 12))"
    # Windows\Temp: the user can create the file, SYSTEM reads it.
    $log = Join-Path $env:SystemRoot "Temp\mdm-winget-$tag.log"
    # cmd writes winget's exit code into the log itself (MDMEXIT=; the redirection goes first,
    # "=0>>" would redirect handle 0), so the result does not depend on when Task Scheduler
    # updates LastTaskResult (267009 = still running).
    $command = "title Laravel MDM - $Title & echo Laravel MDM: $Title. This window closes by itself. & winget $Arguments > `"$log`" 2>&1 & >>`"$log`" echo MDMEXIT=!errorlevel!"
    if ([Environment]::OSVersion.Version.Build -ge 17763 -and (Test-Path "$env:SystemRoot\System32\conhost.exe")) {
        $execute = "$env:SystemRoot\System32\conhost.exe"
        $taskArguments = "--headless `"$env:SystemRoot\System32\cmd.exe`" /v:on /c `"$command`""
    } else {
        $execute = "$env:SystemRoot\System32\cmd.exe"
        $taskArguments = "/v:on /c `"$command`""
    }
    # No $state here: -OnWait runs in this scope and reads the update job's $state.
    $code = -1
    $lines = @()
    try {
        $action = New-ScheduledTaskAction -Execute $execute -Argument $taskArguments
        $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Highest
        $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Seconds $TimeoutSeconds) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
        Register-ScheduledTask -TaskName $name -Action $action -Principal $principal -Settings $settings -Force -ErrorAction Stop | Out-Null
        Start-ScheduledTask -TaskName $name -ErrorAction Stop
        $deadline = (Get-Date).AddSeconds($TimeoutSeconds + 60)
        $exit = $null
        do {
            Start-Sleep -Seconds 2
            $lines = @(Get-Content -Path $log -Encoding UTF8 -ErrorAction SilentlyContinue)
            $exit = $lines | Where-Object { $_ -match '^MDMEXIT=(-?\d+)' } | Select-Object -Last 1
            $info = Get-ScheduledTaskInfo -TaskName $name -ErrorAction Stop
            $taskState = (Get-ScheduledTask -TaskName $name -ErrorAction Stop).State
            # 267009 = still running, 267011 = not started yet.
            $busy = "$taskState" -eq 'Running' -or $info.LastTaskResult -in 267009, 267011
            if ($OnWait -and -not $exit) { try { & $OnWait $lines } catch { } }
        } while (-not $exit -and $busy -and (Get-Date) -lt $deadline)
        $lines = @(Get-Content -Path $log -Encoding UTF8 -ErrorAction SilentlyContinue)
        # cmd may have written MDMEXIT= between the last read and the task state check.
        if (-not $exit) { $exit = $lines | Where-Object { $_ -match '^MDMEXIT=(-?\d+)' } | Select-Object -Last 1 }
        if ($exit -match '^MDMEXIT=(-?\d+)') {
            $code = [int]$Matches[1]
        } elseif ($busy) {
            Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
            $code = -1
        } else {
            $code = [BitConverter]::ToInt32([BitConverter]::GetBytes([uint32]$info.LastTaskResult), 0)
        }
    }
    finally {
        Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
        Remove-Item -Path $log -Force -ErrorAction SilentlyContinue
    }
    return @{ User = $user; Code = $code; Lines = @($lines | Where-Object { $_ -notmatch '^MDMEXIT=' }) }
}

function Get-WingetUpdates {
    # The updates winget offers, as SYSTEM (machine-wide installs) and in the session of the
    # logged-on user (installs only for them, which SYSTEM does not see; the user's own
    # "winget upgrade" shows both). Scope: machine, or user for the ones only the user sees.
    $system = @(Get-WingetSoftware -Updatable)
    $known = @{}
    foreach ($item in $system) {
        $item | Add-Member -Name 'Scope' -Value 'machine' -MemberType NoteProperty -Force
        $known[$item.Id] = $true
        $item
    }
    # Without conhost --headless (before Windows 10 1809) the check would open a window on the
    # user's desktop at every inventory: only an update the user asked for does that.
    if ($env:OS -ne 'Windows_NT' -or [Environment]::OSVersion.Version.Build -lt 17763 -or -not (Test-Path "$env:SystemRoot\System32\conhost.exe")) { return }
    try {
        $session = Invoke-WingetInUserSession -Arguments 'upgrade --accept-source-agreements --disable-interactivity' -Title 'checking for updates' -TimeoutSeconds 180
    }
    catch {
        return
    }
    if (-not $session -or $session.Code -notin 0, -1978335189) { return }
    foreach ($values in @(ConvertFrom-WingetTable -Lines $session.Lines)) {
        $id = $values[1]
        if ($known.ContainsKey($id) -or $values.Count -lt 5 -or $id -notmatch '^[A-Za-z0-9][A-Za-z0-9._+\-]*$') { continue }
        $known[$id] = $true
        [PSCustomObject]@{
            Name      = $values[0]
            Id        = $id
            Version   = $values[2]
            Avaliable = $values[3]
            Source    = $values[$values.Count - 1]
            Scope     = 'user'
        }
    }
}

function Get-WingetStatus {
    # What a running winget is doing, from its output so far (any language): the download in
    # MB / percent, else that the installer runs. Lines are split at the carriage returns of its
    # progress bar.
    param ([string[]]$Lines)

    $segments = @($Lines | ForEach-Object { "$_" -split "`r" } | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    for ($i = $segments.Count - 1; $i -ge 0; $i--) {
        $text = $segments[$i]
        if ($text -match '([0-9]+(?:[.,][0-9]+)?)\s*(KB|MB|GB)\s*/\s*([0-9]+(?:[.,][0-9]+)?)\s*(KB|MB|GB)') {
            # A text line after the bar: the download is done, the next step runs.
            if ($i -lt $segments.Count - 1) { return 'running the installer' }
            return "downloading $($Matches[1]) $($Matches[2]) / $($Matches[3]) $($Matches[4])"
        }
        if ($text -match '^[^0-9]*?([0-9]{1,3})\s*%$') {
            if ($i -lt $segments.Count - 1) { return 'running the installer' }
            return "downloading $($Matches[1]) %"
        }
    }
    if ($segments.Count -gt 0) { return 'preparing' }
    return 'starting'
}

function Get-WindowsUpdate {
    $UpdateSession = New-Object -ComObject Microsoft.Update.Session
    $UpdateSearcher = $UpdateSession.CreateUpdateSearcher()
    # The same updates Install-WindowsUpdate installs: software and drivers, not hidden.
    $Updates = $UpdateSearcher.Search('IsInstalled=0 and IsHidden=0').Updates

    return $Updates | ForEach-Object {
        [PSCustomObject]@{
            # installUpdate installs a single update by this id.
            Id             = $_.Identity.UpdateID
            Title          = $_.Title
            IsDownloaded   = $_.IsDownloaded
            RebootRequired = $_.RebootRequired
        }
    }
}

function Install-WindowsUpdate {
    # Returns log lines. ResultCode: 2 succeeded, 3 succeeded with errors, 4 failed, 5 aborted.
    # -UpdateId installs only that update; -OnProgress gets the percent (0-100) and a message;
    # $State.Failures collects what failed, $State.Restart is set when a restart is needed.
    param (
        [string]
        $UpdateId,
        [scriptblock]
        $OnProgress = {},
        [hashtable]
        $State = @{ Failures = [System.Collections.ArrayList]@() }
    )

    $results = @{ 0 = 'not started'; 1 = 'in progress'; 2 = 'succeeded'; 3 = 'succeeded with errors'; 4 = 'failed'; 5 = 'aborted' }
    # The code is what to search for; the common ones are named.
    $describe = {
        param ([int]$HResult)
        if (-not $HResult) { return '' }
        $code = '0x{0:X8}' -f $HResult
        $known = @{
            '0x80240016' = 'another installation is running'; '0x80240017' = 'not applicable to this device'
            '0x8024001E' = 'Windows Update is shutting down'; '0x80240022' = 'all updates failed'
            '0x8024200B' = 'the installer of the update failed'; '0x80070005' = 'access denied'
            '0x80070643' = 'the installer failed (MSI)'; '0x800F0922' = 'not enough space in the system reserved partition, or the network failed'
            '0x80070070' = 'not enough disk space'; '0x8024402C' = 'the update server is not reachable'
            '0x80072EFD' = 'the update server is not reachable'; '0x80072EE2' = 'the connection to the update server timed out'
            '0x80240FFF' = 'unexpected Windows Update error'; '0x8024A105' = 'Windows Update needs a restart first'
        }
        if ($known[$code]) { " ($code, $($known[$code]))" } else { " ($code)" }
    }
    $Session = New-Object -ComObject Microsoft.Update.Session
    try { $Session.ClientApplicationID = 'Laravel-MDM agent' } catch { }
    # Software and drivers (the list shows both: "Intel net Driver Update", "NVIDIA Display Driver
    # Update"), without the hidden ones.
    $found = $Session.CreateUpdateSearcher().Search('IsInstalled=0 and IsHidden=0').Updates
    $Updates = New-Object -ComObject Microsoft.Update.UpdateColl
    $waiting = @()
    foreach ($update in $found) {
        if ($UpdateId -and $update.Identity.UpdateID -ne $UpdateId) { continue }
        if ($update.RebootRequired) {
            # Installed already, Windows lists it until the restart finishes it.
            $waiting += $update.Title
            continue
        }
        # CanRequestUserInput is set for many drivers and vendor packages (NVIDIA, Intel, firmware):
        # with ForceQuiet they install without a window, as Windows Update itself installs them as
        # SYSTEM. One that really needs a person fails and says so in its result.
        if (-not $update.EulaAccepted) { $update.AcceptEula() }
        $Updates.Add($update) | Out-Null
    }
    if ($waiting) {
        "Windows Update: installed, waiting for a restart: $($waiting -join '; ')"
        $State.Restart = $true
    }
    if ($Updates.Count -eq 0) {
        if ($UpdateId -and -not $waiting -and $State.Failures.Count -eq 0) {
            'Windows Update: the update is not offered anymore (installed or replaced)'
            [void]$State.Failures.Add('The update is not offered by Windows Update anymore (installed or replaced), the list is collected again')
        } elseif (-not $waiting) {
            'Windows Update: nothing to install'
        }
        return
    }
    "Windows Update: $($Updates.Count) update(s): $(@($Updates | ForEach-Object { $_.Title }) -join '; ')"

    # Windows installs on its own too: one installation at a time, wait for it (30 min at most).
    $Installer = $Session.CreateUpdateInstaller()
    $deadline = (Get-Date).AddMinutes(30)
    if ($Installer.IsBusy) {
        'Windows Update: another installation is running, waiting for it'
        & $OnProgress 0 'Windows Update: waiting for another installation'
        while ($Installer.IsBusy -and (Get-Date) -lt $deadline) { Start-Sleep -Seconds 10 }
    }

    & $OnProgress 0 'Windows Update: downloading'
    $Downloader = $Session.CreateUpdateDownloader()
    $Downloader.Updates = $Updates
    # In the foreground: the default background download is throttled and can take hours.
    try { $Downloader.Priority = 3 } catch { }
    try {
        $download = $Downloader.Download()
        "Windows Update: download $($results[[int]$download.ResultCode])"
    }
    catch {
        "Windows Update: download failed: $($_.Exception.Message)"
        [void]$State.Failures.Add("Windows Update: download failed$(& $describe $_.Exception.HResult)")
        return
    }

    # One by one, so the progress moves with each installed update.
    $reboot = $false
    for ($i = 0; $i -lt $Updates.Count; $i++) {
        $update = $Updates.Item($i)
        if (-not $update.IsDownloaded) {
            $hresult = try { $download.GetUpdateResult($i).HResult } catch { 0 }
            "Windows Update: $($update.Title): not downloaded$(& $describe $hresult)"
            [void]$State.Failures.Add("$($update.Title): download failed$(& $describe $hresult)")
            continue
        }
        & $OnProgress (30 + [int](70 * $i / $Updates.Count)) "Windows Update: installing $($update.Title)"
        $single = New-Object -ComObject Microsoft.Update.UpdateColl
        $single.Add($update) | Out-Null
        $Installer = $Session.CreateUpdateInstaller()
        # Never wait for a prompt or a source medium.
        try { $Installer.ForceQuiet = $true } catch { }
        try { $Installer.AllowSourcePrompts = $false } catch { }
        $Installer.Updates = $single
        $hresult = 0
        try {
            $install = $Installer.Install()
            $code = [int]$install.ResultCode
            $hresult = try { $install.GetUpdateResult(0).HResult } catch { 0 }
            if ($install.RebootRequired) { $reboot = $true }
        }
        catch {
            $code = 4
            $hresult = $_.Exception.HResult
            "Windows Update: $($update.Title): $($_.Exception.Message)"
        }
        if ($code -ne 2) {
            "Windows Update: $($update.Title): $($results[$code])$(& $describe $hresult)"
            if ($code -ne 3) { [void]$State.Failures.Add("$($update.Title): $($results[$code])$(& $describe $hresult)") }
        }
    }
    if ($reboot) { $State.Restart = $true }
    & $OnProgress 100 'Windows Update: done'
    "Windows Update: install finished$(if ($reboot) { ', restart required' })"
}

function Install-PowerShellRelease {
    # Installs a PowerShell 7 release from GitHub (installations no package manager knows about).
    # The download is checked against the release's hashes.sha256. Linux: the .deb when the
    # powershell package is installed (amd64), otherwise the tar.gz replaces the directory of
    # the installed pwsh. Windows: the MSI. Returns log lines; failures go to $State.Failures.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Version,
        [bool]
        $OnLinux,
        [hashtable]
        $State = @{ Failures = [System.Collections.ArrayList]@() }
    )

    if ($Version -notmatch '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,4}$') {
        [void]$State.Failures.Add("PowerShell: invalid version '$Version'")
        return
    }
    $pwsh = @(Get-PowerShellHosts -OnLinux $OnLinux | Where-Object { $_.Edition -eq 'PowerShell 7' }) | Select-Object -First 1
    if (-not $pwsh) {
        [void]$State.Failures.Add('PowerShell 7 is not installed')
        return
    }
    switch (Get-PowerShellManager -Path $pwsh.Path -OnLinux $OnLinux) {
        'snap' {
            # Read-only: the snap is refreshed instead (it may not have this version yet).
            $output = @(snap refresh powershell 2>&1 | ForEach-Object { "$_" })
            if ($LASTEXITCODE) { [void]$State.Failures.Add("PowerShell: snap refresh powershell exit $LASTEXITCODE, $((@($output) | Select-Object -Last 2) -join ' | ')"); return }
            $installed = "$(& $pwsh.Path -NoProfile -NonInteractive -Command '$PSVersionTable.PSVersion.ToString()' 2>$null)".Trim()
            "PowerShell: installed from snap, snap refresh: $((@($output) | Select-Object -Last 1)) (version $installed)"
            if ($installed -ne $Version) {
                [void]$State.Failures.Add("PowerShell is installed from snap, which has $installed, not $Version yet: snap updates it once the release is there")
            }
            return
        }
        'store' {
            [void]$State.Failures.Add('PowerShell is installed from the Microsoft Store, which updates it')
            return
        }
    }
    $base = "https://github.com/PowerShell/PowerShell/releases/download/v$Version"
    $machine = if ($OnLinux) { "$(uname -m)" } else { "$env:PROCESSOR_ARCHITECTURE" }
    $arch = switch -Regex ($machine) { '^(x86_64|AMD64)$' { 'x64' } '^(aarch64|arm64|ARM64)$' { 'arm64' } default { $null } }
    if (-not $arch) {
        [void]$State.Failures.Add("PowerShell: architecture '$machine' is not supported")
        return
    }
    $realPath = if ($OnLinux) { "$(readlink -f $pwsh.Path)" } else { $pwsh.Path }
    $dir = Split-Path -Path $realPath -Parent
    $asset = if ($OnLinux) {
        $deb = if ($arch -eq 'x64' -and (Get-Command -Name dpkg-query -ErrorAction SilentlyContinue)) { "$(dpkg-query -W -f='${Status}' powershell 2>$null)" -match 'install ok installed' }
        if ($deb) { "powershell_$Version-1.deb_amd64.deb" } else { "powershell-$Version-linux-$arch.tar.gz" }
    } else {
        "PowerShell-$Version-win-$arch.msi"
    }

    $temp = Join-Path ([System.IO.Path]::GetTempPath()) "mdm-pwsh-$([guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Path $temp -Force | Out-Null
    try {
        $file = Join-Path $temp $asset
        $ProgressPreference = 'SilentlyContinue'
        Invoke-WebRequest -Uri "$base/$asset" -OutFile $file -UseBasicParsing -TimeoutSec 600
        # The list is UTF-16 in some releases (7.6), ASCII in others: read it by its byte order mark.
        $hashFile = Join-Path $temp 'hashes.sha256'
        Invoke-WebRequest -Uri "$base/hashes.sha256" -OutFile $hashFile -UseBasicParsing -TimeoutSec 60
        $reader = New-Object System.IO.StreamReader($hashFile, [System.Text.Encoding]::UTF8, $true)
        try { $hashes = $reader.ReadToEnd() } finally { $reader.Dispose() }
        $expected = foreach ($line in ($hashes -split "`r?`n")) { if ($line.Trim() -match "^([0-9a-fA-F]{64})\s+\*?$([regex]::Escape($asset))$") { $Matches[1] } }
        if (-not $expected) {
            [void]$State.Failures.Add("PowerShell ${Version}: $asset is not listed in the release hashes")
            return
        }
        $actual = (Get-FileHash -Path $file -Algorithm SHA256).Hash
        if ($actual -ne "$(@($expected)[0])".ToUpperInvariant()) {
            [void]$State.Failures.Add("PowerShell ${Version}: $asset does not match the release hashes (SHA-256 $actual)")
            return
        }
        "PowerShell ${Version}: $asset downloaded, SHA-256 verified"

        if ($asset -like '*.deb') {
            $env:DEBIAN_FRONTEND = 'noninteractive'
            $output = @(apt-get -o DPkg::Lock::Timeout=600 -y -q install $file 2>&1 | ForEach-Object { "$_" })
            if ($LASTEXITCODE) { [void]$State.Failures.Add("PowerShell ${Version}: apt-get install exit $LASTEXITCODE, $((@($output) | Select-Object -Last 2) -join ' | ')") ; return }
        } elseif ($asset -like '*.tar.gz') {
            # Unpacked next to the installation, then swapped in: running pwsh processes keep
            # their open files.
            $new = "$dir.mdm-new"
            Remove-Item -Path $new, "$dir.mdm-old" -Recurse -Force -ErrorAction SilentlyContinue
            # Unpacked it is about three times the archive (7.6: 75 MB -> 200 MB), next to the
            # installed one until the swap.
            $parent = Split-Path -Path $dir -Parent
            $neededMb = [int][Math]::Ceiling((Get-Item -Path $file).Length * 3.5 / 1MB)
            $freeKb = "$(df -Pk $parent 2>$null | Select-Object -Last 1)" -split '\s+' | Select-Object -Index 3
            if ($freeKb -match '^\d+$' -and [long]$freeKb / 1024 -lt $neededMb) {
                [void]$State.Failures.Add("PowerShell ${Version}: not enough disk space in ${parent}: $([int]([long]$freeKb / 1024)) MB free, about $neededMb MB needed")
                return
            }
            New-Item -ItemType Directory -Path $new -Force | Out-Null
            $output = @(tar -xzf $file -C $new 2>&1 | ForEach-Object { "$_" })
            $code = $LASTEXITCODE
            if ($code -or -not (Test-Path -Path "$new/pwsh")) {
                $reason = if ($code) { "tar exit $code" } else { 'no pwsh in the archive' }
                $tail = (@($output | Where-Object { $_.Trim() }) | Select-Object -Last 2) -join ' | '
                [void]$State.Failures.Add("PowerShell ${Version}: unpacking into $new failed ($reason)$(if ($tail) { ": $tail" })")
                Remove-Item -Path $new -Recurse -Force -ErrorAction SilentlyContinue
                return
            }
            chmod +x "$new/pwsh"
            Move-Item -Path $dir -Destination "$dir.mdm-old"
            Move-Item -Path $new -Destination $dir
            Remove-Item -Path "$dir.mdm-old" -Recurse -Force -ErrorAction SilentlyContinue
        } else {
            $process = Start-Process -FilePath msiexec.exe -ArgumentList @('/i', "`"$file`"", '/quiet', '/norestart') -Wait -PassThru
            # 3010: installed, a restart completes it.
            if ($process.ExitCode -notin 0, 3010) { [void]$State.Failures.Add("PowerShell ${Version}: msiexec exit $($process.ExitCode)"); return }
        }

        $installed = "$(& $pwsh.Path -NoProfile -NonInteractive -Command '$PSVersionTable.PSVersion.ToString()' 2>$null)".Trim()
        if ($installed -ne $Version) {
            [void]$State.Failures.Add("PowerShell: version $installed after installing $Version")
            return
        }
        "PowerShell: updated to $Version"
        $State.RestartAgent = $PSVersionTable.PSEdition -eq 'Core'
    }
    catch {
        [void]$State.Failures.Add("PowerShell ${Version}: $($_.Exception.Message)")
    }
    finally {
        Remove-Item -Path $temp -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Get-ChromeInstall {
    # The Google Chrome installed for all users on this device: its version (the one Google Update
    # keeps), its program and its architecture ('x64', 'x86', 'arm64'); $null when there is none. A
    # Chrome installed for one user only is not seen here (the agent runs as SYSTEM).
    $guid = '{8A69D345-D564-463c-AFF1-A69D9E530F96}'
    $path = "$((Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe' -ErrorAction SilentlyContinue).'(default)')".Trim('"')
    if (-not $path -or -not (Test-Path -LiteralPath $path -PathType Leaf)) { return $null }
    $version = $null
    foreach ($root in 'HKLM:\SOFTWARE\Google\Update\Clients', 'HKLM:\SOFTWARE\WOW6432Node\Google\Update\Clients') {
        $value = (Get-ItemProperty -Path "$root\$guid" -Name pv -ErrorAction SilentlyContinue).pv
        if ($value) { $version = "$value"; break }
    }
    if (-not $version) { $version = "$((Get-Item -LiteralPath $path).VersionInfo.ProductVersion)" }
    if ($version -notmatch '^[0-9]+(\.[0-9]+){3}$') { return $null }
    $arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'arm64' } elseif ($path -like '*Program Files (x86)*') { 'x86' } else { 'x64' }
    return @{ Version = $version; Path = $path; Arch = $arch }
}

function Get-ChromeUpdate {
    # The update of the Google Chrome of all users: it is outdated when Google's version history has
    # a newer stable release. winget offers it where there is winget (and the Chrome manifest is
    # current); servers have none, and the version of winget lags behind: Chrome is not listed twice.
    param ($Known)

    $chrome = Get-ChromeInstall
    if (-not $chrome -or @($Known | Where-Object { "$($_.Id)" -eq 'Google.Chrome' })) {
        return
    }
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $platform = switch ($chrome.Arch) { 'x86' { 'win' } 'arm64' { 'win_arm64' } default { 'win64' } }
    $page = Invoke-RestMethod -Uri "https://versionhistory.googleapis.com/v1/chrome/platforms/$platform/channels/stable/versions?orderBy=version%20desc&pageSize=1" -TimeoutSec 30
    $latest = "$(@($page.versions)[0].version)"
    if ($latest -notmatch '^[0-9]+(\.[0-9]+){3}$') { return }
    if ([version]$latest -gt [version]$chrome.Version) {
        [PSCustomObject]@{ Id = 'Google.Chrome'; Version = $chrome.Version; Avaliable = $latest; Source = 'google.com/chrome' }
    }
}

function Install-ChromeRelease {
    # Installs the current stable Google Chrome for all users from Google's enterprise MSI: where winget
    # is not there (servers) or does not have the release yet. The MSI is checked for the signature of
    # Google before it runs. Google serves the release it rolls out now, which may be one behind the newest
    # (a staged rollout): it is enough that Chrome is newer than it was. Returns log lines; failures go to
    # $State.Failures.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Version,
        [hashtable]
        $State = @{ Failures = [System.Collections.ArrayList]@() }
    )

    if ($Version -notmatch '^[0-9]{1,4}(\.[0-9]{1,5}){3}$') {
        [void]$State.Failures.Add("Google Chrome: invalid version '$Version'")
        return
    }
    $chrome = Get-ChromeInstall
    if (-not $chrome) {
        [void]$State.Failures.Add('Google Chrome is not installed for all users')
        return
    }
    $asset = switch ($chrome.Arch) { 'x86' { 'googlechromestandaloneenterprise.msi' } 'arm64' { 'googlechromestandaloneenterprise_arm64.msi' } default { 'googlechromestandaloneenterprise64.msi' } }
    $temp = Join-Path ([System.IO.Path]::GetTempPath()) "mdm-chrome-$([guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Path $temp -Force | Out-Null
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
        $file = Join-Path $temp $asset
        $ProgressPreference = 'SilentlyContinue'
        Invoke-WebRequest -Uri "https://dl.google.com/dl/chrome/install/$asset" -OutFile $file -UseBasicParsing -TimeoutSec 900
        $signature = Get-AuthenticodeSignature -FilePath $file
        if ($signature.Status -ne 'Valid' -or "$($signature.SignerCertificate.Subject)" -notmatch '(^|, )O=Google LLC(,|$)') {
            [void]$State.Failures.Add("Google Chrome ${Version}: $asset is not signed by Google LLC (signature: $($signature.Status), $($signature.SignerCertificate.Subject))")
            return
        }
        "Google Chrome: $asset downloaded, signed by Google LLC"

        $process = Start-Process -FilePath msiexec.exe -ArgumentList @('/i', "`"$file`"", '/qn', '/norestart') -Wait -PassThru
        # 3010: installed, a restart completes it.
        if ($process.ExitCode -notin 0, 3010) {
            [void]$State.Failures.Add("Google Chrome ${Version}: msiexec exit $($process.ExitCode)")
            return
        }
        $after = Get-ChromeInstall
        if (-not $after -or [version]$after.Version -le [version]$chrome.Version) {
            [void]$State.Failures.Add("Google Chrome: version $(if ($after) { $after.Version } else { 'unknown' }) after installing the MSI, it was $($chrome.Version)")
            return
        }
        $note = if ([version]$after.Version -lt [version]$Version) { " (the newest stable is $Version, Google serves this one while it rolls it out)" } else { '' }
        "Google Chrome: updated $($chrome.Version) -> $($after.Version)$note"
        if (Get-Process -Name chrome -ErrorAction SilentlyContinue) {
            'Google Chrome: windows that are open keep running the old version until they are restarted'
        }
    }
    catch {
        [void]$State.Failures.Add("Google Chrome ${Version}: $($_.Exception.Message)")
    }
    finally {
        Remove-Item -Path $temp -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test-PendingReboot {
    if (Get-ChildItem "HKLM:\Software\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending" -EA Ignore) {
        return $true
    }
    if (Get-Item "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired" -EA Ignore) {
        return $true
    }
    if (Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager" -Name PendingFileRenameOperations -EA Ignore) {
        return $true
    }
    try {
        $util = [wmiclass]"\\.\root\ccm\clientsdk:CCM_ClientUtilities"
        $status = $util.DetermineIfRebootPending()
        if (( $null -ne $status) -and $status.RebootPending) {
            return $true
        }
    }
    catch {
    }

    return $false
}

function Get-AgentServices {
    # Running services and the ones that should run but do not (automatic but stopped, failed).
    if ($OnLinux) {
        # unit load active sub description
        systemctl list-units --type=service --all --plain --no-legend --no-pager 2>$null | ForEach-Object {
            $parts = $_.Trim() -split '\s+', 5
            if ($parts.Count -ge 4 -and $parts[1] -eq 'loaded' -and ($parts[3] -eq 'running' -or $parts[2] -eq 'failed')) {
                [PSCustomObject]@{
                    Name        = $parts[0] -replace '\.service$', ''
                    DisplayName = if ($parts.Count -eq 5) { $parts[4] } else { '' }
                    State       = if ($parts[2] -eq 'failed') { 'failed' } else { 'running' }
                    StartType   = $null
                }
            }
        }
        return
    }

    Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Status -eq 'Running' -or ($_.StartType -eq 'Automatic' -and $_.Status -eq 'Stopped') } | ForEach-Object {
        [PSCustomObject]@{
            Name        = $_.Name
            DisplayName = $_.DisplayName
            State       = if ($_.Status -eq 'Running') { 'running' } else { 'stopped' }
            StartType   = "$($_.StartType)".ToLowerInvariant()
        }
    }
}

function Test-DockerInstalled {
    # The CLI alone (docker-ce-cli, a Docker Desktop that is not running, a leftover) is not Docker:
    # the engine must be reachable at its default endpoint or one set in DOCKER_HOST.
    if (-not (Get-Command -Name docker -CommandType Application -ErrorAction SilentlyContinue)) {
        return $false
    }
    if ($env:DOCKER_HOST) {
        return $true
    }
    if ($OnLinux) {
        return (Test-Path -Path /var/run/docker.sock) -or (Test-Path -Path /run/docker.sock)
    }

    return Test-Path -Path '\\.\pipe\docker_engine'
}

function Get-DockerContainers {
    # Only when Docker is installed; one call to the local daemon, no per-container requests.
    if (-not (Test-DockerInstalled)) {
        return $null
    }

    $output = @(docker ps --all --no-trunc --format '{{json .}}' 2>&1)
    if ($LASTEXITCODE -ne 0) {
        return @{ error = (($output | Select-Object -First 1) -as [string]).Trim() }
    }

    return @{
        containers = @($output | Where-Object { $_ -is [string] -and $_.StartsWith('{') } | ForEach-Object {
                $container = $_ | ConvertFrom-Json
                [PSCustomObject]@{
                    Name    = $container.Names
                    Image   = $container.Image
                    State   = $container.State
                    Status  = $container.Status
                    Ports   = $container.Ports
                    Created = $container.CreatedAt
                }
            })
    }
}

function Get-DiskHealth {
    param (
        # The previous result, reused for disks that are asleep now.
        $Previous
    )

    if ($OnLinux) {
        return Get-LinuxDiskHealth -Previous $Previous
    }

    $disks = @(Get-PhysicalDisk -ErrorAction Stop | ForEach-Object {
            $counter = $_ | Get-StorageReliabilityCounter -ErrorAction SilentlyContinue
            $errors = [long]$counter.ReadErrorsUncorrected + [long]$counter.WriteErrorsUncorrected
            [PSCustomObject]@{
                Device       = "PhysicalDisk$($_.DeviceId)"
                Model        = $_.FriendlyName
                Serial       = "$($_.SerialNumber)".Trim()
                Protocol     = "$($_.BusType)"
                MediaType    = "$($_.MediaType)"
                Size         = [long]$_.Size
                Health       = switch ("$($_.HealthStatus)") { 'Healthy' { 'passed' } 'Unhealthy' { 'failed' } 'Warning' { 'warning' } default { 'unknown' } }
                Temperature  = if ($counter.Temperature) { [int]$counter.Temperature } else { $null }
                PowerOnHours = if ($null -ne $counter.PowerOnHours) { [long]$counter.PowerOnHours } else { $null }
                WearPercent  = if ($null -ne $counter.Wear) { [int]$counter.Wear } else { $null }
                Reallocated  = $null
                Pending      = $null
                MediaErrors  = if ($counter) { $errors } else { $null }
                Standby      = $false
            }
        })

    return @{ disks = $disks }
}

function Get-LinuxDiskHealth {
    param (
        $Previous
    )

    if (-not (Get-Command -Name smartctl -CommandType Application -ErrorAction SilentlyContinue)) {
        return @{ error = 'smartctl not found, install smartmontools (apt install smartmontools).' }
    }

    $scan = (smartctl --scan -j 2>$null | Out-String | ConvertFrom-Json).devices
    $disks = foreach ($device in @($scan)) {
        if (-not $device.name) {
            continue
        }

        # -n standby: do not spin up a sleeping disk just to read its values.
        $result = smartctl -j -n standby -H -A -i -d $device.type $device.name 2>$null | Out-String | ConvertFrom-Json -ErrorAction SilentlyContinue
        if (-not $result) {
            continue
        }

        if ([bool](@($result.smartctl.messages.string) -match 'STANDBY|SLEEP')) {
            $last = @($Previous.disks) | Where-Object { $_.Device -eq $device.name } | Select-Object -First 1
            if ($last) {
                $last.Standby = $true
                $last
            } else {
                [PSCustomObject]@{ Device = $device.name; Model = $null; Serial = $null; Protocol = $device.protocol; MediaType = $null; Size = $null; Health = 'unknown'; Temperature = $null; PowerOnHours = $null; WearPercent = $null; Reallocated = $null; Pending = $null; MediaErrors = $null; Standby = $true }
            }
            continue
        }

        if (-not $result.model_name -and -not $result.serial_number) {
            # No SMART on this device (virtual disks, some USB bridges).
            continue
        }

        $attributes = @{}
        foreach ($attribute in @($result.ata_smart_attributes.table)) {
            if ($attribute) { $attributes[[int]$attribute.id] = $attribute }
        }
        $nvme = $result.nvme_smart_health_information_log
        # SSD wear on ATA: normalized "life left" attributes (Samsung 177, Intel 233, others 231, 202).
        $wear = $null
        foreach ($id in 177, 231, 233, 202) {
            if ($attributes.ContainsKey($id)) { $wear = 100 - [int]$attributes[$id].value; break }
        }
        if ($nvme) { $wear = $nvme.percentage_used }

        [PSCustomObject]@{
            Device       = $device.name
            Model        = $result.model_name
            Serial       = $result.serial_number
            Protocol     = $result.device.protocol
            MediaType    = if ($nvme -or $result.rotation_rate -eq 0) { 'SSD' } elseif ($result.rotation_rate) { 'HDD' } else { $null }
            Size         = $result.user_capacity.bytes
            Health       = if ($null -eq $result.smart_status.passed) { 'unknown' } elseif ($result.smart_status.passed) { 'passed' } else { 'failed' }
            Temperature  = $result.temperature.current
            PowerOnHours = $result.power_on_time.hours
            WearPercent  = $wear
            Reallocated  = if ($attributes.ContainsKey(5)) { [long]$attributes[5].raw.value } else { $null }
            Pending      = if ($attributes.ContainsKey(197)) { [long]$attributes[197].raw.value } else { $null }
            MediaErrors  = if ($nvme) { [long]$nvme.media_errors } elseif ($attributes.ContainsKey(198)) { [long]$attributes[198].raw.value } else { $null }
            Standby      = $false
        }
    }

    return @{ disks = @($disks) }
}

#region Security inventory

# What the security scanner of the portal looks at (agents 1.17.0+, hourly, in a job at idle
# priority): installed software, processes, listening ports, startup items, administrators,
# security settings and the security events since the previous collection. The rules run on the
# server (Security page), the agent only collects; logs go as raw records, parsed by the parser
# rules of the server. Every part is optional: one that fails is left out.

function ConvertTo-SecurityText ($Value, [int]$Max = 1000) {
    $text = "$Value".Trim()
    if ($text.Length -gt $Max) { $text = $text.Substring(0, $Max) }
    if ($text) { $text } else { $null }
}

function Get-DpkgFixedCves {
    # The CVE ids a Debian / Ubuntu package names in its changelog (the entries of the installed version
    # and the ones before it): ",CVE-2026-1,CVE-2026-2," (sorted, with the commas around, so a rule can
    # look for ",CVE-x," and not match a longer id), or $null when there are none or no changelog.
    # Distributions backport a fix without changing the upstream version, so the version alone says
    # nothing; only the head of the changelog (the recent entries) is read.
    param ([string]$Package)

    if ($Package -notmatch '^[a-z0-9][a-z0-9+.\-]*$') { return $null }
    foreach ($name in 'changelog.Debian.gz', 'changelog.gz', 'changelog.Debian', 'changelog') {
        $path = "/usr/share/doc/$Package/$name"
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        try {
            $file = [System.IO.File]::OpenRead($path)
            try {
                $source = if ($name -like '*.gz') { New-Object System.IO.Compression.GZipStream($file, [System.IO.Compression.CompressionMode]::Decompress) } else { $file }
                $buffer = New-Object byte[] 262144
                $read = 0
                while ($read -lt $buffer.Length) {
                    $count = $source.Read($buffer, $read, $buffer.Length - $read)
                    if ($count -le 0) { break }
                    $read += $count
                }
            }
            finally { $file.Dispose() }
            $text = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $read)
            $ids = [string[]]@([regex]::Matches($text, 'CVE-\d{4}-\d{4,}', 'IgnoreCase') | ForEach-Object { $_.Value.ToUpperInvariant() } | Select-Object -Unique)
            if ($ids.Count -eq 0) { return $null }
            [Array]::Sort($ids, [System.StringComparer]::Ordinal)
            # At most 4000 characters (the server keeps that much of a field), cut at an id.
            $result = ','
            foreach ($id in $ids) {
                if ($result.Length + $id.Length + 1 -gt 4000) { break }
                $result += "$id,"
            }
            return $result
        }
        catch { }
    }
    return $null
}

function Get-AptPolicy {
    # What `apt-cache policy <packages>` says of the packages (without names it lists only the repositories, not the packages):
    # the Candidate (the version apt would install) and the Origin of the
    # installed version ('Ubuntu', 'Debian', the host of another repository, or 'dpkg/status' when no active repository
    # has it: it was installed from a file, or its repository is gone). {name = @{ Candidate; Origin }}.
    param ([string[]]$Names)

    $env:LC_ALL = 'C'
    # What the repositories call themselves (o= of their release line: Ubuntu, Debian, Docker, Google LLC): "Package files:" of
    # `apt-cache policy` without names, by the line of the repository ("http://x/ubuntu jammy/main amd64 Packages").
    $labels = @{}
    $files = $null
    foreach ($line in @(apt-cache policy 2>$null)) {
        if ($line -match '^\s+-?\d+\s+(\S.*?)\s*$') { $files = $Matches[1] }
        elseif ($files -and $line -match '^\s+release\s+.*\bo=([^,]+)') { $labels[$files] = $Matches[1].Trim(); $files = $null }
    }
    $map = @{}
    $name = $null
    $installed = $null
    $current = $null
    $origin = $null
    $flush = {
        if ($name -and $installed -and $installed -ne '(none)') {
            $map[$name] = @{ Candidate = $candidate; Origin = if ($origin) { $origin } else { 'dpkg/status' } }
        }
    }
    $candidate = $null
    $lines = @()
    # In chunks: the command line of a thousand packages would be long.
    for ($i = 0; $i -lt @($Names).Count; $i += 200) {
        $lines += @(apt-cache policy @(@($Names)[$i..([Math]::Min($i + 199, @($Names).Count - 1))]) 2>$null)
    }
    foreach ($line in $lines) {
        if ($line -match '^(\S.*):\s*$') {
            & $flush
            $name = $Matches[1]; $installed = $null; $candidate = $null; $current = $null; $origin = $null
        } elseif ($line -match '^\s+Installed:\s*(\S+)') { $installed = $Matches[1]
        } elseif ($line -match '^\s+Candidate:\s*(\S+)') { $candidate = if ($Matches[1] -eq '(none)') { $null } else { $Matches[1] }
        } elseif ($line -match '^\s+(\*\*\*\s+)?(\S+)\s+\d+\s*$') { $current = if ($Matches[1]) { $Matches[2] } else { $null }
        } elseif ($current -and -not $origin -and $line -match '^\s+\d+\s+((?:https?|file|cdrom):\S.*?)\s*$') {
            $rest = $Matches[1]
            $url = ($rest -split '\s+')[0].ToLowerInvariant()
            # The name the repository gives itself, else the address tells (a mirror of Ubuntu has "ubuntu" in it).
            $origin = if ($labels[$rest]) { $labels[$rest] } elseif ($url -match 'ubuntu') { 'Ubuntu' } elseif ($url -match 'debian') { 'Debian' } elseif ($url -match '^https?://([^/:]+)') { $Matches[1] } else { 'local' }
        }
    }
    & $flush
    return $map
}

function Get-LinuxFirewall {
    # Whether a firewall is active and what is installed, from every tool: ufw, firewalld, nftables and iptables. Active means
    # ufw / firewalld running, or a chain on the input hook with a drop / reject policy or rules (the chains and rules of Docker,
    # DOCKER* and FORWARD, do not count). Returns @{ Enabled = $true | $false | $null; Detail = text with how to turn it on }.
    $active = @()
    $installed = @()
    if (Get-Command -Name ufw -CommandType Application -ErrorAction SilentlyContinue) {
        if ("$(ufw status 2>$null)" -match 'Status: active') { $active += 'ufw' } else { $installed += 'ufw is installed but inactive: sudo ufw enable' }
    }
    if (Get-Command -Name firewall-cmd -CommandType Application -ErrorAction SilentlyContinue) {
        if ("$(firewall-cmd --state 2>$null)".Trim() -eq 'running') { $active += 'firewalld' } else { $installed += 'firewalld is installed but not running: sudo systemctl enable --now firewalld' }
    }
    $checked = $false
    if (Get-Command -Name nft -CommandType Application -ErrorAction SilentlyContinue) {
        $ruleset = "$(nft list ruleset 2>$null)"
        if ($LASTEXITCODE -eq 0) {
            $checked = $true
            foreach ($chain in [regex]::Matches($ruleset, '(?s)chain\s+(\S+)\s*\{([^{}]*)\}')) {
                if ($chain.Groups[1].Value -match '^(DOCKER|docker)') { continue }
                $body = $chain.Groups[2].Value
                if ($body -match 'hook\s+input' -and $body -match 'policy\s+(drop|reject)|\s(drop|reject)\b') { $active += 'nftables'; break }
            }
            if ($active -notcontains 'nftables') { $installed += 'nftables is installed without a drop policy or rules on the input hook: write them in /etc/nftables.conf and sudo systemctl enable --now nftables' }
        }
    }
    if ($active -notcontains 'nftables' -and (Get-Command -Name iptables -CommandType Application -ErrorAction SilentlyContinue)) {
        # Only the INPUT chain: Docker's own chains and FORWARD are not a firewall of the device.
        $rules = @(iptables -S INPUT 2>$null)
        if ($LASTEXITCODE -eq 0) {
            $checked = $true
            if ($rules -match '^-P INPUT (DROP|REJECT)|^-A INPUT .*-j (DROP|REJECT)') { $active += 'iptables' } else { $installed += 'iptables has no drop / reject policy or rule on INPUT' }
        }
    }
    if ($active.Count -gt 0) { return @{ Enabled = $true; Detail = "active: $($active -join ', ')" } }
    if ($installed.Count -eq 0 -and -not $checked) { return @{ Enabled = $null; Detail = $null } }
    $detail = if ($installed.Count -gt 0) { $installed -join '; ' } else { 'no ufw, firewalld, nftables or iptables is installed: sudo apt install ufw && sudo ufw enable' }
    return @{ Enabled = $false; Detail = $detail }
}

function Test-LinuxDeviceEncrypted {
    # Whether dm-crypt / LUKS is somewhere under the device (a partition of a LUKS device, LVM on LUKS, LUKS on LVM):
    # lsblk -s walks from the device down to its parents; without lsblk the same is read from sysfs.
    param ([string]$Device)

    if (Get-Command -Name lsblk -CommandType Application -ErrorAction SilentlyContinue) {
        $types = @(lsblk -s -n -o TYPE $Device 2>$null)
        if ($LASTEXITCODE -eq 0 -and $types.Count -gt 0) { return [bool]($types -match 'crypt') }
    }
    $walk = $null
    $walk = {
        param ([string]$Name, [int]$Depth)
        if ($Depth -gt 8 -or -not $Name) { return $false }
        $uuid = "$(Get-Content -Path "/sys/class/block/$Name/dm/uuid" -ErrorAction SilentlyContinue)"
        if ($uuid -like 'CRYPT-*') { return $true }
        foreach ($slave in @(Get-ChildItem -Path "/sys/class/block/$Name/slaves" -ErrorAction SilentlyContinue)) {
            if (& $walk $slave.Name ($Depth + 1)) { return $true }
        }
        # A partition: its disk is the parent directory in sysfs.
        $real = "$(readlink -f "/sys/class/block/$Name" 2>$null)"
        if ($real -match '/block/[^/]+/[^/]+$') { return [bool](& $walk (Split-Path -Leaf (Split-Path -Parent $real)) ($Depth + 1)) }
        return $false
    }
    $resolved = "$(readlink -f $Device 2>$null)"
    if (-not $resolved) { return $null }
    return [bool](& $walk (Split-Path -Leaf $resolved) 0)
}

function Get-LinuxUnencryptedMounts {
    # The mounts of the disks of the device (/, /home, /var, /srv, /data, ... on a block device, each device once) that are not on
    # an encrypted device; $null when it cannot be told. Boot and EFI partitions, snaps, containers, removable media and what is not
    # a disk (tmpfs, network shares) are not looked at.
    $unencrypted = @()
    $known = 0
    $seen = @{}
    $mounts = @(findmnt -rn -o TARGET,SOURCE,FSTYPE 2>$null | ForEach-Object {
            $p = $_ -split '\s+'
            if ($p.Count -ge 3) { [PSCustomObject]@{ Target = $p[0] -replace '\\x20', ' '; Source = $p[1] -replace '\[.*\]$', ''; Type = $p[2] } }
        } | Sort-Object -Property { $_.Target.Length })
    foreach ($mount in $mounts) {
        if ($mount.Type -notin 'ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'f2fs', 'jfs', 'reiserfs') { continue }
        if (-not $mount.Source.StartsWith('/dev/')) { continue }
        if ($mount.Target -match '^/(boot|snap|media|run|var/lib/(docker|containers|snapd|kubelet|lxd))(/|$)') { continue }
        if ($seen[$mount.Source]) { continue }
        $seen[$mount.Source] = $true
        $encrypted = Test-LinuxDeviceEncrypted -Device $mount.Source
        if ($null -eq $encrypted) { continue }
        $known++
        if (-not $encrypted) { $unencrypted += $mount.Target }
    }
    if ($known -eq 0) { return $null }
    return , $unencrypted
}

function Get-AptUpdateState {
    # What the last apt-get update of the agent said (written by Get-AptUpdates): @{ Ok; Errors }, $null when it did not run yet.
    param ([string]$Path)

    if (-not $Path -or -not (Test-Path -LiteralPath $Path)) { return $null }
    try {
        $state = Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($null -eq $state.ok) { return $null }
        return @{ Ok = [bool]$state.ok; Errors = "$($state.errors)" }
    }
    catch { return $null }
}

function Add-SecuritySoftwareContext {
    # Whether a remote access tool or a package without a source of updates is running, as whom and whether it listens on the
    # network: the processes and listening ports it matches by the first word of its name. Only these items get the fields (Running,
    # RunUser, Listening), so the other items, and their hashes, do not change.
    param ($Software, $Processes, $Listening)

    $remote = '\b(anydesk|teamviewer|rustdesk|ultraviewer|supremo|splashtop|ammyy|radmin|dwservice|dwagent|screenconnect|connectwise control|logmein|remotepc|aeroadmin|getscreen|zoho assist|atera)\b'
    foreach ($item in @($Software)) {
        if (-not ($item.Name -match $remote -or $item.NoUpdateSource)) { continue }
        $token = if ("$($item.Name)".ToLowerInvariant() -match '^[a-z0-9]+') { $Matches[0] } else { '' }
        if ($token.Length -lt 4) { continue }
        $running = @(@($Processes) | Where-Object { "$($_.Name)$($_.Path)".ToLowerInvariant().Contains($token) })
        $ports = @(@($Listening) | Where-Object { "$($_.Process)$($_.Path)".ToLowerInvariant().Contains($token) -and "$($_.Address)" -notmatch '^(127\.|::1$|localhost)' })
        $users = @($running | ForEach-Object { "$($_.User)" } | Where-Object { $_ } | Select-Object -Unique | Select-Object -First 3)
        $item | Add-Member -NotePropertyName Running -NotePropertyValue ($running.Count -gt 0) -Force
        $item | Add-Member -NotePropertyName RunUser -NotePropertyValue $(if ($users) { $users -join ', ' } else { $null }) -Force
        $item | Add-Member -NotePropertyName Listening -NotePropertyValue ($ports.Count -gt 0) -Force
    }
}

function Get-SecuritySoftware {
    param (
        [bool]$OnLinux,
        # A file that remembers the CVEs of the changelog of a package version, so only new and updated packages are read.
        [string]$CachePath = ''
    )

    if ($OnLinux) {
        if (Get-Command -Name dpkg-query -CommandType Application -ErrorAction SilentlyContinue) {
            $cves = @{}
            if ($CachePath) {
                try { foreach ($property in (Get-Content -Path $CachePath -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json).PSObject.Properties) { $cves[$property.Name] = "$($property.Value)" } } catch { }
            }
            $used = @{}
            $policy = @{}
            try { $policy = Get-AptPolicy -Names @(dpkg-query -W -f '${db:Status-Abbrev}\t${Package}\n' 2>$null | ForEach-Object { $p = $_ -split "`t"; if ($p.Count -ge 2 -and $p[0] -like 'i*') { $p[1] } }) } catch { }
            $dpkg = @(dpkg-query -W -f '${db:Status-Abbrev}\t${Package}\t${Version}\t${Maintainer}\t${source:Package}\n' 2>$null | ForEach-Object {
                $parts = $_ -split "`t"
                if ($parts.Count -ge 4 -and $parts[0] -like 'i*') {
                    $key = "$($parts[1])|$($parts[2])"
                    if (-not $cves.ContainsKey($key)) { $cves[$key] = "$(Get-DpkgFixedCves $parts[1])" }
                    $used[$key] = $cves[$key]
                    # The source package of a binary one (libssl3t64 is openssl, libglib2.0-0t64 is glib2.0): what a
                    # distribution's security tracker is about. Only when it is not the package's own name.
                    $sourcePackage = if ($parts.Count -ge 5 -and $parts[4] -and $parts[4] -ne $parts[1]) { ConvertTo-SecurityText $parts[4] 200 } else { $null }
                    # The candidate (only when it is another version than the installed one) and where the package comes from (apt-cache policy).
                    $entry = $policy[$parts[1]]
                    $candidate = if ($entry -and $entry.Candidate -and $entry.Candidate -ne $parts[2]) { $entry.Candidate } else { $null }
                    $newer = $false
                    if ($candidate) { dpkg --compare-versions $candidate gt $parts[2] 2>$null; $newer = $LASTEXITCODE -eq 0 }
                    [PSCustomObject]@{
                        Name = $parts[1]; Version = $parts[2]; Publisher = ConvertTo-SecurityText $parts[3] 200; Source = 'dpkg'
                        FixedCves = if ($used[$key]) { $used[$key] } else { $null }; SourcePackage = $sourcePackage
                        Candidate = if ($newer) { $candidate } else { $null }
                        Origin = if ($entry) { $entry.Origin } else { $null }
                        UpdateAvailable = if ($entry) { $newer } else { $null }
                        NoUpdateSource = if ($entry) { $entry.Origin -eq 'dpkg/status' } else { $null }
                        Running = $null; RunUser = $null; Listening = $null
                    }
                }
            })
            # Only the installed versions stay in the cache.
            if ($CachePath -and $dpkg.Count -gt 0) { try { $used | ConvertTo-Json -Compress | Set-Content -Path $CachePath -Encoding UTF8 } catch { } }
            $dpkg
        } elseif (Get-Command -Name rpm -CommandType Application -ErrorAction SilentlyContinue) {
            rpm -qa --queryformat '%{NAME}\t%{VERSION}-%{RELEASE}\t%{VENDOR}\n' 2>$null | ForEach-Object {
                $parts = $_ -split "`t"
                if ($parts.Count -ge 3) { [PSCustomObject]@{ Name = $parts[0]; Version = $parts[1]; Publisher = ConvertTo-SecurityText $parts[2] 200; Source = 'rpm' } }
            }
        }
        if (Get-Command -Name snap -CommandType Application -ErrorAction SilentlyContinue) {
            # The version a refresh would bring, in the channel the snap tracks (snap refresh --list: Name Version Rev Size Publisher Notes).
            $refresh = @{}
            foreach ($line in @(snap refresh --list 2>$null | Select-Object -Skip 1)) {
                $parts = $line.Trim() -split '\s+'
                if ($parts.Count -ge 2) { $refresh[$parts[0]] = $parts[1] }
            }
            snap list 2>$null | Select-Object -Skip 1 | ForEach-Object {
                $parts = $_.Trim() -split '\s+'
                if ($parts.Count -ge 2) {
                    $candidate = $refresh[$parts[0]]
                    [PSCustomObject]@{
                        Name = $parts[0]; Version = $parts[1]; Publisher = if ($parts.Count -ge 5) { $parts[4] } else { $null }; Source = 'snap'
                        Channel = if ($parts.Count -ge 4) { $parts[3] } else { $null }
                        Candidate = if ($candidate) { $candidate } else { $null }
                        UpdateAvailable = [bool]$candidate
                    }
                }
            }
        }
        if (Get-Command -Name flatpak -CommandType Application -ErrorAction SilentlyContinue) {
            flatpak list --app --system --columns=application,version,origin 2>$null | ForEach-Object {
                $parts = $_ -split "`t"
                if ($parts[0]) { [PSCustomObject]@{ Name = $parts[0]; Version = if ($parts.Count -ge 2) { $parts[1] } else { $null }; Publisher = $null; Source = 'flatpak' } }
            }
            # What the users installed for themselves (flatpak --user is theirs: asked as each of them).
            foreach ($userDir in @(Get-ChildItem -Path /home -Directory -ErrorAction SilentlyContinue)) {
                if (-not (Test-Path -LiteralPath "$($userDir.FullName)/.local/share/flatpak/app")) { continue }
                runuser -u $userDir.Name -- flatpak list --app --user --columns=application,version,origin 2>$null | ForEach-Object {
                    $parts = $_ -split "`t"
                    if ($parts[0]) { [PSCustomObject]@{ Name = $parts[0]; Version = if ($parts.Count -ge 2) { $parts[1] } else { $null }; Publisher = $null; Source = 'flatpak' } }
                }
            }
        }
        return
    }

    # Programs and Features: machine-wide (64 and 32 bit) and per user (the loaded profiles, then the profiles of users who are
    # not signed in: their programs - VS Code, Teams, Slack, a per-user Chrome - are installed for them only), and the Store apps.
    $paths = @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*')
    $paths += @(Get-ChildItem -Path Registry::HKEY_USERS -ErrorAction SilentlyContinue | Where-Object { $_.PSChildName -match '^S-1-5-21-[\d-]+$' } |
            ForEach-Object { "Registry::HKEY_USERS\$($_.PSChildName)\Software\Microsoft\Windows\CurrentVersion\Uninstall\*" })
    $software = @(Get-ItemProperty -Path $paths -ErrorAction SilentlyContinue |
        Where-Object { $_.DisplayName -and $_.SystemComponent -ne 1 -and -not $_.ParentKeyName } |
        ForEach-Object {
            [PSCustomObject]@{
                Name      = ConvertTo-SecurityText $_.DisplayName 300
                Version   = ConvertTo-SecurityText $_.DisplayVersion 100
                Publisher = ConvertTo-SecurityText $_.Publisher 200
                Source    = if ($_.PSPath -match 'HKEY_USERS') { 'registry (user)' } else { 'registry' }
            }
        })
    try { $software += @(Get-OfflineUserSoftware) } catch { }
    try { $software += @(Get-AppxSoftware) } catch { }
    $software | Sort-Object -Property Name, Version, Source -Unique
}

function Get-OfflineUserSoftware {
    # The programs of the users who are not signed in: the hive of their profile (NTUSER.DAT) is loaded for a moment, read and
    # unloaded again (the agent runs as SYSTEM). A profile whose hive is in use is left out.
    $loaded = @(Get-ChildItem -Path Registry::HKEY_USERS -ErrorAction SilentlyContinue | ForEach-Object { $_.PSChildName })
    $profiles = @(Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\*' -ErrorAction SilentlyContinue |
            Where-Object { $_.PSChildName -match '^S-1-5-21-[\d-]+$' -and $_.ProfileImagePath })
    foreach ($profile in $profiles) {
        if ($loaded -contains $profile.PSChildName) { continue }
        $file = Join-Path ([Environment]::ExpandEnvironmentVariables($profile.ProfileImagePath)) 'NTUSER.DAT'
        if (-not (Test-Path -LiteralPath $file)) { continue }
        $hive = "MDM_$($profile.PSChildName)"
        & reg.exe load "HKU\$hive" $file *> $null
        if ($LASTEXITCODE -ne 0) { continue }
        try {
            # Plain objects before the hive is unloaded: nothing may keep a handle on it.
            @(Get-ItemProperty -Path "Registry::HKEY_USERS\$hive\Software\Microsoft\Windows\CurrentVersion\Uninstall\*" -ErrorAction SilentlyContinue |
                Where-Object { $_.DisplayName -and $_.SystemComponent -ne 1 -and -not $_.ParentKeyName } |
                ForEach-Object {
                    [PSCustomObject]@{
                        Name      = ConvertTo-SecurityText $_.DisplayName 300
                        Version   = ConvertTo-SecurityText $_.DisplayVersion 100
                        Publisher = ConvertTo-SecurityText $_.Publisher 200
                        Source    = 'registry (user)'
                    }
                })
        }
        finally {
            [GC]::Collect()
            [GC]::WaitForPendingFinalizers()
            & reg.exe unload "HKU\$hive" *> $null
        }
    }
}

function Get-AppxSoftware {
    # The Store apps of every user (not the frameworks and not what belongs to Windows itself).
    Get-AppxPackage -AllUsers -ErrorAction Stop | Where-Object { -not $_.IsFramework -and "$($_.SignatureKind)" -ne 'System' -and $_.Name -and $_.Version } | ForEach-Object {
        [PSCustomObject]@{
            Name      = ConvertTo-SecurityText $_.Name 300
            Version   = ConvertTo-SecurityText "$($_.Version)" 100
            Publisher = ConvertTo-SecurityText ($(if ($_.PublisherId) { $_.PublisherId } else { $_.Publisher }) -replace '^CN=([^,]+).*$', '$1') 200
            Source    = 'appx'
        }
    } | Sort-Object -Property Name, Version -Unique
}

function Get-SecurityProcesses {
    param (
        [bool]$OnLinux,
        # The agent: it and what it starts (jobs, winget, ...) are left out.
        [int]$AgentPid
    )

    $processes = @()
    if ($OnLinux) {
        $users = @{}
        foreach ($line in @(Get-Content -Path /etc/passwd -ErrorAction SilentlyContinue)) {
            $entry = $line -split ':'
            if ($entry.Count -ge 3) { $users[$entry[2]] = $entry[0] }
        }
        foreach ($dir in @(Get-ChildItem -Path /proc -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^\d+$' })) {
            try {
                $commandLine = ([System.IO.File]::ReadAllText("$($dir.FullName)/cmdline") -replace "`0", ' ').Trim()
                # Kernel threads have no command line.
                if (-not $commandLine) { continue }
                $status = [System.IO.File]::ReadAllText("$($dir.FullName)/status")
                $parent = if ($status -match '(?m)^PPid:\s*(\d+)') { [int]$Matches[1] } else { 0 }
                $uid = if ($status -match '(?m)^Uid:\s*(\d+)') { $Matches[1] } else { $null }
                $exe = Get-Item -Path "$($dir.FullName)/exe" -Force -ErrorAction SilentlyContinue
                $path = if ($exe) { if ($exe.PSObject.Properties['LinkTarget'] -and $exe.LinkTarget) { $exe.LinkTarget } else { "$($exe.Target)" } } else { $null }
                $processes += [PSCustomObject]@{
                    Id          = [int]$dir.Name
                    Parent      = $parent
                    Name        = ([System.IO.File]::ReadAllText("$($dir.FullName)/comm")).Trim()
                    Path        = $path
                    CommandLine = $commandLine
                    User        = if ($users.ContainsKey($uid)) { $users[$uid] } else { $uid }
                }
            }
            catch {
                # Ended meanwhile.
            }
        }
    } else {
        $owners = @{}
        try { foreach ($process in @(Get-Process -IncludeUserName -ErrorAction SilentlyContinue)) { $owners[$process.Id] = $process.UserName } } catch { }
        $processes = @(Get-CimInstance -ClassName Win32_Process -Property ProcessId, ParentProcessId, Name, ExecutablePath, CommandLine -ErrorAction SilentlyContinue | ForEach-Object {
                [PSCustomObject]@{
                    Id          = [int]$_.ProcessId
                    Parent      = [int]$_.ParentProcessId
                    Name        = $_.Name
                    Path        = $_.ExecutablePath
                    CommandLine = $_.CommandLine
                    User        = $owners[[int]$_.ProcessId]
                }
            })
    }

    # The agent's own process tree; on Windows also the System Idle Process and System.
    $system = if ($OnLinux) { 0 } else { 4 }
    $excluded = @{}
    $queue = New-Object System.Collections.Queue
    $queue.Enqueue($AgentPid)
    while ($queue.Count -gt 0) {
        $id = $queue.Dequeue()
        if ($excluded.ContainsKey($id)) { continue }
        $excluded[$id] = $true
        foreach ($process in $processes) {
            if ($process.Parent -eq $id -and $process.Id -ne $id -and $process.Id -gt $system) { $queue.Enqueue($process.Id) }
        }
    }

    # One item per program and command line (browsers run dozens of the same).
    $processes | Where-Object { -not $excluded.ContainsKey($_.Id) -and $_.Id -gt $system } |
        Group-Object -Property Name, Path, CommandLine | Select-Object -First 1000 | ForEach-Object {
            $first = $_.Group[0]
            [PSCustomObject]@{
                Name        = ConvertTo-SecurityText $first.Name 260
                Path        = ConvertTo-SecurityText $first.Path 500
                CommandLine = ConvertTo-SecurityText $first.CommandLine 1000
                User        = ConvertTo-SecurityText (@($_.Group | Where-Object { $_.User } | Select-Object -ExpandProperty User -Unique) -join ', ') 200
                Count       = $_.Count
            }
        }
}

function Get-SecurityListening {
    param ([bool]$OnLinux)

    if ($OnLinux) {
        if (-not (Get-Command -Name ss -CommandType Application -ErrorAction SilentlyContinue)) { return }
        # tcp LISTEN 0 4096 127.0.0.53%lo:53 0.0.0.0:* users:(("systemd-resolve",pid=600,fd=14))
        ss -H -tulpn 2>$null | ForEach-Object {
            $parts = $_.Trim() -split '\s+', 7
            if ($parts.Count -lt 5 -or $parts[4] -notmatch '^(.*):(\d+|\*)$') { return }
            $address = $Matches[1] -replace '%.*$', '' -replace '^\[|\]$', ''
            $port = $Matches[2]
            if ($port -eq '*') { return }
            [PSCustomObject]@{
                Protocol = $parts[0]
                Address  = $address
                Port     = [int]$port
                Process  = if ($parts.Count -ge 7 -and $parts[6] -match 'users:\(\("([^"]+)"') { $Matches[1] } else { $null }
                Path     = $null
            }
        } | Sort-Object -Property Protocol, Address, Port -Unique
        return
    }

    $names = @{}
    $paths = @{}
    foreach ($process in @(Get-Process -ErrorAction SilentlyContinue)) {
        $names[$process.Id] = $process.ProcessName
        try { $paths[$process.Id] = $process.Path } catch { }
    }
    $rows = @()
    $rows += @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | ForEach-Object {
            [PSCustomObject]@{ Protocol = 'tcp'; Address = "$($_.LocalAddress)"; Port = [int]$_.LocalPort; Process = $names[[int]$_.OwningProcess]; Path = $paths[[int]$_.OwningProcess] }
        })
    # UDP without the ephemeral ports of clients (DNS lookups and the like).
    $rows += @(Get-NetUDPEndpoint -ErrorAction SilentlyContinue | Where-Object { $_.LocalPort -lt 49152 } | ForEach-Object {
            [PSCustomObject]@{ Protocol = 'udp'; Address = "$($_.LocalAddress)"; Port = [int]$_.LocalPort; Process = $names[[int]$_.OwningProcess]; Path = $paths[[int]$_.OwningProcess] }
        })
    $rows | Sort-Object -Property Protocol, Address, Port -Unique
}

function Get-SecurityStartup {
    param ([bool]$OnLinux)

    if ($OnLinux) {
        # Cron jobs (system and users), custom systemd units, rc.local, desktop autostart.
        $files = @('/etc/crontab', '/etc/rc.local') + @(Get-ChildItem -Path /etc/cron.d, /var/spool/cron/crontabs, /var/spool/cron -File -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName)
        foreach ($file in ($files | Select-Object -Unique)) {
            if (-not (Test-Path -Path $file -PathType Leaf)) { continue }
            $number = 0
            foreach ($line in @(Get-Content -Path $file -ErrorAction SilentlyContinue)) {
                $number++
                $text = $line.Trim()
                if (-not $text -or $text.StartsWith('#') -or $text -match '^[A-Za-z_][A-Za-z0-9_]*\s*=') { continue }
                if ($file -eq '/etc/rc.local' -and $text -match '^(exit 0|#!)') { continue }
                [PSCustomObject]@{
                    Name     = Split-Path -Leaf $file
                    Command  = ConvertTo-SecurityText $text
                    Location = "${file}:$number"
                    User     = if ($file -like '/var/spool/cron/*') { Split-Path -Leaf $file } else { $null }
                }
            }
        }
        foreach ($unit in @(Get-ChildItem -Path /etc/systemd/system -Filter '*.service' -File -ErrorAction SilentlyContinue)) {
            $exec = @(Select-String -Path $unit.FullName -Pattern '^\s*ExecStart\s*=\s*(.+)$' -ErrorAction SilentlyContinue | ForEach-Object { $_.Matches[0].Groups[1].Value.Trim() })
            $user = Select-String -Path $unit.FullName -Pattern '^\s*User\s*=\s*(.+)$' -ErrorAction SilentlyContinue | Select-Object -First 1
            foreach ($command in $exec) {
                [PSCustomObject]@{ Name = $unit.BaseName; Command = ConvertTo-SecurityText $command; Location = $unit.FullName; User = if ($user) { $user.Matches[0].Groups[1].Value.Trim() } else { 'root' } }
            }
        }
        foreach ($entry in @(Get-ChildItem -Path /etc/xdg/autostart/*.desktop, /home/*/.config/autostart/*.desktop -File -ErrorAction SilentlyContinue)) {
            $exec = Select-String -Path $entry.FullName -Pattern '^Exec\s*=\s*(.+)$' -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($exec) {
                [PSCustomObject]@{ Name = $entry.BaseName; Command = ConvertTo-SecurityText $exec.Matches[0].Groups[1].Value; Location = $entry.FullName; User = if ($entry.FullName -match '^/home/([^/]+)/') { $Matches[1] } else { $null } }
            }
        }
        return
    }

    # Run keys of the machine and the loaded user profiles, the Startup folders and the scheduled
    # tasks that are not Windows' own.
    $keys = @(
        @{ Path = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Run'; User = $null },
        @{ Path = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\RunOnce'; User = $null },
        @{ Path = 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Run'; User = $null }
    )
    foreach ($hive in @(Get-ChildItem -Path Registry::HKEY_USERS -ErrorAction SilentlyContinue | Where-Object { $_.PSChildName -match '^S-1-5-21-[\d-]+$' })) {
        $user = try { (New-Object System.Security.Principal.SecurityIdentifier($hive.PSChildName)).Translate([System.Security.Principal.NTAccount]).Value } catch { $hive.PSChildName }
        $keys += @{ Path = "Registry::HKEY_USERS\$($hive.PSChildName)\Software\Microsoft\Windows\CurrentVersion\Run"; User = $user }
        $keys += @{ Path = "Registry::HKEY_USERS\$($hive.PSChildName)\Software\Microsoft\Windows\CurrentVersion\RunOnce"; User = $user }
    }
    foreach ($key in $keys) {
        $item = Get-ItemProperty -Path $key.Path -ErrorAction SilentlyContinue
        if (-not $item) { continue }
        foreach ($property in $item.PSObject.Properties) {
            if ($property.Name -like 'PS*') { continue }
            [PSCustomObject]@{ Name = $property.Name; Command = ConvertTo-SecurityText $property.Value; Location = ($key.Path -replace '^Registry::', '' -replace '^HKEY_USERS\\S-1-5-21-[\d-]+', 'HKCU'); User = $key.User }
        }
    }
    $folders = @("$env:ProgramData\Microsoft\Windows\Start Menu\Programs\StartUp") + @(Get-ChildItem -Path "$env:SystemDrive\Users\*\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup" -Directory -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName)
    foreach ($file in @(Get-ChildItem -Path $folders -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'desktop.ini' })) {
        $target = $null
        if ($file.Extension -eq '.lnk') {
            try { $target = (New-Object -ComObject WScript.Shell).CreateShortcut($file.FullName); $target = "$($target.TargetPath) $($target.Arguments)" } catch { $target = $null }
        }
        [PSCustomObject]@{ Name = $file.Name; Command = ConvertTo-SecurityText $(if ($target) { $target } else { $file.FullName }); Location = $file.DirectoryName; User = if ($file.FullName -match '\\Users\\([^\\]+)\\') { $Matches[1] } else { $null } }
    }
    foreach ($task in @(Get-ScheduledTask -ErrorAction SilentlyContinue | Where-Object { $_.TaskPath -notlike '\Microsoft\*' -and $_.State -ne 'Disabled' })) {
        foreach ($action in @($task.Actions | Where-Object { $_.Execute })) {
            [PSCustomObject]@{ Name = $task.TaskName; Command = ConvertTo-SecurityText "$($action.Execute) $($action.Arguments)"; Location = "Task Scheduler $($task.TaskPath)"; User = $task.Principal.UserId }
        }
    }
}

function Get-SecurityOs {
    # The operating system: its Id ('windows', or the ID of /etc/os-release: ubuntu, debian, ...), its Name (the caption,
    # PRETTY_NAME), its Version (Windows: the build with the revision, 10.0.19045.5555, which is what the cumulative
    # updates of Microsoft change and the rules of their security updates compare; Linux: VERSION_ID, 26.04) and on Linux
    # its Kernel. One item, none when the system does not tell what it is.
    param ([bool]$OnLinux)

    if ($OnLinux) {
        $osRelease = @{}
        foreach ($line in @(Get-Content -Path /etc/os-release -ErrorAction SilentlyContinue)) {
            if ($line -match '^(\w+)=(.*)$') { $osRelease[$Matches[1]] = $Matches[2].Trim('"') }
        }
        if (-not $osRelease['ID']) { return }
        [PSCustomObject]@{
            Id      = ConvertTo-SecurityText $osRelease['ID'] 100
            Name    = ConvertTo-SecurityText $osRelease['PRETTY_NAME'] 300
            Version = ConvertTo-SecurityText $osRelease['VERSION_ID'] 100
            Kernel  = ConvertTo-SecurityText "$(uname -r 2>$null)" 100
            # The release (noble, resolute): what the security tracker of the distribution is organised by.
            Codename = ConvertTo-SecurityText $osRelease['VERSION_CODENAME'] 50
        }
        return
    }
    $os = Get-CimInstance -ClassName Win32_OperatingSystem -Property Caption, Version -ErrorAction Stop
    $revision = (Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -Name UBR -ErrorAction Stop).UBR
    # The revision is missing on very old systems: the build alone would say less than it seems to.
    if ("$($os.Version)" -match '^\d+\.\d+\.\d+$' -and "$revision" -match '^\d+$') {
        [PSCustomObject]@{ Id = 'windows'; Name = ConvertTo-SecurityText $os.Caption 300; Version = "$($os.Version).$revision"; Kernel = $null; Codename = $null }
    }
}

function Get-SecurityAdmins {
    param ([bool]$OnLinux)

    if ($OnLinux) {
        # uid 0 and the members of the groups that may use sudo.
        $shells = @{}
        foreach ($line in @(Get-Content -Path /etc/passwd -ErrorAction SilentlyContinue)) {
            $entry = $line -split ':'
            if ($entry.Count -lt 7) { continue }
            $shells[$entry[0]] = $entry[6]
            if ($entry[2] -eq '0') { [PSCustomObject]@{ Name = $entry[0]; Source = 'uid 0'; Enabled = $entry[6] -notmatch '(nologin|false)$' } }
        }
        foreach ($group in 'sudo', 'wheel', 'admin') {
            $entry = "$(getent group $group 2>$null)" -split ':'
            if ($entry.Count -lt 4 -or -not $entry[3]) { continue }
            foreach ($name in ($entry[3] -split ',' | Where-Object { $_ })) {
                [PSCustomObject]@{ Name = $name; Source = "group $group"; Enabled = -not ("$($shells[$name])" -match '(nologin|false)$') }
            }
        }
        return
    }

    $sid = 'S-1-5-32-544'
    $members = @()
    try {
        $members = @(Get-LocalGroupMember -SID $sid -ErrorAction Stop | ForEach-Object { @{ Name = "$($_.Name)"; Source = "$($_.PrincipalSource)"; Sid = "$($_.SID)" } })
    }
    catch {
        # Get-LocalGroupMember fails on members it cannot resolve (removed domain or Entra ID accounts).
        try {
            $group = (New-Object System.Security.Principal.SecurityIdentifier($sid)).Translate([System.Security.Principal.NTAccount]).Value.Split('\')[-1]
            $members = @(([ADSI]"WinNT://./$group,group").psbase.Invoke('Members') | ForEach-Object {
                    $path = $_.GetType().InvokeMember('ADsPath', 'GetProperty', $null, $_, $null)
                    @{ Name = ($path -replace '^WinNT://', '' -replace '/', '\'); Source = if ($path -match "^WinNT://$([regex]::Escape($env:COMPUTERNAME))/") { 'Local' } else { 'Domain' }; Sid = $null }
                })
        }
        catch { }
    }
    $local = @{}
    try { foreach ($user in @(Get-LocalUser -ErrorAction Stop)) { $local["$($user.SID)"] = $user.Enabled } } catch { }
    foreach ($member in $members) {
        [PSCustomObject]@{ Name = $member.Name; Source = $member.Source; Enabled = if ($member.Sid -and $local.ContainsKey($member.Sid)) { [bool]$local[$member.Sid] } else { $null } }
    }
}

function Get-SecurityPosture {
    param (
        [bool]$OnLinux,
        [bool]$InContainer,
        # What the last apt-get update of the agent said (Get-AptUpdates writes it).
        [string]$AptStatePath = ''
    )

    $posture = @{}
    if ($OnLinux) {
        if (-not $InContainer) {
            $firewall = Get-LinuxFirewall
            $posture.FirewallEnabled = $firewall.Enabled
            $posture.FirewallDetail = $firewall.Detail
            if (Get-Command -Name findmnt -CommandType Application -ErrorAction SilentlyContinue) {
                $unencrypted = Get-LinuxUnencryptedMounts
                if ($null -ne $unencrypted) {
                    $posture.DiskEncrypted = @($unencrypted).Count -eq 0
                    if (@($unencrypted).Count -gt 0) { $posture.UnencryptedMounts = @($unencrypted) -join ', ' }
                }
            }
        }
        $apt = Get-AptUpdateState -Path $AptStatePath
        if ($apt) {
            $posture.AptUpdateOk = $apt.Ok
            if (-not $apt.Ok) { $posture.AptUpdateErrors = $apt.Errors }
        }
        $efi = @(Get-ChildItem -Path '/sys/firmware/efi/efivars/SecureBoot-*' -ErrorAction SilentlyContinue | Select-Object -First 1)
        if ($efi) {
            try { $bytes = [System.IO.File]::ReadAllBytes($efi[0].FullName); $posture.SecureBoot = $bytes[-1] -eq 1 } catch { }
        }
        $sshd = Get-Command -Name sshd -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($sshd -and (Test-Path -Path /etc/ssh/sshd_config)) {
            $config = @(& $sshd.Source -T 2>$null)
            if ($LASTEXITCODE -eq 0) {
                foreach ($line in $config) {
                    if ($line -match '^permitrootlogin\s+(\S+)') { $posture.SshRootLogin = $Matches[1] -replace '^without-password$', 'prohibit-password' }
                    if ($line -match '^passwordauthentication\s+(\S+)') { $posture.SshPasswordAuthentication = $Matches[1] -eq 'yes' }
                }
            }
        }
        if (Test-Path -Path /etc/apt/apt.conf.d) {
            $periodic = (Get-Content -Path /etc/apt/apt.conf.d/* -Raw -ErrorAction SilentlyContinue) -join "`n"
            $posture.AutomaticUpdates = $periodic -match 'APT::Periodic::Unattended-Upgrade\s+"(1|true)"'
            # The last upgrade apt recorded (its history is rotated monthly, the older ones are gzipped).
            foreach ($log in @(Get-ChildItem -Path /var/log/apt/history.log* -File -ErrorAction SilentlyContinue | Sort-Object -Property LastWriteTime -Descending)) {
                try {
                    $text = if ($log.Extension -eq '.gz') {
                        $stream = New-Object System.IO.Compression.GZipStream([System.IO.File]::OpenRead($log.FullName), [System.IO.Compression.CompressionMode]::Decompress)
                        try { (New-Object System.IO.StreamReader($stream)).ReadToEnd() } finally { $stream.Dispose() }
                    } else { [System.IO.File]::ReadAllText($log.FullName) }
                }
                catch { continue }
                $last = $null
                foreach ($block in ($text -split '(?m)^\s*$')) {
                    if ($block -match '(?m)^Upgrade:' -and $block -match '(?m)^Start-Date:\s*(\d{4}-\d{2}-\d{2})') { $last = [datetime]$Matches[1] }
                }
                if ($last) { $posture.DaysSinceUpdate = [int]((Get-Date) - $last).TotalDays; break }
            }
        }
        return $posture
    }

    try {
        $profiles = @(Get-NetFirewallProfile -PolicyStore ActiveStore -ErrorAction Stop)
        $posture.FirewallEnabled = $profiles.Count -gt 0 -and -not (@($profiles | ForEach-Object { "$($_.Enabled)" }) -contains 'False')
    }
    catch { }

    # The antivirus Windows Security Center knows (workstations), Microsoft Defender's own status.
    $defender = try { Get-MpComputerStatus -ErrorAction Stop } catch { $null }
    $products = @(try { Get-CimInstance -Namespace root/SecurityCenter2 -ClassName AntivirusProduct -ErrorAction Stop } catch { })
    $active = $null
    foreach ($product in $products) {
        $state = '{0:X6}' -f [int]$product.productState
        $item = @{ Name = "$($product.displayName)"; Enabled = @('10', '11') -contains $state.Substring(2, 2); UpToDate = $state.Substring(4, 2) -eq '00' }
        if (-not $active -or ($item.Enabled -and -not $active.Enabled) -or ($item.Enabled -and $active.Name -match 'Defender')) { $active = $item }
    }
    if ($active -and -not ($active.Name -match 'Defender' -and $defender)) {
        $posture.AntivirusName = $active.Name
        $posture.AntivirusEnabled = $active.Enabled
        $posture.AntivirusUpToDate = $active.UpToDate
    } elseif ($defender) {
        $posture.AntivirusName = 'Microsoft Defender Antivirus'
        # Passive (another antivirus protects the device) or not running: not known to be off.
        if (-not "$($defender.AMRunningMode)" -or "$($defender.AMRunningMode)" -eq 'Normal') {
            $posture.AntivirusEnabled = [bool]$defender.AntivirusEnabled
            $posture.RealTimeProtection = [bool]$defender.RealTimeProtectionEnabled
            $posture.AntivirusUpToDate = $defender.AntivirusSignatureAge -le 7
        }
    } elseif ($products.Count -eq 0 -and (Get-CimInstance -ClassName Win32_OperatingSystem -Property ProductType).ProductType -eq 1) {
        # A workstation that knows no antivirus at all.
        $posture.AntivirusEnabled = $false
    }

    try {
        $volume = Get-CimInstance -Namespace root/cimv2/Security/MicrosoftVolumeEncryption -ClassName Win32_EncryptableVolume -Filter "DriveLetter = '$env:SystemDrive'" -ErrorAction Stop
        if ($volume) {
            $posture.DiskEncrypted = [int]$volume.ProtectionStatus -eq 1
            if (-not $posture.DiskEncrypted) { $posture.UnencryptedMounts = "$env:SystemDrive" }
        }
    }
    catch { }
    try { $posture.SecureBoot = [bool](Confirm-SecureBootUEFI -ErrorAction Stop) } catch { }

    $terminal = Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server' -ErrorAction SilentlyContinue
    if ($terminal) { $posture.RdpEnabled = [int]$terminal.fDenyTSConnections -eq 0 }
    $rdp = Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp' -ErrorAction SilentlyContinue
    if ($rdp -and $null -ne $rdp.UserAuthentication) { $posture.RdpNla = [int]$rdp.UserAuthentication -eq 1 }
    try { $posture.Smb1Enabled = [bool](Get-SmbServerConfiguration -ErrorAction Stop).EnableSMB1Protocol } catch { }
    $system = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System' -ErrorAction SilentlyContinue
    if ($system) { $posture.UacEnabled = $null -eq $system.EnableLUA -or [int]$system.EnableLUA -ne 0 }
    try {
        $guest = Get-LocalUser -ErrorAction Stop | Where-Object { "$($_.SID)" -like '*-501' } | Select-Object -First 1
        if ($guest) { $posture.GuestEnabled = [bool]$guest.Enabled }
    }
    catch { }
    $winlogon = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon' -ErrorAction SilentlyContinue
    if ($winlogon) { $posture.AutoLogon = "$($winlogon.AutoAdminLogon)" -eq '1' -and $null -ne $winlogon.PSObject.Properties['DefaultPassword'] }
    $au = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU' -ErrorAction SilentlyContinue
    $posture.AutomaticUpdates = -not ($au -and [int]$au.NoAutoUpdate -eq 1)
    $installed = @(Get-HotFix -ErrorAction SilentlyContinue | Where-Object { $_.InstalledOn } | Sort-Object -Property InstalledOn -Descending | Select-Object -First 1)
    if ($installed) { $posture.DaysSinceUpdate = [int]((Get-Date) - $installed[0].InstalledOn).TotalDays }

    return $posture
}

function Get-SecurityLogs {
    # The raw records of the logs the parsers of the server read, from the position the server
    # acknowledged last, only the ones the collection policy asks for (the identifiers / event ids
    # of the enabled parsers: filtered here, so a busy log is not sent whole). The agent does not
    # interpret them. Returns the logs as columns (field names once) and the new positions.
    param (
        [bool]$OnLinux,
        # Where to start without a position: this far back.
        [datetime]$Since,
        # Position per log from the previous collection the server took.
        $Cursors = @{},
        # What to send: the values of the identifier / event id per log, null for all, none for none.
        $Policy,
        [int]$MaxRecords = 20000,
        # About this many bytes of one Windows log per collection.
        [int]$LogBytes = 3MB
    )

    $logs = New-Object System.Collections.Generic.List[object]
    $moved = @{}
    # What the policy asks for of a log: All, or the Ids (none when the log is not listed).
    $wanted = {
        param ($Source)
        $property = if ($Policy -and $Policy.sources) { $Policy.sources.PSObject.Properties[$Source] } else { $null }
        if ($property -and $null -eq $property.Value) { return [pscustomobject]@{ All = $true; Ids = @() } }
        return [pscustomobject]@{ All = $false; Ids = @(if ($property) { $property.Value }) }
    }

    if ($OnLinux) {
        $want = & $wanted 'linux.auth'
        if (-not $want.All -and $want.Ids.Count -eq 0) { return @{ logs = @(); cursors = @{} } }
        $ids = if ($want.All) { $null } else { $want.Ids }
        $rows = New-Object System.Collections.Generic.List[object]
        $cursor = $Cursors['linux.auth']
        if (Get-Command -Name journalctl -CommandType Application -ErrorAction SilentlyContinue) {
            # auth and authpriv: sshd, sudo, su, useradd, usermod, gpasswd, PAM.
            $arguments = @('-q', '--no-pager', '-o', 'json', '--output-fields=MESSAGE,SYSLOG_IDENTIFIER,_PID')
            $arguments += $(if ($cursor) { "--after-cursor=$cursor" } else { "--since=@$([int64]([DateTimeOffset]$Since).ToUnixTimeSeconds())" })
            $arguments += @('SYSLOG_FACILITY=4', 'SYSLOG_FACILITY=10')
            if ($ids) { $arguments += @($ids | ForEach-Object { "SYSLOG_IDENTIFIER=$_" }) }
            $last = $null
            foreach ($line in @(journalctl @arguments 2>$null | Select-Object -First $MaxRecords)) {
                try { $entry = $line | ConvertFrom-Json } catch { continue }
                $last = $entry.__CURSOR
                # A message that is not valid UTF-8 comes as a list of bytes.
                $message = if ($entry.MESSAGE -is [string]) { $entry.MESSAGE } else { try { [System.Text.Encoding]::UTF8.GetString([byte[]]@($entry.MESSAGE)) } catch { $null } }
                if (-not $message) { continue }
                $rows.Add([object[]]@([DateTimeOffset]::FromUnixTimeMilliseconds([int64]([int64]$entry.__REALTIME_TIMESTAMP / 1000)).UtcDateTime.ToString('o'), $entry.SYSLOG_IDENTIFIER, $(if ($entry._PID) { [int]$entry._PID } else { $null }), (ConvertTo-SecurityText $message 2000)))
            }
            if ($last) { $moved['linux.auth'] = $last }
        }
        else {
            # Without a journal: auth.log / secure, read from the byte the previous collection stopped at
            # (a new file, after a rotation, is read from its start). The position is "offset:start of the file".
            $path = @('/var/log/auth.log', '/var/log/secure') | Where-Object { Test-Path -Path $_ } | Select-Object -First 1
            if ($path) {
                $stream = [System.IO.File]::Open($path, 'Open', 'Read', 'ReadWrite')
                try {
                    $head = New-Object byte[] ([Math]::Min(128, $stream.Length))
                    [void]$stream.Read($head, 0, $head.Length)
                    $mark = Get-SecurityShortHash ([System.Convert]::ToBase64String($head))
                    $offset = [int64]0
                    if ($cursor -match '^(\d+):(\w+)$' -and $Matches[2] -eq $mark -and [int64]$Matches[1] -le $stream.Length) { $offset = [int64]$Matches[1] }
                    elseif (-not $cursor) { $offset = [Math]::Max(0, $stream.Length - 8MB) }
                    [void]$stream.Seek($offset, 'Begin')
                    $buffer = New-Object byte[] ([int][Math]::Min(8MB, $stream.Length - $offset))
                    $read = $stream.Read($buffer, 0, $buffer.Length)
                    # Only whole lines: the rest is read next time.
                    $end = [Array]::LastIndexOf($buffer, [byte]10, [Math]::Max(0, $read - 1))
                    $consumed = if ($end -ge 0 -and $read -gt 0) { $end + 1 } else { 0 }
                    $text = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $consumed)
                    foreach ($line in ($text -split "`n")) {
                        $time = $null
                        $rest = $null
                        # "2026-10-05T10:00:01.123+02:00 host sshd[123]: ..." or "Oct  5 10:00:01 host sshd[123]: ..."
                        if ($line -match '^(\d{4}-\d{2}-\d{2}T[\d:.]+([+-]\d{2}:?\d{2}|Z)?)\s+\S+\s+(.*)$') {
                            $parsed = [DateTimeOffset]::MinValue
                            if ([DateTimeOffset]::TryParse($Matches[1], [ref]$parsed)) { $time = $parsed.LocalDateTime }
                            $rest = $Matches[3]
                        } elseif ($line -match '^([A-Z][a-z]{2})\s+(\d{1,2}) (\d{2}:\d{2}:\d{2})\s+\S+\s+(.*)$') {
                            $rest = $Matches[4]
                            $parsed = [datetime]::MinValue
                            if ([datetime]::TryParseExact("$($Matches[1]) $($Matches[2]) $((Get-Date).Year) $($Matches[3])", 'MMM d yyyy HH:mm:ss', [System.Globalization.CultureInfo]::InvariantCulture, 'AllowWhiteSpaces', [ref]$parsed)) {
                                $time = if ($parsed -gt (Get-Date).AddDays(1)) { $parsed.AddYears(-1) } else { $parsed }
                            }
                        }
                        if (-not $time -or $time -lt $Since -or $rest -notmatch '^([^\s\[:]+)(\[(\d+)\])?:\s?(.*)$') { continue }
                        if ($ids -and @($ids) -notcontains $Matches[1]) { continue }
                        if ($rows.Count -ge $MaxRecords) { break }
                        $rows.Add([object[]]@($time.ToUniversalTime().ToString('o'), $Matches[1], $(if ($Matches[3]) { [int]$Matches[3] } else { $null }), (ConvertTo-SecurityText $Matches[4] 2000)))
                    }
                    $moved['linux.auth'] = "$($offset + $consumed):$mark"
                }
                finally { $stream.Dispose() }
            }
        }
        if ($rows.Count -gt 0) { $logs.Add(@{ source = 'linux.auth'; fields = @('Time', 'Identifier', 'Pid', 'Message'); rows = $rows.ToArray() }) }
        return @{ logs = $logs.ToArray(); cursors = $moved }
    }

    # Windows: the events the parsers read, each with its EventData (or UserData) as Data, from the
    # EventRecordID the previous collection stopped at (a day back at the first one); field names
    # without spaces ("Threat Name" is ThreatName).
    $channels = @(
        @{ Source = 'windows.security'; Log = 'Security' },
        @{ Source = 'windows.system'; Log = 'System' },
        @{ Source = 'windows.defender'; Log = 'Microsoft-Windows-Windows Defender/Operational' }
    )
    foreach ($channel in $channels) {
        $want = & $wanted $channel.Source
        if (-not $want.All -and $want.Ids.Count -eq 0) { continue }
        $ids = if ($want.All) { $null } else { $want.Ids }
        $conditions = @()
        if ($ids) { $conditions += '(' + ((@($ids) | ForEach-Object { "EventID=$([int]$_)" }) -join ' or ') + ')' }
        $position = $Cursors[$channel.Source]
        $conditions += $(if ($position) { "EventRecordID>$([int64]$position)" } else { 'TimeCreated[timeDiff(@SystemTime) <= 86400000]' })
        $events = @(try { Get-WinEvent -LogName $channel.Log -FilterXPath "*[System[$($conditions -join ' and ')]]" -MaxEvents $MaxRecords -Oldest -ErrorAction Stop } catch { })
        if ($events.Count -eq 0) { continue }
        $rows = New-Object System.Collections.Generic.List[object]
        # At most about LogBytes of a log per collection (the server takes 16 MB unpacked): the
        # position moves to the last record sent, the rest comes with the next collection.
        $size = 0
        $last = $null
        foreach ($record in $events) {
            if ($size -ge $LogBytes) { break }
            $data = @{}
            try {
                $xml = [xml]$record.ToXml()
                $items = if ($xml.Event.EventData) { @($xml.Event.EventData.Data) } else { @() }
                foreach ($item in $items) {
                    if ($item -is [System.Xml.XmlElement] -and $item.GetAttribute('Name')) { $data[($item.GetAttribute('Name') -replace '\s', '')] = ConvertTo-SecurityText $item.InnerText 1000 }
                }
                if ($xml.Event.UserData) {
                    foreach ($node in @($xml.Event.UserData.ChildNodes)) {
                        foreach ($child in @($node.ChildNodes)) { if ($child -is [System.Xml.XmlElement]) { $data[$child.LocalName] = ConvertTo-SecurityText $child.InnerText 1000 } }
                    }
                }
            }
            catch { }
            $rows.Add([object[]]@($record.TimeCreated.ToUniversalTime().ToString('o'), [int]$record.Id, $record.ProviderName, [int]$record.Level, $data))
            $size += 100 + "$($record.ProviderName)".Length
            foreach ($entry in $data.GetEnumerator()) { $size += 6 + $entry.Key.Length + "$($entry.Value)".Length }
            $last = $record.RecordId
        }
        $moved[$channel.Source] = [int64]$last
        $logs.Add(@{ source = $channel.Source; fields = @('Time', 'Id', 'Provider', 'Level', 'Data'); rows = $rows.ToArray() })
    }

    return @{ logs = $logs.ToArray(); cursors = $moved }
}

function Get-SecurityInventory {
    param (
        [bool]$OnLinux,
        [string]$CachePath = '',
        [datetime]$Since,
        [int]$AgentPid,
        [bool]$InContainer,
        # security_logs is on in config.json: without it the logs are not even read.
        [bool]$SendLogs = $false,
        # The collection policy of the server and the positions of the logs (JSON), see Get-SecurityLogs.
        [string]$PolicyJson = '',
        [string]$CursorsJson = '',
        [string]$AptStatePath = ''
    )

    $inventory = @{}
    try { $inventory['software'] = @(Get-SecuritySoftware -OnLinux $OnLinux -CachePath $CachePath | Select-Object -First 5000) } catch { }
    # As many as the server takes of each list (more and it refuses the whole collection).
    try { $inventory['processes'] = @(Get-SecurityProcesses -OnLinux $OnLinux -AgentPid $AgentPid | Select-Object -First 1000) } catch { }
    try { $inventory['listening'] = @(Get-SecurityListening -OnLinux $OnLinux | Select-Object -First 1000) } catch { }
    try { $inventory['startup'] = @(Get-SecurityStartup -OnLinux $OnLinux | Select-Object -First 500) } catch { }
    try { $inventory['admins'] = @(Get-SecurityAdmins -OnLinux $OnLinux | Select-Object -First 200) } catch { }
    try { $inventory['os'] = @(Get-SecurityOs -OnLinux $OnLinux | Select-Object -First 1) } catch { }
    try { $inventory['posture'] = Get-SecurityPosture -OnLinux $OnLinux -InContainer $InContainer -AptStatePath $AptStatePath } catch { }
    try { Add-SecuritySoftwareContext -Software $inventory['software'] -Processes $inventory['processes'] -Listening $inventory['listening'] } catch { }
    $records = @()
    $moved = @{}
    if ($SendLogs -and $PolicyJson) {
        try {
            $cursors = @{}
            if ($CursorsJson) { foreach ($property in ($CursorsJson | ConvertFrom-Json).PSObject.Properties) { $cursors[$property.Name] = $property.Value } }
            $result = Get-SecurityLogs -OnLinux $OnLinux -Since $Since -Cursors $cursors -Policy ($PolicyJson | ConvertFrom-Json)
            $records = @($result.logs)
            $moved = $result.cursors
        }
        catch { }
    }

    return @{ inventory = $inventory; logs = $records; cursors = $moved }
}

#endregion

function Get-PowerShellHosts {
    param (
        [bool]
        $OnLinux
    )

    # Windows PowerShell and PowerShell 7 keep their modules apart, both are checked.
    $hosts = @()
    if (-not $OnLinux) {
        $hosts += @{ Edition = 'Windows PowerShell'; Path = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" }
    }
    $pwsh = Get-Command -Name pwsh -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    $pwshPath = if ($pwsh) { $pwsh.Source } elseif (-not $OnLinux) { "$env:ProgramFiles\PowerShell\7\pwsh.exe" }
    if ($pwshPath) {
        $hosts += @{ Edition = 'PowerShell 7'; Path = $pwshPath }
    }

    return @($hosts | Where-Object { Test-Path -Path $_.Path })
}

function Invoke-PowerShellModules {
    param (
        [bool]
        $OnLinux,
        # Install the newer versions instead of only listing them.
        [switch]
        $Update,
        # Only these modules of this edition and owner (installUpdate), '' for the system-wide ones.
        [string[]]
        $Names,
        [string]
        $Edition,
        [string]
        $User,
        # With -Names: the version to install (known from the inventory), so the gallery is not
        # searched again for every installed module.
        [string]
        $Version,
        # Gets the percent (0-100) and a message while modules are updated.
        [scriptblock]
        $OnProgress
    )

    # Runs in each PowerShell edition. Only modules installed from the PowerShell Gallery with
    # Install-Module are considered; nothing ever prompts (-NonInteractive, no NuGet bootstrap).
    $moduleScript = @'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
function ConvertTo-ModuleVersion ($Value) {
    $base = ("$Value" -split '-')[0]
    if ($base -notmatch '\.') { $base += '.0' }
    try { [version]$base } catch { [version]'0.0' }
}
function Write-MdmProgress ([int]$Percent, [string]$Message) {
    # Read by the agent while the process runs (the output is only read at the end).
    if ($ProgressPath) { try { Set-Content -Path $ProgressPath -Value "$Percent|$Message" -Encoding UTF8 } catch { } }
}
$outdated = @()
$canCheck = (Get-Command -Name Get-InstalledModule -ErrorAction SilentlyContinue) -and (Get-Command -Name Find-Module -ErrorAction SilentlyContinue)
# PowerShellGet 1 (Windows PowerShell) would ask to install the NuGet provider first.
if ($canCheck -and $PSVersionTable.PSEdition -ne 'Core' -and -not (Get-PackageProvider -ListAvailable -Name NuGet -ErrorAction SilentlyContinue)) { $canCheck = $false }
if ($canCheck) {
    Write-MdmProgress 5 'Reading the installed modules'
    # Only the modules asked for (installUpdate): listing all of them (Microsoft.Graph alone has
    # dozens) and searching the gallery for each would take minutes.
    $found = if ($Names) { Get-InstalledModule -Name $Names -ErrorAction SilentlyContinue } else { Get-InstalledModule -ErrorAction SilentlyContinue }
    $installed = @($found | Where-Object { $_.Repository -eq 'PSGallery' } |
        ForEach-Object { [pscustomobject]@{ Name = $_.Name; Version = "$($_.Version)"; User = $null } })
    # Modules users installed for themselves (Install-Module -Scope CurrentUser) are not visible to
    # SYSTEM / root: read their PowerShellGet metadata. They are listed, not updated (only the
    # user can update their own profile).
    $userModules = if ($IsLinux) {
        @(Get-ChildItem -Path '/home/*/.local/share/powershell/Modules/*/*/PSGetModuleInfo.xml' -ErrorAction SilentlyContinue)
    } else {
        $folder = if ($PSVersionTable.PSEdition -eq 'Core') { 'PowerShell' } else { 'WindowsPowerShell' }
        @(Get-ChildItem -Path "$env:SystemDrive\Users\*\Documents\$folder\Modules\*\*\PSGetModuleInfo.xml", "$env:SystemDrive\Users\*\OneDrive*\Documents\$folder\Modules\*\*\PSGetModuleInfo.xml" -ErrorAction SilentlyContinue)
    }
    if ($Names) { $userModules = @($userModules | Where-Object { $Names -contains $_.Directory.Parent.Name }) }
    foreach ($file in $userModules) {
        try {
            $info = Import-Clixml -Path $file.FullName
            $user = ($file.FullName -replace '\\', '/' -split '/')[2]
            if ($info.Repository -eq 'PSGallery') { $installed += [pscustomobject]@{ Name = $info.Name; Version = "$($info.Version)"; User = $user } }
        }
        catch { }
    }
    # The newest version per module and owner (side-by-side versions).
    $installed = @($installed | Group-Object -Property Name, User | ForEach-Object { $_.Group | Sort-Object -Property { ConvertTo-ModuleVersion $_.Version } -Descending | Select-Object -First 1 })
    if ($installed) {
        $latest = @{}
        if ($Names -and $TargetVersion) {
            # The version the portal offered, found by the last inventory.
            foreach ($name in $Names) { $latest[$name] = $TargetVersion }
        } else {
            Write-MdmProgress 10 'Searching the PowerShell Gallery'
            foreach ($module in @(Find-Module -Name @($installed.Name | Select-Object -Unique) -Repository PSGallery -ErrorAction SilentlyContinue)) { $latest[$module.Name] = "$($module.Version)" }
        }
        foreach ($module in $installed) {
            $available = $latest[$module.Name]
            if ($available -and (ConvertTo-ModuleVersion $available) -gt (ConvertTo-ModuleVersion $module.Version)) {
                $outdated += [pscustomobject]@{ Name = $module.Name; Version = $module.Version; Available = $available; User = $module.User }
            }
        }
    }
}
if ($Names) {
    $outdated = @($outdated | Where-Object { $Names -contains $_.Name -and "$($_.User)" -eq "$OnlyUser" })
}
if ($Update) {
    # Installed next to the current version, like Update-Module always does; failed ones stay listed
    # with the reason. Users' own modules are left to the caller (updated as that user).
    $remaining = @()
    $done = 0
    $count = @($outdated | Where-Object { -not $_.User }).Count
    foreach ($module in $outdated) {
        if ($module.User) { $remaining += $module; continue }
        Write-MdmProgress (15 + [int](80 * $done / [Math]::Max(1, $count))) "Update-Module $($module.Name) $($module.Version) -> $($module.Available)"
        $done++
        # Install-Module next to the current version is what Update-Module does, without its
        # problems: it reads the dates of the installation, which modules not installed with
        # Install-Module lack (Microsoft.WinGet.Client: "Cannot convert null to type
        # System.DateTime"), and it cannot skip the publisher check (a module signed with a new
        # certificate). Update-Module is the fallback.
        $errors = @()
        try {
            $install = @{ Name = $module.Name; RequiredVersion = $module.Available; Repository = 'PSGallery'; Scope = 'AllUsers'; Force = $true; Confirm = $false; ErrorAction = 'Stop'; WarningAction = 'SilentlyContinue' }
            $parameters = (Get-Command -Name Install-Module).Parameters
            foreach ($switch in 'AllowClobber', 'SkipPublisherCheck', 'AcceptLicense') { if ($parameters.ContainsKey($switch)) { $install[$switch] = $true } }
            Install-Module @install
        }
        catch {
            $errors += "Install-Module: $($_.Exception.Message)"
            try { Update-Module -Name $module.Name -RequiredVersion $module.Available -Force -Confirm:$false -ErrorAction Stop -WarningAction SilentlyContinue }
            catch { $errors += "Update-Module: $($_.Exception.Message)" }
        }
        # Only what is installed afterwards counts.
        if (-not (Get-InstalledModule -Name $module.Name -RequiredVersion $module.Available -ErrorAction SilentlyContinue)) {
            if (-not $errors) { $errors += "version $($module.Available) is not installed afterwards" }
            $module | Add-Member -NotePropertyName Error -NotePropertyValue ($errors -join '; ') -Force
            $remaining += $module
        }
    }
    $outdated = $remaining
}
# Into a file: Windows PowerShell writes warnings to the standard output when it is redirected,
# which would break the JSON.
Set-Content -Path $ResultPath -Value (ConvertTo-Json -InputObject @($outdated) -Compress) -Encoding UTF8
'@

    # Runs a script in a separate process (inherits the idle priority, stopped when the gallery
    # hangs) and returns the objects of its JSON output.
    $invoke = {
        param ([string]$FilePath, [string[]]$Prefix, [string]$Script, [int]$TimeoutMinutes = 15)
        $output = [System.IO.Path]::GetTempFileName()
        $errorOutput = [System.IO.Path]::GetTempFileName()
        $progress = [System.IO.Path]::GetTempFileName()
        $resultFile = [System.IO.Path]::GetTempFileName()
        Remove-Item -Path $resultFile -Force -ErrorAction SilentlyContinue
        $Script = "`$ProgressPath = '$($progress -replace "'", "''")'`n`$ResultPath = '$($resultFile -replace "'", "''")'`n$Script"
        $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($Script))
        try {
            $process = Start-Process -FilePath $FilePath -ArgumentList (@($Prefix) + @('-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded)) -RedirectStandardOutput $output -RedirectStandardError $errorOutput -NoNewWindow -PassThru
            $deadline = (Get-Date).AddMinutes($TimeoutMinutes)
            $last = $null
            while (-not $process.WaitForExit(2000)) {
                if ((Get-Date) -gt $deadline) {
                    try { & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null } catch { }
                    try { $process.Kill() } catch { }
                    # Not an empty result: the caller must not take it for "nothing to update".
                    return [pscustomobject]@{ TimedOut = $true; Minutes = $TimeoutMinutes }
                }
                $mark = (Get-Content -Path $progress -Raw -ErrorAction SilentlyContinue) -as [string]
                if ($OnProgress -and $mark -and $mark -ne $last -and $mark -match '^(\d+)\|(.*)$') {
                    $last = $mark
                    & $OnProgress ([int]$Matches[1]) $Matches[2].Trim()
                }
            }
            $json = (Get-Content -Path $resultFile -Raw -Encoding UTF8 -ErrorAction SilentlyContinue) -as [string]
            if (-not $json) {
                # Ended without a result: what it wrote tells why.
                $tail = @(Get-Content -Path $output, $errorOutput -ErrorAction SilentlyContinue | Where-Object { $_ -match '\S' } | Select-Object -Last 3) -join ' | '
                return [pscustomobject]@{ Failed = "$(Split-Path -Leaf $FilePath) ended without a result$(if ($tail) { ": $tail" })" }
            }
            # Windows PowerShell 5.1 outputs a JSON array as one object: enumerate it explicitly.
            foreach ($item in @($json.Trim() | ConvertFrom-Json | ForEach-Object { $_ })) { if ($item) { $item } }
        }
        catch {
            [pscustomobject]@{ Failed = "$(Split-Path -Leaf $FilePath): $($_.Exception.Message)" }
        }
        finally {
            Remove-Item -Path $output, $errorOutput, $progress, $resultFile -Force -ErrorAction SilentlyContinue
        }
    }

    $result = @()
    $quote = { param ($Value) "'" + ("$Value" -replace "'", "''") + "'" }
    foreach ($powershell in @(Get-PowerShellHosts -OnLinux $OnLinux | Where-Object { -not $Edition -or $_.Edition -eq $Edition })) {
        $prefix = if ($Update) { '$Update = $true' } else { '$Update = $false' }
        $prefix += "`n`$Names = @($(@($Names | ForEach-Object { & $quote $_ }) -join ', '))`n`$OnlyUser = $(& $quote $User)`n`$TargetVersion = $(& $quote $Version)"
        # Updates of big modules (Microsoft.Graph, Az) take long, listing does not.
        foreach ($module in @(& $invoke $powershell.Path @() "$prefix`n$moduleScript" $(if ($Update) { 45 } else { 15 }))) {
            if ($module.PSObject.Properties['TimedOut'] -or $module.PSObject.Properties['Failed']) {
                $reason = if ($module.PSObject.Properties['TimedOut']) { "Timed out after $($module.Minutes) minutes" } else { $module.Failed }
                if (-not $Update) { continue }
                $result += [PSCustomObject]@{ Name = $(if ($Names) { $Names -join ', ' } else { 'PowerShell modules' }); Version = $null; Available = $null; Edition = $powershell.Edition; User = $null; Error = $reason }
                continue
            }
            $result += [PSCustomObject]@{ Name = $module.Name; Version = $module.Version; Available = $module.Available; Edition = $powershell.Edition; User = $module.User; Error = $module.Error }
        }
    }

    if ($Update -and $OnLinux) {
        # Users' own modules (Install-Module defaults to CurrentUser in PowerShell 7) are updated as
        # that user, so they stay in the user's profile and owned by the user. Not possible on
        # Windows: SYSTEM cannot run as a user without the password, they stay listed.
        foreach ($group in @($result | Where-Object { $_.User -and (-not $User -or $_.User -eq $User) } | Group-Object -Property User, Edition)) {
            $first = $group.Group[0]
            $powershell = @(Get-PowerShellHosts -OnLinux $true | Where-Object { $_.Edition -eq $first.Edition }) | Select-Object -First 1
            if (-not $powershell -or $first.User -notmatch '^[a-z_][a-z0-9_.-]*$') { continue }
            $modules = ConvertTo-Json -InputObject @($group.Group | Select-Object -Property Name, Available) -Compress
            $userScript = @"
`$ProgressPreference = 'SilentlyContinue'
`$failed = @()
foreach (`$module in @(ConvertFrom-Json '$($modules -replace "'", "''")')) {
    try { Update-Module -Name `$module.Name -RequiredVersion `$module.Available -Force -Confirm:`$false -ErrorAction Stop }
    catch { `$failed += [pscustomobject]@{ Name = `$module.Name; Error = `$_.Exception.Message } }
}
Set-Content -Path `$ResultPath -Value (ConvertTo-Json -InputObject @(`$failed) -Compress) -Encoding UTF8
"@
            $asUser = Get-UserCommand -User $first.User
            if (-not $asUser) { continue }
            $failed = @{}
            foreach ($item in @(& $invoke $asUser[0] (@($asUser[1..($asUser.Count - 1)]) + $powershell.Path) $userScript 45)) {
                if ($item.PSObject.Properties['TimedOut']) { foreach ($module in $group.Group) { $failed[$module.Name] = "Timed out after $($item.Minutes) minutes" } }
                elseif ($item.PSObject.Properties['Failed']) { foreach ($module in $group.Group) { $failed[$module.Name] = $item.Failed } }
                else { $failed[$item.Name] = $item.Error }
            }
            foreach ($module in $group.Group) {
                if ($failed.ContainsKey($module.Name)) { $module.Error = $failed[$module.Name] } else { $result = @($result | Where-Object { $_ -ne $module }) }
            }
        }
    }

    return $result
}

function Get-Virtualization {
    param (
        # System manufacturer and model (SMBIOS), e.g. "QEMU" / "Standard PC (Q35 + ICH9, 2009)".
        [string]
        $Vendor,
        [string]
        $Product
    )

    # Names follow systemd-detect-virt. HypervisorPresent is not used: it is also set on physical
    # machines running Hyper-V or virtualization-based security.
    $identity = "$Vendor $Product"
    $name = switch -Regex ($identity) {
        'VMware' { 'vmware'; break }
        'VirtualBox|innotek' { 'oracle'; break }
        'Parallels' { 'parallels'; break }
        'Xen|HVM domU' { 'xen'; break }
        'QEMU|KVM|Standard PC \(|Proxmox' { 'kvm'; break }
        'Amazon EC2' { 'amazon'; break }
        'Google Compute Engine' { 'google'; break }
        'bhyve' { 'bhyve'; break }
        '^Microsoft Corporation Virtual Machine' { 'microsoft'; break }
    }
    if ($name) {
        return @{ Type = 'vm'; Name = $name }
    }

    return $null
}

#region Linux

function Get-LinuxVirtualization {
    # systemd-detect-virt knows most hypervisors and containers; a VM wins over a container in it.
    if (Get-Command -Name systemd-detect-virt -CommandType Application -ErrorAction SilentlyContinue) {
        foreach ($kind in @('vm', 'container')) {
            $name = "$(systemd-detect-virt --$kind 2>$null)".Trim()
            if ($LASTEXITCODE -eq 0 -and $name -and $name -ne 'none') {
                return @{ Type = $kind; Name = $name }
            }
        }
        return $null
    }

    $vendor = Get-Content -Path /sys/class/dmi/id/sys_vendor -ErrorAction SilentlyContinue
    $product = Get-Content -Path /sys/class/dmi/id/product_name -ErrorAction SilentlyContinue
    $vm = Get-Virtualization -Vendor $vendor -Product $product
    if ($vm) {
        return $vm
    }
    if ((Test-Path -Path /.dockerenv) -or (Test-Path -Path /run/.containerenv)) {
        return @{ Type = 'container'; Name = if (Test-Path -Path /run/.containerenv) { 'podman' } else { 'docker' } }
    }

    return $null
}

function Get-LinuxMachineInfo {
    $osRelease = @{}
    Get-Content -Path /etc/os-release | ForEach-Object {
        if ($_ -match '^(\w+)=(.*)$') { $osRelease[$Matches[1]] = $Matches[2].Trim('"') }
    }
    $cpu = Select-String -Path /proc/cpuinfo -Pattern '^model name\s*:\s*(.+)$' | Select-Object -First 1
    $battery = Get-ChildItem -Path /sys/class/power_supply -Filter 'BAT*' -ErrorAction SilentlyContinue | Select-Object -First 1
    $loggedOn = @(who 2>$null) | Select-Object -First 1

    [PSCustomObject]@{
        AgentVersion    = $AgentVersion
        Platform        = 'linux'
        Type            = Get-LinuxDeviceType -HasBattery ([bool]$battery)
        Virtualization  = Get-LinuxVirtualization
        Hostname        = [System.Net.Dns]::GetHostName()
        User            = [Environment]::UserName
        os              = $osRelease['PRETTY_NAME']
        uptime          = [int][double]((Get-Content -Path /proc/uptime -Raw).Split(' ')[0])
        last_logon_user = if ($loggedOn) { ($loggedOn -split '\s+')[0] } else { $null }
        Processor       = if ($cpu) { $cpu.Matches[0].Groups[1].Value.Trim() } else { $null }
        Cores           = [Environment]::ProcessorCount
        Battery         = if ($battery) { [int](Get-Content -Path "$($battery.FullName)/capacity") } else { $null }
        PluggedIn       = (Get-PowerStatus).plugged
        RestartRequired = Test-Path -Path /var/run/reboot-required
        Drives          = @(Get-LinuxDrives)
        Networks        = @(Get-LinuxNetworks)
    }
}

function Get-LinuxDeviceType {
    param (
        [bool]
        $HasBattery
    )

    # SMBIOS chassis types: 8-10, 14, 30-32 portable; 17, 23, 28, 29 server / rack / blade.
    $chassis = [int](Get-Content -Path /sys/class/dmi/id/chassis_type -ErrorAction SilentlyContinue)
    if ($HasBattery -or $chassis -in 8, 9, 10, 14, 30, 31, 32) {
        return 'laptop'
    }
    if ($chassis -in 17, 23, 28, 29) {
        return 'server'
    }

    # No desktop environment installed: a server (also covers VMs, which report "Other").
    $sessions = @('/usr/share/xsessions', '/usr/share/wayland-sessions') | Where-Object { (Get-ChildItem -Path $_ -ErrorAction SilentlyContinue | Measure-Object).Count -gt 0 }
    if (-not $sessions) {
        return 'server'
    }

    return 'desktop'
}

function Get-LinuxDrives {
    # Real block devices only (no tmpfs, overlays, snaps ...).
    df -B1 --output=source,size,avail,target -x tmpfs -x devtmpfs -x squashfs -x overlay -x efivarfs 2>$null | Select-Object -Skip 1 | ForEach-Object {
        $parts = $_.Trim() -split '\s+', 4
        if ($parts.Count -eq 4 -and $parts[0].StartsWith('/dev/')) {
            [PSCustomObject]@{
                DriveLetter   = $parts[3]
                FriendlyName  = $parts[0].Substring(5)
                Size          = [long]$parts[1]
                SizeRemaining = [long]$parts[2]
                DriveType     = 3
            }
        }
    }
}

function Get-LinuxInterfaceType {
    # The kind of a Linux interface (an entry of `ip -j addr show`). A tunnel has no link layer: no
    # MAC (link/none), link_type "none" or a point-to-point link; WireGuard, tun and ppp are typed
    # by this, not only by name, because a WireGuard interface can be named anything (e.g. "PC").
    param ($Interface, [bool]$Wireless)

    $name = "$($Interface.ifname)"
    $tunnel = [string]::IsNullOrEmpty("$($Interface.address)") -or "$($Interface.link_type)" -eq 'none' -or @($Interface.flags) -contains 'POINTOPOINT'
    if ($Wireless -or $name -match '^wl') { return 'wifi' }
    if ($name -match '^(docker|br-)') { return 'docker' }
    if ($name -match '^(tun|tap|wg|tailscale|zt|ppp|vpn|ipsec|nordlynx)') { return 'vpn' }
    if ($name -match '^(wwan|ww)') { return 'cellular' }
    if ($name -match '^(virbr|vnet|lxc|lxd|incus|cni|flannel|cali|podman)') { return 'virtual' }
    if ($name -match '^(br|bond)') { return 'bridge' }
    if ($tunnel) { return 'vpn' }
    return 'lan'
}

function Get-LinuxNetworks {
    # Every interface except loopback and container ends (veth), also the ones that are down.
    $interfaces = ip -j addr show 2>$null | ConvertFrom-Json
    # Default gateways by interface, and the MACs of the neighbours (the gateway's).
    $routes = @(try { ip -j -4 route show default 2>$null | ConvertFrom-Json } catch { })
    $neighbours = @(try { ip -j -4 neigh show 2>$null | ConvertFrom-Json } catch { })
    foreach ($interface in $interfaces) {
        $name = "$($interface.ifname)"
        if ($name -eq 'lo' -or $name -like 'veth*') {
            continue
        }
        $addresses = @($interface.addr_info | ForEach-Object { $_.local })
        $prefixed = @($interface.addr_info | Where-Object { $_.local } | ForEach-Object { @{ Address = "$($_.local)"; PrefixLength = [int]$_.prefixlen } })
        $type = Get-LinuxInterfaceType -Interface $interface -Wireless (Test-Path -Path "/sys/class/net/$name/wireless")
        $gateway = $routes | Where-Object { "$($_.dev)" -eq $name -and $_.gateway } | Select-Object -First 1 -ExpandProperty gateway
        $gatewayMac = if ($gateway) { $neighbours | Where-Object { "$($_.dst)" -eq "$gateway" -and "$($_.dev)" -eq $name -and $_.lladdr } | Select-Object -First 1 -ExpandProperty lladdr }
        [PSCustomObject]@{
            Name        = $name
            Gateway     = if ($gateway) { "$gateway" } else { $null }
            GatewayMac  = if ($gatewayMac) { "$gatewayMac" } else { $null }
            Status      = if ($interface.operstate -eq 'UP') { 'Up' } else { "$($interface.operstate)" }
            # Tunnels (WireGuard, tun) report UNKNOWN: connected when they have an address.
            Connected   = $interface.operstate -eq 'UP' -or ($interface.operstate -eq 'UNKNOWN' -and $addresses.Count -gt 0)
            Type        = $type
            Mac         = $interface.address
            IPAddresses = $addresses
            Addresses   = $prefixed
        }
    }
}

function Get-UserCommand {
    param (
        [string]
        $User
    )

    # runuser keeps root's environment (HOME=/root, PSModulePath ...): tools would work on root's
    # profile. The command runs with the user's own minimal environment instead.
    $entry = "$(getent passwd $User 2>$null)" -split ':'
    if ($entry.Count -lt 6 -or -not $entry[5]) {
        return $null
    }

    return @('runuser', '-u', $User, '--', 'env', '-i', "HOME=$($entry[5])", "USER=$User", "LOGNAME=$User", 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG=C.UTF-8')
}

function Get-FlatpakUpdates {
    # System installation plus every user's own (~/.local/share/flatpak), checked as that user.
    if (-not (Get-Command -Name flatpak -CommandType Application -ErrorAction SilentlyContinue)) {
        return
    }

    $installations = @(@{ User = $null; Arguments = @('--system') })
    foreach ($userHome in @(Get-ChildItem -Path /home -Directory -ErrorAction SilentlyContinue)) {
        if (Test-Path -Path "$($userHome.FullName)/.local/share/flatpak") {
            $installations += @{ User = $userHome.Name; Arguments = @('--user') }
        }
    }

    foreach ($installation in $installations) {
        $asUser = if ($installation.User) { Get-UserCommand -User $installation.User }
        if ($installation.User -and -not $asUser) { continue }
        $run = {
            param ([string[]]$FlatpakArguments)
            if ($asUser) { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) flatpak @FlatpakArguments 2>$null } else { flatpak @FlatpakArguments 2>$null }
        }
        $installed = @{}
        foreach ($line in @(& $run (@('list', '--app') + $installation.Arguments + '--columns=application,version'))) {
            $parts = "$line" -split "`t"
            if ($parts[0]) { $installed[$parts[0]] = $parts[1] }
        }
        foreach ($line in @(& $run (@('remote-ls', '--updates', '--app') + $installation.Arguments + '--columns=application,version'))) {
            $parts = "$line" -split "`t"
            if (-not $parts[0] -or $parts[0] -eq 'Application ID') { continue }
            [PSCustomObject]@{
                Id        = $parts[0]
                Version   = $installed[$parts[0]]
                Avaliable = $parts[1]
                Source    = if ($installation.User) { "flatpak ($($installation.User))" } else { 'flatpak' }
            }
        }
    }
}

function Get-SnapUpdates {
    if (-not (Get-Command -Name snap -CommandType Application -ErrorAction SilentlyContinue)) {
        return
    }

    $installed = @{}
    foreach ($line in @(snap list 2>$null | Select-Object -Skip 1)) {
        $parts = "$line".Trim() -split '\s+'
        if ($parts.Count -ge 2) { $installed[$parts[0]] = $parts[1] }
    }
    # Name  Version  Rev  Size  Publisher  Notes
    foreach ($line in @(snap refresh --list 2>$null | Select-Object -Skip 1)) {
        $parts = "$line".Trim() -split '\s+'
        if ($parts.Count -ge 2) {
            [PSCustomObject]@{ Id = $parts[0]; Version = $installed[$parts[0]]; Avaliable = $parts[1]; Source = 'snap' }
        }
    }
}

function Test-PythonExternallyManaged {
    # PEP 668: the distribution marks its Python as managed by the OS package manager
    # (EXTERNALLY-MANAGED next to the standard library), pip refuses to install into it.
    param ([string]$Python)

    $stdlib = "$(& $Python -c 'import sysconfig;print(sysconfig.get_path(''stdlib''))' 2>$null)".Trim()
    return [bool]$stdlib -and (Test-Path -Path (Join-Path $stdlib 'EXTERNALLY-MANAGED'))
}

function Get-PythonAptPackage {
    # The Debian package of a Python package (python3-name) when it is installed from apt, else $null.
    param ([string]$Name)

    if (-not (Get-Command -Name dpkg-query -CommandType Application -ErrorAction SilentlyContinue)) { return $null }
    $package = 'python3-' + $Name.ToLowerInvariant().Replace('_', '-')
    $status = "$(dpkg-query -W -f='${Status}' $package 2>$null)"
    return $(if ($status -eq 'install ok installed') { $package } else { $null })
}

function Get-PythonUpdates {
    # Outdated Python packages installed per user: pip --user packages and pipx apps, for the current
    # context and (on Linux) each /home user's own site and pipx. Only user installs are touched; the
    # system Python is left to the OS package manager (often externally managed). The latest versions
    # come from pip's own outdated check (it queries the package index). Rows:
    # @{ Id; Version; Avaliable; Source; Provider } with Source 'pip'/'pipx' plus " (user)" for a
    # user's, Provider 'python'.
    $hasPipx = [bool](Get-Command -Name pipx -CommandType Application -ErrorAction SilentlyContinue)
    $python = @('python3', 'python') | Where-Object { Get-Command -Name $_ -CommandType Application -ErrorAction SilentlyContinue } | Select-Object -First 1
    if (-not $hasPipx -and -not $python) { return }

    # Who to check: the current context, and on Linux each user with a home directory.
    $targets = @(@{ User = $null; Prefix = @() })
    if ($OnLinux) {
        foreach ($userHome in @(Get-ChildItem -Path /home -Directory -ErrorAction SilentlyContinue)) {
            $prefix = Get-UserCommand -User $userHome.Name
            if ($prefix) { $targets += @{ User = $userHome.Name; Prefix = @($prefix) } }
        }
    }

    foreach ($target in $targets) {
        $prefix = @($target.Prefix)
        $label = if ($target.User) { " ($($target.User))" } else { '' }
        $invoke = {
            param ([string[]]$CommandLine)
            $full = @($prefix + $CommandLine)
            try { & $full[0] @($full[1..($full.Count - 1)]) 2>$null } catch { }
        }

        # pip --user outdated: [{name, version, latest_version}].
        if ($python) {
            $raw = "$(& $invoke @($python, '-m', 'pip', 'list', '--user', '--outdated', '--format=json'))".Trim()
            if ($raw.StartsWith('[')) {
                foreach ($pkg in @(try { $raw | ConvertFrom-Json } catch { @() })) {
                    if ($pkg.name -and $pkg.latest_version) {
                        [PSCustomObject]@{ Id = "$($pkg.name)"; Version = "$($pkg.version)"; Avaliable = "$($pkg.latest_version)"; Source = "pip$label"; Provider = 'python' }
                    }
                }
            }
        }

        # pipx apps: list the venvs, then ask each app's own venv what is outdated.
        if ($hasPipx) {
            $listRaw = "$(& $invoke @('pipx', 'list', '--json'))".Trim()
            $list = if ($listRaw.StartsWith('{')) { try { $listRaw | ConvertFrom-Json } catch { $null } } else { $null }
            foreach ($prop in @($list.venvs.PSObject.Properties)) {
                $app = $prop.Name
                $main = $prop.Value.metadata.main_package
                if (-not $main.package) { continue }
                $pkg = "$($main.package)".Replace('_', '-').ToLowerInvariant()
                $outRaw = "$(& $invoke @('pipx', 'runpip', $app, 'list', '--outdated', '--format=json'))".Trim()
                if (-not $outRaw.StartsWith('[')) { continue }
                foreach ($o in @(try { $outRaw | ConvertFrom-Json } catch { @() })) {
                    if ("$($o.name)".Replace('_', '-').ToLowerInvariant() -eq $pkg -and $o.latest_version) {
                        [PSCustomObject]@{ Id = "$app"; Version = "$($main.package_version)"; Avaliable = "$($o.latest_version)"; Source = "pipx$label"; Provider = 'python' }
                    }
                }
            }
        }
    }
}

function Get-PowerShellManager {
    # Who updates this PowerShell 7 instead of a release from GitHub: 'snap' (read-only, snap
    # refreshes it) or 'store' (Microsoft Store); $null when it is a plain installation.
    param ([string]$Path, [bool]$OnLinux)

    if ($OnLinux) {
        $real = "$(readlink -f $Path 2>$null)"
        if ($Path -like '/snap/*' -or $real -like '/snap/*' -or $real -eq '/usr/bin/snap') { return 'snap' }
    } elseif ($Path -like '*\WindowsApps\*') {
        return 'store'
    }
    return $null
}

function Get-PowerShellReleaseUpdate {
    param (
        [bool]
        $OnLinux,
        # Updates already listed by a package manager, PowerShell is not reported twice.
        $Known
    )

    $pwsh = @(Get-PowerShellHosts -OnLinux $OnLinux | Where-Object { $_.Edition -eq 'PowerShell 7' }) | Select-Object -First 1
    if (-not $pwsh -or @($Known | Where-Object { "$($_.Id) $($_.Title)" -match '(^|\s)(powershell|Microsoft\.PowerShell)(\s|$)' })) {
        return
    }
    # snap and the Store update it themselves (snap refresh --list shows a waiting snap update).
    if (Get-PowerShellManager -Path $pwsh.Path -OnLinux $OnLinux) {
        return
    }

    # The release pwsh itself checks for its update notification.
    $installed = (& $pwsh.Path -NoProfile -NonInteractive -Command '$PSVersionTable.PSVersion.ToString()' 2>$null | Select-Object -First 1)
    $latest = (Invoke-RestMethod -Uri 'https://aka.ms/pwsh-buildinfo-stable' -TimeoutSec 30).ReleaseTag -replace '^v', ''
    try {
        if ($installed -and $latest -and [version]($latest -split '-')[0] -gt [version](("$installed" -split '-')[0])) {
            [PSCustomObject]@{ Id = 'PowerShell'; Version = "$installed"; Avaliable = $latest; Source = 'github.com/PowerShell' }
        }
    }
    catch { }
}

function Save-AptUpdateState {
    # The result of apt-get update for the security inventory (a failed update makes its CVE findings uncertain): the error lines
    # only; notices (a repository without an architecture such as i386) are no errors.
    param ([string]$Path, [int]$ExitCode, $Output)

    if (-not $Path) { return }
    $errors = @(@($Output) | Where-Object { "$_" -match '^(E:|W:|Err:)' -and "$_" -notmatch "doesn't support architecture" })
    $text = ($errors -join "`n")
    if ($text.Length -gt 1800) { $text = $text.Substring(0, 1800) }
    try { @{ at = (Get-Date).ToString('o'); ok = ($ExitCode -eq 0); errors = $text } | ConvertTo-Json -Compress | Set-Content -Path $Path -Encoding UTF8 } catch { }
}

function Get-AptUpdates {
    param ([string]$StatePath = '')
    # The apt cache is refreshed first (apt-get update), a stale cache is the reason updates were
    # missed. When the refresh fails this throws, so the caller never reports "0 updates" from an old
    # cache. "apt list --upgradable" also lists packages apt will not install now, so a simulated
    # upgrade (as Install updates runs it) tells which ones are installable, deferred by phasing
    # or held back.
    $env:DEBIAN_FRONTEND = 'noninteractive'
    $refresh = @(apt-get -o DPkg::Lock::Timeout=600 -q update 2>&1 | ForEach-Object { "$_" })
    Save-AptUpdateState -Path $StatePath -ExitCode $LASTEXITCODE -Output $refresh
    if ($LASTEXITCODE) { throw "apt-get update failed (exit $LASTEXITCODE): $((@($refresh) | Select-Object -Last 2) -join ' | ')" }
    $simulation = @(apt-get -s -q -o Debug::NoLocking=1 --with-new-pkgs upgrade 2>$null)
    if ($LASTEXITCODE) { throw "apt-get -s upgrade failed (exit $LASTEXITCODE)" }
    $installable = @{}
    $phased = @{}
    $section = $null
    foreach ($line in $simulation) {
        if ($line -match '^Inst (\S+)') {
            $installable[$Matches[1]] = $true
        } elseif ($line -match '^\S.*:\s*$') {
            # A list header, e.g. "The following upgrades have been deferred due to phasing:" (apt 2)
            # or "Not upgrading yet due to phasing:" (apt 3).
            $section = if ($line -match 'phasing') { 'phased' } else { 'other' }
        } elseif ($section -eq 'phased' -and $line -match '^\s+\S') {
            foreach ($name in ($line.Trim() -split '\s+')) { $phased[$name] = $true }
        } elseif ($line -notmatch '^\s') {
            $section = $null
        }
    }

    apt list --upgradable 2>$null | Where-Object { $_ -match '^(\S+?)/\S+\s+(\S+)' } | ForEach-Object {
        $name = $Matches[1]
        [PSCustomObject]@{
            Title          = "$name $($Matches[2])"
            Status         = if ($installable[$name]) { 'installable' } elseif ($phased[$name]) { 'phased' } else { 'held' }
            IsDownloaded   = $false
            RebootRequired = $false
        }
    }
}

#endregion

#region Security

# Everything the server sends is signed with its RSA key, which the agent pins on the first start
# (from $EmbeddedServerKey, filled in when the server serves this script). Everything the agent
# sends is signed with the device key, generated on the device and never sent anywhere. Each
# signature covers a context prefix (MDM1-REQ, MDM1-RESP, ...), so it is valid for one purpose only.
$script:ClockOffset = 0
$script:ServerKey = $null
$script:ServerRsa = $null
$script:DeviceKey = $null
$script:DeviceId = $null
$script:HttpClient = $null

function Get-UnixTime {
    # Server time: the offset comes from the timestamps of verified server responses.
    return [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() + $script:ClockOffset
}

function New-Nonce {
    $bytes = New-Object byte[] 16
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($bytes) } finally { $random.Dispose() }
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Get-Sha256Hex {
    param (
        [byte[]]
        $Bytes
    )

    if ($null -eq $Bytes) { $Bytes = [byte[]]@() }
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return -join ($sha.ComputeHash($Bytes) | ForEach-Object { $_.ToString('x2') })
    }
    finally {
        $sha.Dispose()
    }
}

function Get-KeyFingerprint {
    param (
        [Parameter(Mandatory = $true)]
        $Key
    )

    return Get-Sha256Hex -Bytes ([System.Text.Encoding]::UTF8.GetBytes("rsa:$($Key.n):$($Key.e)"))
}

function Protect-AgentPath {
    # Only SYSTEM / root and administrators may read or change the agent's files.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Path,
        [switch]
        $Directory
    )

    if ($OnLinux) {
        chmod $(if ($Directory) { '700' } else { '600' }) $Path
        return
    }

    # SIDs instead of names: independent of the system language.
    $grant = if ($Directory) { '(OI)(CI)F' } else { 'F' }
    icacls $Path /inheritance:r /grant:r "*S-1-5-18:$grant" "*S-1-5-32-544:$grant" | Out-Null
}

function ConvertTo-Hashtable {
    # ConvertFrom-Json -AsHashtable does not exist in Windows PowerShell 5.1.
    param (
        $Object
    )

    if ($Object -is [System.Management.Automation.PSCustomObject]) {
        $table = @{}
        foreach ($property in $Object.PSObject.Properties) {
            $table[$property.Name] = ConvertTo-Hashtable -Object $property.Value
        }
        return $table
    }
    return $Object
}

function Get-AgentConfig {
    # Local settings the server cannot change: the pinned server key, the device id and whether
    # remediation scripts may run.
    $path = Join-Path $AgentDir 'config.json'
    $config = @{}
    if (Test-Path -Path $path) {
        $config = ConvertTo-Hashtable -Object (Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json)
    }
    if ($null -eq $config['scripts_enabled']) {
        $config['scripts_enabled'] = $true
    }
    return $config
}

function Save-AgentConfig {
    param (
        [Parameter(Mandatory = $true)]
        [hashtable]
        $Config
    )

    $path = Join-Path $AgentDir 'config.json'
    $Config | ConvertTo-Json -Depth 4 | Set-Content -Path $path -Encoding UTF8
    Protect-AgentPath -Path $path
}

function Set-ServerKey {
    param (
        [Parameter(Mandatory = $true)]
        $Key
    )

    $parameters = New-Object System.Security.Cryptography.RSAParameters
    $parameters.Modulus = [Convert]::FromBase64String($Key.n)
    $parameters.Exponent = [Convert]::FromBase64String($Key.e)
    $rsa = [System.Security.Cryptography.RSA]::Create()
    $rsa.ImportParameters($parameters)
    $script:ServerKey = $Key
    $script:ServerRsa = $rsa
}

function Initialize-ServerKey {
    # Pins the server key once: the one filled into this script, otherwise the one the server
    # announces (trust on first use). -ServerKeyFingerprint must match when it is given.
    $config = Get-AgentConfig
    $embedded = $null
    if ($EmbeddedServerKey -match '^([A-Za-z0-9+/=]+):([A-Za-z0-9+/=]+)$') {
        $embedded = @{ n = $Matches[1]; e = $Matches[2] }
    }

    if ($ResetServerKey -and $config['server_key']) {
        Write-AgentLog "Pinned server key $($config['server_key'].fingerprint) removed (-ResetServerKey)"
        $config.Remove('server_key')
    }

    $key = $config['server_key']
    if (-not $key) {
        $key = $embedded
        if (-not $key) {
            $response = Invoke-WebRequest -UseBasicParsing -Uri "$($ServerUrl.TrimEnd('/'))/agent/signing-key"
            $announced = $response.Content | ConvertFrom-Json
            $key = @{ n = $announced.n; e = $announced.e }
        }
        $key['fingerprint'] = Get-KeyFingerprint -Key $key
        if ($ServerKeyFingerprint -and $key.fingerprint -ne $ServerKeyFingerprint.ToLowerInvariant()) {
            throw "The server key $($key.fingerprint) does not match -ServerKeyFingerprint $ServerKeyFingerprint, it is not trusted."
        }
        $config['server_key'] = $key
        Save-AgentConfig -Config $config
        Write-AgentLog "Pinned the server key $($key.fingerprint)"
    } elseif ($ServerKeyFingerprint -and $key.fingerprint -ne $ServerKeyFingerprint.ToLowerInvariant()) {
        throw "The pinned server key $($key.fingerprint) does not match -ServerKeyFingerprint $ServerKeyFingerprint. Reinstall with -ResetServerKey if the server key was replaced on purpose."
    } elseif ($embedded -and (Get-KeyFingerprint -Key $embedded) -ne $key.fingerprint) {
        Write-AgentLog "Warning: this agent carries server key $(Get-KeyFingerprint -Key $embedded), the pinned key $($key.fingerprint) stays trusted" -IsError
    }

    Set-ServerKey -Key $key
    $script:DeviceId = $config['device_id']
    return $config
}

function Test-ServerSignature {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Context,
        [AllowEmptyString()]
        [string]
        $Message,
        [string]
        $Signature
    )

    if (-not $script:ServerRsa -or -not $Signature) {
        return $false
    }
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes("$Context`n$Message")
        return $script:ServerRsa.VerifyData($bytes, [Convert]::FromBase64String($Signature), [System.Security.Cryptography.HashAlgorithmName]::SHA256, [System.Security.Cryptography.RSASignaturePadding]::Pkcs1)
    }
    catch {
        return $false
    }
}

function Get-DeviceKey {
    # The device's own RSA key. Windows: a non-exportable CNG machine key (not even SYSTEM can read
    # the private key out), or a DPAPI-protected file where CNG is not available. Linux: a root-only file.
    if ($script:DeviceKey) {
        return $script:DeviceKey
    }

    if ($OnLinux) {
        $path = Join-Path $AgentDir 'device.key'
        $rsa = [System.Security.Cryptography.RSA]::Create()
        if (Test-Path -Path $path) {
            $read = 0
            $rsa.ImportRSAPrivateKey([Convert]::FromBase64String((Get-Content -Path $path -Raw).Trim()), [ref]$read)
        } else {
            $rsa.KeySize = 3072
            Set-Content -Path $path -Value ([Convert]::ToBase64String($rsa.ExportRSAPrivateKey())) -NoNewline
            Protect-AgentPath -Path $path
            Write-AgentLog 'Device key created'
        }
        return $script:DeviceKey = $rsa
    }

    try {
        $name = 'Laravel-MDM-Agent'
        $provider = [System.Security.Cryptography.CngProvider]::MicrosoftSoftwareKeyStorageProvider
        $machine = [System.Security.Cryptography.CngKeyOpenOptions]::MachineKey
        if ([System.Security.Cryptography.CngKey]::Exists($name, $provider, $machine)) {
            $cng = [System.Security.Cryptography.CngKey]::Open($name, $provider, $machine)
        } else {
            $parameters = New-Object System.Security.Cryptography.CngKeyCreationParameters
            $parameters.Provider = $provider
            $parameters.KeyCreationOptions = [System.Security.Cryptography.CngKeyCreationOptions]::MachineKey
            $parameters.ExportPolicy = [System.Security.Cryptography.CngExportPolicies]::None
            $parameters.Parameters.Add((New-Object System.Security.Cryptography.CngProperty('Length', [BitConverter]::GetBytes(3072), [System.Security.Cryptography.CngPropertyOptions]::None)))
            $cng = [System.Security.Cryptography.CngKey]::Create([System.Security.Cryptography.CngAlgorithm]::Rsa, $name, $parameters)
            Write-AgentLog 'Device key created (CNG machine key, not exportable)'
        }
        return $script:DeviceKey = New-Object System.Security.Cryptography.RSACng($cng)
    }
    catch {
        Write-AgentLog "CNG key storage failed ($($_.Exception.Message)), using a DPAPI-protected key file"
    }

    $path = Join-Path $AgentDir 'DeviceKey.xml'
    $rsa = New-Object System.Security.Cryptography.RSACryptoServiceProvider(3072)
    if (Test-Path -Path $path) {
        $secure = (Import-Clixml -Path $path).key
        $rsa.FromXmlString([System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)))
    } else {
        @{ key = ($rsa.ToXmlString($true) | ConvertTo-SecureString -AsPlainText -Force) } | Export-Clixml -Path $path
        Protect-AgentPath -Path $path
        Write-AgentLog 'Device key created (DPAPI-protected file)'
    }
    return $script:DeviceKey = $rsa
}

function Get-DevicePublicKey {
    $parameters = (Get-DeviceKey).ExportParameters($false)
    return @{ n = [Convert]::ToBase64String($parameters.Modulus); e = [Convert]::ToBase64String($parameters.Exponent) }
}

function New-DeviceSignature {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Context,
        [AllowEmptyString()]
        [string]
        $Message
    )

    $bytes = [System.Text.Encoding]::UTF8.GetBytes("$Context`n$Message")
    return [Convert]::ToBase64String((Get-DeviceKey).SignData($bytes, [System.Security.Cryptography.HashAlgorithmName]::SHA256, [System.Security.Cryptography.RSASignaturePadding]::Pkcs1))
}

function Get-HttpClient {
    if (-not $script:HttpClient) {
        if (-not ('System.Net.Http.HttpClient' -as [type])) {
            Add-Type -AssemblyName System.Net.Http
        }
        $script:HttpClient = New-Object System.Net.Http.HttpClient
        $script:HttpClient.Timeout = [TimeSpan]::FromSeconds(100)
    }
    return $script:HttpClient
}

function Get-ResponseHeader {
    param (
        [Parameter(Mandatory = $true)]
        $Response,
        [Parameter(Mandatory = $true)]
        [string]
        $Name
    )

    $values = $null
    if ($Response.Headers.TryGetValues($Name, [ref]$values)) {
        return @($values)[0]
    }
    return $null
}

#endregion

#region Remediation scripts

# Runs are verified against the pinned server key (manifest signature, device, expiry, one-time
# run id, platform, hashes of the script bytes), kept in memory only and run one at a time in a
# separate low-priority process without network access, the code passed on stdin. Nothing of the
# script is written to disk and it never appears on a command line.
$script:ScriptQueue = New-Object System.Collections.Queue
$script:CurrentScript = $null
$script:ScriptsRequested = $false
$script:ExecutedRuns = $null

# Constant bootstrap of the script process: reads the script (base64) from stdin, checks its hash
# again and runs it. Exit 97 = hash mismatch, 98 = the script threw.
$ScriptBootstrap = @'
$ErrorActionPreference = 'Stop'
# Windows PowerShell writes progress ("Preparing modules for first use.") to a redirected stderr as CLIXML.
$ProgressPreference = 'SilentlyContinue'
$bytes = [Convert]::FromBase64String([Console]::In.ReadToEnd().Trim())
$sha = [Security.Cryptography.SHA256]::Create()
$hash = -join ($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') })
if ($hash -ne $env:MDM_SCRIPT_SHA256) { [Console]::Error.WriteLine('The script does not match its signed hash.'); exit 97 }
$code = [Text.Encoding]::UTF8.GetString($bytes)
Remove-Variable bytes, sha, hash
$global:LASTEXITCODE = 0
try { & ([scriptblock]::Create($code)); exit $LASTEXITCODE }
catch { [Console]::Error.WriteLine($_.ToString()); exit 98 }
'@

function Get-ExecutedRuns {
    if ($null -eq $script:ExecutedRuns) {
        $script:ExecutedRuns = New-Object System.Collections.Generic.List[long]
        $path = Join-Path $AgentDir 'state.json'
        if (Test-Path -Path $path) {
            try { foreach ($id in @((Get-Content -Path $path -Raw | ConvertFrom-Json).executed_runs)) { $script:ExecutedRuns.Add([long]$id) } } catch { }
        }
    }
    # The comma keeps an empty list from being unrolled to $null.
    return , $script:ExecutedRuns
}

function Add-ExecutedRun {
    param (
        [long]
        $RunId
    )

    $runs = Get-ExecutedRuns
    $runs.Add($RunId)
    while ($runs.Count -gt 500) { $runs.RemoveAt(0) }
    $path = Join-Path $AgentDir 'state.json'
    @{ executed_runs = @($runs) } | ConvertTo-Json -Compress | Set-Content -Path $path -Encoding UTF8
    Protect-AgentPath -Path $path
}

function Get-ScriptFingerprint {
    # The same as the server: SHA-256 of {detection_sha256, platform, remediation_sha256, timeout}.
    param (
        $Manifest
    )

    $remediation = if ($Manifest.remediation_sha256) { '"' + $Manifest.remediation_sha256 + '"' } else { 'null' }
    $json = '{"detection_sha256":"' + $Manifest.detection_sha256 + '","platform":"' + $Manifest.platform + '","remediation_sha256":' + $remediation + ',"timeout":' + [int]$Manifest.timeout + '}'
    return Get-Sha256Hex -Bytes ([System.Text.Encoding]::UTF8.GetBytes($json))
}

function Test-ScriptRun {
    # Returns the verified run, or throws why it is rejected.
    param (
        [Parameter(Mandatory = $true)]
        $Payload
    )

    if (-not (Test-ServerSignature -Context 'MDM1-SCRIPT' -Message "$($Payload.manifest)" -Signature "$($Payload.signature)")) {
        throw 'the manifest is not signed with the pinned server key'
    }
    $manifest = $Payload.manifest | ConvertFrom-Json
    $now = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() + $script:ClockOffset
    if ("$($manifest.device_id)" -ne "$($script:DeviceId)") { throw "the run is for device $($manifest.device_id)" }
    if ($now -gt [long]$manifest.expires_at) { throw 'the run has expired' }
    if ($now -lt [long]$manifest.issued_at - 300) { throw 'the run is issued in the future' }
    if ((Get-ExecutedRuns).Contains([long]$manifest.run_id)) { throw 'the run was already executed' }
    $platform = if ($OnLinux) { 'linux' } else { 'windows' }
    if ($manifest.platform -ne 'all' -and $manifest.platform -ne $platform) { throw "the script is for $($manifest.platform)" }
    if ([int]$manifest.timeout -lt 1 -or [int]$manifest.timeout -gt 3600) { throw 'invalid timeout' }

    $detection = [Convert]::FromBase64String("$($Payload.detection)")
    if ((Get-Sha256Hex -Bytes $detection) -ne $manifest.detection_sha256) { throw 'the detection script does not match its signed hash' }
    $remediation = $null
    if ($manifest.remediation_sha256) {
        $remediation = [Convert]::FromBase64String("$($Payload.remediation)")
        if ((Get-Sha256Hex -Bytes $remediation) -ne $manifest.remediation_sha256) { throw 'the remediation script does not match its signed hash' }
    } elseif ($Payload.remediation) {
        throw 'the remediation script is not in the signed manifest'
    }
    if ((Get-ScriptFingerprint -Manifest $manifest) -ne $manifest.fingerprint) { throw 'the fingerprint does not match the scripts' }

    return @{ Manifest = $manifest; Detection = $detection; Remediation = $remediation }
}

function Initialize-ScriptSandbox {
    # Windows: a copy of powershell.exe that the firewall blocks completely; the agent itself keeps
    # its network. Returns the path, or throws why scripts cannot run isolated.
    $source = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $dir = Join-Path $AgentDir 'sandbox'
    $target = Join-Path $dir 'powershell.exe'
    if (-not (Test-Path -Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
    # Copied again after a Windows update changed the original.
    if (-not (Test-Path -Path $target) -or (Get-FileHash -Path $target).Hash -ne (Get-FileHash -Path $source).Hash) {
        Copy-Item -Path $source -Destination $target -Force
    }

    foreach ($direction in 'Outbound', 'Inbound') {
        $name = "LaravelMDM-Scripts-$direction"
        $rule = Get-NetFirewallRule -Name $name -ErrorAction SilentlyContinue
        if (-not $rule) {
            New-NetFirewallRule -Name $name -DisplayName "Laravel-MDM remediation scripts ($direction, no network)" -Direction $direction -Action Block -Program $target -Profile Any -Enabled True | Out-Null
            $rule = Get-NetFirewallRule -Name $name
        }
        $program = ($rule | Get-NetFirewallApplicationFilter).Program
        if ("$($rule.Enabled)" -ne 'True' -or "$($rule.Action)" -ne 'Block' -or $program -ne $target) {
            throw "the firewall rule $name is changed or disabled"
        }
    }
    $disabled = @(Get-NetFirewallProfile | Where-Object { "$($_.Enabled)" -ne 'True' })
    if ($disabled) {
        throw "Windows Firewall is off for the $(($disabled.Name) -join ', ') profile, scripts cannot run without network access"
    }
    return $target
}

function Start-ScriptProcess {
    param (
        [Parameter(Mandatory = $true)]
        [byte[]]
        $Code
    )

    $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($ScriptBootstrap))
    $info = New-Object System.Diagnostics.ProcessStartInfo
    if ($OnLinux) {
        # An empty network namespace: no interfaces, no DNS, also for every child process.
        foreach ($tool in 'unshare', 'nice', 'ionice') {
            if (-not (Get-Command -Name $tool -CommandType Application -ErrorAction SilentlyContinue)) {
                throw "network isolation unavailable ($tool not found)"
            }
        }
        $info.FileName = (Get-Command -Name unshare -CommandType Application | Select-Object -First 1).Source
        $info.Arguments = "--net -- nice -n 10 ionice -c 3 `"$((Get-Process -Id $PID).Path)`" -NoLogo -NoProfile -NonInteractive -EncodedCommand $encoded"
    } else {
        $info.FileName = Initialize-ScriptSandbox
        $info.Arguments = "-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand $encoded"
    }
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.WorkingDirectory = [System.IO.Path]::GetTempPath()
    $info.EnvironmentVariables['MDM_SCRIPT_SHA256'] = Get-Sha256Hex -Bytes $Code
    # Where the agent keeps config.json (scripts that change its settings, any -InstallPath).
    $info.EnvironmentVariables['MDM_AGENT_DIR'] = $AgentDir

    $process = [System.Diagnostics.Process]::Start($info)
    if (-not $OnLinux) {
        try { $process.PriorityClass = [System.Diagnostics.ProcessPriorityClass]::BelowNormal } catch { }
    }
    $stdout = $process.StandardOutput.ReadToEndAsync()
    $stderr = $process.StandardError.ReadToEndAsync()
    $process.StandardInput.Write([Convert]::ToBase64String($Code))
    $process.StandardInput.Close()

    return @{ Process = $process; Stdout = $stdout; Stderr = $stderr; Started = Get-Date }
}

function ConvertFrom-CliXmlStream {
    # Windows PowerShell serializes errors, warnings and progress to a redirected stderr as
    # "#< CLIXML" followed by <Objs>. Keeps the text of errors, warnings, verbose and debug records
    # and drops progress; anything that is not CLIXML stays as it is.
    param (
        [AllowEmptyString()]
        [string]
        $Text
    )

    $marker = '#< CLIXML'
    $start = $Text.IndexOf($marker)
    if ($start -lt 0) {
        return $Text
    }

    $before = $Text.Substring(0, $start)
    $lines = New-Object System.Collections.Generic.List[string]
    try {
        $xml = New-Object System.Xml.XmlDocument
        $xml.XmlResolver = $null
        # Every write starts a new "#< CLIXML" block.
        foreach ($block in ($Text.Substring($start) -split [regex]::Escape($marker))) {
            if (-not $block.Trim()) { continue }
            $xml.LoadXml($block.Trim())
            foreach ($node in $xml.DocumentElement.ChildNodes) {
                if ($node.LocalName -ne 'S') { continue }
                $prefix = switch ($node.GetAttribute('S')) { 'warning' { 'WARNING: ' } 'verbose' { 'VERBOSE: ' } 'debug' { 'DEBUG: ' } default { '' } }
                $lines.Add($prefix + [System.Xml.XmlConvert]::DecodeName($node.InnerText).TrimEnd("`r", "`n"))
            }
        }
    }
    catch {
        return $Text
    }

    return ($before + ($lines -join [Environment]::NewLine)).TrimEnd()
}

function Stop-ScriptProcess {
    param (
        $Process
    )

    try {
        if ($OnLinux) { $Process.Kill($true) } else { taskkill /PID $Process.Id /T /F 2>&1 | Out-Null }
    }
    catch { }
}

function Request-ScriptRuns {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $script:ScriptsRequested = $false
    if (-not (Get-AgentConfig)['scripts_enabled']) {
        Write-AgentLog 'Remediation scripts are disabled in config.json, not taking any'
        return
    }

    $response = Invoke-MdmApi -Path 'device/scripts' -Token $Token
    foreach ($payload in @($response.runs)) {
        if (-not $payload) { continue }
        $runId = $null
        try {
            $runId = ($payload.manifest | ConvertFrom-Json).run_id
            $run = Test-ScriptRun -Payload $payload
            $script:ScriptQueue.Enqueue($run)
        }
        catch {
            Write-AgentLog "Script run $runId rejected: $($_.Exception.Message)" -ErrorRecord $_
            if ($runId) {
                try { Invoke-MdmApi -Method Post -Path "device/scripts/runs/$runId" -Token $Token -Body @{ status = 'rejected'; error = $_.Exception.Message } | Out-Null } catch { }
            }
        }
    }
}

function Update-ScriptRun {
    # Advances the current run (detection, remediation, detection again) without blocking the loop.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $current = $script:CurrentScript
    if (-not $current) {
        if ($script:ScriptQueue.Count -eq 0) { return }
        $run = $script:ScriptQueue.Dequeue()
        Add-ExecutedRun -RunId $run.Manifest.run_id
        $current = $script:CurrentScript = @{ Run = $run; Step = 'detection'; Output = New-Object System.Text.StringBuilder; Exit = @{}; Error = $null; Proc = $null }
        Write-AgentLog "Script '$($run.Manifest.name)' v$($run.Manifest.version) ($($run.Manifest.fingerprint)) started"
    }

    try {
        if (-not $current.Proc) {
            $code = if ($current.Step -eq 'remediation') { $current.Run.Remediation } else { $current.Run.Detection }
            $current.Proc = Start-ScriptProcess -Code $code
            return
        }

        $proc = $current.Proc
        if (-not $proc.Process.HasExited) {
            if (((Get-Date) - $proc.Started).TotalSeconds -lt [int]$current.Run.Manifest.timeout) { return }
            Stop-ScriptProcess -Process $proc.Process
            $proc.Process.WaitForExit(5000) | Out-Null
            $current.Error = "$($current.Step) timed out after $($current.Run.Manifest.timeout) s"
        }

        $exit = if ($current.Error) { $null } else { $proc.Process.ExitCode }
        [void]$current.Output.AppendLine("== $($current.Step) ($(if ($current.Error) { 'timed out' } else { "exit $exit" })) ==")
        foreach ($stream in $proc.Stdout, $proc.Stderr) {
            if ($stream.Wait(5000) -and $stream.Result) {
                $text = ConvertFrom-CliXmlStream -Text $stream.Result
                if ($text.Trim()) { [void]$current.Output.AppendLine($text.TrimEnd()) }
            }
        }
        $proc.Process.Dispose()
        $current.Proc = $null
        $current.Exit[$current.Step] = $exit

        $status = $null
        if ($current.Error) {
            $status = 'error'
        } elseif ($current.Step -eq 'detection') {
            if ($exit -eq 0) { $status = 'compliant' }
            elseif ($exit -eq 1 -and $current.Run.Remediation) { $current.Step = 'remediation'; return }
            elseif ($exit -eq 1) { $status = 'failed' }
            else { $status = 'error'; $current.Error = "detection exited with $exit" }
        } elseif ($current.Step -eq 'remediation') {
            $current.Step = 'post'
            return
        } else {
            $status = if ($exit -eq 0) { 'remediated' } elseif ($exit -eq 1) { 'failed' } else { 'error' }
            if ($status -eq 'error') { $current.Error = "detection after the remediation exited with $exit" }
        }
    }
    catch {
        $status = 'error'
        $current.Error = $_.Exception.Message
        if ($current.Proc) { Stop-ScriptProcess -Process $current.Proc.Process }
    }

    $script:CurrentScript = $null
    $manifest = $current.Run.Manifest
    $output = $current.Output.ToString()
    if ($output.Length -gt 16000) { $output = $output.Substring(0, 16000) }
    # A run that only detects (manual remediation): exit 1 is "needs remediation", started from the
    # portal; reported as failed (nothing ran), the server tells it apart.
    $logged = if ($manifest.detect_only -and $status -eq 'failed' -and $current.Exit['detection'] -eq 1) { 'needs remediation (started from the portal)' } else { $status }
    Write-AgentLog "Script '$($manifest.name)' ($($manifest.fingerprint)): $logged$(if ($current.Error) { ", $($current.Error)" })"
    try {
        Invoke-MdmApi -Method Post -Path "device/scripts/runs/$($manifest.run_id)" -Token $Token -Body @{
        status              = $status
        fingerprint         = $manifest.fingerprint
        detection_exit      = $current.Exit['detection']
        remediation_exit    = $current.Exit['remediation']
        post_detection_exit = $current.Exit['post']
        output              = $output
        error               = $current.Error
        } | Out-Null
    }
    catch {
        Write-AgentLog "Script result not sent: $($_.Exception.Message)" -ErrorRecord $_
    }
    # A remediation may have changed what the report carries (e.g. network_discovery in
    # config.json): the portal sees it right away, not with the next report in up to 5 minutes.
    if ($null -ne $current.Exit['remediation']) {
        $script:ReportSoon = $true
    }
}

#endregion

#region Agent

function Write-AgentLog {
    # -IsError, or -ErrorRecord of an error that is not just the network: the line also goes to the
    # server, as an alert of the device (Send-AgentErrors).
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Message,
        [switch]
        $IsError,
        $ErrorRecord
    )

    $LogPath = "$AgentDir/agent.log"
    if ((Test-Path -Path $LogPath) -and (Get-Item -Path $LogPath).Length -gt 1MB) {
        Move-Item -Path $LogPath -Destination "$LogPath.1" -Force
    }
    "{0:yyyy-MM-dd HH:mm:ss} {1}" -f (Get-Date), $Message | Add-Content -Path $LogPath -Encoding UTF8
    if ($IsError -or ($ErrorRecord -and -not (Test-NetworkError -ErrorRecord $ErrorRecord))) {
        Add-AgentError -Message $Message
    }
}

function Test-NetworkError {
    # The server or the network could not be reached (nothing the server can show while it is so);
    # an answer of the server with an error is not one.
    param ($ErrorRecord)

    $exception = if ($ErrorRecord -is [System.Management.Automation.ErrorRecord]) { $ErrorRecord.Exception } else { $ErrorRecord }
    if ($exception -and $exception.Data['MdmGateway']) { return $true }
    if ($exception -and $null -ne $exception.Data['MdmStatus']) { return $false }
    # By name: Windows PowerShell has no System.Net.Http before the first request loads it (a type
    # literal would throw). An IOException only under one of them (a broken connection), not a
    # file the agent could not write.
    $network = @('System.Net.Http.HttpRequestException', 'System.Net.Sockets.SocketException', 'System.Net.WebSockets.WebSocketException',
        'System.Net.WebException', 'System.TimeoutException', 'System.Threading.Tasks.TaskCanceledException') |
        ForEach-Object { $_ -as [type] } | Where-Object { $_ }
    $inner = $false
    while ($exception) {
        if (@($network | Where-Object { $_.IsInstanceOfType($exception) }).Count -gt 0 -or ($inner -and $exception -is [System.IO.IOException])) {
            return $true
        }
        $exception = $exception.InnerException
        $inner = $true
    }
    return $false
}

function Add-AgentError {
    # Kept (also over a restart) until the server took it, the same message counted once.
    param ([string]$Message)

    # Only the agent itself, not its jobs (they have their own variables).
    if ($null -eq $script:AgentErrors) { return }
    try {
        $now = Get-UnixTime
        $text = if ($Message.Length -gt 500) { $Message.Substring(0, 500) } else { $Message }
        $known = $script:AgentErrors | Where-Object { $_['message'] -eq $text } | Select-Object -First 1
        if ($known) {
            # Index syntax: .count of a dictionary is its number of entries.
            $known['count'] = [int]$known['count'] + 1
            $known['last'] = $now
        } else {
            if ($script:AgentErrors.Count -ge 30) { $script:AgentErrors.RemoveAt(0) }
            [void]$script:AgentErrors.Add([ordered]@{ message = $text; count = 1; first = $now; last = $now })
        }
        ConvertTo-Json -InputObject @($script:AgentErrors) -Depth 3 -Compress | Set-Content -Path "$AgentDir/pending-errors.json" -Encoding UTF8
    }
    catch {
        # Never break the log line itself.
    }
}

function Initialize-AgentErrors {
    $script:AgentErrors = New-Object System.Collections.ArrayList
    try {
        if (Test-Path -Path "$AgentDir/pending-errors.json") {
            # ForEach-Object: Windows PowerShell's ConvertFrom-Json passes the array on as one object.
            foreach ($item in @(Get-Content -Path "$AgentDir/pending-errors.json" -Raw -Encoding UTF8 | ConvertFrom-Json | ForEach-Object { $_ })) {
                if ($item.message) {
                    [void]$script:AgentErrors.Add([ordered]@{ message = "$($item.message)"; count = [int]$item.count; first = [long]$item.first; last = [long]$item.last })
                }
            }
        }
    }
    catch {
        $script:AgentErrors.Clear()
    }
}

function Send-AgentErrors {
    # The errors of the log to the server (an alert of the device); kept when it cannot take them.
    param ([Parameter(Mandatory = $true)][string]$Token)

    if (-not $script:AgentErrors -or $script:AgentErrors.Count -eq 0) { return }
    $batch = @($script:AgentErrors.ToArray())
    try {
        Invoke-MdmApi -Method Post -Path 'device/errors' -Token $Token -Body @{ errors = $batch } | Out-Null
        $sent = $true
    }
    catch {
        # A server without it (or an agent that does not sign): nothing to wait for.
        $sent = $_.Exception.Data['MdmStatus'] -in 403, 404
        if (-not $sent) { return }
    }
    foreach ($item in $batch) { $script:AgentErrors.Remove($item) }
    if ($script:AgentErrors.Count -eq 0) {
        Remove-Item -Path "$AgentDir/pending-errors.json" -Force -ErrorAction SilentlyContinue
    } else {
        ConvertTo-Json -InputObject @($script:AgentErrors) -Depth 3 -Compress | Set-Content -Path "$AgentDir/pending-errors.json" -Encoding UTF8
    }
}

function Invoke-MdmApi {
    # Signed request with the device key; the response must be signed with the pinned server key
    # for this very request (its nonce), otherwise it is rejected before anything reads it.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Path,
        [string]
        $Method = 'Get',
        $Body,
        [string]
        $Token,
        # Compress a body over 1 KB (Content-Encoding: gzip). The signature covers the compressed
        # bytes, the server checks it before it inflates anything.
        [switch]
        $Gzip
    )

    $client = Get-HttpClient
    $method = $Method.ToUpperInvariant()
    $bytes = if ($null -ne $Body) { [System.Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10 -Compress)) } else { [byte[]]@() }
    $gzipped = $false
    if ($Gzip -and $bytes.Length -gt 1024) {
        $memory = New-Object System.IO.MemoryStream
        $zip = New-Object System.IO.Compression.GZipStream($memory, [System.IO.Compression.CompressionMode]::Compress, $true)
        try { $zip.Write($bytes, 0, $bytes.Length) } finally { $zip.Dispose() }
        $bytes = $memory.ToArray()
        $memory.Dispose()
        $gzipped = $true
    }
    $bodyHash = Get-Sha256Hex -Bytes $bytes

    for ($attempt = 1; ; $attempt++) {
        $nonce = New-Nonce
        $timestamp = Get-UnixTime
        $request = New-Object System.Net.Http.HttpRequestMessage((New-Object System.Net.Http.HttpMethod($method)), "$($ServerUrl.TrimEnd('/'))/api/$Path")
        $request.Headers.Add('Accept', 'application/json')
        if ($Token) { $request.Headers.Add('Authorization', "Bearer $Token") }
        $request.Headers.Add('X-MDM-Timestamp', "$timestamp")
        $request.Headers.Add('X-MDM-Nonce', $nonce)
        $request.Headers.Add('X-MDM-Signature', (New-DeviceSignature -Context 'MDM1-REQ' -Message "$method`n/api/$Path`n$timestamp`n$nonce`n$bodyHash"))
        if ($bytes.Length -gt 0) {
            $request.Content = New-Object System.Net.Http.ByteArrayContent(, $bytes)
            $request.Content.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/json; charset=utf-8')
            if ($gzipped) { $request.Content.Headers.ContentEncoding.Add('gzip') }
        }

        try {
            $response = $client.SendAsync($request).GetAwaiter().GetResult()
            $content = $response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
        }
        finally {
            $request.Dispose()
        }

        $device = Get-ResponseHeader -Response $response -Name 'X-MDM-Device'
        $serverTime = Get-ResponseHeader -Response $response -Name 'X-MDM-Timestamp'
        $signed = Test-ServerSignature -Context 'MDM1-RESP' -Message "$device`n$nonce`n$serverTime`n$(Get-Sha256Hex -Bytes $content)" -Signature (Get-ResponseHeader -Response $response -Name 'X-MDM-Signature')
        if (-not $signed) {
            $unsigned = New-Object System.Exception("The response of $Path (HTTP $([int]$response.StatusCode)) is not signed with the pinned server key, it is ignored")
            # A gateway in front of the server that has nothing to forward to (the server restarts: a new version is
            # deployed and the agents update right after it) answers 502 / 503 / 504 without the signature. That is
            # the server not being reached, like a refused connection, not an alert of the device.
            if ([int]$response.StatusCode -in 502, 503, 504) { $unsigned.Data['MdmGateway'] = $true }
            throw $unsigned
        }
        if ($script:DeviceId -and "$device" -ne "$($script:DeviceId)" -and $response.IsSuccessStatusCode) {
            throw "The response of $Path is signed for device $device, not for this device ($($script:DeviceId))"
        }
        $script:ClockOffset = [long]$serverTime - [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()

        $text = [System.Text.Encoding]::UTF8.GetString($content)
        $data = if ($text.Trim()) { $text | ConvertFrom-Json } else { $null }
        if ($response.IsSuccessStatusCode) {
            return $data
        }

        $code = if ($data -and $data.error) { "$($data.error)" } elseif ($data -and $data.message) { "$($data.message)" } else { "$($response.ReasonPhrase)" }
        # The clock of the device is off: the signed server time corrected it, try once more.
        if ($code -eq 'clock_skew' -and $attempt -eq 1) {
            Write-AgentLog ('Clock differs from the server by {0} s, using the server time' -f $script:ClockOffset)
            continue
        }
        if ($code -eq 'key_not_registered') {
            $script:KeyRegistered = $false
        }
        $exception = New-Object System.Exception("$Path failed: HTTP $([int]$response.StatusCode) $code")
        $exception.Data['MdmError'] = $code
        $exception.Data['MdmStatus'] = [int]$response.StatusCode
        $exception.Data['MdmBody'] = $data
        throw $exception
    }
}

function Register-MDMDevice {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $EnrolmentCode
    )

    # The public key goes with the enrolment, the request is signed with it.
    $response = Invoke-MdmApi -Method Post -Path 'device/register' -Body @{ 'enrolment_code' = $EnrolmentCode; 'public_key' = Get-DevicePublicKey }
    if (-not $response.token) {
        throw "Enrolment failed: $response"
    }

    $config = Get-AgentConfig
    $config['device_id'] = [int]$response.device_id
    $config['key_registered'] = $true
    Save-AgentConfig -Config $config
    $script:DeviceId = $config['device_id']
    $script:KeyRegistered = $true

    return $response.token
}

function Register-DeviceKey {
    # Agents enrolled before 1.7.0 register their key once with the device token; afterwards the
    # server accepts only requests signed with it.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $config = Get-AgentConfig
    try {
        $response = Invoke-MdmApi -Method Post -Path 'device/key' -Token $Token -Body @{ public_key = Get-DevicePublicKey }
        $config['device_id'] = [int]$response.device_id
        Write-AgentLog "Device key registered (device $($response.device_id))"
    }
    catch {
        if ($_.Exception.Data['MdmError'] -ne 'key_already_registered') {
            throw
        }
        # Registered before (the configuration was lost). If it is another key, requests fail
        # with invalid_signature until an admin resets the device key in the portal.
        Write-AgentLog 'The server already has a key for this device'
    }
    $config['key_registered'] = $true
    Save-AgentConfig -Config $config
    $script:DeviceId = $config['device_id']
    $script:KeyRegistered = $true
}

function Protect-MachineSecret {
    # DPAPI bound to this computer (not to a user): for handing the token from the installing
    # administrator to the SYSTEM task. The file is readable by SYSTEM and administrators only.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Text
    )

    try { Add-Type -AssemblyName System.Security -ErrorAction Stop } catch { }
    $bytes = [System.Security.Cryptography.ProtectedData]::Protect([System.Text.Encoding]::UTF8.GetBytes($Text), $null, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    return [Convert]::ToBase64String($bytes)
}

function Unprotect-MachineSecret {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Protected
    )

    try { Add-Type -AssemblyName System.Security -ErrorAction Stop } catch { }
    $bytes = [System.Security.Cryptography.ProtectedData]::Unprotect([Convert]::FromBase64String($Protected.Trim()), $null, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    return [System.Text.Encoding]::UTF8.GetString($bytes)
}

function Read-UserToken {
    # Token.xml: a SecureString (DPAPI) readable only by the account that wrote it.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Path
    )

    $auth = Import-Clixml -Path $Path
    return [System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($auth.token))
}

function Save-UserToken {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Path,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    @{ token = ($Token | ConvertTo-SecureString -AsPlainText -Force) } | Export-Clixml -Path $Path
    Protect-AgentPath -Path $Path
}

function Get-EnrolmentCode {
    # From -EnrolmentCode, or saved by -Install for the SYSTEM task (Windows); asked for only when
    # someone runs the agent interactively.
    $code = $EnrolmentCode
    if (-not $code) { $code = (Get-AgentConfig)['enrolment_code'] }
    if (-not $code -and [Environment]::UserInteractive -and $Host.Name -eq 'ConsoleHost') {
        $code = Read-Host -Prompt 'Enrolment code'
    }
    if (-not $code) {
        throw 'The device is not enrolled: run the install command from Add device again (with a new enrolment code).'
    }
    return "$code"
}

function Get-AgentToken {
    if ($OnLinux) {
        # SecureString export is Windows only (DPAPI); keep the token in a root-only file.
        $tokenPath = "$AgentDir/token"
        if (-not (Test-Path -Path $tokenPath)) {
            Set-Content -Path $tokenPath -Value (Register-MDMDevice -EnrolmentCode (Get-EnrolmentCode)) -NoNewline
            Protect-AgentPath -Path $tokenPath
        }
        return (Get-Content -Path $tokenPath -Raw).Trim()
    }

    # Windows: the token is enrolled and kept by the account the agent runs as (SYSTEM), in
    # Token.xml encrypted for that account.
    $userPath = Join-Path $AgentDir 'Token.xml'
    $machinePath = Join-Path $AgentDir 'token.dat'
    if (Test-Path -Path $userPath) {
        try {
            return Read-UserToken -Path $userPath
        }
        catch {
            throw "Token.xml cannot be decrypted by $([System.Security.Principal.WindowsIdentity]::GetCurrent().Name): it belongs to another account (agents before 1.7.0 enrolled as the installing administrator). Run the install command again as Administrator, it hands the token over."
        }
    }

    if (Test-Path -Path $machinePath) {
        # Handed over by -Install: from now on only this account can read it.
        $token = Unprotect-MachineSecret -Protected (Get-Content -Path $machinePath -Raw)
        Save-UserToken -Path $userPath -Token $token
        Remove-Item -Path $machinePath -Force
        Write-AgentLog 'Device token taken over from the installation'
        return $token
    }

    $token = Register-MDMDevice -EnrolmentCode (Get-EnrolmentCode)
    Save-UserToken -Path $userPath -Token $token
    $config = Get-AgentConfig
    if ($config.ContainsKey('enrolment_code')) {
        $config.Remove('enrolment_code')
        Save-AgentConfig -Config $config
    }
    Write-AgentLog "Device enrolled (device $($script:DeviceId))"
    return $token
}

# The options in config.json: key => @{ Variable; Default; Min } (seconds).
$AgentIntervals = [ordered]@{
    report_interval    = @{ Variable = 'ReportInterval'; Default = 300; Min = 60 }
    heartbeat_interval = @{ Variable = 'HeartbeatInterval'; Default = 30; Min = 10 }
    inventory_interval = @{ Variable = 'InventoryInterval'; Default = 21600; Min = 600 }
    health_interval    = @{ Variable = 'HealthInterval'; Default = 3600; Min = 300 }
}

function Initialize-AgentSettings {
    # The options from config.json, unless given on the command line (older installations):
    # intervals (at least their minimum) and realtime.
    $config = Get-AgentConfig
    foreach ($key in $AgentIntervals.Keys) {
        $option = $AgentIntervals[$key]
        if ($LegacyOptions.ContainsKey($option.Variable) -or $null -eq $config[$key]) { continue }
        $value = 0
        if ([int]::TryParse("$($config[$key])", [ref]$value) -and $value -ge $option.Min) {
            Set-Variable -Scope Script -Name $option.Variable -Value $value
        } else {
            Write-AgentLog "$key '$($config[$key])' in config.json is not a number of seconds from $($option.Min), using $($option.Default)" -IsError
        }
    }
    if (-not $NoRealtime -and $config['realtime'] -eq $false) {
        $script:NoRealtime = $true
    }
}

function Save-AgentSettings {
    # -Install: the options given on the command line (of agents before 1.16.0 too) go to
    # config.json, the task / service only gets -ServerUrl.
    param ([Parameter(Mandatory = $true)] [hashtable] $Config)

    $changed = $false
    foreach ($key in $AgentIntervals.Keys) {
        $option = $AgentIntervals[$key]
        if ($LegacyOptions.ContainsKey($option.Variable)) {
            $value = (Get-Variable -Name $option.Variable -ValueOnly)
            if ($value -eq $option.Default) { $Config.Remove($key) } else { $Config[$key] = [Math]::Max($option.Min, $value) }
            $changed = $true
        }
    }
    if ($NoRealtime) {
        $Config['realtime'] = $false
        $changed = $true
    }
    if ($ReverbHost -or $ReverbPort -or $ReverbScheme -or $ReverbKey) {
        Write-Host 'The -Reverb* options are not used anymore: the WebSocket is /app at the address of -ServerUrl.' -ForegroundColor Yellow
    }
    if ($changed) {
        Save-AgentConfig -Config $Config
    }
}

function Get-AgentArguments {
    # The command line of the task / service: only the portal, the options are in config.json.
    return '-ServerUrl "{0}"' -f $ServerUrl
}

function Remove-TemporaryInstaller {
    # The install command downloads the agent to the temp directory; the installed copy lives elsewhere.
    if ($PSCommandPath.StartsWith([System.IO.Path]::GetTempPath())) {
        Remove-Item -Path $PSCommandPath -Force -ErrorAction SilentlyContinue
    }
}

function Test-AgentAdmin {
    if ($OnLinux) {
        return (id -u) -eq '0'
    }

    $principal = New-Object System.Security.Principal.WindowsPrincipal([System.Security.Principal.WindowsIdentity]::GetCurrent())
    return $principal.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Register-AgentService {
    # Linux: systemd service running as root, restarted when it exits, low CPU and I/O priority.
    $pwsh = (Get-Process -Id $PID).Path
    $unit = @"
[Unit]
Description=Laravel-MDM agent
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=$pwsh -NoLogo -NoProfile -NonInteractive -File "$AgentDir/app.ps1" $(Get-AgentArguments)
WorkingDirectory=$AgentDir
Restart=always
RestartSec=10
Nice=10
IOSchedulingClass=idle

[Install]
WantedBy=multi-user.target
"@
    Set-Content -Path /etc/systemd/system/laravel-mdm-agent.service -Value $unit
    systemctl daemon-reload
    systemctl enable --now laravel-mdm-agent.service
    systemctl restart laravel-mdm-agent.service
}

function Register-AgentTask {
    # Starts the agent at boot; the repetition acts as a watchdog, a running instance is not started twice.
    $Trigger1 = New-ScheduledTaskTrigger -AtStartup
    $Trigger2 = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15)
    $Settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $arguments = '-WindowStyle Hidden -ExecutionPolicy Bypass -NoLogo -File "{0}\app.ps1" {1}' -f $AgentDir, (Get-AgentArguments)
    $Action = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument $arguments -WorkingDirectory $AgentDir

    # A running agent (update) would keep the old version; the task never starts a second instance.
    Stop-ScheduledTask -TaskName "Laravel-MDM-Agent" -ErrorAction SilentlyContinue
    Register-ScheduledTask -TaskName "Laravel-MDM-Agent" -Trigger @($Trigger1, $Trigger2) -Settings $Settings -User "NT AUTHORITY\SYSTEM" -Action $Action -RunLevel Highest -Force | Out-Null
    Start-ScheduledTask -TaskName "Laravel-MDM-Agent"
}

function Restart-AgentTask {
    # Starts the agent again once this instance has exited (the caller exits right after). With
    # -Watch (agent update) it also rolls the update back: when the new agent has not reported to
    # the server within 5 minutes (agent-update.json is still there), app.ps1.previous is restored.
    # On Windows the task ignores a start while it runs and a process started from it counts as part
    # of it, so a separate one-off task does this; otherwise only the 15 minute watchdog would start
    # the new agent. On Linux systemd restarts the agent, the watcher runs in its own unit.
    param ([switch]$Watch)

    $dir = $AgentDir.Replace("'", "''")
    $script = @"
`$ErrorActionPreference = 'SilentlyContinue'
`$dir = '$dir'
`$onLinux = `$$([bool]$OnLinux)
function Write-Log(`$message) { '{0:yyyy-MM-dd HH:mm:ss} {1}' -f (Get-Date), `$message | Add-Content -Path "`$dir/agent.log" -Encoding UTF8 }
function Start-AgentNow {
    if (`$onLinux) { systemctl start laravel-mdm-agent.service; return }
    for (`$i = 0; `$i -lt 60; `$i++) {
        if ((Get-ScheduledTask -TaskName 'Laravel-MDM-Agent').State -eq 'Running') { return }
        Start-ScheduledTask -TaskName 'Laravel-MDM-Agent'
        Start-Sleep -Seconds 2
    }
    Write-Log 'Restart: the agent task did not start'
}
Wait-Process -Id $PID -Timeout 120
Start-AgentNow
if (`$$([bool]$Watch) -and (Test-Path -Path "`$dir/app.ps1.previous")) {
    `$deadline = (Get-Date).AddMinutes(5)
    while ((Get-Date) -lt `$deadline -and (Test-Path -Path "`$dir/agent-update.json")) { Start-Sleep -Seconds 5 }
    if (Test-Path -Path "`$dir/agent-update.json") {
        Write-Log 'Agent update: the new version did not report to the server within 5 minutes, rolling back'
        if (`$onLinux) { systemctl stop laravel-mdm-agent.service } else { Stop-ScheduledTask -TaskName 'Laravel-MDM-Agent'; Start-Sleep -Seconds 3 }
        Copy-Item -Path "`$dir/app.ps1.previous" -Destination "`$dir/app.ps1" -Force
        `$pending = Get-Content -Path "`$dir/pending-command.json" -Raw -Encoding UTF8 | ConvertFrom-Json
        if (`$pending) {
            `$pending | Add-Member -NotePropertyName rolled_back -NotePropertyValue (Get-Content -Path "`$dir/agent-update.json" -Raw -Encoding UTF8 | ConvertFrom-Json).to -Force
            `$pending | ConvertTo-Json -Compress | Set-Content -Path "`$dir/pending-command.json" -Encoding UTF8
        }
        Remove-Item -Path "`$dir/agent-update.json" -Force
        Start-AgentNow
    }
}
if (-not `$onLinux) { Unregister-ScheduledTask -TaskName 'Laravel-MDM-Agent-Restart' -Confirm:`$false }
"@
    $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($script))
    if ($OnLinux) {
        if (-not $Watch) { return }
        $pwsh = (Get-Process -Id $PID).Path
        # Not in the agent's cgroup: systemd would stop it together with the agent.
        systemd-run --unit="laravel-mdm-agent-restart-$PID" --collect --quiet $pwsh -NoProfile -NonInteractive -EncodedCommand $encoded 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-AgentLog 'Agent update: systemd-run failed, no rollback watcher' -IsError }
        return
    }
    try {
        $action = New-ScheduledTaskAction -Execute 'PowerShell.exe' -Argument "-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -EncodedCommand $encoded"
        $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
        Register-ScheduledTask -TaskName 'Laravel-MDM-Agent-Restart' -Action $action -Settings $settings -User 'NT AUTHORITY\SYSTEM' -RunLevel Highest -Force -ErrorAction Stop | Out-Null
        Start-ScheduledTask -TaskName 'Laravel-MDM-Agent-Restart' -ErrorAction Stop
    }
    catch {
        Write-AgentLog "Restart task not available ($($_.Exception.Message)), restarting directly"
        Start-Process -FilePath powershell.exe -WindowStyle Hidden -ArgumentList "-NoProfile -ExecutionPolicy Bypass -EncodedCommand $encoded"
    }
}

function Start-AgentJob {
    # Start-Job with the given agent functions defined in the job. Windows PowerShell passes an
    # -InitializationScript on the command line of the job process (32767 characters at most), so
    # the definitions go with the arguments instead, through the pipe to the job.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Name,
        [Parameter(Mandatory = $true)]
        [string[]]
        $Functions,
        [Parameter(Mandatory = $true)]
        [scriptblock]
        $ScriptBlock,
        [object[]]
        $ArgumentList = @()
    )

    $definitions = ($Functions | ForEach-Object { "function $_ {$((Get-Item -Path "function:$_").ScriptBlock)}" }) -join "`n"
    return Start-Job -Name $Name -ArgumentList (@($definitions, "$ScriptBlock") + $ArgumentList) -ScriptBlock {
        param ($Definitions, $Body)
        . ([scriptblock]::Create($Definitions))
        & ([scriptblock]::Create($Body)) @args
    }
}

function Start-InventoryCollection {
    # Windows Update search and winget are expensive, run them rarely in a separate idle-priority process.
    return Start-AgentJob -Name 'inventory' -Functions 'Get-ChromeInstall', 'Get-ChromeUpdate', 'Get-WingetSoftware', 'Get-WingetUpdates', 'Invoke-WingetInUserSession', 'Get-WindowsUpdate', 'Save-AptUpdateState', 'Get-AptUpdates', 'Get-UserCommand', 'Get-FlatpakUpdates', 'Get-SnapUpdates', 'Get-PythonUpdates', 'Get-PowerShellReleaseUpdate', 'Get-PowerShellManager', 'ConvertFrom-WingetTable', 'Get-WingetPath', 'Get-PowerShellHosts', 'Invoke-PowerShellModules' -ArgumentList $OnLinux, "$AgentDir/apt-update.json" -ScriptBlock {
        param ($OnLinux, $AptStatePath)
        try { [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::Idle } catch { }
        $data = @{}
        try { $data['module_updates'] = @(Invoke-PowerShellModules -OnLinux $OnLinux | Where-Object { $_.Version }) } catch { }
        $packages = @()
        if ($OnLinux) {
            try { $data['os_updates'] = @(Get-AptUpdates -StatePath $AptStatePath) } catch { $data['os_updates_error'] = $_.Exception.Message }
            try { $packages += @(Get-FlatpakUpdates) } catch { }
            try { $packages += @(Get-SnapUpdates) } catch { }
        } else {
            try { $data['os_updates'] = @(Get-WindowsUpdate) } catch { }
            try { $packages += @(Get-WingetUpdates | Select-Object -Property Id, Version, Avaliable, Source, Scope) } catch { }
            try { $packages += @(Get-ChromeUpdate -Known $packages) } catch { }
        }
        try { $packages += @(Get-PythonUpdates) } catch { }
        try { $packages += @(Get-PowerShellReleaseUpdate -OnLinux $OnLinux -Known (@($data['os_updates']) + $packages)) } catch { }
        $data['packages_updates'] = $packages
        return $data
    }
}

function Start-HealthCollection {
    param (
        $Previous
    )

    # smartctl / storage reliability counters talk to every disk, run them rarely and at idle priority.
    return Start-AgentJob -Name 'health' -Functions 'Get-DiskHealth', 'Get-LinuxDiskHealth' -ArgumentList $OnLinux, $Previous -ScriptBlock {
        param ($OnLinux, $Previous)
        try { [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::Idle } catch { }
        try {
            return Get-DiskHealth -Previous $Previous
        }
        catch {
            return @{ error = $_.Exception.Message }
        }
    }
}

function Start-SecurityCollection {
    param (
        # Events from this time on (the start of the previous collection that was sent).
        [datetime]
        $Since,
        [bool]
        $InContainer,
        # Whether the records of the security logs are read and sent (security_logs in config.json),
        # what the server asks for of them and where the previous collection stopped (JSON).
        [bool]
        $SendLogs = $false,
        [string]
        $PolicyJson = '',
        [string]
        $CursorsJson = ''
    )

    return Start-AgentJob -Name 'security' -Functions 'ConvertTo-SecurityText', 'Get-DpkgFixedCves', 'Get-AptPolicy', 'Get-OfflineUserSoftware', 'Get-AppxSoftware', 'Get-LinuxFirewall', 'Test-LinuxDeviceEncrypted', 'Get-LinuxUnencryptedMounts', 'Get-AptUpdateState', 'Add-SecuritySoftwareContext', 'Get-SecurityOs', 'Get-SecuritySoftware', 'Get-SecurityProcesses', 'Get-SecurityListening', 'Get-SecurityStartup', 'Get-SecurityAdmins', 'Get-SecurityPosture', 'Get-SecurityShortHash', 'Get-SecurityLogs', 'Get-SecurityInventory' -ArgumentList $OnLinux, $Since, $PID, $InContainer, $SendLogs, $PolicyJson, $CursorsJson, "$AgentDir/security-cves.json", "$AgentDir/apt-update.json" -ScriptBlock {
        param ($OnLinux, $Since, $AgentPid, $InContainer, $SendLogs, $PolicyJson, $CursorsJson, $CachePath, $AptStatePath)
        try { [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::Idle } catch { }
        return Get-SecurityInventory -OnLinux $OnLinux -CachePath $CachePath -Since $Since -AgentPid $AgentPid -InContainer $InContainer -SendLogs $SendLogs -PolicyJson $PolicyJson -CursorsJson $CursorsJson -AptStatePath $AptStatePath
    }
}

function Get-SecurityPolicy {
    # What the server wants of the logs (the identifiers / event ids its parsers read), kept in
    # security-policy.json: the cached one when the server cannot be asked, none before it was.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $path = "$AgentDir/security-policy.json"
    try {
        $policy = Invoke-MdmApi -Method Get -Path 'device/security/policy' -Token $Token
        if ($policy -and $policy.sources) {
            $policy | ConvertTo-Json -Depth 6 -Compress | Set-Content -Path $path -Encoding UTF8
            return $policy
        }
    }
    catch {
        Write-AgentLog "Security policy not taken: $($_.Exception.Message)"
    }
    try { return (Get-Content -Path $path -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json) } catch { return $null }
}

function Get-SecurityCursors {
    # Where each log was left off, as far as the server acknowledged (security-cursors.json).
    $cursors = @{}
    try {
        foreach ($property in (Get-Content -Path "$AgentDir/security-cursors.json" -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json).PSObject.Properties) { $cursors[$property.Name] = $property.Value }
    }
    catch { }
    return $cursors
}

function Get-SecurityState {
    # When the last collection that reached the server started: the next one reads the events from then.
    try {
        $state = Get-Content -Path "$AgentDir/security.json" -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json
        return @{ Since = [datetime]$state.since; SentAt = [datetime]$state.sent_at }
    }
    catch {
        return $null
    }
}

# The fields of every source of the security inventory and the ones that tell its items apart
# (App\Support\SecurityRules::SOURCES on the server): an item's key and hash come from them.
$SecurityFields = @{
    software  = @('Name', 'Version', 'Publisher', 'Source', 'FixedCves', 'SourcePackage', 'Candidate', 'Origin', 'Channel', 'UpdateAvailable', 'NoUpdateSource', 'Running', 'RunUser', 'Listening')
    processes = @('Name', 'Path', 'CommandLine', 'User', 'Count')
    listening = @('Protocol', 'Address', 'Port', 'Process', 'Path')
    startup   = @('Name', 'Command', 'Location', 'User')
    admins    = @('Name', 'Source', 'Enabled')
    os        = @('Name', 'Version', 'Id', 'Kernel', 'Codename')
    posture   = @('FirewallEnabled', 'AntivirusName', 'AntivirusEnabled', 'AntivirusUpToDate', 'RealTimeProtection', 'DiskEncrypted', 'SecureBoot', 'RdpEnabled', 'RdpNla', 'Smb1Enabled', 'UacEnabled', 'GuestEnabled', 'AutoLogon', 'SshRootLogin', 'SshPasswordAuthentication', 'AutomaticUpdates', 'DaysSinceUpdate', 'FirewallDetail', 'UnencryptedMounts', 'AptUpdateOk', 'AptUpdateErrors')
}
$SecurityKeyFields = @{
    software  = @('Name', 'Source')
    processes = @('Name', 'Path', 'CommandLine')
    listening = @('Protocol', 'Address', 'Port')
    startup   = @('Name', 'Location')
    admins    = @('Name')
    os        = @('Id')
}

function ConvertTo-SecurityCanon ($Value) {
    # A value as text for hashing, the same as the server: "" for none, true / false, numbers as they are.
    if ($null -eq $Value) { return '' }
    if ($Value -is [bool]) { return $(if ($Value) { 'true' } else { 'false' }) }
    return [string]::Format([System.Globalization.CultureInfo]::InvariantCulture, '{0}', $Value)
}

function Get-SecurityShortHash ([string]$Text) {
    return (Get-Sha256Hex -Bytes ([System.Text.Encoding]::UTF8.GetBytes($Text))).Substring(0, 16)
}

function Get-SecurityItemKey ([string]$Source, $Item) {
    # Tells an item apart from the others of its source (posture is one item).
    if ($Source -eq 'posture') { return 'posture' }
    $parts = foreach ($field in $SecurityKeyFields[$Source]) { (ConvertTo-SecurityCanon $Item.$field).ToLowerInvariant() }
    return Get-SecurityShortHash ($parts -join [string][char]0x1f)
}

function Get-SecurityItemHash ([string]$Source, $Item) {
    # The content of an item: all its fields in the order of its source.
    # The fields older agents did not send (SecurityRules::OPTIONAL_FIELDS on the server) count only when they are set, so the
    # hashes of the items without them stay what they were.
    $optional = 'FixedCves', 'SourcePackage', 'Candidate', 'Origin', 'Channel', 'UpdateAvailable', 'NoUpdateSource', 'Running', 'RunUser', 'Listening', 'Codename', 'FirewallDetail', 'UnencryptedMounts', 'AptUpdateOk', 'AptUpdateErrors'
    $parts = foreach ($field in $SecurityFields[$Source]) {
        $value = ConvertTo-SecurityCanon $Item.$field
        if ($field -in $optional -and $value -eq '') { continue }
        $value
    }
    return Get-SecurityShortHash ($parts -join [string][char]0x1f)
}

function Get-SecurityStateHash ($Map) {
    # The state of a source: a hash of its sorted "key:hash" lines (the server computes the same).
    $keys = [string[]]@($Map.Keys)
    [Array]::Sort($keys, [System.StringComparer]::Ordinal)
    $lines = foreach ($key in $keys) { "${key}:$($Map[$key])" }
    return Get-Sha256Hex -Bytes ([System.Text.Encoding]::UTF8.GetBytes(($lines -join "`n")))
}

function Get-SecurityStateFile {
    # What the server acknowledged for each source: its state and the hash of each item (security-state.json).
    try {
        $raw = Get-Content -Path "$AgentDir/security-state.json" -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json
    }
    catch {
        return @{}
    }
    $state = @{}
    foreach ($property in $raw.PSObject.Properties) {
        $items = @{}
        foreach ($item in $property.Value.items.PSObject.Properties) { $items[$item.Name] = "$($item.Value)" }
        $state[$property.Name] = @{ state = "$($property.Value.state)"; items = $items }
    }
    return $state
}

function Get-SecurityDeltas {
    # The sources of a collection: only what changed against the state the server acknowledged
    # ({source, state} when nothing did, {source, base, state, upsert, remove} otherwise, the whole
    # source with full: true when there is no state or the server asked for it).
    param (
        [Parameter(Mandatory = $true)]
        $Inventory,
        [Parameter(Mandatory = $true)]
        $Acknowledged,
        [string[]]
        $Whole = @()
    )

    $sources = New-Object System.Collections.Generic.List[object]
    $pending = @{}
    foreach ($source in 'software', 'processes', 'listening', 'startup', 'admins', 'os', 'posture') {
        if (-not $Inventory.ContainsKey($source)) { continue }
        $items = if ($source -eq 'posture') { @($Inventory[$source]) } else { @($Inventory[$source]) }
        $rows = @{}
        $map = @{}
        foreach ($item in $items) {
            if ($null -eq $item) { continue }
            $key = Get-SecurityItemKey $source $item
            $hash = Get-SecurityItemHash $source $item
            $rows[$key] = @($key, $hash, $item)
            $map[$key] = $hash
        }
        $state = Get-SecurityStateHash $map
        $previous = $Acknowledged[$source]
        $delta = [ordered]@{ source = $source; state = $state }
        # The rows go into a list, not through the pipeline: Windows PowerShell 5.1 would write an
        # array that went through it as {"value": [...], "Count": 3} in JSON.
        $upsert = New-Object System.Collections.Generic.List[object]
        if (-not $previous -or $Whole -contains $source) {
            $delta['full'] = $true
            foreach ($row in $rows.Values) { $upsert.Add($row) }
            $delta['upsert'] = $upsert.ToArray()
        }
        elseif ($previous.state -ne $state) {
            $delta['base'] = $previous.state
            foreach ($key in $map.Keys) { if ($previous.items[$key] -ne $map[$key]) { $upsert.Add($rows[$key]) } }
            $delta['upsert'] = $upsert.ToArray()
            $delta['remove'] = [string[]]@($previous.items.Keys | Where-Object { -not $map.ContainsKey($_) })
        }
        $sources.Add($delta)
        $pending[$source] = @{ state = $state; items = $map }
    }

    return @{ Sources = $sources.ToArray(); Pending = $pending }
}

function Send-SecurityInventory {
    # The security collection to the server (its scanner runs the rules): the inventory as deltas
    # against what the server acknowledged, the raw log records. A delta the server does not follow
    # (409) is sent again with those sources whole. The state is kept only once it is acknowledged.
    # Returns whether it was taken.
    param (
        [Parameter(Mandatory = $true)]
        $Data,
        [Parameter(Mandatory = $true)]
        [datetime]
        $StartedAt,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $acknowledged = Get-SecurityStateFile
    $whole = @()
    $sentBytes = 0
    for ($attempt = 1; $attempt -le 2; $attempt++) {
        $deltas = Get-SecurityDeltas -Inventory $Data.inventory -Acknowledged $acknowledged -Whole $whole
        $body = @{ collection_id = [guid]::NewGuid().ToString(); sources = $deltas.Sources; logs = @($Data.logs); cursors = $Data.cursors }
        try {
            $response = Invoke-MdmApi -Method Post -Path 'device/security' -Body $body -Token $Token -Gzip
            break
        }
        catch {
            $status = $_.Exception.Data['MdmStatus']
            # A server without the scanner: nothing to resend.
            if ($status -in 403, 404) { return $true }
            if ($status -eq 409 -and $attempt -eq 1) {
                $whole = @($_.Exception.Data['MdmBody'].resync)
                Write-AgentLog "Security inventory: the server asked for $($whole -join ', ') whole"
                continue
            }
            throw
        }
    }

    # Acknowledged: this is what the server has now.
    $kept = @{}
    foreach ($property in $response.ack.PSObject.Properties) {
        if ($deltas.Pending.ContainsKey($property.Name) -and $deltas.Pending[$property.Name].state -eq $property.Value) { $kept[$property.Name] = $deltas.Pending[$property.Name] }
    }
    foreach ($source in $acknowledged.Keys) { if (-not $kept.ContainsKey($source)) { $kept[$source] = $acknowledged[$source] } }
    $kept | ConvertTo-Json -Depth 4 -Compress | Set-Content -Path "$AgentDir/security-state.json" -Encoding UTF8
    # The logs are read on from where the server took them.
    if ($response.cursors) {
        $cursors = Get-SecurityCursors
        foreach ($property in $response.cursors.PSObject.Properties) { $cursors[$property.Name] = $property.Value }
        $cursors | ConvertTo-Json -Compress | Set-Content -Path "$AgentDir/security-cursors.json" -Encoding UTF8
    }
    @{ since = $StartedAt.ToString('o'); sent_at = (Get-Date).ToString('o') } | ConvertTo-Json -Compress | Set-Content -Path "$AgentDir/security.json" -Encoding UTF8

    $changed = 0
    foreach ($delta in $deltas.Sources) { $changed += @($delta.upsert).Count + @($delta.remove).Count }
    $records = 0
    foreach ($log in @($Data.logs)) { $records += @($log.records).Count }
    Write-AgentLog ('Security collection sent: {0} changed item(s) of {1} source(s), {2} log record(s)' -f $changed, @($deltas.Sources).Count, $records)

    return $true
}

function Get-CachedInventory {
    param (
        [string]
        $Name = 'inventory'
    )

    $path = "$AgentDir/$Name.json"
    if (-not (Test-Path -Path $path)) {
        return $null
    }

    try {
        $cache = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
        # Collected by another agent version (update): still reported until the new one is ready (the
        # portal would lose its update list meanwhile), but collected again right away.
        return @{ CollectedAt = [DateTime]$cache.collected_at; Data = $cache.data; Stale = $cache.agent_version -ne $AgentVersion }
    }
    catch {
        return $null
    }
}

function Save-CachedInventory {
    param (
        [Parameter(Mandatory = $true)]
        $Data,
        [string]
        $Name = 'inventory'
    )

    @{ collected_at = (Get-Date).ToString('o'); agent_version = $AgentVersion; data = $Data } | ConvertTo-Json -Depth 6 -Compress | Set-Content -Path "$AgentDir/$Name.json" -Encoding UTF8
}

function Get-Report {
    param (
        $Inventory,
        $Health
    )

    $data = @{ machine = if ($OnLinux) { Get-LinuxMachineInfo } else { Get-MachineInfo } }
    $config = Get-AgentConfig
    $data.machine | Add-Member -NotePropertyName ScriptsEnabled -NotePropertyValue ([bool]$config['scripts_enabled']) -Force
    $data.machine | Add-Member -NotePropertyName ServerKeyFingerprint -NotePropertyValue $config['server_key'].fingerprint -Force
    $discovery = Get-NetworkDiscovery -Config $config
    $portScan = Get-PortScan -Config $config
    $data.machine | Add-Member -NotePropertyName NetworkDiscovery -NotePropertyValue $discovery -Force
    $data.machine | Add-Member -NotePropertyName PortScan -NotePropertyValue $portScan -Force
    # What this agent is set to do (the portal shows it on the Agent tab, read only).
    $data.machine | Add-Member -NotePropertyName Features -NotePropertyValue ([ordered]@{
            scripts           = [bool]$config['scripts_enabled']
            network_discovery = $discovery
            port_scan         = $portScan
            security_logs     = (Get-SecurityLogsLevel -Config $config)
        }) -Force
    try { Add-PublicAddresses -Networks $data.machine.Networks } catch { Write-AgentLog "Public addresses failed: $($_.Exception.Message)" }
    if ($discovery -ne 'off') {
        try { $data['neighbours'] = @(Get-Neighbours) } catch { Write-AgentLog "Neighbours failed: $($_.Exception.Message)" -ErrorRecord $_ }
    }
    if ($Inventory) {
        # Not sent when the check failed: a failed check is not "0 updates".
        if ($null -ne $Inventory.os_updates) { $data['os_updates'] = $Inventory.os_updates }
        if ($Inventory.os_updates_error) { $data['os_updates_error'] = $Inventory.os_updates_error }
        $data['packages_updates'] = $Inventory.packages_updates
        $data['module_updates'] = $Inventory.module_updates
    }
    if ($Health) {
        $data['disk_health'] = $Health
    }
    try { $data['services'] = @(Get-AgentServices) } catch { Write-AgentLog "Services failed: $($_.Exception.Message)" -ErrorRecord $_ }
    try {
        $docker = Get-DockerContainers
        if ($docker) { $data['docker'] = $docker }
    }
    catch {
        Write-AgentLog "Docker failed: $($_.Exception.Message)" -ErrorRecord $_
    }

    return $data
}

function Send-Report {
    param (
        [Parameter(Mandatory = $true)]
        $Data,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $response = Invoke-MdmApi -Method Post -Path 'device' -Body $Data -Token $Token
    # Commands returned here were not delivered over the WebSocket (the server hands them over once).
    Invoke-DeviceCommands -Response $response
    if ($response.scripts_pending) {
        $script:ScriptsRequested = $true
    }
    if ($response.PSObject.Properties['ping_targets']) {
        Set-PingTargets -Targets $response.ping_targets
    }
    if ($response.PSObject.Properties['public_address_url']) {
        $script:PublicAddressUrl = "$($response.public_address_url)"
    }
}

function Add-PublicAddresses {
    # The public address the internet sees from each connected interface with a gateway (each can
    # have its own: a second card, a mobile network, a VPN to the server only), as PublicAddress of
    # the interface, for the network map. Asked of the address the server names (ifconfig.me), at
    # most once an hour per interface; nothing when it names none (MDM_DETECT_PUBLIC_ADDRESS=false).
    param ($Networks)

    $url = "$script:PublicAddressUrl"
    if ($url -notmatch '^https?://') { return }
    if ($script:PublicAddressFrom -ne $url) {
        $script:PublicAddressFrom = $url
        $script:PublicAddresses = @{}
    }
    foreach ($network in @($Networks)) {
        if (-not $network.Connected -or -not $network.Gateway -or $network.Type -in @('vpn', 'virtual', 'docker', 'bluetooth')) { continue }
        $address = $null
        $ipv4 = @($network.IPAddresses) | Where-Object { [System.Net.IPAddress]::TryParse("$_", [ref]$address) -and $address.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork -and "$_" -notlike '169.254.*' } | Select-Object -First 1
        if (-not $ipv4) { continue }
        $key = "$($network.Name)|$ipv4"
        $known = $script:PublicAddresses[$key]
        if (-not $known -or ((Get-Date) - $known.At).TotalMinutes -ge 60) {
            $known = @{ At = Get-Date; Address = (Get-PublicAddressVia -Url $url -Interface "$($network.Name)" -Address ([System.Net.IPAddress]::Parse($ipv4))) }
            $script:PublicAddresses[$key] = $known
        }
        if ($known.Address) {
            $network | Add-Member -NotePropertyName PublicAddress -NotePropertyValue $known.Address -Force
        }
    }
}

function Get-PublicAddressVia {
    # The address an HTTP(S) GET of the URL answers, sent from one interface: a socket bound to its
    # address (Windows sends from the interface that has it), on Linux also to the interface itself
    # (SO_BINDTODEVICE, as root), whatever the default route says. $null when it does not answer.
    param ([string]$Url, [string]$Interface, [System.Net.IPAddress]$Address)

    $uri = [Uri]$Url
    $socket = [System.Net.Sockets.Socket]::new([System.Net.Sockets.AddressFamily]::InterNetwork, [System.Net.Sockets.SocketType]::Stream, [System.Net.Sockets.ProtocolType]::Tcp)
    try {
        $socket.ReceiveTimeout = 5000
        $socket.SendTimeout = 5000
        if ($OnLinux) {
            # SOL_SOCKET (1), SO_BINDTODEVICE (25): the name of the interface, ended by a zero byte.
            $socket.SetRawSocketOption(1, 25, [System.Text.Encoding]::ASCII.GetBytes("$Interface`0"))
        }
        $socket.Bind([System.Net.IPEndPoint]::new($Address, 0))
        $target = [System.Net.Dns]::GetHostAddresses($uri.DnsSafeHost) | Where-Object { $_.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork } | Select-Object -First 1
        if (-not $target -or -not $socket.ConnectAsync($target, $uri.Port).Wait(5000)) { return $null }
        $stream = [System.Net.Sockets.NetworkStream]::new($socket, $false)
        if ($uri.Scheme -eq 'https') {
            $tls = [System.Net.Security.SslStream]::new($stream, $false)
            $tls.AuthenticateAsClient($uri.DnsSafeHost)
            $stream = $tls
        }
        $request = [System.Text.Encoding]::ASCII.GetBytes("GET $($uri.PathAndQuery) HTTP/1.0`r`nHost: $($uri.Authority)`r`nUser-Agent: curl/8.0`r`nAccept: text/plain`r`nConnection: close`r`n`r`n")
        $stream.Write($request, 0, $request.Length)
        $response = [System.IO.StreamReader]::new($stream).ReadToEnd()
        $parts = $response -split "`r?`n`r?`n", 2
        $parsed = $null
        if ($parts.Count -eq 2 -and $parts[0] -match '^HTTP/1\.[01] 200' -and [System.Net.IPAddress]::TryParse($parts[1].Trim(), [ref]$parsed)) {
            return $parsed.ToString()
        }
        return $null
    }
    catch {
        return $null
    }
    finally {
        $socket.Dispose()
    }
}

function Set-PingTargets {
    # Ping-only devices of this network the server asks this agent to ping: an id and an IPv4
    # address each, checked again here (at most 32).
    param ($Targets)

    $script:PingTargets = @(@($Targets) | Where-Object { $_ } | ForEach-Object {
            $address = $null
            if ("$($_.id)" -match '^[0-9]{1,10}$' -and [System.Net.IPAddress]::TryParse("$($_.address)", [ref]$address) -and $address.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork) {
                @{ Id = [long]$_.id; Address = $address }
            }
        } | Select-Object -First 32)
}

function Invoke-Pings {
    # Pings the targets (@{ Id; Address }) at once and returns id => @{ Up; Rtt; Via }. A target in
    # the network of a local interface is pinged from that interface (the system ping: -I on Linux,
    # -S with its address on Windows), not over whatever the default route picks (Docker, VPN, a
    # second card); the others, or without a ping command, with .NET Ping.
    param ($Targets, [int]$TimeoutMs = 1000)

    if ($null -eq $script:PingCommand) {
        $script:PingCommand = if ($OnLinux) { (Get-Command -Name ping -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1).Source } else { "$env:SystemRoot\System32\ping.exe" }
        if (-not $script:PingCommand -or -not (Test-Path -Path $script:PingCommand)) { $script:PingCommand = '' }
        # BusyBox ping (Alpine) knows no -n (it does not resolve names anyway).
        $script:PingNumeric = $OnLinux -and $script:PingCommand -and "$((Get-Item -Path $script:PingCommand -ErrorAction SilentlyContinue).ResolvedTarget)" -notmatch 'busybox'
    }
    $networks = if ($script:PingCommand) { @(Get-LocalNetworks) } else { @() }
    $seconds = [Math]::Max(1, [int][Math]::Ceiling($TimeoutMs / 1000))
    $runs = @(foreach ($target in $Targets) {
            $network = if ($networks.Count -gt 0) { Find-LocalNetwork -Networks $networks -Address $target.Address } else { $null }
            if ($network) {
                $info = New-Object System.Diagnostics.ProcessStartInfo
                $info.FileName = $script:PingCommand
                # The address comes from Set-PingTargets (a parsed IPv4 address), the name from the system.
                $info.Arguments = if ($OnLinux) { "$(if ($script:PingNumeric) { '-n ' })-c 1 -W $seconds -I `"$($network.Name)`" $($target.Address)" } else { "-n 1 -w $TimeoutMs -S $($network.Address) $($target.Address)" }
                $info.UseShellExecute = $false
                $info.RedirectStandardOutput = $true
                $info.RedirectStandardError = $true
                $info.CreateNoWindow = $true
                $process = try { [System.Diagnostics.Process]::Start($info) } catch { $null }
                if ($process) {
                    @{ Id = $target.Id; Process = $process; Output = $process.StandardOutput.ReadToEndAsync(); Via = $network.Name }
                    continue
                }
            }
            $ping = New-Object System.Net.NetworkInformation.Ping
            @{ Id = $target.Id; Ping = $ping; Task = $ping.SendPingAsync($target.Address, $TimeoutMs); Via = $null }
        })
    $deadline = (Get-Date).AddMilliseconds($TimeoutMs + 2000)
    $results = @{}
    foreach ($run in $runs) {
        $left = [Math]::Max(0, [int]($deadline - (Get-Date)).TotalMilliseconds)
        $up = $false
        $rtt = $null
        if ($run.Process) {
            if ($run.Process.WaitForExit($left)) {
                $text = try { $run.Output.Result } catch { '' }
                # Windows answers 0 also for "destination unreachable": only a reply carries TTL=.
                $up = $run.Process.ExitCode -eq 0 -and ($OnLinux -or $text -match 'TTL=')
                if ($up -and $text -match '[=<]\s*([0-9]+(?:[.,][0-9]+)?)\s*ms') { $rtt = [double]($Matches[1].Replace(',', '.')) }
            } else {
                try { $run.Process.Kill() } catch { }
            }
            $run.Process.Dispose()
        } else {
            try { [void]$run.Task.Wait($left) } catch { }
            $reply = if ($run.Task.Status -eq 'RanToCompletion') { $run.Task.Result } else { $null }
            $up = $reply -and $reply.Status -eq [System.Net.NetworkInformation.IPStatus]::Success
            if ($up) { $rtt = [double]$reply.RoundtripTime }
            $run.Ping.Dispose()
        }
        $results[$run.Id] = @{ Up = [bool]$up; Rtt = $rtt; Via = $run.Via }
    }
    return $results
}

function Send-PingResults {
    # Pings the ping-only devices (all at once, 1 s timeout) and reports which answered: the
    # answer is their online status in the portal.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    if (-not $script:PingTargets -or $script:PingTargets.Count -eq 0) {
        return
    }
    $pings = Invoke-Pings -Targets $script:PingTargets -TimeoutMs 1000
    $at = Get-UnixTime
    $results = @($script:PingTargets | ForEach-Object {
            $reply = $pings[$_.Id]
            @{ id = $_.Id; up = [bool]$reply.Up; rtt = $(if ($reply.Up) { $reply.Rtt } else { $null }); at = $at }
        })
    try {
        $response = Invoke-MdmApi -Method Post -Path 'device/pings' -Token $Token -Body @{ results = $results }
        if ($response.PSObject.Properties['ping_targets']) {
            Set-PingTargets -Targets $response.ping_targets
        }
    }
    catch {
        Write-AgentLog "Ping results not sent: $($_.Exception.Message)" -ErrorRecord $_
        # Not reached: the pings go into the history later (see Send-Backlog).
        if ($null -eq $_.Exception.Data['MdmStatus']) { Add-Backlog -Kind 'pings' -Items $results -Max 10000 }
    }
}

function Update-Agent {
    # Replace the installed script with the version the server serves, then restart.
    # The command carries no data: the source is always the -ServerUrl set at install time.
    param (
        $CommandId
    )

    $server = [Uri]$ServerUrl
    if ($server.Scheme -ne 'https' -and -not $server.IsLoopback) {
        Write-AgentLog 'Agent update refused: the server is not reached over HTTPS'
        [void](Send-CommandStatus -Id $CommandId -Status failed -Message 'Refused: the server is not reached over HTTPS')
        return
    }
    [void](Send-CommandStatus -Id $CommandId -Status running -Progress 10 -Message 'Downloading the agent')

    # Downloaded into memory and checked there: the bytes that are verified are the ones written.
    try {
        $client = Get-HttpClient
        $base = "$($ServerUrl.TrimEnd('/'))/agent/app.ps1"
        $bytes = $client.GetByteArrayAsync($base).GetAwaiter().GetResult()
        $signature = $client.GetStringAsync("$base.sig").GetAwaiter().GetResult().Trim()
        if (-not (Test-ServerSignature -Context 'MDM1-AGENT' -Message (Get-Sha256Hex -Bytes $bytes) -Signature $signature)) {
            throw 'the downloaded agent is not signed with the pinned server key'
        }

        $text = [System.Text.Encoding]::UTF8.GetString($bytes)
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseInput($text, [ref]$null, [ref]$errors) | Out-Null
        if ($errors -or $text -notmatch '(?m)^function Start-Agent') {
            throw 'the downloaded agent is not valid'
        }
        # Windows PowerShell reads a script without a byte order mark in the ANSI code page, not as
        # UTF-8: checked the way it will run (a character outside ASCII broke the whole file once).
        if ($PSVersionTable.PSEdition -ne 'Core') {
            $ansiErrors = $null
            [System.Management.Automation.Language.Parser]::ParseInput([System.Text.Encoding]::Default.GetString($bytes), [ref]$null, [ref]$ansiErrors) | Out-Null
            if ($ansiErrors) {
                throw "the downloaded agent does not parse in Windows PowerShell ($(@($ansiErrors)[0].Message))"
            }
        }

        # Only move forward, an older agent is never installed this way.
        $version = if ($text -match "(?m)^\`$AgentVersion = '([^']+)'") { $Matches[1] } else { $null }
        if (-not $version -or [version]$version -le [version]$AgentVersion) {
            throw "the server offers version '$version', not newer than $AgentVersion"
        }

        # Kept for the rollback (see Restart-AgentTask), confirmed by the new agent once it reached the server.
        Copy-Item -Path (Join-Path $AgentDir 'app.ps1') -Destination (Join-Path $AgentDir 'app.ps1.previous') -Force
        @{ from = $AgentVersion; to = $version; at = (Get-Date).ToString('o') } | ConvertTo-Json -Compress | Set-Content -Path "$AgentDir/agent-update.json" -Encoding UTF8
        [System.IO.File]::WriteAllBytes((Join-Path $AgentDir 'app.ps1'), $bytes)
    }
    catch {
        Write-AgentLog "Agent update failed: $($_.Exception.Message)" -ErrorRecord $_
        [void](Send-CommandStatus -Id $CommandId -Status failed -Message $_.Exception.Message)
        return
    }

    Write-AgentLog 'Agent updated, restarting'
    # The new agent reports the result when it runs (see Complete-PendingCommand).
    Save-PendingCommand -Id $CommandId -Command 'updateAgent'
    [void](Send-CommandStatus -Id $CommandId -Status running -Progress 80 -Message 'Restarting the agent')
    # systemd (Restart=always) starts the new version on Linux, the task is started again on Windows.
    Restart-AgentTask -Watch
    exit 0
}

function Send-CommandStatus {
    # Reports what a command does (agents 1.8.0+): running with an optional progress, then
    # succeeded / failed. Problems are only logged, the command itself goes on. Returns $false
    # when the server could not be reached (a finished or unknown command counts as reported).
    param (
        [Parameter(Mandatory = $true)]
        $Id,
        [Parameter(Mandatory = $true)]
        [ValidateSet('running', 'succeeded', 'failed')]
        [string]
        $Status,
        $Progress,
        [string]
        $Message,
        # An update of many packages (agents 1.22.0+): the one being installed and the finished ones.
        [string]
        $Current,
        [string[]]
        $Done
    )

    if (-not $Id -or -not $script:AgentToken) {
        return $true
    }
    $body = @{ status = $Status }
    if ($null -ne $Progress) { $body['progress'] = [int]$Progress }
    if ($Current) { $body['current'] = $Current.Substring(0, [Math]::Min(300, $Current.Length)) }
    if ($PSBoundParameters.ContainsKey('Done')) { $body['done'] = @($Done | Select-Object -Last 300) }
    if ($Message) { $body['message'] = $Message.Substring(0, [Math]::Min(1000, $Message.Length)) }
    try {
        Invoke-MdmApi -Method Post -Path "device/commands/$Id" -Token $script:AgentToken -Body $body | Out-Null
        return $true
    }
    catch {
        # The server gave the command up meanwhile (not_running) or does not know it: expected
        # (cancelled, given up, a wake finished by the woken device), not an error of the agent.
        $gone = $_.Exception.Data['MdmError'] -in 'not_running', 'Not Found'
        if ($gone) {
            Write-AgentLog "Command ${Id}: status '$Status' not reported: $($_.Exception.Message)"
        } else {
            Write-AgentLog "Command ${Id}: status '$Status' not reported: $($_.Exception.Message)" -ErrorRecord $_
        }
        return $gone
    }
}

function Save-PendingCommand {
    # A command that finishes after the agent restarts (restart, agent update): reported by the
    # agent that starts next (see Complete-PendingCommand).
    param ($Id, [string]$Command)

    @{ id = $Id; command = $Command; agent_version = $AgentVersion; at = (Get-Date).ToString('o') } | ConvertTo-Json -Compress | Set-Content -Path "$AgentDir/pending-command.json" -Encoding UTF8
}

function Complete-PendingCommand {
    # Returns $true when there is nothing (left) to report.
    $path = "$AgentDir/pending-command.json"
    if (-not (Test-Path -Path $path)) {
        return $true
    }
    try {
        $pending = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
        $reported = switch ($pending.command) {
            'restart' { Send-CommandStatus -Id $pending.id -Status succeeded -Message 'Restarted' }
            'updateAgent' {
                if ([version]$AgentVersion -gt [version]$pending.agent_version) {
                    $sent = Send-CommandStatus -Id $pending.id -Status succeeded -Message "Updated to $AgentVersion"
                    # The server is reached: no rollback (see Restart-AgentTask).
                    if ($sent) { Remove-Item -Path "$AgentDir/agent-update.json" -Force -ErrorAction SilentlyContinue }
                    $sent
                } elseif ($pending.rolled_back) {
                    Send-CommandStatus -Id $pending.id -Status failed -Message "Rolled back to ${AgentVersion}: version $($pending.rolled_back) did not report to the server within 5 minutes"
                } else {
                    Send-CommandStatus -Id $pending.id -Status failed -Message "Still version $AgentVersion after the update"
                }
            }
            default { $true }
        }
        # Right after a restart the network may not be up yet: tried again later.
        if ($reported -ne $false) {
            Remove-Item -Path $path -Force
            return $true
        }
    }
    catch {
        Write-AgentLog "Pending command: $($_.Exception.Message)" -ErrorRecord $_
        Remove-Item -Path $path -Force -ErrorAction SilentlyContinue
        return $true
    }

    return $false
}

function Test-UpdateParams {
    # installUpdate parameters, checked again on the device: only known kinds, ids of the
    # expected form (they are passed as arguments, never as code).
    param ($Params)

    $kind = "$($Params.kind)"
    if (-not $UpdateKinds.ContainsKey($kind) -or "$($Params.id)" -notmatch $UpdateKinds[$kind] -or "$($Params.id)".Length -gt 200) {
        throw "invalid update '$kind' '$($Params.id)'"
    }
    if ($Params.user -and ($kind -notin 'flatpak', 'module', 'pip', 'pipx' -or "$($Params.user)" -notmatch '^[a-z_][a-z0-9_.\-]{0,31}$')) {
        throw "invalid user '$($Params.user)'"
    }
    if ($kind -eq 'module' -and ("$($Params.edition)" -notin 'Windows PowerShell', 'PowerShell 7' -or "$($Params.version)" -notmatch '^[0-9][0-9A-Za-z.\-]{0,49}$')) {
        throw "invalid module edition or version"
    }
    if ((($kind -in 'windows', 'winget', 'chrome') -and $OnLinux) -or (($kind -in 'apt', 'flatpak', 'snap') -and -not $OnLinux)) {
        throw "'$kind' updates are not available on this platform"
    }

    # winget: the source of the package in the inventory (servers before 1.12.3 do not send it), and
    # whether it is installed only for the logged-on user (SYSTEM does not see it).
    $source = if ($kind -eq 'winget' -and "$($Params.source)" -in 'winget', 'msstore') { "$($Params.source)" } else { '' }
    $scope = if ($kind -eq 'winget' -and "$($Params.scope)" -eq 'user') { 'user' } else { '' }

    return @{ kind = $kind; id = "$($Params.id)"; user = "$($Params.user)"; edition = "$($Params.edition)"; version = "$($Params.version)"; source = $source; scope = $scope }
}

function Test-WakeParams {
    # wake parameters, checked again on the device: MAC addresses and IPv4 broadcast addresses only.
    param ($Params)

    $macs = @($Params.macs | ForEach-Object { "$_" } | Where-Object { $_ -match '^[0-9A-Fa-f]{2}([:-][0-9A-Fa-f]{2}){5}$' })
    $broadcasts = @($Params.broadcasts | ForEach-Object {
            $address = $null
            if ([System.Net.IPAddress]::TryParse("$_", [ref]$address) -and $address.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork) { $address }
        })
    if ($macs.Count -eq 0 -or $macs.Count -gt 8 -or $broadcasts.Count -eq 0 -or $broadcasts.Count -gt 4) {
        throw 'invalid MAC or broadcast addresses'
    }

    return @{ Macs = $macs; Broadcasts = $broadcasts }
}

function Get-LocalNetworks {
    # The IPv4 addresses of the interfaces that are up, with their network: name, address, mask and
    # broadcast (bytes), so a packet can leave on the interface of its network.
    $networks = @()
    try {
        foreach ($interface in [System.Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces()) {
            if ($interface.OperationalStatus -ne 'Up') { continue }
            foreach ($unicast in $interface.GetIPProperties().UnicastAddresses) {
                $address = $unicast.Address
                # A mask of 0.0.0.0 would be a network of every address.
                if ($address.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork -or -not $unicast.IPv4Mask -or "$($unicast.IPv4Mask)" -eq '0.0.0.0' -or [System.Net.IPAddress]::IsLoopback($address)) { continue }
                $ip = $address.GetAddressBytes()
                $mask = $unicast.IPv4Mask.GetAddressBytes()
                $networks += @{
                    Name      = $interface.Name
                    Address   = $address
                    Mask      = $mask
                    Network   = [byte[]](0..3 | ForEach-Object { $ip[$_] -band $mask[$_] })
                    Broadcast = [System.Net.IPAddress]::new([byte[]](0..3 | ForEach-Object { ($ip[$_] -band $mask[$_]) -bor (255 -bxor $mask[$_]) }))
                }
            }
        }
    }
    catch {
        Write-AgentLog "Network interfaces not read ($($_.Exception.Message)), using the default route"
    }
    return $networks
}

function Find-LocalNetwork {
    # The local interface whose network contains the address (the longest prefix), or $null.
    param ($Networks, [System.Net.IPAddress]$Address)

    $bytes = $Address.GetAddressBytes()
    $best = $null
    $bestBits = -1
    foreach ($network in $Networks) {
        $inside = $true
        foreach ($i in 0..3) {
            if (($bytes[$i] -band $network.Mask[$i]) -ne $network.Network[$i]) { $inside = $false; break }
        }
        if (-not $inside) { continue }
        $bits = 0
        foreach ($b in $network.Mask) { $bits += [Convert]::ToString($b, 2).Replace('0', '').Length }
        if ($bits -gt $bestBits) { $best = $network; $bestBits = $bits }
    }
    return $best
}

function Get-NetworkDiscovery {
    # network_discovery in config.json: off, neighbours (the default: the ARP table goes with the
    # report) or scan (also scans on request). "none" and false mean off, as does anything else.
    param ($Config = (Get-AgentConfig))

    $level = $Config['network_discovery']
    if ($null -eq $level) { return 'neighbours' }
    if (@('off', 'none', 'false') -contains "$level") { return 'off' }
    if (@('neighbours', 'scan') -contains "$level") { return "$level".ToLowerInvariant() }
    return 'off'
}

function Get-SecurityLogsLevel {
    # security_logs in config.json: on sends the records of the security logs, anything else is off.
    # Only changed on the device (-SecurityLogs with -Install, or editing config.json).
    param ($Config = (Get-AgentConfig))

    return $(if ("$($Config['security_logs'])" -eq 'on') { 'on' } else { 'off' })
}

function Get-PortScan {
    # port_scan in config.json: on scans the ports of an address of the device's networks on request,
    # anything else (the default) is off. Only changed on the device (-PortScan, or editing config.json).
    param ($Config = (Get-AgentConfig))

    return $(if ("$($Config['port_scan'])" -eq 'on') { 'on' } else { 'off' })
}

function Test-UnicastMac {
    param ([string]$Mac)

    if ($Mac -notmatch '^[0-9A-Fa-f]{2}([:-][0-9A-Fa-f]{2}){5}$' -or $Mac -match '^(00[:-]){5}00$') { return $false }
    # The lowest bit of the first byte marks broadcast and multicast addresses.
    return ([Convert]::ToInt32($Mac.Substring(0, 2), 16) -band 1) -eq 0
}

function Get-Neighbours {
    # The IPv4 neighbours of the interfaces (the ARP table): @{ Ip; Mac; State; Hostname }, the names
    # from the last scan (reverse DNS). Entries that did not answer (failed, incomplete) are left
    # out; State is fresh (confirmed lately) or stale (kept from before: after a change of address
    # the old one can stay for hours, the server does not move a device back to it).
    $entries = @()
    if ($OnLinux) {
        $raw = try { (& ip -j -4 neigh show 2>$null) -join "`n" } catch { '' }
        if ($raw.Trim()) {
            foreach ($entry in @($raw | ConvertFrom-Json | ForEach-Object { $_ })) {
                $state = @($entry.state)
                if (-not $entry.lladdr -or $state -contains 'FAILED' -or $state -contains 'INCOMPLETE') { continue }
                $entries += @{ Ip = "$($entry.dst)"; Mac = "$($entry.lladdr)"; State = if ($state -contains 'STALE' -or $state -contains 'NOARP') { 'stale' } else { 'fresh' } }
            }
        } elseif (Test-Path -Path /proc/net/arp) {
            # Without iproute2's JSON (BusyBox): IP, type, flags (0x2 complete), MAC, mask, device.
            foreach ($line in @(Get-Content -Path /proc/net/arp | Select-Object -Skip 1)) {
                $fields = @($line -split '\s+')
                if ($fields.Count -ge 4 -and $fields[2] -eq '0x2') { $entries += @{ Ip = $fields[0]; Mac = $fields[3] } }
            }
        }
    } else {
        foreach ($entry in @(Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue)) {
            if ($entry.LinkLayerAddress -and @('Reachable', 'Stale', 'Delay', 'Probe') -contains "$($entry.State)") {
                $entries += @{ Ip = "$($entry.IPAddress)"; Mac = "$($entry.LinkLayerAddress)"; State = if ("$($entry.State)" -eq 'Stale') { 'stale' } else { 'fresh' } }
            }
        }
    }
    $names = Get-ScanNames
    $seen = @{}
    $neighbours = @()
    foreach ($entry in $entries) {
        if ($seen.ContainsKey($entry.Ip) -or -not (Test-UnicastMac -Mac $entry.Mac)) { continue }
        $seen[$entry.Ip] = $true
        $entry['Hostname'] = $names[$entry.Ip]
        $neighbours += $entry
        if ($neighbours.Count -ge 512) { break }
    }
    return $neighbours
}

function Get-ScanNames {
    # ip => name of the devices the last scans found (reverse DNS), kept for a week.
    $names = @{}
    $path = Join-Path $AgentDir 'scan-names.json'
    if (-not (Test-Path -Path $path)) { return $names }
    try {
        $saved = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
        foreach ($property in $saved.PSObject.Properties) {
            if ([DateTime]::Parse($property.Value.at) -gt (Get-Date).AddDays(-7)) { $names[$property.Name] = "$($property.Value.name)" }
        }
    }
    catch {
        Write-AgentLog "Scan names not read: $($_.Exception.Message)"
    }
    return $names
}

function Save-ScanNames {
    param ([hashtable]$Names)

    $path = Join-Path $AgentDir 'scan-names.json'
    $all = @{}
    foreach ($entry in (Get-ScanNames).GetEnumerator()) { $all[$entry.Key] = @{ name = $entry.Value; at = (Get-Date).ToString('o') } }
    foreach ($entry in $Names.GetEnumerator()) { $all[$entry.Key] = @{ name = "$($entry.Value)"; at = (Get-Date).ToString('o') } }
    $all | ConvertTo-Json -Depth 3 -Compress | Set-Content -Path $path -Encoding UTF8
}

function Test-ScanParams {
    # scanNetwork: the network (a.b.c.d/prefix, /22 to /30) must be the network of one of the
    # interfaces that are up. Returns @{ Cidr; Interface; Hosts }.
    param ($Params)

    $cidr = "$($Params.cidr)"
    if ($cidr -notmatch '^(\d{1,3}(\.\d{1,3}){3})/(\d{1,2})$') { throw "not a network: '$cidr'" }
    $prefix = [int]$Matches[3]
    if ($prefix -lt 22 -or $prefix -gt 30) { throw "only networks from /22 to /30 are scanned, not /$prefix" }
    foreach ($network in Get-LocalNetworks) {
        $bits = 0
        foreach ($b in $network.Mask) { $bits += [Convert]::ToString($b, 2).Replace('0', '').Length }
        if ("$([System.Net.IPAddress]::new([byte[]]$network.Network))/$bits" -eq $cidr) {
            return @{ Cidr = $cidr; Interface = $network.Name; Hosts = [Math]::Pow(2, 32 - $prefix) - 2 }
        }
    }
    throw "$cidr is not a network of this device's interfaces"
}

function Test-InNetwork {
    param ([string]$Cidr, [string]$Address)

    $parts = $Cidr -split '/'
    $ip = [System.Net.IPAddress]::None
    if (-not [System.Net.IPAddress]::TryParse($Address, [ref]$ip) -or $ip.AddressFamily -ne 'InterNetwork') { return $false }
    $bytes = $ip.GetAddressBytes()
    $network = [System.Net.IPAddress]::Parse($parts[0]).GetAddressBytes()
    $bits = [int]$parts[1]
    foreach ($i in 0..3) {
        $take = [Math]::Max(0, [Math]::Min(8, $bits - 8 * $i))
        $mask = (0xFF -shl (8 - $take)) -band 0xFF
        if (($bytes[$i] -band $mask) -ne ($network[$i] -band $mask)) { return $false }
    }
    return $true
}

function Invoke-NetworkSweep {
    # In the scan job: pings every address of the network (128 at once, 700 ms each), which also
    # fills the ARP table with every device that answers ARP (firewalls drop the ping, not ARP).
    # Writes @{ Progress; Found } after each batch, then @{ Done; Up; Names } (reverse DNS, 3 s).
    param ([string]$Cidr, [int]$TimeoutMs = 700, [int]$Batch = 128)

    $parts = $Cidr -split '/'
    $base = [System.Net.IPAddress]::Parse($parts[0]).GetAddressBytes()
    [Array]::Reverse($base)
    $start = [BitConverter]::ToUInt32($base, 0)
    $count = [int][Math]::Pow(2, 32 - [int]$parts[1])
    $hosts = @(for ($i = 1; $i -lt $count - 1; $i++) {
            $bytes = [BitConverter]::GetBytes([uint32]($start + $i))
            [Array]::Reverse($bytes)
            [System.Net.IPAddress]::new($bytes)
        })
    $up = New-Object System.Collections.ArrayList
    for ($offset = 0; $offset -lt $hosts.Count; $offset += $Batch) {
        $runs = @($hosts[$offset..([Math]::Min($hosts.Count, $offset + $Batch) - 1)] | ForEach-Object {
                $ping = New-Object System.Net.NetworkInformation.Ping
                @{ Ip = "$_"; Ping = $ping; Task = $ping.SendPingAsync($_, $TimeoutMs) }
            })
        foreach ($run in $runs) {
            try { [void]$run.Task.Wait($TimeoutMs + 1000) } catch { }
            if ($run.Task.Status -eq 'RanToCompletion' -and "$($run.Task.Result.Status)" -eq 'Success') { [void]$up.Add($run.Ip) }
            $run.Ping.Dispose()
        }
        [PSCustomObject]@{ Progress = [int](($offset + $runs.Count) * 90 / $hosts.Count); Found = $up.Count }
    }
    $lookups = @($up | ForEach-Object { @{ Ip = $_; Task = [System.Net.Dns]::GetHostEntryAsync($_) } })
    $deadline = (Get-Date).AddSeconds(3)
    $names = @{}
    foreach ($lookup in $lookups) {
        $left = [Math]::Max(0, [int]($deadline - (Get-Date)).TotalMilliseconds)
        try {
            if ($lookup.Task.Wait($left) -and $lookup.Task.Result.HostName -and $lookup.Task.Result.HostName -ne $lookup.Ip) { $names[$lookup.Ip] = $lookup.Task.Result.HostName }
        }
        catch { }
    }
    [PSCustomObject]@{ Done = $true; Up = @($up); Names = $names }
}

function Start-NetworkScan {
    param ($CommandId, $Scan)

    $script:ScanJob = Start-AgentJob -Name 'scan' -Functions 'Invoke-NetworkSweep' -ArgumentList $Scan.Cidr -ScriptBlock {
        param ($Cidr)
        Invoke-NetworkSweep -Cidr $Cidr
    }
    $script:ScanCommand = @{ Id = $CommandId; Cidr = $Scan.Cidr; Interface = $Scan.Interface; Hosts = $Scan.Hosts; Result = $null; ProgressSent = Get-Date }
}

function Sync-NetworkScan {
    # The scan job's progress goes to the server; when it is done the names are kept and the
    # result waits for the next report (sent right away), which carries the ARP table. Returns
    # $true when the report should go now.
    if (-not $script:ScanJob) { return $false }
    $scan = $script:ScanCommand
    foreach ($item in @(Receive-Job -Job $script:ScanJob -ErrorAction SilentlyContinue)) {
        if ($item.Done) { $scan.Result = $item } elseif ($null -ne $item.Progress) { $scan.Last = $item }
    }
    if ($scan.Last -and ((Get-Date) - $scan.ProgressSent).TotalSeconds -ge 3) {
        $scan.ProgressSent = Get-Date
        [void](Send-CommandStatus -Id $scan.Id -Status running -Progress $scan.Last.Progress -Message "Pinging the $($scan.Hosts) addresses of $($scan.Cidr) from $($scan.Interface): $($scan.Last.Found) answered")
    }
    if ($script:ScanJob.State -eq 'Running') { return $false }

    $reason = $script:ScanJob.ChildJobs[0].JobStateInfo.Reason
    Remove-Job -Job $script:ScanJob -Force
    $script:ScanJob = $null
    if (-not $scan.Result) {
        Write-AgentLog "Network scan of $($scan.Cidr) failed: $reason" -IsError
        [void](Send-CommandStatus -Id $scan.Id -Status failed -Message "Scan failed: $reason")
        $script:ScanCommand = $null
        return $false
    }
    $names = @{}
    if ($scan.Result.Names) { foreach ($entry in $scan.Result.Names.GetEnumerator()) { $names["$($entry.Key)"] = "$($entry.Value)" } }
    Save-ScanNames -Names $names
    $inTable = @(Get-Neighbours | Where-Object { Test-InNetwork -Cidr $scan.Cidr -Address $_.Ip }).Count
    $answered = @($scan.Result.Up).Count
    Write-AgentLog "Network scan of $($scan.Cidr): $answered answered the ping, $inTable in the ARP table"
    $scan.Message = "$answered answered the ping, $inTable in the ARP table ($($scan.Hosts) addresses from $($scan.Interface))"
    [void](Send-CommandStatus -Id $scan.Id -Status running -Progress 95 -Message 'Sending what was found')
    return $true
}

function Complete-NetworkScan {
    # After the report with the ARP table: the scan is done (or failed with the report).
    param ([string]$Failure)

    $scan = $script:ScanCommand
    if (-not $scan -or -not $scan.Message) { return }
    if ($Failure) {
        [void](Send-CommandStatus -Id $scan.Id -Status failed -Message "Scanned, but the report failed: $Failure")
    } else {
        [void](Send-CommandStatus -Id $scan.Id -Status succeeded -Message $scan.Message)
    }
    $script:ScanCommand = $null
}

# The common TCP ports scanned when a scanPorts command carries no port list.
$script:DefaultScanPorts = @(
    21, 22, 23, 25, 53, 80, 110, 111, 135, 139, 143, 161, 389, 443, 445, 465, 587, 631, 636,
    993, 995, 1433, 1521, 1723, 1883, 2049, 2375, 2376, 3000, 3306, 3389, 5000, 5060, 5432,
    5601, 5900, 5985, 5986, 6379, 7070, 8000, 8008, 8080, 8086, 8096, 8123, 8443, 8883, 9000,
    9090, 9100, 9200, 11211, 27017
)

function Test-PortScanParams {
    # scanPorts: the address must be a host of a network of one of the interfaces that are up (not
    # the device's own address, nor the network or broadcast address). "all" scans every port
    # (1-65535), a list scans those ports, otherwise the default common ports. Returns
    # @{ Ip; Ports; Interface; Full }.
    param ($Params)

    $ip = "$($Params.ip)"
    $parsed = [System.Net.IPAddress]::None
    if (-not [System.Net.IPAddress]::TryParse($ip, [ref]$parsed) -or $parsed.AddressFamily -ne 'InterNetwork') { throw "not an IPv4 address: '$ip'" }
    $networks = Get-LocalNetworks
    $network = Find-LocalNetwork -Networks $networks -Address $parsed
    if (-not $network) { throw "$ip is not in a network of this device's interfaces" }
    if ($parsed.Equals($network.Address)) { throw "$ip is this device's own address" }
    $networkAddress = [System.Net.IPAddress]::new([byte[]]$network.Network)
    if ($parsed.Equals($networkAddress) -or $parsed.Equals($network.Broadcast)) { throw "$ip is the network or broadcast address" }
    $full = "$($Params.all)" -in 'true', '1', 'all'
    $ports = if ($full) {
        1..65535
    } elseif ($Params.ports) {
        @($Params.ports | ForEach-Object { try { [int]$_ } catch { $null } } | Where-Object { $_ -ge 1 -and $_ -le 65535 } | Select-Object -Unique)
    } else {
        $script:DefaultScanPorts
    }
    if ($ports.Count -eq 0) { throw 'no ports to scan' }
    if ($ports.Count -gt 65535) { throw "too many ports ($($ports.Count)), at most 65535" }
    return @{ Ip = $ip; Ports = @($ports); Interface = $network.Name; Full = $full }
}

function Read-StreamText {
    # Reads up to $Max bytes the service offers, within a short deadline, as printable text (control
    # characters dropped). Used only to look at what a port returns; nothing is interpreted or run.
    param ($Stream, [int]$Max = 4096, [int]$TimeoutMs = 1000)

    try { $Stream.ReadTimeout = $TimeoutMs } catch { }
    $buffer = New-Object byte[] 4096
    $out = New-Object System.IO.MemoryStream
    $deadline = (Get-Date).AddMilliseconds($TimeoutMs * 2 + 500)
    try {
        while ($out.Length -lt $Max -and (Get-Date) -lt $deadline) {
            $read = $Stream.Read($buffer, 0, [Math]::Min($buffer.Length, $Max - $out.Length))
            if ($read -le 0) { break }
            $out.Write($buffer, 0, $read)
        }
    }
    catch { }
    if ($out.Length -eq 0) { return $null }
    $text = [System.Text.Encoding]::ASCII.GetString($out.ToArray())
    return ($text -replace '[^\x09\x0A\x0D\x20-\x7E]', '').Trim()
}

function Get-HttpSummary {
    # The Server header, the page <title> (or the status) and passive observations from an HTTP
    # response. Only reads the response; it never reflects anything back into a request.
    param ([string]$Response, [switch]$Tls)

    $headerBlock = ($Response -split "`r?`n`r?`n", 2)[0]
    $lines = @($headerBlock -split "`r?`n")
    $server = $null
    foreach ($line in $lines) {
        if ($line -match '^(?i)server:\s*(.+)$') { $server = $Matches[1].Trim(); break }
    }
    $status = ($lines[0] -replace '^HTTP/\d(\.\d)?\s*', '').Trim()
    $title = if ($Response -match '(?is)<title[^>]*>(.*?)</title>') { ($Matches[1] -replace '\s+', ' ').Trim() } else { $null }
    $parts = @()
    if ($server) { $parts += "Server: $server" }
    if ($title) { $parts += "title: $title" } elseif ($status) { $parts += "HTTP $status" }
    $findings = @()
    $lower = $headerBlock.ToLowerInvariant()
    # Structured flags for the server-side rules: whether each security header is present. HSTS is
    # only meaningful over TLS, so on plain HTTP it is reported present (not missing).
    $hsts = (-not $Tls) -or ($lower -match '(?m)^strict-transport-security:')
    $csp = [bool]($lower -match '(?m)^content-security-policy:')
    $xframe = [bool]($lower -match '(?m)^x-frame-options:')
    if ($Tls -and $lower -notmatch '(?m)^strict-transport-security:') { $findings += 'no HSTS header' }
    if (-not $csp) { $findings += 'no Content-Security-Policy header' }
    if (-not $xframe) { $findings += 'no X-Frame-Options header' }
    if (-not $Tls) { $findings += 'serves HTTP without TLS' }
    return @{ Banner = ($parts -join '; '); Findings = $findings; Server = $server; Title = $title; Hsts = [bool]$hsts; Csp = $csp; XFrameOptions = $xframe }
}

function Invoke-PortSweep {
    # In the scan job: which of the ports accept a TCP connection, and for each open one what the
    # service returns on its own (a banner, the HTTP Server header and title, the TLS certificate,
    # or the common service name of the port). Sends only a single standard HTTP GET where it helps
    # identify a web server; no payloads go to the services. A large sweep (a full 1-65535 scan)
    # connects in bigger batches with a shorter timeout. Writes @{ Progress; Found } per batch, then
    # @{ Done; Open; Findings }.
    param ([string]$Ip, [int[]]$Ports, [int]$ConnectTimeoutMs, [int]$ReadTimeoutMs = 1500, [int]$Batch)

    $httpPorts = @(80, 81, 591, 3000, 5000, 5601, 7070, 8000, 8008, 8080, 8086, 8096, 8123, 9090)
    $tlsPorts = @(443, 465, 636, 989, 990, 993, 995, 5986, 8443, 9443)
    # Common TCP services, to name a port that answers but offers no banner.
    $wellKnown = @{
        21 = 'ftp'; 22 = 'ssh'; 23 = 'telnet'; 25 = 'smtp'; 53 = 'dns'; 110 = 'pop3'; 111 = 'rpcbind';
        135 = 'msrpc'; 139 = 'netbios'; 143 = 'imap'; 161 = 'snmp'; 389 = 'ldap'; 445 = 'smb';
        465 = 'smtps'; 514 = 'syslog'; 515 = 'printer'; 587 = 'submission'; 631 = 'ipp'; 636 = 'ldaps';
        993 = 'imaps'; 995 = 'pop3s'; 1433 = 'mssql'; 1521 = 'oracle'; 1723 = 'pptp'; 1883 = 'mqtt';
        2049 = 'nfs'; 2375 = 'docker'; 2376 = 'docker-tls'; 3306 = 'mysql'; 3389 = 'rdp'; 5060 = 'sip';
        5432 = 'postgresql'; 5900 = 'vnc'; 5985 = 'winrm'; 5986 = 'winrm-tls'; 6379 = 'redis';
        8883 = 'mqtt-tls'; 9100 = 'jetdirect'; 9200 = 'elasticsearch'; 11211 = 'memcached'; 27017 = 'mongodb'
    }
    # A full scan (many ports) connects wider and faster; a short list stays gentle.
    if (-not $Batch) { $Batch = if ($Ports.Count -gt 1024) { 256 } else { 64 } }
    if (-not $ConnectTimeoutMs) { $ConnectTimeoutMs = if ($Ports.Count -gt 1024) { 500 } else { 1000 } }

    $open = New-Object System.Collections.ArrayList
    for ($offset = 0; $offset -lt $Ports.Count; $offset += $Batch) {
        $slice = @($Ports[$offset..([Math]::Min($Ports.Count, $offset + $Batch) - 1)])
        $runs = @($slice | ForEach-Object {
                $client = New-Object System.Net.Sockets.TcpClient
                @{ Port = $_; Client = $client; Task = $client.ConnectAsync($Ip, $_) }
            })
        # Wait for the whole batch at once (bounded by the timeout), not each port in turn: a
        # filtered host drops SYNs, so a per-port wait would take timeout x batch size.
        try { [void][System.Threading.Tasks.Task]::WaitAll(@($runs.Task), $ConnectTimeoutMs) } catch { }
        foreach ($run in $runs) {
            if ($run.Task.Status -eq 'RanToCompletion' -and $run.Client.Connected) { [void]$open.Add($run.Port) }
            try { $run.Client.Close() } catch { }
        }
        [PSCustomObject]@{ Progress = [int](($offset + $slice.Count) * 80 / $Ports.Count); Found = $open.Count }
    }

    $results = @()
    $findings = New-Object System.Collections.ArrayList
    foreach ($port in @($open | Sort-Object)) {
        $service = $null
        $banner = $null
        $isTls = $tlsPorts -contains $port
        # Structured per-port facts for the server-side detection rules (booleans default to the
        # "nothing wrong" value, so a port with no web/TLS layer does not look like a finding).
        $tls = $false; $http = $false; $tlsFailed = $false; $certSelfSigned = $false; $certExpired = $false
        $server = $null; $title = $null; $hsts = $true; $csp = $true; $xframe = $true
        $client = New-Object System.Net.Sockets.TcpClient
        try {
            $connect = $client.ConnectAsync($Ip, $port)
            if (-not $connect.Wait($ConnectTimeoutMs) -or -not $client.Connected) { throw 'not reachable the second time' }
            $client.ReceiveTimeout = $ReadTimeoutMs
            $stream = $client.GetStream()
            if ($isTls) {
                try {
                    $ssl = New-Object System.Net.Security.SslStream($stream, $false, ([System.Net.Security.RemoteCertificateValidationCallback] { param($sndr, $crt, $chn, $err) $true }))
                    $ssl.AuthenticateAsClient($Ip)
                    $stream = $ssl
                    $service = 'https'
                    $tls = $true
                    if ($ssl.RemoteCertificate) {
                        $cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($ssl.RemoteCertificate)
                        $subject = $cert.GetNameInfo([System.Security.Cryptography.X509Certificates.X509NameType]::SimpleName, $false)
                        if ($subject) { $banner = "TLS: $subject" }
                        if ($cert.NotAfter -lt (Get-Date)) { $certExpired = $true; [void]$findings.Add("Port ${port}: TLS certificate expired $($cert.NotAfter.ToString('yyyy-MM-dd'))") }
                        if ($cert.Subject -eq $cert.Issuer) { $certSelfSigned = $true; [void]$findings.Add("Port ${port}: self-signed TLS certificate") }
                    }
                }
                catch {
                    $service = 'tls?'
                    $tlsFailed = $true
                    [void]$findings.Add("Port ${port}: TLS handshake failed")
                }
            }
            # Services that greet on connect (SSH, FTP, SMTP, IMAP, POP3): read their banner (its
            # first line). A server that speaks HTTP unasked on an off-list port is summarized too.
            if (-not $isTls -and $httpPorts -notcontains $port) {
                $peek = Read-StreamText -Stream $stream -Max 2048 -TimeoutMs 700
                if ($peek -match '^HTTP/') {
                    $service = 'http'
                    $http = $true
                    $httpSum = Get-HttpSummary -Response $peek
                    $banner = $httpSum.Banner
                    $server = $httpSum.Server; $title = $httpSum.Title; $hsts = $httpSum.Hsts; $csp = $httpSum.Csp; $xframe = $httpSum.XFrameOptions
                    foreach ($f in $httpSum.Findings) { [void]$findings.Add("Port ${port}: $f") }
                }
                elseif ($peek) {
                    $banner = @($peek -split "`r?`n" | Where-Object { $_.Trim() })[0]
                    $service = switch -Regex ($peek) {
                        '^SSH-' { 'ssh'; break }
                        '(?i)^220.*ftp' { 'ftp'; break }
                        '(?i)^220.*smtp|^220 ' { 'smtp'; break }
                        '(?i)^\*\s*OK.*imap' { 'imap'; break }
                        '^\+OK' { 'pop3'; break }
                        default { $null }
                    }
                }
            }
            # No banner yet and a web port: a single standard GET, then read the headers and title.
            if ((-not $banner -or $isTls) -and (($httpPorts -contains $port) -or $isTls)) {
                try {
                    $request = "GET / HTTP/1.0`r`nHost: $Ip`r`nUser-Agent: Laravel-MDM-Agent`r`nAccept: */*`r`nConnection: close`r`n`r`n"
                    $bytes = [System.Text.Encoding]::ASCII.GetBytes($request)
                    $stream.Write($bytes, 0, $bytes.Length)
                    $stream.Flush()
                    $response = Read-StreamText -Stream $stream -Max 8192 -TimeoutMs $ReadTimeoutMs
                    if ($response -and $response -match '^HTTP/') {
                        if (-not $service) { $service = if ($isTls) { 'https' } else { 'http' } }
                        $http = $true
                        $httpSum = Get-HttpSummary -Response $response -Tls:$isTls
                        if ($httpSum.Banner) { $banner = if ($banner) { "$banner; $($httpSum.Banner)" } else { $httpSum.Banner } }
                        $server = $httpSum.Server; $title = $httpSum.Title; $hsts = $httpSum.Hsts; $csp = $httpSum.Csp; $xframe = $httpSum.XFrameOptions
                        foreach ($f in $httpSum.Findings) { [void]$findings.Add("Port ${port}: $f") }
                    }
                }
                catch { }
            }
            # No banner and not a web/TLS port: name it by its well-known port, else just "open".
            if (-not $service) { $service = if ($wellKnown.ContainsKey([int]$port)) { $wellKnown[[int]$port] } else { 'open' } }
            if ($port -eq 23) { [void]$findings.Add("Port ${port}: Telnet is unencrypted") }
            if ($port -eq 21) { [void]$findings.Add("Port ${port}: FTP is unencrypted") }
        }
        catch { }
        finally { try { $client.Close() } catch { } }
        $results += @{
            port = $port; service = $service; banner = $banner
            tls = $tls; http = $http; server = $server; title = $title
            hsts = $hsts; csp = $csp; xframe = $xframe
            certSelfSigned = $certSelfSigned; certExpired = $certExpired; tlsFailed = $tlsFailed
        }
    }
    [PSCustomObject]@{ Done = $true; Open = @($results); Findings = @($findings) }
}

function Start-PortScan {
    param ($CommandId, $Scan)

    $script:PortScanJob = Start-AgentJob -Name 'portscan' -Functions 'Invoke-PortSweep', 'Read-StreamText', 'Get-HttpSummary' -ArgumentList $Scan.Ip, $Scan.Ports -ScriptBlock {
        param ($Ip, $Ports)
        Invoke-PortSweep -Ip $Ip -Ports $Ports
    }
    $script:PortScanCommand = @{ Id = $CommandId; Ip = $Scan.Ip; Interface = $Scan.Interface; Count = @($Scan.Ports).Count; ProgressSent = Get-Date }
}

function Sync-PortScan {
    # The port scan job's progress goes to the server; when it is done the open ports go to the
    # dedicated endpoint and the command is finished. Nothing in the regular report depends on it.
    if (-not $script:PortScanJob) { return }
    $scan = $script:PortScanCommand
    foreach ($item in @(Receive-Job -Job $script:PortScanJob -ErrorAction SilentlyContinue)) {
        if ($item.Done) { $scan.Result = $item } elseif ($null -ne $item.Progress) { $scan.Last = $item }
    }
    if ($scan.Last -and ((Get-Date) - $scan.ProgressSent).TotalSeconds -ge 3) {
        $scan.ProgressSent = Get-Date
        [void](Send-CommandStatus -Id $scan.Id -Status running -Progress $scan.Last.Progress -Message "Scanning $($scan.Count) ports of $($scan.Ip) from $($scan.Interface): $($scan.Last.Found) open")
    }
    if ($script:PortScanJob.State -eq 'Running') { return }

    $reason = $script:PortScanJob.ChildJobs[0].JobStateInfo.Reason
    Remove-Job -Job $script:PortScanJob -Force
    $script:PortScanJob = $null
    if (-not $scan.Result) {
        Write-AgentLog "Port scan of $($scan.Ip) failed: $reason" -IsError
        [void](Send-CommandStatus -Id $scan.Id -Status failed -Message "Port scan failed: $reason")
        $script:PortScanCommand = $null
        return
    }
    $open = @($scan.Result.Open)
    $findings = @($scan.Result.Findings)
    try {
        Invoke-MdmApi -Method Post -Path 'device/port-scan' -Token $script:AgentToken -Body @{ ip = $scan.Ip; ports = $open; findings = $findings } | Out-Null
        Write-AgentLog "Port scan of $($scan.Ip): $($open.Count) open, $($findings.Count) notes"
        $note = if ($findings.Count) { ", $($findings.Count) note(s)" } else { '' }
        [void](Send-CommandStatus -Id $scan.Id -Status succeeded -Message "$($open.Count) open $(if ($open.Count -eq 1) { 'port' } else { 'ports' }) on $($scan.Ip)$note")
    }
    catch {
        Write-AgentLog "Port scan result not sent: $($_.Exception.Message)" -ErrorRecord $_
        [void](Send-CommandStatus -Id $scan.Id -Status failed -Message "Scanned, but sending the result failed: $($_.Exception.Message)")
    }
    $script:PortScanCommand = $null
}

function Get-WakeInterfaces {
    # The local interfaces in the networks of the broadcast addresses (network broadcast => name and
    # address), so each packet leaves on the interface of its network, not the default route's.
    param ([System.Net.IPAddress[]]$Broadcasts)

    $found = @{}
    foreach ($network in Get-LocalNetworks) {
        if ($Broadcasts -contains $network.Broadcast -and -not $found.ContainsKey("$($network.Broadcast)")) {
            $found["$($network.Broadcast)"] = @{ Name = $network.Name; Address = $network.Address }
        }
    }
    return $found
}

function Send-MagicPacket {
    # Wake-on-LAN for a device in this network: 6 x 0xFF and 16 x its MAC, as UDP broadcast to
    # ports 9 and 7. Each network's packets leave on the interface with an address in it (bound to
    # that address, on Linux also to the interface), together with 255.255.255.255; without such an
    # interface they go through the default route. Returns the interfaces used.
    param (
        [string[]]
        $Macs,
        [System.Net.IPAddress[]]
        $Broadcasts
    )

    # @(): one MAC must stay a list of one packet, not become the bytes of it.
    $packets = @(foreach ($mac in $Macs) {
            $bytes = [byte[]]($mac -split '[:-]' | ForEach-Object { [Convert]::ToByte($_, 16) })
            , [byte[]](@(0xFF) * 6 + ($bytes * 16))
        })
    $limited = [System.Net.IPAddress]::Broadcast
    $interfaces = Get-WakeInterfaces -Broadcasts $Broadcasts
    # One send per interface (its network's broadcast and 255.255.255.255), the rest unbound.
    $routes = @(foreach ($broadcast in $Broadcasts | Where-Object { -not $_.Equals($limited) }) {
            $interface = $interfaces["$broadcast"]
            if ($interface) { @{ Interface = $interface; Targets = @($broadcast, $limited) } }
        })
    $unbound = @($Broadcasts | Where-Object { -not $interfaces.ContainsKey("$_") -or $_.Equals($limited) })
    if ($routes.Count -gt 0) {
        # 255.255.255.255 goes out with the bound ones.
        $unbound = @($unbound | Where-Object { -not $_.Equals($limited) })
    }
    if ($unbound.Count -gt 0) { $routes += @{ Interface = $null; Targets = $unbound } }

    $used = @()
    foreach ($route in $routes) {
        $client = if ($route.Interface) {
            New-Object System.Net.Sockets.UdpClient (New-Object System.Net.IPEndPoint($route.Interface.Address, 0))
        } else {
            New-Object System.Net.Sockets.UdpClient
        }
        try {
            $client.EnableBroadcast = $true
            if ($route.Interface -and $OnLinux) {
                # SO_BINDTODEVICE (the agent runs as root): 255.255.255.255 too leaves on it.
                try { $client.Client.SetRawSocketOption(1, 25, [System.Text.Encoding]::ASCII.GetBytes("$($route.Interface.Name)`0")) } catch { }
            }
            foreach ($packet in $packets) {
                foreach ($target in $route.Targets) {
                    foreach ($port in 9, 7) {
                        [void]$client.Send($packet, $packet.Length, (New-Object System.Net.IPEndPoint($target, $port)))
                    }
                }
            }
        }
        finally {
            $client.Close()
        }
        $used += if ($route.Interface) { "$($route.Interface.Name) ($($route.Interface.Address))" } else { "the default route ($($route.Targets -join ', '))" }
    }
    return $used
}

function Save-CommandState {
    # The update being installed, the ones waiting and a waiting agent update, so they go on
    # after the agent restarts (it restarts itself when an update replaced its PowerShell).
    $state = @{
        running = if ($script:UpdateJob) { @{ id = $script:UpdateCommandId } } else { $null }
        queue = @($script:UpdateQueue.ToArray() | ForEach-Object { @{ id = $_.Id; params = $_.Params } })
        deferred_agent_update = $script:DeferredAgentUpdate
    }
    try {
        $state | ConvertTo-Json -Depth 5 -Compress | Set-Content -Path "$AgentDir/commands-state.json" -Encoding UTF8
    }
    catch {
        Write-AgentLog "Command state not saved: $($_.Exception.Message)" -ErrorRecord $_
    }
}

function Restore-CommandState {
    # After a restart: the result of the update that was running (written by its job) or that it
    # was interrupted, then the waiting ones are started again.
    $path = "$AgentDir/commands-state.json"
    if (-not (Test-Path -Path $path)) {
        return
    }
    try {
        $state = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
    }
    catch {
        Remove-Item -Path $path -Force -ErrorAction SilentlyContinue
        return
    }
    $resultPath = "$AgentDir/update-result.json"
    if ($state.running -and $state.running.id) {
        $result = try { Get-Content -Path $resultPath -Raw -Encoding UTF8 | ConvertFrom-Json } catch { $null }
        if ($result -and "$($result.CommandId)" -eq "$($state.running.id)") {
            Send-UpdateResult -Id $state.running.id -Result $result
        } else {
            [void](Send-CommandStatus -Id $state.running.id -Status failed -Message 'Interrupted: the agent restarted before the update finished')
        }
    }
    Remove-Item -Path $resultPath -Force -ErrorAction SilentlyContinue
    foreach ($item in @($state.queue)) {
        if (-not $item) { continue }
        $params = $null
        if ($item.params) {
            try { $params = Test-UpdateParams -Params $item.params } catch { [void](Send-CommandStatus -Id $item.id -Status failed -Message "Refused by the agent: $($_.Exception.Message)"); continue }
        }
        $script:UpdateQueue.Enqueue(@{ Id = $item.id; Params = $params })
    }
    if ($state.deferred_agent_update) {
        $script:DeferredAgentUpdate = $state.deferred_agent_update
    }
    Remove-Item -Path $path -Force -ErrorAction SilentlyContinue
    if ($script:UpdateQueue.Count -gt 0 -or $script:DeferredAgentUpdate) {
        Write-AgentLog "Resuming $($script:UpdateQueue.Count) waiting update(s)$(if ($script:DeferredAgentUpdate) { ' and the agent update' })"
        Start-NextUpdate
    }
}

function Send-UpdateResult {
    param ($Id, $Result)

    $restart = if ($Result.Restart) { ', restart required' } else { '' }
    if (@($Result.Failures).Count -gt 0) {
        [void](Send-CommandStatus -Id $Id -Status failed -Message ((@($Result.Failures) -join '; ') + $restart))
    } else {
        [void](Send-CommandStatus -Id $Id -Status succeeded -Progress 100 -Message "Done$restart")
    }
}

function Start-UpdateJob {
    # Installs OS, package and module updates in the background, or a single one (-Params of
    # installUpdate). Every step is logged to agent.log when the job ends (see Complete-UpdateJob),
    # the full tool output to updates.log; the progress goes to update-progress.json.
    param (
        $CommandId,
        [hashtable]
        $Params
    )

    $script:UpdateJobStarted = Get-Date
    $script:UpdateCommandId = $CommandId
    $script:UpdateProgress = $null
    $progressFile = "$AgentDir/update-progress.json"
    Remove-Item -Path $progressFile -Force -ErrorAction SilentlyContinue
    [void](Send-CommandStatus -Id $CommandId -Status running -Progress 0 -Message 'Starting')
    $script:UpdateJob = Start-AgentJob -Name 'updates' -Functions 'Install-ChromeRelease', 'Get-ChromeInstall', 'Get-ChromeUpdate', 'Install-WindowsUpdate', 'Invoke-WingetInUserSession', 'Get-WingetUpdates', 'Get-WingetStatus', 'Install-PowerShellRelease', 'Get-PowerShellReleaseUpdate', 'Get-PowerShellManager', 'Get-WingetPath', 'Get-WingetSoftware', 'ConvertFrom-WingetTable', 'Get-PowerShellHosts', 'Invoke-PowerShellModules', 'Get-UserCommand', 'Test-PythonExternallyManaged', 'Get-PythonAptPackage', 'Get-PythonUpdates' -ArgumentList $OnLinux, "$AgentDir/updates.log", $progressFile, $Params, $CommandId, "$AgentDir/update-result.json" -ScriptBlock {
        param ($OnLinux, $OutputLog, $ProgressFile, $Params, $CommandId, $ResultFile)

        # When an update replaces the PowerShell the agent runs in (apt, a GitHub release, snap,
        # winget), the agent has to start again in the new one: compared at the end.
        $runtime = @('pwsh.dll', 'System.Management.Automation.dll') | ForEach-Object { Join-Path $PSHOME $_ } | Where-Object { Test-Path -Path $_ } | Select-Object -First 1
        $runtimeBefore = if ($runtime) { $item = Get-Item -Path $runtime; "$($item.Length)|$($item.LastWriteTimeUtc.Ticks)" }

        if ((Test-Path -Path $OutputLog) -and (Get-Item -Path $OutputLog).Length -gt 2MB) {
            Move-Item -Path $OutputLog -Destination "$OutputLog.1" -Force
        }
        $state = @{ Failures = [System.Collections.ArrayList]@(); Current = $null; Done = [System.Collections.ArrayList]@(); Base = 0; Span = 100; Last = $null; WingetPercent = 0; WingetLabel = 'winget upgrade' }
        function Set-Progress ([int]$Percent, [string]$Message) {
            # Percent within the current step (Base .. Base + Span), written for the agent loop.
            $total = [Math]::Min(100, [Math]::Max(0, $state.Base + [int]($state.Span * $Percent / 100)))
            $key = "$total|$Message|$($state.Current)|$($state.Done.Count)"
            if ($key -eq $state.Last) { return }
            $state.Last = $key
            $temp = "$ProgressFile.tmp"
            # current: what is being installed now, done: what is finished (targets "kind:edition:id:user"
            # of the portal's update rows, or "group:<provider>" for a step that updates a whole group).
            @{ progress = $total; message = $Message; current = $state.Current; done = @($state.Done | Select-Object -Last 300) } | ConvertTo-Json -Compress | Set-Content -Path $temp -Encoding UTF8
            Move-Item -Path $temp -Destination $ProgressFile -Force
        }
        function Set-Current ([string]$Target) {
            # The row (or group) the following progress belongs to; the previous one is finished.
            Complete-Current
            $state.Current = $Target
        }
        function Complete-Current {
            if ($state.Current) { [void]$state.Done.Add($state.Current) }
            $state.Current = $null
        }
        function Enter-Step ([int]$From, [int]$To, [string]$Message) {
            $state.Base = $From
            $state.Span = $To - $From
            Set-Progress 0 $Message
        }
        function Invoke-Logged ([string]$Title, [scriptblock]$Command, [scriptblock]$OnLine) {
            # Runs a native command, keeps its whole output in updates.log and returns it.
            # -OnLine sees each line as it comes (progress); it returns $true for lines to drop.
            "===== {0:yyyy-MM-dd HH:mm:ss} $Title" -f (Get-Date) | Add-Content -Path $OutputLog -Encoding UTF8
            $output = @(& $Command 2>&1 | ForEach-Object {
                $line = "$_"
                if (-not ($OnLine -and (& $OnLine $line))) { $line }
            })
            $output | Add-Content -Path $OutputLog -Encoding UTF8
            "exit code $LASTEXITCODE" | Add-Content -Path $OutputLog -Encoding UTF8
            return , $output
        }
        function Get-Tail ($Lines) { (@($Lines | Where-Object { $_.Trim() }) | Select-Object -Last 3) -join ' | ' }
        function Add-Result ([string]$Name, [int]$Code, $Output, [int[]]$Ok = @(0)) {
            if ($Ok -notcontains $Code) {
                [void]$state.Failures.Add("${Name}: exit $Code$(if ($Output) { ', ' + (Get-Tail $Output) })")
            }
        }
        function Invoke-PipUpgrade ([string]$Id, [string]$User, [bool]$RefreshApt = $false) {
            # A pip package of a user. When the distribution manages its Python (PEP 668) pip refuses,
            # so the package is updated by apt (python3-name) if it came from there, else pip is told
            # to go on anyway (--break-system-packages). Failures go to the result like any other step.
            $python = @('python3', 'python') | Where-Object { Get-Command -Name $_ -CommandType Application -ErrorAction SilentlyContinue } | Select-Object -First 1
            if (-not $python) { [void]$state.Failures.Add('python not found'); return }
            $managed = $OnLinux -and (Test-PythonExternallyManaged $python)
            $aptPackage = if ($managed) { Get-PythonAptPackage $Id }
            if ($aptPackage) {
                if ($RefreshApt) { $null = Invoke-Logged 'apt-get update' { apt-get @lock -q update } }
                $output = Invoke-Logged "apt-get install --only-upgrade $aptPackage" { apt-get @lock @aptOptions install --only-upgrade $aptPackage }
                "apt-get install --only-upgrade ${aptPackage} (python $Id): exit $LASTEXITCODE"
                Add-Result "apt-get install $aptPackage" $LASTEXITCODE $output
                return
            }
            $asUser = if ($User) { Get-UserCommand -User $User }
            if ($User -and -not $asUser) { [void]$state.Failures.Add("user $User not found"); return }
            $extra = @(if ($managed) { '--break-system-packages' })
            $title = "pip install --upgrade $Id$(if ($User) { " ($User)" })"
            $run = {
                param ([string[]]$PipArguments)
                if ($asUser) { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) $python -m pip install --user --upgrade @PipArguments $Id }
                else { & $python -m pip install --user --upgrade @PipArguments $Id }
            }
            $output = Invoke-Logged $title { & $run $extra }
            $code = $LASTEXITCODE
            if ($code -ne 0 -and -not $managed -and ($output -join "`n") -match 'externally-managed-environment') {
                # The marker was not found by the check, but pip refused: go on the same way.
                $output = Invoke-Logged "$title --break-system-packages" { & $run @('--break-system-packages') }
                $code = $LASTEXITCODE
            }
            "$title$(if ($extra) { ' --break-system-packages' }): exit $code"
            Add-Result "pip install --upgrade $Id" $code $output
        }
        # apt progress (APT::Status-Fd=1): "dlstatus:..:percent:text" while downloading (first
        # third of the step), "pmstatus:..:percent:text" while installing.
        $aptProgress = {
            param ($Line)
            if ($Line -match '^(dlstatus|pmstatus):[^:]*:([\d.]+):(.*)$') {
                $percent = [double]$Matches[2]
                $overall = if ($Matches[1] -eq 'dlstatus') { $percent / 3 } else { 33 + $percent * 2 / 3 }
                Set-Progress ([int]$overall) $Matches[3]
                return $true
            }
            return $false
        }
        $wingetOk = @(0, -1978335189) # APPINSTALLER_CLI_ERROR_UPDATE_NOT_APPLICABLE: nothing to update
        # One package, killed with its installer after the timeout: an installer that waits for a
        # window nobody sees (SYSTEM has no desktop) or for an application to close would
        # otherwise hang the whole update forever. Returns the output; $LASTEXITCODE is set.
        $wingetTimeout = 900
        function Invoke-WingetUpgrade ([string]$Winget, [string]$Id, [string]$Source) {
            # The Microsoft Store first; only when it fails (not there, its REST API down:
            # 0x8A15003B) the winget repository, whatever source the inventory listed.
            $output = Invoke-WingetUpgradeOnce -Winget $Winget -Id $Id -Source 'msstore'
            $code = $LASTEXITCODE
            if ($wingetOk -notcontains $code) {
                "winget upgrade ${Id}: msstore failed (exit $code), trying the winget source" | Add-Content -Path $OutputLog -Encoding UTF8
                $fallback = Invoke-WingetUpgradeOnce -Winget $Winget -Id $Id -Source 'winget'
                $code = $LASTEXITCODE
                $output = @($output) + @($fallback)
            }
            if ($code -eq -1978335090) {
                # 0x8A15008E: the new version uses another install technology: the old one is
                # uninstalled first (as winget does on its own for packages that ask for it).
                "winget upgrade ${Id}: another install technology, uninstalling the previous version first" | Add-Content -Path $OutputLog -Encoding UTF8
                $again = Invoke-WingetUpgradeOnce -Winget $Winget -Id $Id -Source 'winget' -Extra @('--uninstall-previous')
                $code = $LASTEXITCODE
                $output = @($output) + @($again)
            }
            if ($code -in -1978335226, -1978334964) {
                # 0x8A150006 / 0x8A15010C: the installer does not run (or gives up) as SYSTEM, which
                # has no desktop: tried again in the session of the user who is logged on.
                $asUser = Invoke-WingetUpgradeAsUser -Id $Id
                if ($null -ne $asUser) {
                    $code = $LASTEXITCODE
                    $output = @($output) + @($asUser)
                }
            }
            $global:LASTEXITCODE = $code
            return , $output
        }
        function Invoke-WingetUpgradeAsUser ([string]$Id, [string]$Source = 'winget') {
            # In the session of the logged-on user, the way they would run it; $null when nobody
            # is logged on. The id is of the checked form (Test-UpdateParams); the source is the
            # one the inventory listed (Store apps are installed per user: msstore).
            $Source = if ($Source -eq 'msstore') { 'msstore' } else { 'winget' }
            "===== {0:yyyy-MM-dd HH:mm:ss} winget upgrade $Id ($Source, in the session of the logged-on user)" -f (Get-Date) | Add-Content -Path $OutputLog -Encoding UTF8
            $started = Get-Date
            try {
                $session = Invoke-WingetInUserSession -Arguments "upgrade --id `"$Id`" --exact --silent --accept-source-agreements --accept-package-agreements --disable-interactivity --source $Source" `
                    -Title "updating $Id" -TimeoutSeconds $wingetTimeout -OnWait { param ($lines) Set-WingetProgress -Status "$(Get-WingetStatus -Lines $lines), in the session of the user" -Started $started }
            }
            catch {
                $output = @("Not run in the session of the logged-on user: $($_.Exception.Message)")
                $output | Add-Content -Path $OutputLog -Encoding UTF8
                $global:LASTEXITCODE = -1
                return , $output
            }
            if ($null -eq $session) {
                "winget upgrade ${Id}: nobody is logged on, not tried in a user session" | Add-Content -Path $OutputLog -Encoding UTF8
                return $null
            }
            $output = @($session.Lines | Where-Object { $_ -match '\S' -and $_ -notmatch '^[\s\-\\|/\u2588\u2592]*$' })
            @("(in the session of $($session.User))") + $output | Add-Content -Path $OutputLog -Encoding UTF8
            "exit code $($session.Code)" | Add-Content -Path $OutputLog -Encoding UTF8
            $global:LASTEXITCODE = $session.Code
            return , $output
        }
        function Set-WingetProgress ([string]$Status, [datetime]$Started) {
            # The step's message ("winget upgrade X (2/5)") with what winget does and for how long.
            $elapsed = (Get-Date) - $Started
            Set-Progress $state.WingetPercent ("{0}: {1} ({2}:{3:00})" -f $state.WingetLabel, $Status, [int][Math]::Floor($elapsed.TotalMinutes), $elapsed.Seconds)
        }
        function Invoke-WingetUpgradeOnce ([string]$Winget, [string]$Id, [string]$Source, [string[]]$Extra = @()) {
            "===== {0:yyyy-MM-dd HH:mm:ss} winget upgrade $Id ($Source $($Extra -join ' '))" -f (Get-Date) | Add-Content -Path $OutputLog -Encoding UTF8
            $out = [System.IO.Path]::GetTempFileName()
            $err = [System.IO.Path]::GetTempFileName()
            $arguments = @('upgrade', '--id', $Id, '--exact', '--silent', '--accept-source-agreements', '--accept-package-agreements', '--disable-interactivity') + @($Extra)
            if ($Source -in 'winget', 'msstore') { $arguments += @('--source', $Source) }
            try {
                $process = Start-Process -FilePath $Winget -ArgumentList $arguments -NoNewWindow -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
                $null = $process.Handle # without it ExitCode stays empty in Windows PowerShell
                # What winget does (download, installer) every 2 s in the progress.
                $started = Get-Date
                $deadline = $started.AddSeconds($wingetTimeout)
                while (-not $process.WaitForExit(2000) -and (Get-Date) -lt $deadline) {
                    $text = try {
                        $stream = [System.IO.File]::Open($out, 'Open', 'Read', 'ReadWrite')
                        try { if ($stream.Length -gt 8192) { [void]$stream.Seek(-8192, 'End') }; (New-Object System.IO.StreamReader($stream)).ReadToEnd() } finally { $stream.Dispose() }
                    } catch { '' }
                    Set-WingetProgress -Status "$(Get-WingetStatus -Lines @($text -split "`n"))$(if ($Source) { ", $Source" })" -Started $started
                }
                if ($process.HasExited) {
                    $code = $process.ExitCode
                } else {
                    # The installer is a child of winget: end the whole tree.
                    & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null
                    $code = -1
                }
                $output = @(Get-Content -Path $out, $err -Encoding UTF8 -ErrorAction SilentlyContinue | Where-Object { $_ -match '\S' -and $_ -notmatch '^[\s\-\\|/\u2588\u2592]*$' })
                if ($code -eq -1) { $output += "Timed out after $([int]($wingetTimeout / 60)) min, the installer was ended" }
            }
            finally {
                Remove-Item -Path $out, $err -Force -ErrorAction SilentlyContinue
            }
            $output | Add-Content -Path $OutputLog -Encoding UTF8
            "exit code $code" | Add-Content -Path $OutputLog -Encoding UTF8
            $global:LASTEXITCODE = $code
            return , $output
        }
        $env:DEBIAN_FRONTEND = 'noninteractive'
        # Wait for a running apt / unattended-upgrades instead of failing on its lock.
        $lock = '-o', 'DPkg::Lock::Timeout=600'
        $aptOptions = @('-y', '-q', '-o', 'APT::Status-Fd=1', '-o', 'Dpkg::Options::=--force-confdef', '-o', 'Dpkg::Options::=--force-confold')

        if ($Params) {
            # One update (installUpdate); the parameters were checked by Test-UpdateParams.
            $id = $Params.id
            switch ($Params.kind) {
                'windows' {
                    try { Install-WindowsUpdate -UpdateId $id -OnProgress { param ($p, $m) Set-Progress $p $m } -State $state } catch { [void]$state.Failures.Add("Windows Update: $($_.Exception.Message)") }
                }
                'winget' {
                    $winget = Get-WingetPath
                    if (-not $winget) { [void]$state.Failures.Add('winget not found'); break }
                    Set-Progress 10 "winget upgrade $id"
                    $state.WingetPercent = 10
                    $state.WingetLabel = "winget upgrade $id"
                    if ($Params.scope -eq 'user') {
                        # Installed only for the user: SYSTEM does not see it.
                        $output = Invoke-WingetUpgradeAsUser -Id $id -Source $Params.source
                        if ($null -eq $output) { $output = @('Installed only for a user, who is not logged on now: it is updated when they are'); $global:LASTEXITCODE = -1 }
                    } else {
                        $output = Invoke-WingetUpgrade -Winget $winget -Id $id -Source $Params.source
                    }
                    "winget upgrade ${id}: exit $LASTEXITCODE"
                    Add-Result "winget upgrade $id" $LASTEXITCODE $output $wingetOk
                }
                'apt' {
                    Enter-Step 0 10 'apt-get update'
                    $output = Invoke-Logged 'apt-get update' { apt-get @lock -q update }
                    "apt-get update: exit $LASTEXITCODE"
                    Enter-Step 10 100 "apt-get install $id"
                    $output = Invoke-Logged "apt-get install --only-upgrade $id" { apt-get @lock @aptOptions install --only-upgrade $id } $aptProgress
                    "apt-get install --only-upgrade ${id}: exit $LASTEXITCODE"
                    Add-Result "apt-get install $id" $LASTEXITCODE $output
                }
                'flatpak' {
                    Set-Progress 10 "flatpak update $id"
                    if ($Params.user) {
                        $asUser = Get-UserCommand -User $Params.user
                        if (-not $asUser) { [void]$state.Failures.Add("user $($Params.user) not found"); break }
                        $output = Invoke-Logged "flatpak update $id ($($Params.user))" { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) flatpak update --user -y --noninteractive $id }
                    } else {
                        $output = Invoke-Logged "flatpak update $id" { flatpak update --system -y --noninteractive $id }
                    }
                    "flatpak update ${id}: exit $LASTEXITCODE"
                    Add-Result "flatpak update $id" $LASTEXITCODE $output
                }
                'snap' {
                    Set-Progress 10 "snap refresh $id"
                    $output = Invoke-Logged "snap refresh $id" { snap refresh $id }
                    "snap refresh ${id}: exit $LASTEXITCODE"
                    Add-Result "snap refresh $id" $LASTEXITCODE $output
                }
                'pip' {
                    # A user's --user package (never the system Python); apt or pip, see Invoke-PipUpgrade.
                    Set-Progress 10 "pip install --upgrade $id"
                    Invoke-PipUpgrade -Id $id -User $Params.user -RefreshApt $true
                }
                'pipx' {
                    Set-Progress 10 "pipx upgrade $id"
                    if ($Params.user) {
                        $asUser = Get-UserCommand -User $Params.user
                        if (-not $asUser) { [void]$state.Failures.Add("user $($Params.user) not found"); break }
                        $output = Invoke-Logged "pipx upgrade $id ($($Params.user))" { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) pipx upgrade $id }
                    } else {
                        if (-not (Get-Command -Name pipx -CommandType Application -ErrorAction SilentlyContinue)) { [void]$state.Failures.Add('pipx not found'); break }
                        $output = Invoke-Logged "pipx upgrade $id" { pipx upgrade $id }
                    }
                    "pipx upgrade ${id}: exit $LASTEXITCODE"
                    Add-Result "pipx upgrade $id" $LASTEXITCODE $output
                }
                'pwsh' {
                    Set-Progress 10 "PowerShell $id"
                    Install-PowerShellRelease -Version $id -OnLinux $OnLinux -State $state
                }
                'chrome' {
                    Set-Progress 10 "Google Chrome $id"
                    Install-ChromeRelease -Version $id -State $state
                }
                'module' {
                    Set-Progress 10 "Update-Module $id"
                    if (-not @(Get-PowerShellHosts -OnLinux $OnLinux | Where-Object { $_.Edition -eq $Params.edition })) { [void]$state.Failures.Add("$($Params.edition) is not installed"); break }
                    try {
                        $failed = @(Invoke-PowerShellModules -OnLinux $OnLinux -Update -Names @($id) -Edition $Params.edition -User $Params.user -Version $Params.version -OnProgress { param ($p, $m) Set-Progress $p $m })
                        foreach ($module in $failed) {
                            $reason = if ($module.Error) { $module.Error } else { 'not updated' }
                            [void]$state.Failures.Add("PowerShell module $($module.Name): $reason")
                        }
                        if (-not $failed) { "PowerShell module ${id}: updated" }
                    }
                    catch {
                        [void]$state.Failures.Add("PowerShell module ${id}: $($_.Exception.Message)")
                    }
                }
            }
        } elseif ($OnLinux) {
            Enter-Step 0 5 'apt-get update'
            $output = Invoke-Logged 'apt-get update' { apt-get @lock -q update }
            "apt-get update: exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"

            # --with-new-pkgs installs new dependencies (plain upgrade keeps such packages back),
            # nothing is removed; existing configuration files are kept.
            Set-Current 'group:apt'
            Enter-Step 5 70 'apt-get upgrade'
            $output = Invoke-Logged 'apt-get upgrade' { apt-get @lock @aptOptions --with-new-pkgs upgrade } $aptProgress
            $code = $LASTEXITCODE
            $summary = $output | Where-Object { $_ -match '\d+ upgraded, \d+ newly installed' } | Select-Object -Last 1
            "apt-get upgrade: exit $code$(if ($summary) { ', ' + $summary.Trim() })$(if ($code) { ': ' + (Get-Tail $output) })"
            Add-Result 'apt-get upgrade' $code $output
            $keptBack = $false
            $held = foreach ($line in $output) {
                # apt 2: "... kept back:" / "... deferred due to phasing:", apt 3: "Not upgrading ...:"
                if ($line -match '(kept back|phasing|Not upgrading).*:\s*$') { $keptBack = $true; continue }
                if ($keptBack -and $line -match '^\s+\S') { $line.Trim() } elseif ($keptBack) { $keptBack = $false }
            }
            if ($held) { "apt-get upgrade: kept back (phased or held): $($held -join ' ')" }
            Set-Current 'group:flatpak'
            Enter-Step 70 80 'flatpak update'
            if (Get-Command -Name flatpak -CommandType Application -ErrorAction SilentlyContinue) {
                $output = Invoke-Logged 'flatpak update' { flatpak update --system -y --noninteractive }
                "flatpak update (system): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                Add-Result 'flatpak update' $LASTEXITCODE $output
                foreach ($userHome in @(Get-ChildItem -Path /home -Directory -ErrorAction SilentlyContinue | Where-Object { Test-Path -Path "$($_.FullName)/.local/share/flatpak" })) {
                    $user = $userHome.Name
                    $asUser = Get-UserCommand -User $user
                    if (-not $asUser) { continue }
                    $output = Invoke-Logged "flatpak update ($user)" { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) flatpak update --user -y --noninteractive }
                    "flatpak update ($user): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                    Add-Result "flatpak update ($user)" $LASTEXITCODE $output
                }
            }
            Set-Current 'group:snap'
            Enter-Step 80 90 'snap refresh'
            if (Get-Command -Name snap -CommandType Application -ErrorAction SilentlyContinue) {
                $output = Invoke-Logged 'snap refresh' { snap refresh }
                "snap refresh: exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                Add-Result 'snap refresh' $LASTEXITCODE $output
            }
            Enter-Step 90 93 'pip install --upgrade'
            # Each user's pip --user packages, one at a time (apt for the ones it manages, see Invoke-PipUpgrade).
            $pipPackages = @(try { Get-PythonUpdates | Where-Object { "$($_.Source)" -match '^pip( \(|$)' } } catch { })
            $pipDone = 0
            foreach ($pip in $pipPackages) {
                $pipUser = if ("$($pip.Source)" -match '\((.+)\)$') { $Matches[1] } else { '' }
                Set-Current "pip::$($pip.Id):$pipUser"
                Set-Progress ([int](100 * $pipDone / [Math]::Max(1, $pipPackages.Count))) "pip install --upgrade $($pip.Id) ($($pipDone + 1)/$($pipPackages.Count))"
                Invoke-PipUpgrade -Id $pip.Id -User $pipUser
                $pipDone++
            }
            Set-Current 'group:pipx'
            Enter-Step 93 95 'pipx upgrade-all'
            # Each user's pipx apps (pipx is per user).
            foreach ($userHome in @(Get-ChildItem -Path /home -Directory -ErrorAction SilentlyContinue)) {
                $asUser = Get-UserCommand -User $userHome.Name
                if (-not $asUser) { continue }
                if (& $asUser[0] @($asUser[1..($asUser.Count - 1)]) sh -c 'command -v pipx' 2>$null) {
                    $output = Invoke-Logged "pipx upgrade-all ($($userHome.Name))" { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) pipx upgrade-all }
                    "pipx upgrade-all ($($userHome.Name)): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                    Add-Result "pipx upgrade-all ($($userHome.Name))" $LASTEXITCODE $output
                }
            }
            if (Get-Command -Name pipx -CommandType Application -ErrorAction SilentlyContinue) {
                $output = Invoke-Logged 'pipx upgrade-all' { pipx upgrade-all }
                "pipx upgrade-all: exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                Add-Result 'pipx upgrade-all' $LASTEXITCODE $output
            }
        } else {
            Enter-Step 0 40 'winget'
            $winget = Get-WingetPath
            if ($winget) {
                # One package at a time instead of "upgrade --all": each has a timeout, one that
                # hangs or fails does not stop the others. winget also updates PowerShell 7
                # (Microsoft.PowerShell). App Installer is winget itself: replacing it ends winget.
                $listed = try { @(Get-WingetUpdates) } catch { [void]$state.Failures.Add("winget: $($_.Exception.Message)"); @() }
                # Frameworks of Store apps (VCLibs, UI.Xaml, Windows App Runtime) are updated by the Store,
                # winget fails on them as SYSTEM (0x8A15005C).
                $packages = @($listed | Where-Object { $_.Id -and $_.Id -notmatch '^Microsoft\.(AppInstaller|VCLibs|UI\.Xaml|WindowsAppRuntime)' -and "$($_.Avaliable)" -ne '' })
                $done = 0
                foreach ($package in $packages) {
                    $state.WingetPercent = [int](40 * $done / [Math]::Max(1, $packages.Count))
                    $state.WingetLabel = "winget upgrade $($package.Id) ($($done + 1)/$($packages.Count))"
                    Set-Current "winget::$($package.Id):"
                    Set-Progress $state.WingetPercent $state.WingetLabel
                    $output = if ($package.Scope -eq 'user') { Invoke-WingetUpgradeAsUser -Id $package.Id -Source $package.Source } else { Invoke-WingetUpgrade -Winget $winget -Id $package.Id -Source $package.Source }
                    if ($null -eq $output) { $output = @('Installed only for a user, who is not logged on now'); $global:LASTEXITCODE = -1 }
                    "winget upgrade $($package.Id): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                    Add-Result "winget upgrade $($package.Id)" $LASTEXITCODE $output $wingetOk
                    $done++
                }
                if ($packages.Count -eq 0) { 'winget: nothing to update' }
            } else {
                'winget: not found, application updates skipped'
            }
            # Google Chrome of all users when winget did not update it (no winget on servers, or its manifest lags behind).
            Set-Current 'group:chrome'
            Enter-Step 38 40 'Google Chrome'
            try {
                $update = Get-ChromeUpdate -Known @()
                if ($update) { Install-ChromeRelease -Version $update.Avaliable -State $state }
            }
            catch {
                "Google Chrome: $($_.Exception.Message)"
            }
            Set-Current 'group:windows'
            Enter-Step 40 90 'Windows Update'
            try { Install-WindowsUpdate -OnProgress { param ($p, $m) Set-Progress $p $m } -State $state } catch { "Windows Update failed: $($_.Exception.Message)"; [void]$state.Failures.Add("Windows Update: $($_.Exception.Message)") }
        }

        if (-not $Params) {
            # A PowerShell 7 release no package manager updated (winget / apt do it themselves).
            Set-Current 'group:powershell'
            Enter-Step 88 90 'PowerShell 7'
            try {
                $release = Get-PowerShellReleaseUpdate -OnLinux $OnLinux -Known @()
                # Package managers ran first: still outdated means none of them manages PowerShell.
                if ($release) {
                    Install-PowerShellRelease -Version $release.Avaliable -OnLinux $OnLinux -State $state
                }
            }
            catch {
                "PowerShell 7: $($_.Exception.Message)"
            }
            Enter-Step 90 100 'PowerShell modules'
            try {
                $failed = @(Invoke-PowerShellModules -OnLinux $OnLinux -Update -OnProgress { param ($p, $m) Set-Progress $p $m })
                if (-not $failed) { 'PowerShell modules: up to date' }
                foreach ($module in $failed) {
                    $owner = if ($module.User) { ", user $($module.User)" } else { '' }
                    $reason = if ($module.Error) { $module.Error } elseif ($module.User) { 'in the user profile, only the user can update it' } else { 'not updated' }
                    "PowerShell module $($module.Name) $($module.Version) -> $($module.Available) ($($module.Edition)$owner): $reason"
                    # Modules only their user can update are no failure of this run.
                    if ($module.Error) { [void]$state.Failures.Add("PowerShell module $($module.Name): $($module.Error)") }
                }
            }
            catch {
                "PowerShell modules failed: $($_.Exception.Message)"
            }
        }
        Complete-Current
        $restart = if ($OnLinux) { Test-Path -Path /var/run/reboot-required } else { [bool]$state.Restart }
        if ($restart) { 'Restart required' }

        $runtimeAfter = if ($runtime -and (Test-Path -Path $runtime)) { $item = Get-Item -Path $runtime; "$($item.Length)|$($item.LastWriteTimeUtc.Ticks)" }
        if ($runtime -and $runtimeAfter -ne $runtimeBefore) {
            'PowerShell of the agent was updated, the agent restarts'
            $state.RestartAgent = $true
        }

        # The result for the portal, after the log lines; also in a file, in case the agent is
        # gone (restarted) before it reads the job.
        $result = [pscustomobject]@{ UpdateResult = $true; CommandId = $CommandId; Failures = @($state.Failures); Restart = $restart; RestartAgent = [bool]$state.RestartAgent }
        try { $result | ConvertTo-Json -Depth 4 -Compress | Set-Content -Path $ResultFile -Encoding UTF8 } catch { }
        $result
    }
    Save-CommandState
    Write-AgentLog "Installing updates started$(if ($Params) { " ($($Params.kind) $($Params.id))" }) (details in updates.log)"
}

function Sync-UpdateProgress {
    # Sends the progress the update job wrote, at most every 3 seconds.
    $job = $script:UpdateJob
    if (-not $job -or -not $script:UpdateCommandId -or ((Get-Date) - $script:UpdateProgressSent).TotalSeconds -lt 3) {
        return
    }
    $path = "$AgentDir/update-progress.json"
    try {
        $text = if (Test-Path -Path $path) { Get-Content -Path $path -Raw -Encoding UTF8 } else { $null }
    }
    catch {
        return
    }
    if (-not $text -or $text -eq $script:UpdateProgress) {
        return
    }
    $script:UpdateProgress = $text
    $script:UpdateProgressSent = Get-Date
    $progress = $text | ConvertFrom-Json
    [void](Send-CommandStatus -Id $script:UpdateCommandId -Status running -Progress $progress.progress -Message $progress.message -Current "$($progress.current)" -Done @($progress.done | ForEach-Object { "$_" }))
}

function Complete-UpdateJob {
    # Logs the finished update job and reports its result; returns $true when it finished, so
    # the inventory is refreshed. Starts the next queued update then.
    $job = $script:UpdateJob
    if (-not $job -or $job.State -eq 'Running') {
        return $false
    }

    $result = $null
    foreach ($line in @(Receive-Job -Job $job -ErrorAction SilentlyContinue 2>&1)) {
        if ($line.PSObject.Properties['UpdateResult']) { $result = $line; continue }
        Write-AgentLog "Updates: $line"
    }
    if ($job.State -ne 'Completed') {
        Write-AgentLog "Updates: job $($job.State): $($job.ChildJobs[0].JobStateInfo.Reason)"
    }
    Write-AgentLog ('Installing updates finished after {0:n0} min' -f ((Get-Date) - $script:UpdateJobStarted).TotalMinutes)
    Remove-Job -Job $job -Force
    Remove-Item -Path "$AgentDir/update-progress.json" -Force -ErrorAction SilentlyContinue
    $script:UpdateJob = $null

    $resultPath = "$AgentDir/update-result.json"
    if (-not $result -and (Test-Path -Path $resultPath)) {
        $saved = try { Get-Content -Path $resultPath -Raw -Encoding UTF8 | ConvertFrom-Json } catch { $null }
        if ($saved -and "$($saved.CommandId)" -eq "$($script:UpdateCommandId)") { $result = $saved }
    }
    Remove-Item -Path $resultPath -Force -ErrorAction SilentlyContinue
    if (-not $result) {
        [void](Send-CommandStatus -Id $script:UpdateCommandId -Status failed -Message "The update job ended unexpectedly ($($job.State))")
    } else {
        Send-UpdateResult -Id $script:UpdateCommandId -Result $result
    }
    $script:UpdateCommandId = $null
    Save-CommandState
    if ($result -and $result.RestartAgent) {
        # The agent runs in the PowerShell that was just replaced: start it again in the new one,
        # the waiting updates go on after the restart (commands-state.json).
        Write-AgentLog 'PowerShell updated, restarting the agent'
        if (-not $OnLinux) {
            Restart-AgentTask
        }
        exit 0
    }
    Start-NextUpdate

    return $true
}

function Start-NextUpdate {
    # Updates run one after another: the next queued one, then a waiting agent update.
    if ($script:UpdateJob) {
        return
    }
    if ($script:UpdateQueue.Count -gt 0) {
        $next = $script:UpdateQueue.Dequeue()
        Start-UpdateJob -CommandId $next.Id -Params $next.Params
        return
    }
    if ($script:DeferredAgentUpdate) {
        $id = $script:DeferredAgentUpdate
        $script:DeferredAgentUpdate = $null
        Save-CommandState
        Update-Agent -CommandId $id
    }
}

function Invoke-PowerAction {
    # Restarts or turns off the device, also while updates are being installed (their job and
    # installers are ended). Returns the reason when it did not work, $null when it is on its way.
    param ([switch]$Restart)

    if ($script:UpdateJob) {
        Write-AgentLog "$(if ($Restart) { 'Restart' } else { 'Turn off' }) while updates are being installed: they are interrupted"
        Save-CommandState
    }
    try {
        if ($OnLinux) {
            $output = if ($Restart) { systemctl reboot 2>&1 } else { systemctl poweroff 2>&1 }
            if ($LASTEXITCODE -ne 0) { throw "systemctl: exit $LASTEXITCODE $output" }
        } elseif ($Restart) {
            Restart-Computer -Force -ErrorAction Stop
        } else {
            # As the Start menu turns off: /hybrid keeps Fast Startup (a full shutdown, which Stop-Computer
            # does, leaves many network cards unable to wake the PC; without Fast Startup it is a normal one).
            $output = & shutdown.exe /s /hybrid /f /t 0 /d p:4:1 2>&1
            if ($LASTEXITCODE -ne 0) { throw "shutdown /hybrid: exit $LASTEXITCODE $output" }
        }
        return $null
    }
    catch {
        $first = $_.Exception.Message
    }
    # Second try, forced: the cmdlets refuse while an installation holds the shutdown (Windows) or
    # a unit blocks it (Linux).
    if ($OnLinux) {
        $output = if ($Restart) { systemctl reboot --force 2>&1 } else { systemctl poweroff --force 2>&1 }
    } else {
        $output = & shutdown.exe $(if ($Restart) { '/r' } else { '/s' }) /f /t 0 /d p:4:1 2>&1
    }
    if ($LASTEXITCODE -eq 0) {
        return $null
    }

    return "$first; forced: exit $LASTEXITCODE $output".Trim()
}

function Invoke-DeviceCommand {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Command,
        # Given by servers that track commands: the id the progress and the result are reported with.
        $Id,
        $Params
    )

    if ($AllowedCommands -notcontains $Command) {
        Write-AgentLog "Ignoring unknown command '$Command'"
        [void](Send-CommandStatus -Id $Id -Status failed -Message "Unknown command '$Command'")
        return
    }

    Write-AgentLog "Executing command '$Command'$(if ($Id) { " ($Id)" })"
    switch ($Command) {
        'updateAgent' {
            # Replacing the agent ends the update job: the agent update waits for it.
            if ($script:UpdateJob) {
                $script:DeferredAgentUpdate = $Id
                Save-CommandState
                [void](Send-CommandStatus -Id $Id -Status running -Message 'Waiting for the updates to finish')
                return
            }
            Update-Agent -CommandId $Id
        }
        'runScripts' {
            # Only a trigger: the runs are taken, verified and run by the main loop.
            $script:ScriptsRequested = $true
        }
        'sync' {
            # Everything collected again now (inventory, disk health) and reported right away; the
            # main loop does it (it owns the collection jobs) and reports the result.
            $script:SyncRequest = @{ Id = $Id; Phase = 'requested' }
            [void](Send-CommandStatus -Id $Id -Status running -Progress 5 -Message 'Collecting the inventory')
        }
        { $_ -in 'doUpdates', 'installUpdate' } {
            $updateParams = $null
            if ($Command -eq 'installUpdate') {
                try {
                    $updateParams = Test-UpdateParams -Params $Params
                }
                catch {
                    Write-AgentLog "Update refused: $($_.Exception.Message)" -ErrorRecord $_
                    [void](Send-CommandStatus -Id $Id -Status failed -Message "Refused by the agent: $($_.Exception.Message)")
                    return
                }
            }
            if ($script:UpdateJob) {
                if (-not $Id -and -not $updateParams) {
                    Write-AgentLog 'Updates are already being installed, command ignored'
                    return
                }
                $script:UpdateQueue.Enqueue(@{ Id = $Id; Params = $updateParams })
                Save-CommandState
                [void](Send-CommandStatus -Id $Id -Status running -Progress 0 -Message 'Waiting for the updates that are being installed')
                return
            }
            Start-UpdateJob -CommandId $Id -Params $updateParams
        }
        'wake' {
            try {
                $wake = Test-WakeParams -Params $Params
                $via = @(Send-MagicPacket -Macs $wake.Macs -Broadcasts $wake.Broadcasts) -join ', '
                Write-AgentLog "Magic packet sent to $($wake.Macs -join ', ') via $via"
                # Running until the woken device reports (the server finishes it, or it times out).
                [void](Send-CommandStatus -Id $Id -Status running -Progress 50 -Message "Magic packet sent to $($wake.Macs -join ', ') via $via, waiting for the device to come online")
            }
            catch {
                Write-AgentLog "Wake-on-LAN failed: $($_.Exception.Message)" -ErrorRecord $_
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Wake-on-LAN failed: $($_.Exception.Message)")
            }
        }
        'pingNow' {
            # Sync of a ping-only device: pinged now. Only an address the server gave this agent
            # for it (Set-PingTargets), the parameters carry just its id.
            $target = @($script:PingTargets | Where-Object { "$($_.Id)" -eq "$($Params.device)" }) | Select-Object -First 1
            if (-not $target) {
                [void](Send-CommandStatus -Id $Id -Status failed -Message 'It is not one of the devices this agent pings')
                return
            }
            $reply = (Invoke-Pings -Targets @($target) -TimeoutMs 2000)[$target.Id]
            $up = [bool]$reply.Up
            $via = if ($reply.Via) { " via $($reply.Via)" } else { '' }
            $result = @{ id = $target.Id; up = $up; rtt = $(if ($up) { $reply.Rtt } else { $null }); at = Get-UnixTime }
            try { Invoke-MdmApi -Method Post -Path 'device/pings' -Token $script:AgentToken -Body @{ results = @($result) } | Out-Null } catch { Write-AgentLog "Ping result not sent: $($_.Exception.Message)" -ErrorRecord $_ }
            if ($up) {
                [void](Send-CommandStatus -Id $Id -Status succeeded -Message "$($target.Address) answered in $($reply.Rtt) ms$via")
            } else {
                [void](Send-CommandStatus -Id $Id -Status failed -Message "$($target.Address) did not answer within 2 s$via")
            }
        }
        'scanNetwork' {
            # Only when config.json allows it (network_discovery "scan"), one at a time, only a
            # network of an interface of this device.
            $level = Get-NetworkDiscovery
            if ($level -ne 'scan') {
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Scans are not allowed on this device (network_discovery is '$level' in config.json)")
                return
            }
            if ($script:ScanCommand) {
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Already scanning $($script:ScanCommand.Cidr)")
                return
            }
            try {
                $scan = Test-ScanParams -Params $Params
            }
            catch {
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Refused by the agent: $($_.Exception.Message)")
                return
            }
            Start-NetworkScan -CommandId $Id -Scan $scan
            [void](Send-CommandStatus -Id $Id -Status running -Progress 0 -Message "Pinging the $($scan.Hosts) addresses of $($scan.Cidr) from $($scan.Interface)")
        }
        'scanPorts' {
            # Only when config.json allows it (port_scan "on"), one at a time, only an address of a
            # network of an interface of this device.
            $level = Get-PortScan
            if ($level -ne 'on') {
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Port scans are not allowed on this device (port_scan is '$level' in config.json)")
                return
            }
            if ($script:PortScanCommand) {
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Already scanning the ports of $($script:PortScanCommand.Ip)")
                return
            }
            try {
                $scan = Test-PortScanParams -Params $Params
            }
            catch {
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Refused by the agent: $($_.Exception.Message)")
                return
            }
            Start-PortScan -CommandId $Id -Scan $scan
            [void](Send-CommandStatus -Id $Id -Status running -Progress 0 -Message "Scanning $(@($scan.Ports).Count) ports of $($scan.Ip)")
        }
        'turnOff' {
            [void](Send-CommandStatus -Id $Id -Status running -Message 'Shutting down')
            $failure = Invoke-PowerAction -Restart:$false
            if ($failure) {
                Write-AgentLog "Turn off failed: $failure" -IsError
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Turn off failed: $failure")
            } else {
                [void](Send-CommandStatus -Id $Id -Status succeeded -Message 'Shutting down')
            }
        }
        'restart' {
            # Done when the agent is back after the restart.
            Save-PendingCommand -Id $Id -Command 'restart'
            [void](Send-CommandStatus -Id $Id -Status running -Message 'Restarting')
            $failure = Invoke-PowerAction -Restart
            if ($failure) {
                # Not restarting after all: report it now, so it can be tried again.
                Remove-Item -Path "$AgentDir/pending-command.json" -Force -ErrorAction SilentlyContinue
                Write-AgentLog "Restart failed: $failure" -IsError
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Restart failed: $failure")
            }
        }
    }
}

function Invoke-DeviceCommands {
    # The commands of a server response: "tasks" (with ids, servers that track commands) or the
    # names in "commands" (older servers).
    param (
        [Parameter(Mandatory = $true)]
        $Response
    )

    if ($Response.PSObject.Properties['tasks']) {
        foreach ($task in @($Response.tasks)) {
            if ($task -and $task.command) {
                Invoke-DeviceCommand -Command $task.command -Id $task.id -Params $task.params
            }
        }
        return
    }
    foreach ($command in @($Response.commands)) {
        if ($command) {
            Invoke-DeviceCommand -Command $command
        }
    }
}

function Send-WsMessage {
    param (
        [Parameter(Mandatory = $true)]
        [System.Net.WebSockets.ClientWebSocket]
        $Socket,
        [Parameter(Mandatory = $true)]
        [hashtable]
        $Message
    )

    $bytes = [System.Text.Encoding]::UTF8.GetBytes(($Message | ConvertTo-Json -Depth 5 -Compress))
    $segment = New-Object 'System.ArraySegment[byte]' -ArgumentList (, $bytes)
    $Socket.SendAsync($segment, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [System.Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
}

function Start-Realtime {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    if ($NoRealtime) {
        return $null
    }

    $config = Invoke-MdmApi -Path 'device/realtime' -Token $Token
    if (-not $config.enabled) {
        return $null
    }

    # The WebSocket is /app at the address of the portal (nginx proxies it to Reverb), whatever
    # address the server has for Reverb itself; the server only gives the key and the channel.
    # Agents installed before 1.16.0 may still have -ReverbHost / -ReverbPort / -ReverbScheme on the
    # command line of their task / service: they are used until -Install runs again.
    $server = [Uri]$ServerUrl
    $https = if ($ReverbScheme) { $ReverbScheme -eq 'https' } else { $server.Scheme -eq 'https' }
    $wsHost = if ($ReverbHost) { $ReverbHost } else { $server.Host }
    $port = if ($ReverbPort) { $ReverbPort } elseif (-not $ReverbScheme -and -not $server.IsDefaultPort) { $server.Port } elseif ($https) { 443 } else { 80 }
    $key = if ($ReverbKey) { $ReverbKey } else { $config.key }
    $uri = "{0}://{1}:{2}/app/{3}?protocol=7&client=laravel-mdm-agent&version=1.0&flash=false" -f $(if ($https) { 'wss' } else { 'ws' }), $wsHost, $port, $key

    $socket = New-Object System.Net.WebSockets.ClientWebSocket
    $socket.Options.KeepAliveInterval = [TimeSpan]::FromSeconds(30)
    $socket.ConnectAsync([Uri]$uri, [System.Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
    Write-AgentLog "WebSocket connected to $uri"

    return @{ Socket = $socket; Config = $config; Subscribed = $false }
}

function Receive-WsMessage {
    # Waits up to $TimeoutMs for a complete text message; returns $null on timeout.
    param (
        [Parameter(Mandatory = $true)]
        [hashtable]
        $Realtime,
        [int]
        $TimeoutMs = 1000
    )

    if (-not $Realtime.Pending) {
        $Realtime.Buffer = New-Object byte[] 8192
        $Realtime.Pending = $Realtime.Socket.ReceiveAsync((New-Object 'System.ArraySegment[byte]' -ArgumentList (, $Realtime.Buffer)), [System.Threading.CancellationToken]::None)
    }

    if (-not $Realtime.Pending.Wait($TimeoutMs)) {
        return $null
    }

    $result = $Realtime.Pending.Result
    $Realtime.Pending = $null

    if ($result.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) {
        # A WebSocketException: the connection is set up again, it is not an error of the agent.
        throw (New-Object System.Net.WebSockets.WebSocketException "WebSocket closed by server: $($result.CloseStatusDescription)")
    }

    if (-not $Realtime.Message) {
        $Realtime.Message = New-Object System.IO.MemoryStream
    }
    $Realtime.Message.Write($Realtime.Buffer, 0, $result.Count)

    if (-not $result.EndOfMessage) {
        return $null
    }

    $text = [System.Text.Encoding]::UTF8.GetString($Realtime.Message.ToArray())
    $Realtime.Message = $null

    return $text | ConvertFrom-Json
}

function Invoke-RealtimeMessage {
    param (
        [Parameter(Mandatory = $true)]
        [hashtable]
        $Realtime,
        [Parameter(Mandatory = $true)]
        $Message,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $data = $null
    if ($Message.data -is [string] -and $Message.data.Length -gt 0) {
        $data = $Message.data | ConvertFrom-Json
    }

    switch ($Message.event) {
        'pusher:connection_established' {
            $auth = Invoke-MdmApi -Method Post -Path 'broadcasting/auth' -Token $Token -Body @{
                socket_id    = $data.socket_id
                channel_name = $Realtime.Config.channel
            }
            Send-WsMessage -Socket $Realtime.Socket -Message @{
                event = 'pusher:subscribe'
                data  = @{ auth = $auth.auth; channel = $Realtime.Config.channel }
            }
        }
        'pusher_internal:subscription_succeeded' {
            $Realtime.Subscribed = $true
            Write-AgentLog "Subscribed to $($Message.channel)"
        }
        'pusher:ping' {
            Send-WsMessage -Socket $Realtime.Socket -Message @{ event = 'pusher:pong'; data = @{} }
        }
        'pusher:error' {
            throw (New-Object System.Net.WebSockets.WebSocketException "WebSocket error: $($Message.data)")
        }
        'command' {
            if ($Message.channel -ne $Realtime.Config.channel) {
                return
            }
            # Only a trigger signed by the server for this device and not older than 5 minutes;
            # the commands themselves come from the signed API, so nothing can be injected or replayed.
            $payload = if ($data.p -is [string]) { $data.p | ConvertFrom-Json } else { $null }
            if (-not (Test-ServerSignature -Context 'MDM1-WS' -Message "$($data.p)" -Signature "$($data.sig)") -or "$($payload.device_id)" -ne "$($script:DeviceId)" -or [Math]::Abs((Get-UnixTime) - [long]$payload.ts) -gt 300) {
                Write-AgentLog 'Ignoring a command event that is not signed by the server for this device' -IsError
                return
            }
            $response = Invoke-MdmApi -Method Post -Path 'device/commands/take' -Token $Token -Body @{}
            Invoke-DeviceCommands -Response $response
        }
    }
}

function Initialize-SystemMetrics {
    # Plain Win32 calls: negligible cost and independent of the system language (unlike performance counters).
    if (-not ('MdmAgent.SystemMetrics' -as [type])) {
        Add-Type -Namespace MdmAgent -Name SystemMetrics -MemberDefinition @'
[DllImport("kernel32.dll", SetLastError = true)]
public static extern bool GetSystemTimes(out long idleTime, out long kernelTime, out long userTime);

[StructLayout(LayoutKind.Sequential)]
public struct SystemPowerStatus {
    public byte ACLineStatus;
    public byte BatteryFlag;
    public byte BatteryLifePercent;
    public byte SystemStatusFlag;
    public int BatteryLifeTime;
    public int BatteryFullLifeTime;
}

[DllImport("kernel32.dll")]
public static extern bool GetSystemPowerStatus(out SystemPowerStatus status);

[StructLayout(LayoutKind.Sequential)]
public class MemoryStatusEx {
    public uint dwLength = (uint)Marshal.SizeOf(typeof(MemoryStatusEx));
    public uint dwMemoryLoad;
    public ulong ullTotalPhys;
    public ulong ullAvailPhys;
    public ulong ullTotalPageFile;
    public ulong ullAvailPageFile;
    public ulong ullTotalVirtual;
    public ulong ullAvailVirtual;
    public ulong ullAvailExtendedVirtual;
}

[DllImport("kernel32.dll", SetLastError = true)]
public static extern bool GlobalMemoryStatusEx([In, Out] MemoryStatusEx buffer);
'@
    }
}

function Get-CpuMemoryCounters {
    # Cumulative CPU times and current memory, read with negligible cost.
    if ($OnLinux) {
        # Direct file reads, a pipeline would cost ~10x more.
        $fields = [System.IO.File]::ReadLines('/proc/stat') | Select-Object -First 1
        $cpu = [long[]]($fields.Split(' ', [System.StringSplitOptions]::RemoveEmptyEntries)[1..8])
        $memory = @{}
        foreach ($line in [System.IO.File]::ReadAllLines('/proc/meminfo')[0..4]) {
            $parts = $line.Split(' ', [System.StringSplitOptions]::RemoveEmptyEntries)
            $memory[$parts[0].TrimEnd(':')] = [long]$parts[1] * 1024
        }
        # user nice system idle iowait irq softirq steal
        return @{
            Idle        = $cpu[3] + $cpu[4]
            Total       = $cpu[0] + $cpu[1] + $cpu[2] + $cpu[3] + $cpu[4] + $cpu[5] + $cpu[6] + $cpu[7]
            MemoryUsed  = $memory['MemTotal'] - $memory['MemAvailable']
            MemoryTotal = $memory['MemTotal']
        }
    }

    Initialize-SystemMetrics
    $idle = $kernel = $user = [long]0
    [MdmAgent.SystemMetrics]::GetSystemTimes([ref]$idle, [ref]$kernel, [ref]$user) | Out-Null
    $memory = New-Object MdmAgent.SystemMetrics+MemoryStatusEx
    [MdmAgent.SystemMetrics]::GlobalMemoryStatusEx($memory) | Out-Null

    # Kernel time includes idle time.
    return @{
        Idle        = $idle
        Total       = $kernel + $user
        MemoryUsed  = $memory.ullTotalPhys - $memory.ullAvailPhys
        MemoryTotal = $memory.ullTotalPhys
    }
}

function Get-SystemMetrics {
    # CPU usage is the average since the previous call (i.e. over the whole heartbeat interval), so nothing is sampled in between.
    try {
        $counters = Get-CpuMemoryCounters

        $previous = $script:CpuTimes
        $script:CpuTimes = @{ Idle = $counters.Idle; Total = $counters.Total }
        if (-not $previous) {
            return $null
        }

        $total = $counters.Total - $previous.Total
        $cpu = if ($total -gt 0) { [math]::Round((1 - ($counters.Idle - $previous.Idle) / $total) * 100, 1) } else { 0 }

        return @{
            cpu          = [math]::Max(0, $cpu)
            memory_used  = $counters.MemoryUsed
            memory_total = $counters.MemoryTotal
        }
    }
    catch {
        return $null
    }
}

function Get-PowerStatus {
    param (
        [string]
        $PowerSupplyPath = '/sys/class/power_supply'
    )

    # Battery level and whether the device runs on mains power; null without a battery.
    if ($OnLinux) {
        $battery = Get-ChildItem -Path $PowerSupplyPath -Filter 'BAT*' -ErrorAction SilentlyContinue | Select-Object -First 1
        if (-not $battery) {
            return $null
        }
        $mains = @(Get-ChildItem -Path $PowerSupplyPath -ErrorAction SilentlyContinue | Where-Object {
                (Get-Content -Path "$($_.FullName)/type" -ErrorAction SilentlyContinue) -eq 'Mains'
            })
        $plugged = if ($mains) {
            [bool]($mains | Where-Object { (Get-Content -Path "$($_.FullName)/online" -ErrorAction SilentlyContinue) -eq '1' })
        } else {
            (Get-Content -Path "$($battery.FullName)/status" -ErrorAction SilentlyContinue) -ne 'Discharging'
        }
        $capacity = Get-Content -Path "$($battery.FullName)/capacity" -ErrorAction SilentlyContinue
        return [ordered]@{ battery = if ($capacity -match '^\d+$') { [int]$capacity } else { $null }; plugged = $plugged }
    }

    Initialize-SystemMetrics
    $status = New-Object MdmAgent.SystemMetrics+SystemPowerStatus
    # BatteryFlag 128 = no system battery, 255 = unknown.
    if (-not [MdmAgent.SystemMetrics]::GetSystemPowerStatus([ref]$status) -or $status.BatteryFlag -eq 128 -or $status.BatteryFlag -eq 255) {
        return $null
    }

    return [ordered]@{
        battery = if ($status.BatteryLifePercent -le 100) { [int]$status.BatteryLifePercent } else { $null }
        plugged = $status.ACLineStatus -eq 1
    }
}

function Get-LiveState {
    # Small, fast-changing state sent with the heartbeat (the full details go with the report):
    # restart pending, service and container states. Kept cheap, it runs every heartbeat.
    $services = [ordered]@{}
    if ($OnLinux) {
        foreach ($service in @(Get-AgentServices | Sort-Object -Property Name)) { $services[$service.Name] = $service.State }
    } else {
        Add-Type -AssemblyName System.ServiceProcess -ErrorAction SilentlyContinue
        # Start types (one service manager query each) change rarely: cached, only needed for stopped services.
        if (-not $script:StartTypes -or $script:StartTypesAt -lt (Get-Date).AddHours(-1)) {
            $script:StartTypes = @{}
            $script:StartTypesAt = Get-Date
        }
        foreach ($service in @([System.ServiceProcess.ServiceController]::GetServices() | Sort-Object -Property ServiceName)) {
            if ($service.Status -eq 'Running') {
                $services[$service.ServiceName] = 'running'
            } else {
                if (-not $script:StartTypes.ContainsKey($service.ServiceName)) {
                    $script:StartTypes[$service.ServiceName] = "$($service.StartType)"
                }
                if ($script:StartTypes[$service.ServiceName] -eq 'Automatic') { $services[$service.ServiceName] = 'stopped' }
            }
            $service.Dispose()
        }
    }

    $state = [ordered]@{
        restart_required = if ($OnLinux) { Test-Path -Path /var/run/reboot-required } else { [bool](Test-PendingReboot) }
        services         = $services
    }
    $power = Get-PowerStatus
    if ($power) {
        $state['power'] = $power
    }

    if (Test-DockerInstalled) {
        $lines = @(docker ps --all --no-trunc --format '{{.Names}}|{{.State}}|{{.Status}}' 2>$null)
        if ($LASTEXITCODE -eq 0) {
            $containers = [ordered]@{}
            foreach ($line in ($lines | Sort-Object)) {
                $name, $containerState, $status = "$line" -split '\|', 3
                if (-not $name) { continue }
                $containers[$name] = if ($containerState -eq 'running' -and $status -like '*(unhealthy)*') { 'unhealthy' } else { $containerState }
            }
            $state['containers'] = $containers
        }
    }

    return $state
}

function Get-Backlog {
    # Data collected while the server could not be reached (agents 1.11.0+): 'metrics' (CPU and
    # memory samples) and 'pings' (results of the ping-only devices), each with the time it was
    # taken. Kept in backlog-<kind>.json until the server has it, also over a restart.
    param ([string]$Kind)

    if (-not $script:Backlog) { $script:Backlog = @{} }
    if (-not $script:Backlog.ContainsKey($Kind)) {
        $list = New-Object System.Collections.ArrayList
        $path = "$AgentDir/backlog-$Kind.json"
        try {
            if (Test-Path -Path $path) {
                foreach ($item in @(Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json | ForEach-Object { $_ })) { if ($item) { [void]$list.Add($item) } }
            }
        }
        catch { Write-AgentLog "Backlog ${Kind}: $($_.Exception.Message)" -ErrorRecord $_ }
        $script:Backlog[$Kind] = $list
    }
    return , $script:Backlog[$Kind]
}

function Save-Backlog {
    param ([string]$Kind)

    $list = Get-Backlog -Kind $Kind
    $path = "$AgentDir/backlog-$Kind.json"
    try {
        if ($list.Count -eq 0) { Remove-Item -Path $path -Force -ErrorAction SilentlyContinue; return }
        ConvertTo-Json -InputObject @($list) -Depth 4 -Compress | Set-Content -Path $path -Encoding UTF8
    }
    catch { Write-AgentLog "Backlog ${Kind} not saved: $($_.Exception.Message)" -ErrorRecord $_ }
}

function Add-Backlog {
    # Keeps the newest $Max items (a day of samples every 30 s).
    param ([string]$Kind, $Items, [int]$Max)

    $list = Get-Backlog -Kind $Kind
    if ($list.Count -eq 0) { Write-AgentLog "Server not reachable: keeping the $Kind until it is" }
    foreach ($item in @($Items)) { if ($item) { [void]$list.Add($item) } }
    if ($list.Count -gt $Max) { $list.RemoveRange(0, $list.Count - $Max) }
    Save-Backlog -Kind $Kind
}

function Send-Backlog {
    # Sends what was collected while the server could not be reached, in batches, once the server
    # answers again (at most every 20 s, oldest first).
    param ([Parameter(Mandatory = $true)][string]$Token)

    if ($script:BacklogSent -and ((Get-Date) - $script:BacklogSent).TotalSeconds -lt 20) { return }
    $script:BacklogSent = Get-Date
    Send-AgentErrors -Token $Token
    foreach ($kind in 'metrics', 'pings') {
        $list = Get-Backlog -Kind $kind
        if ($list.Count -eq 0) { continue }
        $batch = @($list | Select-Object -First $(if ($kind -eq 'metrics') { 600 } else { 2000 }))
        try {
            $response = if ($kind -eq 'metrics') {
                Invoke-MdmApi -Method Post -Path 'device/metrics/backfill' -Token $Token -Body @{ samples = $batch }
            } else {
                Invoke-MdmApi -Method Post -Path 'device/pings' -Token $Token -Body @{ results = @(); backlog = $batch }
            }
            $list.RemoveRange(0, $batch.Count)
            Save-Backlog -Kind $kind
            $stored = if ($kind -eq 'metrics') { $response.taken } else { $response.backfilled }
            Write-AgentLog "Sent $($batch.Count) $kind collected while the server was not reachable ($stored stored), $($list.Count) left"
        }
        catch {
            if ($_.Exception.Data['MdmStatus'] -in 403, 404) {
                # A server without backfill (or an agent that does not sign): nothing to wait for.
                $list.Clear()
                Save-Backlog -Kind $kind
            }
            Write-AgentLog "Backlog ($kind) not sent: $($_.Exception.Message)" -ErrorRecord $_
        }
    }
}

function Send-Heartbeat {
    param (
        $Realtime,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $metrics = Get-SystemMetrics

    # The live state is sent only when it changed; the server keeps the last one.
    $state = $null
    try {
        $current = Get-LiveState
        $stateJson = $current | ConvertTo-Json -Depth 4 -Compress
        if ($stateJson -ne $script:LastStateJson) { $state = $current }
    }
    catch {
        Write-AgentLog "Live state failed: $($_.Exception.Message)" -ErrorRecord $_
    }
    # Reverb limits messages to 10 kB (with the signature): a large state (many services) goes over HTTPS instead.
    $stateOverWs = $state -and $stateJson.Length -le 7000

    # Kept for later when the server cannot be reached now (see Send-Backlog).
    $sample = if ($metrics) { [ordered]@{ at = Get-UnixTime; cpu = $metrics.cpu; memory_used = $metrics.memory_used; memory_total = $metrics.memory_total } } else { $null }

    if ($Realtime -and $Realtime.Subscribed) {
        # Signed with the device key, for this device, once (nonce).
        $payload = [ordered]@{ device_id = $script:DeviceId; ts = Get-UnixTime; nonce = New-Nonce; metrics = $metrics; state = $(if ($stateOverWs) { $state } else { $null }) } | ConvertTo-Json -Depth 6 -Compress
        $data = @{ p = $payload; sig = New-DeviceSignature -Context 'MDM1-HB' -Message $payload }
        # A failed send throws: the connection is reset and the state is sent again later.
        try {
            Send-WsMessage -Socket $Realtime.Socket -Message @{ event = 'client-heartbeat'; channel = $Realtime.Config.channel; data = $data }
        }
        catch {
            if ($sample) { Add-Backlog -Kind 'metrics' -Items $sample -Max 2880 }
            throw
        }
        Send-Backlog -Token $Token
        if ($stateOverWs) {
            $script:LastStateJson = $stateJson
            return
        }
        $metrics = $null
        $sample = $null
    }
    if (-not $state -and $Realtime -and $Realtime.Subscribed) {
        return
    }

    try {
        Invoke-MdmApi -Method Post -Path 'device/heartbeat' -Token $Token -Body @{ metrics = $metrics; state = $state } | Out-Null
        if ($state) { $script:LastStateJson = $stateJson }
        Send-Backlog -Token $Token
    }
    catch {
        Write-AgentLog "Heartbeat failed: $($_.Exception.Message)" -ErrorRecord $_
        if ($sample -and $null -eq $_.Exception.Data['MdmStatus']) {
            # Not reached at all (an answer with an error would not change by waiting).
            Add-Backlog -Kind 'metrics' -Items $sample -Max 2880
        }
    }
}

function Start-Agent {
    Initialize-AgentErrors
    Initialize-AgentSettings
    # Keep the agent in the background, user applications take precedence.
    try { [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::BelowNormal } catch { }

    $config = Initialize-ServerKey
    Protect-AgentPath -Path $AgentDir -Directory
    $Token = Get-AgentToken
    $script:AgentToken = $Token
    $script:UpdateQueue = New-Object System.Collections.Queue
    $script:UpdateProgressSent = [DateTime]::MinValue
    $pendingChecked = $false
    $nextPendingCheck = Get-Date
    # Read again: an enrolment just now registered the key.
    $script:KeyRegistered = [bool](Get-AgentConfig)['key_registered']
    Write-AgentLog ('Agent {0} started (PowerShell {1}, {2}, server {3})' -f $AgentVersion, $PSVersionTable.PSVersion, $(if ($OnLinux) { 'Linux' } else { 'Windows' }), $ServerUrl)
    $inventory = Get-CachedInventory
    # First inventory a few minutes after start, so it does not add to the load during boot.
    $nextInventory = if ($inventory -and -not $inventory.Stale) { $inventory.CollectedAt.AddSeconds($InventoryInterval) } elseif ($inventory) { (Get-Date).AddMinutes(1) } else { (Get-Date).AddMinutes(5) }
    $inventoryJob = $null
    # Virtual disks have no S.M.A.R.T. values: no disk health on VMs and containers.
    $virtualization = try { if ($OnLinux) { Get-LinuxVirtualization } else { $system = Get-CimInstance -ClassName Win32_ComputerSystem -Property Manufacturer, Model; Get-Virtualization -Vendor $system.Manufacturer -Product $system.Model } } catch { $null }
    $health = if ($virtualization) { $null } else { Get-CachedInventory -Name 'health' }
    $nextHealth = if ($virtualization) { [DateTime]::MaxValue } elseif ($health -and -not $health.Stale) { $health.CollectedAt.AddSeconds($HealthInterval) } else { (Get-Date).AddMinutes(2) }
    if ($virtualization) {
        Write-AgentLog "Running in a $($virtualization.Type) ($($virtualization.Name)), disk health is not collected"
    }
    $healthJob = $null
    # Security inventory (hourly): the first one a few minutes after start, so it does not add to the load during boot.
    $securityState = Get-SecurityState
    $nextSecurity = if ($securityState -and $securityState.SentAt -le (Get-Date)) { $securityState.SentAt.AddSeconds($SecurityInterval) } else { (Get-Date).AddMinutes(3) }
    $securityJob = $null
    $securityStarted = $null
    $lastReport = [DateTime]::MinValue
    $realtime = $null
    $nextConnect = Get-Date
    $lastActivity = Get-Date
    $lastHeartbeat = [DateTime]::MinValue
    $lastPackageCheck = [DateTime]::MinValue

    while ($true) {
        try {
            if (-not $script:KeyRegistered) {
                # Updated from an agent that did not sign, or the key was reset in the portal.
                Register-DeviceKey -Token $Token
            }
            if (-not $pendingChecked -and (Get-Date) -ge $nextPendingCheck) {
                # A restart or agent update of the last run is done now.
                $pendingChecked = Complete-PendingCommand
                if ($pendingChecked) { Restore-CommandState }
                $nextPendingCheck = (Get-Date).AddSeconds(30)
            }

            if (-not $Once -and ((Get-Date) - $lastHeartbeat).TotalSeconds -ge $HeartbeatInterval) {
                $lastHeartbeat = Get-Date
                Send-Heartbeat -Realtime $realtime -Token $Token
                Send-PingResults -Token $Token
                $lastActivity = Get-Date
            }

            if ($inventoryJob -and $inventoryJob.State -ne 'Running') {
                if ($inventoryJob.State -eq 'Completed') {
                    $data = Receive-Job -Job $inventoryJob
                    if ($data.os_updates_error) { Write-AgentLog "OS update check failed: $($data.os_updates_error)" }
                    Write-AgentLog ('Inventory collected: {0} OS, {1} application, {2} module update(s)' -f @($data.os_updates).Count, @($data.packages_updates).Count, @($data.module_updates).Count)
                    Save-CachedInventory -Data $data
                    $inventory = @{ CollectedAt = Get-Date; Data = $data }
                    $lastReport = [DateTime]::MinValue
                    if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'collecting') {
                        $script:SyncRequest.InventoryDone = $true
                        [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status running -Progress 60 -Message 'Inventory collected')
                    }
                } else {
                    Write-AgentLog "Inventory collection failed: $($inventoryJob.ChildJobs[0].JobStateInfo.Reason)" -IsError
                    if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'collecting') {
                        [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status failed -Message "Inventory collection failed: $($inventoryJob.ChildJobs[0].JobStateInfo.Reason)")
                        $script:SyncRequest = $null
                    }
                }
                Remove-Job -Job $inventoryJob -Force
                $inventoryJob = $null
            }
            if ((Sync-NetworkScan) -or $script:ReportSoon) {
                $script:ReportSoon = $false
                $lastReport = [DateTime]::MinValue
            }
            Sync-PortScan
            Sync-UpdateProgress
            if (Complete-UpdateJob) {
                # Show what is left right away instead of the pre-update list for 6 hours.
                $nextInventory = Get-Date
            }
            try {
                if ($script:ScriptsRequested -and -not $script:CurrentScript -and $script:ScriptQueue.Count -eq 0) {
                    Request-ScriptRuns -Token $Token
                }
                Update-ScriptRun -Token $Token
            }
            catch {
                # Script problems must not tear down the WebSocket connection.
                Write-AgentLog "Scripts: $($_.Exception.Message)" -ErrorRecord $_
            }
            if ($OnLinux -and $inventory -and -not $inventoryJob -and ((Get-Date) - $lastPackageCheck).TotalSeconds -ge 60) {
                # Packages installed outside the agent (apt, unattended-upgrades): collect again once
                # dpkg has been quiet for 2 minutes, so the portal does not list installed updates.
                $lastPackageCheck = Get-Date
                $dpkgChanged = (Get-Item -Path /var/lib/dpkg/status -ErrorAction SilentlyContinue).LastWriteTime
                if ($dpkgChanged -gt $inventory.CollectedAt -and $dpkgChanged -lt (Get-Date).AddMinutes(-2) -and $nextInventory -gt (Get-Date)) {
                    Write-AgentLog 'Packages changed, collecting the inventory again'
                    $nextInventory = Get-Date
                }
            }
            if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'requested') {
                # Sync: collect everything now. A collection already running is waited for.
                Write-AgentLog 'Sync requested: collecting the inventory and disk health now'
                $script:SyncRequest.Phase = 'collecting'
                $script:SyncRequest.InventoryDone = $false
                if (-not $inventoryJob) { $nextInventory = Get-Date }
                if (-not $virtualization -and -not $healthJob) { $nextHealth = Get-Date }
                if (-not $securityJob) { $nextSecurity = Get-Date }
            }
            if (-not $inventoryJob -and (Get-Date) -ge $nextInventory) {
                Write-AgentLog 'Inventory collection started'
                $inventoryJob = Start-InventoryCollection
                $nextInventory = (Get-Date).AddSeconds($InventoryInterval)
            }

            if ($healthJob -and $healthJob.State -ne 'Running') {
                if ($healthJob.State -eq 'Completed') {
                    $data = Receive-Job -Job $healthJob | Select-Object -Last 1
                    Save-CachedInventory -Data $data -Name 'health'
                    $health = @{ CollectedAt = Get-Date; Data = $data }
                    $lastReport = [DateTime]::MinValue
                } else {
                    Write-AgentLog "Disk health collection failed: $($healthJob.ChildJobs[0].JobStateInfo.Reason)" -IsError
                }
                Remove-Job -Job $healthJob -Force
                $healthJob = $null
            }
            if (-not $healthJob -and (Get-Date) -ge $nextHealth) {
                $healthJob = Start-HealthCollection -Previous $health.Data
                $nextHealth = (Get-Date).AddSeconds($HealthInterval)
            }

            if ($securityJob -and $securityJob.State -ne 'Running') {
                try {
                    if ($securityJob.State -eq 'Completed') {
                        $data = Receive-Job -Job $securityJob | Select-Object -Last 1
                        [void](Send-SecurityInventory -Data $data -StartedAt $securityStarted -Token $Token)
                    } else {
                        Write-AgentLog "Security inventory failed: $($securityJob.ChildJobs[0].JobStateInfo.Reason)" -IsError
                    }
                }
                catch {
                    # Sent again with the next collection (the events since the last one that was taken).
                    Write-AgentLog "Security inventory not sent: $($_.Exception.Message)" -ErrorRecord $_
                    $nextSecurity = (Get-Date).AddMinutes(10)
                }
                Remove-Job -Job $securityJob -Force
                $securityJob = $null
            }
            if (-not $securityJob -and (Get-Date) -ge $nextSecurity) {
                # Events since the last collection the server took, at most a day back.
                $state = Get-SecurityState
                $since = if ($state -and $state.Since -gt (Get-Date).AddDays(-1)) { $state.Since } else { (Get-Date).AddDays(-1) }
                $securityStarted = Get-Date
                $sendLogs = (Get-SecurityLogsLevel) -eq 'on'
                $policy = if ($sendLogs) { Get-SecurityPolicy -Token $Token } else { $null }
                $securityJob = Start-SecurityCollection -Since $since -InContainer ($virtualization -and $virtualization.Type -eq 'container') -SendLogs ($sendLogs -and $null -ne $policy) -PolicyJson $(if ($policy) { $policy | ConvertTo-Json -Depth 6 -Compress } else { '' }) -CursorsJson ((Get-SecurityCursors) | ConvertTo-Json -Compress)
                $nextSecurity = (Get-Date).AddSeconds($SecurityInterval)
            }

            if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'collecting' -and $script:SyncRequest.InventoryDone -and -not $healthJob) {
                # Collected: the full report goes now.
                $script:SyncRequest.Phase = 'report'
                [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status running -Progress 90 -Message 'Sending the report')
                $lastReport = [DateTime]::MinValue
            }

            if (((Get-Date) - $lastReport).TotalSeconds -ge $ReportInterval) {
                $lastReport = Get-Date
                try {
                    Send-Report -Data (Get-Report -Inventory $inventory.Data -Health $health.Data) -Token $Token
                    Complete-NetworkScan
                    if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'report') {
                        [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status succeeded -Progress 100 -Message 'Synced')
                        $script:SyncRequest = $null
                    }
                }
                catch {
                    # HTTP problems must not tear down the WebSocket connection.
                    Write-AgentLog "Report failed: $($_.Exception.Message)" -ErrorRecord $_
                    Complete-NetworkScan -Failure $_.Exception.Message
                    if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'report') {
                        [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status failed -Message "Report failed: $($_.Exception.Message)")
                        $script:SyncRequest = $null
                    }
                }
                if ($Once) {
                    return
                }
            }

            if (-not $realtime -and (Get-Date) -ge $nextConnect) {
                $realtime = Start-Realtime -Token $Token
                $lastActivity = Get-Date
                if (-not $realtime) {
                    # WebSocket not enabled on the server, rely on HTTP reports only.
                    $nextConnect = (Get-Date).AddMinutes(15)
                }
            }

            if ($realtime) {
                $message = Receive-WsMessage -Realtime $realtime
                if ($message) {
                    $lastActivity = Get-Date
                    Invoke-RealtimeMessage -Realtime $realtime -Message $message -Token $Token
                } elseif (((Get-Date) - $lastActivity).TotalSeconds -ge 60) {
                    Send-WsMessage -Socket $realtime.Socket -Message @{ event = 'pusher:ping'; data = @{} }
                    $lastActivity = Get-Date
                }
            } else {
                Start-Sleep -Seconds 1
            }
        }
        catch {
            Write-AgentLog "Error: $($_.Exception.Message)" -ErrorRecord $_
            if ($realtime) {
                $realtime.Socket.Dispose()
                $realtime = $null
            }
            # Back off before reconnecting.
            $nextConnect = (Get-Date).AddSeconds(15)
            Start-Sleep -Seconds 5
        }
    }
}

#endregion

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if ($env:MDM_AGENT_NO_START) {
    return
}

if (-not $ServerUrl) {
    Write-Host 'The address of the portal is needed: -ServerUrl https://mdm.example.com (see Add device in the portal).' -ForegroundColor Red
    exit 1
}

if ($Install) {
    # Check privileges before anything else, so a failed install does not use up the enrolment code.
    if (-not (Test-AgentAdmin)) {
        $hint = if ($OnLinux) { 'run it with sudo (sudo pwsh ...)' } else { 'run PowerShell as Administrator' }
        Write-Host "The agent installs a system service and needs administrator rights: $hint." -ForegroundColor Red
        Remove-TemporaryInstaller
        exit 1
    }

    # The installer may run from a temporary download, install the agent to a permanent location.
    if (-not $InstallPath) {
        $InstallPath = if ($OnLinux) { '/opt/laravel-mdm' } else { Join-Path $env:ProgramData 'Laravel-MDM' }
    }
    New-Item -ItemType Directory -Force -Path $InstallPath | Out-Null
    $AgentDir = (Resolve-Path -Path $InstallPath).Path

    # An existing installation keeps its token: the run only updates the agent and the service,
    # the device is not enrolled again (an enrolment code is not needed and not used up).
    $existing = @($(if ($OnLinux) { 'token' } else { 'Token.xml', 'token.dat' }) | Where-Object { Test-Path -Path (Join-Path $AgentDir $_) }).Count -gt 0
    if ($existing) {
        Write-Host "Existing installation found in $AgentDir, updating the agent to $AgentVersion." -ForegroundColor Yellow
    }

    $target = Join-Path $AgentDir 'app.ps1'
    if ($PSCommandPath -ne $target) {
        Copy-Item -Path $PSCommandPath -Destination $target -Force
    }

    Protect-AgentPath -Path $AgentDir -Directory
    $config = Initialize-ServerKey
    if ($DisableScripts -or $EnableScripts) {
        $config['scripts_enabled'] = [bool]$EnableScripts
        Save-AgentConfig -Config $config
    }
    if ($NetworkDiscovery) {
        $config['network_discovery'] = $NetworkDiscovery
        Save-AgentConfig -Config $config
    }
    if ($SecurityLogs) {
        $config['security_logs'] = $SecurityLogs
        Save-AgentConfig -Config $config
    }
    if ($PortScan) {
        $config['port_scan'] = $PortScan
        Save-AgentConfig -Config $config
    }
    Save-AgentSettings -Config $config
    Write-Host "Server key: $($config['server_key'].fingerprint)" -ForegroundColor Yellow
    Write-Host "Remediation scripts: $(if ($config['scripts_enabled']) { 'enabled' } else { 'disabled' }) (config.json)" -ForegroundColor Yellow
    Write-Host "Network discovery: $(Get-NetworkDiscovery -Config $config) (config.json)" -ForegroundColor Yellow
    Write-Host "Port scanning: $(Get-PortScan -Config $config) (config.json)" -ForegroundColor Yellow
    Write-Host "Security logs: $(Get-SecurityLogsLevel -Config $config) (config.json)" -ForegroundColor Yellow

    if ($OnLinux) {
        # The service runs as root like this installer: enrol right away.
        Get-AgentToken | Out-Null
        Register-AgentService
    } else {
        # The task runs as SYSTEM, which cannot read what this administrator encrypts: the agent
        # enrols (token, device key) itself on its first start, this only hands over the code or
        # the token of an older installation.
        $userPath = Join-Path $AgentDir 'Token.xml'
        if (Test-Path -Path $userPath) {
            try {
                Set-Content -Path (Join-Path $AgentDir 'token.dat') -Value (Protect-MachineSecret -Text (Read-UserToken -Path $userPath)) -NoNewline
                Protect-AgentPath -Path (Join-Path $AgentDir 'token.dat')
                Remove-Item -Path $userPath -Force
                Write-Host 'The device token of the older installation is handed over to the SYSTEM task.' -ForegroundColor Yellow
            }
            catch {
                # Already written by the SYSTEM task, nothing to hand over.
            }
        } elseif (-not $existing) {
            if (-not $EnrolmentCode) {
                Write-Host 'An enrolment code is needed (-EnrolmentCode, see Add device in the portal).' -ForegroundColor Red
                exit 1
            }
            $config['enrolment_code'] = "$EnrolmentCode"
            Save-AgentConfig -Config $config
        }

        $since = '{0:yyyy-MM-dd HH:mm:ss}' -f (Get-Date)
        Register-AgentTask

        # Wait for the first start of the task to report how it went (log lines of this run only).
        $log = Join-Path $AgentDir 'agent.log'
        $deadline = (Get-Date).AddSeconds(90)
        $started = $false
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Seconds 2
            $lines = @(if (Test-Path -Path $log) { Get-Content -Path $log -Tail 50 | Where-Object { $_.Length -ge 19 -and $_.Substring(0, 19) -ge $since } })
            if ($lines -match 'Agent stopped: ') {
                Write-Host "The agent failed to start: $(($lines -match 'Agent stopped: ') | Select-Object -Last 1)" -ForegroundColor Red
                Remove-TemporaryInstaller
                exit 1
            }
            if ($lines -match "Agent $([regex]::Escape($AgentVersion)) started") {
                $started = $true
                break
            }
        }
        if (-not $started) {
            Write-Host "The agent did not report its start within 90 s, see $log and the task Laravel-MDM-Agent in Task Scheduler." -ForegroundColor Yellow
        }
    }

    Remove-TemporaryInstaller

    $action = if ($existing) { 'updated' } else { 'installed' }
    Write-Host "Laravel-MDM agent $AgentVersion $action in $AgentDir and started." -ForegroundColor Green
    return
}

try {
    Start-Agent
}
catch {
    # Errors before the loop (token, keys, configuration) would end the task without a trace.
    try { Write-AgentLog "Agent stopped: $($_.Exception.Message)" -ErrorRecord $_ } catch { }
    exit 1
}
