param(
  [string]$HostIP = "127.0.0.1",
  [int]$Port = 5173
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$frontend = Join-Path $root "frontend"

Push-Location $frontend
try {
  $env:HOST = $HostIP
  $env:PORT = "$Port"
  npm run dev
} finally {
  Pop-Location
}
