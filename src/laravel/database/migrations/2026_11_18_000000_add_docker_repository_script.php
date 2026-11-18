<?php

use App\Models\Script;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'Docker apt repository';

    /** Exit 0 = apt has a Docker repository for the release (or Docker is not installed), exit 1 = it has none. */
    private const DETECTION = <<<'PS'
# Docker apt repository on Debian / Ubuntu (download.docker.com), the codename of the installed release.
$os = @{}
if (Test-Path -Path /etc/os-release) {
    foreach ($line in Get-Content -Path /etc/os-release) {
        if ($line -match '^([A-Z_]+)=(.*)$') { $os[$Matches[1]] = $Matches[2].Trim('"', "'") }
    }
}
$distro = $os['ID']
$codename = if ($os['UBUNTU_CODENAME']) { $os['UBUNTU_CODENAME'] } else { $os['VERSION_CODENAME'] }
if ($distro -notin 'ubuntu', 'debian' -or -not $codename) {
    Write-Output "Not applicable: $($os['PRETTY_NAME'])"
    exit 0
}
$installed = $false
foreach ($package in 'docker-ce', 'containerd.io') {
    if ((& dpkg-query -W -f='${Status}' $package 2>$null) -match 'install ok installed') { $installed = $true }
}
if (-not $installed) {
    Write-Output 'Docker (docker-ce / containerd.io) is not installed'
    exit 0
}
# The active apt sources that name download.docker.com (apt ignores *.distUpgrade, *.save, *.bak).
$files = @('/etc/apt/sources.list') + @(Get-ChildItem -Path /etc/apt/sources.list.d -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '\.(list|sources)$' } | ForEach-Object FullName)
$docker = @($files | Where-Object { (Test-Path -Path $_) -and ((Get-Content -Path $_ -Raw) -match 'download\.docker\.com') })
$current = @($docker | Where-Object { (Get-Content -Path $_ -Raw) -match "(?m)^\s*(Suites:.*\b$([regex]::Escape($codename))\b|deb\s.*\s$([regex]::Escape($codename))\s)" })
if ($current.Count -gt 0) {
    Write-Output "Docker apt repository for $codename: $($current -join ', ')"
    exit 0
}
if ($docker.Count -gt 0) {
    Write-Output "Docker apt repository does not name the release $codename: $($docker -join ', ')"
} else {
    Write-Output "No Docker apt repository: apt cannot update docker-ce / containerd.io ($distro $codename)"
}
exit 1
PS;

    /** Adds docker.sources with the signing key (inside the script, scripts have no network). */
    private const REMEDIATION = <<<'PS'
# Docker apt repository on Debian / Ubuntu (download.docker.com), the codename of the installed release.
$os = @{}
if (Test-Path -Path /etc/os-release) {
    foreach ($line in Get-Content -Path /etc/os-release) {
        if ($line -match '^([A-Z_]+)=(.*)$') { $os[$Matches[1]] = $Matches[2].Trim('"', "'") }
    }
}
$distro = $os['ID']
$codename = if ($os['UBUNTU_CODENAME']) { $os['UBUNTU_CODENAME'] } else { $os['VERSION_CODENAME'] }
if ($distro -notin 'ubuntu', 'debian' -or -not $codename) {
    Write-Output "Not applicable: $($os['PRETTY_NAME'])"
    exit 0
}
$installed = $false
foreach ($package in 'docker-ce', 'containerd.io') {
    if ((& dpkg-query -W -f='${Status}' $package 2>$null) -match 'install ok installed') { $installed = $true }
}
if (-not $installed) {
    Write-Output 'Docker (docker-ce / containerd.io) is not installed'
    exit 0
}
# The active apt sources that name download.docker.com (apt ignores *.distUpgrade, *.save, *.bak).
$files = @('/etc/apt/sources.list') + @(Get-ChildItem -Path /etc/apt/sources.list.d -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '\.(list|sources)$' } | ForEach-Object FullName)
$docker = @($files | Where-Object { (Test-Path -Path $_) -and ((Get-Content -Path $_ -Raw) -match 'download\.docker\.com') })
$current = @($docker | Where-Object { (Get-Content -Path $_ -Raw) -match "(?m)^\s*(Suites:.*\b$([regex]::Escape($codename))\b|deb\s.*\s$([regex]::Escape($codename))\s)" })
if ($current.Count -gt 0) {
    Write-Output "Docker apt repository for $codename is there: $($current -join ', ')"
    exit 0
}
# The signing key of Docker (9DC8 5822 9FC7 DD38 854A E2D8 8D81 803C 0EBF CD88), inside the script: it runs without network.
$key = @'
-----BEGIN PGP PUBLIC KEY BLOCK-----

