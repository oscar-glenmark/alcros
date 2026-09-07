# Import marriage certificate forms from assets/print/forms/official/marriages.pdf
Add-Type -AssemblyName System.Drawing

$ErrorActionPreference = 'Stop'
$repoRoot = Join-Path $PSScriptRoot '..' | Resolve-Path
$officialDir = Join-Path $repoRoot 'assets\print\forms\official'
$formsDir = Join-Path $repoRoot 'assets\print\forms'
$pdftoppm = 'C:\msys64\ucrt64\bin\pdftoppm.exe'
$pdfPath = Join-Path $officialDir 'marriages.pdf'
$bondWidth = 1700
$bondHeight = [int][Math]::Round($bondWidth * (1024.0 / 616.0))

function Enhance-FormClarity {
    param([System.Drawing.Bitmap]$Bitmap)

    $enhanced = New-Object System.Drawing.Bitmap $Bitmap.Width, $Bitmap.Height
    $g = [System.Drawing.Graphics]::FromImage($enhanced)
    $g.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic

    $attributes = New-Object System.Drawing.Imaging.ImageAttributes
    $matrix = New-Object System.Drawing.Imaging.ColorMatrix
    $matrix.Matrix00 = 1.12
    $matrix.Matrix11 = 1.12
    $matrix.Matrix22 = 1.12
    $matrix.Matrix33 = 1
    $matrix.Matrix40 = -0.06
    $matrix.Matrix41 = -0.06
    $matrix.Matrix42 = -0.06
    $attributes.SetColorMatrix($matrix)

    $rect = New-Object System.Drawing.Rectangle 0, 0, $Bitmap.Width, $Bitmap.Height
    $g.DrawImage($Bitmap, $rect, 0, 0, $Bitmap.Width, $Bitmap.Height, [System.Drawing.GraphicsUnit]::Pixel, $attributes)
    $g.Dispose()
    return $enhanced
}

function Convert-PageToBond {
    param([string]$SourcePng, [string]$DestPng)

    $srcBmp = [System.Drawing.Bitmap]::FromFile($SourcePng)
    $dest = New-Object System.Drawing.Bitmap $bondWidth, $bondHeight
    $g = [System.Drawing.Graphics]::FromImage($dest)
    $g.Clear([System.Drawing.Color]::White)
    $g.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::None
    $g.DrawImage($srcBmp, 0, 0, $bondWidth, $bondHeight)

    $clear = Enhance-FormClarity -Bitmap $dest
    $dest.Dispose()
    $srcBmp.Dispose()

    $encoder = [System.Drawing.Imaging.EncoderParameters]::new(1)
    $encoder.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter ([System.Drawing.Imaging.Encoder]::Quality, 100L)
    $codec = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object { $_.MimeType -eq 'image/png' } | Select-Object -First 1
    if ($codec) {
        $clear.Save($DestPng, $codec, $encoder)
    } else {
        $clear.Save($DestPng, [System.Drawing.Imaging.ImageFormat]::Png)
    }
    $clear.Dispose()
}

if (-not (Test-Path $pdftoppm)) {
    throw "pdftoppm not found at $pdftoppm"
}
if (-not (Test-Path $pdfPath)) {
    throw "Missing marriages.pdf at $pdfPath"
}

$tmpPrefix = Join-Path $officialDir 'marriages-page'
Get-ChildItem "$tmpPrefix-*.png" -ErrorAction SilentlyContinue | Remove-Item -Force

$pdftoppmCmd = "`"$pdftoppm`" -png -scale-to-x $bondWidth -f 1 -l 2 `"$pdfPath`" `"$tmpPrefix`""
cmd.exe /c "$pdftoppmCmd 2>nul"

foreach ($side in @('front', 'back')) {
    $page = if ($side -eq 'front') { 1 } else { 2 }
    $rendered = Join-Path $officialDir "marriages-page-$page.png"
    $dest = Join-Path $formsDir "marriage-$side.png"

    if (-not (Test-Path $rendered)) {
        throw "Render failed: $rendered"
    }

    Convert-PageToBond -SourcePng $rendered -DestPng $dest
    Remove-Item $rendered -Force
    Write-Output "Updated $dest from marriages.pdf page $page (${bondWidth}px)"
}

Write-Output "Bond canvas: ${bondWidth}x${bondHeight}px"
