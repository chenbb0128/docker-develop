param([string] $Distribution = '')

$ErrorActionPreference = 'Stop'
$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))

try {
    if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
        throw 'WSL is not installed. Install a WSL2 Linux distribution and enable Docker Desktop WSL Integration first.'
    }

    $listing = @(& wsl.exe --list --verbose 2>&1)
    if ($LASTEXITCODE -ne 0) { throw ($listing -join "`n") }
    $distributions = @(
        foreach ($line in $listing) {
            if ($null -eq $line) { continue }
            $text = $line.ToString().Replace([string][char]0, '')
            if ($text -match '^\s*\*?\s*(?<name>\S+)\s+.+\s+2\s*$') {
                $name = $Matches['name']
                if ($name -notlike 'docker-desktop*') { $name }
            }
        }
    )

    if ($Distribution) {
        if ($distributions -notcontains $Distribution) {
            throw "Not a usable WSL2 distribution: $Distribution. Available: $($distributions -join ', ')"
        }
    } elseif ($distributions -contains 'Ubuntu') {
        $Distribution = 'Ubuntu'
    } elseif ($distributions.Count -gt 0) {
        $Distribution = $distributions[0]
    } else {
        throw 'No user WSL2 distribution found. Install Ubuntu (WSL2), then enable Docker Desktop > Settings > Resources > WSL Integration.'
    }

    Write-Host "Starting Docker Develop in WSL2: $Distribution"
    & wsl.exe --distribution $Distribution --cd $root --exec bash ./start-panel.sh
    exit $LASTEXITCODE
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
