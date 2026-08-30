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
            width: 100%;
            height: 100%;
            font-family: sans-serif;
            border: 1px solid #000;
        }

        .background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        .background img {
            width: 100%;
            height: 100%;
        }

        .content {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            text-align: center;
        }

        .qr {
            margin-top: 90px;

        }

        .qr img {
            width: 120px;
            height: 120px;
            border: 1rem solid #fff;
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
