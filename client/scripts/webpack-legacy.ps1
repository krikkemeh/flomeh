param(
  [Parameter(Mandatory = $true)]
  [ValidateSet('build', 'dev')]
  [string]$Mode
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. "$PSScriptRoot\\shared-legacy-node.ps1"

$node = Get-LegacyNodeBinary
$webpack = Join-Path $PSScriptRoot '..\\node_modules\\webpack\\bin\\webpack.js'
$webpack = (Resolve-Path $webpack).Path

Write-Host "Using legacy Node: $node"

if ($Mode -eq 'build') {
  $env:NODE_ENV = 'production'
  & $node $webpack --hide-modules
  exit $LASTEXITCODE
}

Remove-Item Env:NODE_ENV -ErrorAction SilentlyContinue
& $node $webpack -w --hide-modules
exit $LASTEXITCODE
