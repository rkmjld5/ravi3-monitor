<?php
/*
    ECG HISTORY PAGE
    Project: ravi2_patient

    Hospital-style single-channel ECG rhythm strip:
    - 10-second strip when 2,500 samples are stored at 250 Hz.
    - 25 mm/second horizontal time scale.
    - ECG grid: 1 mm small squares and 5 mm bold squares.
    - Signal amplitude is deliberately marked UNCALIBRATED.
    - Horizontally scrollable display; no squeezing of the 250 mm strip.
    - Keeps patient/device authorization, date search, latest 20 records,
      PNG, PDF and CSV downloads.

    This page only reads records. It does not alter database records.
*/

require_once "patient_access.php";

date_default_timezone_set("Asia/Kolkata");

$patientId = $_SESSION["ravi2_patient_id"];
$patientName = $_SESSION["ravi2_patient_name"];
$deviceId = $_SESSION["ravi2_device_id"];

// --------------------------------------------------
// DATE AND TIME SEARCH
// --------------------------------------------------

$startInput = trim($_GET["start_datetime"] ?? "");
$endInput   = trim($_GET["end_datetime"] ?? "");

$startDateTime = "1000-01-01 00:00:00";
$endDateTime   = "9999-12-31 23:59:59";
$errorMessage = "";

function convertDateTime($value)
{
    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat("!Y-m-d\TH:i", $value);

    if (!$date || $date->format("Y-m-d\TH:i") !== $value) {
        return false;
    }

    return $date->format("Y-m-d H:i:s");
}

if ($startInput !== "") {
    $converted = convertDateTime($startInput);
    if ($converted === false) {
        $errorMessage = "Please enter a valid start date and time.";
    } else {
        $startDateTime = $converted;
    }
}

if ($endInput !== "" && $errorMessage === "") {
    $converted = convertDateTime($endInput);
    if ($converted === false) {
        $errorMessage = "Please enter a valid end date and time.";
    } else {
        $endDateTime = $converted;
    }
}

if ($errorMessage === "" && $startDateTime > $endDateTime) {
    $errorMessage = "Start date and time cannot be later than end date and time.";
}

// --------------------------------------------------
// GET ECG RECORDS
// --------------------------------------------------

$records = [];

if ($errorMessage === "") {
    $sql = "
        SELECT
            id,
            samples_json,
            mode,
            recorded_at,
            sample_rate,
            sample_count
        FROM ecg_readings
        WHERE patient_id = ?
          AND device_id = ?
          AND recorded_at >= ?
          AND recorded_at <= ?
        ORDER BY recorded_at DESC, id DESC
        LIMIT 20
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        $errorMessage = "Unable to prepare the ECG history query.";
    } else {
        mysqli_stmt_bind_param(
            $stmt,
            "ssss",
            $patientId,
            $deviceId,
            $startDateTime,
            $endDateTime
        );

        if (!mysqli_stmt_execute($stmt)) {
            $errorMessage = "Unable to retrieve ECG records.";
        } else {
            mysqli_stmt_bind_result(
                $stmt,
                $id,
                $samplesJson,
                $mode,
                $recordedAt,
                $sampleRate,
                $sampleCount
            );

            while (mysqli_stmt_fetch($stmt)) {
                $records[] = [
                    "id" => $id,
                    "samples_json" => $samplesJson,
                    "mode" => $mode,
                    "recorded_at" => $recordedAt,
                    "sample_rate" => $sampleRate,
                    "sample_count" => $sampleCount
                ];
            }
        }

        mysqli_stmt_close($stmt);
    }
}

// --------------------------------------------------
// READ STORED SAMPLE ARRAY
// --------------------------------------------------

