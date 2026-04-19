<?php
require_once __DIR__ . '/tcpdf/tcpdf.php';

$pdf = new TCPDF();
$pdf->AddPage();
$pdf->Write(0, 'TCPDF is working!');
$pdf->Output('test.pdf', 'I');
