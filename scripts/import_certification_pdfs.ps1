# Import LCRO certification forms from CivilRegistryForm.pdf
# Page 1 = Birth (Form 1A), Page 2 = Death (Form 2A), Page 3 = Marriage (Form 3A)

$ErrorActionPreference = 'Stop'
$repoRoot = Join-Path $PSScriptRoot '..' | Resolve-Path
$certDir = Join-Path $repoRoot 'assets\print\forms\certification'
$pdftoppm = 'C:\msys64\ucrt64\bin\pdftoppm.exe'
$sourcePdf = Join-Path $certDir 'CivilRegistryForm.pdf'
$fallbackPdf = Join-Path $env:USERPROFILE 'OneDrive\Documents\CivilRegistryForm.pdf'
$renderWidth = 1700
$a4Height = [int][Math]::Round($renderWidth * 297.0 / 210.0)

if (-not (Test-Path $sourcePdf) -and (Test-Path $fallbackPdf)) {
    New-Item -ItemType Directory -Force -Path $certDir | Out-Null
    Copy-Item $fallbackPdf $sourcePdf -Force
    Write-Output "Copied CivilRegistryForm.pdf from OneDrive Documents."
}

if (-not (Test-Path $sourcePdf)) {
    throw "Place CivilRegistryForm.pdf in $certDir (or OneDrive\Documents\CivilRegistryForm.pdf) and run again."
}

if (-not (Test-Path $pdftoppm)) {
    throw "pdftoppm not found at $pdftoppm"
}

$pageMap = @{
    'birth'    = 1
    'death'    = 2
    'marriage' = 3
}

$tmpPrefix = Join-Path $certDir '_import-page'

foreach ($type in $pageMap.Keys) {
    $page = [int]$pageMap[$type]
    & $pdftoppm -png -scale-to-x $renderWidth -scale-to-y $a4Height -f $page -l $page $sourcePdf $tmpPrefix 2>&1 | Out-Null
    $rendered = "$tmpPrefix-$page.png"
    $dest = Join-Path $certDir "$type.png"
    if (-not (Test-Path $rendered)) {
        throw "Render failed for $type (page $page)."
    }
    Copy-Item $rendered $dest -Force
    Remove-Item $rendered -Force -ErrorAction SilentlyContinue
    Write-Output "Updated $dest from CivilRegistryForm.pdf page $page"
}

Get-ChildItem (Join-Path $certDir '_import-page*.png') -ErrorAction SilentlyContinue | Remove-Item -Force
Get-ChildItem (Join-Path $certDir '_preview*.png') -ErrorAction SilentlyContinue | Remove-Item -Force

Write-Output 'Done. Certification templates use A4 (210 x 297 mm). Refresh Print Calibration (Ctrl+F5).'
