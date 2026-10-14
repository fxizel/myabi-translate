param(
    [string]$Ref = 'HEAD',
    [string]$Config = '.runtime/vps-deploy.json',
    [string]$Python = 'python',
    [switch]$Plan,
    [string]$Recover
)

$ErrorActionPreference = 'Stop'
$taskRepo = Split-Path -Parent $PSScriptRoot
$taskArguments = @((Join-Path $PSScriptRoot 'deploy-vps.py'), '--config', $Config, '--ref', $Ref)
if ($Plan) { $taskArguments += '--plan' }
if ($Recover) { $taskArguments += @('--recover', $Recover) }
Push-Location -LiteralPath $taskRepo
try {
    & $Python @taskArguments
    $taskExitCode = $LASTEXITCODE
}
finally {
    Pop-Location
}
exit $taskExitCode
