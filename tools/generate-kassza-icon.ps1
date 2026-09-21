# FountainTrade — fountaintrade-kassa.ico generátor.
#
# NEM egy harmadik féltől származó ikoncsomagot használ — ugyanazt a
# glyph-et rajzolja újra GDI+ primitívekkel, amit a FountainTrade UI
# saját "Kassza" oldalsáv-ikonja használ (Feather Icons "shopping-cart",
# MIT licenc — lásd webroot/sidebarmenu.php), a favicon.svg-vel azonos
# szín/stílus-nyelven (sötétzöld #12362a háttér, élénkzöld #22c55e vonal).
#
# Ez a szkript CSAK fejlesztői/build-eszköz — nem szállítjuk az ügyfélnek,
# és nem futtatja az install-windows.ps1. A KIMENETE
# (webroot/assets/fountaintrade-kassa.ico) VAN a repóban/csomagban.

Add-Type -AssemblyName System.Drawing

$outDir = Join-Path $PSScriptRoot '..\webroot\assets'
$masterSize = 256
$sizes = @(16, 24, 32, 48, 64, 128, 256)

function New-KasszaBitmap {
    param([int]$Size)

    $bmp = New-Object System.Drawing.Bitmap($Size, $Size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)

    # Háttér — lekerekített négyzet, ugyanaz az arány/szín, mint a
    # favicon.svg-ben (rx = 7/32 a teljes mérethez képest).
    $bgColor = [System.Drawing.ColorTranslator]::FromHtml('#12362a')
    $radius = [Math]::Round($Size * (7.0 / 32.0))
    $bgPath = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $radius * 2
    $bgPath.AddArc(0, 0, $d, $d, 180, 90)
    $bgPath.AddArc($Size - $d, 0, $d, $d, 270, 90)
    $bgPath.AddArc($Size - $d, $Size - $d, $d, $d, 0, 90)
    $bgPath.AddArc(0, $Size - $d, $d, $d, 90, 90)
    $bgPath.CloseFigure()
    $bgBrush = New-Object System.Drawing.SolidBrush($bgColor)
    $g.FillPath($bgBrush, $bgPath)

    # Kassza glyph — pontosan a FountainTrade UI "Kassza" oldalsáv-ikonja
    # (lásd webroot/sidebarmenu.php, Feather Icons "shopping-cart"),
    # 24x24-es saját koordinátatérből újraszámolva erre a vászonra.
    $scale = $Size * (18.0 / 24.0) / 24.0  # a 24x24 glyph ~18/24-öd rész vászonszélességet foglaljon el
    $offsetX = ($Size - 24 * $scale) / 2.0
    $offsetY = ($Size - 22 * $scale) / 2.0 - (1 * $scale)

    function P([double]$x, [double]$y) {
        return New-Object System.Drawing.PointF((($offsetX + $x * $scale)), (($offsetY + $y * $scale)))
    }

    $fgColor = [System.Drawing.ColorTranslator]::FromHtml('#22c55e')
    $strokeWidth = [Math]::Max(1.5, $Size * (2.4 / 24.0))
    $pen = New-Object System.Drawing.Pen($fgColor, $strokeWidth)
    $pen.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Round
    $pen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $pen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round

    # A kosár körvonala — M1,1 h4 l2.68,13.39 (kis ívek egyenessel
    # közelítve) h9.72 L23,6 H6 Z (a feather path pontos koordinátái).
    $points = @(
        P 1 1
        P 5 1
        P 7.68 14.39
        P 9.68 16
        P 19.4 16
        P 21.4 14.39
        P 23 6
        P 6 6
    )
    $g.DrawLines($pen, $points)

    # Két kerék.
    $wheelR = 1.35 * $scale
    $brush = New-Object System.Drawing.SolidBrush($fgColor)
    $w1 = P 9 21
    $w2 = P 20 21
    $g.FillEllipse($brush, $w1.X - $wheelR, $w1.Y - $wheelR, $wheelR * 2, $wheelR * 2)
    $g.FillEllipse($brush, $w2.X - $wheelR, $w2.Y - $wheelR, $wheelR * 2, $wheelR * 2)

    $pen.Dispose(); $brush.Dispose(); $bgBrush.Dispose(); $bgPath.Dispose(); $g.Dispose()
    return $bmp
}

# --- PNG-k generálása minden mérethez (előnézethez + az ICO forrásának) ---
$pngPaths = @{}
foreach ($size in $sizes) {
    $bmp = New-KasszaBitmap -Size $size
    $pngPath = Join-Path $outDir "kassza-icon-$size.png"
    $bmp.Save($pngPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $pngPaths[$size] = $pngPath
    $bmp.Dispose()
    Write-Host "PNG generálva: $pngPath"
}

# --- Valódi, több-felbontású .ico összeállítása (Vista+ PNG-alapú bejegyzésekkel) ---
function New-MultiResolutionIco {
    param([hashtable]$PngPaths, [int[]]$Sizes, [string]$IcoPath)

    $imageDatas = @()
    foreach ($size in $Sizes) {
        $imageDatas += , [System.IO.File]::ReadAllBytes($PngPaths[$size])
    }

    $ms = New-Object System.IO.MemoryStream
    $bw = New-Object System.IO.BinaryWriter($ms)

    # ICONDIR
    $bw.Write([UInt16]0)      # reserved
    $bw.Write([UInt16]1)      # type = 1 (icon)
    $bw.Write([UInt16]$Sizes.Count)

    $headerSize = 6 + (16 * $Sizes.Count)
    $offset = $headerSize
    for ($i = 0; $i -lt $Sizes.Count; $i++) {
        $size = $Sizes[$i]
        $dataLen = $imageDatas[$i].Length
        $dim = if ($size -ge 256) { 0 } else { $size }  # 256 -> 0 az ICONDIRENTRY-ben
        $bw.Write([byte]$dim)      # width
        $bw.Write([byte]$dim)      # height
        $bw.Write([byte]0)         # color count (0 = nincs paletta, igaz PNG-nél)
        $bw.Write([byte]0)         # reserved
        $bw.Write([UInt16]1)       # color planes
        $bw.Write([UInt16]32)      # bits per pixel
        $bw.Write([UInt32]$dataLen)
        $bw.Write([UInt32]$offset)
        $offset += $dataLen
    }
    foreach ($data in $imageDatas) {
        $bw.Write($data)
    }

    $bw.Flush()
    [System.IO.File]::WriteAllBytes($IcoPath, $ms.ToArray())
    $bw.Dispose(); $ms.Dispose()
}

$icoPath = Join-Path $outDir 'fountaintrade-kassa.ico'
New-MultiResolutionIco -PngPaths $pngPaths -Sizes $sizes -IcoPath $icoPath
Write-Host "ICO generálva: $icoPath ($((Get-Item $icoPath).Length) bájt)"

# A köztes, egyedi méretű PNG-k csak build-melléktermékek — töröljük őket,
# az ICO-ban már minden felbontás benne van.
foreach ($size in $sizes) {
    Remove-Item -LiteralPath $pngPaths[$size] -Force -ErrorAction SilentlyContinue
}
