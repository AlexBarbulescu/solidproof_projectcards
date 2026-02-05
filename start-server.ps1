param(
  [string]$HostIP = "127.0.0.1",
  [int]$Port = 8000
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$public = Join-Path $root "public"
$ini = Join-Path $root "config\php.ini"

function Find-PhpExe {
  # 1) If php is already in PATH
  $cmd = Get-Command php -ErrorAction SilentlyContinue
  if ($cmd -and $cmd.Source -and (Test-Path $cmd.Source)) { return $cmd.Source }

  # 2) Common WinGet install location pattern
  $wingetRoot = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages"
  if (Test-Path $wingetRoot) {
    $candidates = Get-ChildItem -Path $wingetRoot -Directory -ErrorAction SilentlyContinue |
      Where-Object { $_.Name -like "PHP.PHP.*" } |
      ForEach-Object {
        $php = Join-Path $_.FullName "php.exe"
        if (Test-Path $php) { $php }
      }
    $first = $candidates | Select-Object -First 1
    if ($first) { return $first }
  }

  throw "php.exe not found. Install PHP or add it to PATH."
}

$phpExe = Find-PhpExe

Write-Host "Using: $phpExe"
Write-Host "Serving: $public"
Write-Host "URL: http://$HostIP`:$Port/"

# Run in this console so you can see errors.
& $phpExe -c $ini -S "$HostIP`:$Port" -t $public
