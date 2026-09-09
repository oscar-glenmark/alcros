# Import LCRO certification forms from certifications.pdf
# Page 1 = Death (Form 2A), Page 2 = Marriage (Form 3A), Page 3 = Birth (Form 1A)

Add-Type -AssemblyName System.Drawing

$ErrorActionPreference = 'Stop'
$repoRoot = Join-Path $PSScriptRoot '..' | Resolve-Path
$certDir = Join-Path $repoRoot 'assets\print\forms\certification'
$pdftoppm = 'C:\msys64\ucrt64\bin\pdftoppm.exe'
$sourcePdf = Join-Path $certDir 'certifications.pdf'
$fallbackPdf = Join-Path $env:USERPROFILE 'Downloads\certifications.pdf'
$renderWidth = 1700

if (-not (Test-Path $sourcePdf) -and (Test-Path $fallbackPdf)) {
    New-Item -ItemType Directory -Force -Path $certDir | Out-Null
    Copy-Item $fallbackPdf $sourcePdf -Force
    Write-Output "Copied certifications.pdf from Downloads."
}

if (-not (Test-Path $sourcePdf)) {
    throw "Place certifications.pdf in $certDir (or Downloads\certifications.pdf) and run again."
}

if (-not (Test-Path $pdftoppm)) {
    throw "pdftoppm not found at $pdftoppm"
}

$pageMap = @{
    'death'    = 1
    'marriage' = 2
    'birth'    = 3
}

$tmpPrefix = Join-Path $certDir '_import-page'

foreach ($type in $pageMap.Keys) {
    $page = [int]$pageMap[$type]
    & $pdftoppm -png -scale-to-x $renderWidth -f $page -l $page $sourcePdf $tmpPrefix 2>&1 | Out-Null
    $rendered = "$tmpPrefix-$page.png"
    $dest = Join-Path $certDir "$type.png"
    if (-not (Test-Path $rendered)) {
        throw "Render failed for $type (page $page)."
    }
    Copy-Item $rendered $dest -Force
    Remove-Item $rendered -Force -ErrorAction SilentlyContinue
    Write-Output "Updated $dest from certifications.pdf page $page"
}

Get-ChildItem (Join-Path $certDir '_import-page*.png') -ErrorAction SilentlyContinue | Remove-Item -Force
Get-ChildItem (Join-Path $certDir '_preview*.png') -ErrorAction SilentlyContinue | Remove-Item -Force

Write-Output 'Done. Paper height is derived from PNG aspect in certificationPaperSize(). Refresh Print Calibration (Ctrl+F5).'
