<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Serif, serif; color: #111; margin: 48px 56px; font-size: 13px; line-height: 1.6; }
    .head { text-align: center; border-bottom: 2px solid #1e3a8a; padding-bottom: 10px; margin-bottom: 26px; }
    .inst { font-size: 18px; font-weight: bold; letter-spacing: .5px; }
    .meta { display: table; width: 100%; margin-bottom: 26px; font-size: 12px; }
    .meta div { display: table-cell; }
    .meta .r { text-align: right; }
    h1 { text-align: center; font-size: 17px; text-decoration: underline; margin: 0 0 22px; }
    .body { text-align: justify; white-space: pre-line; }
    .sign { margin-top: 56px; text-align: right; }
    .foot { position: fixed; bottom: 30px; left: 56px; right: 56px; font-size: 9.5px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; }
</style>
</head>
<body>
    <div class="head"><div class="inst">{{ $institute }}</div></div>
    <div class="meta"><div>No. {{ $certificate->number }}</div><div class="r">Date: {{ $certificate->issued_at->format('d F Y') }}</div></div>
    <h1>{{ $title }}</h1>
    <div class="body">{{ $certificate->body }}</div>
    <div class="sign">
        <strong>{{ $certificate->signatory_name }}</strong><br>
        {{ $certificate->signatory_designation }}
    </div>
    <div class="foot">
        @if ($footer){{ $footer }}<br>@endif
        Issued through the institute workflow system after approval by the officer named above. This document is not digitally signed.
        Verify at {{ $verifyUrl }} (code {{ $certificate->verification_code }}).
    </div>
</body>
</html>
