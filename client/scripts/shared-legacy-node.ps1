function Get-LegacyNodeBinary {
  $candidates = @()

  if ($env:FLOMEH_NODE_LEGACY) {
    $candidates += $env:FLOMEH_NODE_LEGACY
  }

  $candidates += @(
    'D:\Temp\node12\node-v12.22.12-win-x64\node-v12.22.12-win-x64\node.exe',
    'D:\Temp\node14\node-v14.21.3-win-x64\node-v14.21.3-win-x64\node.exe'
  )

  foreach ($candidate in $candidates) {
    if ($candidate -and (Test-Path $candidate)) {
      return (Resolve-Path $candidate).Path
    }
  }

  throw "Node legacy non trovato. Imposta FLOMEH_NODE_LEGACY oppure installa Node 12/14."
}

function Get-LegacyNpmCommand {
  param(
    [Parameter(Mandatory = $true)]
    [string]$NodeBinary
  )

  $nodeDir = Split-Path -Parent $NodeBinary
  $npmCmd = Join-Path $nodeDir 'npm.cmd'
  if (Test-Path $npmCmd) {
    return @{
      Type = 'cmd'
      Path = $npmCmd
    }
  }

  $npmCli = Join-Path $nodeDir 'node_modules\npm\bin\npm-cli.js'
  if (Test-Path $npmCli) {
    return @{
      Type = 'cli'
      Path = $npmCli
    }
  }

  throw "npm legacy non trovato accanto a $NodeBinary"
}
