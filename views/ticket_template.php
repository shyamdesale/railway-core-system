<?php
$d = $data ?? [];

function e($value, $fallback = 'N/A')
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        $text = $fallback;
    }

    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function money($value, $fallback = '0.00')
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    if (is_numeric($value)) {
        return number_format((float)$value, 2, '.', '');
    }

    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$passengers = $d['passengers'] ?? [];
if (!is_array($passengers) || $passengers === []) {
    $passengers = [[
        'name' => $d['name'] ?? 'Passenger Name',
        'age' => $d['age'] ?? '28',
        'gender' => $d['gender'] ?? 'M',
        'booking_status' => $d['booking_status'] ?? ($d['status'] ?? 'CNF'),
        'current_status' => $d['current_status'] ?? ($d['status'] ?? 'CNF'),
        'coach' => $d['coach'] ?? 'S3',
        'berth' => $d['seat'] ?? '41',
        'berth_type' => $d['berth_type'] ?? 'LB',
        'food' => $d['food'] ?? 'No',
    ]];
}

$fare = isset($d['fare']) ? (float)$d['fare'] : (isset($d['price']) ? (float)$d['price'] : 845.00);
$convFee = isset($d['convenience_fee']) ? (float)$d['convenience_fee'] : 11.80;
$insurance = isset($d['insurance']) ? (float)$d['insurance'] : 0.45;
$otherCharges = isset($d['other_charges']) ? (float)$d['other_charges'] : 0.00;
$totalFare = isset($d['total_fare']) ? (float)$d['total_fare'] : ($fare + $convFee + $insurance + $otherCharges);

$logo = '';
$logoCandidates = [
    __DIR__ . '/assets/logo.png',
    __DIR__ . '/../assets/logo.png',
    __DIR__ . '/../assets/image.png',
];

