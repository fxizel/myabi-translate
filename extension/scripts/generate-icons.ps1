# Génère assets/icons/icon-{16,32,48,128}.png (loupe sur fond bleu) avec System.Drawing, sans dépendance.
# Usage : powershell -ExecutionPolicy Bypass -File scripts/generate-icons.ps1
Add-Type -AssemblyName System.Drawing

$target = Join-Path (Split-Path -Parent $PSScriptRoot) 'assets\icons'
New-Item -ItemType Directory -Force -Path $target | Out-Null

function New-RoundedPath([single]$x, [single]$y, [single]$size, [single]$radius) {
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $radius * 2
    $path.AddArc($x, $y, $d, $d, 180, 90)
    $path.AddArc($x + $size - $d, $y, $d, $d, 270, 90)
    $path.AddArc($x + $size - $d, $y + $size - $d, $d, $d, 0, 90)
    $path.AddArc($x, $y + $size - $d, $d, $d, 90, 90)
    $path.CloseFigure()
    return $path
}

foreach ($size in 16, 32, 48, 128) {
    $bitmap = New-Object System.Drawing.Bitmap $size, $size
    $g = [System.Drawing.Graphics]::FromImage($bitmap)
    $g.SmoothingMode = 'AntiAlias'
    $g.Clear([System.Drawing.Color]::Transparent)

    $background = New-RoundedPath 0 0 $size ($size * 0.22)
    $g.FillPath((New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 9, 105, 218))), $background)

    $pen = New-Object System.Drawing.Pen ([System.Drawing.Color]::White), ([single]($size * 0.1))
    $pen.StartCap = 'Round'
    $pen.EndCap = 'Round'
    $ring = $size * 0.5
    $g.DrawEllipse($pen, [single]($size * 0.17), [single]($size * 0.17), [single]$ring, [single]$ring)
    $g.DrawLine($pen, [single]($size * 0.62), [single]($size * 0.62), [single]($size * 0.82), [single]($size * 0.82))

    $path = Join-Path $target "icon-$size.png"
    $bitmap.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bitmap.Dispose()
    Write-Host "Wrote $path"
}
