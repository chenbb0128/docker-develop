$ErrorActionPreference = 'Stop'
$launcher = Join-Path $PSScriptRoot '../start-panel-wsl.ps1'

# Shadow wsl.exe so these tests never start a real distribution or container.
function wsl.exe {
    if ($args[0] -eq '--list') {
        $global:LASTEXITCODE = 0
        return $global:WSLTestListing
    }
    $global:WSLTestLaunchArgs = @($args)
    $global:LASTEXITCODE = $global:WSLTestLaunchExitCode
}

function Assert-Equal($Actual, $Expected) {
    if ($Actual -ne $Expected) { throw "Got <$Actual>, expected <$Expected>" }
}

$global:WSLTestListing = @(
    '  NAME                   STATE           VERSION',
    '* docker-desktop         Running         2',
    '  Debian                 Stopped         2',
    '  Ubuntu                 Stopped         2',
    '  OldLinux               Stopped         1'
)
$global:WSLTestLaunchExitCode = 0
$global:WSLTestLaunchArgs = @()
& $launcher
Assert-Equal $LASTEXITCODE 0
Assert-Equal $global:WSLTestLaunchArgs[1] 'Ubuntu'
Assert-Equal $global:WSLTestLaunchArgs[2] '--cd'
Assert-Equal $global:WSLTestLaunchArgs[4] '--exec'
Assert-Equal $global:WSLTestLaunchArgs[5] 'bash'
Assert-Equal $global:WSLTestLaunchArgs[6] './start-panel.sh'

& $launcher -Distribution Debian
Assert-Equal $LASTEXITCODE 0
Assert-Equal $global:WSLTestLaunchArgs[1] 'Debian'

$global:WSLTestLaunchArgs = @()
& $launcher -Distribution OldLinux
Assert-Equal $LASTEXITCODE 1
Assert-Equal $global:WSLTestLaunchArgs.Count 0

$global:WSLTestLaunchExitCode = 7
& $launcher
Assert-Equal $LASTEXITCODE 7

$global:WSLTestListing = @('* docker-desktop Running 2', ' Debian Stopped 2')
$global:WSLTestLaunchExitCode = 0
& $launcher
Assert-Equal $LASTEXITCODE 0
Assert-Equal $global:WSLTestLaunchArgs[1] 'Debian'

$global:WSLTestListing = @('* docker-desktop Running 2', ' OldLinux Stopped 1')
$global:WSLTestLaunchArgs = @()
& $launcher
Assert-Equal $LASTEXITCODE 1
Assert-Equal $global:WSLTestLaunchArgs.Count 0

Write-Host 'PASS: WSL2 selection, explicit distribution, WSL1 rejection, exit codes, no user distribution'
