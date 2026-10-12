<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Serif, serif; color: #111; margin: 48px 56px; font-size: 13px; line-height: 1.6; }
    .head { text-align: center; border-bottom: 2px solid #1e3a8a; padding-bottom: 10px; margin-bottom: 20px; }
    .inst { font-size: 18px; font-weight: bold; }
    .meta { display: table; width: 100%; margin-bottom: 20px; font-size: 12px; }
    .meta div { display: table-cell; } .meta .r { text-align: right; }
    h1 { text-align: center; font-size: 16px; margin: 0 0 6px; text-transform: uppercase; }
    h2 { text-align: center; font-size: 14px; margin: 0 0 20px; }
    .body { text-align: justify; white-space: pre-line; }
    .sign { margin-top: 48px; text-align: right; }
    .foot { position: fixed; bottom: 30px; left: 56px; right: 56px; font-size: 9.5px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; }
</style>
</head>
<body>
    <div class="head"><div class="inst">{{ $institute }}</div>@if ($circular->department)<div>{{ $circular->department->name }}</div>@endif</div>
    <div class="meta"><div>No. {{ $circular->number }}@if ($circular->version > 1) (version {{ $circular->version }})@endif</div><div class="r">Date: {{ $circular->published_at->format('d F Y') }}</div></div>
    <h1>{{ \App\Modules\Registrar\Models\Circular::KINDS[$circular->kind] }}</h1>
    <h2>{{ $circular->title }}</h2>
    <div class="body">{{ $circular->body }}</div>
    <div class="sign"><strong>{{ $circular->author->name }}</strong><br>{{ $circular->author->designation?->name }}</div>
    <div class="foot">Published through the institute workflow system after approval. Not digitally signed.@if ($circular->requires_ack) Acknowledgment required{{ $circular->ack_by ? ' by '.$circular->ack_by->format('d F Y') : '' }}.@endif</div>
</body>
</html>
