<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Travel Order</title>
    <style>
        @page { margin: 22px 28px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; }
        .title { text-align: center; font-weight: 700; letter-spacing: 0.5px; margin: 6px 0 10px; }
        .row { width: 100%; }
        .right { text-align: right; }
        .muted { color: #444; }
        .box { border: 1px solid #111; padding: 8px 10px; }
        table.form { width: 100%; border-collapse: collapse; }
        table.form td { border: 1px solid #111; padding: 6px 8px; vertical-align: top; }
        .label { width: 22%; font-weight: 600; }
        .value { font-weight: 700; }
        .small { font-size: 11px; }
        .sig { height: 54px; }
        .sigline { border-top: 1px solid #111; margin-top: 40px; }
        .section-title { font-weight: 700; text-align: center; margin: 12px 0 6px; }
        .ctt-text { font-size: 11px; line-height: 1.35; text-align: justify; }
        .na { font-weight: 700; }
    </style>
</head>
<body>
@php
    $p = $travelOrder->personnel;
    $fullName = trim(collect([$p->first_name ?? '', $p->middle_name ?? '', $p->last_name ?? ''])->filter()->implode(' '));
    $position = $p->position ?? 'N/A';
    $officialStation = $travelOrder->official_station ?: 'N/A';
    $departureDate = $travelOrder->start_date ? \Carbon\Carbon::parse($travelOrder->start_date)->format('F d, Y') : 'N/A';
    $returnDate = $travelOrder->end_date ? \Carbon\Carbon::parse($travelOrder->end_date)->format('F d, Y') : 'N/A';
    $destination = $travelOrder->destination ?: 'N/A';
    $purpose = $travelOrder->travel_purpose ?: 'N/A';
    $objectives = $travelOrder->objectives ?: 'N/A';
    $perDiems = $travelOrder->per_diems_note ?: ($travelOrder->per_diems_expenses !== null ? number_format((float)$travelOrder->per_diems_expenses, 2) : 'N/A');
    $assistantAllowed = $travelOrder->assistant_or_laborers_allowed ?: 'N/A';
    $appropriation = $travelOrder->appropriation ?: 'N/A';
    $remarks = $travelOrder->remarks ?: 'N/A';

    $reco = $travelOrder->approvals->firstWhere('step_order', 1);
    $appr = $travelOrder->approvals->firstWhere('step_order', 2);
    
    // CRITICAL: DomPDF requires GD extension for image processing (even base64)
    $gdEnabled = extension_loaded('gd');
    
    // Only embed signatures if GD is available (otherwise DomPDF will crash)
    $recoSigDataUri = null;
    if ($gdEnabled && $reco && $reco->director && $reco->director->signature_path) {
        $absPath = storage_path('app/public/' . $reco->director->signature_path);
        if (file_exists($absPath) && is_readable($absPath)) {
            $imageData = file_get_contents($absPath);
            if ($imageData) {
                $mimeType = mime_content_type($absPath);
                if (!$mimeType) {
                    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
                    $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
                    $mimeType = $mimeMap[$ext] ?? 'image/png';
                }
                $recoSigDataUri = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            }
        }
    }

    $apprSigDataUri = null;
    if ($gdEnabled && $appr && $appr->director && $appr->director->signature_path) {
        $absPath = storage_path('app/public/' . $appr->director->signature_path);
        if (file_exists($absPath) && is_readable($absPath)) {
            $imageData = file_get_contents($absPath);
            if ($imageData) {
                $mimeType = mime_content_type($absPath);
                if (!$mimeType) {
                    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
                    $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
                    $mimeType = $mimeMap[$ext] ?? 'image/png';
                }
                $apprSigDataUri = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            }
        }
    }
@endphp

<div class="title">TRAVEL ORDER</div>

<table class="form">
    <tr>
        <td colspan="2">
            <span class="label">Name:</span>
            <span class="value">{{ $fullName ?: 'N/A' }}</span>
        </td>
        <td style="width: 34%;">
            <div class="right small">
                <div><span class="label">No:</span> __________________</div>
                <div><span class="label">Date:</span> <span class="value">{{ $generatedAt->format('F d, Y') }}</span></div>
            </div>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <span class="label">Position/Designation:</span>
            <span class="value">{{ $position }}</span>
        </td>
        <td>
            <span class="label">Official Station:</span>
            <span class="value">{{ $officialStation }}</span>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <span class="label">Departure Date:</span>
            <span class="value">{{ $departureDate }}</span>
        </td>
        <td>
            <span class="label">Return Date:</span>
            <span class="value">{{ $returnDate }}</span>
        </td>
    </tr>
    <tr>
        <td class="label">Destination:</td>
        <td colspan="2" class="value">{{ $destination }}</td>
    </tr>
    <tr>
        <td class="label">Purpose:</td>
        <td colspan="2" class="value">{{ $purpose }}</td>
    </tr>
    <tr>
        <td class="label">Objectives:</td>
        <td colspan="2" class="value">{{ $objectives }}</td>
    </tr>
    <tr>
        <td class="label">Per Diems Expenses Allowed:</td>
        <td colspan="2" class="value">{{ $perDiems }}</td>
    </tr>
    <tr>
        <td class="label">Assistant or Laborers Allowed:</td>
        <td colspan="2" class="value">{{ $assistantAllowed }}</td>
    </tr>
    <tr>
        <td class="label">Appropriation to which travel should be charged:</td>
        <td colspan="2" class="value">{{ $appropriation }}</td>
    </tr>
    <tr>
        <td class="label">Remarks or Special Instructions:</td>
        <td colspan="2" class="value">{{ $remarks }}</td>
    </tr>
    <tr>
        <td colspan="2" class="sig">
            <div class="small muted">RECOMMENDING APPROVAL:</div>
            @if($recoSigDataUri && $reco && $reco->director && in_array($reco->status, ['recommended', 'approved'], true))
                <div style="height:40px; display:flex; align-items:flex-end; justify-content:center; margin-top:4px;">
                    <img src="{{ $recoSigDataUri }}" alt="Recommending signature" style="max-height:36px; max-width:160px; object-fit: contain;">
                </div>
            @else
                <div class="sigline"></div>
            @endif
            <div class="small" style="margin-top:4px;">
                @if($reco && $reco->director)
                    <span class="value">{{ trim(collect([$reco->director->first_name, $reco->director->middle_name, $reco->director->last_name])->filter()->implode(' ')) }}</span>
                @else
                    <span class="na">N/A</span>
                @endif
            </div>
        </td>
        <td class="sig">
            <div class="small muted">APPROVED:</div>
            @if($apprSigDataUri && $appr && $appr->director && $appr->status === 'approved')
                <div style="height:40px; display:flex; align-items:flex-end; justify-content:center; margin-top:4px;">
                    <img src="{{ $apprSigDataUri }}" alt="Approving signature" style="max-height:36px; max-width:160px; object-fit: contain;">
                </div>
            @else
                <div class="sigline"></div>
            @endif
            <div class="small" style="margin-top:4px;">
                @if($appr && $appr->director)
                    <span class="value">{{ trim(collect([$appr->director->first_name, $appr->director->middle_name, $appr->director->last_name])->filter()->implode(' ')) }}</span>
                @else
                    <span class="na">N/A</span>
                @endif
            </div>
        </td>
    </tr>
</table>

@if($includeCtt)
    <div class="section-title">CERTIFICATION TO TRAVEL</div>
    <div class="ctt-text">
        This is to certify that <span class="value">{{ $fullName ?: 'N/A' }}</span>,
        <span class="value">{{ $position }}</span>, is allowed to go on an official travel on
        <span class="value">{{ $departureDate }}</span> to <span class="value">{{ $returnDate }}</span>
        with the following purpose:
        <br><br>
        <span class="value">{{ $purpose }}</span>
        <br><br>
        a. The official mission/task cannot be performed by / or assigned to any other regular/permanent official and/or employee of agency.
        <br>
        b. The tasks/activities are necessary to fulfill the obligations as contained in his/her contract of service.
        <br><br>
        Endorsed by: ________________________________
        <br><br>
        Certified by: ________________________________
    </div>
@endif

</body>
</html>