function getEcgSamples($json)
{
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [];
    }

    if (isset($data["ecg_samples"]) && is_array($data["ecg_samples"])) {
        $data = $data["ecg_samples"];
    } elseif (isset($data["samples"]) && is_array($data["samples"])) {
        $data = $data["samples"];
    } elseif (isset($data["data"]) && is_array($data["data"])) {
        $data = $data["data"];
    }

    $samples = [];

    foreach ($data as $value) {
        if (is_numeric($value) && is_finite((float)$value)) {
            $samples[] = (float)$value;
        }
    }

    return $samples;
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ECG Rhythm Strip History</title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<style>
* { box-sizing: border-box; }

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #eef2f6;
    margin: 0;
    padding: 16px;
    color: #202a35;
}

.container {
    max-width: 1250px;
    margin: 0 auto;
}

.header, .search-box, .record {
    background: #fff;
    border: 1px solid #d8e0e8;
    border-radius: 9px;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: 0 2px 7px rgba(20, 40, 60, .05);
}

.header h2, .record h3 {
    color: #174a75;
    margin-top: 0;
}

.patient-details, .record-details {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 18px;
}

.patient-details p { margin: 4px 0; }

.navigation { margin-top: 14px; }

.navigation a {
    display: inline-block;
    margin: 4px 7px 4px 0;
    padding: 9px 14px;
    background: #174a75;
    color: #fff;
    text-decoration: none;
    border-radius: 5px;
}

.navigation a:hover { background: #103653; }

.search-box h3 { margin-top: 0; }

.search-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.search-field {
    flex: 1;
    min-width: 220px;
}

.search-field label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
}

.search-field input {
    width: 100%;
    padding: 10px;
    border: 1px solid #b8c4d0;
    border-radius: 5px;
}

button {
    border: 0;
    border-radius: 5px;
    color: white;
    padding: 10px 15px;
    margin: 6px 6px 6px 0;
    cursor: pointer;
    font-size: 14px;
}

button:hover { opacity: .88; }

