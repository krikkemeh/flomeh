Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. "$PSScriptRoot\\shared-legacy-node.ps1"

$node = Get-LegacyNodeBinary
$npm = Get-LegacyNpmCommand -NodeBinary $node

Write-Host "Using legacy Node: $node"

if ($npm.Type -eq 'cmd') {
  & $npm.Path install
  exit $LASTEXITCODE
}

& $node $npm.Path install
exit $LASTEXITCODE
