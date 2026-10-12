<?php

namespace App\Modules\OfficialDocuments\Support;

use App\Modules\OfficialDocuments\Pdf\TemplateInspector;
use Mpdf\Mpdf;

/**
 * Stand-in forms for development and tests ONLY, until the institute's
 * real PDF files are supplied (docs/OFFICIAL_PDF_TEMPLATES.md). Every page
 * says so in red. They are not models of any real institute form.
 */
class SyntheticTemplates
{
    public const BANNER = 'SYNTHETIC SAMPLE - NOT AN INSTITUTE FORM';

    private const PT = 25.4 / 72;

    /** A two-page, non-fillable leave form; returns a mapping for the "leave" workflow. */
    public static function leave(string $path): array
    {
        $pdf = self::start();
        $pdf->AddPage();
        self::banner($pdf);
        self::label($pdf, 'APPLICATION FOR LEAVE', 50, 52, 16, true);
        $rows = [['1. Name of applicant', 110], ['2. Designation', 140], ['3. Department', 170], ['4. Kind of leave', 200]];
        foreach ($rows as [$text, $y]) {
            self::label($pdf, $text, 50, $y);
            self::box($pdf, 230, $y - 6, 315, 18);
        }
        self::label($pdf, '5. From', 50, 230);
        self::box($pdf, 230, 224, 120, 18);
        self::label($pdf, 'To', 370, 230);
        self::box($pdf, 400, 224, 145, 18);
        self::label($pdf, '6. Reason', 50, 260);
        self::box($pdf, 230, 254, 315, 60);
        self::label($pdf, '7. Will leave headquarters', 50, 330);
        self::box($pdf, 230, 326, 14, 14);
        self::label($pdf, '8. Application number', 50, 360);
        self::box($pdf, 230, 354, 200, 18);
        self::label($pdf, 'Signature of applicant', 380, 760, 9);

        $pdf->AddPage();
        self::banner($pdf);
        self::label($pdf, 'FOR OFFICE USE', 50, 52, 14, true);
        foreach ([['Sanctioned by', 120], ['Designation', 150], ['Date of sanction', 180]] as [$text, $y]) {
            self::label($pdf, $text, 50, $y);
            self::box($pdf, 230, $y - 6, 315, 18);
        }
        self::label($pdf, 'Remarks', 50, 210);
        self::box($pdf, 230, 204, 315, 40);
        self::label($pdf, 'Record of approval', 50, 260);
        self::box($pdf, 230, 254, 315, 30);
        self::label($pdf, 'Signature of sanctioning authority', 50, 320);
        self::box($pdf, 230, 300, 200, 50); // left empty: signed by hand if the office requires it
        $pdf->Output($path, 'F');

        // $extra leads: with `+` the left-hand keys win.
        $f = fn (string $key, string $label, int $page, string $source, array $box, array $extra = []) => $extra + ['key' => $key, 'label' => $label, 'page' => $page, 'type' => 'text', 'source' => $source,
            'x' => $box[0], 'y' => $box[1], 'w' => $box[2], 'h' => $box[3], 'font' => 'sans', 'font_size' => 10, 'min_font_size' => 7, 'align' => 'L', 'overflow' => 'shrink', 'format' => ['kind' => 'auto']];

        return [
            $f('name', 'Name of applicant', 1, 'applicant.name', [230, 104, 315, 18], ['required' => true]),
            $f('designation', 'Designation', 1, 'applicant.designation', [230, 134, 315, 18]),
            $f('department', 'Department', 1, 'applicant.department', [230, 164, 315, 18]),
            $f('leave_type', 'Kind of leave', 1, 'form_label.leave_type', [230, 194, 315, 18], ['required' => true]),
            $f('from_date', 'From', 1, 'form.from_date', [230, 224, 120, 18], ['format' => ['kind' => 'date'], 'required' => true]),
            $f('to_date', 'To', 1, 'form.to_date', [400, 224, 145, 18], ['format' => ['kind' => 'date'], 'required' => true]),
            $f('reason', 'Reason', 1, 'form.reason', [230, 254, 315, 60], ['type' => 'multiline', 'required' => true]),
            $f('station', 'Leaves headquarters', 1, 'form.leave_station', [230, 326, 14, 14], ['type' => 'checkbox', 'checkbox' => ['mark' => '✓']]),
            $f('reference', 'Application number', 1, 'workflow.reference', [230, 354, 200, 18], ['font' => 'mono', 'required' => true]),
            $f('sanctioned_by', 'Sanctioned by', 2, 'approval.sanction.name', [230, 114, 315, 18], ['required' => true]),
            $f('sanction_designation', 'Designation of sanctioning officer', 2, 'approval.sanction.designation', [230, 144, 315, 18]),
            $f('sanction_date', 'Date of sanction', 2, 'approval.sanction.date', [230, 174, 315, 18], ['format' => ['kind' => 'date']]),
            $f('sanction_remarks', 'Remarks', 2, 'approval.sanction.remarks', [230, 204, 315, 40], ['type' => 'multiline']),
            $f('approval_record', 'Record of approval', 2, 'approval.sanction.statement', [230, 254, 315, 30], ['type' => 'multiline', 'font_size' => 8, 'min_font_size' => 6]),
        ];
    }

    /** A one-page form with interactive (AcroForm) fields, as many institute PDFs are. */
    public static function fillable(string $path): void
    {
        $pdf = self::start();
        $pdf->useActiveForms = true;
        $pdf->WriteHTML('<p style="color:#b00;font-weight:bold">'.self::BANNER.'</p><h2>Certificate request</h2>'
            .'<p>Name: <input type="text" name="applicant_name" size="40" /></p>'
            .'<p>Purpose: <input type="text" name="purpose" size="50" /></p>'
            .'<p>Urgent <input type="checkbox" name="urgent" value="yes" /></p>');
        $pdf->Output($path, 'F');
    }

    private static function start(): Mpdf
    {
        $pdf = new Mpdf(['format' => 'A4', 'tempDir' => TemplateInspector::tempDir(), 'margin_top' => 15, 'margin_bottom' => 15]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Synthetic sample form');

        return $pdf;
    }

    private static function banner(Mpdf $pdf): void
    {
        $pdf->WriteFixedPosHTML('<div style="color:#b00000;font-weight:bold;font-size:9pt;text-align:center">'.self::BANNER.'</div>', 20, 8, 170, 6, 'visible');
    }

    private static function label(Mpdf $pdf, string $text, float $x, float $y, float $size = 10, bool $bold = false): void
    {
        $pdf->WriteFixedPosHTML('<div style="font-family:dejavuserif;font-size:'.$size.'pt;'.($bold ? 'font-weight:bold;' : '').'white-space:nowrap">'.htmlspecialchars($text).'</div>', $x * self::PT, ($y - $size) * self::PT, 180, $size * 1.4 * self::PT, 'visible');
    }

    private static function box(Mpdf $pdf, float $x, float $y, float $w, float $h): void
    {
        $pdf->SetDrawColor(120, 120, 120);
        $pdf->SetLineWidth(0.2);
        $pdf->Rect($x * self::PT, $y * self::PT, $w * self::PT, $h * self::PT);
    }
}
