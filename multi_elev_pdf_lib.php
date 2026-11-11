<?php
/**
 * Draws the Multi-Activate elevation profile block into an FPDF page (plain vector lines, no images).
 * Data comes from multi_elev_trips() in multi_elev_lib.php. $imperial: true for miles and feet.
 * $text converts a string for the PDF font (pdftext in multi_activate_pdf.php). Starts a new page when needed.
 */
function multi_elev_pdf_block($pdf, array $trips, $page_w, $imperial, callable $text) {
    if (!$trips) return;
    $dK = $imperial ? 0.000621371 : 0.001; $eK = $imperial ? 3.28084 : 1;
    $dU = $imperial ? 'mi' : 'km'; $eU = $imperial ? 'ft' : 'm';
    $hex = function ($h) { $h = ltrim($h, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; };
    $tint = fn($c, $a) => [(int)round(255 - (255 - $c[0]) * $a), (int)round(255 - (255 - $c[1]) * $a), (int)round(255 - (255 - $c[2]) * $a)];

    $chart_h = 1.7; $axis_l = 0.5; $x0 = 0.6;
    if ($pdf->GetY() + 0.5 + $chart_h + 0.6 > 10.3) $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor(28, 27, 25);
    $pdf->Cell(0, 0.28, 'Elevation Profile', 0, 1);

    foreach ($trips as $trip) {
        if ($pdf->GetY() + 0.3 + $chart_h + 0.55 > 10.3) $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(74, 72, 68);
        $pdf->Cell(0, 0.2, $text($trip['title']), 0, 1);
        $top = $pdf->GetY() + 0.12; $base = $top + $chart_h; $w = $page_w - $axis_l;
        $lo = INF; $hi = -INF;
        foreach ($trip['segments'] as $sg) foreach ($sg['pts'] as $p) { $lo = min($lo, $p[1]); $hi = max($hi, $p[1]); }
        $pad = max(10, ($hi - $lo) * 0.08); $lo -= $pad; $hi += $pad;
        $total = max(1.0, $trip['total_m']);
        $X = fn($d) => $x0 + $axis_l + $d / $total * $w;
        $Y = fn($e) => $base - ($e - $lo) / ($hi - $lo) * $chart_h;

        // Grid and elevation labels
        $pdf->SetFont('Helvetica', '', 7);
        for ($i = 0; $i <= 4; $i++) {
            $e = $lo + ($hi - $lo) * $i / 4; $y = $Y($e);
            $pdf->SetDrawColor(229, 226, 218); $pdf->SetLineWidth(0.005);
            $pdf->Line($x0 + $axis_l, $y, $x0 + $axis_l + $w, $y);
            $pdf->SetTextColor(140, 138, 134);
            $pdf->SetXY($x0, $y - 0.05);
            $pdf->Cell($axis_l - 0.06, 0.1, number_format($e * $eK), 0, 0, 'R');
        }
        // Distance labels
        $ticks = 5;
        for ($i = 0; $i <= $ticks; $i++) {
            $d = $total * $i / $ticks;
            $pdf->SetXY($X($d) - 0.4, $base + 0.04);
            $pdf->Cell(0.8, 0.12, number_format($d * $dK, $d * $dK < 10 ? 1 : 0) . ' ' . $dU, 0, 0, 'C');
        }
        $pdf->SetXY($x0, $top - 0.17); $pdf->Cell(0.5, 0.12, $eU, 0, 0, 'L');

        // Segments: tinted fill, then the line (dashed when estimated)
        foreach ($trip['segments'] as $sg) {
            $c = $hex($sg['color']); $fill = $tint($c, 0.18);
            $pts = $sg['pts']; $n = count($pts);
            $xa = $X($pts[0][0]); $xb = $X($pts[$n - 1][0]);
            $yAt = function ($x) use ($pts, $n, $X, $Y) {
                for ($k = 1; $k < $n; $k++) {
                    $xk0 = $X($pts[$k - 1][0]); $xk1 = $X($pts[$k][0]);
                    if ($x <= $xk1) {
                        $f = $xk1 > $xk0 ? ($x - $xk0) / ($xk1 - $xk0) : 0;
                        return $Y($pts[$k - 1][1] + ($pts[$k][1] - $pts[$k - 1][1]) * $f);
                    }
                }
                return $Y($pts[$n - 1][1]);
            };
            $pdf->SetDrawColor($fill[0], $fill[1], $fill[2]); $pdf->SetLineWidth(0.032);
            for ($x = $xa; $x <= $xb; $x += 0.03) $pdf->Line($x, $yAt($x), $x, $base);
            $pdf->SetDrawColor($c[0], $c[1], $c[2]); $pdf->SetLineWidth(0.02);
            if (empty($sg['est'])) {
                for ($k = 1; $k < $n; $k++) $pdf->Line($X($pts[$k - 1][0]), $Y($pts[$k - 1][1]), $X($pts[$k][0]), $Y($pts[$k][1]));
            } else {
                for ($x = $xa; $x < $xb; $x += 0.03) {
                    if (((int)floor(($x - $xa) / 0.08)) % 2 === 0) $pdf->Line($x, $yAt($x), min($x + 0.03, $xb), $yAt(min($x + 0.03, $xb)));
                }
            }
        }
        // Summit markers
        foreach ($trip['segments'] as $sg) {
            if ($sg['summit'] === null) continue;
            $last = end($sg['pts']); $mx = $X($last[0]); $my = $Y($last[1]);
            $pdf->SetFillColor(28, 27, 25); $pdf->Rect($mx - 0.035, $my - 0.035, 0.07, 0.07, 'F');
            $pdf->SetFont('Helvetica', 'B', 7.5); $pdf->SetTextColor(28, 27, 25);
            $pdf->SetXY($mx - 0.3, $my - 0.19); $pdf->Cell(0.6, 0.12, '#' . ($sg['summit'] + 1), 0, 0, 'C');
        }
        // Legend
        $lx = $x0; $ly = $base + 0.22;
        $pdf->SetFont('Helvetica', '', 7.5);
        foreach ($trip['segments'] as $sg) {
            $c = $hex($sg['color']); $lab = $text($sg['label']); $lw = $pdf->GetStringWidth($lab) + 0.3;
            if ($lx + $lw > $x0 + $page_w) { $lx = $x0; $ly += 0.17; }
            $pdf->SetFillColor($c[0], $c[1], $c[2]); $pdf->Rect($lx, $ly + 0.035, 0.12, 0.07, 'F');
            $pdf->SetTextColor(74, 72, 68); $pdf->SetXY($lx + 0.16, $ly); $pdf->Cell($lw - 0.16, 0.14, $lab, 0, 0);
            $lx += $lw + 0.1;
        }
        $pdf->SetXY($x0, $ly + 0.28);
    }
    $pdf->SetFont('Helvetica', 'I', 7.5); $pdf->SetTextColor(140, 138, 134);
    $pdf->Cell(0, 0.16, $text('Dashed lines are estimated from map elevation data; solid lines come from recorded GPX tracks.'), 0, 1);
    $pdf->SetTextColor(28, 27, 25); $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.01);
    $pdf->Ln(0.1);
}
