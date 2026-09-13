<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $event->name }} — QR Codes</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
            color: #111;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding: 12px;
        }

        h1 {
            font-size: 16px;
            margin-bottom: 4px;
            grid-column: 1 / -1;
        }

        .subtitle {
            color: #555;
            margin-bottom: 50px;
            grid-column: 1 / -1;
        }

        .cell {
            text-align: center;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 12px;
            page-break-inside: avoid;
            display: inline-block;
        }

        .qr img {
            width: 140px;
            height: 140px;
        }

        .name {
            font-weight: bold;
            margin-top: 8px;
        }

        .meta {
            color: #555;
            font-size: 10px;
            margin-top: 2px;
        }
    </style>
</head>

<body>
    <h1>{{ $event->name }}</h1>
    <div class="subtitle">Event QR Codes — {{ $registrations->count() }} attendee(s)</div>

    @foreach ($registrations as $registration)
        <div class="cell">
            <div class="qr"><img src="{{ $qrImages[$registration->id] }}" alt="QR code"></div>
            <div class="name">{{ trim("{$registration->attendee->first_name} {$registration->attendee->last_name}") }}
            </div>
        </div>
    @endforeach
</body>

</html>