foreach ($logoCandidates as $path) {
    if (is_file($path)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        $logo = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Railway Reservation Slip</title>
    <style>
        @page {
            margin: 18px 20px 18px 20px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.4px;
            line-height: 1.3;
            color: #111111;
            background: #ffffff;
        }

        .page {
            position: relative;
            border: 1px solid #222222;
            padding: 10px 10px 12px;
        }

        .watermark {
            position: absolute;
            top: 42%;
            left: 12%;
            width: 76%;
            text-align: center;
            font-size: 42px;
            font-weight: bold;
            color: #999999;
            opacity: 0.08;
            transform: rotate(-18deg);
            letter-spacing: 5px;
        }

        .top-strip {
            border: 1px solid #222222;
            padding: 4px 6px;
            margin-bottom: 6px;
            font-size: 9px;
        }

        .top-strip table,
        .meta-table,
        .grid-table,
        .fare-table,
        .passenger-table,
        .contacts-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-box {
            border: 1px solid #222222;
            margin-bottom: 6px;
        }

        .header-table td {
            vertical-align: top;
            padding: 6px 7px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td + td {
            border-left: 1px solid #222222;
        }

        .brand-cell {
            width: 24%;
            text-align: center;
        }

        .brand-logo {
            max-width: 110px;
            max-height: 48px;
            margin-bottom: 4px;
        }

        .brand-fallback {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 0.8px;
            margin-top: 8px;
        }

        .brand-sub {
            font-size: 9px;
        }

        .title-cell {
            width: 52%;
            text-align: center;
        }

        .title-main {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 0.2px;
            margin-top: 2px;
        }

        .title-sub {
            font-size: 10px;
            margin-top: 3px;
        }

        .title-caption {
            font-size: 9px;
            margin-top: 5px;
            padding-top: 4px;
            border-top: 1px solid #222222;
        }

        .status-cell {
            width: 24%;
            font-size: 9px;
        }

        .status-pill {
            display: inline-block;
            border: 1px solid #222222;
            padding: 2px 7px;
            font-weight: bold;
            margin-top: 4px;
            margin-bottom: 6px;
        }

        .section {
            border: 1px solid #222222;
            margin-bottom: 6px;
        }

        .section-title {
            background: #efefef;
            border-bottom: 1px solid #222222;
            padding: 4px 6px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .section-body {
            padding: 0;
        }

        td,
        th {
            border: 1px solid #222222;
            padding: 4px 5px;
            vertical-align: top;
        }

        th {
            background: #f7f7f7;
            font-weight: bold;
        }

        .no-border td,
        .no-border th {
            border: none;
            padding: 0;
        }

        .label {
            width: 18%;
            font-weight: bold;
            background: #fbfbfb;
        }

        .value {
            width: 32%;
        }

        .tight td,
        .tight th {
            padding-top: 3px;
            padding-bottom: 3px;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .small {
            font-size: 8.8px;
        }

        .x-small {
            font-size: 8px;
        }

        .bold {
            font-weight: bold;
        }

        .note-box {
            border: 1px solid #222222;
            padding: 6px 7px;
            margin-bottom: 6px;
            font-size: 8.8px;
        }

        .note-box p {
            margin: 0 0 4px;
        }

        .note-box p:last-child {
            margin-bottom: 0;
        }

        .instruction-list {
            margin: 0;
            padding: 7px 10px 8px 22px;
            font-size: 8.7px;
        }

        .instruction-list li {
            margin-bottom: 3px;
        }

        .footer-strip {
            border: 1px solid #222222;
            padding: 5px 6px;
            font-size: 8.2px;
        }
    </style>
</head>
<body>
    <div class="page">

        <div class="top-strip">
            <table class="no-border">
                <tr>
                    <td class="small">Transaction ID: <span class="bold"><?= e($d['transaction_id'] ?? 'TXN' . date('YmdHis')) ?></span></td>
                    <td class="small center">Booking Date: <span class="bold"><?= e($d['booking_date'] ?? date('d-M-Y H:i')) ?></span></td>
                    <td class="small right">Reservation Type: <span class="bold"><?= e($d['reservation_type'] ?? 'E-Ticket') ?></span></td>
                </tr>
            </table>
        </div>

        <div class="header-box">
            <table class="header-table">
                <tr>
                    <td class="brand-cell">
                        <?php if ($logo): ?>
                            <img src="<?= $logo ?>" alt="Logo" class="brand-logo">
                        <?php else: ?>
                            <div class="brand-fallback">RAIL CONNECT</div>
                        <?php endif; ?>
                        <div class="brand-sub">Passenger Reservation Document</div>
                    </td>
                    <td class="title-cell">
                        <div class="title-main">Electronic Reservation Slip</div>
                        <div class="title-sub">Railway Journey Details, Passenger Manifest and Fare Summary</div>
                        <div class="title-caption">Computer Generated Travel Record for Presentation During Journey</div>
                    </td>
                    <td class="status-cell">
                        <div>PNR Number</div>
                        <div class="status-pill"><?= e($d['pnr'] ?? '2457813690') ?></div>
                        <div>Chart Status: <span class="bold"><?= e($d['chart_status'] ?? 'Prepared') ?></span></div>
                        <div>Booking Status: <span class="bold"><?= e($d['status'] ?? 'Confirmed') ?></span></div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Journey And Train Details</div>
            <div class="section-body">
                <table class="grid-table tight">
                    <tr>
                        <td class="label">Train No. / Name</td>
                        <td class="value"><?= e($d['train_no'] ?? '12951') ?> / <?= e($d['train'] ?? 'Sample Superfast Express') ?></td>
                        <td class="label">Date Of Journey</td>
                        <td class="value"><?= e($d['date'] ?? date('d-M-Y')) ?></td>
                    </tr>
                    <tr>
                        <td class="label">From</td>
                        <td class="value"><?= e($d['source'] ?? 'Mumbai Central (MMCT)') ?></td>
                        <td class="label">To</td>
                        <td class="value"><?= e($d['destination'] ?? 'New Delhi (NDLS)') ?></td>
                    </tr>
                    <tr>
                        <td class="label">Departure</td>
                        <td class="value"><?= e($d['departure'] ?? '16:35') ?></td>
                        <td class="label">Arrival</td>
                        <td class="value"><?= e($d['arrival'] ?? '08:10') ?></td>
                    </tr>
                    <tr>
                        <td class="label">Boarding Point</td>
                        <td class="value"><?= e($d['boarding_point'] ?? ($d['source'] ?? 'Mumbai Central')) ?></td>
                        <td class="label">Reservation Upto</td>
                        <td class="value"><?= e($d['reservation_upto'] ?? ($d['destination'] ?? 'New Delhi')) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Class</td>
                        <td class="value"><?= e($d['class'] ?? 'Sleeper (SL)') ?></td>
                        <td class="label">Quota</td>
                        <td class="value"><?= e($d['quota'] ?? 'General') ?></td>
                    </tr>
                    <tr>
                        <td class="label">Coach Position</td>
                        <td class="value"><?= e($d['coach_position'] ?? 'As per chart / platform display') ?></td>
                        <td class="label">Distance</td>
                        <td class="value"><?= e($d['distance'] ?? '1384 km') ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Passenger Details</div>
            <div class="section-body">
                <table class="passenger-table tight">
                    <tr class="center">
                        <th style="width: 6%;">S.No.</th>
                        <th style="width: 24%;">Passenger Name</th>
                        <th style="width: 7%;">Age</th>
                        <th style="width: 8%;">Gender</th>
                        <th style="width: 17%;">Booking Status</th>
                        <th style="width: 17%;">Current Status</th>
                        <th style="width: 8%;">Coach</th>
                        <th style="width: 7%;">Berth</th>
                        <th style="width: 6%;">Type</th>
                    </tr>
                    <?php foreach ($passengers as $index => $passenger): ?>
                        <tr class="center">
                            <td><?= $index + 1 ?></td>
                            <td style="text-align: left;"><?= e($passenger['name'] ?? 'Passenger') ?></td>
                            <td><?= e($passenger['age'] ?? '--') ?></td>
                            <td><?= e($passenger['gender'] ?? '--') ?></td>
                            <td><?= e($passenger['booking_status'] ?? 'CNF') ?></td>
                            <td><?= e($passenger['current_status'] ?? 'CNF') ?></td>
                            <td><?= e($passenger['coach'] ?? 'S3') ?></td>
                            <td><?= e($passenger['berth'] ?? '41') ?></td>
                            <td><?= e($passenger['berth_type'] ?? 'LB') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Contact, ID And Additional Details</div>
            <div class="section-body">
                <table class="contacts-table tight">
                    <tr>
                        <td class="label">Passenger Mobile</td>
                        <td class="value"><?= e($d['mobile'] ?? '98XXXXXX54') ?></td>
                        <td class="label">Email</td>
                        <td class="value"><?= e($d['email'] ?? 'sample@example.com') ?></td>
                    </tr>
                    <tr>
                        <td class="label">ID Card Type</td>
                        <td class="value"><?= e($d['id_type'] ?? 'Aadhaar Card') ?></td>
                        <td class="label">ID Card Number</td>
                        <td class="value"><?= e($d['id_number'] ?? 'XXXX-XXXX-4587') ?></td>
                    </tr>
                    <tr>
                        <td class="label">Booked By</td>
                        <td class="value"><?= e($d['booked_by'] ?? 'Normal User') ?></td>
                        <td class="label">Payment Mode</td>
                        <td class="value"><?= e($d['payment_mode'] ?? 'UPI / Net Banking / Card') ?></td>
                    </tr>
                    <tr>
                        <td class="label">Travel Insurance</td>
                        <td class="value"><?= e($d['insurance_status'] ?? 'Opted') ?></td>
                        <td class="label">Meal Preference</td>
                        <td class="value"><?= e($d['meal_preference'] ?? 'Not Included') ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Fare Details</div>
            <div class="section-body">
                <table class="fare-table tight">
                    <tr>
                        <td style="width: 70%;">Basic Ticket Fare</td>
                        <td class="right" style="width: 30%;">Rs. <?= money($fare) ?></td>
                    </tr>
                    <tr>
                        <td>Convenience Fee</td>
                        <td class="right">Rs. <?= money($convFee) ?></td>
                    </tr>
                    <tr>
                        <td>Travel Insurance Charge</td>
                        <td class="right">Rs. <?= money($insurance) ?></td>
                    </tr>
                    <tr>
                        <td>Other Charges</td>
                        <td class="right">Rs. <?= money($otherCharges) ?></td>
                    </tr>
                    <tr>
                        <th>Total Fare Paid</th>
                        <th class="right">Rs. <?= money($totalFare) ?></th>
                    </tr>
                </table>
            </div>
        </div>

        <div class="note-box">
            <p><span class="bold">Important:</span> This template is for sample, demo, testing or internal software output use. Replace all labels and branding only where you have legal rights to do so.</p>
            <p><span class="bold">Travel Note:</span> Passenger should carry a valid original ID proof during journey and verify platform / coach position before boarding.</p>
            <p><span class="bold">Security Advice:</span> Never share OTP, booking password, bank card details or UPI PIN with any caller, agent or unknown person.</p>
        </div>

        <div class="section">
            <div class="section-title">Instructions For Passenger</div>
            <ol class="instruction-list">
                <li>Carry the booking reference and one valid original photo identity proof during the complete journey.</li>
                <li>Only the passenger whose name appears on the reservation record should travel against the allotted berth or seat.</li>
                <li>Boarding point, coach position, platform details and chart preparation status should be checked again near departure time.</li>
                <li>Waitlisted or partially confirmed travel conditions must be interpreted as per the applicable booking and boarding rules of the issuing system.</li>
                <li>Any correction in passenger name, age, gender, class or journey date should be handled through the authorised booking workflow only.</li>
                <li>Refund, cancellation, rescheduling, no-show handling and service charge deduction depend on the policy applicable to the booking channel.</li>
                <li>Luggage, prohibited items, onboard conduct, safety practices and station access rules should be followed throughout the trip.</li>
                <li>For premium or long-distance sectors, passengers should reach the station sufficiently before departure to avoid last-minute rush.</li>
                <li>Meal service, linen, insurance cover, berth preference and quota benefits may vary by route, class, operator and operational conditions.</li>
                <li>This document may be stored digitally or printed, but all travel decisions should be taken only after cross-checking live journey information.</li>
                <li>Helpline numbers, customer care contact points and escalation channels should be sourced from the operator's official website or app only.</li>
                <li>If this layout is used in software development, keep a visible sample indicator until all legal, branding and compliance approvals are complete.</li>
            </ol>
        </div>

        <div class="footer-strip">
            <table class="no-border">
                <tr>
                    <td class="x-small">Customer Support: <?= e($d['support'] ?? '139 / 1800-000-000') ?></td>
                    <td class="x-small center">Website: <?= e($d['website'] ?? 'www.example-rail-portal.test') ?></td>
                    <td class="x-small right">Generated On: <?= e($d['generated_at'] ?? date('d-M-Y H:i:s')) ?></td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
