# Straighten uploaded civil registry form photos onto clean bond-paper canvases.
# Output: assets/print/forms/{type}-{side}.png (calibration reference)

Add-Type -AssemblyName System.Drawing

$formsDir = Join-Path $PSScriptRoot "..\assets\print\forms" | Resolve-Path
$bondWidth = 850
$bondHeight = [int][Math]::Round($bondWidth * (1024.0 / 616.0))

function Get-Luminance($color) {
    return ($color.R * 0.299 + $color.G * 0.587 + $color.B * 0.114)
}

function Get-PaperBounds($bitmap, [int]$minLum = 190) {
    $minX = $bitmap.Width
    $minY = $bitmap.Height
    $maxX = 0
    $maxY = 0
    $found = $false

    for ($y = 0; $y -lt $bitmap.Height; $y++) {
        for ($x = 0; $x -lt $bitmap.Width; $x++) {
            $lum = Get-Luminance $bitmap.GetPixel($x, $y)
            if ($lum -ge $minLum) {
                $found = $true
                if ($x -lt $minX) { $minX = $x }
                if ($y -lt $minY) { $minY = $y }
                if ($x -gt $maxX) { $maxX = $x }
                if ($y -gt $maxY) { $maxY = $y }
            }
        }
    }

    if (-not $found) {
        return @{ X = 0; Y = 0; Width = $bitmap.Width; Height = $bitmap.Height }
    }

    $pad = 4
    $x1 = [Math]::Max(0, $minX - $pad)
    $y1 = [Math]::Max(0, $minY - $pad)
    $x2 = [Math]::Min($bitmap.Width - 1, $maxX + $pad)
    $y2 = [Math]::Min($bitmap.Height - 1, $maxY + $pad)

    return @{
        X      = $x1
        Y      = $y1
        Width  = ($x2 - $x1 + 1)
        Height = ($y2 - $y1 + 1)
    }
}

function Convert-FormReference {
    param(
        [string]$SourcePath,
        [string]$DestPath
    )

    if (-not (Test-Path $SourcePath)) {
        Write-Warning "Missing source: $SourcePath"
        return
    }

    $src = [System.Drawing.Image]::FromFile($SourcePath)
    $srcBmp = New-Object System.Drawing.Bitmap $src
    $bounds = Get-PaperBounds $srcBmp

    $crop = New-Object System.Drawing.Bitmap $bounds.Width, $bounds.Height
    $gCrop = [System.Drawing.Graphics]::FromImage($crop)
    $gCrop.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $gCrop.DrawImage($srcBmp, 0, 0, (New-Object System.Drawing.Rectangle $bounds.X, $bounds.Y, $bounds.Width, $bounds.Height), [System.Drawing.GraphicsUnit]::Pixel)

    $dest = New-Object System.Drawing.Bitmap $bondWidth, $bondHeight
    $gDest = [System.Drawing.Graphics]::FromImage($dest)
    $gDest.Clear([System.Drawing.Color]::White)
    $gDest.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $gDest.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $gDest.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $gDest.DrawImage($crop, 0, 0, $bondWidth, $bondHeight)

    $dest.Save($DestPath, [System.Drawing.Imaging.ImageFormat]::Png)

    $gCrop.Dispose()
    $gDest.Dispose()
    $crop.Dispose()
    $srcBmp.Dispose()
    $src.Dispose()

    Write-Output "Created $DestPath"
}

$forms = @(
    'birth-front', 'birth-back', 'marriage-front', 'marriage-back', 'death-front', 'death-back'
)

foreach ($name in $forms) {
    $jpg = Join-Path $formsDir "$name.jpg"
    $sourceBackup = Join-Path $formsDir "$name-source.jpg"
    if (-not (Test-Path $sourceBackup) -and (Test-Path $jpg)) {
        Copy-Item $jpg $sourceBackup -Force
    }
    $src = if (Test-Path $sourceBackup) { $sourceBackup } else { $jpg }
    $png = Join-Path $formsDir "$name.png"
    Convert-FormReference -SourcePath $src -DestPath $png
}

Write-Output "Bond canvas: ${bondWidth}x${bondHeight}px (legal long bond ratio)"
