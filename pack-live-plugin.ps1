param(
    [string]$Root = $PSScriptRoot,
    [string]$OutFile = (Join-Path $PSScriptRoot 'dist\woo-product-syncer-live.zip')
)

$ErrorActionPreference = 'Stop'

$rootPath = (Resolve-Path $Root).Path
$distDir = Join-Path $rootPath 'dist'
$stageRoot = Join-Path $env:TEMP ('wpsyncer-live-' + [guid]::NewGuid().ToString('N'))
$stagePlugin = Join-Path $stageRoot 'woo-product-syncer'
$stageIncludes = Join-Path $stagePlugin 'includes'

New-Item -ItemType Directory -Force -Path $stageIncludes | Out-Null
New-Item -ItemType Directory -Force -Path $distDir | Out-Null

try {
    Copy-Item -Path (Join-Path $rootPath 'woo-product-syncer.php') -Destination $stagePlugin

    foreach ($file in @('readme.md', 'uninstall.php')) {
        $source = Join-Path $rootPath $file
        if (Test-Path $source) {
            Copy-Item -Path $source -Destination $stagePlugin
        }
    }

    Get-ChildItem -Path (Join-Path $rootPath 'includes') -Filter '*.php' | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $stageIncludes
    }

    if (Test-Path $OutFile) {
        Remove-Item -Path $OutFile -Force
    }

    Compress-Archive -Path $stagePlugin -DestinationPath $OutFile -Force
    Write-Host "Created archive: $OutFile"
} finally {
    if (Test-Path $stageRoot) {
        Remove-Item -Path $stageRoot -Recurse -Force
    }
}