mQINBFit2ioBEADhWpZ8/wvZ6hUTiXOwQHXMAlaFHcPH9hAtr4F1y2+OYdbtMuth
lqqwp028AqyY+PRfVMtSYMbjuQuu5byyKR01BbqYhuS3jtqQmljZ/bJvXqnmiVXh
38UuLa+z077PxyxQhu5BbqntTPQMfiyqEiU+BKbq2WmANUKQf+1AmZY/IruOXbnq
L4C1+gJ8vfmXQt99npCaxEjaNRVYfOS8QcixNzHUYnb6emjlANyEVlZzeqo7XKl7
UrwV5inawTSzWNvtjEjj4nJL8NsLwscpLPQUhTQ+7BbQXAwAmeHCUTQIvvWXqw0N
cmhh4HgeQscQHYgOJjjDVfoY5MucvglbIgCqfzAHW9jxmRL4qbMZj+b1XoePEtht
ku4bIQN1X5P07fNWzlgaRL5Z4POXDDZTlIQ/El58j9kp4bnWRCJW0lya+f8ocodo
vZZ+Doi+fy4D5ZGrL4XEcIQP/Lv5uFyf+kQtl/94VFYVJOleAv8W92KdgDkhTcTD
G7c0tIkVEKNUq48b3aQ64NOZQW7fVjfoKwEZdOqPE72Pa45jrZzvUFxSpdiNk2tZ
XYukHjlxxEgBdC/J3cMMNRE1F4NCA3ApfV1Y7/hTeOnmDuDYwr9/obA8t016Yljj
q5rdkywPf4JF8mXUW5eCN1vAFHxeg9ZWemhBtQmGxXnw9M+z6hWwc6ahmwARAQAB
tCtEb2NrZXIgUmVsZWFzZSAoQ0UgZGViKSA8ZG9ja2VyQGRvY2tlci5jb20+iQI3
BBMBCgAhBQJYrefAAhsvBQsJCAcDBRUKCQgLBRYCAwEAAh4BAheAAAoJEI2BgDwO
v82IsskP/iQZo68flDQmNvn8X5XTd6RRaUH33kXYXquT6NkHJciS7E2gTJmqvMqd
tI4mNYHCSEYxI5qrcYV5YqX9P6+Ko+vozo4nseUQLPH/ATQ4qL0Zok+1jkag3Lgk
jonyUf9bwtWxFp05HC3GMHPhhcUSexCxQLQvnFWXD2sWLKivHp2fT8QbRGeZ+d3m
6fqcd5Fu7pxsqm0EUDK5NL+nPIgYhN+auTrhgzhK1CShfGccM/wfRlei9Utz6p9P
XRKIlWnXtT4qNGZNTN0tR+NLG/6Bqd8OYBaFAUcue/w1VW6JQ2VGYZHnZu9S8LMc
FYBa5Ig9PxwGQOgq6RDKDbV+PqTQT5EFMeR1mrjckk4DQJjbxeMZbiNMG5kGECA8
g383P3elhn03WGbEEa4MNc3Z4+7c236QI3xWJfNPdUbXRaAwhy/6rTSFbzwKB0Jm
ebwzQfwjQY6f55MiI/RqDCyuPj3r3jyVRkK86pQKBAJwFHyqj9KaKXMZjfVnowLh
9svIGfNbGHpucATqREvUHuQbNnqkCx8VVhtYkhDb9fEP2xBu5VvHbR+3nfVhMut5
G34Ct5RS7Jt6LIfFdtcn8CaSas/l1HbiGeRgc70X/9aYx/V/CEJv0lIe8gP6uDoW
FPIZ7d6vH+Vro6xuWEGiuMaiznap2KhZmpkgfupyFmplh0s6knymuQINBFit2ioB
EADneL9S9m4vhU3blaRjVUUyJ7b/qTjcSylvCH5XUE6R2k+ckEZjfAMZPLpO+/tF
M2JIJMD4SifKuS3xck9KtZGCufGmcwiLQRzeHF7vJUKrLD5RTkNi23ydvWZgPjtx
Q+DTT1Zcn7BrQFY6FgnRoUVIxwtdw1bMY/89rsFgS5wwuMESd3Q2RYgb7EOFOpnu
w6da7WakWf4IhnF5nsNYGDVaIHzpiqCl+uTbf1epCjrOlIzkZ3Z3Yk5CM/TiFzPk
z2lLz89cpD8U+NtCsfagWWfjd2U3jDapgH+7nQnCEWpROtzaKHG6lA3pXdix5zG8
eRc6/0IbUSWvfjKxLLPfNeCS2pCL3IeEI5nothEEYdQH6szpLog79xB9dVnJyKJb
VfxXnseoYqVrRz2VVbUI5Blwm6B40E3eGVfUQWiux54DspyVMMk41Mx7QJ3iynIa
1N4ZAqVMAEruyXTRTxc9XW0tYhDMA/1GYvz0EmFpm8LzTHA6sFVtPm/ZlNCX6P1X
zJwrv7DSQKD6GGlBQUX+OeEJ8tTkkf8QTJSPUdh8P8YxDFS5EOGAvhhpMBYD42kQ
pqXjEC+XcycTvGI7impgv9PDY1RCC1zkBjKPa120rNhv/hkVk/YhuGoajoHyy4h7
ZQopdcMtpN2dgmhEegny9JCSwxfQmQ0zK0g7m6SHiKMwjwARAQABiQQ+BBgBCAAJ
BQJYrdoqAhsCAikJEI2BgDwOv82IwV0gBBkBCAAGBQJYrdoqAAoJEH6gqcPyc/zY
1WAP/2wJ+R0gE6qsce3rjaIz58PJmc8goKrir5hnElWhPgbq7cYIsW5qiFyLhkdp
YcMmhD9mRiPpQn6Ya2w3e3B8zfIVKipbMBnke/ytZ9M7qHmDCcjoiSmwEXN3wKYI
mD9VHONsl/CG1rU9Isw1jtB5g1YxuBA7M/m36XN6x2u+NtNMDB9P56yc4gfsZVES
KA9v+yY2/l45L8d/WUkUi0YXomn6hyBGI7JrBLq0CX37GEYP6O9rrKipfz73XfO7
JIGzOKZlljb/D9RX/g7nRbCn+3EtH7xnk+TK/50euEKw8SMUg147sJTcpQmv6UzZ
cM4JgL0HbHVCojV4C/plELwMddALOFeYQzTif6sMRPf+3DSj8frbInjChC3yOLy0
6br92KFom17EIj2CAcoeq7UPhi2oouYBwPxh5ytdehJkoo+sN7RIWua6P2WSmon5
U888cSylXC0+ADFdgLX9K2zrDVYUG1vo8CX0vzxFBaHwN6Px26fhIT1/hYUHQR1z
VfNDcyQmXqkOnZvvoMfz/Q0s9BhFJ/zU6AgQbIZE/hm1spsfgvtsD1frZfygXJ9f
irP+MSAI80xHSf91qSRZOj4Pl3ZJNbq4yYxv0b1pkMqeGdjdCYhLU+LZ4wbQmpCk
SVe2prlLureigXtmZfkqevRz7FrIZiu9ky8wnCAPwC7/zmS18rgP/17bOtL4/iIz
QhxAAoAMWVrGyJivSkjhSGx1uCojsWfsTAm11P7jsruIL61ZzMUVE2aM3Pmj5G+W
9AcZ58Em+1WsVnAXdUR//bMmhyr8wL/G1YO1V3JEJTRdxsSxdYa4deGBBY/Adpsw
24jxhOJR+lsJpqIUeb999+R8euDhRHG9eFO7DRu6weatUJ6suupoDTRWtr/4yGqe
dKxV3qQhNLSnaAzqW/1nA3iUB4k7kCaKZxhdhDbClf9P37qaRW467BLCVO/coL3y
Vm50dwdrNtKpMBh3ZpbB1uJvgi9mXtyBOMJ3v8RZeDzFiG8HdCtg9RvIt/AIFoHR
H3S+U79NT6i0KPzLImDfs8T7RlpyuMc4Ufs8ggyg9v3Ae6cN3eQyxcK3w0cbBwsh
/nQNfsA6uu+9H7NhbehBMhYnpNZyrHzCmzyXkauwRAqoCbGCNykTRwsur9gS41TQ
M8ssD1jFheOJf3hODnkKU+HKjvMROl1DK7zdmLdNzA1cvtZH/nCC9KPj1z8QC47S
xx+dTZSx4ONAhwbS/LN3PoKtn8LPjY9NP9uDWI+TWYquS2U+KHDrBDlsgozDbs/O
jCxcpDzNmXpWQHEtHU7649OXHP7UeNST1mCUCH5qdank0V1iejF6/CfTFU4MfcrG
YT90qFF93M3v01BbxP+EIY2/9tiIPbrd
=0YYh
-----END PGP PUBLIC KEY BLOCK-----
'@
New-Item -ItemType Directory -Path /etc/apt/keyrings -Force | Out-Null
[System.IO.File]::WriteAllText('/etc/apt/keyrings/docker.asc', ($key.Trim() + "`n"))
& chmod 0644 /etc/apt/keyrings/docker.asc
# A Docker source for another release is kept next to it (apt ignores *.bak), not deleted.
foreach ($file in $docker) {
    Move-Item -Path $file -Destination "$file.bak" -Force
    Write-Output "Moved $file to $file.bak"
}
$source = "Types: deb`nURIs: https://download.docker.com/linux/$distro`nSuites: $codename`nComponents: stable`nSigned-By: /etc/apt/keyrings/docker.asc`n"
[System.IO.File]::WriteAllText('/etc/apt/sources.list.d/docker.sources', $source)
# Written only when it reads back.
if ((Get-Content -Path /etc/apt/sources.list.d/docker.sources -Raw) -notmatch "Suites: $([regex]::Escape($codename))") {
    Write-Output 'docker.sources did not read back'
    exit 1
}
Write-Output "Docker apt repository for $distro $codename added (/etc/apt/sources.list.d/docker.sources). The script has no network: the next apt update of the agent reads it, then update docker-ce and containerd.io (sudo apt update && sudo apt install --only-upgrade containerd.io docker-ce docker-ce-cli docker-buildx-plugin docker-compose-plugin; it restarts Docker and its containers)."
exit 0
PS;

    /**
     * A remediation script for "Package without a source of updates" of docker-ce / containerd.io, the Docker
     * repository that is lost in an upgrade of the distribution. Manual remediation: a run only detects, an admin
     * starts Remediate per device. The script cannot run apt (no network): the agent's next apt update reads the new source.
     */
    public function up(): void
    {
        if (DB::table('scripts')->where('name', self::NAME)->exists()) {
            return;
        }
        $now = now();
        DB::table('scripts')->insert([
            'name' => self::NAME,
            'description' => 'Adds the apt repository of Docker (download.docker.com) for the installed release of Debian / Ubuntu when Docker is installed and no source names it, as after an upgrade of the distribution, so that apt updates docker-ce and containerd.io again. It only detects, Remediate writes /etc/apt/sources.list.d/docker.sources and the signing key (an old Docker source is kept as .bak). The script has no network: the next apt update of the agent reads the source, then update the packages.',
            'platform' => 'linux',
            'detection' => self::DETECTION,
            'remediation' => self::REMEDIATION,
            'timeout' => 120,
            'version' => 1,
            'fingerprint' => Script::fingerprintOf('linux', 120, self::DETECTION, self::REMEDIATION),
            'manual_remediation' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('scripts')->where('name', self::NAME)->where('version', 1)->delete();
    }
};
