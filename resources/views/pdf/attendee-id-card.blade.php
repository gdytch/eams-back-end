<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 0;
        }

        body {
            margin: 0;
            padding: 0;
            width: 216px;
            height: 288px;
            font-family: sans-serif;
        }

        .background {
            position: absolute;
            top: 0;
            left: 0;
            width: 216px;
            height: 288px;
        }

        .background img {
            width: 216px;
            height: 288px;
        }

        .content {
            position: absolute;
            top: 0;
            left: 0;
            width: 216px;
            height: 288px;
            text-align: center;
        }

        .qr {
            margin-top: 70px;
        }

        .qr img {
            width: 120px;
            height: 120px;
        }

        .name {
            margin-top: 10px;
            padding: 0 8px;
            font-size: 14px;
            font-weight: bold;
            color: #111;
        }
    </style>
</head>

<body>
    @if ($backgroundImage)
        <div class="background"><img src="{{ $backgroundImage }}" alt=""></div>
    @endif
    <div class="content">
        <div class="qr"><img src="{{ $qrImage }}" alt="QR code"></div>
        <div class="name">{{ $attendeeName }}</div>
    </div>
</body>

</html>