.search-button { background: #16804a; margin-top: 15px; }

.clear-button {
    display: inline-block;
    margin: 15px 0 0 6px;
    padding: 10px 15px;
    background: #66717c;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}

.record { overflow: hidden; }

.record h3 { margin-bottom: 15px; }

.detail {
    background: #f0f4f7;
    border: 1px solid #e0e7ed;
    padding: 9px 12px;
    border-radius: 5px;
    line-height: 1.5;
}

.record-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}

.record-heading h3 { margin: 0; }

.mode-label {
    display: inline-block;
    background: #e8eef5;
    color: #334e68;
    border-radius: 4px;
    padding: 5px 9px;
    font-size: 12px;
    font-weight: bold;
}

.strip-note {
    margin: 13px 0 8px;
    padding: 10px 12px;
    background: #fff8e5;
    border-left: 4px solid #d49b24;
    color: #624b16;
    font-size: 13px;
    line-height: 1.5;
}

.axis-label {
    font-size: 12px;
    color: #4b5967;
    margin: 7px 0;
    font-weight: bold;
}

.strip-scroll {
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    border: 1px solid #cbd5df;
    background: #fff;
    padding: 4px;
    border-radius: 5px;
    -webkit-overflow-scrolling: touch;
}

.ecg-strip {
    display: block;
    height: 300px;
    max-width: none;
    background: #fffafa;
}

.strip-footer {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    color: #566372;
    font-size: 12px;
    margin-top: 7px;
}

.download-buttons {
    margin-top: 15px;
    padding-top: 12px;
    border-top: 1px solid #e0e5ea;
}

.png-button { background: #1769aa; }
.pdf-button { background: #bd3030; }
.csv-button { background: #16804a; }

.error {
    background: #ffe8e8;
    color: #990000;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.success {
    background: #e8f5e9;
    color: #17652a;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.empty {
    background: white;
    padding: 25px;
    text-align: center;
    border-radius: 8px;
    color: #66717c;
}

.small-print {
    color: #687684;
    font-size: 12px;
    line-height: 1.5;
}

@media (max-width: 600px) {
    body { padding: 8px; }
    .header, .search-box, .record { padding: 13px; }
    .ecg-strip { height: 260px; }
}
</style>
</head>

<body>
<div class="container">

    <div class="header">
        <h2>ECG Rhythm Strip History</h2>

        <div class="patient-details">
            <p><strong>Patient:</strong> <?php echo h($patientName); ?></p>
            <p><strong>Patient ID:</strong> <?php echo h($patientId); ?></p>
            <p><strong>Device ID:</strong> <?php echo h($deviceId); ?></p>
        </div>

        <div class="navigation">
            <a href="index.php">Dashboard</a>
            <a href="history.php">Vital History</a>
            <a href="alerts.php">Alerts</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="search-box">
        <h3>Search ECG by Date and Time</h3>

        <form method="GET" action="ecg_history.php">
            <div class="search-row">
                <div class="search-field">
                    <label for="start_datetime">Start Date and Time</label>
                    <input type="datetime-local" id="start_datetime"
                           name="start_datetime" value="<?php echo h($startInput); ?>">
                </div>

                <div class="search-field">
                    <label for="end_datetime">End Date and Time</label>
                    <input type="datetime-local" id="end_datetime"
                           name="end_datetime" value="<?php echo h($endInput); ?>">
                </div>
            </div>

            <button class="search-button" type="submit">Search ECG</button>
            <a class="clear-button" href="ecg_history.php">Show All</a>
        </form>

        <p class="small-print">
            Leave both fields blank to search all available ECG records.
            The page displays the latest 20 matching records.
        </p>
    </div>

    <?php if ($errorMessage !== ""): ?>
        <div class="error"><?php echo h($errorMessage); ?></div>
    <?php else: ?>
        <div class="success">ECG records found: <?php echo count($records); ?></div>

        <?php if (count($records) === 0): ?>
            <div class="empty">No ECG records found for this date and time range.</div>
        <?php else: ?>

            <?php foreach ($records as $record): ?>
                <?php
                    // Keep ALL valid stored samples. Do not truncate at 2,000.
                    $samples = getEcgSamples($record["samples_json"]);

                    $samplesForJavascript = json_encode(
                        $samples,
                        JSON_HEX_TAG | JSON_HEX_APOS |
                        JSON_HEX_QUOT | JSON_HEX_AMP
                    );

                    if ($samplesForJavascript === false) {
                        $samplesForJavascript = "[]";
                    }

                    $recordId = (string)$record["id"];
                    $rate = (int)($record["sample_rate"] ?? 250);
                    if ($rate < 1 || $rate > 10000) {
                        $rate = 250;
                    }

                    $storedCount = $record["sample_count"] !== null
                        ? (int)$record["sample_count"]
                        : count($samples);
                ?>

                <section class="record"
                    data-record-id="<?php echo h($recordId); ?>"
                    data-patient-id="<?php echo h($patientId); ?>"
                    data-patient-name="<?php echo h($patientName); ?>"
                    data-device-id="<?php echo h($deviceId); ?>"
                    data-recorded-at="<?php echo h($record["recorded_at"]); ?>"
                    data-sample-rate="<?php echo h($rate); ?>"
                    data-sample-count="<?php echo h($storedCount); ?>"
                    data-mode="<?php echo h($record["mode"] ?? "N/A"); ?>"
                    data-samples="<?php echo h($samplesForJavascript); ?>">

                    <div class="record-heading">
                        <h3>ECG Record #<?php echo h($recordId); ?></h3>
                        <span class="mode-label"><?php echo h($record["mode"] ?? "N/A"); ?></span>
                    </div>

                    <div class="record-details">
                        <div class="detail">
                            <strong>Recorded At</strong><br>
                            <?php echo h($record["recorded_at"]); ?>
                        </div>
                        <div class="detail">
                            <strong>Sample Rate</strong><br>
                            <?php echo h($rate); ?> Hz
                        </div>
                        <div class="detail">
                            <strong>Stored Sample Count</strong><br>
                            <?php echo h($storedCount); ?>
                        </div>
                        <div class="detail">
                            <strong>Samples Read</strong><br>
                            <?php echo count($samples); ?>
                        </div>
                        <div class="detail">
                            <strong>Duration</strong><br>
                            <?php echo $rate > 0 ? number_format(count($samples) / $rate, 2) : "N/A"; ?> seconds
                        </div>
                    </div>

                    <div class="strip-note">
                        <strong>Prototype / calibration notice:</strong>
                        This is a single-channel AD8232-style waveform display.
                        The vertical axis is signal amplitude in uncalibrated ADC-derived units,
                        not millivolts. The display must not be used to diagnose a medical condition.
                    </div>

                    <?php if (count($samples) >= 2): ?>
                        <div class="axis-label">Signal amplitude (uncalibrated)</div>

                        <div class="strip-scroll">
                            <canvas class="ecg-strip" aria-label="ECG rhythm strip"></canvas>
                        </div>

                        <div class="strip-footer">
                            <span>Horizontal scale: 25 mm/s</span>
                            <span>Small grid: 1 mm | Bold grid: 5 mm</span>
                            <span>Scroll horizontally to view the full strip</span>
                        </div>

                        <div class="download-buttons">
                            <strong>Download this ECG recording:</strong><br>
                            <button type="button" class="png-button" onclick="downloadPNG(this)">Download PNG</button>
                            <button type="button" class="pdf-button" onclick="downloadPDF(this)">Download PDF</button>
                            <button type="button" class="csv-button" onclick="downloadCSV(this)">Download CSV</button>
                        </div>
                    <?php else: ?>
                        <div class="empty">
                            ECG samples could not be displayed. The stored JSON does not contain
                            at least two supported numeric samples.
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
"use strict";

/*
  ECG paper geometry:
  25 mm/second horizontally.
  CSS uses 96 pixels per inch, therefore 1 mm = 96 / 25.4 CSS pixels.
  At 250 Hz, each sample is 1/250 second apart.
  A 10-second recording is therefore 250 mm wide (about 944.88 CSS pixels).
*/
const CSS_PIXELS_PER_MM = 96 / 25.4;
const PAPER_SPEED_MM_PER_SECOND = 25;
const STRIP_HEIGHT_MM = 80;
const TRACE_COLOR = "#b51224";

function getSamples(record) {
    try {
        const values = JSON.parse(record.dataset.samples || "[]");
        return Array.isArray(values) ? values.map(Number).filter(Number.isFinite) : [];
    } catch (error) {
        return [];
    }
}

function calculateVerticalRange(samples) {
    let min = Math.min(...samples);
    let max = Math.max(...samples);

    if (!Number.isFinite(min) || !Number.isFinite(max)) {
        return { min: -1, max: 1 };
    }

    if (min === max) {
        min -= 1;
        max += 1;
    }

    // Add a modest margin while preserving the original signal shape.
    const margin = (max - min) * 0.12;
    return { min: min - margin, max: max + margin };
}

function drawEcgStrip(canvas, samples, sampleRate) {
    if (samples.length < 2 || sampleRate < 1) return;

    const durationSeconds = samples.length / sampleRate;
    const widthMm = durationSeconds * PAPER_SPEED_MM_PER_SECOND;
    const widthPx = Math.max(
        300,
        Math.round(widthMm * CSS_PIXELS_PER_MM)
    );
    const heightPx = Math.round(STRIP_HEIGHT_MM * CSS_PIXELS_PER_MM);

    // Canvas pixel dimensions are also the intended CSS-pixel dimensions.
    // This makes the on-screen grid close to the standard 96 dpi CSS scale.
    canvas.width = widthPx;
    canvas.height = heightPx;
    canvas.style.width = widthPx + "px";
    canvas.style.height = heightPx + "px";

    const ctx = canvas.getContext("2d");
    const mm = CSS_PIXELS_PER_MM;

    // ECG paper background.
    ctx.fillStyle = "#fffafa";
    ctx.fillRect(0, 0, widthPx, heightPx);

    // 1 mm fine grid.
    ctx.beginPath();
    ctx.strokeStyle = "#f3dada";
    ctx.lineWidth = 0.65;

    for (let x = 0; x <= widthPx; x += mm) {
        const xx = Math.round(x) + 0.5;
        ctx.moveTo(xx, 0);
        ctx.lineTo(xx, heightPx);
    }

    for (let y = 0; y <= heightPx; y += mm) {
        const yy = Math.round(y) + 0.5;
        ctx.moveTo(0, yy);
        ctx.lineTo(widthPx, yy);
    }
    ctx.stroke();

    // 5 mm bold grid.
    ctx.beginPath();
    ctx.strokeStyle = "#dfa9a9";
    ctx.lineWidth = 1.0;

    const majorStep = 5 * mm;
    for (let x = 0; x <= widthPx; x += majorStep) {
        const xx = Math.round(x) + 0.5;
        ctx.moveTo(xx, 0);
        ctx.lineTo(xx, heightPx);
    }

    for (let y = 0; y <= heightPx; y += majorStep) {
        const yy = Math.round(y) + 0.5;
        ctx.moveTo(0, yy);
        ctx.lineTo(widthPx, yy);
    }
    ctx.stroke();

    // Time labels at 1-second intervals.
    ctx.fillStyle = "#704747";
    ctx.font = "11px Arial";
    ctx.textBaseline = "top";

    for (let second = 0; second <= durationSeconds; second++) {
        const x = second * PAPER_SPEED_MM_PER_SECOND * mm;
        if (x < widthPx - 25) {
            ctx.fillText(second + " s", x + 3, 3);
        }
    }

    // Trace is automatically fitted vertically for visibility only.
    // This is NOT a calibrated mV scale.
    const range = calculateVerticalRange(samples);
    const topMargin = 24;
    const bottomMargin = 12;
    const usableHeight = heightPx - topMargin - bottomMargin;

    ctx.beginPath();
    ctx.strokeStyle = TRACE_COLOR;
    ctx.lineWidth = 1.65;
    ctx.lineJoin = "round";
    ctx.lineCap = "round";

    for (let i = 0; i < samples.length; i++) {
        const timeSeconds = i / sampleRate;
        const x = timeSeconds * PAPER_SPEED_MM_PER_SECOND * mm;
        const normalized = (samples[i] - range.min) / (range.max - range.min);
        const y = topMargin + (1 - normalized) * usableHeight;

        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    }
    ctx.stroke();
}

function redrawAll() {
    document.querySelectorAll(".record").forEach(function(record) {
        const canvas = record.querySelector(".ecg-strip");
        if (!canvas) return;

        const samples = getSamples(record);
        const rate = Number(record.dataset.sampleRate) || 250;
        drawEcgStrip(canvas, samples, rate);
    });
}

window.addEventListener("load", redrawAll);
window.addEventListener("resize", redrawAll);

function getRecordInfo(button) {
    const record = button.closest(".record");
    if (!record) return null;

    const samples = getSamples(record);
    if (!samples.length) {
        alert("No ECG samples are available for this recording.");
        return null;
    }

    return {
        record: record,
        canvas: record.querySelector(".ecg-strip"),
        samples: samples,
        id: record.dataset.recordId,
        patientId: record.dataset.patientId,
        patientName: record.dataset.patientName,
        deviceId: record.dataset.deviceId,
        recordedAt: record.dataset.recordedAt,
        sampleRate: Number(record.dataset.sampleRate) || 250,
        sampleCount: record.dataset.sampleCount,
        mode: record.dataset.mode
    };
}

// PNG: export the complete-width strip canvas, not the visible viewport.
function downloadPNG(button) {
    const info = getRecordInfo(button);
    if (!info || !info.canvas) return;

    const link = document.createElement("a");
    link.href = info.canvas.toDataURL("image/png");
    link.download = "ECG_Record_" + info.id + ".png";
    document.body.appendChild(link);
    link.click();
    link.remove();
}

// PDF: create a wide custom page so a 10-second strip is not squeezed
// into a portrait A4 page. The graph width corresponds to 250 mm at 25 mm/s.
function downloadPDF(button) {
    const info = getRecordInfo(button);
    if (!info || !info.canvas) return;

    if (!window.jspdf || !window.jspdf.jsPDF) {
        alert("PDF library could not be loaded. Check your internet connection and try again.");
        return;
    }

    const { jsPDF } = window.jspdf;

    const duration = info.samples.length / info.sampleRate;
    const graphWidthMm = duration * PAPER_SPEED_MM_PER_SECOND;
    const margin = 10;
    const metadataHeight = 35;
    const graphHeightMm = STRIP_HEIGHT_MM;
    const pageWidth = graphWidthMm + margin * 2;
    const pageHeight = metadataHeight + graphHeightMm + margin * 2 + 12;

    const pdf = new jsPDF({
        orientation: "landscape",
        unit: "mm",
        format: [pageWidth, pageHeight]
    });

    pdf.setFont("helvetica", "bold");
    pdf.setFontSize(15);
    pdf.text("ECG RHYTHM STRIP REPORT", margin, 10);

    pdf.setFont("helvetica", "normal");
    pdf.setFontSize(8.5);
    pdf.text("Patient: " + info.patientName + "   Patient ID: " + info.patientId, margin, 17);
    pdf.text("Device: " + info.deviceId + "   Record: " + info.id, margin, 22);
    pdf.text("Recorded: " + info.recordedAt, margin, 27);
    pdf.text(
        "Rate: " + info.sampleRate + " Hz   Samples: " + info.samples.length +
        "   Duration: " + duration.toFixed(2) + " s   Mode: " + info.mode,
        margin, 32
    );

    const graphImage = info.canvas.toDataURL("image/png");
    pdf.addImage(
        graphImage,
        "PNG",
        margin,
        metadataHeight + margin,
        graphWidthMm,
        graphHeightMm,
        undefined,
        "FAST"
    );

    pdf.setFontSize(8);
    pdf.text(
        "25 mm/s horizontal scale. Signal amplitude is uncalibrated; this is not a diagnostic ECG.",
        margin,
        metadataHeight + margin + graphHeightMm + 6
    );

    pdf.save("ECG_Record_" + info.id + ".pdf");
}

// CSV: export every valid original stored sample, not the display canvas.
function downloadCSV(button) {
    const info = getRecordInfo(button);
    if (!info) return;

    let csv = "";
    csv += "ECG Recording Details\n";
    csv += "Patient Name," + csvEscape(info.patientName) + "\n";
    csv += "Patient ID," + csvEscape(info.patientId) + "\n";
    csv += "Device ID," + csvEscape(info.deviceId) + "\n";
    csv += "Record ID," + csvEscape(info.id) + "\n";
    csv += "Recorded At," + csvEscape(info.recordedAt) + "\n";
    csv += "Sample Rate Hz," + info.sampleRate + "\n";
    csv += "Sample Count," + info.samples.length + "\n";
    csv += "Mode," + csvEscape(info.mode) + "\n";
    csv += "Amplitude Calibration,UNCALIBRATED\n\n";
    csv += "Sample Number,Time Seconds,ECG Value\n";

    info.samples.forEach(function(value, index) {
        const time = index / info.sampleRate;
        csv += (index + 1) + "," + time.toFixed(6) + "," + value + "\n";
    });

    const blob = new Blob(["\uFEFF" + csv], {
        type: "text/csv;charset=utf-8;"
    });

    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "ECG_Record_" + info.id + ".csv";
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function csvEscape(value) {
    return '"' + String(value ?? "").replace(/"/g, '""') + '"';
}
</script>
</body>
</html>
